<?php

declare(strict_types=1);

namespace WPBrewer\Updater\Tests\Wp;

use Plugin_Upgrader;
use Automatic_Upgrader_Skin;
use WP_Error;
use WP_UnitTestCase;
use WPBrewer\Updater\Tests\Support\FakeServer;
use WPBrewer\Updater\Tests\Support\SchemaAssert;
use WPBrewer\Updater\Updater;

/**
 * Updater behavior against the fake license server inside WordPress.
 *
 * @license GPL-3.0-or-later
 */
final class UpdaterTest extends WP_UnitTestCase
{
    private const SLUG = 'wpbr-dummy';

    private const BASENAME = 'wpbr-dummy/wpbr-dummy.php';

    private const OPTION = 'wpbr_dummy_license';

    private const CACHE = 'wpbr_updater_wpbr-dummy';

    private const TOKEN = '0123456789abcdef0123456789abcdef';

    private static string $zip = '';

    private static string $sha = '';

    private static string $pluginFile = '';

    public static function setUpBeforeClass(): void
    {
        parent::setUpBeforeClass();

        self::$zip = FakeServer::buildZip('1.2.0');
        self::$sha = (string) hash_file('sha256', self::$zip);
        self::installPlugin();
        FakeServer::start(FakeServer::baseState(self::$zip, self::$sha));

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
        update_option(self::OPTION, ['instance_token' => self::TOKEN], false);
        delete_option(self::CACHE);
        delete_site_transient('update_plugins');
        FakeServer::setMode('update');
        wp_set_current_user(self::factory()->user->create(['role' => 'administrator']));
        if (is_file(self::$pluginFile)) {
            file_put_contents(self::$pluginFile, FakeServer::pluginSource('1.0.0'));
            wp_clean_plugins_cache();
        }
    }

    public function test_wordpress_core_is_7_1(): void
    {
        $this->assertStringStartsWith('7.1', (string) get_bloginfo('version'));
    }

    public function test_update_is_offered_in_the_transient(): void
    {
        $value = $this->refresh();

        $this->assertArrayHasKey(self::BASENAME, $value->response);
        $this->assertArrayNotHasKey(self::BASENAME, $value->no_update);
        $item = $value->response[self::BASENAME];
        $this->assertSame('1.2.0', $item->new_version);
        $this->assertSame('7.0', $item->requires);
        $this->assertSame('7.1.4', $item->tested);
        $this->assertSame('8.1', $item->requires_php);
        $this->assertSame(self::SLUG, $item->slug);
        $this->assertNotSame('', $item->package);
        $this->assertStringContainsString('/wpbr-download/42?', (string) $item->package);

        SchemaAssert::assertValid(
            FakeServer::lastUpdateBody(),
            'https://wpbrewer.com/schema/wpbr-license/v1/update-info.request.json'
        );
        /** @var array<string, mixed> $sent */
        $sent = json_decode(FakeServer::lastUpdateBody(), true);
        $this->assertSame(self::TOKEN, $sent['instance_token']);
        $this->assertSame('127.0.0.1', $sent['domain']);
        $this->assertSame(self::SLUG, $sent['product_slug']);
        $this->assertSame('1.0.0', $sent['version']);
    }

    public function test_wp_update_plugins_keeps_the_offer_and_enables_the_auto_update_toggle(): void
    {
        wp_update_plugins();
        $transient = get_site_transient('update_plugins');
        $this->assertIsObject($transient);
        $this->assertArrayHasKey(self::BASENAME, $transient->response);

        $html = $this->pluginsTable();
        $this->assertStringContainsString('Enable auto-updates', $html);
        $this->assertStringContainsString('plugin=wpbr-dummy', $html);
        $this->assertStringContainsString('View details', $html);
        $this->assertStringNotContainsString('Auto-updates are not available', $html);
    }

    public function test_plugins_api_modal_includes_changelog_requires_and_tested(): void
    {
        require_once ABSPATH . 'wp-admin/includes/plugin-install.php';
        $api = plugins_api('plugin_information', (object) ['slug' => self::SLUG]);
        $this->assertIsObject($api);
        $this->assertSame('WPBR Dummy', $api->name);
        $this->assertSame('1.2.0', $api->version);
        $this->assertSame('7.0', $api->requires);
        $this->assertSame('8.1', $api->requires_php);
        $this->assertSame('7.1.4', $api->tested);
        $this->assertIsArray($api->sections);
        $this->assertStringContainsString('Fix renewal invoice.', (string) $api->sections['changelog']);
        $this->assertStringContainsString('Second line.', (string) $api->sections['changelog']);
        $this->assertStringNotContainsString('<script', (string) $api->sections['changelog']);
    }

    public function test_no_update_and_expired_license_fill_no_update_without_a_package(): void
    {
        FakeServer::setMode('no_update');
        $value = $this->refresh();
        $this->assertArrayHasKey(self::BASENAME, $value->no_update);
        $this->assertSame('', $value->no_update[self::BASENAME]->package);
        $this->assertFalse($this->cache()['update']['failure']);

        delete_option(self::CACHE);
        FakeServer::setMode('expired');
        $expired = $this->refresh();
        $this->assertArrayHasKey(self::BASENAME, $expired->no_update);
        $this->assertArrayNotHasKey(self::BASENAME, $expired->response);
        $this->assertFalse($this->cache()['update']['failure']);
        $this->assertGreaterThan(time() + (11 * HOUR_IN_SECONDS), $this->cache()['update']['expires_at']);
    }

    public function test_downgrade_is_offered_only_when_the_server_allows_it(): void
    {
        FakeServer::setMode('downgrade');
        $value = $this->refresh();
        $this->assertSame('0.9.0', $value->response[self::BASENAME]->new_version);
    }

    public function test_fresh_result_is_cached_for_twelve_hours(): void
    {
        $this->refresh();
        $this->assertGreaterThan(time() + (11 * HOUR_IN_SECONDS), $this->cache()['update']['expires_at']);
        $this->assertSame(1, FakeServer::hits('update-info'));

        $this->refresh();
        $this->assertSame(1, FakeServer::hits('update-info'));
    }

    public function test_missing_token_lists_the_plugin_and_does_not_call_the_server(): void
    {
        delete_option(self::OPTION);
        $value = $this->refresh();
        $this->assertArrayHasKey(self::BASENAME, $value->no_update);
        $this->assertSame(0, FakeServer::hits('update-info'));
        $this->assertFalse(get_option(self::CACHE, false));
    }

    public function test_foreign_package_is_not_offered(): void
    {
        FakeServer::setMode('foreign');
        $value = $this->refresh();
        $this->assertArrayNotHasKey(self::BASENAME, $value->response);
        $this->assertArrayHasKey(self::BASENAME, $value->no_update);
        $this->assertStringNotContainsString('evil.example', (string) wp_json_encode($value));
        $this->assertBackoff();
    }

    public function test_bad_checksum_blocks_the_update(): void
    {
        FakeServer::setMode('bad_sha256');
        $transient = $this->saved();
        $package = (string) $transient->response[self::BASENAME]->package;

        $rejected = apply_filters('upgrader_pre_download', false, $package, null, ['plugin' => self::BASENAME]);
        $this->assertInstanceOf(WP_Error::class, $rejected);
        $this->assertSame('wpbr_updater_checksum', $rejected->get_error_code());

        // Plugin_Upgrader::upgrade() drops the WP_Error when download_package fails
        // before the skin result is stored. The install must still not happen.
        $this->runUpgrade();
        $this->assertSame('1.0.0', $this->installedVersion());
        $this->assertGreaterThan(0, FakeServer::hits('download'));
    }

    public function test_matching_checksum_installs_the_package(): void
    {
        $this->saveTransient();
        $result = $this->runUpgrade();
        $this->assertTrue($result);
        $this->assertSame('1.2.0', $this->installedVersion());
    }

    public function test_unexpected_package_for_this_plugin_is_rejected_and_other_plugins_are_ignored(): void
    {
        $this->refresh();
        $rejected = apply_filters(
            'upgrader_pre_download',
            false,
            'https://evil.example/wpbr-download/1?exp=1790000000&dom=127.0.0.1&sig=' . str_repeat('a', 64),
            null,
            ['plugin' => self::BASENAME]
        );
        $this->assertInstanceOf(WP_Error::class, $rejected);
        $this->assertSame('wpbr_updater_rejected', $rejected->get_error_code());

        $other = apply_filters(
            'upgrader_pre_download',
            false,
            'https://downloads.wordpress.org/plugin/hello.zip',
            null,
            ['plugin' => 'hello.php']
        );
        $this->assertFalse($other);
    }

    public function test_server_down_leaves_admin_working_and_sets_backoff(): void
    {
        FakeServer::stop();
        try {
            $value = $this->saved();
            $this->assertArrayHasKey(self::BASENAME, $value->no_update);
            $this->assertSame('', $value->no_update[self::BASENAME]->package);
            $this->assertBackoff();
            $this->assertStringContainsString('Enable auto-updates', $this->pluginsTable());

            $hitsBefore = $this->cache()['update']['expires_at'];
            $this->saved();
            $this->assertSame($hitsBefore, $this->cache()['update']['expires_at']);
        } finally {
            FakeServer::start(FakeServer::baseState(self::$zip, self::$sha));
        }
    }

    public function test_http_500_leaves_admin_working_and_sets_backoff(): void
    {
        $this->assertFailureMode('http_500');
    }

    public function test_html_response_leaves_admin_working_and_sets_backoff(): void
    {
        $this->assertFailureMode('html');
    }

    public function test_timeout_leaves_admin_working_and_sets_backoff(): void
    {
        FakeServer::setMode('timeout', ['sleep' => 30]);
        $started = microtime(true);
        try {
            $value = $this->saved();
            $elapsed = microtime(true) - $started;
            $this->assertGreaterThan(4.0, $elapsed);
            $this->assertLessThan(12.0, $elapsed);
            $this->assertArrayHasKey(self::BASENAME, $value->no_update);
            $this->assertBackoff();
            $this->assertStringContainsString('Enable auto-updates', $this->pluginsTable());
        } finally {
            FakeServer::stop();
            FakeServer::start(FakeServer::baseState(self::$zip, self::$sha));
        }
    }

    public function test_invalid_config_does_not_fatal(): void
    {
        Updater::register(['slug' => 'Not A Slug']);
        Updater::register([]);
        $value = $this->refresh();
        $this->assertArrayHasKey(self::BASENAME, $value->response);
    }

    private function assertFailureMode(string $mode): void
    {
        FakeServer::setMode($mode);
        $value = $this->saved();
        $this->assertArrayHasKey(self::BASENAME, $value->no_update);
        $this->assertArrayNotHasKey(self::BASENAME, $value->response);
        $this->assertBackoff();
        $html = $this->pluginsTable();
        $this->assertStringContainsString('Enable auto-updates', $html);

        $calls = FakeServer::hits('update-info');
        $this->saved();
        $this->assertSame($calls, FakeServer::hits('update-info'));
    }

    private function assertBackoff(): void
    {
        $slot = $this->cache()['update'];
        $this->assertTrue($slot['failure']);
        $this->assertNull($slot['data']);
        $this->assertGreaterThan(time() + 3500, $slot['expires_at']);
        $this->assertLessThan(time() + 3700, $slot['expires_at']);
    }

    /**
     * @return array<string, mixed>
     */
    private function cache(): array
    {
        $cache = get_option(self::CACHE, null);
        $this->assertIsArray($cache);
        /** @var array<string, mixed> $cache */
        return $cache;
    }

    private function refresh(): object
    {
        $value = (object) [
            'response' => [],
            'no_update' => [],
            'checked' => [],
        ];
        $filtered = apply_filters('pre_set_site_transient_update_plugins', $value);
        $this->assertIsObject($filtered);
        return $filtered;
    }

    private function saved(): object
    {
        set_site_transient('update_plugins', (object) [
            'last_checked' => time(),
            'response' => [],
            'no_update' => [],
            'checked' => [],
        ]);
        $stored = get_site_transient('update_plugins');
        $this->assertIsObject($stored);
        return $stored;
    }

    private function saveTransient(): void
    {
        set_site_transient('update_plugins', (object) [
            'last_checked' => time(),
            'response' => [],
            'no_update' => [],
            'checked' => [self::BASENAME => '1.0.0'],
        ]);
    }

    private function pluginsTable(): string
    {
        $GLOBALS['s'] = '';
        require_once ABSPATH . 'wp-admin/includes/admin.php';
        // The WP test suite forces automatic_updater_disabled. Production does not.
        // Put the site back on the production default before rendering the column.
        add_filter('automatic_updater_disabled', '__return_false', 100);
        set_current_screen('plugins');
        $table = _get_list_table('WP_Plugins_List_Table', ['screen' => 'plugins']);
        ob_start();
        $table->prepare_items();
        $table->display();
        return (string) ob_get_clean();
    }

    /**
     * @return mixed
     */
    private function runUpgrade()
    {
        require_once ABSPATH . 'wp-admin/includes/file.php';
        require_once ABSPATH . 'wp-admin/includes/plugin.php';
        require_once ABSPATH . 'wp-admin/includes/class-wp-upgrader.php';
        require_once ABSPATH . 'wp-admin/includes/class-automatic-upgrader-skin.php';
        require_once ABSPATH . 'wp-admin/includes/class-plugin-upgrader.php';

        // Automatic_Upgrader_Skin::error() calls header() again because
        // done_header stays false, leaving one output buffer open.
        $level = ob_get_level();
        try {
            $upgrader = new Plugin_Upgrader(new Automatic_Upgrader_Skin());
            $result = $upgrader->upgrade(self::BASENAME);
            wp_clean_plugins_cache();
            return $result;
        } finally {
            while (ob_get_level() > $level) {
                ob_end_clean();
            }
        }
    }

    private function installedVersion(): string
    {
        require_once ABSPATH . 'wp-admin/includes/plugin.php';
        $data = get_plugin_data(self::$pluginFile, false, false);
        return (string) ($data['Version'] ?? '');
    }

    private static function installPlugin(): void
    {
        $dir = WP_PLUGIN_DIR . '/wpbr-dummy';
        if (!is_dir($dir) && !mkdir($dir, 0777, true) && !is_dir($dir)) {
            throw new \RuntimeException('Could not create the dummy plugin directory.');
        }
        self::$pluginFile = $dir . '/wpbr-dummy.php';
        file_put_contents(self::$pluginFile, FakeServer::pluginSource('1.0.0'));
        wp_clean_plugins_cache();
    }
}
