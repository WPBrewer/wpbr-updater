<?php

declare(strict_types=1);

/**
 * Live WordPress 7.1.x run against the fake license server.
 *
 * Usage: php tests/live-run.php
 * DB credentials: WP_TEST_DB_USER / WP_TEST_DB_PASS / WP_TEST_DB_HOST
 * (defaults wp / wp / 127.0.0.1). Creates the database wordpress_live.
 *
 * @license GPL-3.0-or-later
 */

use WPBrewer\Updater\Tests\Support\FakeServer;

const WPBR_LIVE_TOKEN = '0123456789abcdef0123456789abcdef';
const WPBR_LIVE_BASENAME = 'wpbr-dummy/wpbr-dummy.php';
const WPBR_LIVE_CACHE = 'wpbr_updater_wpbr-dummy';

$command = $argv[1] ?? 'run';
if (substr($command, 0, 4) === 'ctl-') {
    wpbr_live_ctl($command);
    exit(0);
}

wpbr_live_run();

function wpbr_live_run(): void
{
    $root = dirname(__DIR__);
    $core = getenv('WP_CORE_DIR') ?: '/tmp/wpbr-wp-core';
    $live = '/tmp/wpbr-live';
    $log = $live . '/wp-content/debug.log';

    if (!is_file($core . '/wp-settings.php')) {
        fwrite(STDERR, "WordPress core is missing at {$core}. Run tests/install-wp-tests.sh first.\n");
        exit(1);
    }

    require $root . '/vendor/autoload.php';

    echo "Preparing WordPress at {$live}\n";
    wpbr_live_reset_tree($core, $live);
    wpbr_live_write_config($live);
    wpbr_live_write_mu_plugin($live);
    wpbr_live_write_plugin($live, $root);
    wpbr_live_create_database();

    $php = PHP_BINARY;
    $install = wpbr_live_proc([$php, __FILE__, 'ctl-install', $live]);
    if ($install['code'] !== 0) {
        fwrite(STDERR, $install['out']);
        exit(1);
    }
    echo $install['out'];
    wpbr_live_clear_log($log);

    $zip = FakeServer::buildZip('1.2.0');
    $sha = hash_file('sha256', $zip);
    if (!is_string($sha)) {
        throw new RuntimeException('Could not hash the package.');
    }
    FakeServer::start(FakeServer::baseState($zip, $sha));

    $server = proc_open(
        [$php, '-S', '127.0.0.1:8090', '-t', $live],
        [
            1 => ['file', '/tmp/wpbr-live-http.log', 'w'],
            2 => ['file', '/tmp/wpbr-live-http.log', 'a'],
        ],
        $pipes
    );
    if (!is_resource($server)) {
        FakeServer::stop();
        throw new RuntimeException('Could not start php -S.');
    }
    if (isset($pipes[0]) && is_resource($pipes[0])) {
        fclose($pipes[0]);
    }

    register_shutdown_function(static function () use ($server): void {
        if (is_resource($server)) {
            proc_terminate($server, 9);
            proc_close($server);
        }
        FakeServer::stop();
    });

    wpbr_live_wait('http://127.0.0.1:8090/wp-login.php');
    $cookie = trim(wpbr_live_proc([$php, __FILE__, 'ctl-cookie', $live])['out']);

    $plugins = wpbr_live_http('http://127.0.0.1:8090/wp-admin/plugins.php', $cookie);
    wpbr_live_assert($plugins['status'] === 200, 'plugins.php status ' . $plugins['status']);
    wpbr_live_assert(wpbr_live_contains($plugins['body'], 'Enable auto-updates'), 'auto-update toggle missing');
    wpbr_live_assert(wpbr_live_contains($plugins['body'], 'wpbr-dummy'), 'plugin row missing');
    wpbr_live_assert(wpbr_live_contains($plugins['body'], 'View details'), 'details link missing');
    wpbr_live_assert(!wpbr_live_contains($plugins['body'], 'name="log"'), 'plugins.php showed the login form');
    wpbr_live_assert_log_empty($log);

    $state = wpbr_live_read($live);
    wpbr_live_assert(($state['transient_version'] ?? '') === '1.2.0', 'transient did not offer 1.2.0');
    wpbr_live_assert(wpbr_live_contains((string) ($state['package'] ?? ''), '127.0.0.1:8080/wpbr-download/'), 'package URL missing');
    echo "EVIDENCE transient new_version=1.2.0 package={$state['package']}\n";
    echo "EVIDENCE plugins.php 200 contains Enable auto-updates and View details\n";

    if (!preg_match('/href="([^"]*enable-auto-update[^"]*)"/', $plugins['body'], $match)) {
        throw new RuntimeException('Enable auto-updates href missing.');
    }
    $toggle = 'http://127.0.0.1:8090/wp-admin/' . html_entity_decode($match[1], ENT_QUOTES);
    $enabled = wpbr_live_http($toggle, $cookie);
    wpbr_live_assert($enabled['status'] === 200, 'enable auto-update status ' . $enabled['status']);
    wpbr_live_assert(wpbr_live_contains($enabled['body'], 'Disable auto-updates'), 'toggle did not switch');
    $state = wpbr_live_read($live);
    $auto = $state['auto'] ?? [];
    wpbr_live_assert(is_array($auto) && in_array(WPBR_LIVE_BASENAME, $auto, true), 'auto_update_plugins missing the plugin');
    echo "EVIDENCE auto_update_plugins contains " . WPBR_LIVE_BASENAME . " and the row says Disable auto-updates\n";
    wpbr_live_assert_log_empty($log);

    $modal = wpbr_live_http(
        'http://127.0.0.1:8090/wp-admin/plugin-install.php?tab=plugin-information&plugin=wpbr-dummy&section=changelog',
        $cookie
    );
    wpbr_live_assert($modal['status'] === 200, 'details modal status ' . $modal['status']);
    wpbr_live_assert(wpbr_live_contains($modal['body'], 'Fix renewal invoice'), 'changelog missing');
    wpbr_live_assert(wpbr_live_contains($modal['body'], 'Requires WordPress Version:'), 'requires label missing');
    wpbr_live_assert(wpbr_live_contains($modal['body'], '7.0 or higher'), 'requires value missing');
    wpbr_live_assert(wpbr_live_contains($modal['body'], 'Compatible up to:'), 'tested label missing');
    wpbr_live_assert(wpbr_live_contains($modal['body'], '7.1.4'), 'tested value missing');
    echo "EVIDENCE modal changelog, Requires WordPress Version 7.0, Compatible up to 7.1.4\n";
    wpbr_live_assert_log_empty($log);

    foreach (['http_500' => '500', 'html' => 'HTML'] as $mode => $label) {
        wpbr_live_failure($live, $cookie, $log, $mode, $label);
    }

    FakeServer::stop();
    $downStarted = microtime(true);
    wpbr_live_failure($live, $cookie, $log, null, 'down');
    echo 'EVIDENCE server-down elapsed ' . number_format(microtime(true) - $downStarted, 2) . "s\n";
    FakeServer::start(FakeServer::baseState($zip, $sha));

    FakeServer::setMode('timeout');
    wpbr_live_reset_cache($live);
    $timeoutStarted = microtime(true);
    $timed = wpbr_live_http('http://127.0.0.1:8090/wp-admin/plugins.php', $cookie, 20);
    $elapsed = microtime(true) - $timeoutStarted;
    FakeServer::stop();
    wpbr_live_assert($timed['status'] === 200, 'timeout plugins.php status ' . $timed['status']);
    wpbr_live_assert($elapsed > 4 && $elapsed < 15, 'timeout elapsed ' . $elapsed);
    wpbr_live_assert_backoff(wpbr_live_read($live), 'timeout');
    wpbr_live_assert_log_empty($log);
    echo 'EVIDENCE timeout plugins.php 200 elapsed ' . number_format($elapsed, 2) . "s backoff 1h\n";
    FakeServer::start(FakeServer::baseState($zip, $sha));

    FakeServer::setMode('bad_sha256');
    wpbr_live_reset_cache($live);
    $upgrade = wpbr_live_proc([$php, __FILE__, 'ctl-upgrade', $live]);
    if ($upgrade['code'] !== 0) {
        fwrite(STDERR, $upgrade['out']);
        exit(1);
    }
    $decoded = json_decode($upgrade['out'], true);
    if (!is_array($decoded)) {
        throw new RuntimeException('Upgrade probe did not return JSON: ' . $upgrade['out']);
    }
    wpbr_live_assert(($decoded['error'] ?? '') === 'wpbr_updater_checksum', 'checksum error was ' . json_encode($decoded));
    wpbr_live_assert(($decoded['version'] ?? '') === '1.0.0', 'bad checksum changed the installed version');
    wpbr_live_assert(FakeServer::hits('download') > 0, 'package was not downloaded');
    echo "EVIDENCE bad sha256 blocked code=wpbr_updater_checksum installed version stays 1.0.0 downloads=" . FakeServer::hits('download') . "\n";
    wpbr_live_assert_log_empty($log);

    wpbr_live_license($live, $cookie, $log, $zip, $sha);

    $version = (string) (wpbr_live_read($live)['wp_version'] ?? '');
    echo "EVIDENCE wordpress {$version} debug.log empty\n";
    echo "LIVE OK\n";
}

function wpbr_live_failure(string $live, string $cookie, string $log, ?string $mode, string $label): void
{
    if ($mode !== null) {
        FakeServer::setMode($mode);
    }
    wpbr_live_reset_cache($live);
    $response = wpbr_live_http('http://127.0.0.1:8090/wp-admin/plugins.php', $cookie);
    wpbr_live_assert($response['status'] === 200, $label . ' plugins.php status ' . $response['status']);
    wpbr_live_assert(!wpbr_live_contains($response['body'], 'There has been a critical error'), $label . ' fatals');
    wpbr_live_assert(wpbr_live_contains($response['body'], 'Enable auto-updates') || wpbr_live_contains($response['body'], 'Disable auto-updates'), $label . ' toggle missing');
    wpbr_live_assert_backoff(wpbr_live_read($live), $label);
    wpbr_live_assert_log_empty($log);
    echo "EVIDENCE {$label} plugins.php 200 backoff 1h\n";
}

/**
 * @param array<string, mixed> $state
 */
function wpbr_live_assert_backoff(array $state, string $label): void
{
    $slot = $state['cache']['update'] ?? null;
    wpbr_live_assert(is_array($slot) && ($slot['failure'] ?? null) === true, $label . ' backoff missing');
    $expires = (int) ($slot['expires_at'] ?? 0);
    $delta = $expires - time();
    wpbr_live_assert($delta > 3500 && $delta < 3700, $label . ' backoff ttl ' . $delta);
}

function wpbr_live_assert_log_empty(string $log): void
{
    $contents = is_file($log) ? (string) file_get_contents($log) : '';
    if (trim($contents) !== '') {
        throw new RuntimeException("debug.log is not empty:\n" . $contents);
    }
}

function wpbr_live_assert(bool $condition, string $message): void
{
    if (!$condition) {
        throw new RuntimeException($message);
    }
}

function wpbr_live_clear_log(string $log): void
{
    if (is_file($log)) {
        unlink($log);
    }
}

function wpbr_live_reset_cache(string $live): void
{
    $result = wpbr_live_proc([PHP_BINARY, __FILE__, 'ctl-reset', $live]);
    if ($result['code'] !== 0) {
        throw new RuntimeException($result['out']);
    }
}

/**
 * @return array<string, mixed>
 */
function wpbr_live_read(string $live): array
{
    $result = wpbr_live_proc([PHP_BINARY, __FILE__, 'ctl-read', $live]);
    if ($result['code'] !== 0) {
        throw new RuntimeException($result['out']);
    }
    $decoded = json_decode($result['out'], true);
    if (!is_array($decoded)) {
        throw new RuntimeException('Could not read live state: ' . $result['out']);
    }

    /** @var array<string, mixed> $decoded */
    return $decoded;
}

function wpbr_live_wait(string $url): void
{
    $deadline = microtime(true) + 5;
    while (microtime(true) < $deadline) {
        $response = @file_get_contents($url);
        if (is_string($response) && $response !== '') {
            return;
        }
        usleep(50000);
    }
    $httpLog = (string) @file_get_contents('/tmp/wpbr-live-http.log');
    throw new RuntimeException("WordPress did not accept HTTP. {$httpLog}");
}

/**
 * @return array{status: int, body: string}
 */
function wpbr_live_http(string $url, string $cookie, int $maxTime = 30): array
{
    $bodyFile = tempnam(sys_get_temp_dir(), 'wpbr-body');
    if ($bodyFile === false) {
        throw new RuntimeException('tempnam failed');
    }
    $command = sprintf(
        'curl -sS -L --max-redirs 5 --max-time %d -o %s -w %%{http_code} -b %s %s',
        $maxTime,
        escapeshellarg($bodyFile),
        escapeshellarg($cookie),
        escapeshellarg($url)
    );
    $status = (int) shell_exec($command);
    $body = (string) file_get_contents($bodyFile);
    unlink($bodyFile);
    return ['status' => $status, 'body' => $body];
}

/**
 * @param list<string> $command
 * @return array{code: int, out: string}
 */
function wpbr_live_proc(array $command): array
{
    $spec = [
        1 => ['pipe', 'w'],
        2 => ['pipe', 'w'],
    ];
    $process = proc_open($command, $spec, $pipes);
    if (!is_resource($process)) {
        throw new RuntimeException('Could not run ' . implode(' ', $command));
    }
    $out = stream_get_contents($pipes[1]) . stream_get_contents($pipes[2]);
    fclose($pipes[1]);
    fclose($pipes[2]);
    $code = proc_close($process);
    return ['code' => $code, 'out' => $out];
}

function wpbr_live_reset_tree(string $core, string $live): void
{
    if (is_dir($live)) {
        wpbr_live_proc(['rm', '-rf', $live]);
    }
    if (!mkdir($live, 0777, true) && !is_dir($live)) {
        throw new RuntimeException('Could not create ' . $live);
    }
    $copy = wpbr_live_proc(['cp', '-a', $core . '/.', $live]);
    if ($copy['code'] !== 0) {
        throw new RuntimeException($copy['out']);
    }
}

function wpbr_live_write_config(string $live): void
{
    $user = getenv('WP_TEST_DB_USER') ?: 'wp';
    $pass = getenv('WP_TEST_DB_PASS');
    $pass = $pass === false ? 'wp' : $pass;
    $host = getenv('WP_TEST_DB_HOST') ?: '127.0.0.1';
    $config = <<<PHP
<?php
define( 'DB_NAME', 'wordpress_live' );
define( 'DB_USER', '{$user}' );
define( 'DB_PASSWORD', '{$pass}' );
define( 'DB_HOST', '{$host}' );
define( 'DB_CHARSET', 'utf8mb4' );
define( 'DB_COLLATE', '' );
define( 'AUTH_KEY', 'wpbr-live-auth' );
define( 'SECURE_AUTH_KEY', 'wpbr-live-secure-auth' );
define( 'LOGGED_IN_KEY', 'wpbr-live-logged-in' );
define( 'NONCE_KEY', 'wpbr-live-nonce' );
define( 'AUTH_SALT', 'wpbr-live-auth-salt' );
define( 'SECURE_AUTH_SALT', 'wpbr-live-secure-auth-salt' );
define( 'LOGGED_IN_SALT', 'wpbr-live-logged-in-salt' );
define( 'NONCE_SALT', 'wpbr-live-nonce-salt' );
\$table_prefix = 'wp_';
define( 'WP_DEBUG', true );
define( 'WP_DEBUG_LOG', true );
define( 'WP_DEBUG_DISPLAY', false );
define( 'FS_METHOD', 'direct' );
define( 'DISABLE_WP_CRON', true );
define( 'WP_HOME', 'http://127.0.0.1:8090' );
define( 'WP_SITEURL', 'http://127.0.0.1:8090' );
if ( ! defined( 'ABSPATH' ) ) {
    define( 'ABSPATH', __DIR__ . '/' );
}
require ABSPATH . 'wp-settings.php';
PHP;
    file_put_contents($live . '/wp-config.php', $config);
}

function wpbr_live_write_mu_plugin(string $live): void
{
    $dir = $live . '/wp-content/mu-plugins';
    if (!is_dir($dir) && !mkdir($dir, 0777, true) && !is_dir($dir)) {
        throw new RuntimeException('Could not create mu-plugins.');
    }
    $php = <<<'PHP'
<?php
/**
 * Keep api.wordpress.org from touching the network during the live run.
 * A failed HTTPS call with WP_DEBUG logs wp_trigger_error into debug.log.
 *
 * @license GPL-3.0-or-later
 */
add_filter('pre_wp_mail', '__return_true');

add_filter(
    'pre_http_request',
    static function ($pre, $args, $url) {
        unset($args);
        if (!is_string($url)) {
            return $pre;
        }
        $host = parse_url($url, PHP_URL_HOST);
        if (!is_string($host) || !preg_match('/(^|\\.)wordpress\\.org$/', $host)) {
            return $pre;
        }
        $body = '{"offers":[],"translations":[]}';
        if (strpos($url, '/plugins/update-check/') !== false) {
            $body = '{"plugins":{},"translations":[],"no_update":{}}';
        } elseif (strpos($url, '/themes/update-check/') !== false) {
            $body = '{"themes":{},"translations":[],"no_update":{}}';
        }
        return [
            'headers' => [],
            'body' => $body,
            'response' => ['code' => 200, 'message' => 'OK'],
            'cookies' => [],
            'filename' => null,
        ];
    },
    10,
    3
);
PHP;
    file_put_contents($dir . '/wpbr-block-wporg.php', $php);
}

function wpbr_live_write_plugin(string $live, string $root): void
{
    $dir = $live . '/wp-content/plugins/wpbr-dummy';
    if (!is_dir($dir) && !mkdir($dir, 0777, true) && !is_dir($dir)) {
        throw new RuntimeException('Could not create the dummy plugin.');
    }
    $autoload = $root . '/vendor/autoload.php';
    $php = <<<PHP
<?php
/**
 * Plugin Name: WPBR Dummy
 * Version: 1.0.0
 * Author: WPBrewer
 * License: GPL-3.0-or-later
 * Update URI: http://127.0.0.1:8080/wpbr-dummy
 */

require_once '{$autoload}';

WPBrewer\\Updater\\Updater::register([
    'file' => __FILE__,
    'slug' => 'wpbr-dummy',
    'server' => 'http://127.0.0.1:8080',
    'version' => '1.0.0',
    'license_option' => 'wpbr_dummy_license',
]);

function wpbr_dummy_feature(): string
{
    return 'working';
}
PHP;
    file_put_contents($dir . '/wpbr-dummy.php', $php);
}

function wpbr_live_create_database(): void
{
    $host = getenv('WP_TEST_DB_HOST') ?: '127.0.0.1';
    $user = getenv('WP_TEST_DB_USER') ?: 'wp';
    $pass = getenv('WP_TEST_DB_PASS');
    $pass = $pass === false ? 'wp' : $pass;
    $mysqli = new mysqli($host, $user, $pass, '', 3306);
    if ($mysqli->connect_error) {
        throw new RuntimeException($mysqli->connect_error);
    }
    if (!$mysqli->query('DROP DATABASE IF EXISTS `wordpress_live`') || !$mysqli->query('CREATE DATABASE `wordpress_live`')) {
        throw new RuntimeException($mysqli->error);
    }
}

function wpbr_live_ctl(string $command): void
{
    global $argv;
    $live = $argv[2] ?? '';
    if ($live === '' || !is_file($live . '/wp-load.php')) {
        fwrite(STDERR, "Missing WordPress directory.\n");
        exit(1);
    }

    if ($command === 'ctl-install') {
        define('WP_INSTALLING', true);
    }
    require $live . '/wp-load.php';

    if ($command === 'ctl-install') {
        require_once ABSPATH . 'wp-admin/includes/upgrade.php';
        require_once ABSPATH . 'wp-admin/includes/plugin.php';
        wp_install('WPBR Live', 'admin', 'admin@example.org', false, '', 'admin-pass', 'en_US');
        $activated = activate_plugin(WPBR_LIVE_BASENAME);
        if (is_wp_error($activated)) {
            fwrite(STDERR, $activated->get_error_message() . "\n");
            exit(1);
        }
        update_option('wpbr_dummy_license', ['instance_token' => WPBR_LIVE_TOKEN], false);
        echo "installed wordpress " . get_bloginfo('version') . "\n";
        return;
    }

    if ($command === 'ctl-cookie') {
        $user = get_user_by('login', 'admin');
        if (!$user instanceof WP_User) {
            fwrite(STDERR, "admin user missing\n");
            exit(1);
        }
        $expires = time() + 86400;
        $auth = wp_generate_auth_cookie($user->ID, $expires, 'auth');
        $logged = wp_generate_auth_cookie($user->ID, $expires, 'logged_in');
        echo AUTH_COOKIE . '=' . $auth . '; ' . LOGGED_IN_COOKIE . '=' . $logged;
        return;
    }

    if ($command === 'ctl-reset') {
        delete_option(WPBR_LIVE_CACHE);
        delete_site_transient('update_plugins');
        return;
    }

    if ($command === 'ctl-read') {
        $transient = get_site_transient('update_plugins');
        $item = is_object($transient) && isset($transient->response[WPBR_LIVE_BASENAME])
            ? $transient->response[WPBR_LIVE_BASENAME]
            : null;
        $cache = get_option(WPBR_LIVE_CACHE, null);
        global $wpdb;
        $license = get_option('wpbr_dummy_license', null);
        $autoload = $wpdb->get_var($wpdb->prepare(
            "SELECT autoload FROM {$wpdb->options} WHERE option_name = %s",
            'wpbr_dummy_license'
        ));
        $active = get_option('active_plugins', []);
        echo (string) json_encode([
            'wp_version' => get_bloginfo('version'),
            'transient_version' => is_object($item) ? (string) ($item->new_version ?? '') : '',
            'package' => is_object($item) ? (string) ($item->package ?? '') : '',
            'cache' => is_array($cache) ? $cache : null,
            'auto' => get_site_option('auto_update_plugins', []),
            'version' => wpbr_live_installed_version(),
            'license' => is_array($license) ? $license : null,
            'autoload' => is_string($autoload) ? $autoload : null,
            'active' => is_array($active) ? $active : [],
            'licensed' => \WPBrewer\Updater\Updater::is_licensed('wpbr-dummy'),
            'expires_at' => \WPBrewer\Updater\Updater::expires_at('wpbr-dummy'),
            'locale' => get_locale(),
        ]);
        return;
    }

    if ($command === 'ctl-clear-license') {
        delete_option('wpbr_dummy_license');
        return;
    }

    if ($command === 'ctl-lang') {
        $locale = $argv[3] ?? '';
        if ($locale !== '' && $locale !== 'en_US' && preg_match('/^[A-Za-z0-9_]+$/', $locale) !== 1) {
            fwrite(STDERR, "Bad locale\n");
            exit(1);
        }
        update_option('WPLANG', $locale === 'en_US' ? '' : $locale);
        echo (string) get_option('WPLANG', '');
        return;
    }

    if ($command === 'ctl-feature') {
        if (!function_exists('wpbr_dummy_feature')) {
            fwrite(STDERR, "feature missing\n");
            exit(1);
        }
        echo wpbr_dummy_feature();
        return;
    }

    if ($command === 'ctl-upgrade') {
        $user = get_user_by('login', 'admin');
        if (!$user instanceof WP_User) {
            fwrite(STDERR, "admin user missing\n");
            exit(1);
        }
        wp_set_current_user($user->ID);
        delete_option(WPBR_LIVE_CACHE);
        delete_site_transient('update_plugins');
        require_once ABSPATH . 'wp-admin/includes/file.php';
        require_once ABSPATH . 'wp-admin/includes/plugin.php';
        require_once ABSPATH . 'wp-admin/includes/class-wp-upgrader.php';
        require_once ABSPATH . 'wp-admin/includes/class-automatic-upgrader-skin.php';
        require_once ABSPATH . 'wp-admin/includes/class-plugin-upgrader.php';
        wp_update_plugins();
        $transient = get_site_transient('update_plugins');
        $item = is_object($transient) ? ($transient->response[WPBR_LIVE_BASENAME] ?? null) : null;
        $package = is_object($item) ? (string) ($item->package ?? '') : '';
        $error = apply_filters('upgrader_pre_download', false, $package, null, ['plugin' => WPBR_LIVE_BASENAME]);
        $level = ob_get_level();
        $upgrader = new Plugin_Upgrader(new Automatic_Upgrader_Skin());
        $result = $upgrader->upgrade(WPBR_LIVE_BASENAME);
        while (ob_get_level() > $level) {
            ob_end_clean();
        }
        echo (string) json_encode([
            'error' => is_wp_error($error) ? $error->get_error_code() : null,
            'upgrade_result' => is_wp_error($result) ? $result->get_error_code() : $result,
            'version' => wpbr_live_installed_version(),
        ]);
        return;
    }

    fwrite(STDERR, "Unknown command {$command}\n");
    exit(1);
}

function wpbr_live_contains(string $haystack, string $needle): bool
{
    return $needle === '' || strpos($haystack, $needle) !== false;
}

function wpbr_live_installed_version(): string
{
    require_once ABSPATH . 'wp-admin/includes/plugin.php';
    $data = get_plugin_data(WP_PLUGIN_DIR . '/' . WPBR_LIVE_BASENAME, false, false);
    return (string) ($data['Version'] ?? '');
}

function wpbr_live_license(string $live, string $cookie, string $log, string $zip, string $sha): void
{
    $page = 'http://127.0.0.1:8090/wp-admin/admin.php?page=wpbr-license-wpbr-dummy';
    $post = 'http://127.0.0.1:8090/wp-admin/admin-post.php';
    $key = 'WPBR-TEST-KEY-0123456789ABCD';

    FakeServer::setMode('update', ['license_status' => 'active']);
    $cleared = wpbr_live_proc([PHP_BINARY, __FILE__, 'ctl-clear-license', $live]);
    wpbr_live_assert($cleared['code'] === 0, $cleared['out']);

    $screen = wpbr_live_http($page, $cookie);
    wpbr_live_assert($screen['status'] === 200, 'license screen status ' . $screen['status']);
    wpbr_live_assert_contains($screen['body'], 'License key', 'en_US key label missing');
    wpbr_live_assert_contains($screen['body'], '>Activate<', 'en_US activate button missing');
    wpbr_live_assert_contains($screen['body'], 'method="post"', 'activate form is not a POST');
    wpbr_live_assert_contains($screen['body'], 'admin-post.php', 'activate form does not post to admin-post.php');
    wpbr_live_assert_contains($screen['body'], 'name="license_key"', 'key field missing');
    wpbr_live_assert_log_empty($log);
    echo "EVIDENCE en_US license screen 200 plain POST form, no JavaScript\n";

    $activated = wpbr_live_license_post($post, $cookie, $screen['body'], 'activate', $key);
    wpbr_live_assert($activated['status'] === 200, 'activate status ' . $activated['status']);
    wpbr_live_assert_contains($activated['body'], 'License activated.', 'activate success missing');
    wpbr_live_assert_contains($activated['body'], '********ABCD', 'mask missing');
    wpbr_live_assert(!wpbr_live_contains($activated['body'], $key), 'full license key is on the screen');
    wpbr_live_assert_contains($activated['body'], '1 / 3', 'sites used missing');
    $state = wpbr_live_read($live);
    $stored = $state['license'] ?? null;
    wpbr_live_assert(is_array($stored), 'license option missing after activate');
    $encoded = (string) json_encode($stored);
    wpbr_live_assert(!wpbr_live_contains($encoded, 'WPBR-TEST-KEY'), 'full key stored in the option');
    wpbr_live_assert(($stored['instance_token'] ?? '') === WPBR_LIVE_TOKEN, 'instance token mismatch');
    wpbr_live_assert(in_array($state['autoload'] ?? '', ['no', 'off', 'auto-off'], true), 'autoload is ' . json_encode($state['autoload'] ?? null));
    wpbr_live_assert(($state['licensed'] ?? false) === true, 'is_licensed() is false after activate');
    wpbr_live_assert(($state['expires_at'] ?? '') === '2027-11-15T00:00:00Z', 'expires_at mismatch');
    wpbr_live_assert_log_empty($log);
    echo 'EVIDENCE activate masked ********ABCD autoload=' . (string) $state['autoload'] . " is_licensed=true expires_at=2027-11-15T00:00:00Z\n";

    $plugins = wpbr_live_http('http://127.0.0.1:8090/wp-admin/plugins.php', $cookie);
    wpbr_live_assert($plugins['status'] === 200, 'licensed plugins.php status ' . $plugins['status']);
    wpbr_live_assert_contains($plugins['body'], 'License:', 'plugins row license status missing');
    wpbr_live_assert_contains($plugins['body'], 'Active', 'plugins row is not Active');
    wpbr_live_assert_log_empty($log);
    echo "EVIDENCE plugins row License: Active\n";

    $checked = wpbr_live_license_post($post, $cookie, $activated['body'], 'status');
    wpbr_live_assert($checked['status'] === 200, 'status check HTTP ' . $checked['status']);
    wpbr_live_assert_contains($checked['body'], 'License status updated.', 'status refresh missing');
    wpbr_live_assert(!wpbr_live_contains($checked['body'], $key), 'full key appeared after status');
    wpbr_live_assert_log_empty($log);
    echo "EVIDENCE status check refreshed the stored summary\n";

    $deactivated = wpbr_live_license_post($post, $cookie, $checked['body'], 'deactivate');
    wpbr_live_assert($deactivated['status'] === 200, 'deactivate status ' . $deactivated['status']);
    wpbr_live_assert_contains($deactivated['body'], 'License deactivated.', 'deactivate success missing');
    wpbr_live_assert_contains($deactivated['body'], 'name="license_key"', 'key field did not return');
    $state = wpbr_live_read($live);
    wpbr_live_assert($state['license'] === null, 'token remained after deactivate');
    wpbr_live_assert(($state['licensed'] ?? true) === false, 'is_licensed() stayed true after deactivate');
    wpbr_live_assert_log_empty($log);
    echo "EVIDENCE deactivate removed the token\n";

    $wrong = wpbr_live_license_post($post, $cookie, $deactivated['body'], 'activate', 'WPBR-WRONG-KEY');
    wpbr_live_assert($wrong['status'] === 200, 'wrong key status ' . $wrong['status']);
    wpbr_live_assert_contains($wrong['body'], 'License key not found.', 'wrong key message missing');
    $state = wpbr_live_read($live);
    wpbr_live_assert($state['license'] === null, 'wrong key stored a token');
    wpbr_live_assert_log_empty($log);
    echo "EVIDENCE wrong key rejected, nothing stored\n";

    FakeServer::stop();
    $downStarted = microtime(true);
    $down = wpbr_live_license_post($post, $cookie, $wrong['body'], 'activate', $key);
    $downElapsed = microtime(true) - $downStarted;
    wpbr_live_assert($down['status'] === 200, 'server down status ' . $down['status']);
    wpbr_live_assert($downElapsed < 15, 'server down blocked wp-admin for ' . $downElapsed);
    wpbr_live_assert_contains($down['body'], 'The license server could not be reached. The plugin keeps working.', 'server down message missing');
    wpbr_live_assert(!wpbr_live_contains($down['body'], 'There has been a critical error'), 'server down fatals');
    $state = wpbr_live_read($live);
    wpbr_live_assert($state['license'] === null, 'server down stored a token');
    wpbr_live_assert_log_empty($log);
    echo 'EVIDENCE server down license POST 200 elapsed ' . number_format($downElapsed, 2) . "s token not stored\n";

    FakeServer::start(FakeServer::baseState($zip, $sha));
    FakeServer::setMode('update', ['license_status' => 'expired']);
    $expired = wpbr_live_license_post($post, $cookie, $down['body'], 'activate', $key);
    wpbr_live_assert($expired['status'] === 200, 'expired activate status ' . $expired['status']);
    wpbr_live_assert_contains($expired['body'], 'License activated.', 'expired activate success missing');
    wpbr_live_assert_contains($expired['body'], 'Updates have stopped. The plugin keeps working.', 'expired notice missing');
    wpbr_live_assert_contains($expired['body'], '>Expired<', 'expired status missing');
    wpbr_live_assert(!wpbr_live_contains($expired['body'], $key), 'full key shown for an expired license');
    $state = wpbr_live_read($live);
    wpbr_live_assert(($state['licensed'] ?? false) === true, 'expired license made is_licensed() false');
    wpbr_live_assert(in_array(WPBR_LIVE_BASENAME, is_array($state['active'] ?? null) ? $state['active'] : [], true), 'expired license deactivated the plugin');
    $feature = trim(wpbr_live_proc([PHP_BINARY, __FILE__, 'ctl-feature', $live])['out']);
    wpbr_live_assert($feature === 'working', 'expired license broke plugin feature: ' . $feature);
    wpbr_live_assert_log_empty($log);
    echo "EVIDENCE expired license keeps the plugin working and is_licensed() true\n";

    FakeServer::setMode('expired');
    wpbr_live_reset_cache($live);
    $plugins = wpbr_live_http('http://127.0.0.1:8090/wp-admin/plugins.php', $cookie);
    wpbr_live_assert($plugins['status'] === 200, 'expired plugins.php status ' . $plugins['status']);
    wpbr_live_assert(!wpbr_live_contains($plugins['body'], 'There has been a critical error'), 'expired update check fatals');
    wpbr_live_assert_contains($plugins['body'], 'License:', 'expired plugins row missing');
    wpbr_live_assert_contains($plugins['body'], 'Expired', 'expired plugins row status missing');
    wpbr_live_assert_contains($plugins['body'], 'Updates have stopped. The plugin keeps working.', 'expired renew notice missing');
    $state = wpbr_live_read($live);
    wpbr_live_assert(($state['transient_version'] ?? 'x') === '', 'expired license still offered a package');
    $feature = trim(wpbr_live_proc([PHP_BINARY, __FILE__, 'ctl-feature', $live])['out']);
    wpbr_live_assert($feature === 'working', 'feature broke after expired update-info');
    wpbr_live_assert_log_empty($log);
    echo "EVIDENCE expired update-info offers no package, plugins.php 200, feature still working\n";

    $langDir = $live . '/wp-content/languages';
    if (!is_dir($langDir) && !mkdir($langDir, 0777, true) && !is_dir($langDir)) {
        throw new RuntimeException('Could not create the live languages directory.');
    }
    if (!copy(dirname(__DIR__) . '/languages/wpbr-updater-zh_TW.mo', $langDir . '/zh_TW.mo')) {
        throw new RuntimeException('Could not install the zh_TW locale.');
    }
    $lang = wpbr_live_proc([PHP_BINARY, __FILE__, 'ctl-lang', $live, 'zh_TW']);
    wpbr_live_assert($lang['code'] === 0, $lang['out']);
    $state = wpbr_live_read($live);
    wpbr_live_assert(($state['locale'] ?? '') === 'zh_TW', 'locale stayed ' . (string) ($state['locale'] ?? ''));
    $zh = wpbr_live_http($page, $cookie);
    wpbr_live_assert($zh['status'] === 200, 'zh_TW license screen status ' . $zh['status']);
    wpbr_live_assert_contains($zh['body'], '授權金鑰', 'zh_TW key label missing');
    wpbr_live_assert_contains($zh['body'], '已過期', 'zh_TW expired status missing');
    wpbr_live_assert_contains($zh['body'], '外掛仍可繼續使用', 'zh_TW keep-working copy missing');
    wpbr_live_assert_contains($zh['body'], '停用', 'zh_TW deactivate missing');
    $zhPlugins = wpbr_live_http('http://127.0.0.1:8090/wp-admin/plugins.php', $cookie);
    wpbr_live_assert($zhPlugins['status'] === 200, 'zh_TW plugins.php status ' . $zhPlugins['status']);
    wpbr_live_assert_contains($zhPlugins['body'], '授權：已過期', 'zh_TW plugins row missing');
    wpbr_live_assert_contains($zhPlugins['body'], '外掛仍可繼續使用', 'zh_TW plugins notice missing');
    wpbr_live_assert_log_empty($log);
    echo "EVIDENCE zh_TW license screen and plugins row use the catalog\n";
}

/**
 * @return array{status: int, body: string}
 */
function wpbr_live_license_post(string $url, string $cookie, string $html, string $action, string $key = ''): array
{
    $fields = [
        'action' => 'wpbr_license_wpbr-dummy',
        'wpbr_license_do' => $action,
        'wpbr_license_nonce' => wpbr_live_nonce($html),
    ];
    if ($key !== '') {
        $fields['license_key'] = $key;
    }

    return wpbr_live_post($url, $cookie, $fields);
}

function wpbr_live_nonce(string $html): string
{
    if (!preg_match('/name="wpbr_license_nonce" value="([a-zA-Z0-9]+)"/', $html, $match)) {
        throw new RuntimeException('License nonce missing.');
    }

    return $match[1];
}

/**
 * @param array<string, string> $fields
 * @return array{status: int, body: string}
 */
function wpbr_live_post(string $url, string $cookie, array $fields, int $maxTime = 30): array
{
    $bodyFile = tempnam(sys_get_temp_dir(), 'wpbr-body');
    if ($bodyFile === false) {
        throw new RuntimeException('tempnam failed');
    }
    $command = sprintf(
        'curl -sS -L --max-redirs 5 --max-time %d -o %s -w %%{http_code} -b %s',
        $maxTime,
        escapeshellarg($bodyFile),
        escapeshellarg($cookie)
    );
    foreach ($fields as $name => $value) {
        $command .= ' --data-urlencode ' . escapeshellarg($name . '=' . $value);
    }
    $command .= ' ' . escapeshellarg($url);
    $status = (int) shell_exec($command);
    $body = (string) file_get_contents($bodyFile);
    unlink($bodyFile);

    return ['status' => $status, 'body' => $body];
}

function wpbr_live_assert_contains(string $haystack, string $needle, string $message): void
{
    if (wpbr_live_contains($haystack, $needle)) {
        return;
    }
    $excerpt = preg_replace('/\s+/', ' ', $haystack);
    if (!is_string($excerpt)) {
        $excerpt = $haystack;
    }
    throw new RuntimeException($message . ' excerpt=' . substr($excerpt, 0, 500));
}
