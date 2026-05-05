<?php

// SPDX-License-Identifier: Apache-2.0

declare(strict_types=1);

namespace OpenSalesTax\WooCommerce;

use OpenSalesTax\Exceptions\OpenSalesTaxException;

defined('ABSPATH') || exit;

/**
 * AJAX handler for the "Test connection" button on the settings page.
 *
 * Action: `opensalestax_test_connection` (admin-ajax.php)
 * Nonce:  `opensalestax_test_connection`
 *
 * Calls the engine's `/v1/health` and returns a JSON result the settings
 * page renders inline.
 */
final class ConnectionTester
{
    public const AJAX_ACTION = 'opensalestax_test_connection';

    public function __construct(
        private readonly ClientFactory $clientFactory,
    ) {
    }

    public function register(): void
    {
        add_action('wp_ajax_' . self::AJAX_ACTION, [$this, 'handle']);
    }

    public function handle(): void
    {
        // Capability + nonce checks.
        if (!current_user_can('manage_woocommerce')) {
            wp_send_json(['ok' => false, 'error' => 'You do not have permission to test the connection.'], 403);
        }
        $rawNonce = isset($_POST['_nonce']) && is_string($_POST['_nonce']) ? wp_unslash($_POST['_nonce']) : '';
        $nonce = is_string($rawNonce) ? sanitize_text_field($rawNonce) : '';
        if (!wp_verify_nonce($nonce, self::AJAX_ACTION)) {
            wp_send_json(['ok' => false, 'error' => 'Invalid nonce. Reload the settings page and try again.'], 403);
        }

        $client = $this->clientFactory->build();
        if ($client === null) {
            wp_send_json(['ok' => false, 'error' => 'Engine base URL is not set.']);
        }

        try {
            $health = $client->health();
        } catch (OpenSalesTaxException $e) {
            wp_send_json(['ok' => false, 'error' => $e->getMessage()]);
        } catch (\Throwable $e) {
            wp_send_json(['ok' => false, 'error' => get_class($e) . ': ' . $e->getMessage()]);
        }

        $msg = sprintf(
            'Engine v%s is %s — DB %s',
            $health->version,
            $health->status,
            $health->databaseConnected ? 'up' : 'down',
        );
        wp_send_json(['ok' => true, 'message' => $msg]);
    }
}
