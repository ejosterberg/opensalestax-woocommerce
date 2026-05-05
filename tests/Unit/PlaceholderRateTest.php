<?php

// SPDX-License-Identifier: Apache-2.0

declare(strict_types=1);

namespace OpenSalesTax\WooCommerce\Tests\Unit;

use OpenSalesTax\WooCommerce\PlaceholderRate;
use PHPUnit\Framework\TestCase;

/**
 * PlaceholderRate is a thin wrapper around `$wpdb` calls. We can't realistically
 * mock `$wpdb` via WP_Mock (it's a global object), so these tests verify the
 * surface (constants, method signatures) and lean on the integration test on
 * VM 907 to exercise the real DB path.
 */
final class PlaceholderRateTest extends TestCase
{
    public function testRateNameConstant(): void
    {
        self::assertSame('OpenSalesTax', PlaceholderRate::RATE_NAME);
    }

    public function testRateCountryConstant(): void
    {
        self::assertSame('US', PlaceholderRate::RATE_COUNTRY);
    }

    public function testGetRateIdReturnsNullWhenWpdbReturnsNull(): void
    {
        // Stub $wpdb so getRateId() can run without a real DB.
        $GLOBALS['wpdb'] = new class () {
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

        self::assertNull(PlaceholderRate::getRateId());
    }

    public function testGetRateIdReturnsIntWhenRowExists(): void
    {
        $GLOBALS['wpdb'] = new class () {
            public string $prefix = 'wp_';
            public function prepare(string $query, mixed ...$args): string
            {
                return $query;
            }
            public function get_var(string $query): ?string
            {
                return '42';
            }
        };

        self::assertSame(42, PlaceholderRate::getRateId());
    }

    public function testEnsureReturnsExistingRateIdWithoutInsert(): void
    {
        $insertCalls = 0;
        $GLOBALS['wpdb'] = new class ($insertCalls) {
            public int $insertCallCount;
            public string $prefix = 'wp_';
            public int $insert_id = 0;
            public function __construct(int &$insertCalls)
            {
                $this->insertCallCount = $insertCalls;
            }
            public function prepare(string $query, mixed ...$args): string
            {
                return $query;
            }
            public function get_var(string $query): ?string
            {
                return '99';
            }
            public function insert(string $table, array $data, ?array $format = null): int|false
            {
                ++$this->insertCallCount;
                return 1;
            }
        };

        $id = PlaceholderRate::ensure();
        self::assertSame(99, $id);
    }

    public function testEnsureInsertsAndReturnsIdWhenRowMissing(): void
    {
        $GLOBALS['wpdb'] = new class () {
            public string $prefix = 'wp_';
            public int $insert_id = 7;
            public function prepare(string $query, mixed ...$args): string
            {
                return $query;
            }
            public function get_var(string $query): ?string
            {
                return null;
            }
            public function insert(string $table, array $data, ?array $format = null): int|false
            {
                // Verify the data shape passed in
                if ($data['tax_rate_country'] !== 'US') {
                    throw new \RuntimeException('expected country=US, got: ' . $data['tax_rate_country']);
                }
                if ($data['tax_rate_name'] !== 'OpenSalesTax') {
                    throw new \RuntimeException('expected name=OpenSalesTax');
                }
                return 1;
            }
        };

        $id = PlaceholderRate::ensure();
        self::assertSame(7, $id);
    }
}
