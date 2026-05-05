# Changelog

All notable changes documented here.
Format: [Keep a Changelog](https://keepachangelog.com/en/1.1.0/).
Versioning: [SemVer](https://semver.org).

## [Unreleased]

## [0.3.1] — 2026-05-05

### Added
- **Status dashboard widget** (`src/DashboardWidget.php`). The WP-admin home page now shows a compact OpenSalesTax health panel below "At a Glance":
  - **Connection** — engine reachable / unreachable / not configured, with engine version + DB connectivity state.
  - **Placeholder rate** — whether the `wp_woocommerce_tax_rates` row exists (so `WC_Cart::get_tax_totals()` can label the line correctly). Flags MISSING and prompts to re-activate the plugin if the row is gone.
  - **Orders today** — count of orders created today that have engine-captured breakdown meta. HPOS-aware (queries `wp_wc_orders_meta` with `wp_postmeta` fallback for legacy CPT installs).
  - Quick-action buttons: Configure → settings page; View orders → `wp-admin/admin.php?page=wc-orders`.
- Health probe is cached for 60 seconds in a transient (`opensalestax_dashboard_health`) so the widget doesn't hammer the engine on every admin page-load.
- Visibility gated on `manage_woocommerce` capability — same as the settings page.
- 5 new unit tests in `DashboardWidgetTest`: not-configured state, healthy state, unreachable state, missing placeholder state, cached-health state.

### Changed
- `Plugin::wireUp()` registers the new `DashboardWidget` handler.

### Verified end-to-end
On VM 907 against engine v0.36, the live `renderHtml()` produces a 1070-byte widget showing OK status, engine version, configured base URL, and the per-day order count. Renders correctly under the `manage_woocommerce` capability check.

## [0.3.0] — 2026-05-05

### Added
- **Per-order jurisdiction breakdown view** (`src/OrderTaxBreakdown.php`). On `woocommerce_checkout_create_order`, the plugin re-runs the engine calculation against the order's destination + line items and stores the full per-jurisdiction breakdown (state / county / city / district splits, each with its own rate % and tax $) as JSON in the order meta `_opensalestax_breakdown`. The WC admin order-edit page renders this as a table beneath the order details — useful for audit reconciliation and showing customers exactly where the tax went.
- New hook: `woocommerce_admin_order_data_after_order_details` renders the breakdown panel.
- Public static accessor `OrderTaxBreakdown::get(WC_Order $order): ?array` for accounting integrations that want to consume the structured breakdown.
- 12 new unit tests in `OrderTaxBreakdownTest`: capture path, ZIP fallback, zero-rate skip, malformed-meta safety, render-with-jurisdictions, render-with-note, XSS-defense (real `htmlspecialchars` via WP_Mock override), engine-error tolerance.

### Changed
- `Plugin::wireUp()` instantiates and registers the new `OrderTaxBreakdown` handler.
- Test bootstrap dropped redundant `esc_html` / `esc_html__` stubs (WP_Mock owns those); the XSS-defense test installs a real escaper via `WP_Mock::userFunction` so the test isn't self-defeating.

### Verified end-to-end
On VM 907 against engine v0.36, ZIP 55401: created a real `WC_Order` with a $100 product, captured the breakdown, decoded the meta. Result: 6 jurisdictions (city/county/state + 3 transit districts) summing to $9.025 — exactly matching what the calc filter charged. `renderHtml()` produces a 1506-byte table with all jurisdictions visible.

## [0.2.0] — 2026-05-05

### Added
- **WC tax class → OST category custom mapping** (`src/TaxClassMap.php`). v0.1.x hard-coded every WooCommerce tax class to OST's `general` category, which was wrong for shops with `clothing`, `groceries`, or other custom classes. Merchants can now map any WC tax-class slug to one of the six OST categories (`general`, `clothing`, `groceries`, `prescription_drugs`, `prepared_food`, `digital_goods`) or to skip (non-taxable). Built-in defaults still apply for `''`/`standard`/`reduced-rate` → `general` and `zero-rate` → skip. Persisted as JSON in `wp_options['opensalestax_tax_class_map']`.
- WP-CLI commands: `wp opensalestax tax-class-list`, `tax-class-set <wc-class> <ost-category>`, `tax-class-reset`. The set command validates the category and refuses unknown values with a helpful error.
- 16 new unit tests in `TaxClassMapTest`: defaults, overrides, skip semantics, malformed-JSON fallback, set/reset, invalid-category-throws.

### Changed
- `TaxHandler::resolveCategory()` now consults `TaxClassMap::mapClassToCategory()` instead of hard-coding `zero-rate → skip` and everything else to `general`. The behavior for the four built-in WC classes is unchanged when no overrides are configured, so v0.1.x sites upgrade transparently.

### Verified end-to-end
On VM 907 against engine v0.36, ZIP 55401: `general` category yields MN's full 9.025% stack ($9.025 on $100); `clothing` correctly returns $0 with engine note "Clothing is non-taxable in Minnesota (Minn. Stat. 297A.67 subd 8)." Same `WC_Cart` payload, mapping flips the result.

## [0.1.2] — 2026-05-05

### Added
- **SSRF mitigation** (`src/UrlValidator.php`): the engine base URL is now validated to reject private/loopback/link-local/CGNAT IP ranges by default. Admins running the engine on the same LAN can opt in via the `opensalestax_allow_private_nets` WP option or the `OPENSALESTAX_ALLOW_PRIVATE_NETS` constant in `wp-config.php`. Closes the medium-severity SSRF finding from the v0.1.1 security review (CWE-918).
- 17 new unit tests covering UrlValidator scenarios (loopback, all RFC1918 ranges, link-local, CGNAT, public IPs, schemes, opt-in path).

### Changed
- `ClientFactory::build()` now runs the URL through `UrlValidator::validate()` and returns null with a logged error message if the URL is rejected. Callers see the same null-Client behavior as if the URL was unset.
- `docs/SECURITY-REVIEW.md` updated to reflect the SSRF finding as resolved.

## [0.1.1] — 2026-05-04

### Added
- `OpenSalesTax\WooCommerce\PlaceholderRate` — manages a single row in `wp_woocommerce_tax_rates` named "OpenSalesTax". TaxHandler now uses this row's tax_rate_id as the synthetic rate-id, so `WC_Cart::get_tax_totals()` properly labels the tax line as "OpenSalesTax" in the order summary. Fixes the v0.1.0 cosmetic gap where the breakdown showed `Tax lines (0)`.
- WP-CLI commands: `wp opensalestax test-connection`, `cache-flush`, `calc <zip> <amount>`, `placeholder-rate`.
- Comprehensive `docs/SECURITY-REVIEW.md` (OWASP Top 10 audit + CWE mapping + composer audit).
- Defense-in-depth direct-access guards (`defined('ABSPATH') || exit`) on all 6 src class files.

### Changed
- `composer.json` and `.github/workflows/ci.yml` updated for the public-repo state — VCS repo entry pointing at the public SDK; dropped the broken sibling-checkout step from CI.
- README and CONTRIBUTING.md cleaned up to remove "while the SDK is private" wording.

## [0.1.0] — 2026-05-04

### Added
- Initial v0.1 alpha against engine v0.24 + `ejosterberg/opensalestax` v0.1.0
- WordPress plugin compliant with the WP Plugin Handbook (canonical header, `readme.txt`)
- `OpenSalesTax\WooCommerce\TaxHandler` — overrides `woocommerce_calc_tax` filter to compute destination-based US sales tax via OpenSalesTax
- `OpenSalesTax\WooCommerce\Settings` — adds an "OpenSalesTax" subtab under WooCommerce → Settings → Tax
- `OpenSalesTax\WooCommerce\ConnectionTester` — AJAX "Test Connection" button on the settings page
- `OpenSalesTax\WooCommerce\Cache` — WP transient-based caching of tax lookups (default 60-min TTL)
- `OpenSalesTax\WooCommerce\ClientFactory` — builds the OST SDK client from saved settings
- Tax-exempt customers honored via `WC()->customer->is_vat_exempt()` short-circuit
- Calculation-only disclaimer on settings page (per project constitution §10)
- Configurable error fallback: `block` (no tax line) or `zero` (charge $0)
- PHPUnit unit-test suite + integration test against a real WP+WooCom instance
- GitHub Actions CI on PHP 8.2 / 8.3 / 8.4
