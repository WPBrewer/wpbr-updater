<?php

declare(strict_types=1);

namespace WPBrewer\Updater;

use Throwable;
use WP_Error;

/**
 * Update client for one WordPress plugin. No product-specific behavior.
 *
 * Register once from the plugin main file:
 *
 *     Updater::register([
 *         'file' => __FILE__,
 *         'slug' => 'wpbr-ezpay-einvoice',
 *         'server' => 'https://wpbrewer.com',
 *         'version' => '1.0.0',
 *         'license_option' => 'wpbr_ezpay_license',
 *     ]);
 *
 * `license_option` is an array with `instance_token` (the token from
 * POST /activate). Without a token the plugin is still listed in `no_update`,
 * so the auto-update toggle is available, and no package is offered.
 * The same option stores the license summary. It is saved with autoload off.
 * An expired license stops updates only. The plugin keeps working.
 *
 * @license GPL-3.0-or-later
 */
final class Updater
{
    public const VERSION = '1.0.1';

    private const API_TIMEOUT = 5;

    private const DOWNLOAD_TIMEOUT = 60;

    private const CACHE_TTL = 43200;

    private const BACKOFF_TTL = 3600;

    /** @var array<string, true> */
    private static array $registered = [];

    private string $slug;

    private string $file;

    private string $basename;

    private string $server;

    private string $version;

    private string $licenseOption;

    /**
     * @param array<string, mixed> $config
     */
    public static function register(array $config): void
    {
        try {
            $updater = new self($config);
        } catch (Throwable $exception) {
            return;
        }

        if (isset(self::$registered[$updater->slug])) {
            return;
        }

        self::$registered[$updater->slug] = true;
        $updater->hooks();
    }

    /**
     * True when this site holds an activation that has not been revoked.
     *
     * Expired is still licensed. Expiry stops updates, not the plugin.
     */
    public static function is_licensed(string $slug): bool
    {
        try {
            return License::isLicensed($slug);
        } catch (Throwable $exception) {
            return false;
        }
    }

    /**
     * UTC expiry from the stored license summary.
     *
     * Null means a lifetime license, or that no expiry is stored.
     */
    public static function expires_at(string $slug): ?string
    {
        try {
            return License::expiresAt($slug);
        } catch (Throwable $exception) {
            return null;
        }
    }

    /**
     * @param array<string, mixed> $config
     */
    private function __construct(array $config)
    {
        foreach (['file', 'slug', 'server', 'version', 'license_option'] as $key) {
            if (!isset($config[$key]) || !is_string($config[$key]) || $config[$key] === '') {
                throw new \InvalidArgumentException('Missing updater config: ' . $key);
            }
        }

        $slug = $config['slug'];
        if (strlen($slug) > 191 || preg_match('/^[a-z0-9]+(?:-[a-z0-9]+)*$/', $slug) !== 1) {
            throw new \InvalidArgumentException('Invalid updater slug.');
        }

        $version = $config['version'];
        if (preg_match('/^[0-9A-Za-z][0-9A-Za-z._+-]{0,31}$/', $version) !== 1) {
            throw new \InvalidArgumentException('Invalid updater version.');
        }

        $server = self::normalizeServer($config['server']);
        if ($server === null) {
            throw new \InvalidArgumentException('Invalid updater server.');
        }

        $option = $config['license_option'];
        if (strlen($option) > 191 || preg_match('/^[A-Za-z0-9_]+$/', $option) !== 1) {
            throw new \InvalidArgumentException('Invalid updater license_option.');
        }

        if (!function_exists('plugin_basename')) {
            throw new \RuntimeException('WordPress is not loaded.');
        }

        $this->slug = $slug;
        $this->file = $config['file'];
        $this->basename = plugin_basename($config['file']);
        $this->server = $server;
        $this->version = $version;
        $this->licenseOption = $option;
    }

    private function hooks(): void
    {
        add_filter('pre_set_site_transient_update_plugins', [$this, 'filterTransient']);
        add_filter('plugins_api', [$this, 'pluginsApi'], 10, 3);
        add_filter('upgrader_pre_download', [$this, 'guardDownload'], 10, 4);
        try {
            License::attach([
                'slug' => $this->slug,
                'file' => $this->file,
                'basename' => $this->basename,
                'server' => $this->server,
                'version' => $this->version,
                'license_option' => $this->licenseOption,
            ]);
        } catch (Throwable $exception) {
            return;
        }
    }

    /**
     * Always place this plugin in response or no_update.
     *
     * @param mixed $value
     * @return mixed
     */
    public function filterTransient($value)
    {
        try {
            if (!is_object($value)) {
                $value = new \stdClass();
            }
            if (!isset($value->response) || !is_array($value->response)) {
                $value->response = [];
            }
            if (!isset($value->no_update) || !is_array($value->no_update)) {
                $value->no_update = [];
            }

            unset($value->response[$this->basename], $value->no_update[$this->basename]);

            $data = $this->updatePayload();
            if (is_array($data) && $this->offer($data)) {
                $remote = $data['version'] ?? '';
                $package = $data['package'] ?? '';
                if (is_string($remote) && is_string($package) && $package !== '') {
                    $value->response[$this->basename] = $this->item($remote, $package, $data);
                    return $value;
                }
            }

            $meta = is_array($data) ? $data : [];
            $value->no_update[$this->basename] = $this->item($this->version, '', $meta);
            return $value;
        } catch (Throwable $exception) {
            return is_object($value) ? $value : new \stdClass();
        }
    }

    /**
     * Details modal. GET /info/{slug} supplies changelog, requires, and tested.
     *
     * @param mixed $result
     * @param mixed $action
     * @param mixed $args
     * @return mixed
     */
    public function pluginsApi($result, $action, $args)
    {
        try {
            if ($action !== 'plugin_information' || !$this->isOurSlug($args)) {
                return $result;
            }

            $info = $this->infoPayload();
            if (!is_array($info)) {
                return $result;
            }

            $name = $info['name'] ?? '';
            if (!is_string($name) || $name === '') {
                return $result;
            }

            $version = $info['version'] ?? null;
            if (!is_string($version) || $version === '') {
                $version = $this->version;
            }

            $changelog = $info['changelog'] ?? '';
            if (!is_string($changelog)) {
                $changelog = '';
            }

            return (object) [
                'name' => $name,
                'slug' => $this->slug,
                'version' => $version,
                'author' => 'WPBrewer',
                'homepage' => $this->server,
                'requires' => $this->stringOrEmpty($info['requires_wp'] ?? null),
                'tested' => $this->stringOrEmpty($info['tested'] ?? null),
                'requires_php' => $this->stringOrEmpty($info['requires_php'] ?? null),
                'download_link' => '',
                'sections' => [
                    'description' => esc_html($name),
                    'changelog' => nl2br(esc_html($changelog)),
                ],
            ];
        } catch (Throwable $exception) {
            return $result;
        }
    }

    /**
     * Download the package ourselves and refuse it unless the sha256 matches
     * the one stored from /update-info. Other plugins are left alone.
     *
     * @param mixed $reply
     * @param mixed $package
     * @param mixed $upgrader
     * @param mixed $hookExtra
     * @return mixed
     */
    public function guardDownload($reply, $package, $upgrader, $hookExtra)
    {
        unset($upgrader);

        try {
            $plugin = '';
            if (is_array($hookExtra) && isset($hookExtra['plugin']) && is_string($hookExtra['plugin'])) {
                $plugin = $hookExtra['plugin'];
            }
            if ($plugin !== $this->basename) {
                return $reply;
            }
            if (!is_string($package) || $package === '') {
                return new WP_Error('wpbr_updater_rejected', 'Refusing an unexpected package.');
            }

            $expected = $this->expectedPackage();
            if (
                $expected === null
                || !hash_equals($expected['package'], $package)
                || !$this->packageIsOurs($package)
            ) {
                return new WP_Error('wpbr_updater_rejected', 'Refusing an unexpected package.');
            }

            return $this->downloadVerified($package, $expected['sha256']);
        } catch (Throwable $exception) {
            return new WP_Error('wpbr_updater_error', 'Update could not be verified.');
        }
    }

    /**
     * @return array<string, mixed>|null
     */
    private function updatePayload(): ?array
    {
        $cached = $this->cached('update');
        if ($cached['hit']) {
            return $cached['failure'] ? null : $cached['data'];
        }

        $token = $this->token();
        $domain = Domain::normalize(function_exists('home_url') ? (string) home_url() : '');
        if ($token === '' || $domain === '') {
            return null;
        }

        $result = $this->request('POST', $this->endpoint('/update-info'), [
            'instance_token' => $token,
            'domain' => $domain,
            'product_slug' => $this->slug,
            'version' => $this->version,
        ]);

        if ($result['transport_error']) {
            $this->writeSlot('update', true, null, self::BACKOFF_TTL);
            return null;
        }

        $json = $result['json'];
        if ($result['status'] === 403 && is_array($json) && ($json['code'] ?? null) === 'license_expired') {
            License::markExpired($this->licenseOption);
            $data = ['update_available' => false];
            $this->writeSlot('update', false, $data, self::CACHE_TTL);
            return $data;
        }

        if ($result['status'] !== 200 || !is_array($json)) {
            $this->writeSlot('update', true, null, self::BACKOFF_TTL);
            return null;
        }

        $parsed = $this->parseUpdate($json);
        if ($parsed === null) {
            $this->writeSlot('update', true, null, self::BACKOFF_TTL);
            return null;
        }

        $license = $json['license'] ?? null;
        if (is_array($license)) {
            License::remember($this->licenseOption, $license);
        }

        $this->writeSlot('update', false, $parsed, self::CACHE_TTL);
        return $parsed;
    }

    /**
     * @return array<string, mixed>|null
     */
    private function infoPayload(): ?array
    {
        $cached = $this->cached('info');
        if ($cached['hit']) {
            return $cached['failure'] ? null : $cached['data'];
        }

        $result = $this->request('GET', $this->endpoint('/info/' . rawurlencode($this->slug)), null);
        if ($result['transport_error'] || $result['status'] !== 200 || !is_array($result['json'])) {
            $ttl = $result['status'] === 404 ? self::CACHE_TTL : self::BACKOFF_TTL;
            $this->writeSlot('info', true, null, $ttl);
            return null;
        }

        $parsed = $this->parseInfo($result['json']);
        if ($parsed === null) {
            $this->writeSlot('info', true, null, self::BACKOFF_TTL);
            return null;
        }

        $this->writeSlot('info', false, $parsed, self::CACHE_TTL);
        return $parsed;
    }

    /**
     * @param array<string, mixed> $json
     * @return array<string, mixed>|null
     */
    private function parseUpdate(array $json): ?array
    {
        if (!array_key_exists('update_available', $json) || !is_bool($json['update_available'])) {
            return null;
        }

        if ($json['update_available'] === false) {
            if (array_key_exists('package', $json) || array_key_exists('sha256', $json)) {
                return null;
            }
            return ['update_available' => false];
        }

        $version = $json['version'] ?? null;
        $package = $json['package'] ?? null;
        $sha = $json['sha256'] ?? null;
        $requiresWp = $json['requires_wp'] ?? null;
        $requiresPhp = $json['requires_php'] ?? null;
        $tested = $json['tested'] ?? null;
        $changelog = $json['changelog'] ?? null;
        $allow = $json['allow_downgrade'] ?? null;

        if (!is_string($version) || preg_match('/^[0-9A-Za-z][0-9A-Za-z._+-]{0,31}$/', $version) !== 1) {
            return null;
        }
        if (!is_string($package) || !$this->packageIsOurs($package)) {
            return null;
        }
        if (!is_string($sha) || preg_match('/^[a-f0-9]{64}$/', $sha) !== 1) {
            return null;
        }
        if (!is_string($requiresWp) || !is_string($requiresPhp) || !is_string($tested)) {
            return null;
        }
        if (!is_string($changelog) || strlen($changelog) > 100000) {
            return null;
        }
        if (!is_bool($allow)) {
            return null;
        }

        return [
            'update_available' => true,
            'version' => $version,
            'package' => $package,
            'sha256' => $sha,
            'requires_wp' => $requiresWp,
            'requires_php' => $requiresPhp,
            'tested' => $tested,
            'changelog' => $changelog,
            'allow_downgrade' => $allow,
        ];
    }

    /**
     * @param array<string, mixed> $json
     * @return array<string, mixed>|null
     */
    private function parseInfo(array $json): ?array
    {
        $slug = $json['slug'] ?? null;
        $name = $json['name'] ?? null;
        $changelog = $json['changelog'] ?? null;
        if ($slug !== $this->slug || !is_string($name) || $name === '' || strlen($name) > 255) {
            return null;
        }
        if (!is_string($changelog) || strlen($changelog) > 100000) {
            return null;
        }

        $parsed = [
            'name' => $name,
            'changelog' => $changelog,
        ];
        foreach (['version', 'requires_wp', 'requires_php', 'tested'] as $key) {
            if (!array_key_exists($key, $json)) {
                return null;
            }
            $value = $json[$key];
            if ($value === null) {
                $parsed[$key] = null;
                continue;
            }
            if (!is_string($value) || $value === '' || strlen($value) > 32) {
                return null;
            }
            $parsed[$key] = $value;
        }

        return $parsed;
    }

    /**
     * @param array<string, mixed> $data
     */
    private function offer(array $data): bool
    {
        if (($data['update_available'] ?? null) !== true) {
            return false;
        }

        $version = $data['version'] ?? null;
        $allow = $data['allow_downgrade'] ?? null;
        if (!is_string($version) || !is_bool($allow)) {
            return false;
        }

        $cmp = version_compare($version, $this->version);
        if ($cmp === 0) {
            return false;
        }

        return $cmp > 0 || $allow === true;
    }

    /**
     * @return array{package: string, sha256: string}|null
     */
    private function expectedPackage(): ?array
    {
        $cached = $this->cached('update');
        if (!$cached['hit'] || $cached['failure'] || !is_array($cached['data'])) {
            return null;
        }

        $data = $cached['data'];
        if (($data['update_available'] ?? null) !== true) {
            return null;
        }

        $package = $data['package'] ?? null;
        $sha = $data['sha256'] ?? null;
        if (!is_string($package) || !is_string($sha) || preg_match('/^[a-f0-9]{64}$/', $sha) !== 1) {
            return null;
        }
        if (!$this->packageIsOurs($package)) {
            return null;
        }

        return ['package' => $package, 'sha256' => $sha];
    }

    private function packageIsOurs(string $package): bool
    {
        $parts = parse_url($package);
        $server = parse_url($this->server);
        if (!is_array($parts) || !is_array($server)) {
            return false;
        }
        if (isset($parts['user']) || isset($parts['pass']) || isset($parts['fragment'])) {
            return false;
        }

        $scheme = strtolower((string) ($parts['scheme'] ?? ''));
        $serverScheme = strtolower((string) ($server['scheme'] ?? ''));
        if (($scheme !== 'http' && $scheme !== 'https') || $scheme !== $serverScheme) {
            return false;
        }

        $host = strtolower((string) ($parts['host'] ?? ''));
        $serverHost = strtolower((string) ($server['host'] ?? ''));
        if ($host === '' || $host !== $serverHost) {
            return false;
        }

        $port = isset($parts['port']) ? (int) $parts['port'] : ($scheme === 'https' ? 443 : 80);
        $serverPort = isset($server['port']) ? (int) $server['port'] : ($serverScheme === 'https' ? 443 : 80);
        if ($port !== $serverPort) {
            return false;
        }

        $path = (string) ($parts['path'] ?? '');
        if (preg_match('#/wpbr-download/[1-9][0-9]*$#', $path) !== 1) {
            return false;
        }

        $query = (string) ($parts['query'] ?? '');
        return preg_match('/^exp=[1-9][0-9]*&dom=[a-z0-9.-]+&sig=[a-f0-9]{64}$/', $query) === 1;
    }

    /**
     * @return string|WP_Error
     */
    private function downloadVerified(string $package, string $expected)
    {
        if (!defined('ABSPATH')) {
            return new WP_Error('wpbr_updater_download', 'Package download failed.');
        }
        if (!function_exists('wp_tempnam')) {
            require_once ABSPATH . 'wp-admin/includes/file.php';
        }

        $tmp = wp_tempnam($this->slug . '.zip');
        if ($tmp === '') {
            return new WP_Error('wpbr_updater_download', 'Could not create a temporary file.');
        }

        try {
            $response = wp_remote_get($package, [
                'timeout' => self::DOWNLOAD_TIMEOUT,
                'redirection' => 0,
                'stream' => true,
                'filename' => $tmp,
                'headers' => [
                    'User-Agent' => 'WPBrewer-Updater/' . self::VERSION,
                ],
            ]);
        } catch (Throwable $exception) {
            $this->unlink($tmp);
            return new WP_Error('wpbr_updater_download', 'Package download failed.');
        }

        if (!is_array($response) || (int) wp_remote_retrieve_response_code($response) !== 200) {
            $this->unlink($tmp);
            return new WP_Error('wpbr_updater_download', 'Package download failed.');
        }

        $actual = hash_file('sha256', $tmp);
        if (!is_string($actual) || !hash_equals($expected, $actual)) {
            $this->unlink($tmp);
            return new WP_Error('wpbr_updater_checksum', 'Package checksum does not match.');
        }

        return $tmp;
    }

    /**
     * @param array<string, string>|null $payload
     * @return array{status: int, json: array<string, mixed>|null, transport_error: bool}
     */
    private function request(string $method, string $url, ?array $payload): array
    {
        $error = ['status' => 0, 'json' => null, 'transport_error' => true];
        $args = [
            'timeout' => self::API_TIMEOUT,
            'redirection' => 0,
            'headers' => [
                'Accept' => 'application/json',
                'User-Agent' => 'WPBrewer-Updater/' . self::VERSION,
            ],
        ];

        try {
            if ($method === 'POST') {
                $body = wp_json_encode($payload ?? []);
                if (!is_string($body)) {
                    return $error;
                }
                $args['headers']['Content-Type'] = 'application/json';
                $args['body'] = $body;
                $response = wp_remote_post($url, $args);
            } else {
                $response = wp_remote_get($url, $args);
            }
        } catch (Throwable $exception) {
            return $error;
        }

        if (!is_array($response)) {
            return $error;
        }

        return [
            'status' => (int) wp_remote_retrieve_response_code($response),
            'json' => $this->decodeObject(wp_remote_retrieve_body($response)),
            'transport_error' => false,
        ];
    }

    /**
     * @return array<string, mixed>|null
     */
    private function decodeObject(string $raw): ?array
    {
        try {
            $decoded = json_decode($raw, true, 32, JSON_THROW_ON_ERROR);
        } catch (Throwable $exception) {
            return null;
        }
        if (!is_array($decoded) || self::isList($decoded)) {
            return null;
        }

        /** @var array<string, mixed> $decoded */
        return $decoded;
    }

    /**
     * @param array<string, mixed> $meta
     */
    private function item(string $newVersion, string $package, array $meta): \stdClass
    {
        return (object) [
            'id' => $this->slug,
            'slug' => $this->slug,
            'plugin' => $this->basename,
            'new_version' => $newVersion,
            'url' => $this->endpoint('/info/' . rawurlencode($this->slug)),
            'package' => $package,
            'icons' => [],
            'banners' => [],
            'banners_rtl' => [],
            'requires' => $this->stringOrEmpty($meta['requires_wp'] ?? null),
            'tested' => $this->stringOrEmpty($meta['tested'] ?? null),
            'requires_php' => $this->stringOrEmpty($meta['requires_php'] ?? null),
            'compatibility' => new \stdClass(),
        ];
    }

    private function token(): string
    {
        $stored = get_option($this->licenseOption, null);
        if (!is_array($stored)) {
            return '';
        }
        $token = $stored['instance_token'] ?? null;
        if (!is_string($token) || preg_match('/^[A-Za-z0-9_-]{32,255}$/', $token) !== 1) {
            return '';
        }
        return $token;
    }

    /**
     * @return array{hit: bool, failure: bool, data: array<string, mixed>|null}
     */
    private function cached(string $name): array
    {
        $miss = ['hit' => false, 'failure' => false, 'data' => null];
        $cache = get_option($this->cacheOption(), null);
        if (!is_array($cache) || !isset($cache[$name]) || !is_array($cache[$name])) {
            return $miss;
        }

        $slot = $cache[$name];
        $expires = $slot['expires_at'] ?? null;
        $failure = $slot['failure'] ?? null;
        if (!is_int($expires) || $expires <= time() || !is_bool($failure)) {
            return $miss;
        }

        $data = $slot['data'] ?? null;
        if ($data !== null && !is_array($data)) {
            return $miss;
        }

        /** @var array<string, mixed>|null $data */
        return ['hit' => true, 'failure' => $failure, 'data' => $data];
    }

    /**
     * @param array<string, mixed>|null $data
     */
    private function writeSlot(string $name, bool $failure, ?array $data, int $ttl): void
    {
        $cache = get_option($this->cacheOption(), null);
        if (!is_array($cache)) {
            $cache = [];
        }
        $cache[$name] = [
            'expires_at' => time() + $ttl,
            'failure' => $failure,
            'data' => $data,
        ];
        update_option($this->cacheOption(), $cache, false);
    }

    private function cacheOption(): string
    {
        $name = 'wpbr_updater_' . $this->slug;
        if (strlen($name) <= 191) {
            return $name;
        }
        return 'wpbr_updater_' . substr(hash('sha256', $this->slug), 0, 32);
    }

    private function endpoint(string $path): string
    {
        return $this->server . '/wp-json/wpbr-license/v1' . $path;
    }

    /**
     * @param mixed $value
     */
    private function stringOrEmpty($value): string
    {
        return is_string($value) ? $value : '';
    }

    /**
     * @param mixed $args
     */
    private function isOurSlug($args): bool
    {
        if (is_object($args) && isset($args->slug) && is_string($args->slug)) {
            return $args->slug === $this->slug;
        }
        if (is_array($args) && isset($args['slug']) && is_string($args['slug'])) {
            return $args['slug'] === $this->slug;
        }
        return false;
    }

    private static function normalizeServer(string $server): ?string
    {
        $parts = parse_url($server);
        if (!is_array($parts)) {
            return null;
        }
        if (isset($parts['user']) || isset($parts['pass']) || isset($parts['query']) || isset($parts['fragment'])) {
            return null;
        }

        $scheme = strtolower((string) ($parts['scheme'] ?? ''));
        if ($scheme !== 'http' && $scheme !== 'https') {
            return null;
        }

        $host = strtolower((string) ($parts['host'] ?? ''));
        if ($host === '') {
            return null;
        }

        $port = isset($parts['port']) ? ':' . (int) $parts['port'] : '';
        $path = isset($parts['path']) ? rtrim((string) $parts['path'], '/') : '';
        return $scheme . '://' . $host . $port . $path;
    }

    /**
     * JSON arrays are lists. An empty object decodes to an empty list in PHP.
     *
     * @param array<mixed> $value
     */
    private static function isList(array $value): bool
    {
        foreach (array_keys($value) as $index => $key) {
            if ($key !== $index) {
                return false;
            }
        }

        return true;
    }

    private function unlink(string $path): void
    {
        if (is_file($path)) {
            unlink($path);
        }
    }
}
