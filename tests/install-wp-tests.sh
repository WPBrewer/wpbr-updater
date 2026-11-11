#!/usr/bin/env bash
# Download WordPress core for the PHPUnit suite and create the test database.
# License: GPL-3.0-or-later
set -euo pipefail

WP_VERSION="${WP_VERSION:-7.1.4}"
WP_CORE_DIR="${WP_CORE_DIR:-/tmp/wpbr-wp-core}"
DB_NAME="${WP_TEST_DB_NAME:-wordpress_test}"
DB_USER="${WP_TEST_DB_USER:-root}"
DB_PASS="${WP_TEST_DB_PASS:-root}"
DB_HOST="${WP_TEST_DB_HOST:-127.0.0.1}"

if [[ ! -f "${WP_CORE_DIR}/wp-settings.php" ]]; then
    mkdir -p "${WP_CORE_DIR}"
    curl -fsSL "https://wordpress.org/wordpress-${WP_VERSION}.tar.gz" | tar -xz -C "${WP_CORE_DIR}" --strip-components=1
fi

php -r '
$host = getenv("WP_TEST_DB_HOST") ?: "127.0.0.1";
$user = getenv("WP_TEST_DB_USER") ?: "root";
$pass = getenv("WP_TEST_DB_PASS");
$pass = $pass === false ? "root" : $pass;
$name = getenv("WP_TEST_DB_NAME") ?: "wordpress_test";
$mysqli = new mysqli($host, $user, $pass, "", 3306);
if ($mysqli->connect_error) {
    fwrite(STDERR, $mysqli->connect_error . PHP_EOL);
    exit(1);
}
$escaped = $mysqli->real_escape_string($name);
if (!$mysqli->query("CREATE DATABASE IF NOT EXISTS `{$escaped}`")) {
    fwrite(STDERR, $mysqli->error . PHP_EOL);
    exit(1);
}
'

grep -E "wp_version = " "${WP_CORE_DIR}/wp-includes/version.php"
