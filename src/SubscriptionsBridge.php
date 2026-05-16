<?php

// SPDX-License-Identifier: Apache-2.0 OR GPL-2.0-or-later

declare(strict_types=1);

namespace OpenSalesTax\WooCommerce;

defined('ABSPATH') || exit;

/**
 * WooCommerce Subscriptions integration — recalculates tax on every renewal
 * order so destination-rate changes (state law updates, customer moves) are
 * picked up automatically rather than blindly copied from the parent
 * subscription.
 *
 * Without this bridge: WC Subscriptions clones the parent sub's tax line
 * onto the renewal order. If MN raised a transit-district rate three months
 * after the original sub started, every renewal under-collects until
 * someone manually fixes it.
 *
 * With this bridge: each renewal order's tax is computed fresh against
 * today's rates via the same engine call path the original cart used.
 *
 * The bridge is a no-op when WC Subscriptions isn't installed — `register()`
 * checks for the parent class before hooking. So this class is safe to wire
 * up unconditionally in `Plugin::wireUp()`.
 *
 * The hook fires at priority 20, after WC Subscriptions' own renewal-creation
 * machinery runs, so the order's line items + addresses are settled by the
 * time we recalculate.
 */
final class SubscriptionsBridge
{
    public function __construct(
        private readonly OrderTaxBreakdown $breakdown,
    ) {
    }

    public function register(): void
    {
        // Guard: skip when WC Subscriptions isn't loaded.
        if (!self::isSubscriptionsActive()) {
            return;
        }
        add_action('wcs_renewal_order_created', [$this, 'recalcRenewalTax'], 20, 2);
    }

    /**
     * Hook handler — fires after WC Subscriptions creates a renewal order.
     * Forces a tax recalculation against current rates and re-captures the
     * per-jurisdiction breakdown.
     *
     * @param mixed $renewalOrder  WC_Order instance
     * @param mixed $subscription  WC_Subscription instance (unused; we read from $renewalOrder)
     */
    public function recalcRenewalTax($renewalOrder, $subscription): void
    {
        if (!is_object($renewalOrder) || !method_exists($renewalOrder, 'calculate_taxes')) {
            return;
        }

        try {
            // calculate_taxes() walks the line items and triggers the
            // `woocommerce_calc_tax` filter chain, which lands in our
            // TaxHandler → engine → fresh tax. The renewal address comes
            // from the order's billing/shipping fields (which WC
            // Subscriptions copies from the parent sub by default; the
            // merchant can override per-renewal if needed).
            $renewalOrder->calculate_taxes();
            // calculate_totals re-rolls subtotal + tax_total + grand total
            // after the per-line tax has changed.
            if (method_exists($renewalOrder, 'calculate_totals')) {
                $renewalOrder->calculate_totals();
            }
            if (method_exists($renewalOrder, 'save')) {
                $renewalOrder->save();
            }
        } catch (\Throwable $e) {
            self::logWarning('subscription renewal recalc failed: '
                . get_class($e) . ': ' . $e->getMessage());
            return;
        }

        // Capture the per-jurisdiction breakdown so the renewal order's
        // admin page shows the same audit panel as a regular order.
        $this->breakdown->captureOnOrderCreate($renewalOrder, []);
        if (method_exists($renewalOrder, 'save')) {
            $renewalOrder->save();
        }
    }

    /**
     * Detect whether WC Subscriptions is loaded. Public so admin notices
     * and the dashboard widget can surface a "Subscriptions integration
     * active" badge in the future.
     */
    public static function isSubscriptionsActive(): bool
    {
        return class_exists('WC_Subscriptions') || class_exists('WC_Subscriptions_Core_Plugin');
    }

    /**
     * Log a warning through WC's logger when available; fall back to PHP's
     * error_log when WC isn't loaded (unit-test contexts).
     */
    private static function logWarning(string $msg): void
    {
        if (function_exists('wc_get_logger')) {
            wc_get_logger()->warning('[opensalestax-for-woocommerce] ' . $msg, ['source' => 'opensalestax-for-woocommerce']);
            return;
        }
        // phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log
        error_log('[opensalestax-for-woocommerce] ' . $msg);
    }
}
