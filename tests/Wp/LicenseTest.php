<?php

declare(strict_types=1);

namespace WPBrewer\Updater\Tests\Wp;

use WP_UnitTestCase;
use WPBrewer\Updater\Tests\Support\FakeServer;
use WPBrewer\Updater\Tests\Support\SchemaAssert;
use WPBrewer\Updater\License;
use WPBrewer\Updater\Updater;

/**
 * License screen: activate, deactivate, masking, autoload, expiry.
 *
 * @license GPL-3.0-or-later
 */
final class LicenseTest extends WP_UnitTestCase
{
    private const SLUG = 'wpbr-dummy';

    private const BASENAME = 'wpbr-dummy/wpbr-dummy.php';

    private const OPTION = 'wpbr_dummy_license';

    private const TOKEN = '0123456789abcdef0123456789abcdef';

    private const KEY = 'WPBR-TEST-KEY-0123456789ABCD';

    private string $redirectTo = '';

    private static string $pluginFile = '';

    public static function setUpBeforeClass(): void
    {
        parent::setUpBeforeClass();

        $zip = FakeServer::buildZip('1.2.0');
        $sha = (string) hash_file('sha256', $zip);
        self::installPlugin();
        FakeServer::start(FakeServer::baseState($zip, $sha));
        Updater::register([
            'file' => self::$pluginFile,
            'slug' => self::SLUG,
            'server' => FakeServer::URL,
            'version' => '1.0.0',
            'license_option' => self::OPTION,
        ]);
    }

    public static function tearDownAfterClass(): void
    {
        FakeServer::stop();
        parent::tearDownAfterClass();
    }

    public function setUp(): void
    {
        parent::setUp();
        delete_option(self::OPTION);
        wp_set_current_user(self::factory()->user->create(['role' => 'administrator']));
        FakeServer::merge([
            'license_status' => 'active',
            'license_error' => '',
            'license_expires_at' => '2027-11-15T00:00:00Z',
            'sites_used' => 1,
            'sites_limit' => 3,
        ]);
    }

    public function test_activate_masks_the_key_and_stores_the_token_without_autoload(): void
    {
        $this->post('activate', ['license_key' => self::KEY]);

        $stored = get_option(self::OPTION, null);
        $this->assertIsArray($stored);
        $this->assertSame(self::TOKEN, $stored['instance_token']);
        $this->assertSame('active', $stored['status']);
        $this->assertSame('2027-11-15T00:00:00Z', $stored['expires_at']);
        $this->assertSame(1, $stored['sites_used']);
        $this->assertSame(3, $stored['sites_limit']);
        $this->assertSame('ABCD', $stored['key_hint']);
        $encoded = (string) wp_json_encode($stored);
        $this->assertStringNotContainsString('WPBR-TEST-KEY', $encoded);
        $this->assertStringNotContainsString('0123456789ABCD', $encoded);
        $this->assertNotAutoloaded();

        $body = FakeServer::lastBody('activate');
        $this->assertStringNotContainsString(self::KEY, FakeServer::lastUri());
        SchemaAssert::assertValid($body, 'https://wpbrewer.com/schema/wpbr-license/v1/activate.request.json');
        /** @var array<string, mixed> $sent */
        $sent = json_decode($body, true);
        $this->assertSame(self::KEY, $sent['license_key']);
        $this->assertSame(self::SLUG, $sent['product_slug']);
        $this->assertSame('127.0.0.1', $sent['domain']);
        $this->assertSame('1.0.0', $sent['version']);

        $html = $this->render();
        $this->assertStringContainsString('********ABCD', $html);
        $this->assertStringNotContainsString(self::KEY, $html);
        $this->assertStringNotContainsString('0123456789ABCD', $html);
        $this->assertStringContainsString('Active', $html);
        $this->assertStringContainsString('1 / 3', $html);
        $this->assertStringContainsString('method="post"', $html);
        $this->assertStringContainsString('admin-post.php', $html);
        $this->assertStringNotContainsString('<script', $html);
        $this->assertTrue(Updater::is_licensed(self::SLUG));
        $this->assertSame('2027-11-15T00:00:00Z', Updater::expires_at(self::SLUG));
    }

    public function test_deactivate_removes_the_token(): void
    {
        $this->post('activate', ['license_key' => self::KEY]);
        $this->post('deactivate');

        $this->assertFalse(get_option(self::OPTION, false));
        $this->assertFalse(Updater::is_licensed(self::SLUG));
        $this->assertNull(Updater::expires_at(self::SLUG));
        SchemaAssert::assertValid(
            FakeServer::lastBody('deactivate'),
            'https://wpbrewer.com/schema/wpbr-license/v1/deactivate.request.json'
        );
        $html = $this->render();
        $this->assertStringContainsString('name="license_key"', $html);
        $this->assertStringContainsString('Activate', $html);
        $this->assertStringNotContainsString(self::KEY, $html);
    }

    public function test_wrong_key_is_rejected_and_not_stored(): void
    {
        $this->post('activate', ['license_key' => 'not-the-key']);

        $this->assertFalse(get_option(self::OPTION, false));
        $html = $this->render();
        $this->assertStringContainsString('License key not found.', $html);
        $this->assertStringNotContainsString('not-the-key', $html);
        $this->assertFalse(Updater::is_licensed(self::SLUG));
    }

    public function test_server_down_does_not_fatal_or_store_a_token(): void
    {
        FakeServer::stop();
        try {
            $this->post('activate', ['license_key' => self::KEY]);
            $this->assertFalse(get_option(self::OPTION, false));
            $html = $this->render();
            $this->assertStringContainsString('could not be reached', $html);
            $this->assertStringContainsString('keeps working', $html);
            $this->assertStringContainsString('name="license_key"', $html);
        } finally {
            $zip = FakeServer::buildZip('1.2.0');
            $sha = (string) hash_file('sha256', $zip);
            FakeServer::start(FakeServer::baseState($zip, $sha));
        }
    }

    public function test_expired_license_keeps_the_plugin_working(): void
    {
        $before = get_option('active_plugins');
        update_option(self::OPTION, [
            'instance_token' => self::TOKEN,
            'status' => 'expired',
            'expires_at' => '2020-01-01T00:00:00Z',
            'sites_used' => 1,
            'sites_limit' => 3,
            'key_hint' => 'ABCD',
        ], false);

        $this->assertTrue(Updater::is_licensed(self::SLUG));
        $this->assertSame('2020-01-01T00:00:00Z', Updater::expires_at(self::SLUG));
        $this->assertSame($before, get_option('active_plugins'));

        add_filter('wpbr_dummy_feature', static function (): string {
            return 'working';
        });
        $this->assertSame('working', apply_filters('wpbr_dummy_feature', ''));

        $notices = $this->capture('admin_notices');
        $this->assertStringContainsString('Updates have stopped', $notices);
        $this->assertStringContainsString('The plugin keeps working', $notices);

        $html = $this->render();
        $this->assertStringContainsString('Expired', $html);
        $this->assertStringContainsString('Deactivate', $html);
        $this->assertStringContainsString('********ABCD', $html);
        $this->assertSame('working', apply_filters('wpbr_dummy_feature', ''));
        $this->assertSame($before, get_option('active_plugins'));
    }

    public function test_renew_notice_appears_when_expiry_is_within_fourteen_days(): void
    {
        $soon = gmdate('Y-m-d\TH:i:s\Z', time() + (7 * DAY_IN_SECONDS));
        update_option(self::OPTION, [
            'instance_token' => self::TOKEN,
            'status' => 'active',
            'expires_at' => $soon,
            'sites_used' => 1,
            'sites_limit' => 3,
            'key_hint' => 'ABCD',
        ], false);

        $notices = $this->capture('admin_notices');
        $this->assertStringContainsString('expires on', $notices);
        $this->assertStringContainsString('The plugin keeps working', $notices);
        $this->assertStringContainsString(substr($soon, 0, 10), $notices);

        $row = apply_filters('plugin_row_meta', ['Details'], self::BASENAME);
        $this->assertIsArray($row);
        $joined = implode(' ', $row);
        $this->assertStringContainsString('License:', $joined);
        $this->assertStringContainsString('Expires soon', $joined);
        $this->assertStringContainsString('wpbr-license-wpbr-dummy', $joined);
    }

    public function test_plugins_row_shows_not_activated_without_a_token(): void
    {
        $row = apply_filters('plugin_row_meta', ['Details'], self::BASENAME);
        $this->assertIsArray($row);
        $this->assertStringContainsString('Not activated', implode(' ', $row));
        $this->assertFalse(Updater::is_licensed(self::SLUG));
        $this->assertNull(Updater::expires_at(self::SLUG));
    }

    public function test_status_check_refreshes_sites_used(): void
    {
        $this->post('activate', ['license_key' => self::KEY]);
        FakeServer::merge([
            'sites_used' => 2,
            'sites_limit' => 3,
        ]);
        $this->post('status');

        $stored = get_option(self::OPTION, null);
        $this->assertIsArray($stored);
        $this->assertSame(2, $stored['sites_used']);
        $this->assertSame(self::TOKEN, $stored['instance_token']);
        SchemaAssert::assertValid(
            FakeServer::lastBody('check'),
            'https://wpbrewer.com/schema/wpbr-license/v1/check.request.json'
        );
        $html = $this->render();
        $this->assertStringContainsString('2 / 3', $html);
        $this->assertStringNotContainsString(self::KEY, $html);
    }

    public function test_zh_tw_screen_uses_the_catalog_strings(): void
    {
        $locale = static function (): string {
            return 'zh_TW';
        };
        add_filter('locale', $locale);
        add_filter('determine_locale', $locale);
        try {
            License::loadTextdomain();
            $html = $this->render();
            $this->assertStringContainsString('授權金鑰', $html);
            $this->assertStringContainsString('啟用', $html);
            $this->assertStringNotContainsString('>License key<', $html);
        } finally {
            remove_filter('locale', $locale);
            remove_filter('determine_locale', $locale);
            License::loadTextdomain();
        }
    }

    public function test_subscriber_cannot_activate(): void
    {
        wp_set_current_user(self::factory()->user->create(['role' => 'subscriber']));
        $_POST = [
            'action' => 'wpbr_license_' . self::SLUG,
            'wpbr_license_do' => 'activate',
            'license_key' => self::KEY,
            'wpbr_license_nonce' => wp_create_nonce('wpbr_license_' . self::SLUG),
        ];
        $_REQUEST = $_POST;

        $this->expectException(\WPDieException::class);
        do_action('admin_post_wpbr_license_' . self::SLUG);
    }

    public function test_missing_nonce_is_rejected(): void
    {
        $_POST = [
            'action' => 'wpbr_license_' . self::SLUG,
            'wpbr_license_do' => 'activate',
            'license_key' => self::KEY,
        ];
        $_REQUEST = $_POST;

        $this->expectException(\WPDieException::class);
        do_action('admin_post_wpbr_license_' . self::SLUG);
    }

    /**
     * @param array<string, string> $fields
     */
    private function post(string $action, array $fields = []): void
    {
        $_POST = array_merge([
            'action' => 'wpbr_license_' . self::SLUG,
            'wpbr_license_do' => $action,
            'wpbr_license_nonce' => wp_create_nonce('wpbr_license_' . self::SLUG),
        ], $fields);
        $_REQUEST = $_POST;
        $this->redirectTo = '';
        add_filter('wp_redirect', [$this, 'blockRedirect'], 1, 1);
        try {
            do_action('admin_post_wpbr_license_' . self::SLUG);
            $this->fail('The license form did not redirect.');
        } catch (\RuntimeException $exception) {
            if ($exception->getMessage() !== 'redirect') {
                throw $exception;
            }
        } finally {
            remove_filter('wp_redirect', [$this, 'blockRedirect'], 1);
        }

        $this->assertStringContainsString('wpbr-license-' . self::SLUG, $this->redirectTo);
    }

    /**
     * @param mixed $location
     */
    public function blockRedirect($location): void
    {
        $this->redirectTo = is_string($location) ? $location : '';
        throw new \RuntimeException('redirect');
    }

    private function render(): string
    {
        $property = (new \ReflectionClass(License::class))->getProperty('screens');
        $property->setAccessible(true);
        $screens = $property->getValue();
        $this->assertIsArray($screens);
        $this->assertArrayHasKey(self::SLUG, $screens);
        ob_start();
        $screens[self::SLUG]->render();
        return (string) ob_get_clean();
    }

    private function capture(string $hook): string
    {
        ob_start();
        do_action($hook);
        return (string) ob_get_clean();
    }

    private function assertNotAutoloaded(): void
    {
        global $wpdb;
        $autoload = $wpdb->get_var($wpdb->prepare(
            "SELECT autoload FROM {$wpdb->options} WHERE option_name = %s",
            self::OPTION
        ));
        $this->assertContains($autoload, ['no', 'off', 'auto-off']);
    }

    private static function installPlugin(): void
    {
        $dir = WP_PLUGIN_DIR . '/wpbr-dummy';
        if (!is_dir($dir) && !mkdir($dir, 0777, true) && !is_dir($dir)) {
            throw new \RuntimeException('Could not create the dummy plugin directory.');
        }
        self::$pluginFile = $dir . '/wpbr-dummy.php';
        if (!is_file(self::$pluginFile)) {
            file_put_contents(self::$pluginFile, FakeServer::pluginSource('1.0.0'));
        }
    }
}
