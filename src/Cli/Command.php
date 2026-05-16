<?php

// SPDX-License-Identifier: Apache-2.0 OR GPL-2.0-or-later

declare(strict_types=1);

namespace OpenSalesTax\WooCommerce\Cli;

use OpenSalesTax\Address;
use OpenSalesTax\Exceptions\OpenSalesTaxException;
use OpenSalesTax\LineItem;
use OpenSalesTax\WooCommerce\Cache;
use OpenSalesTax\WooCommerce\CalculationLog;
use OpenSalesTax\WooCommerce\ClientFactory;
use OpenSalesTax\WooCommerce\PlaceholderRate;
use OpenSalesTax\WooCommerce\TaxClassMap;

defined('ABSPATH') || exit;

/**
 * WP-CLI commands for the OpenSalesTax for WooCommerce plugin.
 *
 * Registered as `wp opensalestax <subcommand>` from Plugin::wireUp() when
 * WP-CLI is loaded. Provides operational shortcuts:
 *
 *   wp opensalestax test-connection      # hits /v1/health
 *   wp opensalestax cache-flush          # bulk-flushes the OST transient cache
 *   wp opensalestax calc <zip5> <amount> [--category=general]
 */
final class Command
{
    public function __construct(
        private readonly ClientFactory $clientFactory,
    ) {
    }

    /**
     * Test the connection to the configured OpenSalesTax engine.
     *
     * Calls `/v1/health` and prints the response. Exits non-zero on failure
     * so this can be used in CI / monitoring scripts.
     *
     * ## EXAMPLES
     *
     *     wp opensalestax test-connection
     */
    public function test_connection(): void
    {
        $client = $this->clientFactory->build();
        if ($client === null) {
            self::error('Engine base URL is not set. Configure under WC > Settings > Tax > OpenSalesTax.');
        }

        try {
            $health = $client->health();
        } catch (OpenSalesTaxException $e) {
            self::error('Connection failed: ' . $e->getMessage());
        } catch (\Throwable $e) {
            self::error('Unexpected error: ' . get_class($e) . ': ' . $e->getMessage());
        }

        self::success(sprintf(
            'Engine v%s is %s — DB %s',
            $health->version,
            $health->status,
            $health->databaseConnected ? 'up' : 'down',
        ));
    }

    /**
     * Bulk-flush the OpenSalesTax transient cache.
     *
     * Useful after the engine ships a rate update, or to verify a fresh
     * calculation when troubleshooting an unexpected tax amount.
     *
     * ## EXAMPLES
     *
     *     wp opensalestax cache-flush
     */
    public function cache_flush(): void
    {
        Cache::flushAll();
        self::success('OpenSalesTax cache flushed.');
    }

    /**
     * Compute tax for a single hypothetical line item.
     *
     * Doesn't touch the WC cart — purely a one-shot engine query useful
     * for sanity-checking a rate without going through the checkout flow.
     *
     * ## OPTIONS
     *
     * <zip5>
     * : 5-digit US ZIP, e.g. 55401
     *
     * <amount>
     * : Pre-tax line amount as a decimal string, e.g. 100.00
     *
     * [--category=<category>]
     * : Tax category. One of: general, clothing, groceries,
     *   prescription_drugs, prepared_food, digital_goods.
     * ---
     * default: general
     * ---
     *
     * ## EXAMPLES
     *
     *     wp opensalestax calc 55401 100.00
     *     wp opensalestax calc 55401 50.00 --category=clothing
     *
     * @param array<int, string>           $args
     * @param array<string, string>        $assoc_args
     */
    public function calc(array $args, array $assoc_args): void
    {
        $zip5 = $args[0] ?? '';
        $amount = $args[1] ?? '';
        $category = $assoc_args['category'] ?? 'general';

        if (preg_match('/^\d{5}$/', $zip5) !== 1) {
            self::error("Invalid ZIP: '{$zip5}'. Must be exactly 5 digits.");
        }

        $client = $this->clientFactory->build();
        if ($client === null) {
            self::error('Engine base URL is not set.');
        }

        try {
            $result = $client->calculate(
                address: new Address(zip5: $zip5),
                lineItems: [new LineItem(amount: $amount, category: $category)],
            );
        } catch (OpenSalesTaxException $e) {
            self::error('Calculation failed: ' . $e->getMessage());
        }

        self::log("Subtotal: \${$result->subtotal}");
        self::log("Tax:      \${$result->taxTotal}");
        self::log('Lines:');
        foreach ($result->lines as $line) {
            $note = $line->note !== null ? "  [note: {$line->note}]" : '';
            self::log("  - {$line->category}: amount=\${$line->amount}, tax=\${$line->tax}{$note}");
            foreach ($line->jurisdictions as $j) {
                $taxStr = $j->tax !== null ? " \${$j->tax}" : '';
                self::log(sprintf('      %-9s %-50s %s%%%s', $j->type, $j->name, $j->ratePct, $taxStr));
            }
        }
        self::success('Calculation complete.');
    }

    /**
     * Inspect the current placeholder tax-rate row that makes WC fire our
     * filter. Useful when debugging "tax line not appearing" issues.
     *
     * ## EXAMPLES
     *
     *     wp opensalestax placeholder-rate
     */
    public function placeholder_rate(): void
    {
        $rateId = PlaceholderRate::getRateId();
        if ($rateId === null) {
            self::warning('No placeholder rate row exists in wp_woocommerce_tax_rates.');
            self::log('  Run `wp plugin deactivate opensalestax-for-woocommerce && wp plugin activate opensalestax-for-woocommerce` to create it.');
            return;
        }
        self::success("Placeholder rate row: tax_rate_id={$rateId}, tax_rate_name='" . PlaceholderRate::RATE_NAME . "'");
    }

    /**
     * List the effective WC tax-class → OST category mapping.
     *
     * Built-in defaults plus any merchant overrides. Empty-string mapping
     * means "skip this class — explicitly non-taxable".
     *
     * ## EXAMPLES
     *
     *     wp opensalestax tax-class-list
     */
    public function tax_class_list(): void
    {
        $effective = TaxClassMap::loadEffectiveMap();
        $custom = TaxClassMap::loadCustomMap();

        self::log('Effective WC tax-class → OST category mapping:');
        foreach ($effective as $wcClass => $ostCategory) {
            $label = $wcClass === '' ? '(standard / empty slug)' : $wcClass;
            $cat = $ostCategory === '' ? '(skip — non-taxable)' : $ostCategory;
            $marker = isset($custom[$wcClass]) ? ' [custom override]' : '';
            self::log(sprintf('  %-30s → %s%s', $label, $cat, $marker));
        }
        self::log('');
        self::log('Valid OST categories: ' . implode(', ', TaxClassMap::VALID_CATEGORIES));
        self::log("Use '' (empty string) to mark a class as non-taxable.");
        self::log('');
        self::log('Set with:   wp opensalestax tax-class-set <wc-class-slug> <ost-category>');
        self::log('Reset with: wp opensalestax tax-class-reset');
    }

    /**
     * Set a custom WC tax-class → OST category mapping.
     *
     * ## OPTIONS
     *
     * <wc-class>
     * : The WC tax-class slug, e.g. `clothing`, `reduced-rate`, or '' for Standard.
     *
     * <ost-category>
     * : One of: general, clothing, groceries, prescription_drugs, prepared_food, digital_goods.
     *   Use '' (empty string) to mark the class as non-taxable.
     *
     * ## EXAMPLES
     *
     *     # Map a custom "Clothing" tax class to OST's clothing category
     *     wp opensalestax tax-class-set clothing clothing
     *
     *     # Map a custom "Software" class to digital_goods
     *     wp opensalestax tax-class-set software digital_goods
     *
     *     # Mark a custom "Gift cards" class as non-taxable
     *     wp opensalestax tax-class-set gift-cards ''
     *
     * @param array<int, string> $args
     */
    public function tax_class_set(array $args): void
    {
        if (count($args) < 2) {
            self::error('Usage: wp opensalestax tax-class-set <wc-class> <ost-category>');
        }
        $wcClass = $args[0];
        $ostCategory = $args[1];

        try {
            TaxClassMap::set($wcClass, $ostCategory);
        } catch (\InvalidArgumentException $e) {
            self::error($e->getMessage());
        }
        $label = $wcClass === '' ? '(standard / empty slug)' : $wcClass;
        $catLabel = $ostCategory === '' ? '(skip — non-taxable)' : $ostCategory;
        self::success("Mapped WC class '{$label}' → OST category '{$catLabel}'.");
    }

    /**
     * Reset all custom WC tax-class mappings; revert to built-in defaults.
     *
     * ## EXAMPLES
     *
     *     wp opensalestax tax-class-reset
     */
    public function tax_class_reset(): void
    {
        TaxClassMap::reset();
        self::success('Custom WC tax-class mappings cleared. Now using built-in defaults.');
    }

    /**
     * Show recent tax calculations from the in-plugin debug log.
     *
     * Disabled by default — turn it on under WC > Settings > Tax > OpenSalesTax,
     * or `wp option update opensalestax_calc_log_enabled 1`. Captures the last
     * 50 calculations including cache hits, engine calls, and errors.
     *
     * ## OPTIONS
     *
     * [--limit=<limit>]
     * : Number of entries to show (newest first).
     * ---
     * default: 20
     * ---
     *
     * ## EXAMPLES
     *
     *     wp opensalestax recent-calcs
     *     wp opensalestax recent-calcs --limit=50
     *
     * @param array<int, string>     $args
     * @param array<string, string>  $assoc_args
     */
    public function recent_calcs(array $args, array $assoc_args): void
    {
        $limit = isset($assoc_args['limit']) ? (int) $assoc_args['limit'] : 20;
        if (!CalculationLog::isEnabled()) {
            self::warning('Calculation log is currently DISABLED. Showing whatever was captured before it was turned off.');
            self::log("Enable with:  wp option update '" . CalculationLog::ENABLED_OPTION . "' 1");
            self::log('');
        }
        $entries = CalculationLog::getRecent($limit);
        if (count($entries) === 0) {
            self::log('No recent calculations recorded.');
            return;
        }
        self::log(sprintf('%-25s %-12s %-7s %-12s %-10s %-10s %-6s %s', 'Timestamp', 'Source', 'ZIP', 'Category', 'Amount', 'Tax', 'Dur', 'Notes'));
        foreach ($entries as $e) {
            $tax = $e['tax_total'] ?? null;
            $dur = $e['duration_ms'] ?? null;
            $notes = '';
            if (isset($e['error']) && is_string($e['error']) && $e['error'] !== '') {
                $notes = $e['error'];
            } elseif (isset($e['order_id']) && $e['order_id'] !== null) {
                $notes = 'order=' . $e['order_id'];
            }
            self::log(sprintf(
                '%-25s %-12s %-7s %-12s %-10s %-10s %-6s %s',
                self::stringify($e['ts'] ?? ''),
                self::stringify($e['source'] ?? ''),
                self::stringify($e['zip5'] ?? ''),
                self::stringify($e['category'] ?? ''),
                '$' . self::stringify($e['amount'] ?? '0'),
                $tax === null ? '—' : '$' . self::stringify($tax),
                $dur === null ? '—' : self::stringify($dur) . 'ms',
                $notes,
            ));
        }
    }

    /**
     * Clear the recent-calculations log.
     *
     * ## EXAMPLES
     *
     *     wp opensalestax clear-log
     */
    public function clear_log(): void
    {
        CalculationLog::clear();
        self::success('Calculation log cleared.');
    }

    private static function success(string $msg): void
    {
        if (class_exists('WP_CLI')) {
            \WP_CLI::success($msg);
            return;
        }
        // Terminal output — escape defensively for WP-org Plugin Check
        // satisfaction; never reached in a real WP-CLI context.
        echo esc_html("\xE2\x9C\x93 " . $msg) . "\n";
    }

    private static function error(string $msg): never
    {
        if (class_exists('WP_CLI')) {
            \WP_CLI::error($msg);
            // WP_CLI::error() exits internally — control never returns here.
        }
        // Non-WP-CLI fallback: write to stderr via the standard PHP stream
        // (WP_Filesystem doesn't expose STDERR; this branch is unreachable
        // in a real WP-CLI invocation).
        // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fwrite
        fwrite(STDERR, esc_html("\xE2\x9C\x97 " . $msg) . "\n");
        exit(1);
    }

    private static function warning(string $msg): void
    {
        if (class_exists('WP_CLI')) {
            \WP_CLI::warning($msg);
            return;
        }
        echo esc_html("\xE2\x9A\xA0 " . $msg) . "\n";
    }

    /** Render a mixed scalar (or null) as a string for log output. */
    private static function stringify(mixed $v): string
    {
        if (is_scalar($v)) {
            return (string) $v;
        }
        return '';
    }

    private static function log(string $msg): void
    {
        if (class_exists('WP_CLI')) {
            \WP_CLI::log($msg);
            return;
        }
        // Terminal-only fallback when WP-CLI is not present.
        echo esc_html($msg) . "\n";
    }
}
