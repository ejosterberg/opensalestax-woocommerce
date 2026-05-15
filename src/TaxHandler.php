<?php

// SPDX-License-Identifier: Apache-2.0

declare(strict_types=1);

namespace OpenSalesTax\WooCommerce;

use OpenSalesTax\Address;
use OpenSalesTax\Exceptions\OpenSalesTaxException;
use OpenSalesTax\LineItem;

defined('ABSPATH') || exit;

/**
 * Implements the `woocommerce_calc_tax` filter — replaces WooCommerce's
 * default tax calculation with a call to the OpenSalesTax engine.
 *
 * Strategy: REPLACE (not populate WC's tax-rate tables). Single source of
 * truth = the engine. We honor `WC()->customer->is_vat_exempt()` ourselves
 * because the populate-style flow is bypassed.
 *
 * Hook timing: priority 10 on `woocommerce_calc_tax`. WC fires this after
 * shipping methods have evaluated (so destination address is settled) and
 * after coupons/fees have been applied to the line price.
 */
final class TaxHandler
{
    /**
     * Fallback rate-id used when the placeholder tax-rate row is missing
     * (plugin not activated cleanly). Real rate-id is resolved at request
     * time from `PlaceholderRate::getRateId()` so WC's `get_tax_totals()`
     * can label the line as "OpenSalesTax".
     */
    public const SYNTHETIC_RATE_ID = 'opensalestax';

    private ?int $cachedRateId = null;

    public function __construct(
        private readonly ClientFactory $clientFactory,
        private readonly Cache $cache,
    ) {
    }

    public function register(): void
    {
        add_filter('woocommerce_calc_tax', [$this, 'calcTax'], 10, 4);
    }

    /**
     * @param mixed $taxes              WC's default tax array (we ignore it; we replace)
     * @param mixed $price              Pre-tax line amount in shop currency (assumed USD)
     * @param mixed $rates              Tax rates WC would have applied; used to detect tax_class
     * @param mixed $price_includes_tax
     *
     * @return array<string, float> [rate_id => tax_amount] OR [] for "no tax this line"
     */
    public function calcTax($taxes, $price, $rates, $price_includes_tax): array
    {
        $price = is_numeric($price) ? (float) $price : 0.0;
        if ($price <= 0.0) {
            return [];
        }

        if ($this->isCustomerVatExempt()) {
            return [];
        }

        $zip5 = $this->resolveDestinationZip();
        if ($zip5 === null) {
            return [];
        }

        // Per-state nexus filter: when enabled, skip engine call entirely for
        // states outside the merchant's nexus list. Returns [] so WC falls
        // back to its built-in tax-rate calculation (typically: no tax).
        // Mirrors Vendure v1.2 / Magento (v1.4 candidate) sibling pattern.
        if (!$this->destinationIsInNexus()) {
            return [];
        }

        $category = $this->resolveCategory(is_array($rates) ? $rates : []);
        if ($category === null) {
            // Explicitly non-taxable per WC tax class (zero-rate, etc.)
            return [];
        }

        $cents = (int) round($price * 100);
        $payloadKey = $zip5 . '|' . $category . '|' . $cents;

        $cached = $this->cache->get($payloadKey);
        if ($cached !== null) {
            $cachedTotal = is_array($cached) ? (float) array_sum(array_map('floatval', $cached)) : 0.0;
            CalculationLog::record(
                source: CalculationLog::SOURCE_CACHE_HIT,
                zip5: $zip5,
                category: $category,
                amount: $price,
                taxTotal: $cachedTotal,
            );
            return $cached;
        }

        $client = $this->clientFactory->build();
        if ($client === null) {
            // Plugin not configured — return empty (no tax line). The settings
            // page nags the merchant when base URL is empty, so this is a
            // benign degraded state, not an error.
            return [];
        }

        $startMs = (int) (microtime(true) * 1000);
        try {
            $result = $client->calculate(
                address: new Address(zip5: $zip5),
                lineItems: [
                    new LineItem(
                        amount: number_format($price, 2, '.', ''),
                        category: $category,
                    ),
                ],
            );
        } catch (OpenSalesTaxException $e) {
            error_log('[opensalestax-woocommerce] calculate failed: ' . $e->getMessage());
            CalculationLog::record(
                source: CalculationLog::SOURCE_ERROR,
                zip5: $zip5,
                category: $category,
                amount: $price,
                taxTotal: null,
                durationMs: (int) (microtime(true) * 1000) - $startMs,
                error: $e->getMessage(),
            );
            return $this->fallbackOnError();
        } catch (\Throwable $e) {
            error_log('[opensalestax-woocommerce] unexpected calculate error: ' . get_class($e) . ': ' . $e->getMessage());
            CalculationLog::record(
                source: CalculationLog::SOURCE_ERROR,
                zip5: $zip5,
                category: $category,
                amount: $price,
                taxTotal: null,
                durationMs: (int) (microtime(true) * 1000) - $startMs,
                error: get_class($e) . ': ' . $e->getMessage(),
            );
            return $this->fallbackOnError();
        }

        $taxTotal = (float) $result->taxTotal;
        $rateKey = $this->resolveRateKey();
        $out = [$rateKey => $taxTotal];
        $this->cache->set($payloadKey, $out);
        CalculationLog::record(
            source: CalculationLog::SOURCE_ENGINE_CALL,
            zip5: $zip5,
            category: $category,
            amount: $price,
            taxTotal: $taxTotal,
            durationMs: (int) (microtime(true) * 1000) - $startMs,
        );
        return $out;
    }

    /**
     * Resolve the rate-array key. Prefers the placeholder rate's int id
     * (so WC's `get_tax_totals()` can resolve our line as "OpenSalesTax"
     * via the rates table). Falls back to the legacy string id if the
     * placeholder row is missing.
     */
    private function resolveRateKey(): string
    {
        if ($this->cachedRateId !== null) {
            return (string) $this->cachedRateId;
        }
        $rateId = PlaceholderRate::getRateId();
        if ($rateId !== null) {
            $this->cachedRateId = $rateId;
            return (string) $rateId;
        }
        return self::SYNTHETIC_RATE_ID;
    }

    private function isCustomerVatExempt(): bool
    {
        if (!function_exists('WC')) {
            return false;
        }
        $wc = \WC();
        if (!is_object($wc) || !isset($wc->customer) || !is_object($wc->customer)) {
            return false;
        }
        $customer = $wc->customer;
        if (!method_exists($customer, 'is_vat_exempt')) {
            return false;
        }
        return (bool) $customer->is_vat_exempt();
    }

    private function resolveDestinationZip(): ?string
    {
        if (!function_exists('WC')) {
            return null;
        }
        $wc = \WC();
        if (!is_object($wc) || !isset($wc->customer) || !is_object($wc->customer)) {
            return null;
        }
        $customer = $wc->customer;

        $taxBasedOn = self::stringOption('woocommerce_tax_based_on', 'shipping');
        $rawZip = match ($taxBasedOn) {
            'billing' => $this->callCustomerMethod($customer, 'get_billing_postcode'),
            'base'    => self::stringOption('woocommerce_store_postcode', ''),
            default   => $this->callCustomerMethod($customer, 'get_shipping_postcode')
                ?: $this->callCustomerMethod($customer, 'get_billing_postcode'),
        };

        if ($rawZip === '') {
            return null;
        }

        $digits = preg_replace('/\D/', '', $rawZip) ?? '';
        if (strlen($digits) < 5) {
            return null;
        }
        return substr($digits, 0, 5);
    }

    /**
     * True if the merchant has nexus in the destination's state (or the
     * filter is disabled). The customer's "tax_based_on" choice picks
     * which address feeds the state lookup; an unresolvable state with the
     * filter ENABLED is treated as out-of-nexus (fail-closed — the safer
     * default for a merchant who explicitly opted into the filter).
     */
    private function destinationIsInNexus(): bool
    {
        if (self::stringOption('opensalestax_nexus_enabled', '0') !== '1') {
            return true;
        }
        $allowlist = $this->nexusAllowlist();
        $state = $this->resolveDestinationState();
        if ($state === null) {
            return false;
        }
        return in_array(strtoupper($state), $allowlist, true);
    }

    /**
     * Two-letter destination-state code, uppercased, or null if not
     * resolvable. Mirrors `resolveDestinationZip()` — billing/shipping/base
     * selection follows the same `woocommerce_tax_based_on` option.
     */
    private function resolveDestinationState(): ?string
    {
        if (!function_exists('WC')) {
            return null;
        }
        $wc = \WC();
        if (!is_object($wc) || !isset($wc->customer) || !is_object($wc->customer)) {
            return null;
        }
        $customer = $wc->customer;

        $taxBasedOn = self::stringOption('woocommerce_tax_based_on', 'shipping');
        $rawState = match ($taxBasedOn) {
            'billing' => $this->callCustomerMethod($customer, 'get_billing_state'),
            'base'    => self::stringOption('woocommerce_store_state', ''),
            default   => $this->callCustomerMethod($customer, 'get_shipping_state')
                ?: $this->callCustomerMethod($customer, 'get_billing_state'),
        };

        $state = strtoupper(trim($rawState));
        if (strlen($state) !== 2 || !ctype_alpha($state)) {
            return null;
        }
        return $state;
    }

    /**
     * Parsed allowlist from `opensalestax_nexus_states`: an array of
     * uppercase 2-letter state codes, deduplicated. Empty list means the
     * merchant turned the filter on but listed no states — degenerate, but
     * we honor it (everywhere is out-of-nexus → no tax lines).
     *
     * @return array<int, string>
     */
    private function nexusAllowlist(): array
    {
        $raw = self::stringOption('opensalestax_nexus_states', '');
        if ($raw === '') {
            return [];
        }
        $parts = preg_split('/[\s,]+/', strtoupper($raw)) ?: [];
        $out = [];
        foreach ($parts as $part) {
            if (strlen($part) === 2 && ctype_alpha($part) && !in_array($part, $out, true)) {
                $out[] = $part;
            }
        }
        return $out;
    }

    private static function stringOption(string $name, string $default): string
    {
        $v = get_option($name, $default);
        return is_string($v) ? $v : $default;
    }

    private function callCustomerMethod(object $customer, string $method): string
    {
        if (!method_exists($customer, $method)) {
            return '';
        }
        /** @var mixed $val */
        $val = $customer->{$method}();
        return is_string($val) ? $val : '';
    }

    /**
     * @param array<int, array<string, mixed>> $rates  WC tax-rate rows for this line
     * @return string|null  OST category name, or null to short-circuit "no tax"
     */
    private function resolveCategory(array $rates): ?string
    {
        // Take the first row's tax_rate_class — WC always matches by class,
        // so all rate rows for one line item share the same class.
        foreach ($rates as $row) {
            if (!is_array($row)) {
                continue;
            }
            $class = isset($row['tax_rate_class']) && is_string($row['tax_rate_class'])
                ? $row['tax_rate_class']
                : '';
            return TaxClassMap::mapClassToCategory($class);
        }
        // No rate rows at all — fall back to general. Should be rare; means
        // WC fired the filter without any matching rates, which only happens
        // when WC tax calculation is in a degenerate state.
        return TaxClassMap::FALLBACK_CATEGORY;
    }

    /**
     * @return array<string, float>
     */
    private function fallbackOnError(): array
    {
        $mode = self::stringOption('opensalestax_error_fallback', 'block');
        if ($mode !== 'zero') {
            return [];
        }
        return [$this->resolveRateKey() => 0.0];
    }
}
