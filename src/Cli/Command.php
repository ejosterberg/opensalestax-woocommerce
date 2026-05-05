<?php

// SPDX-License-Identifier: Apache-2.0

declare(strict_types=1);

namespace OpenSalesTax\WooCommerce\Cli;

use OpenSalesTax\Address;
use OpenSalesTax\Exceptions\OpenSalesTaxException;
use OpenSalesTax\LineItem;
use OpenSalesTax\WooCommerce\Cache;
use OpenSalesTax\WooCommerce\ClientFactory;
use OpenSalesTax\WooCommerce\PlaceholderRate;

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
            self::log('  Run `wp plugin deactivate opensalestax-woocommerce && wp plugin activate opensalestax-woocommerce` to create it.');
            return;
        }
        self::success("Placeholder rate row: tax_rate_id={$rateId}, tax_rate_name='" . PlaceholderRate::RATE_NAME . "'");
    }

    private static function success(string $msg): void
    {
        if (class_exists('WP_CLI')) {
            \WP_CLI::success($msg);
            return;
        }
        echo "✓ {$msg}\n";
    }

    private static function error(string $msg): never
    {
        if (class_exists('WP_CLI')) {
            \WP_CLI::error($msg);
            // WP_CLI::error() exits internally — control never returns here.
        }
        fwrite(STDERR, "✗ {$msg}\n");
        exit(1);
    }

    private static function warning(string $msg): void
    {
        if (class_exists('WP_CLI')) {
            \WP_CLI::warning($msg);
            return;
        }
        echo "⚠ {$msg}\n";
    }

    private static function log(string $msg): void
    {
        if (class_exists('WP_CLI')) {
            \WP_CLI::log($msg);
            return;
        }
        echo $msg . "\n";
    }
}
