<?php

/**
 * Plugin Name: WPBrewer Updater Demo
 * Description: One Updater::register() call. Run Strauss before shipping. See the package README.
 * Version: 1.0.0
 * Author: WPBrewer
 * License: GPL-3.0-or-later
 *
 * @license GPL-3.0-or-later
 */

use WPBrewer\Updater\Updater;

$wpbrAutoload = __DIR__ . '/vendor-prefixed/autoload.php';
if (is_file($wpbrAutoload)) {
    require_once $wpbrAutoload;
    Updater::register([
        'file' => __FILE__,
        'slug' => 'wpbr-updater-demo',
        'server' => 'https://wpbrewer.com',
        'version' => '1.0.0',
        'license_option' => 'wpbr_updater_demo_license',
    ]);
}
