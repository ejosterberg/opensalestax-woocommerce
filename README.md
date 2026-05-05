# OpenSalesTax for WooCommerce

> Replace TaxJar / Avalara / WooCommerce Tax with self-hosted [OpenSalesTax](https://github.com/ejosterberg/open-sales-tax). Free, open-source, US sales-tax calculation at WooCommerce checkout.

[![License](https://img.shields.io/badge/license-Apache%202.0-blue)](LICENSE) [![PHP](https://img.shields.io/badge/php-%E2%89%A58.2-777bb4)](composer.json) [![WordPress](https://img.shields.io/badge/wordpress-%E2%89%A56.2-21759b)](readme.txt) [![WooCommerce](https://img.shields.io/badge/woocommerce-%E2%89%A58.2-96588a)](readme.txt)

**Status:** v0.1 alpha. Tested against WordPress 6.6+ / WooCommerce 9+ / OpenSalesTax engine v0.24.

## What this saves you

Most WooCommerce tax plugins are paid services or limited free tiers:

| Service | Pricing model |
|---|---:|
| **WooCommerce Tax** (Jetpack) | Free up to 200 transactions/mo; paid tier above |
| **TaxJar** | from $19/mo + transaction fees |
| **Avalara AvaTax** | enterprise pricing |
| **OpenSalesTax + this plugin** | $0 software cost, self-hosted |

You run a small VM (or container) for the OpenSalesTax engine; this plugin calls into it from WC's checkout flow. Tax math runs locally on infrastructure you own.

## Install

```bash
cd /path/to/your/wordpress/wp-content/plugins/
git clone https://github.com/ejosterberg/opensalestax-woocommerce.git
cd opensalestax-woocommerce
composer install --no-dev
```

Activate via **WP Admin → Plugins**.

The plugin's `composer.json` declares the [opensalestax-php SDK](https://github.com/ejosterberg/opensalestax-php) as a dependency. Composer will pull it from the SDK's public GitHub repo during install. (The SDK will be on Packagist shortly; once it is, the install path simplifies further.)

For the full step-by-step walkthrough including engine setup, configuration, and verification, see [`docs/INSTALL.md`](docs/INSTALL.md).

## Configure

1. Stand up an OpenSalesTax engine ([5-minute Docker quickstart](https://github.com/ejosterberg/open-sales-tax))
2. In WP Admin: **WooCommerce → Settings → Tax → OpenSalesTax**
3. Enter your engine's base URL (e.g. `http://your-engine:8080`)
4. (Optional) Enter API key if your engine has authentication enabled
5. Click **Test Connection** — should report `status: ok` with the engine version

Done. The next cart that includes a US shipping address gets destination-based sales tax.

## How it works

The plugin hooks WooCommerce's `woocommerce_calc_tax` filter. On every line that needs tax computed:

1. Read the customer's shipping ZIP from `WC()->customer`
2. Resolve the line's tax category (WC tax class → OpenSalesTax category)
3. Skip if customer is tax-exempt (`WC()->customer->is_vat_exempt()`)
4. Look up cached tax for `(zip, category, line-amount)` — return early on hit
5. On cache miss, call `POST /v1/calculate` on your OpenSalesTax engine
6. Return the calculated tax amount in WC's expected format

Tax breakdown (per-state, per-county, per-city, per-district) is computed but only the combined total flows into WC's tax line. You can read the full breakdown via `wp option get opensalestax_last_breakdown` for accounting.

## Design choices

- **Replace, not populate.** This plugin replaces WC's tax calculation entirely via the filter. WC's `wp_woocommerce_tax_rates` table is ignored. Single source of truth = your OpenSalesTax engine. No DB sync drift.
- **Tax-exempt customers** are honored via `WC()->customer->is_vat_exempt()`. The exempt flag short-circuits before the engine call.
- **Caching** uses WP transients with a default 60-minute TTL. Configurable. Bulk-flushed on settings save.
- **Error handling** is graceful: if the engine is unreachable, you choose between blocking (no tax line, transaction fails until config is fixed) or zero (charge $0 tax, log via WC error log). Default = block.

## Compatibility

- **WordPress** 6.2+ (Blocks-stable)
- **WooCommerce** 8.2+ (security-supported)
- **PHP** 8.2+ (uses class-level `readonly` syntax via the OpenSalesTax SDK)
- **OpenSalesTax engine** v0.22+ (recommended — a state-bleed bug was fixed in v0.22)

Compatible with **classic checkout AND Cart/Checkout Blocks** (Blocks invoke the same `woocommerce_calc_tax` filter via the Store API).

HPOS-compatible: this plugin doesn't post-process orders in v0.1, so the HPOS tax-reading caveat doesn't apply.

## What's NOT in v0.1

- **WC Subscriptions** recurring tax recalculation (v0.2)
- **WC Tax Class** mapping beyond `standard` / `reduced-rate` / `zero-rate` (v0.2)
- **WP-CLI** commands (`wp opensalestax sync-rates`, `wp opensalestax test-connection`) (v0.2)
- **Multi-currency** carts — engine is USD-only; non-USD throws
- **Stripe Connect** / multi-vendor marketplace tax allocation
- **Per-product** custom tax-code overrides

## Disclaimer

> Tax calculations are provided as-is for convenience. The merchant is solely responsible for tax-collection accuracy and remittance to the appropriate jurisdictions. Verify against your state Department of Revenue before remitting.

## Quality bar

- **PHPStan level=max** — zero suppressed errors
- **PHP-CS-Fixer** with PSR-12 + risky rules — zero violations
- **PHPUnit** unit tests against fixtures + integration tests against a real WP+WooCom instance
- **GitHub Actions CI** matrix on PHP 8.2 / 8.3 / 8.4

## Contributing

DCO sign-off (`git commit -s`) required on every commit. See [CONTRIBUTING.md](CONTRIBUTING.md).

## License

[Apache 2.0](LICENSE).
