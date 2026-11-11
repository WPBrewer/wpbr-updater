<?php

declare(strict_types=1);

/**
 * Boots the WordPress test suite, then the library.
 *
 * @license GPL-3.0-or-later
 */

require_once dirname(__DIR__) . '/vendor/autoload.php';

define('WP_TESTS_PHPUNIT_POLYFILLS_PATH', dirname(__DIR__) . '/vendor/yoast/phpunit-polyfills');

$testsDir = getenv('WP_PHPUNIT__DIR');
if (!is_string($testsDir) || $testsDir === '') {
    $testsDir = dirname(__DIR__) . '/vendor/wp-phpunit/wp-phpunit';
}

$coreDir = getenv('WP_CORE_DIR');
if (!is_string($coreDir) || !is_file(rtrim($coreDir, '/') . '/wp-settings.php')) {
    fwrite(STDERR, "WordPress core is missing. Run tests/install-wp-tests.sh first.\n");
    exit(1);
}

require $testsDir . '/includes/functions.php';

tests_add_filter('pre_http_request', static function ($preempt, $args, $url) {
    unset($args);
    if (!is_string($url)) {
        return $preempt;
    }
    $host = parse_url($url, PHP_URL_HOST);
    if (!is_string($host)) {
        return $preempt;
    }
    if (!in_array($host, ['api.wordpress.org', 'downloads.wordpress.org', 'wordpress.org'], true)) {
        return $preempt;
    }

    $body = '{"offers":[],"translations":[]}';
    if (strpos($url, '/plugins/update-check/') !== false) {
        $body = '{"plugins":{},"translations":[],"no_update":{}}';
    } elseif (strpos($url, '/themes/update-check/') !== false) {
        $body = '{"themes":{},"translations":[],"no_update":{}}';
    }

    return [
        'headers' => ['content-type' => 'application/json'],
        'body' => $body,
        'response' => [
            'code' => 200,
            'message' => 'OK',
        ],
        'cookies' => [],
        'filename' => null,
    ];
}, 10, 3);

require $testsDir . '/includes/bootstrap.php';
