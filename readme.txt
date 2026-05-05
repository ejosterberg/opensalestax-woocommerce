=== OpenSalesTax for WooCommerce ===
Contributors: ejosterberg
Tags: tax, sales-tax, woocommerce, taxjar, avalara, stripe-tax
Requires at least: 6.2
Tested up to: 6.9
Requires PHP: 8.2
WC requires at least: 8.2
WC tested up to: 10.5
Stable tag: 0.3.1
License: Apache License 2.0
License URI: https://www.apache.org/licenses/LICENSE-2.0

Replace TaxJar / Avalara / WooCommerce Tax with self-hosted OpenSalesTax. Free, open-source US sales-tax calculation at WooCommerce checkout.

== Description ==

**OpenSalesTax for WooCommerce** plugs WooCommerce's tax calculation into your own [OpenSalesTax](https://github.com/ejosterberg/open-sales-tax) engine — a free, self-hostable US sales-tax API. No transaction fees, no per-month limits, no enterprise pricing tiers.

Run a small server for the OpenSalesTax engine; this plugin calls into it during WooCommerce checkout. Destination-based sales tax for any US shipping ZIP, computed on infrastructure you control.

= Why =

* **WooCommerce Tax** (Jetpack) is free up to 200 transactions/mo, then paid.
* **TaxJar** starts at $19/mo plus per-transaction fees.
* **Avalara AvaTax** is enterprise pricing.
* **OpenSalesTax** is $0 software cost — you own the deployment.

For shops above ~200 transactions/month or above ~$50K/yr in revenue, the math flips fast.

= How it works =

The plugin hooks WooCommerce's `woocommerce_calc_tax` filter. On every line that needs tax:

1. Reads the customer's shipping ZIP from WooCommerce
2. Calls your OpenSalesTax engine for the right tax breakdown
3. Returns the calculated tax to WooCommerce

Tax-exempt customers honored. Caching included (default 60-min TTL). Compatible with classic checkout AND Cart/Checkout Blocks.

= What this plugin is NOT =

* Not a tax-filing service — calculation only. The merchant remits.
* Not the engine itself — see [OpenSalesTax](https://github.com/ejosterberg/open-sales-tax) for the calculator.
* Not a closed/proprietary integration — fully open-source, Apache 2.0 licensed.

== Installation ==

1. Install + activate the plugin (via WP Admin → Plugins → Add New, or via Composer)
2. Stand up an OpenSalesTax engine — see [the engine's docker-compose quickstart](https://github.com/ejosterberg/open-sales-tax)
3. In WP Admin: **WooCommerce → Settings → Tax → OpenSalesTax**
4. Enter your engine's base URL (e.g. `http://your-engine:8080`)
5. Click **Test Connection** to verify
6. Done — the next US-shipped cart gets correct destination-based sales tax

== Frequently Asked Questions ==

= Does this work with the new Cart/Checkout Blocks (React-based checkout)? =

Yes. Blocks call into the same WooCommerce server-side calculation via the Store API, so our `woocommerce_calc_tax` filter fires for both classic and Blocks checkouts.

= What happens if my OpenSalesTax engine is unreachable? =

Configurable. Default is "block" — the cart shows no tax line until the engine is reachable again. Alternatively, choose "zero" — charge $0 tax and log the failure via the WooCommerce error log. Both are explicit; neither silently mis-charges.

= Does it support tax-exempt customers? =

Yes. The plugin honors WooCommerce's `WC()->customer->is_vat_exempt()` flag — exempt customers short-circuit before any engine call.

= Does it support multi-currency carts? =

Not in v0.1. The OpenSalesTax engine is USD-only; non-USD orders throw an exception. v0.2+ may add currency-conversion support if the engine adds it.

= Will this conflict with my other WooCommerce plugins? =

The plugin is filter-only — it doesn't write to `wp_woocommerce_tax_rates`. Lowest possible plugin-conflict surface. Other plugins that read tax rates from the database see what WooCommerce stored there before activation.

== Disclaimer ==

Tax calculations are provided as-is for convenience. The merchant is solely responsible for tax-collection accuracy and remittance to the appropriate jurisdictions. Verify against your state Department of Revenue before remitting.

== Changelog ==

= 0.3.1 — 2026-05-05 =

* Status dashboard widget. WP-admin home now shows a compact OpenSalesTax health panel: connection status (with engine version + DB connectivity), placeholder-rate row state, and today's order count with breakdown captured. Health probe results cached 60s in a transient so the dashboard never hammers the engine. Visibility gated on `manage_woocommerce`.
* HPOS-aware order counting (queries `wp_wc_orders_meta` with legacy CPT fallback).
* 5 new unit tests in `DashboardWidgetTest`; 74 unit tests total.

= 0.3.0 — 2026-05-05 =

* Per-order jurisdiction breakdown view. Every order created by checkout now stores the engine's full per-jurisdiction tax breakdown (state / county / city / district splits with rate % and tax $) on the order as meta `_opensalestax_breakdown`. The WC admin order-edit page renders this as a clean table — useful for audit reconciliation and showing customers exactly where their tax dollars went.
* Failures during breakdown capture are non-fatal — checkout proceeds even if the engine call fails (the headline tax was already computed via the calc filter).
* 12 new unit tests in `OrderTaxBreakdownTest`; 69 unit tests total, all passing.

= 0.2.0 — 2026-05-05 =

* WC tax-class → OST category custom mapping. Merchants with `clothing`, `groceries`, or other custom WC tax classes can now map them to the right OST category (or mark non-taxable). v0.1.x hard-coded everything to `general`, which mis-categorized clothing/groceries shops in states with category-specific exemptions (e.g., MN clothing exemption).
* WP-CLI: `wp opensalestax tax-class-list`, `tax-class-set <wc-class> <ost-category>`, `tax-class-reset`.
* 16 new unit tests; 57 unit tests total, all passing.

= 0.1.2 — 2026-05-05 =

* SSRF mitigation: engine base URL is now validated against private/loopback/link-local/CGNAT IP ranges. Opt-in for LAN deployments via the `opensalestax_allow_private_nets` option or `OPENSALESTAX_ALLOW_PRIVATE_NETS` constant in `wp-config.php`.

= 0.1.1 — 2026-05-04 =

* PlaceholderRate: registers a row in `wp_woocommerce_tax_rates` named "OpenSalesTax" so `WC_Cart::get_tax_totals()` labels the line correctly in the cart and order summary. (Fixes the v0.1.0 cosmetic gap where the tax line showed `Tax lines (0)`.)
* WP-CLI: `wp opensalestax test-connection`, `cache-flush`, `calc <zip> <amount>`, `placeholder-rate`.
* Direct-access guards on every src class.

= 0.1.0 — 2026-05-04 =

* Initial alpha release
* `woocommerce_calc_tax` filter integration (replace strategy)
* Settings page under WooCommerce → Settings → Tax → OpenSalesTax
* Test-connection AJAX button
* Tax-exempt customer support via `is_vat_exempt()`
* WP-transient caching (configurable TTL)
* Configurable error fallback (block / zero)

== Source code ==

The full source code, including unit tests and integration tests against a real WordPress + WooCommerce instance, lives at https://github.com/ejosterberg/opensalestax-woocommerce.
