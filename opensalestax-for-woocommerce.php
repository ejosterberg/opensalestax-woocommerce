<?php

/**
 * Plugin Name:       OpenSalesTax for WooCommerce
 * Plugin URI:        https://github.com/ejosterberg/opensalestax-for-woocommerce
 * Description:       Calculate US sales tax via a self-hosted OpenSalesTax engine. Replaces TaxJar / Avalara / WooCommerce Tax integrations with a free, open-source alternative.
 * Version:           0.6.0
 * Requires at least: 6.2
 * Requires PHP:      8.2
 * Author:            Eric Osterberg
 * Author URI:        https://github.com/ejosterberg/opensalestax-for-woocommerce
 * License:           GPLv2 or later
 * License URI:       https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain:       opensalestax-for-woocommerce
 * WC requires at least: 8.2
 * WC tested up to:   10.7
 *
 * SPDX-License-Identifier: Apache-2.0 OR GPL-2.0-or-later
 */

declare(strict_types=1);

defined('ABSPATH') || exit;

// Composer autoload — bundled inside the plugin during release packaging.
// Wrapped in an IIFE so we don't pollute the global scope with an unprefixed
// `$autoload` variable (WP-org Plugin Check flags non-prefixed globals).
(static function (): void {
    $opensalestax_autoload = __DIR__ . '/vendor/autoload.php';
    if (!file_exists($opensalestax_autoload)) {
        add_action('admin_notices', static function (): void {
            echo '<div class="notice notice-error"><p>';
            echo '<strong>OpenSalesTax for WooCommerce:</strong> Composer dependencies are not installed. ';
            echo 'Run <code>composer install --no-dev</code> in the plugin directory or download a release ZIP that bundles dependencies.';
            echo '</p></div>';
        });
        return;
    }
    require_once $opensalestax_autoload;

    \OpenSalesTax\WooCommerce\Plugin::boot(__FILE__);
})();
