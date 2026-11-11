# Changelog

## 1.0.1

Strauss runs on PHP 8.1+. The prefixed updater still runs on PHP 7.4. `composer audit` stays on, so the build does not install `league/flysystem` 2.5.0 (`CVE-2026-102601`).

## 1.0.0

Package the update client for Composer and Strauss. `Updater::VERSION` is 1.0.0. `examples/wpbr-updater-demo` registers it with one `Updater::register()` call.

## 0.2.0

License screen, plugins-row status, renew notice, `is_licensed()`, and `expires_at()`.

## 0.1.0

Update client. Fills `response` or `no_update`, serves the details modal, and checks `sha256` before install.
