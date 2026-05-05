<?php

// SPDX-License-Identifier: Apache-2.0

declare(strict_types=1);

namespace OpenSalesTax\WooCommerce;

defined('ABSPATH') || exit;

/**
 * Plugin bootstrap. Wires up the tax handler + settings UI + AJAX
 * connection-tester after WooCommerce is loaded.
 *
 * Defer registration to the `plugins_loaded` action with priority 20 so
 * WooCommerce (priority 10 default) is fully initialized before we hook
 * into its filters.
 */
final class Plugin
{
    private static ?self $instance = null;

    public static function boot(string $pluginFile): void
    {
        if (self::$instance !== null) {
            return;
        }
        self::$instance = new self($pluginFile);
        self::$instance->register();
    }

    private function __construct(
        private readonly string $pluginFile,
    ) {
    }

    private function register(): void
    {
        register_activation_hook($this->pluginFile, [$this, 'onActivation']);
        register_deactivation_hook($this->pluginFile, [$this, 'onDeactivation']);

        add_action('plugins_loaded', [$this, 'wireUp'], 20);
    }

    public function wireUp(): void
    {
        // If WooCommerce isn't active, fail loud in admin and stop hooking.
        if (!class_exists('WooCommerce')) {
            add_action('admin_notices', static function (): void {
                echo '<div class="notice notice-error"><p>';
                echo '<strong>OpenSalesTax for WooCommerce:</strong> WooCommerce is not active. ';
                echo 'Activate WooCommerce 8.2+ to enable tax calculation.';
                echo '</p></div>';
            });
            return;
        }

        $clientFactory = new ClientFactory();
        $cache = new Cache();
        $taxHandler = new TaxHandler($clientFactory, $cache);
        $settings = new Settings();
        $tester = new ConnectionTester($clientFactory);

        $taxHandler->register();
        $settings->register();
        $tester->register();

        // Register WP-CLI subcommands. We register each method explicitly so
        // the dashed subcommand naming (`test-connection`, `cache-flush`,
        // `placeholder-rate`) is consistent across WP-CLI versions — older
        // versions auto-dash method names, newer ones don't.
        if (defined('WP_CLI') && \WP_CLI) {
            $cli = new Cli\Command($clientFactory);
            \WP_CLI::add_command('opensalestax test-connection', [$cli, 'test_connection']);
            \WP_CLI::add_command('opensalestax cache-flush', [$cli, 'cache_flush']);
            \WP_CLI::add_command('opensalestax calc', [$cli, 'calc']);
            \WP_CLI::add_command('opensalestax placeholder-rate', [$cli, 'placeholder_rate']);
        }
    }

    public function onActivation(): void
    {
        // Sanity check: refuse to activate on PHP < 8.2.
        if (PHP_VERSION_ID < 80200) {
            deactivate_plugins(plugin_basename($this->pluginFile));
            wp_die(
                'OpenSalesTax for WooCommerce requires PHP 8.2 or newer. '
                . 'You are running ' . PHP_VERSION . '.',
                'Plugin Activation Error',
                ['back_link' => true],
            );
        }

        // Ensure the placeholder tax-rate row exists so WooCommerce's
        // tax-calculation flow fires our filter. Idempotent.
        if (class_exists('WooCommerce')) {
            PlaceholderRate::ensure();
        }
    }

    public function onDeactivation(): void
    {
        // Flush our transient cache on deactivation so old rates don't linger.
        Cache::flushAll();
    }
}
