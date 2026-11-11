<?php

declare(strict_types=1);

/**
 * Two Strauss-prefixed plugins on one WordPress site.
 *
 * Plugin one vendors this tree (1.0.0, prefix WpbrDemoOne).
 * Plugin two vendors 0f9c2f4 (0.2.0, prefix WpbrDemoTwo).
 *
 * Usage: php tests/coexist-run.php
 * Needs composer install, then tests/install-wp-tests.sh (run automatically
 * when WordPress core is missing). DB: WP_TEST_DB_USER / WP_TEST_DB_PASS /
 * WP_TEST_DB_HOST, defaults wp / wp / 127.0.0.1.
 *
 * @license GPL-3.0-or-later
 */

use WPBrewer\Updater\Tests\Support\FakeServer;

const WPBR_COEXIST_OLDER = '0f9c2f46cb6240c20d6b73b2103248d9e5b5ad9f';
const WPBR_COEXIST_PORT = 8092;
const WPBR_COEXIST_TOKEN = '0123456789abcdef0123456789abcdef';
const WPBR_COEXIST_ONE = 'wpbr-demo-one/wpbr-updater-demo.php';
const WPBR_COEXIST_TWO = 'wpbr-demo-two/wpbr-updater-demo.php';

$command = $argv[1] ?? 'run';
if (strncmp($command, 'ctl-', 4) === 0) {
    wpbr_coexist_ctl($command);
    exit(0);
}

try {
    wpbr_coexist_run();
} catch (Throwable $exception) {
    fwrite(STDERR, $exception->getMessage() . "\n");
    exit(1);
}

function wpbr_coexist_run(): void
{
    $root = dirname(__DIR__);
    if (!is_file($root . '/vendor/autoload.php')) {
        throw new RuntimeException('Run composer install first.');
    }
    require $root . '/vendor/autoload.php';

    $composer = getenv('COMPOSER_BIN');
    $composer = is_string($composer) && $composer !== '' ? $composer : 'composer';
    $core = getenv('WP_CORE_DIR') ?: '/tmp/wpbr-wp-core';
    if (!is_file($core . '/wp-settings.php')) {
        echo "Installing WordPress core\n";
        $install = wpbr_coexist_proc(['bash', $root . '/tests/install-wp-tests.sh'], $root);
        if ($install['code'] !== 0) {
            throw new RuntimeException($install['out']);
        }
    }

    $work = '/tmp/wpbr-coexist-build';
    wpbr_coexist_reset_dir($work);
    $current = $work . '/pkg-1.0.0';
    $older = $work . '/pkg-0.2.0';
    wpbr_coexist_export_current($root, $current);
    wpbr_coexist_export_older($root, $older);

    $specs = [
        [
            'dir' => $work . '/wpbr-demo-one',
            'slug' => 'wpbr-demo-one',
            'name' => 'WPBrewer Demo One',
            'option' => 'wpbr_demo_one_license',
            'prefix' => 'WpbrDemoOne',
            'classmap' => 'WpbrDemoOne_',
            'constant' => 'WPBR_DEMO_ONE_',
            'constraint' => '^1.0.0',
            'alias' => '1.0.0',
            'library' => '1.0.0',
            'package' => $current,
            'basename' => WPBR_COEXIST_ONE,
        ],
        [
            'dir' => $work . '/wpbr-demo-two',
            'slug' => 'wpbr-demo-two',
            'name' => 'WPBrewer Demo Two',
            'option' => 'wpbr_demo_two_license',
            'prefix' => 'WpbrDemoTwo',
            'classmap' => 'WpbrDemoTwo_',
            'constant' => 'WPBR_DEMO_TWO_',
            'constraint' => '0.2.0',
            'alias' => '0.2.0',
            'library' => '0.2.0',
            'package' => $older,
            'basename' => WPBR_COEXIST_TWO,
        ],
    ];

    foreach ($specs as $spec) {
        wpbr_coexist_build($root, $composer, $spec);
    }

    $live = '/tmp/wpbr-coexist';
    $log = $live . '/wp-content/debug.log';
    echo "Preparing WordPress at {$live}\n";
    wpbr_coexist_reset_tree($core, $live);
    wpbr_coexist_write_config($live);
    wpbr_coexist_write_mu_plugin($live);
    foreach ($specs as $spec) {
        wpbr_coexist_install_plugin($spec['dir'], $live . '/wp-content/plugins/' . $spec['slug']);
    }
    wpbr_coexist_create_database();

    $php = PHP_BINARY;
    $installed = wpbr_coexist_proc([$php, __FILE__, 'ctl-install', $live]);
    if ($installed['code'] !== 0) {
        throw new RuntimeException($installed['out']);
    }
    echo $installed['out'];
    wpbr_coexist_clear_log($log);

    $zip = FakeServer::buildZip('1.2.0');
    $sha = hash_file('sha256', $zip);
    if (!is_string($sha)) {
        throw new RuntimeException('Could not hash the package.');
    }
    FakeServer::start(FakeServer::baseState($zip, $sha));

    $server = proc_open(
        [$php, '-S', '127.0.0.1:' . WPBR_COEXIST_PORT, '-t', $live],
        [
            1 => ['file', '/tmp/wpbr-coexist-http.log', 'w'],
            2 => ['file', '/tmp/wpbr-coexist-http.log', 'a'],
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

    $base = 'http://127.0.0.1:' . WPBR_COEXIST_PORT;
    wpbr_coexist_wait($base . '/wp-login.php');
    $cookie = trim(wpbr_coexist_proc([$php, __FILE__, 'ctl-cookie', $live])['out']);
    $plugins = wpbr_coexist_http($base . '/wp-admin/plugins.php', $cookie);
    wpbr_coexist_assert($plugins['status'] === 200, 'plugins.php status ' . $plugins['status']);
    wpbr_coexist_assert(strpos($plugins['body'], 'name="log"') === false, 'plugins.php showed the login form');
    wpbr_coexist_assert(strpos($plugins['body'], 'critical error') === false, 'plugins.php fatals');
    wpbr_coexist_assert(strpos($plugins['body'], 'WPBrewer Demo One') !== false, 'demo one missing from plugins.php');
    wpbr_coexist_assert(strpos($plugins['body'], 'WPBrewer Demo Two') !== false, 'demo two missing from plugins.php');
    wpbr_coexist_assert_log_empty($log);
    echo "EVIDENCE plugins.php 200 shows both plugins, debug.log empty\n";

    $read = wpbr_coexist_proc([$php, __FILE__, 'ctl-read', $live]);
    if ($read['code'] !== 0) {
        throw new RuntimeException($read['out']);
    }
    $state = json_decode($read['out'], true);
    if (!is_array($state)) {
        throw new RuntimeException('ctl-read did not return JSON: ' . $read['out']);
    }

    wpbr_coexist_assert(($state['one_exists'] ?? false) === true, 'prefixed 1.0.0 class missing');
    wpbr_coexist_assert(($state['two_exists'] ?? false) === true, 'prefixed 0.2.0 class missing');
    wpbr_coexist_assert(($state['unprefixed'] ?? true) === false, 'unprefixed WPBrewer\\Updater\\Updater is loaded');
    wpbr_coexist_assert(($state['one_version'] ?? '') === '1.0.0', 'demo one library version ' . ($state['one_version'] ?? ''));
    wpbr_coexist_assert(($state['two_version'] ?? '') === '0.2.0', 'demo two library version ' . ($state['two_version'] ?? ''));
    wpbr_coexist_assert(($state['one_active'] ?? false) === true, 'demo one is not active');
    wpbr_coexist_assert(($state['two_active'] ?? false) === true, 'demo two is not active');
    wpbr_coexist_assert(($state['one_new'] ?? '') === '1.2.0', 'demo one transient ' . ($state['one_new'] ?? ''));
    wpbr_coexist_assert(($state['two_new'] ?? '') === '1.2.0', 'demo two transient ' . ($state['two_new'] ?? ''));
    wpbr_coexist_assert(strpos((string) ($state['one_package'] ?? ''), '127.0.0.1:8080/wpbr-download/') !== false, 'demo one package missing');
    wpbr_coexist_assert(strpos((string) ($state['two_package'] ?? ''), '127.0.0.1:8080/wpbr-download/') !== false, 'demo two package missing');
    wpbr_coexist_assert_log_empty($log);

    echo "EVIDENCE classes WpbrDemoOne\\WPBrewer\\Updater\\Updater 1.0.0 and WpbrDemoTwo\\WPBrewer\\Updater\\Updater 0.2.0, unprefixed class absent\n";
    echo "EVIDENCE transient {$state['one_basename']} new_version=1.2.0 package={$state['one_package']}\n";
    echo "EVIDENCE transient {$state['two_basename']} new_version=1.2.0 package={$state['two_package']}\n";
    echo "EVIDENCE wordpress {$state['wp_version']} debug.log empty\n";
    echo "COEXIST OK\n";
}

/**
 * @param array<string, string> $spec
 */
function wpbr_coexist_build(string $root, string $composer, array $spec): void
{
    echo "Building {$spec['slug']} updater {$spec['library']} prefix {$spec['prefix']}\n";
    wpbr_coexist_reset_dir($spec['dir']);
    $template = $root . '/examples/wpbr-updater-demo';
    $php = file_get_contents($template . '/wpbr-updater-demo.php');
    if (!is_string($php)) {
        throw new RuntimeException('Could not read the demo plugin.');
    }
    $php = str_replace('WPBrewer Updater Demo', $spec['name'], $php);
    $php = str_replace("'slug' => 'wpbr-updater-demo'", "'slug' => '{$spec['slug']}'", $php);
    $php = str_replace('https://wpbrewer.com', 'http://127.0.0.1:8080', $php);
    $php = str_replace('wpbr_updater_demo_license', $spec['option'], $php);
    if (file_put_contents($spec['dir'] . '/wpbr-updater-demo.php', $php) === false) {
        throw new RuntimeException('Could not write the demo plugin.');
    }

    $decoded = json_decode((string) file_get_contents($template . '/composer.json'), true);
    if (!is_array($decoded)) {
        throw new RuntimeException('Demo composer.json is invalid.');
    }
    $decoded['require']['wpbrewer/wpbr-updater'] = $spec['constraint'];
    $decoded['repositories'][0]['url'] = $spec['package'];
    if (!isset($decoded['repositories'][0]['options']) || !is_array($decoded['repositories'][0]['options'])) {
        $decoded['repositories'][0]['options'] = [];
    }
    if (!isset($decoded['repositories'][0]['options']['versions']) || !is_array($decoded['repositories'][0]['options']['versions'])) {
        $decoded['repositories'][0]['options']['versions'] = [];
    }
    $decoded['repositories'][0]['options']['versions']['wpbrewer/wpbr-updater'] = $spec['alias'];
    $decoded['extra']['strauss']['namespace_prefix'] = $spec['prefix'] . '\\';
    $decoded['extra']['strauss']['classmap_prefix'] = $spec['classmap'];
    $decoded['extra']['strauss']['constant_prefix'] = $spec['constant'];
    $encoded = json_encode($decoded, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
    if (!is_string($encoded)) {
        throw new RuntimeException('Could not encode composer.json.');
    }
    file_put_contents($spec['dir'] . '/composer.json', $encoded . "\n");

    $update = wpbr_coexist_proc(
        [$composer, 'update', '--no-interaction', '--prefer-dist', '--no-progress', '--no-plugins', '--no-security-blocking'],
        $spec['dir']
    );
    if ($update['code'] !== 0) {
        throw new RuntimeException($update['out']);
    }
    $strauss = wpbr_coexist_proc(
        [PHP_BINARY, '-d', 'memory_limit=1G', $spec['dir'] . '/vendor/bin/strauss'],
        $spec['dir']
    );
    if ($strauss['code'] !== 0) {
        throw new RuntimeException($strauss['out']);
    }

    $updater = (string) file_get_contents($spec['dir'] . '/vendor-prefixed/wpbrewer/wpbr-updater/src/Updater.php');
    $main = (string) file_get_contents($spec['dir'] . '/wpbr-updater-demo.php');
    $namespace = 'namespace ' . $spec['prefix'] . '\\WPBrewer\\Updater;';
    $use = 'use ' . $spec['prefix'] . '\\WPBrewer\\Updater\\Updater;';
    if (strpos($updater, $namespace) === false) {
        throw new RuntimeException($spec['slug'] . ' namespace was not prefixed.');
    }
    if (strpos($updater, "const VERSION = '{$spec['library']}'") === false) {
        throw new RuntimeException($spec['slug'] . ' library version was not ' . $spec['library']);
    }
    if (strpos($main, $use) === false) {
        throw new RuntimeException($spec['slug'] . ' call site was not rewritten: ' . $main);
    }
    if (is_dir($spec['dir'] . '/vendor/wpbrewer')) {
        throw new RuntimeException($spec['slug'] . ' still has the unprefixed package.');
    }
}

function wpbr_coexist_export_current(string $root, string $dest): void
{
    wpbr_coexist_mkdir($dest);
    foreach (['composer.json', 'LICENSE'] as $file) {
        if (!copy($root . '/' . $file, $dest . '/' . $file)) {
            throw new RuntimeException('Could not copy ' . $file);
        }
    }
    wpbr_coexist_copy_dir($root . '/src', $dest . '/src');
    wpbr_coexist_copy_dir($root . '/languages', $dest . '/languages');
}

function wpbr_coexist_export_older(string $root, string $dest): void
{
    $check = wpbr_coexist_proc(['git', '-C', $root, 'cat-file', '-e', WPBR_COEXIST_OLDER . '^{commit}']);
    if ($check['code'] !== 0) {
        echo "Fetching " . WPBR_COEXIST_OLDER . "\n";
        $fetch = wpbr_coexist_proc(['git', '-C', $root, 'fetch', '--depth=1', 'origin', WPBR_COEXIST_OLDER]);
        if ($fetch['code'] !== 0) {
            throw new RuntimeException($fetch['out']);
        }
    }
    wpbr_coexist_mkdir($dest);
    $command = 'set -o pipefail; git -C ' . escapeshellarg($root)
        . ' archive ' . escapeshellarg(WPBR_COEXIST_OLDER)
        . ' | tar -x -C ' . escapeshellarg($dest);
    $archive = wpbr_coexist_proc(['bash', '-lc', $command]);
    if ($archive['code'] !== 0) {
        throw new RuntimeException($archive['out']);
    }
}

function wpbr_coexist_install_plugin(string $built, string $pluginDir): void
{
    wpbr_coexist_reset_dir($pluginDir);
    if (!copy($built . '/wpbr-updater-demo.php', $pluginDir . '/wpbr-updater-demo.php')) {
        throw new RuntimeException('Could not copy the built plugin.');
    }
    wpbr_coexist_copy_dir($built . '/vendor-prefixed', $pluginDir . '/vendor-prefixed');
}

function wpbr_coexist_ctl(string $command): void
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
        wp_install('WPBR Coexist', 'admin', 'admin@example.org', false, '', 'admin-pass', 'en_US');
        foreach ([WPBR_COEXIST_ONE, WPBR_COEXIST_TWO] as $basename) {
            $activated = activate_plugin($basename);
            if (is_wp_error($activated)) {
                fwrite(STDERR, $activated->get_error_message() . "\n");
                exit(1);
            }
        }
        update_option('wpbr_demo_one_license', ['instance_token' => WPBR_COEXIST_TOKEN], false);
        update_option('wpbr_demo_two_license', ['instance_token' => WPBR_COEXIST_TOKEN], false);
        echo 'installed wordpress ' . get_bloginfo('version') . "\n";
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

    if ($command === 'ctl-read') {
        $one = 'WpbrDemoOne\\WPBrewer\\Updater\\Updater';
        $two = 'WpbrDemoTwo\\WPBrewer\\Updater\\Updater';
        $transient = get_site_transient('update_plugins');
        $oneItem = is_object($transient) && isset($transient->response[WPBR_COEXIST_ONE])
            ? $transient->response[WPBR_COEXIST_ONE]
            : null;
        $twoItem = is_object($transient) && isset($transient->response[WPBR_COEXIST_TWO])
            ? $transient->response[WPBR_COEXIST_TWO]
            : null;
        $active = get_option('active_plugins', []);
        if (!is_array($active)) {
            $active = [];
        }
        echo (string) json_encode([
            'wp_version' => get_bloginfo('version'),
            'one_exists' => class_exists($one, false),
            'two_exists' => class_exists($two, false),
            'unprefixed' => class_exists('WPBrewer\\Updater\\Updater', false),
            'one_version' => class_exists($one, false) ? (string) constant($one . '::VERSION') : '',
            'two_version' => class_exists($two, false) ? (string) constant($two . '::VERSION') : '',
            'one_active' => in_array(WPBR_COEXIST_ONE, $active, true),
            'two_active' => in_array(WPBR_COEXIST_TWO, $active, true),
            'one_basename' => WPBR_COEXIST_ONE,
            'two_basename' => WPBR_COEXIST_TWO,
            'one_new' => is_object($oneItem) ? (string) ($oneItem->new_version ?? '') : '',
            'two_new' => is_object($twoItem) ? (string) ($twoItem->new_version ?? '') : '',
            'one_package' => is_object($oneItem) ? (string) ($oneItem->package ?? '') : '',
            'two_package' => is_object($twoItem) ? (string) ($twoItem->package ?? '') : '',
        ]);
        return;
    }

    fwrite(STDERR, "Unknown command {$command}\n");
    exit(1);
}

/**
 * @return array{code: int, out: string}
 */
function wpbr_coexist_proc(array $command, ?string $cwd = null): array
{
    $process = proc_open(
        $command,
        [
            1 => ['pipe', 'w'],
            2 => ['pipe', 'w'],
        ],
        $pipes,
        $cwd
    );
    if (!is_resource($process)) {
        throw new RuntimeException('Could not run ' . implode(' ', $command));
    }
    $out = stream_get_contents($pipes[1]);
    $err = stream_get_contents($pipes[2]);
    fclose($pipes[1]);
    fclose($pipes[2]);
    return [
        'code' => proc_close($process),
        'out' => (is_string($out) ? $out : '') . (is_string($err) ? $err : ''),
    ];
}

function wpbr_coexist_http(string $url, string $cookie): array
{
    $handle = curl_init($url);
    if ($handle === false) {
        throw new RuntimeException('curl_init failed.');
    }
    curl_setopt_array($handle, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_COOKIE => $cookie,
        CURLOPT_TIMEOUT => 30,
        CURLOPT_FOLLOWLOCATION => true,
    ]);
    $body = curl_exec($handle);
    $status = (int) curl_getinfo($handle, CURLINFO_RESPONSE_CODE);
    curl_close($handle);
    return ['status' => $status, 'body' => is_string($body) ? $body : ''];
}

function wpbr_coexist_wait(string $url): void
{
    $deadline = microtime(true) + 15;
    while (microtime(true) < $deadline) {
        $handle = curl_init($url);
        if ($handle === false) {
            break;
        }
        curl_setopt_array($handle, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT => 2,
            CURLOPT_NOBODY => true,
        ]);
        curl_exec($handle);
        $status = (int) curl_getinfo($handle, CURLINFO_RESPONSE_CODE);
        curl_close($handle);
        if ($status >= 200 && $status < 500) {
            return;
        }
        usleep(100000);
    }
    throw new RuntimeException('WordPress did not answer at ' . $url);
}

function wpbr_coexist_reset_tree(string $core, string $live): void
{
    wpbr_coexist_reset_dir($live);
    wpbr_coexist_copy_dir($core, $live);
    wpbr_coexist_mkdir($live . '/wp-content/plugins');
    wpbr_coexist_mkdir($live . '/wp-content/mu-plugins');
}

function wpbr_coexist_write_config(string $live): void
{
    $user = getenv('WP_TEST_DB_USER') ?: 'wp';
    $pass = getenv('WP_TEST_DB_PASS');
    $pass = $pass === false ? 'wp' : $pass;
    $host = getenv('WP_TEST_DB_HOST') ?: '127.0.0.1';
    $port = (string) WPBR_COEXIST_PORT;
    $config = <<<PHP
<?php
define( 'DB_NAME', 'wordpress_coexist' );
define( 'DB_USER', '{$user}' );
define( 'DB_PASSWORD', '{$pass}' );
define( 'DB_HOST', '{$host}' );
define( 'DB_CHARSET', 'utf8mb4' );
define( 'DB_COLLATE', '' );
define( 'AUTH_KEY', 'wpbr-coexist-auth' );
define( 'SECURE_AUTH_KEY', 'wpbr-coexist-secure-auth' );
define( 'LOGGED_IN_KEY', 'wpbr-coexist-logged-in' );
define( 'NONCE_KEY', 'wpbr-coexist-nonce' );
define( 'AUTH_SALT', 'wpbr-coexist-auth-salt' );
define( 'SECURE_AUTH_SALT', 'wpbr-coexist-secure-auth-salt' );
define( 'LOGGED_IN_SALT', 'wpbr-coexist-logged-in-salt' );
define( 'NONCE_SALT', 'wpbr-coexist-nonce-salt' );
\$table_prefix = 'wp_';
define( 'WP_DEBUG', true );
define( 'WP_DEBUG_LOG', true );
define( 'WP_DEBUG_DISPLAY', false );
define( 'FS_METHOD', 'direct' );
define( 'DISABLE_WP_CRON', true );
define( 'WP_HOME', 'http://127.0.0.1:{$port}' );
define( 'WP_SITEURL', 'http://127.0.0.1:{$port}' );
if ( ! defined( 'ABSPATH' ) ) {
    define( 'ABSPATH', __DIR__ . '/' );
}
require ABSPATH . 'wp-settings.php';
PHP;
    file_put_contents($live . '/wp-config.php', $config);
}

function wpbr_coexist_write_mu_plugin(string $live): void
{
    $php = <<<'PHP'
<?php
/**
 * Keep api.wordpress.org from touching the network during the live run.
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
    file_put_contents($live . '/wp-content/mu-plugins/wpbr-block-wporg.php', $php);
}

function wpbr_coexist_create_database(): void
{
    $host = getenv('WP_TEST_DB_HOST') ?: '127.0.0.1';
    $user = getenv('WP_TEST_DB_USER') ?: 'wp';
    $pass = getenv('WP_TEST_DB_PASS');
    $pass = $pass === false ? 'wp' : $pass;
    $mysqli = new mysqli($host, $user, $pass, '', 3306);
    if ($mysqli->connect_error) {
        throw new RuntimeException($mysqli->connect_error);
    }
    if (
        !$mysqli->query('DROP DATABASE IF EXISTS `wordpress_coexist`')
        || !$mysqli->query('CREATE DATABASE `wordpress_coexist`')
    ) {
        throw new RuntimeException($mysqli->error);
    }
}

function wpbr_coexist_copy_dir(string $src, string $dest): void
{
    wpbr_coexist_mkdir($dest);
    $iterator = new RecursiveIteratorIterator(
        new RecursiveDirectoryIterator($src, FilesystemIterator::SKIP_DOTS),
        RecursiveIteratorIterator::SELF_FIRST
    );
    foreach ($iterator as $item) {
        if (!$item instanceof SplFileInfo) {
            continue;
        }
        $target = $dest . substr($item->getPathname(), strlen($src));
        if ($item->isDir()) {
            wpbr_coexist_mkdir($target);
            continue;
        }
        wpbr_coexist_mkdir(dirname($target));
        if (!copy($item->getPathname(), $target)) {
            throw new RuntimeException('Could not copy ' . $item->getPathname());
        }
    }
}

function wpbr_coexist_reset_dir(string $dir): void
{
    if (is_dir($dir)) {
        $iterator = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($dir, FilesystemIterator::SKIP_DOTS),
            RecursiveIteratorIterator::CHILD_FIRST
        );
        foreach ($iterator as $item) {
            if (!$item instanceof SplFileInfo) {
                continue;
            }
            if ($item->isDir()) {
                rmdir($item->getPathname());
            } else {
                unlink($item->getPathname());
            }
        }
        rmdir($dir);
    }
    wpbr_coexist_mkdir($dir);
}

function wpbr_coexist_mkdir(string $dir): void
{
    if (!is_dir($dir) && !mkdir($dir, 0777, true) && !is_dir($dir)) {
        throw new RuntimeException('Could not create ' . $dir);
    }
}

function wpbr_coexist_clear_log(string $log): void
{
    $dir = dirname($log);
    wpbr_coexist_mkdir($dir);
    file_put_contents($log, '');
}

function wpbr_coexist_assert_log_empty(string $log): void
{
    $text = is_file($log) ? (string) file_get_contents($log) : '';
    if (trim($text) !== '') {
        throw new RuntimeException("debug.log is not empty:\n" . $text);
    }
}

function wpbr_coexist_assert(bool $condition, string $message): void
{
    if (!$condition) {
        throw new RuntimeException($message);
    }
}
