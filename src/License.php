<?php

declare(strict_types=1);

namespace WPBrewer\Updater;

use Throwable;

/**
 * License screen for one registered plugin.
 *
 * Plain form posts. No JavaScript. The license key is not stored; after
 * activation the screen shows a mask. The option is saved with autoload off.
 * An expired license does not deactivate the plugin.
 *
 * @license GPL-3.0-or-later
 */
final class License
{
    private const RENEW_WITHIN = 1209600;

    /** @var array<string, self> */
    private static array $screens = [];

    private string $slug;

    private string $file;

    private string $basename;

    private string $server;

    private string $version;

    private string $option;

    private string $name = '';

    private bool $menuAdded = false;

    /**
     * @param array<string, string> $config
     */
    public static function attach(array $config): void
    {
        foreach (['slug', 'file', 'basename', 'server', 'version', 'license_option'] as $key) {
            if (!isset($config[$key]) || $config[$key] === '') {
                return;
            }
        }

        $slug = $config['slug'];
        if (isset(self::$screens[$slug])) {
            return;
        }

        $screen = new self($config);
        self::$screens[$slug] = $screen;
        $screen->hooks();
    }

    public static function isLicensed(string $slug): bool
    {
        $screen = self::$screens[$slug] ?? null;
        if (!$screen instanceof self) {
            return false;
        }

        $stored = $screen->read();
        if ($stored === null) {
            return false;
        }

        return $stored['status'] !== 'revoked';
    }

    public static function expiresAt(string $slug): ?string
    {
        $screen = self::$screens[$slug] ?? null;
        if (!$screen instanceof self) {
            return null;
        }

        $stored = $screen->read();
        if ($stored === null) {
            return null;
        }

        return $stored['expires_at'];
    }

    /**
     * @param array<mixed> $license
     */
    public static function remember(string $option, array $license): void
    {
        try {
            $summary = self::summary($license);
            if ($summary === null || !self::validOption($option)) {
                return;
            }

            $current = get_option($option, null);
            if (!is_array($current)) {
                return;
            }

            $token = self::tokenFrom($current);
            if ($token === '') {
                return;
            }

            $current['instance_token'] = $token;
            $current['status'] = $summary['status'];
            $current['expires_at'] = $summary['expires_at'];
            $current['sites_used'] = $summary['sites_used'];
            $current['sites_limit'] = $summary['sites_limit'];
            $current['key_hint'] = self::hintFrom($current);
            update_option($option, $current, false);
        } catch (Throwable $exception) {
            return;
        }
    }

    public static function markExpired(string $option): void
    {
        try {
            if (!self::validOption($option)) {
                return;
            }

            $current = get_option($option, null);
            if (!is_array($current) || self::tokenFrom($current) === '') {
                return;
            }

            $current['status'] = 'expired';
            update_option($option, $current, false);
        } catch (Throwable $exception) {
            return;
        }
    }

    public static function loadTextdomain(): void
    {
        if (!function_exists('determine_locale') || !function_exists('load_textdomain')) {
            return;
        }

        $locale = determine_locale();
        if (preg_match('/^[A-Za-z0-9_]+$/', $locale) !== 1) {
            return;
        }

        $mofile = dirname(__DIR__) . '/languages/wpbr-updater-' . $locale . '.mo';
        if (function_exists('unload_textdomain')) {
            unload_textdomain('wpbr-updater');
        }
        if (is_readable($mofile)) {
            load_textdomain('wpbr-updater', $mofile);
        }
    }

    /**
     * @param array<string, string> $config
     */
    private function __construct(array $config)
    {
        $this->slug = $config['slug'];
        $this->file = $config['file'];
        $this->basename = $config['basename'];
        $this->server = $config['server'];
        $this->version = $config['version'];
        $this->option = $config['license_option'];
    }

    private function hooks(): void
    {
        add_action('init', [self::class, 'loadTextdomain']);
        add_action('change_locale', [self::class, 'loadTextdomain']);
        add_action('admin_menu', [$this, 'menu']);
        add_action('admin_post_wpbr_license_' . $this->slug, [$this, 'handlePost']);
        add_action('admin_notices', [$this, 'notice']);
        add_filter('plugin_row_meta', [$this, 'rowMeta'], 10, 2);
        self::loadTextdomain();
    }

    public function menu(): void
    {
        if (!function_exists('add_plugins_page') && defined('ABSPATH')) {
            require_once ABSPATH . 'wp-admin/includes/plugin.php';
        }
        if (!function_exists('add_plugins_page') || $this->menuAdded) {
            return;
        }
        $this->menuAdded = true;

        add_plugins_page(
            sprintf(
                /* translators: %s: plugin name */
                __('%s license', 'wpbr-updater'),
                $this->pluginName()
            ),
            sprintf(
                /* translators: %s: plugin name */
                __('%s license', 'wpbr-updater'),
                $this->pluginName()
            ),
            'manage_options',
            $this->pageSlug(),
            [$this, 'render']
        );
    }

    public function handlePost(): void
    {
        if (!current_user_can('manage_options')) {
            wp_die(esc_html__('You are not allowed to manage this license.', 'wpbr-updater'), '', ['response' => 403]);
        }

        check_admin_referer('wpbr_license_' . $this->slug, 'wpbr_license_nonce');

        $action = '';
        if (isset($_POST['wpbr_license_do']) && is_string($_POST['wpbr_license_do'])) {
            $action = sanitize_key(wp_unslash($_POST['wpbr_license_do']));
        }

        try {
            if ($action === 'activate') {
                $this->activate();
            } elseif ($action === 'deactivate') {
                $this->deactivate();
            } elseif ($action === 'status') {
                $this->check();
            } else {
                $this->flash('error', $this->rejected());
            }
        } catch (Throwable $exception) {
            $this->flash('error', $this->unreachable());
        }

        $this->redirect();
    }

    public function notice(): void
    {
        if (!current_user_can('manage_options')) {
            return;
        }

        $renew = $this->renewState();
        if ($renew !== 'expired' && $renew !== 'soon') {
            return;
        }

        if ($renew === 'expired') {
            $message = sprintf(
                /* translators: %s: plugin name */
                __('The license for %s has expired. Updates have stopped. The plugin keeps working.', 'wpbr-updater'),
                $this->pluginName()
            );
        } else {
            $message = sprintf(
                /* translators: 1: plugin name, 2: expiry date in UTC (Y-m-d) */
                __('The license for %1$s expires on %2$s. Updates stop after it expires. The plugin keeps working.', 'wpbr-updater'),
                $this->pluginName(),
                $this->expiryDate()
            );
        }

        echo '<div class="notice notice-warning"><p>';
        echo esc_html($message);
        echo ' <a href="' . esc_url($this->pageUrl()) . '">' . esc_html__('Manage license', 'wpbr-updater') . '</a>';
        echo '</p></div>';
    }

    /**
     * @param mixed $links
     * @param mixed $file
     * @return mixed
     */
    public function rowMeta($links, $file)
    {
        if (!is_array($links) || !is_string($file) || $file !== $this->basename) {
            return $links;
        }

        $label = sprintf(
            /* translators: %s: license status */
            __('License: %s', 'wpbr-updater'),
            $this->statusLabel()
        );
        if (current_user_can('manage_options')) {
            $links[] = '<a href="' . esc_url($this->pageUrl()) . '">' . esc_html($label) . '</a>';
        } else {
            $links[] = esc_html($label);
        }

        return $links;
    }

    public function render(): void
    {
        if (!current_user_can('manage_options')) {
            wp_die(esc_html__('You are not allowed to manage this license.', 'wpbr-updater'), '', ['response' => 403]);
        }

        $stored = $this->read();
        echo '<div class="wrap">';
        echo '<h1>' . esc_html(sprintf(
            /* translators: %s: plugin name */
            __('%s license', 'wpbr-updater'),
            $this->pluginName()
        )) . '</h1>';
        $this->renderFlash();

        if ($stored === null) {
            $this->activateForm();
            echo '</div>';
            return;
        }

        echo '<table class="form-table" role="presentation"><tbody>';
        echo '<tr><th scope="row">' . esc_html__('License key', 'wpbr-updater') . '</th><td><code>' . esc_html($this->mask($stored['key_hint'])) . '</code></td></tr>';
        echo '<tr><th scope="row">' . esc_html__('Status', 'wpbr-updater') . '</th><td>' . esc_html($this->statusLabel()) . '</td></tr>';
        echo '<tr><th scope="row">' . esc_html__('Expires', 'wpbr-updater') . '</th><td>' . esc_html($this->expiryText($stored['expires_at'])) . '</td></tr>';
        echo '<tr><th scope="row">' . esc_html__('Sites used', 'wpbr-updater') . '</th><td>' . esc_html($this->sitesText($stored['sites_used'], $stored['sites_limit'])) . '</td></tr>';
        echo '</tbody></table>';
        $this->actionForm('deactivate', __('Deactivate', 'wpbr-updater'));
        $this->actionForm('status', __('Check status', 'wpbr-updater'));
        echo '</div>';
    }

    private function activate(): void
    {
        $key = $this->postedKey();
        if ($key === '') {
            $this->flash('error', __('Enter a license key.', 'wpbr-updater'));
            return;
        }

        $domain = $this->domain();
        $versions = $this->versions();
        if ($domain === '' || $versions === null) {
            $this->flash('error', $this->rejected());
            return;
        }

        $result = $this->request($this->endpoint('/activate'), [
            'license_key' => $key,
            'product_slug' => $this->slug,
            'domain' => $domain,
            'version' => $versions['version'],
            'wp' => $versions['wp'],
            'php' => $versions['php'],
        ]);
        if ($result['transport_error']) {
            $this->flash('error', $this->unreachable());
            return;
        }

        $json = $result['json'];
        if ($result['status'] === 200 && is_array($json)) {
            $token = $json['instance_token'] ?? null;
            $license = $json['license'] ?? null;
            if (is_string($token) && self::validToken($token) && is_array($license)) {
                $summary = self::summary($license);
                if ($summary !== null) {
                    $this->write($token, $summary, strlen($key) > 4 ? substr($key, -4) : '');
                    $this->flash('success', __('License activated.', 'wpbr-updater'));
                    return;
                }
            }
            $this->flash('error', $this->rejected());
            return;
        }

        $this->flash('error', $this->errorMessage(is_array($json) ? $json : null));
    }

    private function deactivate(): void
    {
        $stored = $this->read();
        if ($stored === null) {
            $this->flash('error', __('This site is not activated.', 'wpbr-updater'));
            return;
        }

        $domain = $this->domain();
        if ($domain === '') {
            $this->flash('error', $this->rejected());
            return;
        }

        $result = $this->request($this->endpoint('/deactivate'), [
            'instance_token' => $stored['token'],
            'domain' => $domain,
            'product_slug' => $this->slug,
        ]);
        if ($result['transport_error']) {
            $this->flash('error', $this->unreachable());
            return;
        }

        $json = $result['json'];
        if ($result['status'] === 200 && is_array($json) && ($json['ok'] ?? null) === true) {
            delete_option($this->option);
            $this->flash('success', __('License deactivated.', 'wpbr-updater'));
            return;
        }

        $code = is_array($json) ? ($json['code'] ?? '') : '';
        if ($code === 'invalid_token' || $code === 'license_not_found') {
            delete_option($this->option);
        }
        $this->flash('error', $this->errorMessage(is_array($json) ? $json : null));
    }

    private function check(): void
    {
        $stored = $this->read();
        if ($stored === null) {
            $this->flash('error', __('This site is not activated.', 'wpbr-updater'));
            return;
        }

        $domain = $this->domain();
        if ($domain === '' || preg_match('/^[0-9A-Za-z][0-9A-Za-z._+-]{0,31}$/', $this->version) !== 1) {
            $this->flash('error', $this->rejected());
            return;
        }

        $result = $this->request($this->endpoint('/check'), [
            'instance_token' => $stored['token'],
            'domain' => $domain,
            'product_slug' => $this->slug,
            'version' => $this->version,
        ]);
        if ($result['transport_error']) {
            $this->flash('error', $this->unreachable());
            return;
        }

        $json = $result['json'];
        if ($result['status'] === 200 && is_array($json)) {
            $summary = self::summary($json);
            if ($summary !== null) {
                $this->write($stored['token'], $summary, $stored['key_hint']);
                $this->flash('success', __('License status updated.', 'wpbr-updater'));
                return;
            }
            $this->flash('error', $this->rejected());
            return;
        }

        if (is_array($json) && ($json['code'] ?? null) === 'license_expired') {
            self::markExpired($this->option);
        }
        $this->flash('error', $this->errorMessage(is_array($json) ? $json : null));
    }

    /**
     * @param array{status: string, expires_at: ?string, sites_used: int, sites_limit: int} $summary
     */
    private function write(string $token, array $summary, string $hint): void
    {
        update_option($this->option, [
            'instance_token' => $token,
            'status' => $summary['status'],
            'expires_at' => $summary['expires_at'],
            'sites_used' => $summary['sites_used'],
            'sites_limit' => $summary['sites_limit'],
            'key_hint' => $hint,
        ], false);
    }

    /**
     * @param array<string, string> $payload
     * @return array{status: int, json: array<mixed>|null, transport_error: bool}
     */
    private function request(string $url, array $payload): array
    {
        $error = ['status' => 0, 'json' => null, 'transport_error' => true];
        $body = wp_json_encode($payload);
        if (!is_string($body)) {
            return $error;
        }

        try {
            $response = wp_remote_post($url, [
                'timeout' => 5,
                'redirection' => 0,
                'headers' => [
                    'Accept' => 'application/json',
                    'Content-Type' => 'application/json',
                    'User-Agent' => 'WPBrewer-Updater/' . Updater::VERSION,
                ],
                'body' => $body,
            ]);
        } catch (Throwable $exception) {
            return $error;
        }

        if (!is_array($response)) {
            return $error;
        }

        return [
            'status' => (int) wp_remote_retrieve_response_code($response),
            'json' => $this->decode(wp_remote_retrieve_body($response)),
            'transport_error' => false,
        ];
    }

    /**
     * @return array<mixed>|null
     */
    private function decode(string $raw): ?array
    {
        try {
            $decoded = json_decode($raw, true, 32, JSON_THROW_ON_ERROR);
        } catch (Throwable $exception) {
            return null;
        }
        if (!is_array($decoded)) {
            return null;
        }

        return $decoded;
    }

    /**
     * @param array<mixed>|null $json
     */
    private function errorMessage(?array $json): string
    {
        $code = '';
        $retry = 1;
        if (is_array($json)) {
            if (isset($json['code']) && is_string($json['code'])) {
                $code = $json['code'];
            }
            if (isset($json['data']) && is_array($json['data'])) {
                $retryValue = $json['data']['retry_after'] ?? null;
                if (is_int($retryValue) && $retryValue > 0) {
                    $retry = $retryValue;
                }
            }
        }

        switch ($code) {
            case 'license_not_found':
                return __('License key not found.', 'wpbr-updater');
            case 'license_expired':
                return __('This license has expired. Updates have stopped. The plugin keeps working.', 'wpbr-updater');
            case 'license_revoked':
                return __('This license has been revoked.', 'wpbr-updater');
            case 'limit_reached':
                return __('This license has no sites left.', 'wpbr-updater');
            case 'domain_mismatch':
                return __('This activation does not match this site.', 'wpbr-updater');
            case 'invalid_token':
                return __('This activation is no longer valid.', 'wpbr-updater');
            case 'rate_limited':
                return sprintf(
                    /* translators: %d: seconds to wait */
                    __('Too many requests. Try again in %d seconds.', 'wpbr-updater'),
                    $retry
                );
            case 'invalid_request':
            case 'server_error':
            case 'unauthorized':
            case 'forbidden':
                return $this->rejected();
            default:
                return $this->unreachable();
        }
    }

    /**
     * @param array<mixed> $license
     * @return array{status: string, expires_at: ?string, sites_used: int, sites_limit: int}|null
     */
    private static function summary(array $license): ?array
    {
        $status = $license['status'] ?? null;
        if ($status !== 'active' && $status !== 'expired' && $status !== 'revoked') {
            return null;
        }
        if (!array_key_exists('expires_at', $license)) {
            return null;
        }

        $expires = $license['expires_at'];
        if ($expires !== null && (!is_string($expires) || preg_match('/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}Z$/', $expires) !== 1)) {
            return null;
        }
        if (!isset($license['sites_used'], $license['sites_limit'])) {
            return null;
        }
        $used = $license['sites_used'];
        $limit = $license['sites_limit'];
        if (!is_int($used) || !is_int($limit) || $used < 0 || $limit < 0) {
            return null;
        }

        return [
            'status' => $status,
            'expires_at' => $expires,
            'sites_used' => $used,
            'sites_limit' => $limit,
        ];
    }

    /**
     * @return array{token: string, status: string, expires_at: ?string, sites_used: int, sites_limit: int, key_hint: string}|null
     */
    private function read(): ?array
    {
        $stored = get_option($this->option, null);
        if (!is_array($stored)) {
            return null;
        }

        $token = self::tokenFrom($stored);
        if ($token === '') {
            return null;
        }

        $status = '';
        if (isset($stored['status']) && is_string($stored['status'])) {
            if ($stored['status'] === 'active' || $stored['status'] === 'expired' || $stored['status'] === 'revoked') {
                $status = $stored['status'];
            }
        }

        $expires = null;
        if (array_key_exists('expires_at', $stored) && is_string($stored['expires_at'])) {
            if (preg_match('/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}Z$/', $stored['expires_at']) === 1) {
                $expires = $stored['expires_at'];
            }
        }

        $used = 0;
        $limit = 0;
        if (isset($stored['sites_used']) && is_int($stored['sites_used']) && $stored['sites_used'] >= 0) {
            $used = $stored['sites_used'];
        }
        if (isset($stored['sites_limit']) && is_int($stored['sites_limit']) && $stored['sites_limit'] >= 0) {
            $limit = $stored['sites_limit'];
        }

        return [
            'token' => $token,
            'status' => $status,
            'expires_at' => $expires,
            'sites_used' => $used,
            'sites_limit' => $limit,
            'key_hint' => self::hintFrom($stored),
        ];
    }

    /**
     * @param array<mixed> $stored
     */
    private static function tokenFrom(array $stored): string
    {
        $token = $stored['instance_token'] ?? null;
        if (!is_string($token) || !self::validToken($token)) {
            return '';
        }

        return $token;
    }

    /**
     * @param array<mixed> $stored
     */
    private static function hintFrom(array $stored): string
    {
        $hint = $stored['key_hint'] ?? null;
        if (!is_string($hint) || preg_match('/^\S{1,4}$/', $hint) !== 1) {
            return '';
        }

        return $hint;
    }

    private static function validToken(string $token): bool
    {
        return preg_match('/^[A-Za-z0-9_-]{32,255}$/', $token) === 1;
    }

    private static function validOption(string $option): bool
    {
        return strlen($option) <= 191 && preg_match('/^[A-Za-z0-9_]+$/', $option) === 1;
    }

    private function postedKey(): string
    {
        if (!isset($_POST['license_key']) || !is_string($_POST['license_key'])) {
            return '';
        }

        $key = trim(wp_unslash($_POST['license_key']));
        if (preg_match('/^\S{1,255}$/', $key) !== 1) {
            return '';
        }

        return $key;
    }

    private function domain(): string
    {
        if (!function_exists('home_url')) {
            return '';
        }

        return Domain::normalize((string) home_url());
    }

    /**
     * @return array{version: string, wp: string, php: string}|null
     */
    private function versions(): ?array
    {
        $wp = function_exists('get_bloginfo') ? (string) get_bloginfo('version') : '';
        $php = PHP_VERSION;
        if (preg_match('/^[0-9A-Za-z][0-9A-Za-z._+-]{0,31}$/', $this->version) !== 1) {
            return null;
        }
        if (preg_match('/^[0-9A-Za-z][0-9A-Za-z._+-]{0,31}$/', $wp) !== 1) {
            return null;
        }
        if (preg_match('/^[0-9A-Za-z][0-9A-Za-z._+-]{0,31}$/', $php) !== 1) {
            return null;
        }

        return [
            'version' => $this->version,
            'wp' => $wp,
            'php' => $php,
        ];
    }

    private function renewState(): string
    {
        $stored = $this->read();
        if ($stored === null || $stored['status'] === 'revoked') {
            return '';
        }
        if ($stored['status'] === 'expired') {
            return 'expired';
        }

        $expires = $stored['expires_at'];
        if ($expires === null) {
            return '';
        }

        $timestamp = strtotime($expires);
        if (!is_int($timestamp)) {
            return '';
        }
        if ($timestamp <= time()) {
            return 'expired';
        }
        if ($timestamp <= time() + self::RENEW_WITHIN) {
            return 'soon';
        }

        return '';
    }

    private function statusLabel(): string
    {
        $stored = $this->read();
        if ($stored === null) {
            return __('Not activated', 'wpbr-updater');
        }
        if ($stored['status'] === 'revoked') {
            return __('Revoked', 'wpbr-updater');
        }

        $renew = $this->renewState();
        if ($renew === 'expired') {
            return __('Expired', 'wpbr-updater');
        }
        if ($renew === 'soon') {
            return __('Expires soon', 'wpbr-updater');
        }

        return __('Active', 'wpbr-updater');
    }

    private function mask(string $hint): string
    {
        if ($hint === '') {
            return __('Activated on this site', 'wpbr-updater');
        }

        return '********' . $hint;
    }

    private function expiryText(?string $expires): string
    {
        if ($expires === null) {
            return __('Does not expire', 'wpbr-updater');
        }

        return substr($expires, 0, 10);
    }

    private function expiryDate(): string
    {
        $stored = $this->read();
        if ($stored === null) {
            return '';
        }

        return $this->expiryText($stored['expires_at']);
    }

    private function sitesText(int $used, int $limit): string
    {
        if ($limit === 0) {
            return sprintf(
                /* translators: %d: number of sites using this license */
                __('%d / unlimited', 'wpbr-updater'),
                $used
            );
        }

        return sprintf(
            /* translators: 1: sites in use, 2: site limit */
            __('%1$d / %2$d', 'wpbr-updater'),
            $used,
            $limit
        );
    }

    private function pluginName(): string
    {
        if ($this->name !== '') {
            return $this->name;
        }

        $name = $this->slug;
        if (is_readable($this->file) && defined('ABSPATH')) {
            if (!function_exists('get_plugin_data')) {
                require_once ABSPATH . 'wp-admin/includes/plugin.php';
            }
            if (function_exists('get_plugin_data')) {
                $data = get_plugin_data($this->file, false, false);
                if ($data['Name'] !== '') {
                    $name = $data['Name'];
                }
            }
        }

        $this->name = $name;
        return $name;
    }

    private function pageSlug(): string
    {
        return 'wpbr-license-' . $this->slug;
    }

    private function pageUrl(): string
    {
        return admin_url('admin.php?page=' . rawurlencode($this->pageSlug()));
    }

    private function endpoint(string $path): string
    {
        return $this->server . '/wp-json/wpbr-license/v1' . $path;
    }

    private function activateForm(): void
    {
        $id = 'wpbr-license-key-' . $this->slug;
        echo '<form method="post" action="' . esc_url(admin_url('admin-post.php')) . '">';
        echo '<input type="hidden" name="action" value="' . esc_attr('wpbr_license_' . $this->slug) . '" />';
        echo '<input type="hidden" name="wpbr_license_do" value="activate" />';
        wp_nonce_field('wpbr_license_' . $this->slug, 'wpbr_license_nonce');
        echo '<table class="form-table" role="presentation"><tbody><tr>';
        echo '<th scope="row"><label for="' . esc_attr($id) . '">' . esc_html__('License key', 'wpbr-updater') . '</label></th>';
        echo '<td><input name="license_key" id="' . esc_attr($id) . '" type="text" class="regular-text" value="" autocomplete="off" /></td>';
        echo '</tr></tbody></table>';
        echo '<p><button type="submit" class="button button-primary">' . esc_html__('Activate', 'wpbr-updater') . '</button></p>';
        echo '</form>';
    }

    private function actionForm(string $action, string $label): void
    {
        echo '<form method="post" action="' . esc_url(admin_url('admin-post.php')) . '" style="display:inline-block;margin-right:8px">';
        echo '<input type="hidden" name="action" value="' . esc_attr('wpbr_license_' . $this->slug) . '" />';
        echo '<input type="hidden" name="wpbr_license_do" value="' . esc_attr($action) . '" />';
        wp_nonce_field('wpbr_license_' . $this->slug, 'wpbr_license_nonce');
        echo '<p><button type="submit" class="button">' . esc_html($label) . '</button></p>';
        echo '</form>';
    }

    private function flash(string $type, string $message): void
    {
        if ($type !== 'success' && $type !== 'error') {
            $type = 'error';
        }
        set_transient($this->noticeKey(), [
            'type' => $type,
            'message' => $message,
        ], 60);
    }

    private function renderFlash(): void
    {
        $notice = get_transient($this->noticeKey());
        delete_transient($this->noticeKey());
        if (!is_array($notice)) {
            return;
        }

        $type = $notice['type'] ?? '';
        $message = $notice['message'] ?? '';
        if (($type !== 'success' && $type !== 'error') || !is_string($message) || $message === '') {
            return;
        }

        $class = $type === 'success' ? 'notice-success' : 'notice-error';
        echo '<div class="notice ' . esc_attr($class) . '"><p>' . esc_html($message) . '</p></div>';
    }

    private function noticeKey(): string
    {
        return 'wpbr_ln_' . substr(hash('sha256', $this->slug), 0, 32);
    }

    private function redirect(): void
    {
        wp_safe_redirect($this->pageUrl());
        exit;
    }

    private function unreachable(): string
    {
        return __('The license server could not be reached. The plugin keeps working.', 'wpbr-updater');
    }

    private function rejected(): string
    {
        return __('The license server rejected the request. The plugin keeps working.', 'wpbr-updater');
    }
}
