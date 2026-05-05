<?php

/**
 * Plugin Name:       OpenSalesTax for WooCommerce
 * Plugin URI:        https://github.com/ejosterberg/opensalestax-woocommerce
 * Description:       Calculate US sales tax via a self-hosted OpenSalesTax engine. Replaces TaxJar / Avalara / WooCommerce Tax integrations with a free, open-source alternative.
 * Version:           0.3.1
 * Requires at least: 6.2
 * Requires PHP:      8.2
 * Author:            Eric Osterberg
 * Author URI:        https://github.com/ejosterberg
 * License:           Apache License 2.0
 * License URI:       https://www.apache.org/licenses/LICENSE-2.0
 * Text Domain:       opensalestax-woocommerce
 * WC requires at least: 8.2
 * WC tested up to:   10.5
 *
 * SPDX-License-Identifier: Apache-2.0
 */

declare(strict_types=1);

defined('ABSPATH') || exit;

// Composer autoload — bundled inside the plugin during release packaging.
$autoload = __DIR__ . '/vendor/autoload.php';
if (!file_exists($autoload)) {
    add_action('admin_notices', static function (): void {
        echo '<div class="notice notice-error"><p>';
        echo '<strong>OpenSalesTax for WooCommerce:</strong> Composer dependencies are not installed. ';
        echo 'Run <code>composer install --no-dev</code> in the plugin directory or download a release ZIP that bundles dependencies.';
        echo '</p></div>';
    });
    return;
}
require_once $autoload;

\OpenSalesTax\WooCommerce\Plugin::boot(__FILE__);
