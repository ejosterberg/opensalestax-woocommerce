<?php

// SPDX-License-Identifier: Apache-2.0

declare(strict_types=1);

namespace OpenSalesTax\WooCommerce;

use OpenSalesTax\Client as OpenSalesTaxClient;

defined('ABSPATH') || exit;

/**
 * Builds an `OpenSalesTax\Client` from saved plugin settings.
 *
 * Returns `null` if the plugin isn't configured (no base URL set yet) so
 * callers can short-circuit without needing to handle a fake-client edge case.
 *
 * Not `final` — tests extend this class with anonymous overrides to inject
 * fake Clients. Production code should not subclass.
 */
class ClientFactory
{
    public function build(): ?OpenSalesTaxClient
    {
        $baseUrl = $this->normalizeBaseUrl(self::stringOption('opensalestax_base_url', ''));
        if ($baseUrl === '') {
            return null;
        }
        $apiKey = self::stringOption('opensalestax_api_key', '');
        $rawTimeout = get_option('opensalestax_timeout_seconds', 5.0);
        $timeoutSeconds = is_numeric($rawTimeout) ? (float) $rawTimeout : 5.0;

        return new OpenSalesTaxClient(
            baseUrl: $baseUrl,
            apiKey: $apiKey === '' ? null : $apiKey,
            timeoutSeconds: $timeoutSeconds > 0 ? $timeoutSeconds : 5.0,
        );
    }

    private function normalizeBaseUrl(string $raw): string
    {
        $url = trim($raw);
        if ($url === '') {
            return '';
        }
        return rtrim($url, '/');
    }

    private static function stringOption(string $name, string $default): string
    {
        $v = get_option($name, $default);
        return is_string($v) ? $v : $default;
    }
}
