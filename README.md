# OpenSalesTax for WooCommerce

> Replace TaxJar / Avalara / WooCommerce Tax with self-hosted [OpenSalesTax](https://github.com/ejosterberg/open-sales-tax). Free, open-source, US sales-tax calculation at WooCommerce checkout.

[![License](https://img.shields.io/badge/license-Apache%202.0-blue)](LICENSE) [![PHP](https://img.shields.io/badge/php-%E2%89%A58.2-777bb4)](composer.json) [![WordPress](https://img.shields.io/badge/wordpress-%E2%89%A56.2-21759b)](readme.txt) [![WooCommerce](https://img.shields.io/badge/woocommerce-%E2%89%A58.2-96588a)](readme.txt)

**Status:** v0.3.2. Tested against WordPress 6.6+ / WooCommerce 10.7 / OpenSalesTax engine v0.39. 85 unit tests + an end-to-end integration test on a real WP+WooCom Proxmox VM.

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

Or via Composer:

```bash
composer require ejosterberg/opensalestax-woocommerce
```

The SDK ([`ejosterberg/opensalestax`](https://packagist.org/packages/ejosterberg/opensalestax)) and the plugin ([`ejosterberg/opensalestax-woocommerce`](https://packagist.org/packages/ejosterberg/opensalestax-woocommerce)) are both on Packagist.

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

Tax breakdown (per-state, per-county, per-city, per-district) is computed and returned by the engine. The combined total flows into WC's tax line; per-jurisdiction detail can be inspected via `wp opensalestax calc <zip> <amount>`.

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

## What's shipping

- ✅ **Recent-calculations debug log** (v0.3.2) — opt-in 50-entry ring buffer captures every cart calculation (cache-hit / engine-call / error) with timing, ZIP, category, amount, tax. Viewable via `wp opensalestax recent-calcs` or a panel on the settings page. Useful when troubleshooting "why is this tax wrong?"
- ✅ **Status dashboard widget** (v0.3.1) — WP-admin home page shows engine reachability, version, placeholder-rate state, and today's order count with breakdown captured. 60-second transient cache keeps the engine from getting hammered.
- ✅ **Per-order jurisdiction breakdown** (v0.3) — every order stores the engine's full state/county/city/district split as meta and renders a clean table on the WC admin order-edit page. Useful for audit reconciliation and answering "where did my tax money go?"
- ✅ **WC Tax Class custom mapping** (v0.2) — map `clothing`, `groceries`, or any custom WC class to the right OST category (or mark non-taxable). Built-in defaults still apply for `standard`/`reduced-rate`/`zero-rate`. Configure via `wp opensalestax tax-class-list / tax-class-set / tax-class-reset`.
- ✅ **WP-CLI** commands — `test-connection`, `cache-flush`, `calc <zip> <amount>`, `placeholder-rate`, `tax-class-list`, `tax-class-set`, `tax-class-reset`
- ✅ **SSRF mitigation** (v0.1.2) — engine base URL validated against private/loopback/CGNAT ranges (opt-in for LAN deployments)
- ✅ **Tax-line aggregation fix** (v0.1.1) — `WC_Cart::get_tax_totals()` correctly labels the line as "OpenSalesTax" via the placeholder rate row

## What's coming next
- **Admin-UI tax-class mapper** to replace CLI-only configuration (v0.3.3)
- **WC Subscriptions** recurring tax recalculation on renewal invoices (v0.4)
- **WP.org plugin directory** submission (planned after launch traction)

## Out of scope

- **Multi-currency** carts — engine is USD-only; non-USD throws
- **Stripe Connect** / multi-vendor marketplace tax allocation
- **Per-product** custom tax-code overrides
- **Tax filing / remittance** — calculation only, by design (engine constitution §13)

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
