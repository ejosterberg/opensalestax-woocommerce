# Changelog

All notable changes documented here.
Format: [Keep a Changelog](https://keepachangelog.com/en/1.1.0/).
Versioning: [SemVer](https://semver.org).

## [Unreleased]

## [0.6.0] — 2026-05-15

### Changed

- **Plugin slug renamed `opensalestax-woocommerce` → `opensalestax-for-woocommerce`** to comply with WordPress.org's trademark policy. The bare `woocommerce` term is restricted in plugin slugs unless paired with one of the allowed patterns: `for woocommerce`, `with woocommerce`, `using woocommerce`, or `and woocommerce`. Plugin Check flagged the old slug as a WARNING in v0.5.1; WP-org's reviewer treats it as a hard reject. This release does the rename.
- **Main PHP file** renamed `opensalestax-woocommerce.php` → `opensalestax-for-woocommerce.php` (via `git mv` — blame and history preserved).
- **Text domain** renamed across every `__()` / `_e()` / `esc_html__()` / `esc_attr__()` call in `src/` and the main file. Plugin-header `Text Domain:` updated to match.
- **Plugin headers** `Plugin URI:` and `Author URI:` updated to the new GitHub repository URL.
- **Composer package name** `ejosterberg/opensalestax-woocommerce` → `ejosterberg/opensalestax-for-woocommerce`. Old Packagist package marked abandoned with `replacement: ejosterberg/opensalestax-for-woocommerce`; consumers with `composer require ejosterberg/opensalestax-woocommerce` in their `composer.json` get a deprecation warning pointing at the new package.
- **WC-logger `source`** slug renamed (`wc_get_logger()->warning(..., ['source' => 'opensalestax-for-woocommerce'])`) so the new log channel matches the new plugin slug.
- **GitHub repository** renamed `ejosterberg/opensalestax-woocommerce` → `ejosterberg/opensalestax-for-woocommerce` (GitHub auto-installs a redirect from the old URL; existing clones and links continue to work).

### Unchanged

- **User-visible plugin name** stays "OpenSalesTax for WooCommerce" (already user-friendly).
- **PHP namespaces** stay `OpenSalesTax\WooCommerce\…` (not slug-derived). Class names unchanged.
- **Internal option keys** (`opensalestax_*`) stay as-is — none contained `woocommerce`.
- **Enqueue handles** (`opensalestax-admin`) already prefix-clean — no change.
- **Behavior** — no functional changes; no settings migration required.

### Verified

- Plugin Check (WP-CLI mode, run on VM 907 against the deployed copy on the new slug) reports **0 errors + 0 warnings**. The v0.5.1 `trademarked_term` warning is gone.
- 115 unit tests green; PHPStan max + PHP-CS-Fixer dry-run + composer audit all clean.

## [0.5.1] — 2026-05-15

### Changed

- **Dual-licensed under `Apache-2.0 OR GPL-2.0-or-later`.** Previously Apache-2.0 only. New top-level `LICENSE` declares the dual arrangement; full Apache and GPL texts now live in `LICENSE-APACHE.txt` and `LICENSE-GPL.txt` respectively. Every PHP source file's SPDX header was updated to `Apache-2.0 OR GPL-2.0-or-later`. `composer.json`'s `license` field is now an array of both. Plugin header + `readme.txt` declare `GPLv2 or later` for WordPress.org plugin-directory compatibility (the recipient picks; the LICENSE file describes both options). Mirrors the Odoo connector's LGPL/AGPL pattern — pre-empts the WP-org reviewer's "GPL compatible?" challenge.

### Fixed (WordPress.org Plugin Check pre-submission)

- **i18n / escaping**: every output of composed HTML (`DashboardWidget::render`, `OrderTaxBreakdown::renderOrderDetails`) now passes through `wp_kses_post()`. Settings page table-cell renders use `esc_attr()` on every attribute value. CLI fallback (non-WP-CLI) terminal output passes through `esc_html()`.
- **i18n / exceptions**: `TaxClassMap::set()` now `esc_html()`'s the user-supplied OST category in the `InvalidArgumentException` message (it may surface in admin notices or CLI output).
- **input sanitization**: `Settings::saveTaxClassMap` (now explicitly verifies `woocommerce-settings` nonce + unslashes / `sanitize_text_field()`s each `$_REQUEST` / `$_POST` value defensively) and `ConnectionTester::handle` (same pattern for the AJAX `_nonce`). Added `testSaveRejectsBadNonce` test.
- **logging**: every `error_log()` call replaced with WC's standard logger via a `logWarning()` helper (`wc_get_logger()->warning('...', ['source' => 'opensalestax-woocommerce'])`); falls back to `error_log()` when WC isn't loaded (unit-test contexts).
- **URL parsing**: `UrlValidator` now uses `wp_parse_url()` instead of bare `parse_url()`.
- **SQL annotations**: every direct `$wpdb` query (placeholder-rate row management, cache flush, dashboard widget aggregate) now carries a `phpcs:ignore` comment explaining the controlled-input table-name interpolation pattern and why no `wp_cache_*` layer applies. All actually-user-supplied values bind through `$wpdb->prepare()`.
- **plugin header**: main file now wraps the autoload bootstrap in an IIFE so the `$autoload` local doesn't pollute the global namespace; `WC tested up to` bumped 10.5 → 10.7 to match `readme.txt`.
- **readme**: `Tags:` trimmed to the WP-org-allowed five (`tax, sales-tax, us-tax, taxjar, avalara`); `License:` simplified to `GPLv2 or later` to match the plugin file header (the dual-license declaration lives in the `LICENSE` file).
- **heredoc → string-concat**: `Settings::enqueueAdminAssets` rebuilt its inline JS snippet without the heredoc syntax that WP-org's Plugin Check rejects.

### Verified

- Plugin Check (WP-CLI mode, run on VM 907 against the deployed copy) reports **0 errors**. One `trademarked_term` WARNING remains (see *Known caveats*).
- 115 unit tests green (+1 vs. v0.5.0 — the new bad-nonce rejection test); PHPStan max + PHP-CS-Fixer dry-run + composer audit all clean.

### Known caveats (to address before WP-org submission lands)

- **Plugin slug rename: `opensalestax-woocommerce` → `opensalestax-for-woocommerce`** is required by WP-org's trademark policy (the bare "woocommerce" term in the slug is restricted unless paired with one of the allowed prefixes: `for woocommerce`, `with woocommerce`, `using woocommerce`, `and woocommerce`). Plugin Check flags this as a WARNING; the WP-org reviewer will treat it as a hard reject. Deferred from v0.5.1 because the rename touches the repository name, deployment paths, text domain, and every `__()` / `_e()` call — best handled as its own dedicated minor (e.g. v0.6.0).

## [0.5.0] — 2026-05-15

### Added

- **Per-state nexus filter.** New admin toggle *"Per-state nexus filter"* (under WooCommerce → Settings → Tax → OpenSalesTax) plus a *"Nexus states"* text field (comma- or space-separated 2-letter US state codes, e.g. `MN, WI, IA`). When enabled, the `woocommerce_calc_tax` handler resolves the destination state up-front and returns an empty array (no tax line) for any state not on the allowlist — WooCommerce falls back to its built-in tax-rate calculation (typically no tax). Default off; pre-v0.5 behavior unchanged. Mirrors the Vendure v1.2 / Magento v1.4 sibling pattern.
- New `TaxHandler::destinationIsInNexus()`, `TaxHandler::resolveDestinationState()`, and `TaxHandler::nexusAllowlist()` helpers — state lookup follows the same `woocommerce_tax_based_on` option (`billing` / `shipping` / `base`) as the existing ZIP resolution.
- 5 new TaxHandler tests covering: filter-off back-compat path, allowlisted-state pass-through, blocked-state short-circuit, enabled-with-empty-list (blocks everywhere), and the fail-closed path when the customer's state cannot be resolved. 115 tests / 195 assertions total (was 110 / 190).

### Fixed

- **Test fragility**: `TaxHandlerTest::setUp()` now installs a minimal `$wpdb` stub instead of relying on `DashboardWidgetTest` having run first to seed the global. Targeted `--filter TaxHandlerTest` now passes; previously it failed unless the full suite ran in alphabetical order.

### Compatibility

- Filter off → identical behavior to v0.4.1. No new options written until the merchant flips the toggle on.
- Filter on with empty state list → blocks tax everywhere (degenerate but explicit; honored as-is).
- Filter on with unresolvable destination state → fail-closed (no tax line). Safer default than ignoring the merchant's explicit opt-in.

## [0.4.1] — 2026-05-05

### Added
- **Refund handling** (`OrderTaxBreakdown::captureOnRefundCreate`). Hooks `woocommerce_refund_created` (priority 20). When a refund is created, the bridge looks up the parent order's stored breakdown, prorates every value by `(refund_total / parent_total) × -1`, and stores the negated breakdown on the refund. Result: the refund's admin page renders the same audit panel as a regular order, with negative jurisdiction amounts. No engine round-trip required; falls back to no-op when the parent has no stored breakdown (e.g., a refund of an order created before v0.3.0).
- **Engine-unreachable admin notice** (`src/EngineHealthNotice.php`). New class hooks `admin_notices` and renders a red banner when the engine is unreachable AND the plugin is configured. Capability-gated to `manage_woocommerce`. Reuses the same 60s health-probe transient as `DashboardWidget` so the notice never causes an extra engine call. Probes once per 60s when no cached state exists; uses a separate `opensalestax_engine_unreachable` transient marker to distinguish "never probed" from "probed and failed" without re-probing on every admin page-load.
- 4 new tests in `OrderTaxBreakdownTest` for refund proration (success path, missing-parent-breakdown no-op, zero-parent-total skip, missing `wc_get_order` skip).
- 6 new tests in `EngineHealthNoticeTest`: capability check, not-configured silent, healthy-cache silent, failure-marker renders banner, no-cache + 500 → renders banner + sets marker, no-cache + healthy → sets cache + clears marker.
- **Regression test for the v0.1.1 cache bug** (`CacheTest::testGetAcceptsNumericKeysFromTransientLayer`). Pins the v0.3.2 `Cache::get()` fix that accepts numeric-string keys after the WP transient layer coerces them to int. Plus `testGetRejectsNonScalarValues` to confirm the validator still rejects genuinely-corrupt payloads.

### Changed
- `OrderTaxBreakdown::register()` now also hooks `woocommerce_refund_created`.
- `Plugin::wireUp()` instantiates and registers the new `EngineHealthNotice` handler.

### Verified end-to-end
On VM 907 against engine v0.39:
- Order #14 ($100 in MN) captured 668-byte breakdown across 6 jurisdictions.
- Refund #16 ($-50, exactly half) captured 721-byte prorated breakdown — `tax_total: -4.5125` (exactly half of -9.025), Minneapolis line `$0.50 → $-0.25`, note: "Prorated from parent order #14 at ratio -0.5000".
- Engine-unreachable notice: silent when engine reachable; renders 381-byte `notice-error` banner with "engine is unreachable" copy when configured base URL points at a closed port.

## [0.4.0] — 2026-05-05

### Added
- **WooCommerce Subscriptions integration** (`src/SubscriptionsBridge.php`). When WC Subscriptions creates a renewal order, the bridge hooks `wcs_renewal_order_created` (priority 20) and forces a fresh tax recalculation. WC's `calculate_taxes()` walks the renewal's line items and triggers our `woocommerce_calc_tax` filter → engine → today's rates. Without this, WC Subscriptions silently copies the parent sub's tax line onto every renewal, so a state law change three months in goes uncollected on every renewal until someone notices.
- After recalc, the bridge runs `OrderTaxBreakdown::captureOnOrderCreate()` so the renewal order's admin page shows the same per-jurisdiction breakdown table as a regular order.
- Public static `SubscriptionsBridge::isSubscriptionsActive()` for future admin notices / dashboard badges.
- 7 new unit tests in `SubscriptionsBridgeTest`: not-installed detection, register no-op without subs, present detection, register hooks when subs active, recalc method-call sequence, recalc survives engine errors, recalc skips non-object input.

### Changed
- `Plugin::wireUp()` instantiates and registers the new `SubscriptionsBridge` handler. The bridge is gated on `class_exists('WC_Subscriptions')` at register-time, so installations without WC Subscriptions are unaffected.

### Verified
On VM 907 (no WC Subscriptions installed): bridge correctly reports inactive, `register()` is a no-op, regular cart calculations still work. With a stub `WC_Subscriptions` class loaded: bridge reports active. Live verification against a real WC Subscriptions install is deferred to merchants and contributors who own the paid plugin — the integration is built to spec against the documented `wcs_renewal_order_created` hook.

### Known limitations
- Live testing against the real WC Subscriptions plugin (a paid product) is not part of CI. The integration is tested with stubs against the documented hook signature; real-world signal welcomed via GitHub issues.
- Failed recalcs log to the PHP error log and let the renewal proceed with the inherited tax line — better to undercollect by a few cents than block a renewal payment.

## [0.3.3] — 2026-05-05

### Added
- **Admin-UI tax-class mapper.** New "Tax class → OST category mapping" panel on `WC > Settings > Tax > OpenSalesTax` lets merchants configure mappings without dropping to WP-CLI. Each row shows the WC tax-class slug, a dropdown of the 6 OST categories + "Skip (non-taxable)", and whether the current value is a custom override or default. Includes a "Reset all to defaults" checkbox.
- The renderer auto-discovers WC's user-defined tax classes (read from `woocommerce_tax_classes` and slugified the same way WC does), the 4 built-in slugs (`''`, `standard`, `reduced-rate`, `zero-rate`), and any slug already present in the merchant's existing override map.
- 6 new unit tests in `SettingsTaxClassSaveTest`: capability check rejects, reset checkbox path, no-op when no map posted, valid entries persist, invalid categories drop silently, all 6 valid categories accepted.

### Changed
- `Settings::register()` now also registers `saveTaxClassMap` on the `woocommerce_update_options_tax_opensalestax` action so the form-submit saves through.

### Security
- `saveTaxClassMap` enforces `current_user_can('manage_woocommerce')` and validates each posted category against `TaxClassMap::VALID_CATEGORIES` (or empty string for skip). Invalid values are silently dropped — the dropdown shouldn't produce them, but defense-in-depth catches a tampered-form submission.
- Verified on VM 907: an unprivileged user's posted form is no-op'd; the admin user's submit persists; reset clears.

## [0.3.2] — 2026-05-05

### Added
- **Recent-calculations debug log** (`src/CalculationLog.php`). A 50-entry ring buffer that captures every tax calculation passing through `TaxHandler::calcTax()`. Each entry records timestamp (UTC), source (`cache-hit` / `engine-call` / `error`), destination ZIP, OST category, pre-tax amount, computed tax, round-trip duration in ms, and any error message. Logging is **disabled by default** because it adds one option-write per calculation; turn it on via the new toggle on `WC > Settings > Tax > OpenSalesTax` or `wp option update opensalestax_calc_log_enabled 1`.
- New WP-CLI commands: `wp opensalestax recent-calcs [--limit=<N>]` (default 20) and `wp opensalestax clear-log`.
- New "Recent calculations" panel on the OpenSalesTax settings page renders the log as a styled HTML table — only shown when the log option has captured entries (or to nudge users to enable logging).

### Fixed
- **`Cache::get()` was silently degrading to "no caching"** since v0.1.1. The placeholder-rate id round-tripped through the WP transient layer as `int` (PHP auto-converts numeric-string array keys), but the cache validator rejected entries where any key wasn't `is_string()`. This meant every cart calculation hit the engine, every time. Now `Cache::get()` accepts numeric keys and stringifies them on read, matching how WC's tax filter consumes them either way. Verified end-to-end on VM 907: call 1 → `engine-call` (241ms), call 2 → `cache-hit` (no duration).

### Changed
- `TaxHandler::calcTax()` instruments the cache-hit, engine-call, and error code paths with `CalculationLog::record()` calls. No-op when logging is disabled.
- `Plugin::wireUp()` registers two new CLI subcommands (`recent-calcs`, `clear-log`).

### Verified end-to-end
On VM 907 against engine v0.39, `wp opensalestax recent-calcs` after two identical $100 cart calculations to ZIP 55401 shows the log captured both — first as `engine-call` populating the cache, second as `cache-hit` with no engine round-trip. Error path verified separately with engine intentionally unreachable.

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
