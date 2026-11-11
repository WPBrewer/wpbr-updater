<?php

declare(strict_types=1);

namespace WPBrewer\Updater\Tests\Support;

use ZipArchive;

/**
 * Starts the fixture license server on 127.0.0.1:8080.
 *
 * @license GPL-3.0-or-later
 */
final class FakeServer
{
    public const URL = 'http://127.0.0.1:8080';

    /** @var resource|null */
    private static $process;

    private static string $stateFile = '';

    /**
     * @param array<string, mixed> $state
     */
    public static function start(array $state): void
    {
        self::stop();
        self::$stateFile = sys_get_temp_dir() . '/wpbr-fake-state.json';
        self::write($state);

        $log = sys_get_temp_dir() . '/wpbr-fake-server.log';
        file_put_contents($log, '');
        $router = dirname(__DIR__) . '/fixtures/fake-license-server.php';
        $env = getenv();
        if (!is_array($env)) {
            $env = [];
        }
        $env['WPBR_FAKE_STATE'] = self::$stateFile;

        $process = proc_open(
            [PHP_BINARY, '-S', '127.0.0.1:8080', $router],
            [
                0 => ['pipe', 'r'],
                1 => ['file', $log, 'a'],
                2 => ['file', $log, 'a'],
            ],
            $pipes,
            null,
            $env
        );
        if (!is_resource($process)) {
            throw new \RuntimeException('Could not start the fake license server.');
        }
        self::$process = $process;
        if (isset($pipes[0]) && is_resource($pipes[0])) {
            fclose($pipes[0]);
        }

        $deadline = microtime(true) + 5;
        while (microtime(true) < $deadline) {
            $body = @file_get_contents(self::URL . '/health');
            if ($body === 'ok') {
                return;
            }
            usleep(50000);
        }

        $output = (string) @file_get_contents($log);
        self::stop();
        throw new \RuntimeException('Fake license server did not start. ' . $output);
    }

    public static function stop(): void
    {
        if (is_resource(self::$process)) {
            proc_terminate(self::$process, 9);
            proc_close(self::$process);
        }
        self::$process = null;
    }

    /**
     * @param array<string, mixed> $extra
     */
    public static function setMode(string $mode, array $extra = []): void
    {
        $state = self::read();
        $state['mode'] = $mode;
        $state['hits'] = [
            'update-info' => 0,
            'info' => 0,
            'download' => 0,
            'other' => 0,
        ];
        $state['last_update_body'] = '';
        foreach ($extra as $key => $value) {
            $state[$key] = $value;
        }
        self::write($state);
    }

    public static function hits(string $bucket): int
    {
        $hits = self::read()['hits'] ?? [];
        if (!is_array($hits)) {
            return 0;
        }
        return (int) ($hits[$bucket] ?? 0);
    }

    public static function lastUpdateBody(): string
    {
        $body = self::read()['last_update_body'] ?? '';
        return is_string($body) ? $body : '';
    }

    /**
     * @return array<string, mixed>
     */
    public static function baseState(string $zip, string $sha256): array
    {
        return [
            'mode' => 'update',
            'server' => self::URL,
            'slug' => 'wpbr-dummy',
            'name' => 'WPBR Dummy',
            'version' => '1.2.0',
            'downgrade_version' => '0.9.0',
            'changelog' => "Fix renewal invoice.\nSecond line.",
            'requires_wp' => '7.0',
            'requires_php' => '8.1',
            'tested' => '7.1.4',
            'sha256' => $sha256,
            'zip' => $zip,
            'release_id' => 42,
            'domain' => '127.0.0.1',
            'sleep' => 30,
            'license_key' => 'WPBR-TEST-KEY-0123456789ABCD',
            'instance_token' => '0123456789abcdef0123456789abcdef',
            'license_status' => 'active',
            'sites_used' => 1,
            'sites_limit' => 3,
            'hits' => [
                'update-info' => 0,
                'info' => 0,
                'download' => 0,
                'activate' => 0,
                'deactivate' => 0,
                'check' => 0,
                'other' => 0,
            ],
            'last_update_body' => '',
        ];
    }

    /**
     * @param array<string, mixed> $extra
     */
    public static function merge(array $extra): void
    {
        $state = self::read();
        foreach ($extra as $key => $value) {
            $state[$key] = $value;
        }
        self::write($state);
    }

    public static function lastBody(string $bucket): string
    {
        $body = self::read()['last_' . $bucket . '_body'] ?? '';
        return is_string($body) ? $body : '';
    }

    public static function lastUri(): string
    {
        $uri = self::read()['last_uri'] ?? '';
        return is_string($uri) ? $uri : '';
    }

    public static function buildZip(string $version = '1.2.0'): string
    {
        $path = sys_get_temp_dir() . '/wpbr-dummy-' . $version . '.zip';
        $zip = new ZipArchive();
        $opened = $zip->open($path, ZipArchive::CREATE | ZipArchive::OVERWRITE);
        if ($opened !== true) {
            throw new \RuntimeException('Could not create the test package.');
        }
        $zip->addFromString('wpbr-dummy/wpbr-dummy.php', self::pluginSource($version));
        $zip->close();
        return $path;
    }

    public static function pluginSource(string $version): string
    {
        return "<?php\n/**\n * Plugin Name: WPBR Dummy\n * Version: {$version}\n * Author: WPBrewer\n * License: GPL-3.0-or-later\n */\n";
    }

    /**
     * @param array<string, mixed> $state
     */
    private static function write(array $state): void
    {
        file_put_contents(self::$stateFile, (string) json_encode($state, JSON_UNESCAPED_SLASHES));
    }

    /**
     * @return array<string, mixed>
     */
    private static function read(): array
    {
        $decoded = json_decode((string) file_get_contents(self::$stateFile), true);
        if (!is_array($decoded)) {
            throw new \RuntimeException('Fake server state disappeared.');
        }
        /** @var array<string, mixed> $decoded */
        return $decoded;
    }
}
