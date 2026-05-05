<?php

// SPDX-License-Identifier: Apache-2.0

declare(strict_types=1);

namespace OpenSalesTax\WooCommerce;

defined('ABSPATH') || exit;

/**
 * WP transient-based cache for tax-calculation lookups.
 *
 * Key format: `ostax_<sha1(payload-key)>` (transients have a 172-char name limit
 * and we prefer not to leak ZIP/category/amount in the key). The original
 * payload-key is a stable concatenation: `<zip5>|<category>|<cents>`.
 *
 * Transients are stored in `wp_options` (or an object cache drop-in if active).
 * Bulk-flush by deleting all `_transient_ostax_*` rows; that's what the
 * settings page does on save.
 */
final class Cache
{
    private const KEY_PREFIX = 'ostax_';

    /**
     * @return array<string, float>|null Cached tax array, or null on miss.
     */
    public function get(string $payloadKey): ?array
    {
        $stored = get_transient($this->makeKey($payloadKey));
        if (!is_array($stored)) {
            return null;
        }
        // Sanity: ensure all values are scalar floats.
        $out = [];
        foreach ($stored as $k => $v) {
            if (!is_string($k)) {
                return null;
            }
            $out[$k] = (float) $v;
        }
        return $out;
    }

    /**
     * @param array<string, float> $taxArray
     */
    public function set(string $payloadKey, array $taxArray, ?int $ttlSeconds = null): void
    {
        $ttl = $ttlSeconds ?? self::resolveTtlFromOptions();
        if ($ttl <= 0) {
            return; // caching disabled
        }
        set_transient($this->makeKey($payloadKey), $taxArray, $ttl);
    }

    /**
     * Bulk-flush all our cached entries. Called on settings save + plugin
     * deactivation.
     */
    public static function flushAll(): void
    {
        global $wpdb;
        if (!isset($wpdb) || !is_object($wpdb)) {
            return;
        }
        $like = '_transient_' . self::KEY_PREFIX . '%';
        $timeout = '_transient_timeout_' . self::KEY_PREFIX . '%';
        // @phpstan-ignore-next-line — $wpdb is dynamic via WP, safe via prepare()
        $wpdb->query($wpdb->prepare("DELETE FROM {$wpdb->options} WHERE option_name LIKE %s OR option_name LIKE %s", $like, $timeout));
    }

    private function makeKey(string $payloadKey): string
    {
        // sha1 keeps the key compact + opaque; we don't need reversibility.
        return self::KEY_PREFIX . sha1($payloadKey);
    }

    private static function resolveTtlFromOptions(): int
    {
        $raw = get_option('opensalestax_cache_ttl_minutes', 60);
        $minutes = is_numeric($raw) ? (int) $raw : 60;
        return $minutes * 60;
    }
}
