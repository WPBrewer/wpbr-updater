# wpbr-updater

Update client for one WordPress plugin. PHP >= 7.4. GPL-3.0-or-later.

`Updater::VERSION` is `1.0.0`. Composer installs this package from the git tag. There is no Packagist release.

## Install

After `v1.0.0` is tagged on the merge commit:

```json
{
    "repositories": [
        {
            "type": "vcs",
            "url": "https://github.com/WPBrewer/wpbr-updater"
        }
    ],
    "require": {
        "php": ">=7.4",
        "wpbrewer/wpbr-updater": "^1.0.0"
    }
}
```

## Strauss

Each plugin vendors its own prefixed copy. Two plugins can ship different updater versions. The classes do not collide.

`examples/wpbr-updater-demo` is that plugin. Copy its `composer.json` into the plugin. The path repository there points at this checkout, with Composer's `versions` option set to `1.0.0`, so the example builds before the tag exists. A published plugin replaces the path repository with the VCS repository above.

`brianhenryie/strauss` 0.31.1 still installs on PHP 7.4 only if Composer is allowed to use `league/flysystem` 2.5.0. Composer blocks that release (`CVE-2026-102601`, fixed in 3.35.3, which needs PHP 8). Flysystem is a Strauss dependency. It is not part of the prefixed updater. Pass `--no-security-blocking` when installing Strauss on PHP 7.4:

```bash
composer update --no-security-blocking
php -d memory_limit=1G vendor/bin/strauss
```

```json
"extra": {
    "strauss": {
        "target_directory": "vendor-prefixed",
        "namespace_prefix": "WpbrDemo\\",
        "classmap_prefix": "WpbrDemo_",
        "constant_prefix": "WPBR_DEMO_",
        "packages": [
            "wpbrewer/wpbr-updater"
        ],
        "update_call_sites": [
            "wpbr-updater-demo.php"
        ],
        "delete_vendor_packages": true,
        "exclude_from_copy": {
            "file_patterns": [
                "#/tests/#",
                "#/\\.github/#"
            ]
        },
        "parallel": false
    }
}
```

`namespace_prefix` belongs to the plugin (`Acme\\Ezpay\\`, `Acme\\Payuni\\`). Strauss rewrites `WPBrewer\Updater` in `wpbr-updater-demo.php` and under `vendor-prefixed/`. `delete_vendor_packages` drops the unprefixed copy.

The plugin requires `vendor-prefixed/autoload.php` and calls `Updater::register()` once. Do not load `vendor/autoload.php` from the plugin. That autoloader still describes the unprefixed package.

```php
require_once __DIR__ . '/vendor-prefixed/autoload.php';

use WPBrewer\Updater\Updater;

Updater::register([
    'file' => __FILE__,
    'slug' => 'wpbr-ezpay-einvoice',
    'server' => 'https://wpbrewer.com',
    'version' => '1.0.0',
    'license_option' => 'wpbr_ezpay_license',
]);
```

## Coexistence

`php tests/coexist-run.php` builds two plugins with Strauss. One vendors this tree (`1.0.0`, prefix `WpbrDemoOne`). The other vendors commit `0f9c2f4` (`0.2.0`, prefix `WpbrDemoTwo`). It activates both on one WordPress site and checks the update transient for both against the stub server.

Needs `composer install` and `bash tests/install-wp-tests.sh` first. Database credentials are `WP_TEST_DB_USER`, `WP_TEST_DB_PASS`, and `WP_TEST_DB_HOST` (defaults `wp` / `wp` / `127.0.0.1`).
