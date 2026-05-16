<?php

// SPDX-License-Identifier: Apache-2.0 OR GPL-2.0-or-later

declare(strict_types=1);

namespace OpenSalesTax\WooCommerce;

use OpenSalesTax\Exceptions\OpenSalesTaxException;

defined('ABSPATH') || exit;

/**
 * Surface a WP-admin banner when the OpenSalesTax engine is unreachable.
 *
 * Why this exists: v0.4.0 logs engine errors to the PHP error log and
 * gracefully degrades the cart (per the merchant's chosen fallback —
 * `block` or `zero`). That's the right behavior at checkout, but it means
 * a merchant who never opens WP-CLI or the dashboard widget can run for
 * days collecting wrong tax (or refusing to collect) without realizing
 * the engine has been down.
 *
 * This notice closes that gap: every admin page-load (capability-gated to
 * `manage_woocommerce`) renders a red banner if the cached health probe
 * shows the engine unreachable AND the plugin is configured.
 *
 * The probe itself is shared with `DashboardWidget` via the same
 * 60-second transient cache, so this notice never fires an extra
 * engine call.
 */
final class EngineHealthNotice
{
    /**
     * Same transient key the dashboard widget uses; we read it back to
     * decide whether to show the notice. Avoids duplicate probes.
     */
    public const HEALTH_CACHE_KEY = DashboardWidget::HEALTH_CACHE_KEY;

    /**
     * Transient marker — when set, a freshly-failed probe has been
     * recorded. We use a separate flag (rather than just trusting the
     * absence of HEALTH_CACHE_KEY) so we can distinguish "never probed"
     * from "probed and failed" in the renderer.
     */
    public const FAILURE_MARKER = 'opensalestax_engine_unreachable';

    /**
     * Probe TTL. Same as DashboardWidget's so we don't double-tax the engine.
     */
    public const PROBE_TTL = 60;

    public function __construct(
        private readonly ClientFactory $clientFactory,
    ) {
    }

    public function register(): void
    {
        add_action('admin_notices', [$this, 'render']);
    }

    public function render(): void
    {
        if (!current_user_can('manage_woocommerce')) {
            return;
        }
        if (!$this->isConfigured()) {
            return; // separate "not configured" notice handled by Settings page itself
        }

        $reachable = $this->isEngineReachable();
        if ($reachable) {
            return;
        }

        $settingsUrl = admin_url('admin.php?page=wc-settings&tab=tax&section=opensalestax');
        echo '<div class="notice notice-error"><p>';
        echo '<strong>' . esc_html__('OpenSalesTax engine is unreachable.', 'opensalestax-for-woocommerce') . '</strong> ';
        echo esc_html__('New cart calculations may be using your fallback behavior (no tax line or $0) instead of the engine. Check the engine\'s status and the configured base URL.', 'opensalestax-for-woocommerce');
        echo ' <a href="' . esc_url($settingsUrl) . '">' . esc_html__('Open settings', 'opensalestax-for-woocommerce') . '</a>';
        echo '</p></div>';
    }

    /**
     * Check the cached probe; if missing, run a fresh probe (rate-limited
     * to once per PROBE_TTL via transient).
     *
     * Returns true when reachable, false when unreachable, false also when
     * the plugin can't build a client (treated as "not reachable").
     */
    private function isEngineReachable(): bool
    {
        $cached = get_transient(self::HEALTH_CACHE_KEY);
        if (is_object($cached)) {
            // DashboardWidget cached a successful probe; clear our failure marker.
            delete_transient(self::FAILURE_MARKER);
            return true;
        }

        // Was the last probe a known failure within the TTL?
        $lastFailure = get_transient(self::FAILURE_MARKER);
        if ($lastFailure === '1') {
            return false;
        }

        // No cached state — probe ourselves. This will also populate the
        // dashboard widget's cache as a side benefit.
        return $this->probe();
    }

    private function probe(): bool
    {
        $client = $this->clientFactory->build();
        if ($client === null) {
            // Treat as unreachable; the marker prevents repeated probes
            // until the TTL expires.
            set_transient(self::FAILURE_MARKER, '1', self::PROBE_TTL);
            return false;
        }
        try {
            $health = $client->health();
        } catch (OpenSalesTaxException $e) {
            self::logWarning('admin-notice probe failed: ' . $e->getMessage());
            set_transient(self::FAILURE_MARKER, '1', self::PROBE_TTL);
            return false;
        } catch (\Throwable $e) {
            self::logWarning('admin-notice probe unexpected: ' . get_class($e) . ': ' . $e->getMessage());
            set_transient(self::FAILURE_MARKER, '1', self::PROBE_TTL);
            return false;
        }
        // Reachable — populate the shared cache + clear the failure marker.
        set_transient(self::HEALTH_CACHE_KEY, $health, self::PROBE_TTL);
        delete_transient(self::FAILURE_MARKER);
        return true;
    }

    private function isConfigured(): bool
    {
        $url = get_option('opensalestax_base_url', '');
        return is_string($url) && trim($url) !== '';
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
