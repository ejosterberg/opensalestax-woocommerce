<?php

// SPDX-License-Identifier: Apache-2.0

declare(strict_types=1);

namespace OpenSalesTax\WooCommerce;

defined('ABSPATH') || exit;

/**
 * Manages a single placeholder row in `wp_woocommerce_tax_rates` named
 * "OpenSalesTax". WooCommerce's tax-calculation flow only fires the
 * `woocommerce_calc_tax` filter for line items that have at least one
 * matching tax-rate row in the rates table — without our placeholder,
 * the filter never fires and the plugin can't compute anything.
 *
 * The placeholder row's actual `tax_rate` value is `0.0000`; our
 * TaxHandler overrides the rate dynamically per request via the filter.
 * Using the placeholder's `tax_rate_id` as the synthetic rate-id in
 * the filter return value is what makes WooCommerce's `get_tax_totals()`
 * resolve "OpenSalesTax" as the human-readable tax-line label in the
 * order summary.
 *
 * This class is idempotent: `ensure()` creates the row on first run,
 * subsequent runs find it and return the same id.
 */
final class PlaceholderRate
{
    public const RATE_NAME = 'OpenSalesTax';
    public const RATE_COUNTRY = 'US';

    /**
     * Ensure the placeholder rate exists. Called from plugin activation.
     * Returns the rate id so callers can wire it directly without an
     * extra lookup.
     */
    public static function ensure(): int
    {
        $existing = self::getRateId();
        if ($existing !== null) {
            return $existing;
        }

        global $wpdb;
        $wpdb->insert(
            $wpdb->prefix . 'woocommerce_tax_rates',
            [
                'tax_rate_country'  => self::RATE_COUNTRY,
                'tax_rate_state'    => '',
                'tax_rate'          => '0.0000',
                'tax_rate_name'     => self::RATE_NAME,
                'tax_rate_priority' => 1,
                'tax_rate_compound' => 0,
                'tax_rate_shipping' => 0,
                'tax_rate_order'    => 1,
                'tax_rate_class'    => '',
            ],
            ['%s', '%s', '%s', '%s', '%d', '%d', '%d', '%d', '%s'],
        );

        return (int) $wpdb->insert_id;
    }

    /**
     * Look up the placeholder rate's id without creating one. Returns
     * null if the row doesn't exist (plugin not activated, or row was
     * manually deleted).
     */
    public static function getRateId(): ?int
    {
        global $wpdb;
        $sql = $wpdb->prepare(
            "SELECT tax_rate_id FROM {$wpdb->prefix}woocommerce_tax_rates WHERE tax_rate_name = %s LIMIT 1",
            self::RATE_NAME,
        );
        $rateId = $wpdb->get_var($sql);
        if ($rateId === null) {
            return null;
        }
        return (int) $rateId;
    }

    /**
     * Remove the placeholder rate. Called from plugin deactivation if
     * the merchant wants a clean uninstall. By default we LEAVE the row
     * on deactivation so historical orders' tax lines stay labeled.
     * Only call this from an explicit uninstall.php handler.
     */
    public static function remove(): void
    {
        global $wpdb;
        $wpdb->delete(
            $wpdb->prefix . 'woocommerce_tax_rates',
            ['tax_rate_name' => self::RATE_NAME],
            ['%s'],
        );
    }
}
