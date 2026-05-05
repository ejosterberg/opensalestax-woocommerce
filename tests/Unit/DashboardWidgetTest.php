<?php

// SPDX-License-Identifier: Apache-2.0

declare(strict_types=1);

namespace OpenSalesTax\WooCommerce\Tests\Unit;

use GuzzleHttp\Psr7\Response;
use OpenSalesTax\Client as OpenSalesTaxClient;
use OpenSalesTax\WooCommerce\ClientFactory;
use OpenSalesTax\WooCommerce\DashboardWidget;
use OpenSalesTax\WooCommerce\PlaceholderRate;
use PHPUnit\Framework\TestCase;
use Psr\Http\Client\ClientInterface as Psr18Client;
use WP_Mock;

final class DashboardWidgetTest extends TestCase
{
    protected function setUp(): void
    {
        WP_Mock::setUp();
    }

    protected function tearDown(): void
    {
        WP_Mock::tearDown();
    }

    public function testRenderHtmlShowsNotConfiguredWhenBaseUrlEmpty(): void
    {
        WP_Mock::userFunction('get_option', [
            'args' => ['opensalestax_base_url', ''],
            'return' => '',
        ]);
        // PlaceholderRate::getRateId reads from $wpdb — no order count query
        // happens because the widget still queries DB, so we mock $wpdb minimally.
        $this->stubWpdb(rateRowExists: true, rateId: 7, hposExists: false, todayCount: 0);
        WP_Mock::userFunction('admin_url', ['return_arg' => 0]);
        WP_Mock::userFunction('esc_url', ['return_arg' => 0]);

        $widget = new DashboardWidget($this->factoryThatShouldNotBeCalled());
        $html = $widget->renderHtml();

        self::assertStringContainsString('Not configured', $html);
        self::assertStringContainsString('Configure', $html);
        self::assertStringNotContainsString('View orders', $html);
    }

    public function testRenderHtmlShowsHealthyWhenEngineReachable(): void
    {
        WP_Mock::userFunction('get_option', [
            'args' => ['opensalestax_base_url', ''],
            'return' => 'http://10.32.161.126:8080',
        ]);
        WP_Mock::userFunction('get_option', [
            'args' => ['opensalestax_api_key', ''],
            'return' => '',
        ]);
        WP_Mock::userFunction('get_option', [
            'args' => ['opensalestax_timeout_seconds', 5.0],
            'return' => 5.0,
        ]);
        WP_Mock::userFunction('get_option', [
            'args' => ['opensalestax_allow_private_nets', '0'],
            'return' => '1',
        ]);
        WP_Mock::userFunction('get_transient', [
            'args' => [DashboardWidget::HEALTH_CACHE_KEY],
            'return' => false,
        ]);
        WP_Mock::userFunction('set_transient', [
            'times' => 1,
            'return' => true,
        ]);
        $this->stubWpdb(rateRowExists: true, rateId: 7, hposExists: true, todayCount: 3);
        WP_Mock::userFunction('admin_url', ['return_arg' => 0]);
        WP_Mock::userFunction('esc_url', ['return_arg' => 0]);

        $factory = $this->factoryReturning([
            'status' => 'ok',
            'version' => '0.36.0',
            'database_connected' => true,
        ]);
        $widget = new DashboardWidget($factory);
        $html = $widget->renderHtml();

        self::assertStringContainsString('OK', $html);
        self::assertStringContainsString('0.36.0', $html);
        self::assertStringContainsString('connected', $html);
        self::assertStringContainsString('3', $html); // todayCount
        self::assertStringContainsString('View orders', $html);
    }

    public function testRenderHtmlShowsUnreachableWhenEngineErrors(): void
    {
        WP_Mock::userFunction('get_option', [
            'args' => ['opensalestax_base_url', ''],
            'return' => 'http://10.32.161.126:8080',
        ]);
        WP_Mock::userFunction('get_option', [
            'args' => ['opensalestax_api_key', ''],
            'return' => '',
        ]);
        WP_Mock::userFunction('get_option', [
            'args' => ['opensalestax_timeout_seconds', 5.0],
            'return' => 5.0,
        ]);
        WP_Mock::userFunction('get_option', [
            'args' => ['opensalestax_allow_private_nets', '0'],
            'return' => '1',
        ]);
        WP_Mock::userFunction('get_transient', [
            'args' => [DashboardWidget::HEALTH_CACHE_KEY],
            'return' => false,
        ]);
        $this->stubWpdb(rateRowExists: true, rateId: 7, hposExists: false, todayCount: 0);
        WP_Mock::userFunction('admin_url', ['return_arg' => 0]);
        WP_Mock::userFunction('esc_url', ['return_arg' => 0]);

        $factory = $this->factoryReturningHttpStatus(500);
        $widget = new DashboardWidget($factory);
        $html = $widget->renderHtml();

        self::assertStringContainsString('Unreachable', $html);
    }

    public function testRenderHtmlFlagsMissingPlaceholderRate(): void
    {
        WP_Mock::userFunction('get_option', [
            'args' => ['opensalestax_base_url', ''],
            'return' => '',
        ]);
        $this->stubWpdb(rateRowExists: false, rateId: null, hposExists: false, todayCount: 0);
        WP_Mock::userFunction('admin_url', ['return_arg' => 0]);
        WP_Mock::userFunction('esc_url', ['return_arg' => 0]);

        $widget = new DashboardWidget($this->factoryThatShouldNotBeCalled());
        $html = $widget->renderHtml();

        self::assertStringContainsString('Missing', $html);
        self::assertStringContainsString('Re-activate the plugin', $html);
    }

    public function testRenderHtmlUsesCachedHealthIfPresent(): void
    {
        $cachedHealth = new \OpenSalesTax\Responses\HealthResponse(
            status: 'ok',
            version: '0.35.0',
            databaseConnected: true,
        );

        WP_Mock::userFunction('get_option', [
            'args' => ['opensalestax_base_url', ''],
            'return' => 'http://10.32.161.126:8080',
        ]);
        WP_Mock::userFunction('get_transient', [
            'args' => [DashboardWidget::HEALTH_CACHE_KEY],
            'return' => $cachedHealth,
        ]);
        $this->stubWpdb(rateRowExists: true, rateId: 7, hposExists: false, todayCount: 1);
        WP_Mock::userFunction('admin_url', ['return_arg' => 0]);
        WP_Mock::userFunction('esc_url', ['return_arg' => 0]);

        // Factory should NOT be called when cached health is available.
        $widget = new DashboardWidget($this->factoryThatShouldNotBeCalled());
        $html = $widget->renderHtml();

        self::assertStringContainsString('0.35.0', $html);
    }

    /**
     * Install a stub $wpdb in the global namespace.
     */
    private function stubWpdb(bool $rateRowExists, ?int $rateId, bool $hposExists, int $todayCount): void
    {
        global $wpdb;
        $wpdb = new class ($rateRowExists, $rateId, $hposExists, $todayCount) {
            public string $prefix = 'wp_';
            public function __construct(
                private readonly bool $rateRowExists,
                private readonly ?int $rateId,
                private readonly bool $hposExists,
                private readonly int $todayCount,
            ) {
            }
            public function prepare(string $query, mixed ...$args): string
            {
                return $query . '|' . implode('|', array_map(fn ($a) => (string) $a, $args));
            }
            public function get_var(string $query): ?string
            {
                if (str_contains($query, 'information_schema.tables')) {
                    return $this->hposExists ? '1' : '0';
                }
                if (str_contains($query, 'wc_orders_meta') || str_contains($query, 'postmeta')) {
                    return (string) $this->todayCount;
                }
                if (str_contains($query, 'tax_rate_name')) {
                    return $this->rateRowExists && $this->rateId !== null ? (string) $this->rateId : null;
                }
                return null;
            }
        };
    }

    /**
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

    private function factoryReturningHttpStatus(int $status): ClientFactory
    {
        $http = $this->createMock(Psr18Client::class);
        $http->method('sendRequest')->willReturn(
            new Response($status, ['Content-Type' => 'text/plain'], 'engine error'),
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
                throw new \RuntimeException('Factory should not have been called for this test');
            }
        };
    }
}
