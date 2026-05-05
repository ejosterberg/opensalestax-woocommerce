<?php

// SPDX-License-Identifier: Apache-2.0

declare(strict_types=1);

namespace OpenSalesTax\WooCommerce;

use OpenSalesTax\Exceptions\OpenSalesTaxException;
use OpenSalesTax\Responses\HealthResponse;

defined('ABSPATH') || exit;

/**
 * WP-admin dashboard widget showing OpenSalesTax health at-a-glance.
 *
 * Renders below "At a Glance" on the wp-admin home so merchants get an
 * immediate signal if the engine is unreachable, the plugin isn't fully
 * configured, or no orders have flowed through the breakdown capture.
 *
 * Visibility is gated on `manage_woocommerce` — same capability that gates
 * the settings page, since this widget exposes the engine version and base
 * URL configuration state.
 *
 * The health probe response is cached for 60 seconds in a transient so
 * the dashboard doesn't hammer the engine on every admin page-load.
 */
final class DashboardWidget
{
    public const WIDGET_ID = 'opensalestax_health';
    public const WIDGET_TITLE = 'OpenSalesTax';
    public const HEALTH_CACHE_KEY = 'opensalestax_dashboard_health';
    public const HEALTH_CACHE_TTL = 60; // seconds

    public function __construct(
        private readonly ClientFactory $clientFactory,
    ) {
    }

    public function register(): void
    {
        add_action('wp_dashboard_setup', [$this, 'addWidget']);
    }

    public function addWidget(): void
    {
        if (!current_user_can('manage_woocommerce')) {
            return;
        }
        wp_add_dashboard_widget(
            self::WIDGET_ID,
            self::WIDGET_TITLE,
            [$this, 'render'],
        );
    }

    public function render(): void
    {
        echo $this->renderHtml();
    }

    /**
     * Render the widget's HTML. Pure (modulo cached health probe) so it's
     * easy to test against a stubbed ClientFactory.
     */
    public function renderHtml(): string
    {
        $configured = $this->isConfigured();
        $health = $configured ? $this->probeHealth() : null;
        $todayCount = $this->countOrdersWithBreakdownToday();
        $placeholderRateId = PlaceholderRate::getRateId();

        $rows = [];

        // Connection status.
        if (!$configured) {
            $rows[] = [
                'label' => esc_html__('Connection', 'opensalestax-woocommerce'),
                'value' => '<span style="color:#b32d2e;">'
                    . esc_html__('Not configured', 'opensalestax-woocommerce') . '</span>',
                'detail' => sprintf(
                    /* translators: %s = settings link */
                    esc_html__('Set the engine base URL under %s.', 'opensalestax-woocommerce'),
                    '<a href="' . esc_url(self::settingsUrl()) . '">'
                    . esc_html__('WooCommerce → Settings → Tax → OpenSalesTax', 'opensalestax-woocommerce')
                    . '</a>',
                ),
            ];
        } elseif ($health === null) {
            $rows[] = [
                'label' => esc_html__('Connection', 'opensalestax-woocommerce'),
                'value' => '<span style="color:#b32d2e;">'
                    . esc_html__('Unreachable', 'opensalestax-woocommerce') . '</span>',
                'detail' => esc_html__('Could not reach engine. See WP error log.', 'opensalestax-woocommerce'),
            ];
        } else {
            $statusColor = $health->status === 'ok' ? '#00854a' : '#b32d2e';
            $rows[] = [
                'label' => esc_html__('Connection', 'opensalestax-woocommerce'),
                'value' => '<span style="color:' . esc_attr($statusColor) . ';">'
                    . esc_html(strtoupper($health->status)) . '</span>',
                'detail' => sprintf(
                    /* translators: 1: engine version, 2: db state */
                    esc_html__('Engine v%1$s — database %2$s', 'opensalestax-woocommerce'),
                    esc_html($health->version),
                    $health->databaseConnected
                        ? '<span style="color:#00854a;">' . esc_html__('connected', 'opensalestax-woocommerce') . '</span>'
                        : '<span style="color:#b32d2e;">' . esc_html__('disconnected', 'opensalestax-woocommerce') . '</span>',
                ),
            ];
        }

        // Placeholder rate state (affects whether tax line gets labeled "OpenSalesTax").
        if ($placeholderRateId === null) {
            $rows[] = [
                'label' => esc_html__('Placeholder rate', 'opensalestax-woocommerce'),
                'value' => '<span style="color:#dba617;">'
                    . esc_html__('Missing', 'opensalestax-woocommerce') . '</span>',
                'detail' => esc_html__('Re-activate the plugin to register the placeholder row.', 'opensalestax-woocommerce'),
            ];
        } else {
            $rows[] = [
                'label' => esc_html__('Placeholder rate', 'opensalestax-woocommerce'),
                'value' => '<span style="color:#00854a;">'
                    . esc_html__('OK', 'opensalestax-woocommerce') . '</span>',
                'detail' => sprintf(
                    /* translators: %d = wp_woocommerce_tax_rates row id */
                    esc_html__('tax_rate_id = %d', 'opensalestax-woocommerce'),
                    $placeholderRateId,
                ),
            ];
        }

        // Today's order count with breakdown captured.
        $rows[] = [
            'label' => esc_html__('Orders today', 'opensalestax-woocommerce'),
            'value' => esc_html((string) $todayCount),
            'detail' => esc_html__('Orders with engine breakdown captured today.', 'opensalestax-woocommerce'),
        ];

        // Render.
        $out = '<table class="widefat" style="margin-bottom:0;">';
        foreach ($rows as $row) {
            $out .= '<tr>';
            $out .= '<th style="text-align:left;width:35%;padding:6px 0;">' . $row['label'] . '</th>';
            $out .= '<td style="padding:6px 0;"><strong>' . $row['value'] . '</strong>';
            $out .= '<br><small style="color:#646970;">' . $row['detail'] . '</small></td>';
            $out .= '</tr>';
        }
        $out .= '</table>';
        $out .= '<p style="margin:.75em 0 0;">';
        $out .= '<a href="' . esc_url(self::settingsUrl()) . '" class="button button-secondary">'
              . esc_html__('Configure', 'opensalestax-woocommerce') . '</a> ';
        if ($configured) {
            $out .= '<a href="' . esc_url(admin_url('admin.php?page=wc-orders')) . '" class="button button-secondary">'
                  . esc_html__('View orders', 'opensalestax-woocommerce') . '</a>';
        }
        $out .= '</p>';

        return $out;
    }

    private function isConfigured(): bool
    {
        $url = get_option('opensalestax_base_url', '');
        return is_string($url) && trim($url) !== '';
    }

    /**
     * Probe the engine's /v1/health endpoint, with a short transient cache.
     *
     * Returns null on any error so the renderer can show "Unreachable" without
     * second-guessing why.
     */
    private function probeHealth(): ?HealthResponse
    {
        $cached = get_transient(self::HEALTH_CACHE_KEY);
        if ($cached instanceof HealthResponse) {
            return $cached;
        }

        $client = $this->clientFactory->build();
        if ($client === null) {
            return null;
        }

        try {
            $health = $client->health();
        } catch (OpenSalesTaxException $e) {
            error_log('[opensalestax-woocommerce] dashboard health probe failed: ' . $e->getMessage());
            return null;
        } catch (\Throwable $e) {
            error_log('[opensalestax-woocommerce] dashboard health probe unexpected: ' . get_class($e) . ': ' . $e->getMessage());
            return null;
        }

        set_transient(self::HEALTH_CACHE_KEY, $health, self::HEALTH_CACHE_TTL);
        return $health;
    }

    /**
     * Count today's orders with breakdown meta. Cheap query; returns 0 on
     * any failure (the widget should never be the thing that breaks an
     * admin page).
     */
    private function countOrdersWithBreakdownToday(): int
    {
        /** @var \wpdb|null $wpdb */
        global $wpdb;
        if (!isset($wpdb) || !is_object($wpdb)) {
            return 0;
        }

        // Date threshold — today midnight in the site's timezone, expressed
        // in UTC since WC stores order dates in UTC.
        $midnight = strtotime('today midnight');
        $todayStart = gmdate('Y-m-d H:i:s', $midnight !== false ? $midnight : time());

        // Try HPOS first (wp_wc_orders + wp_wc_orders_meta), fall back to legacy CPT.
        $hposTable = $wpdb->prefix . 'wc_orders';
        $hposMeta = $wpdb->prefix . 'wc_orders_meta';
        $exists = $wpdb->get_var($wpdb->prepare(
            'SELECT COUNT(*) FROM information_schema.tables WHERE table_schema = DATABASE() AND table_name = %s',
            $hposTable,
        ));
        if ((int) $exists === 1) {
            $sql = $wpdb->prepare(
                "SELECT COUNT(DISTINCT o.id) FROM {$hposTable} o
                 INNER JOIN {$hposMeta} m ON m.order_id = o.id
                 WHERE m.meta_key = %s AND o.date_created_gmt >= %s",
                OrderTaxBreakdown::META_KEY,
                $todayStart,
            );
            return (int) $wpdb->get_var($sql);
        }

        // Legacy CPT fallback.
        $postsTable = $wpdb->prefix . 'posts';
        $metaTable = $wpdb->prefix . 'postmeta';
        $sql = $wpdb->prepare(
            "SELECT COUNT(DISTINCT p.ID) FROM {$postsTable} p
             INNER JOIN {$metaTable} m ON m.post_id = p.ID
             WHERE p.post_type = 'shop_order'
               AND m.meta_key = %s
               AND p.post_date_gmt >= %s",
            OrderTaxBreakdown::META_KEY,
            $todayStart,
        );
        return (int) $wpdb->get_var($sql);
    }

    private static function settingsUrl(): string
    {
        return admin_url('admin.php?page=wc-settings&tab=tax&section=opensalestax');
    }
}
