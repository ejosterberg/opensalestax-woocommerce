<?php

// SPDX-License-Identifier: Apache-2.0 OR GPL-2.0-or-later

declare(strict_types=1);

namespace OpenSalesTax\WooCommerce;

use OpenSalesTax\Address;
use OpenSalesTax\Exceptions\OpenSalesTaxException;
use OpenSalesTax\LineItem;

defined('ABSPATH') || exit;

/**
 * Persists the per-jurisdiction tax breakdown on each WC order and renders it
 * on the admin order-edit page.
 *
 * The engine returns a full breakdown (state / county / city / district splits)
 * with every /v1/calculate response, but `woocommerce_calc_tax` only consumes
 * the rolled-up total. We re-call the engine on `woocommerce_checkout_create_order`
 * with the order's final line items and persist the breakdown as order meta —
 * the result is cached by the same Cache layer, so re-runs are usually free.
 *
 * The breakdown is stored as JSON on the meta key `_opensalestax_breakdown`
 * (underscore prefix → hidden from WC's default custom-fields UI). Read it
 * programmatically via `OrderTaxBreakdown::get($order)`.
 */
final class OrderTaxBreakdown
{
    public const META_KEY = '_opensalestax_breakdown';

    public function __construct(
        private readonly ClientFactory $clientFactory,
    ) {
    }

    public function register(): void
    {
        add_action('woocommerce_checkout_create_order', [$this, 'captureOnOrderCreate'], 20, 2);
        add_action('woocommerce_admin_order_data_after_order_details', [$this, 'renderOrderDetails']);
        add_action('woocommerce_refund_created', [$this, 'captureOnRefundCreate'], 20, 2);
    }

    /**
     * Hook handler — fires when WC is creating an order from the cart.
     *
     * Re-runs the engine calculation against the order's destination + line
     * items and stores the breakdown on the order. We don't touch the order's
     * tax totals (those are already correct via the calc filter); this is
     * pure metadata for display + audit.
     *
     * Failures here are non-fatal — checkout proceeds even if the breakdown
     * call fails. We log and move on.
     *
     * @param object $order  WC_Order being constructed (typed as object so
     *                       we don't pull WC's class definitions into static
     *                       analysis; runtime guards check for the methods).
     * @param mixed  $data   posted checkout data (unused; we read from $order)
     */
    public function captureOnOrderCreate(object $order, $data): void
    {
        if (!method_exists($order, 'get_shipping_postcode') || !method_exists($order, 'update_meta_data')) {
            return;
        }

        try {
            $payload = $this->buildPayload($order);
            if ($payload === null) {
                return; // No US ZIP, no taxable lines, etc.
            }

            $client = $this->clientFactory->build();
            if ($client === null) {
                return; // Plugin not configured.
            }

            $result = $client->calculate(
                address: new Address(zip5: $payload['zip5']),
                lineItems: $payload['lines'],
            );
        } catch (OpenSalesTaxException $e) {
            self::logWarning('breakdown capture failed: ' . $e->getMessage());
            return;
        } catch (\Throwable $e) {
            self::logWarning('breakdown capture unexpected: ' . get_class($e) . ': ' . $e->getMessage());
            return;
        }

        $serialized = self::serializeResult($result);
        $json = wp_json_encode($serialized);
        if (is_string($json)) {
            $order->update_meta_data(self::META_KEY, $json);
        }
    }

    /**
     * Hook handler — fires after WC creates a refund from a parent order.
     *
     * Refunds present a wrinkle: the engine takes positive line amounts, and
     * a re-calc against an unchanged destination would just produce the same
     * rates as the parent order. So instead of an engine round-trip, we
     * prorate the parent order's stored breakdown by the refund/parent ratio
     * and store the negated values on the refund. Result: the refund's admin
     * page shows the same audit panel as a regular order, with negative
     * jurisdiction amounts.
     *
     * Falls back to no-op if the parent has no stored breakdown (e.g., an
     * order created before v0.3.0). WC's default refund flow already
     * proportions the tax_total correctly, so the refund payment is right
     * either way — this is audit metadata, not a calculation.
     *
     * @param int|string $refundId  ID of the just-created refund
     * @param array<string, mixed>|mixed $args      WC's refund-creation args (unused; we read from the refund)
     */
    public function captureOnRefundCreate($refundId, $args): void
    {
        if (!function_exists('wc_get_order')) {
            return;
        }

        $refund = wc_get_order($refundId);
        if (!is_object($refund) || !method_exists($refund, 'get_parent_id')) {
            return;
        }

        $parentId = (int) $refund->get_parent_id();
        if ($parentId <= 0) {
            return;
        }
        $parentOrder = wc_get_order($parentId);
        if (!is_object($parentOrder)) {
            return;
        }

        $parentBreakdown = self::get($parentOrder);
        if ($parentBreakdown === null) {
            // Parent has no stored breakdown — nothing to prorate. Bail
            // silently; tax_total on the refund is still correct via WC's
            // default flow.
            return;
        }

        $refundTotal = method_exists($refund, 'get_total') ? abs((float) $refund->get_total()) : 0.0;
        $parentTotal = method_exists($parentOrder, 'get_total') ? abs((float) $parentOrder->get_total()) : 0.0;
        if ($parentTotal <= 0.0) {
            return;
        }
        $ratio = -1.0 * ($refundTotal / $parentTotal); // negative — refund reduces collected tax

        $serialized = self::prorateBreakdown($parentBreakdown, $ratio, $parentId);
        $json = wp_json_encode($serialized);
        if (is_string($json) && method_exists($refund, 'update_meta_data')) {
            $refund->update_meta_data(self::META_KEY, $json);
            if (method_exists($refund, 'save')) {
                $refund->save();
            }
        }
    }

    /**
     * Apply a multiplier (typically negative for refunds) to every tax-bearing
     * field in a stored breakdown, returning a new breakdown array. The
     * jurisdiction structure is preserved so the renderer renders identically
     * to a regular order.
     *
     * @param array<string, mixed> $breakdown
     * @return array<string, mixed>
     */
    private static function prorateBreakdown(array $breakdown, float $ratio, int $parentOrderId): array
    {
        $proratedLines = [];
        $linesIn = is_array($breakdown['lines'] ?? null) ? $breakdown['lines'] : [];
        foreach ($linesIn as $line) {
            if (!is_array($line)) {
                continue;
            }
            $proratedJurisdictions = [];
            $jl = is_array($line['jurisdictions'] ?? null) ? $line['jurisdictions'] : [];
            foreach ($jl as $j) {
                if (!is_array($j)) {
                    continue;
                }
                $jTax = (isset($j['tax']) && is_numeric($j['tax'])) ? (float) $j['tax'] : 0.0;
                $proratedJurisdictions[] = [
                    'type' => isset($j['type']) ? (string) $j['type'] : '',
                    'name' => isset($j['name']) ? (string) $j['name'] : '',
                    'rate_pct' => isset($j['rate_pct']) ? (string) $j['rate_pct'] : '0',
                    'tax' => self::numStr($jTax * $ratio, 4),
                ];
            }
            $lineAmount = (isset($line['amount']) && is_numeric($line['amount'])) ? (float) $line['amount'] : 0.0;
            $lineTax = (isset($line['tax']) && is_numeric($line['tax'])) ? (float) $line['tax'] : 0.0;
            $proratedLines[] = [
                'category' => isset($line['category']) ? (string) $line['category'] : '',
                'amount' => self::numStr($lineAmount * $ratio, 2),
                'tax' => self::numStr($lineTax * $ratio, 4),
                'note' => sprintf('Prorated from parent order #%d at ratio %.4f', $parentOrderId, $ratio),
                'jurisdictions' => $proratedJurisdictions,
            ];
        }

        $subtotal = (isset($breakdown['subtotal']) && is_numeric($breakdown['subtotal'])) ? (float) $breakdown['subtotal'] : 0.0;
        $taxTotal = (isset($breakdown['tax_total']) && is_numeric($breakdown['tax_total'])) ? (float) $breakdown['tax_total'] : 0.0;
        return [
            'subtotal' => self::numStr($subtotal * $ratio, 2),
            'tax_total' => self::numStr($taxTotal * $ratio, 4),
            'lines' => $proratedLines,
        ];
    }

    /**
     * Format a float as a string with N decimal places, matching how the
     * engine returns numeric strings. Avoids `(string) $float` which can
     * produce locale-dependent output.
     */
    private static function numStr(float $value, int $decimals): string
    {
        return number_format($value, $decimals, '.', '');
    }

    /**
     * Hook handler — renders the breakdown table inside the WC admin order page.
     *
     * @param object $order
     */
    public function renderOrderDetails(object $order): void
    {
        if (!method_exists($order, 'get_meta')) {
            return;
        }

        $breakdown = self::get($order);
        if ($breakdown === null) {
            return;
        }

        // renderHtml() builds markup from already-escaped (esc_html / esc_attr)
        // pieces. wp_kses_post() satisfies the Plugin Check "echo of composed
        // HTML must pass through an escaping function" rule without altering
        // legitimate output.
        echo wp_kses_post(self::renderHtml($breakdown));
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

    /**
     * Read the breakdown as a structured array, or null if absent / malformed.
     *
     * Public so accounting integrations can pull the data without re-parsing.
     * Shape (when non-null):
     *
     *   subtotal    string
     *   tax_total   string
     *   lines[]
     *     category       string
     *     amount         string
     *     tax            string
     *     note           string|null
     *     jurisdictions[]
     *       type      string
     *       name      string
     *       rate_pct  string
     *       tax       string|null
     *
     * @param object $order
     * @return array<string, mixed>|null
     */
    public static function get(object $order): ?array
    {
        if (!method_exists($order, 'get_meta')) {
            return null;
        }
        $raw = $order->get_meta(self::META_KEY, true);
        if (!is_string($raw) || $raw === '') {
            return null;
        }
        $decoded = json_decode($raw, true);
        if (!is_array($decoded) || !isset($decoded['lines']) || !is_array($decoded['lines'])) {
            return null;
        }
        return $decoded;
    }

    /**
     * Pull the destination ZIP + line items off the order in the format the
     * SDK expects. Returns null if the order isn't a US ZIP we can handle.
     *
     * @param object $order
     * @return array{zip5: string, lines: array<int, LineItem>}|null
     */
    private function buildPayload(object $order): ?array
    {
        $zip5 = $this->resolveZip($order);
        if ($zip5 === null) {
            return null;
        }

        if (!method_exists($order, 'get_items')) {
            return null;
        }
        $items = $order->get_items();
        if (!is_array($items) && !($items instanceof \Traversable)) {
            return null;
        }

        $lineItems = [];
        foreach ($items as $item) {
            if (!is_object($item) || !method_exists($item, 'get_total')) {
                continue;
            }
            $amount = (float) $item->get_total();
            if ($amount <= 0) {
                continue;
            }
            $taxClass = method_exists($item, 'get_tax_class') ? (string) $item->get_tax_class() : '';
            $category = TaxClassMap::mapClassToCategory($taxClass);
            if ($category === null) {
                continue; // Skip non-taxable line.
            }
            $lineItems[] = new LineItem(
                amount: number_format($amount, 2, '.', ''),
                category: $category,
            );
        }

        if (count($lineItems) === 0) {
            return null;
        }

        return ['zip5' => $zip5, 'lines' => $lineItems];
    }

    /**
     * Resolve the destination ZIP from the order's shipping (or billing fallback)
     * postcode.
     *
     * @param object $order
     */
    private function resolveZip(object $order): ?string
    {
        $candidates = [];
        if (method_exists($order, 'get_shipping_postcode')) {
            $candidates[] = (string) $order->get_shipping_postcode();
        }
        if (method_exists($order, 'get_billing_postcode')) {
            $candidates[] = (string) $order->get_billing_postcode();
        }
        foreach ($candidates as $raw) {
            $digits = preg_replace('/\D/', '', $raw) ?? '';
            if (strlen($digits) >= 5) {
                return substr($digits, 0, 5);
            }
        }
        return null;
    }

    /**
     * Convert the SDK's CalculateResponse object into a plain array suitable
     * for JSON storage. The shape is stable and documented in get()'s phpdoc.
     *
     * @param \OpenSalesTax\Responses\CalculateResponse $result
     * @return array<string, mixed>
     */
    private static function serializeResult(\OpenSalesTax\Responses\CalculateResponse $result): array
    {
        $lines = [];
        foreach ($result->lines as $line) {
            $jurisdictions = [];
            foreach ($line->jurisdictions as $j) {
                $jurisdictions[] = [
                    'type' => $j->type,
                    'name' => $j->name,
                    'rate_pct' => $j->ratePct,
                    'tax' => $j->tax,
                ];
            }
            $lines[] = [
                'category' => $line->category,
                'amount' => $line->amount,
                'tax' => $line->tax,
                'note' => $line->note,
                'jurisdictions' => $jurisdictions,
            ];
        }
        return [
            'subtotal' => $result->subtotal,
            'tax_total' => $result->taxTotal,
            'lines' => $lines,
        ];
    }

    /**
     * Render the breakdown as HTML for the admin order page.
     *
     * @param array<string, mixed> $breakdown
     */
    public static function renderHtml(array $breakdown): string
    {
        $lines = $breakdown['lines'] ?? [];
        if (!is_array($lines) || count($lines) === 0) {
            return '';
        }

        $out = '<div class="opensalestax-breakdown" style="margin-top:1em;padding:1em;background:#f6f7f7;border-left:4px solid #2271b1;">';
        $out .= '<h4 style="margin-top:0;">' . esc_html__('OpenSalesTax breakdown', 'opensalestax-for-woocommerce') . '</h4>';
        $out .= '<p class="description" style="margin:0 0 .5em;">'
              . esc_html__('Per-jurisdiction tax computed by the OpenSalesTax engine. Use for audit reconciliation; the rolled-up total is what was charged.', 'opensalestax-for-woocommerce')
              . '</p>';

        foreach ($lines as $line) {
            if (!is_array($line)) {
                continue;
            }
            $category = (string) ($line['category'] ?? '');
            $amount = (string) ($line['amount'] ?? '0');
            $tax = (string) ($line['tax'] ?? '0');
            $note = isset($line['note']) && is_string($line['note']) ? $line['note'] : null;
            $jurisdictions = is_array($line['jurisdictions'] ?? null) ? $line['jurisdictions'] : [];

            $out .= '<table class="widefat striped" style="margin-bottom:.5em;">';
            $out .= '<thead><tr>';
            $out .= '<th colspan="4">' . sprintf(
                /* translators: 1: category slug, 2: pre-tax amount, 3: tax amount */
                esc_html__('Line: %1$s — amount $%2$s, tax $%3$s', 'opensalestax-for-woocommerce'),
                esc_html($category),
                esc_html($amount),
                esc_html($tax),
            ) . '</th>';
            $out .= '</tr><tr>';
            $out .= '<th>' . esc_html__('Type', 'opensalestax-for-woocommerce') . '</th>';
            $out .= '<th>' . esc_html__('Jurisdiction', 'opensalestax-for-woocommerce') . '</th>';
            $out .= '<th style="text-align:right;">' . esc_html__('Rate', 'opensalestax-for-woocommerce') . '</th>';
            $out .= '<th style="text-align:right;">' . esc_html__('Tax', 'opensalestax-for-woocommerce') . '</th>';
            $out .= '</tr></thead><tbody>';

            if (count($jurisdictions) === 0) {
                $out .= '<tr><td colspan="4"><em>' . esc_html__('No jurisdictions returned (non-taxable category or uncovered ZIP).', 'opensalestax-for-woocommerce') . '</em></td></tr>';
            } else {
                foreach ($jurisdictions as $j) {
                    if (!is_array($j)) {
                        continue;
                    }
                    $jTax = $j['tax'] ?? null;
                    $jTaxStr = (is_string($jTax) || is_numeric($jTax)) ? '$' . (string) $jTax : '—';
                    $out .= '<tr>';
                    $out .= '<td>' . esc_html((string) ($j['type'] ?? '')) . '</td>';
                    $out .= '<td>' . esc_html((string) ($j['name'] ?? '')) . '</td>';
                    $out .= '<td style="text-align:right;">' . esc_html((string) ($j['rate_pct'] ?? '0')) . '%</td>';
                    $out .= '<td style="text-align:right;">' . esc_html($jTaxStr) . '</td>';
                    $out .= '</tr>';
                }
            }

            $out .= '</tbody></table>';

            if ($note !== null && $note !== '') {
                $out .= '<p style="margin:0 0 1em;"><em>' . esc_html__('Note:', 'opensalestax-for-woocommerce') . ' ' . esc_html($note) . '</em></p>';
            }
        }

        $out .= '</div>';
        return $out;
    }
}
