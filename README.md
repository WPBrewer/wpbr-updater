# wpbr-updater

Update client for one WordPress plugin. PHP >= 7.4. GPL-3.0-or-later.

`Updater::VERSION` is `1.0.1`. Composer installs this package from the git tag. There is no Packagist release.

## Install

After `v1.0.1` is tagged on the merge commit:

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

`examples/wpbr-updater-demo` is that plugin. Copy its `composer.json` into the plugin. The path repository there points at this checkout, with Composer's `versions` option set to `1.0.1`, so the example builds before the tag exists. A published plugin replaces the path repository with the VCS repository above.

Run Strauss on PHP 8.1 or newer. The updater it writes under `vendor-prefixed/` still runs on PHP 7.4. Strauss is a build tool. Install it from `tools/strauss`, not from the plugin `require`. Its dependencies stay out of the plugin and out of `vendor-prefixed/`. On PHP 8.1+ that install resolves `league/flysystem` 3.36.0 (`CVE-2026-102601` is fixed in 3.35.3). Leave Composer's security blocking on.

```bash
composer update --working-dir=tools/strauss
composer update --working-dir=examples/wpbr-updater-demo
composer --working-dir=examples/wpbr-updater-demo prefix
```

`composer prefix` runs `tools/strauss/strauss.php` with the PHP that is running Composer. The demo keeps `"platform": {"php": "7.4.33"}` so the plugin resolve stays on the runtime you ship. `composer audit` for this package and for `tools/strauss` reports no advisories.

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
                "#/\\.github/#",
                "#/tools/#",
                "#/vendor/#"
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

`php tests/coexist-run.php build` on PHP 8.1+ installs `tools/strauss` and builds two plugins. One vendors this tree (`1.0.1`, prefix `WpbrDemoOne`). The other vendors commit `0f9c2f4` (`0.2.0`, prefix `WpbrDemoTwo`). `php tests/coexist-run.php exec` activates both on one WordPress site and checks the update transient for both against the stub server. CI builds on PHP 8.3 and runs `exec` on PHP 7.4. `php tests/coexist-run.php` does both on the current PHP, which must be 8.1+.

`exec` needs `composer install` and `bash tests/install-wp-tests.sh` first. Database credentials are `WP_TEST_DB_USER`, `WP_TEST_DB_PASS`, and `WP_TEST_DB_HOST` (defaults `wp` / `wp` / `127.0.0.1`).
