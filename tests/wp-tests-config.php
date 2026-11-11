<?php

declare(strict_types=1);

/**
 * WordPress test-suite configuration. Credentials come from the environment.
 *
 * @license GPL-3.0-or-later
 */

$coreDir = getenv('WP_CORE_DIR');
if (!is_string($coreDir) || $coreDir === '') {
    $coreDir = '/tmp/wpbr-wp-core';
}

define('ABSPATH', rtrim($coreDir, '/') . '/');

define('DB_NAME', getenv('WP_TEST_DB_NAME') ?: 'wordpress_test');
define('DB_USER', getenv('WP_TEST_DB_USER') ?: 'root');
define('DB_PASSWORD', getenv('WP_TEST_DB_PASS') !== false ? (string) getenv('WP_TEST_DB_PASS') : 'root');
define('DB_HOST', getenv('WP_TEST_DB_HOST') ?: '127.0.0.1');
define('DB_CHARSET', 'utf8');
define('DB_COLLATE', '');

define('AUTH_KEY', 'wpbr-test-auth');
define('SECURE_AUTH_KEY', 'wpbr-test-secure-auth');
define('LOGGED_IN_KEY', 'wpbr-test-logged-in');
define('NONCE_KEY', 'wpbr-test-nonce');
define('AUTH_SALT', 'wpbr-test-auth-salt');
define('SECURE_AUTH_SALT', 'wpbr-test-secure-auth-salt');
define('LOGGED_IN_SALT', 'wpbr-test-logged-in-salt');
define('NONCE_SALT', 'wpbr-test-nonce-salt');

$table_prefix = 'wptests_';

define('WP_DEBUG', true);
define('WP_DEBUG_DISPLAY', false);
define('FS_METHOD', 'direct');
define('WP_TESTS_MULTISITE', false);
define('WP_TESTS_DOMAIN', '127.0.0.1');
define('WP_TESTS_EMAIL', 'admin@example.org');
define('WP_TESTS_TITLE', 'WPBR Updater Tests');
define('WP_PHP_BINARY', PHP_BINARY);
define('WPLANG', '');
