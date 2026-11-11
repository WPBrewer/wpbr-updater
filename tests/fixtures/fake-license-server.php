<?php

declare(strict_types=1);

/**
 * Fake wpbr-license/v1 server for tests.
 *
 * Speaks the routes the updater calls: POST /update-info, GET /info/{slug},
 * and GET /wpbr-download/{id}. The scenario comes from the JSON file in
 * WPBR_FAKE_STATE.
 *
 * @license GPL-3.0-or-later
 */

if (!class_exists('WpbrFakeLicenseServer', false)) {
    final class WpbrFakeLicenseServer
    {
        public static function handle(): void
        {
            $stateFile = getenv('WPBR_FAKE_STATE');
            if (!is_string($stateFile) || $stateFile === '' || !is_file($stateFile)) {
                self::text(500, 'fake server state missing');
                return;
            }

            $state = json_decode((string) file_get_contents($stateFile), true);
            if (!is_array($state)) {
                self::text(500, 'fake server state is invalid');
                return;
            }

            $path = parse_url((string) ($_SERVER['REQUEST_URI'] ?? '/'), PHP_URL_PATH);
            $path = is_string($path) ? $path : '/';
            $method = (string) ($_SERVER['REQUEST_METHOD'] ?? 'GET');
            $bucket = self::bucket($method, $path);

            if ($bucket === 'health') {
                self::text(200, 'ok');
                return;
            }

            $rawBody = $method === 'POST' ? (string) file_get_contents('php://input') : '';
            $hits = is_array($state['hits'] ?? null) ? $state['hits'] : [];
            $hits[$bucket] = (int) ($hits[$bucket] ?? 0) + 1;
            $state['hits'] = $hits;
            if ($bucket === 'update-info') {
                $state['last_update_body'] = $rawBody;
            }
            file_put_contents($stateFile, (string) json_encode($state, JSON_UNESCAPED_SLASHES));

            $mode = (string) ($state['mode'] ?? 'update');
            if ($mode === 'timeout' && ($bucket === 'update-info' || $bucket === 'info')) {
                sleep(max(1, (int) ($state['sleep'] ?? 30)));
            }

            if ($bucket === 'download') {
                self::download($state);
                return;
            }

            if ($bucket === 'info') {
                self::info($state, $mode);
                return;
            }

            if ($bucket === 'update-info') {
                self::updateInfo($state, $mode);
                return;
            }

            self::error(404, 'release_not_found', 'Not found.');
        }

        private static function bucket(string $method, string $path): string
        {
            if ($path === '/health') {
                return 'health';
            }
            if ($method === 'POST' && str_ends_with($path, '/update-info')) {
                return 'update-info';
            }
            if ($method === 'GET' && str_contains($path, '/wp-json/wpbr-license/v1/info/')) {
                return 'info';
            }
            if ($method === 'GET' && str_contains($path, '/wpbr-download/')) {
                return 'download';
            }
            return 'other';
        }

        /**
         * @param array<string, mixed> $state
         */
        private static function updateInfo(array $state, string $mode): void
        {
            if ($mode === 'http_500') {
                self::error(500, 'server_error', 'Unexpected failure.');
                return;
            }
            if ($mode === 'html' || $mode === 'timeout') {
                self::html();
                return;
            }
            if ($mode === 'expired') {
                self::error(403, 'license_expired', 'This license has expired.');
                return;
            }
            if ($mode === 'no_update') {
                self::json(200, [
                    'update_available' => false,
                    'license' => self::license(),
                ]);
                return;
            }

            $downgrade = $mode === 'downgrade';
            $sha = (string) ($state['sha256'] ?? '');
            if ($mode === 'bad_sha256') {
                $sha = str_repeat('ab', 32);
            }

            $package = self::packageUrl($state);
            if ($mode === 'foreign') {
                $package = 'https://evil.example/wpbr-download/42?exp=1790000000&dom=127.0.0.1&sig=' . str_repeat('a', 64);
            }

            self::json(200, [
                'update_available' => true,
                'version' => $downgrade ? (string) ($state['downgrade_version'] ?? '0.9.0') : (string) ($state['version'] ?? '1.2.0'),
                'package' => $package,
                'sha256' => $sha,
                'requires_wp' => (string) ($state['requires_wp'] ?? '7.0'),
                'requires_php' => (string) ($state['requires_php'] ?? '8.1'),
                'tested' => (string) ($state['tested'] ?? '7.1.4'),
                'changelog' => (string) ($state['changelog'] ?? ''),
                'allow_downgrade' => $downgrade,
                'license' => self::license(),
            ]);
        }

        /**
         * @param array<string, mixed> $state
         */
        private static function info(array $state, string $mode): void
        {
            if ($mode === 'http_500') {
                self::error(500, 'server_error', 'Unexpected failure.');
                return;
            }
            if ($mode === 'html' || $mode === 'timeout') {
                self::html();
                return;
            }

            $slug = (string) ($state['slug'] ?? 'wpbr-dummy');
            self::json(200, [
                'slug' => $slug,
                'name' => (string) ($state['name'] ?? 'WPBR Dummy'),
                'version' => (string) ($state['version'] ?? '1.2.0'),
                'changelog' => (string) ($state['changelog'] ?? ''),
                'requires_wp' => (string) ($state['requires_wp'] ?? '7.0'),
                'requires_php' => (string) ($state['requires_php'] ?? '8.1'),
                'tested' => (string) ($state['tested'] ?? '7.1.4'),
            ]);
        }

        /**
         * @param array<string, mixed> $state
         */
        private static function download(array $state): void
        {
            $zip = (string) ($state['zip'] ?? '');
            if ($zip === '' || !is_file($zip)) {
                self::error(404, 'release_not_found', 'Release not found.');
                return;
            }
            header('Content-Type: application/zip');
            header('Content-Disposition: attachment; filename="wpbr-dummy-1.2.0.zip"');
            header('Cache-Control: private, no-store');
            header('Content-Length: ' . (string) filesize($zip));
            readfile($zip);
        }

        /**
         * @param array<string, mixed> $state
         */
        private static function packageUrl(array $state): string
        {
            $id = (int) ($state['release_id'] ?? 42);
            $dom = (string) ($state['domain'] ?? '127.0.0.1');
            $exp = time() + 900;
            $sig = hash_hmac('sha256', $id . '|' . $dom . '|' . $exp, 'wpbr-test-secret');
            $server = rtrim((string) ($state['server'] ?? 'http://127.0.0.1:8080'), '/');
            return $server . '/wpbr-download/' . $id . '?exp=' . $exp . '&dom=' . $dom . '&sig=' . $sig;
        }

        /**
         * @return array{status: string, expires_at: string, sites_used: int, sites_limit: int}
         */
        private static function license(): array
        {
            return [
                'status' => 'active',
                'expires_at' => '2027-11-15T00:00:00Z',
                'sites_used' => 1,
                'sites_limit' => 1,
            ];
        }

        /**
         * @param array<string, mixed> $body
         */
        private static function json(int $status, array $body): void
        {
            http_response_code($status);
            header('Content-Type: application/json');
            echo (string) json_encode($body, JSON_UNESCAPED_SLASHES);
        }

        private static function error(int $status, string $code, string $message): void
        {
            self::json($status, [
                'code' => $code,
                'message' => $message,
                'data' => ['status' => $status],
            ]);
        }

        private static function html(): void
        {
            http_response_code(200);
            header('Content-Type: text/html; charset=UTF-8');
            echo '<!DOCTYPE html><html><body><h1>Bad gateway</h1></body></html>';
        }

        private static function text(int $status, string $body): void
        {
            http_response_code($status);
            header('Content-Type: text/plain; charset=UTF-8');
            echo $body;
        }
    }
}

WpbrFakeLicenseServer::handle();
