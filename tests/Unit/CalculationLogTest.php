<?php

// SPDX-License-Identifier: Apache-2.0

declare(strict_types=1);

namespace OpenSalesTax\WooCommerce\Tests\Unit;

use OpenSalesTax\WooCommerce\CalculationLog;
use PHPUnit\Framework\TestCase;
use WP_Mock;

final class CalculationLogTest extends TestCase
{
    protected function setUp(): void
    {
        WP_Mock::setUp();
    }

    protected function tearDown(): void
    {
        WP_Mock::tearDown();
    }

    public function testRecordIsNoOpWhenLoggingDisabled(): void
    {
        WP_Mock::userFunction('get_option', [
            'args' => [CalculationLog::ENABLED_OPTION, '0'],
            'return' => '0',
        ]);
        // No update_option mock needed — we expect it NOT to be called.

        CalculationLog::record(
            source: CalculationLog::SOURCE_ENGINE_CALL,
            zip5: '55401',
            category: 'general',
            amount: 100.00,
            taxTotal: 9.025,
        );
        $this->addToAssertionCount(1);
    }

    public function testRecordWritesEntryWhenEnabled(): void
    {
        WP_Mock::userFunction('get_option', [
            'args' => [CalculationLog::ENABLED_OPTION, '0'],
            'return' => '1',
        ]);
        WP_Mock::userFunction('get_option', [
            'args' => [CalculationLog::STORAGE_OPTION, ''],
            'return' => '',
        ]);
        $captured = null;
        WP_Mock::userFunction('update_option', [
            'times' => 1,
            'return' => function (string $name, mixed $value) use (&$captured) {
                if ($name === CalculationLog::STORAGE_OPTION) {
                    $captured = $value;
                }
                return true;
            },
        ]);

        CalculationLog::record(
            source: CalculationLog::SOURCE_ENGINE_CALL,
            zip5: '55401',
            category: 'general',
            amount: 100.00,
            taxTotal: 9.025,
            durationMs: 47,
        );

        self::assertIsString($captured);
        $decoded = json_decode($captured, true);
        self::assertIsArray($decoded);
        self::assertCount(1, $decoded);
        self::assertSame(CalculationLog::SOURCE_ENGINE_CALL, $decoded[0]['source']);
        self::assertSame('55401', $decoded[0]['zip5']);
        self::assertSame('general', $decoded[0]['category']);
        // JSON loses the float vs int distinction for whole numbers, so use loose equality.
        self::assertEquals(100.0, $decoded[0]['amount']);
        self::assertEquals(9.025, $decoded[0]['tax_total']);
        self::assertSame(47, $decoded[0]['duration_ms']);
    }

    public function testRecordPrependsAndCapsAtMaxEntries(): void
    {
        // Pre-fill option with MAX_ENTRIES dummy entries.
        $existing = [];
        for ($i = 0; $i < CalculationLog::MAX_ENTRIES; $i++) {
            $existing[] = ['source' => 'engine-call', 'zip5' => '00000', 'category' => 'general', 'amount' => 1.0, 'tax_total' => 0.1];
        }
        WP_Mock::userFunction('get_option', [
            'args' => [CalculationLog::ENABLED_OPTION, '0'],
            'return' => '1',
        ]);
        WP_Mock::userFunction('get_option', [
            'args' => [CalculationLog::STORAGE_OPTION, ''],
            'return' => json_encode($existing),
        ]);
        $captured = null;
        WP_Mock::userFunction('update_option', [
            'times' => 1,
            'return' => function (string $name, mixed $value) use (&$captured) {
                if ($name === CalculationLog::STORAGE_OPTION) {
                    $captured = $value;
                }
                return true;
            },
        ]);

        CalculationLog::record(
            source: CalculationLog::SOURCE_CACHE_HIT,
            zip5: '99999',
            category: 'clothing',
            amount: 50.0,
            taxTotal: 0.0,
        );

        self::assertIsString($captured);
        $decoded = json_decode($captured, true);
        self::assertCount(CalculationLog::MAX_ENTRIES, $decoded);
        // New entry should be at position 0 (prepended).
        self::assertSame('99999', $decoded[0]['zip5']);
        self::assertSame('clothing', $decoded[0]['category']);
        self::assertSame(CalculationLog::SOURCE_CACHE_HIT, $decoded[0]['source']);
    }

    public function testRecordCapturesErrorEntry(): void
    {
        WP_Mock::userFunction('get_option', [
            'args' => [CalculationLog::ENABLED_OPTION, '0'],
            'return' => '1',
        ]);
        WP_Mock::userFunction('get_option', [
            'args' => [CalculationLog::STORAGE_OPTION, ''],
            'return' => '',
        ]);
        $captured = null;
        WP_Mock::userFunction('update_option', [
            'times' => 1,
            'return' => function (string $name, mixed $value) use (&$captured) {
                if ($name === CalculationLog::STORAGE_OPTION) {
                    $captured = $value;
                }
                return true;
            },
        ]);

        CalculationLog::record(
            source: CalculationLog::SOURCE_ERROR,
            zip5: '55401',
            category: 'general',
            amount: 100.00,
            taxTotal: null,
            durationMs: 5000,
            error: 'engine returned HTTP 500',
        );

        $decoded = json_decode($captured, true);
        self::assertSame(CalculationLog::SOURCE_ERROR, $decoded[0]['source']);
        self::assertNull($decoded[0]['tax_total']);
        self::assertSame('engine returned HTTP 500', $decoded[0]['error']);
    }

    public function testGetRecentReturnsEmptyWhenStorageEmpty(): void
    {
        WP_Mock::userFunction('get_option', [
            'args' => [CalculationLog::STORAGE_OPTION, ''],
            'return' => '',
        ]);
        self::assertSame([], CalculationLog::getRecent());
    }

    public function testGetRecentRejectsMalformedJson(): void
    {
        WP_Mock::userFunction('get_option', [
            'args' => [CalculationLog::STORAGE_OPTION, ''],
            'return' => 'this is not json',
        ]);
        self::assertSame([], CalculationLog::getRecent());
    }

    public function testGetRecentLimitsResultCount(): void
    {
        $entries = [];
        for ($i = 0; $i < 30; $i++) {
            $entries[] = ['source' => 'engine-call', 'zip5' => sprintf('%05d', $i)];
        }
        WP_Mock::userFunction('get_option', [
            'args' => [CalculationLog::STORAGE_OPTION, ''],
            'return' => json_encode($entries),
        ]);

        $result = CalculationLog::getRecent(10);
        self::assertCount(10, $result);
        self::assertSame('00000', $result[0]['zip5']); // oldest entries first when stored chronologically
    }

    public function testIsEnabledTrueWhenOptionIsOne(): void
    {
        WP_Mock::userFunction('get_option', [
            'args' => [CalculationLog::ENABLED_OPTION, '0'],
            'return' => '1',
        ]);
        self::assertTrue(CalculationLog::isEnabled());
    }

    public function testIsEnabledFalseWhenOptionIsZero(): void
    {
        WP_Mock::userFunction('get_option', [
            'args' => [CalculationLog::ENABLED_OPTION, '0'],
            'return' => '0',
        ]);
        self::assertFalse(CalculationLog::isEnabled());
    }

    public function testIsEnabledFalseWhenOptionIsBoolean(): void
    {
        WP_Mock::userFunction('get_option', [
            'args' => [CalculationLog::ENABLED_OPTION, '0'],
            'return' => false,
        ]);
        self::assertFalse(CalculationLog::isEnabled());
    }

    public function testClear(): void
    {
        WP_Mock::userFunction('delete_option', [
            'times' => 1,
            'args' => [CalculationLog::STORAGE_OPTION],
            'return' => true,
        ]);

        CalculationLog::clear();
        $this->addToAssertionCount(1);
    }
}
