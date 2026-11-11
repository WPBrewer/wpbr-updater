<?php

declare(strict_types=1);

namespace WPBrewer\Updater\Tests\Contract;

use PHPUnit\Framework\TestCase;
use WPBrewer\Updater\Tests\Support\FakeServer;
use WPBrewer\Updater\Tests\Support\SchemaAssert;

/**
 * The fake server's responses have to match the vendored contract.
 *
 * @license GPL-3.0-or-later
 */
final class ContractTest extends TestCase
{
    private const UPDATE = 'https://wpbrewer.com/schema/wpbr-license/v1/update-info.response.json';

    private const INFO = 'https://wpbrewer.com/schema/wpbr-license/v1/info.response.json';

    private const ERROR = 'https://wpbrewer.com/schema/wpbr-license/v1/error.response.json';

    private static string $zip;

    private static string $sha;

    public static function setUpBeforeClass(): void
    {
        self::$zip = FakeServer::buildZip();
        self::$sha = (string) hash_file('sha256', self::$zip);
        FakeServer::start(FakeServer::baseState(self::$zip, self::$sha));
    }

    public static function tearDownAfterClass(): void
    {
        FakeServer::stop();
    }

    public function test_update_response_matches_schema(): void
    {
        FakeServer::setMode('update');
        $response = $this->post('/wp-json/wpbr-license/v1/update-info');
        $this->assertSame(200, $response['status']);
        SchemaAssert::assertValid($response['body'], self::UPDATE);
        /** @var array<string, mixed> $json */
        $json = json_decode($response['body'], true);
        $this->assertTrue($json['update_available']);
        $this->assertFalse($json['allow_downgrade']);
        $this->assertSame(self::$sha, $json['sha256']);
    }

    public function test_downgrade_response_matches_schema(): void
    {
        FakeServer::setMode('downgrade');
        $response = $this->post('/wp-json/wpbr-license/v1/update-info');
        $this->assertSame(200, $response['status']);
        SchemaAssert::assertValid($response['body'], self::UPDATE);
        /** @var array<string, mixed> $json */
        $json = json_decode($response['body'], true);
        $this->assertTrue($json['allow_downgrade']);
        $this->assertSame('0.9.0', $json['version']);
    }

    public function test_no_update_response_matches_schema_and_omits_package(): void
    {
        FakeServer::setMode('no_update');
        $response = $this->post('/wp-json/wpbr-license/v1/update-info');
        $this->assertSame(200, $response['status']);
        SchemaAssert::assertValid($response['body'], self::UPDATE);
        $this->assertStringNotContainsString('package', $response['body']);
        $this->assertStringNotContainsString('sha256', $response['body']);
    }

    public function test_update_without_allow_downgrade_is_rejected_by_schema(): void
    {
        $payload = json_encode([
            'update_available' => true,
            'version' => '1.2.0',
            'package' => 'https://wpbrewer.com/wpbr-download/42?exp=1790000000&dom=example.com&sig=' . str_repeat('a', 64),
            'sha256' => str_repeat('b', 64),
            'requires_wp' => '7.0',
            'requires_php' => '8.1',
            'tested' => '7.1.4',
            'changelog' => 'Fix renewal invoice.',
            'license' => [
                'status' => 'active',
                'expires_at' => null,
                'sites_used' => 1,
                'sites_limit' => 0,
            ],
        ]);
        $this->assertIsString($payload);
        SchemaAssert::assertInvalid($payload, self::UPDATE);
    }

    public function test_expired_license_matches_error_schema_and_has_no_package(): void
    {
        FakeServer::setMode('expired');
        $response = $this->post('/wp-json/wpbr-license/v1/update-info');
        $this->assertSame(403, $response['status']);
        SchemaAssert::assertValid($response['body'], self::ERROR);
        $this->assertStringContainsString('license_expired', $response['body']);
        $this->assertStringNotContainsString('package', $response['body']);
    }

    public function test_server_error_matches_error_schema(): void
    {
        FakeServer::setMode('http_500');
        $response = $this->post('/wp-json/wpbr-license/v1/update-info');
        $this->assertSame(500, $response['status']);
        SchemaAssert::assertValid($response['body'], self::ERROR);
    }

    public function test_info_response_matches_schema_and_has_no_package(): void
    {
        FakeServer::setMode('update');
        $response = $this->get('/wp-json/wpbr-license/v1/info/wpbr-dummy');
        $this->assertSame(200, $response['status']);
        SchemaAssert::assertValid($response['body'], self::INFO);
        $this->assertStringNotContainsString('package', $response['body']);
        $this->assertStringNotContainsString('sha256', $response['body']);
        /** @var array<string, mixed> $json */
        $json = json_decode($response['body'], true);
        $this->assertSame('Fix renewal invoice.' . "\n" . 'Second line.', $json['changelog']);
        $this->assertSame('7.0', $json['requires_wp']);
        $this->assertSame('7.1.4', $json['tested']);
    }

    public function test_activate_deactivate_and_check_match_the_schema(): void
    {
        FakeServer::setMode('update');
        $activate = $this->call(
            'POST',
            '/wp-json/wpbr-license/v1/activate',
            (string) json_encode([
                'license_key' => 'WPBR-TEST-KEY-0123456789ABCD',
                'product_slug' => 'wpbr-dummy',
                'domain' => 'https://Example.com/path',
                'version' => '1.0.0',
                'wp' => '7.1.4',
                'php' => '7.4.33',
            ])
        );
        $this->assertSame(200, $activate['status']);
        SchemaAssert::assertValid($activate['body'], 'https://wpbrewer.com/schema/wpbr-license/v1/activate.response.json');
        $this->assertStringNotContainsString('license_key', $activate['body']);

        $check = $this->call(
            'POST',
            '/wp-json/wpbr-license/v1/check',
            '{"instance_token":"0123456789abcdef0123456789abcdef","domain":"example.com","product_slug":"wpbr-dummy","version":"1.0.0"}'
        );
        $this->assertSame(200, $check['status']);
        SchemaAssert::assertValid($check['body'], 'https://wpbrewer.com/schema/wpbr-license/v1/check.response.json');

        $deactivate = $this->call(
            'POST',
            '/wp-json/wpbr-license/v1/deactivate',
            '{"instance_token":"0123456789abcdef0123456789abcdef","domain":"example.com","product_slug":"wpbr-dummy"}'
        );
        $this->assertSame(200, $deactivate['status']);
        SchemaAssert::assertValid($deactivate['body'], 'https://wpbrewer.com/schema/wpbr-license/v1/deactivate.response.json');

        $wrong = $this->call(
            'POST',
            '/wp-json/wpbr-license/v1/activate',
            (string) json_encode([
                'license_key' => 'wrong-key',
                'product_slug' => 'wpbr-dummy',
                'domain' => 'example.com',
                'version' => '1.0.0',
                'wp' => '7.1.4',
                'php' => '7.4.33',
            ])
        );
        $this->assertSame(404, $wrong['status']);
        SchemaAssert::assertValid($wrong['body'], self::ERROR);
        $this->assertStringContainsString('license_not_found', $wrong['body']);
    }

    public function test_download_is_a_zip(): void
    {
        FakeServer::setMode('update');
        $update = $this->post('/wp-json/wpbr-license/v1/update-info');
        /** @var array<string, mixed> $json */
        $json = json_decode($update['body'], true);
        $this->assertIsString($json['package']);

        $path = (string) parse_url($json['package'], PHP_URL_PATH);
        $query = (string) parse_url($json['package'], PHP_URL_QUERY);
        $download = $this->get($path . '?' . $query);
        $this->assertSame(200, $download['status']);
        $this->assertStringStartsWith('PK', $download['body']);
        $this->assertSame(self::$sha, hash('sha256', $download['body']));
    }

    /**
     * @return array{status: int, body: string}
     */
    private function post(string $path): array
    {
        return $this->call('POST', $path, '{"instance_token":"0123456789abcdef0123456789abcdef","domain":"127.0.0.1","product_slug":"wpbr-dummy","version":"1.0.0"}');
    }

    /**
     * @return array{status: int, body: string}
     */
    private function get(string $path): array
    {
        return $this->call('GET', $path, '');
    }

    /**
     * @return array{status: int, body: string}
     */
    private function call(string $method, string $path, string $body): array
    {
        $headers = "Accept: application/json\r\n";
        if ($method === 'POST') {
            $headers .= "Content-Type: application/json\r\n";
        }
        $context = stream_context_create([
            'http' => [
                'method' => $method,
                'header' => $headers,
                'content' => $body,
                'timeout' => 5,
                'ignore_errors' => true,
            ],
        ]);
        $raw = file_get_contents(FakeServer::URL . $path, false, $context);
        $this->assertIsString($raw);
        $status = 0;
        if (isset($http_response_header[0]) && preg_match('/\s(\d{3})\s/', (string) $http_response_header[0], $match) === 1) {
            $status = (int) $match[1];
        }
        return ['status' => $status, 'body' => $raw];
    }
}
