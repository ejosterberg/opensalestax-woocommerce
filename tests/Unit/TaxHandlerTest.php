<?php

// SPDX-License-Identifier: Apache-2.0

declare(strict_types=1);

namespace OpenSalesTax\WooCommerce\Tests\Unit;

use GuzzleHttp\Psr7\Response;
use OpenSalesTax\Client as OpenSalesTaxClient;
use OpenSalesTax\WooCommerce\Cache;
use OpenSalesTax\WooCommerce\ClientFactory;
use OpenSalesTax\WooCommerce\TaxHandler;
use PHPUnit\Framework\TestCase;
use Psr\Http\Client\ClientInterface as Psr18Client;
use WP_Mock;

/**
 * Tests the woocommerce_calc_tax filter implementation.
 *
 * For the engine call we build a real OpenSalesTax\Client with a PSR-18
 * mock that returns canned engine JSON. ClientFactory is non-final and
 * extended via anonymous classes to inject that pre-built Client.
 */
final class TaxHandlerTest extends TestCase
{
    protected function setUp(): void
    {
        WP_Mock::setUp();
        // CalculationLog::isEnabled() is called from the calc-success and
        // engine-error code paths in TaxHandler::calcTax. Default behavior in
        // production is "disabled" — match that here so tests don't have to
        // mock the option individually unless they specifically exercise the
        // logging path.
        WP_Mock::userFunction('get_option', [
            'args' => ['opensalestax_calc_log_enabled', '0'],
            'return' => '0',
        ]);
        // Minimal $wpdb stub for `PlaceholderRate::getRateId()` (consulted
        // by `TaxHandler::resolveRateKey()` on every success/zero-mode
        // path). Returns null for the rate lookup so callers fall back to
        // the SYNTHETIC_RATE_ID 'opensalestax', matching prior expectations.
        // (Pre-v0.5 these tests relied on `$wpdb` already being set as a
        // side effect of DashboardWidgetTest running first — fragile;
        // explicit is better.)
        global $wpdb;
        $wpdb = new class () {
            public string $prefix = 'wp_';
            public function prepare(string $query, mixed ...$args): string
            {
                return $query;
            }
            public function get_var(string $query): ?string
            {
                return null;
            }
        };
    }

    /**
     * The v0.5 per-state nexus filter is consulted on every calcTax that
     * resolves a zip; tests that go past zip-resolution must opt the filter
     * in or out. Default for back-compat = disabled.
     */
    private function expectNexusFilterDisabled(): void
    {
        WP_Mock::userFunction('get_option', [
            'times' => 1,
            'args' => ['opensalestax_nexus_enabled', '0'],
            'return' => '0',
        ]);
    }

    protected function tearDown(): void
    {
        WP_Mock::tearDown();
    }

    public function testZeroPriceShortCircuits(): void
    {
        $handler = new TaxHandler($this->factoryThatShouldNotBeCalled(), new Cache());
        self::assertSame([], $handler->calcTax([], 0.0, [], false));
        self::assertSame([], $handler->calcTax([], '0', [], false));
    }

    public function testTaxExemptCustomerReturnsEmpty(): void
    {
        $customer = new class () {
            public function is_vat_exempt(): bool
            {
                return true;
            }
        };
        $this->stubWC($customer);

        $handler = new TaxHandler($this->factoryThatShouldNotBeCalled(), new Cache());
        self::assertSame([], $handler->calcTax([], 100.0, [], false));
    }

    public function testReturnsEmptyWhenNoZipResolvable(): void
    {
        $customer = new class () {
            public function is_vat_exempt(): bool
            {
                return false;
            }
            public function get_shipping_postcode(): string
            {
                return '';
            }
            public function get_billing_postcode(): string
            {
                return '';
            }
        };
        $this->stubWC($customer);
        WP_Mock::userFunction('get_option', [
            'times' => 1,
            'args' => ['woocommerce_tax_based_on', 'shipping'],
            'return' => 'shipping',
        ]);

        $handler = new TaxHandler($this->factoryThatShouldNotBeCalled(), new Cache());
        self::assertSame([], $handler->calcTax([], 100.0, [], false));
    }

    public function testZeroRateTaxClassReturnsEmpty(): void
    {
        $customer = new class () {
            public function is_vat_exempt(): bool
            {
                return false;
            }
            public function get_shipping_postcode(): string
            {
                return '55401';
            }
            public function get_billing_postcode(): string
            {
                return '';
            }
        };
        $this->stubWC($customer);
        WP_Mock::userFunction('get_option', [
            'times' => 1,
            'args' => ['woocommerce_tax_based_on', 'shipping'],
            'return' => 'shipping',
        ]);
        $this->expectNexusFilterDisabled();
        // TaxClassMap consults the merchant-override option; no override → defaults apply.
        WP_Mock::userFunction('get_option', [
            'args' => ['opensalestax_tax_class_map', ''],
            'return' => '',
        ]);

        $rates = [['tax_rate_class' => 'zero-rate', 'rate' => 0.0]];

        $handler = new TaxHandler($this->factoryThatShouldNotBeCalled(), new Cache());
        self::assertSame([], $handler->calcTax([], 100.0, $rates, false));
    }

    public function testNexusFilterDisabledByDefault(): void
    {
        // Filter off → identical behavior to the pre-v0.5 plugin. No reads
        // on `opensalestax_nexus_states`, no state lookup. Verified by
        // hitting a cache hit and confirming the engine is never built.
        $customer = new class () {
            public function is_vat_exempt(): bool
            {
                return false;
            }
            public function get_shipping_postcode(): string
            {
                return '55401';
            }
            public function get_billing_postcode(): string
            {
                return '';
            }
        };
        $this->stubWC($customer);
        WP_Mock::userFunction('get_option', [
            'times' => 1,
            'args' => ['woocommerce_tax_based_on', 'shipping'],
            'return' => 'shipping',
        ]);
        $this->expectNexusFilterDisabled();
        WP_Mock::userFunction('get_transient', [
            'times' => 1,
            'return' => ['opensalestax' => 8.025],
        ]);

        $handler = new TaxHandler($this->factoryThatShouldNotBeCalled(), new Cache());
        self::assertSame(['opensalestax' => 8.025], $handler->calcTax([], 100.0, [], false));
    }

    public function testNexusFilterAllowsDestinationInListedState(): void
    {
        $customer = new class () {
            public function is_vat_exempt(): bool
            {
                return false;
            }
            public function get_shipping_postcode(): string
            {
                return '55401';
            }
            public function get_billing_postcode(): string
            {
                return '';
            }
            public function get_shipping_state(): string
            {
                return 'MN';
            }
            public function get_billing_state(): string
            {
                return '';
            }
        };
        $this->stubWC($customer);
        // First call resolves zip → reads tax_based_on
        WP_Mock::userFunction('get_option', [
            'times' => 1,
            'args' => ['woocommerce_tax_based_on', 'shipping'],
            'return' => 'shipping',
        ]);
        WP_Mock::userFunction('get_option', [
            'times' => 1,
            'args' => ['opensalestax_nexus_enabled', '0'],
            'return' => '1',
        ]);
        WP_Mock::userFunction('get_option', [
            'times' => 1,
            'args' => ['opensalestax_nexus_states', ''],
            'return' => 'MN, WI, IA',
        ]);
        // resolveDestinationState() reads tax_based_on a second time.
        WP_Mock::userFunction('get_option', [
            'times' => 1,
            'args' => ['woocommerce_tax_based_on', 'shipping'],
            'return' => 'shipping',
        ]);
        WP_Mock::userFunction('get_transient', [
            'times' => 1,
            'return' => ['opensalestax' => 8.025],
        ]);

        $handler = new TaxHandler($this->factoryThatShouldNotBeCalled(), new Cache());
        self::assertSame(['opensalestax' => 8.025], $handler->calcTax([], 100.0, [], false));
    }

    public function testNexusFilterBlocksDestinationOutsideListedStates(): void
    {
        $customer = new class () {
            public function is_vat_exempt(): bool
            {
                return false;
            }
            public function get_shipping_postcode(): string
            {
                return '90210'; // CA
            }
            public function get_billing_postcode(): string
            {
                return '';
            }
            public function get_shipping_state(): string
            {
                return 'CA';
            }
            public function get_billing_state(): string
            {
                return '';
            }
        };
        $this->stubWC($customer);
        WP_Mock::userFunction('get_option', [
            'times' => 1,
            'args' => ['woocommerce_tax_based_on', 'shipping'],
            'return' => 'shipping',
        ]);
        WP_Mock::userFunction('get_option', [
            'times' => 1,
            'args' => ['opensalestax_nexus_enabled', '0'],
            'return' => '1',
        ]);
        WP_Mock::userFunction('get_option', [
            'times' => 1,
            'args' => ['opensalestax_nexus_states', ''],
            'return' => 'MN, WI, IA',
        ]);
        WP_Mock::userFunction('get_option', [
            'times' => 1,
            'args' => ['woocommerce_tax_based_on', 'shipping'],
            'return' => 'shipping',
        ]);

        $handler = new TaxHandler($this->factoryThatShouldNotBeCalled(), new Cache());
        self::assertSame([], $handler->calcTax([], 100.0, [], false));
    }

    public function testNexusFilterEnabledWithEmptyListBlocksEverywhere(): void
    {
        // Degenerate config — filter on but no states listed. Honor it
        // strictly: no taxable line anywhere. The settings UI prompts the
        // merchant when this happens; we don't second-guess.
        $customer = new class () {
            public function is_vat_exempt(): bool
            {
                return false;
            }
            public function get_shipping_postcode(): string
            {
                return '55401';
            }
            public function get_billing_postcode(): string
            {
                return '';
            }
            public function get_shipping_state(): string
            {
                return 'MN';
            }
            public function get_billing_state(): string
            {
                return '';
            }
        };
        $this->stubWC($customer);
        WP_Mock::userFunction('get_option', [
            'times' => 1,
            'args' => ['woocommerce_tax_based_on', 'shipping'],
            'return' => 'shipping',
        ]);
        WP_Mock::userFunction('get_option', [
            'times' => 1,
            'args' => ['opensalestax_nexus_enabled', '0'],
            'return' => '1',
        ]);
        WP_Mock::userFunction('get_option', [
            'times' => 1,
            'args' => ['opensalestax_nexus_states', ''],
            'return' => '',
        ]);
        WP_Mock::userFunction('get_option', [
            'times' => 1,
            'args' => ['woocommerce_tax_based_on', 'shipping'],
            'return' => 'shipping',
        ]);

        $handler = new TaxHandler($this->factoryThatShouldNotBeCalled(), new Cache());
        self::assertSame([], $handler->calcTax([], 100.0, [], false));
    }

    public function testNexusFilterBlocksWhenDestinationStateMissing(): void
    {
        // Filter enabled but state cannot be resolved (customer hasn't
        // filled in state). Fail-closed — safer than silently ignoring.
        $customer = new class () {
            public function is_vat_exempt(): bool
            {
                return false;
            }
            public function get_shipping_postcode(): string
            {
                return '55401';
            }
            public function get_billing_postcode(): string
            {
                return '';
            }
            public function get_shipping_state(): string
            {
                return '';
            }
            public function get_billing_state(): string
            {
                return '';
            }
        };
        $this->stubWC($customer);
        WP_Mock::userFunction('get_option', [
            'times' => 1,
            'args' => ['woocommerce_tax_based_on', 'shipping'],
            'return' => 'shipping',
        ]);
        WP_Mock::userFunction('get_option', [
            'times' => 1,
            'args' => ['opensalestax_nexus_enabled', '0'],
            'return' => '1',
        ]);
        WP_Mock::userFunction('get_option', [
            'times' => 1,
            'args' => ['opensalestax_nexus_states', ''],
            'return' => 'MN, WI',
        ]);
        WP_Mock::userFunction('get_option', [
            'times' => 1,
            'args' => ['woocommerce_tax_based_on', 'shipping'],
            'return' => 'shipping',
        ]);

        $handler = new TaxHandler($this->factoryThatShouldNotBeCalled(), new Cache());
        self::assertSame([], $handler->calcTax([], 100.0, [], false));
    }

    public function testCacheHitReturnsWithoutCallingEngine(): void
    {
        $customer = new class () {
            public function is_vat_exempt(): bool
            {
                return false;
            }
            public function get_shipping_postcode(): string
            {
                return '55401';
            }
            public function get_billing_postcode(): string
            {
                return '';
            }
        };
        $this->stubWC($customer);
        WP_Mock::userFunction('get_option', [
            'times' => 1,
            'args' => ['woocommerce_tax_based_on', 'shipping'],
            'return' => 'shipping',
        ]);
        $this->expectNexusFilterDisabled();
        WP_Mock::userFunction('get_transient', [
            'times' => 1,
            'return' => ['opensalestax' => 8.025],
        ]);

        $handler = new TaxHandler($this->factoryThatShouldNotBeCalled(), new Cache());
        $result = $handler->calcTax([], 100.0, [], false);
        self::assertSame(['opensalestax' => 8.025], $result);
    }

    public function testCacheMissCallsEngineAndCaches(): void
    {
        $customer = new class () {
            public function is_vat_exempt(): bool
            {
                return false;
            }
            public function get_shipping_postcode(): string
            {
                return '55401';
            }
            public function get_billing_postcode(): string
            {
                return '';
            }
        };
        $this->stubWC($customer);
        WP_Mock::userFunction('get_option', [
            'times' => 1,
            'args' => ['woocommerce_tax_based_on', 'shipping'],
            'return' => 'shipping',
        ]);
        $this->expectNexusFilterDisabled();
        WP_Mock::userFunction('get_transient', [
            'times' => 1,
            'return' => false,
        ]);
        WP_Mock::userFunction('get_option', [
            'times' => 1,
            'args' => ['opensalestax_cache_ttl_minutes', 60],
            'return' => 60,
        ]);
        WP_Mock::userFunction('set_transient', [
            'times' => 1,
            'return' => true,
        ]);

        $factory = $this->factoryReturning([
            'subtotal' => '100.00',
            'tax_total' => '8.025',
            'lines' => [],
            'disclaimer' => 'Calculation only',
        ]);

        $handler = new TaxHandler($factory, new Cache());
        $result = $handler->calcTax([], 100.0, [], false);

        self::assertArrayHasKey(TaxHandler::SYNTHETIC_RATE_ID, $result);
        self::assertEqualsWithDelta(8.025, $result[TaxHandler::SYNTHETIC_RATE_ID], 0.0001);
    }

    public function testEngineErrorBlockMode(): void
    {
        $customer = new class () {
            public function is_vat_exempt(): bool
            {
                return false;
            }
            public function get_shipping_postcode(): string
            {
                return '55401';
            }
            public function get_billing_postcode(): string
            {
                return '';
            }
        };
        $this->stubWC($customer);
        WP_Mock::userFunction('get_option', [
            'times' => 1,
            'args' => ['woocommerce_tax_based_on', 'shipping'],
            'return' => 'shipping',
        ]);
        $this->expectNexusFilterDisabled();
        WP_Mock::userFunction('get_transient', [
            'times' => 1,
            'return' => false,
        ]);
        WP_Mock::userFunction('get_option', [
            'times' => 1,
            'args' => ['opensalestax_error_fallback', 'block'],
            'return' => 'block',
        ]);

        $factory = $this->factoryReturningHttpStatus(500);

        $handler = new TaxHandler($factory, new Cache());
        $result = $handler->calcTax([], 100.0, [], false);
        self::assertSame([], $result);
    }

    public function testEngineErrorZeroMode(): void
    {
        $customer = new class () {
            public function is_vat_exempt(): bool
            {
                return false;
            }
            public function get_shipping_postcode(): string
            {
                return '55401';
            }
            public function get_billing_postcode(): string
            {
                return '';
            }
        };
        $this->stubWC($customer);
        WP_Mock::userFunction('get_option', [
            'times' => 1,
            'args' => ['woocommerce_tax_based_on', 'shipping'],
            'return' => 'shipping',
        ]);
        $this->expectNexusFilterDisabled();
        WP_Mock::userFunction('get_transient', [
            'times' => 1,
            'return' => false,
        ]);
        WP_Mock::userFunction('get_option', [
            'times' => 1,
            'args' => ['opensalestax_error_fallback', 'block'],
            'return' => 'zero',
        ]);

        $factory = $this->factoryReturningHttpStatus(500);

        $handler = new TaxHandler($factory, new Cache());
        $result = $handler->calcTax([], 100.0, [], false);
        self::assertSame([TaxHandler::SYNTHETIC_RATE_ID => 0.0], $result);
    }

    /**
     * Build a ClientFactory test-double whose `build()` returns a real
     * `OpenSalesTax\Client` configured with a PSR-18 mock that returns
     * the given response body as JSON with HTTP 200.
     *
     * @param array<string, mixed> $responseBody
     */
    private function factoryReturning(array $responseBody): ClientFactory
    {
        $http = $this->createMock(Psr18Client::class);
        $http->method('sendRequest')->willReturn(
            new Response(200, ['Content-Type' => 'application/json'], (string) json_encode($responseBody, JSON_THROW_ON_ERROR)),
        );
        $client = new OpenSalesTaxClient(baseUrl: 'http://mock', httpClient: $http);
        return new class ($client) extends ClientFactory {
            public function __construct(private readonly OpenSalesTaxClient $client)
            {
            }
            public function build(): ?OpenSalesTaxClient
            {
                return $this->client;
            }
        };
    }

    /**
     * Build a ClientFactory test-double whose Client fails with the given HTTP status.
     */
    private function factoryReturningHttpStatus(int $status): ClientFactory
    {
        $http = $this->createMock(Psr18Client::class);
        $http->method('sendRequest')->willReturn(
            new Response($status, ['Content-Type' => 'text/plain'], 'engine-side error'),
        );
        $client = new OpenSalesTaxClient(baseUrl: 'http://mock', httpClient: $http);
        return new class ($client) extends ClientFactory {
            public function __construct(private readonly OpenSalesTaxClient $client)
            {
            }
            public function build(): ?OpenSalesTaxClient
            {
                return $this->client;
            }
        };
    }

    private function factoryThatShouldNotBeCalled(): ClientFactory
    {
        return new class () extends ClientFactory {
            public function build(): ?OpenSalesTaxClient
            {
                throw new \RuntimeException('ClientFactory should not have been called in this test');
            }
        };
    }

    /**
     * Stub `WC()` global to return an object with a `customer` property.
     * Function only declared once per process; subsequent calls just rebind
     * the held customer pointer via $GLOBALS.
     */
    private function stubWC(object $customer): void
    {
        $GLOBALS['__ostax_test_wc'] = new class ($customer) {
            public object $customer;
            public function __construct(object $customer)
            {
                $this->customer = $customer;
            }
        };
        if (!function_exists('WC')) {
            eval('function WC() { return $GLOBALS["__ostax_test_wc"]; }');
        }
    }
}
