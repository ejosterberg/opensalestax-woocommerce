<?php

// SPDX-License-Identifier: Apache-2.0

declare(strict_types=1);

namespace OpenSalesTax\WooCommerce;

defined('ABSPATH') || exit;

/**
 * Ring-buffer log of recent tax calculations. Records who asked, what for,
 * and what the engine (or cache) returned — so a merchant troubleshooting
 * "why is this rate wrong?" can answer the question without grepping the
 * server's PHP error log.
 *
 * Default: OFF. Each `record()` call writes to a WP option, so leaving it
 * on under heavy traffic adds one option-write per calculation. Turn it on
 * via the settings page or the `opensalestax_calc_log_enabled` option
 * (`'1'` = enabled). Logged entries are trimmed to `MAX_ENTRIES` items.
 *
 * Storage: `wp_options['opensalestax_recent_calcs']`, JSON-encoded.
 * Each entry is a self-contained associative array — see `record()` for
 * the shape — so the consumer side never needs to know the producer's
 * internal types.
 */
final class CalculationLog
{
    public const ENABLED_OPTION = 'opensalestax_calc_log_enabled';
    public const STORAGE_OPTION = 'opensalestax_recent_calcs';
    public const MAX_ENTRIES = 50;

    public const SOURCE_CACHE_HIT = 'cache-hit';
    public const SOURCE_ENGINE_CALL = 'engine-call';
    public const SOURCE_ERROR = 'error';

    /** @return bool True if logging is currently enabled. */
    public static function isEnabled(): bool
    {
        $val = get_option(self::ENABLED_OPTION, '0');
        return is_string($val) && $val === '1';
    }

    /**
     * Record one calculation. No-op if logging is disabled — callers can
     * unconditionally invoke this from hot paths without checking first.
     *
     * @param string                $source     One of the SOURCE_* constants
     * @param string                $zip5       Resolved destination ZIP
     * @param string                $category   OST category (general, clothing, …)
     * @param float                 $amount     Pre-tax amount in shop currency
     * @param float|null            $taxTotal   Computed tax (null when source = error)
     * @param int|null              $durationMs Round-trip ms for engine calls
     * @param string|null           $error      Error message when source = error
     * @param int|string|null       $orderId    Order ID if known
     */
    public static function record(
        string $source,
        string $zip5,
        string $category,
        float $amount,
        ?float $taxTotal,
        ?int $durationMs = null,
        ?string $error = null,
        int|string|null $orderId = null,
    ): void {
        if (!self::isEnabled()) {
            return;
        }

        $entry = [
            'ts' => gmdate('c'),
            'source' => $source,
            'zip5' => $zip5,
            'category' => $category,
            'amount' => round($amount, 4),
            'tax_total' => $taxTotal !== null ? round($taxTotal, 4) : null,
            'duration_ms' => $durationMs,
            'error' => $error,
            'order_id' => $orderId === null ? null : (string) $orderId,
        ];

        $existing = self::loadRaw();
        array_unshift($existing, $entry);
        $existing = array_slice($existing, 0, self::MAX_ENTRIES);

        $json = wp_json_encode($existing);
        if (is_string($json)) {
            update_option(self::STORAGE_OPTION, $json);
        }
    }

    /**
     * Load recent entries (newest first), bounded by $limit.
     *
     * @param int $limit
     * @return list<array<string, mixed>>
     */
    public static function getRecent(int $limit = self::MAX_ENTRIES): array
    {
        $entries = self::loadRaw();
        return array_slice($entries, 0, max(0, $limit));
    }

    /** Clear all recorded entries. */
    public static function clear(): void
    {
        delete_option(self::STORAGE_OPTION);
    }

    /**
     * Read + decode the stored entries. Returns [] when missing/malformed
     * so callers can iterate unconditionally.
     *
     * @return list<array<string, mixed>>
     */
    private static function loadRaw(): array
    {
        $raw = get_option(self::STORAGE_OPTION, '');
        if (!is_string($raw) || $raw === '') {
            return [];
        }
        $decoded = json_decode($raw, true);
        if (!is_array($decoded)) {
            return [];
        }
        // Coerce to list shape and reject non-array elements.
        $out = [];
        foreach ($decoded as $entry) {
            if (is_array($entry)) {
                $out[] = $entry;
            }
        }
        return $out;
    }
}
