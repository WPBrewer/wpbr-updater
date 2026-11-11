<?php

declare(strict_types=1);

namespace WPBrewer\Updater;

/**
 * Host normalization for wpbr-license/v1.
 *
 * The license server applies the same steps before it compares a domain or
 * signs a download URL. Sending the normalized host keeps the activation and
 * the update check on the same string.
 *
 * @license GPL-3.0-or-later
 */
final class Domain
{
    public static function normalize(string $value): string
    {
        $value = trim($value);
        if ($value === '') {
            return '';
        }

        if (preg_match('#^[a-z][a-z0-9+.-]*://#i', $value) !== 1) {
            $value = 'http://' . $value;
        }

        $host = parse_url($value, PHP_URL_HOST);
        if (!is_string($host) || $host === '') {
            return '';
        }

        $host = strtolower($host);
        if (str_starts_with($host, '[') && str_ends_with($host, ']')) {
            $host = substr($host, 1, -1);
        }
        if (str_starts_with($host, 'www.')) {
            $host = substr($host, 4);
        }
        $host = rtrim($host, '.');

        if ($host !== '' && preg_match('/^[\x00-\x7F]+$/', $host) !== 1) {
            if (!function_exists('idn_to_ascii')) {
                return '';
            }
            $ascii = idn_to_ascii($host, IDNA_NONTRANSITIONAL_TO_ASCII);
            if (!is_string($ascii) || $ascii === '') {
                return '';
            }
            $host = strtolower($ascii);
        }

        if ($host === '' || strlen($host) > 253) {
            return '';
        }

        return $host;
    }
}
