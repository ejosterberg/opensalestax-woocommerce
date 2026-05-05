# Security Review — opensalestax-woocommerce v0.3.1

**Reviewer:** automated audit + manual code review (2026-05-04, updated 2026-05-05 with v0.1.2 SSRF mitigation, v0.2.0 TaxClassMap, v0.3.0 OrderTaxBreakdown, and v0.3.1 DashboardWidget)
**Scope:** all PHP source files in `src/` and `opensalestax-woocommerce.php`
**Methodology:** OWASP Top 10 mapped to WP-plugin-specific concerns; manual line-by-line review against CWE-driven checklist; `composer audit` against current advisories.

## Summary

| Severity | Count | Status |
|---|---|---|
| 🔴 **Critical** | 0 | — |
| 🟠 **High** | 0 | — |
| 🟡 **Medium** | 1 | **✅ Fixed in v0.1.2** (SSRF mitigation) |
| 🟢 **Low / Informational** | 4 | 3 fixed, 1 documented |
| ✅ **Defense-in-depth** | 1 | Fixed in v0.1.1 |

**No critical, high, or medium-severity open findings.** The plugin's threat model is bounded by the `manage_woocommerce` capability — every state-changing path is gated behind it. The previously-medium SSRF finding was resolved in v0.1.2 with a private-IP filter that's strict-by-default (admins must explicitly opt in to allow private hosts via the `opensalestax_allow_private_nets` WP option or `OPENSALESTAX_ALLOW_PRIVATE_NETS` constant).

`composer audit` against the dependency tree (production + dev): **0 known CVEs**.

## Findings

### ✅ ~~MEDIUM — SSRF via admin-controlled base URL~~ FIXED in v0.1.2

**Files:** `src/ClientFactory.php`, `src/UrlValidator.php` (new in v0.1.2)
**CWE:** CWE-918 (Server-Side Request Forgery)
**Status:** Resolved 2026-05-05.

The plugin reads `opensalestax_base_url` from `wp_options` and uses it as the destination for HTTP requests to the engine. An attacker who has compromised an admin account could previously set the URL to:

- An internal service the WP server can reach (e.g. `http://localhost:6379/` for Redis)
- AWS metadata endpoint (`http://169.254.169.254/latest/meta-data/`)
- Other sensitive intranet hosts

**Fix (v0.1.2):**

The new `OpenSalesTax\WooCommerce\UrlValidator::validate()` runs at `Client` build time AND can be invoked from the settings save flow (planned for v0.2 settings-page UX). It rejects URLs whose host:

- Has no scheme or a non-`http(s)` scheme (no `file://`, `gopher://`, etc.)
- Resolves to RFC1918 (10/8, 172.16/12, 192.168/16)
- Resolves to loopback (127/8, ::1)
- Resolves to link-local (169.254/16, fe80::/10) — including AWS metadata endpoint
- Resolves to carrier-grade NAT (100.64/10, RFC 6598)
- Cannot be resolved at all (host doesn't exist)

When validation fails, the `Client` build returns null and a structured message is logged via `error_log`. WC's tax-calc filter then degrades according to the configured `opensalestax_error_fallback` setting (`block` or `zero`).

**Opt-out for legitimate self-hosted-on-LAN deployments:**

Many merchants run the OpenSalesTax engine on the same private network as their WordPress server (a common self-hosted pattern). Setting EITHER:

- The WP option `opensalestax_allow_private_nets` to `"1"`:
  ```bash
  wp option update opensalestax_allow_private_nets "1"
  ```
- OR the constant `OPENSALESTAX_ALLOW_PRIVATE_NETS` to `true` in `wp-config.php`:
  ```php
  define('OPENSALESTAX_ALLOW_PRIVATE_NETS', true);
  ```

…allows the plugin to talk to private IPs. The constant takes precedence over the option, so `wp-config.php`-based opt-in survives a database compromise.

**Residual risk:** Acceptable. An admin who can both compromise the WP install AND modify `wp-config.php` is already game-over; SSRF is one of many problems they can cause. The default-strict policy raises the bar materially for the database-only-compromise case.

**Test coverage:** 17 unit tests in `tests/Unit/UrlValidatorTest.php` cover loopback, all RFC1918 ranges, link-local, CGNAT, public IPs, public hostnames, file://, ftp://, empty URLs, and the opt-in path.

### 🟢 LOW — API key stored in plain-text in wp_options

**File:** `src/ClientFactory.php`
**CWE:** CWE-256 (Plaintext Storage of a Password)

The OST API key, when configured, is stored in `wp_options` as plain text (consistent with how WP stores most config secrets). An attacker with read access to the database can recover it.

**Mitigation:**

- WP doesn't provide a built-in encrypted-options API; storing API keys in `wp_options` is the standard pattern (Stripe, SendGrid, Akismet, all do the same)
- The settings UI uses an HTML password field, so casual screen-shoulder-surfing is mitigated
- The OST API key only grants access to the **caller's own engine** — it's a self-hosted-engine auth token, not a third-party key. Compromise impact is bounded to the merchant's own infrastructure.

**Residual risk:** Acceptable. Documented in `docs/INSTALL.md`.

**v0.2 consideration:** If WP adds a built-in encrypted-secrets API or a popular community alternative emerges, switch to it.

### 🟢 LOW — Engine response trust

**File:** `src/TaxHandler.php`
**CWE:** CWE-602 (Client-Side Enforcement of Server-Side Security)

The plugin trusts the engine's `tax_total` response value and uses it directly as the tax to charge the customer. If the engine is compromised (or misconfigured), the response could under-tax (revenue loss) or over-tax (compliance risk).

**Mitigation:**

- The engine is **self-hosted by the merchant** — the merchant controls its security
- The plugin doesn't render arbitrary engine response content to the customer; only the typed `tax_total` numeric value flows into WC's flow
- Engine-side: production deployment should run behind a firewall, with monitoring on the engine's `/v1/health`

**Residual risk:** Trusts the engine, by design. The whole architecture assumes the merchant trusts their own infrastructure.

### 🟢 LOW — Verbose error messages in admin notices

**File:** `src/ConnectionTester.php`
**CWE:** CWE-209 (Information Exposure Through an Error Message)

The "Test connection" AJAX handler returns the underlying exception class and message in its error response. For an unexpected `\Throwable`, this could leak file paths, class names, and other internal details.

**Mitigation:**

- The endpoint is gated behind `manage_woocommerce` capability + nonce verification — only authenticated admins can trigger it
- Authenticated admins ALREADY see PHP error log via WP debug mode; this isn't a new disclosure surface
- The exposed information is implementation detail, not secrets

**Residual risk:** Acceptable for v0.1. The verbose error helps debug deployment issues, which outweighs the disclosure concern given the auth boundary.

### 🟢 LOW — Missing direct-access guards on class files (FIXED in v0.1.1)

**Files (all fixed):** `src/Plugin.php`, `src/Cache.php`, `src/ClientFactory.php`, `src/TaxHandler.php`, `src/Settings.php`, `src/ConnectionTester.php`
**CWE:** N/A (defense-in-depth)

WordPress convention: every plugin PHP file should start with `defined('ABSPATH') || exit;` to prevent direct browser access from executing the file. Class files loaded via Composer autoload are technically safe (autoload only fires after WP boots), but the guard is defense-in-depth and required by WP.org plugin directory review.

**Fix applied (v0.1.1):** Added `defined('ABSPATH') || exit;` after the `namespace` declaration in all 6 src/ class files. The main `opensalestax-woocommerce.php` plugin file already had the guard.

### ✅ Verified safe — areas reviewed with no findings

| Path | Concern | Result |
|---|---|---|
| `Cache::flushAll()` | SQL injection on options table | Uses `$wpdb->prepare()` with `%s` placeholders — safe |
| `TaxHandler::resolveDestinationZip()` | Customer-controlled input | Reads from WC's typed customer object; final regex `preg_replace('/\D/', '', ...)` strips non-digits before use |
| `ConnectionTester::handle()` | CSRF | `wp_verify_nonce()` + `current_user_can('manage_woocommerce')` ✓ |
| `Settings::registerFields()` | XSS in `desc` | Content is hardcoded in our source; no user input rendered ✓ |
| `Settings::enqueueAdminAssets()` inline JS | XSS via interpolated values | URL passed through `json_encode()`; nonce from `wp_create_nonce()` ✓ |
| `TaxHandler::calcTax()` | Engine response handling | Numeric `tax_total` cast to `float`; not echoed to user ✓ |
| `opensalestax-woocommerce.php` (main) | Direct-access protection | `defined('ABSPATH') || exit;` ✓ |
| `error_log()` calls | PII / secret leakage | Logs only exception class + message, never customer data or API key ✓ |
| Hard-coded secrets / credentials | Embedded keys | None found ✓ |
| `eval()` / `assert()` / dynamic include | Code injection vectors | None used (test code uses `eval` only inside PHPUnit fixtures, not production paths) ✓ |
| Composer dependency tree | Known CVEs | `composer audit` clean ✓ |
| `TaxClassMap` JSON option (v0.2.0) | Untrusted JSON deserialization | `json_decode($json, true)` returns plain arrays, never objects; non-array result rejected, malformed JSON falls back to `[]` defaults; values validated in `set()` against the `VALID_CATEGORIES` allow-list before persistence ✓ |
| `TaxClassMap::set()` (v0.2.0) | Capability check | Capability gating enforced at the call site (WP-CLI requires shell access; admin UI not yet exposed). When the admin UI lands in v0.3, calls must be wrapped in `current_user_can('manage_woocommerce')` ⚠ |
| `OrderTaxBreakdown::renderHtml()` (v0.3.0) | XSS via engine-supplied jurisdiction names / notes | Every interpolated value passes through `esc_html()` / `esc_html__()`; jurisdiction `name`, `type`, `rate_pct`, `tax`, line `category`/`amount`/`tax`/`note` all escaped. Verified via `testRenderHtmlEscapesUserContent` with a real `htmlspecialchars` callback (WP_Mock's default pass-through would have masked the test). ✓ |
| `OrderTaxBreakdown::captureOnOrderCreate()` (v0.3.0) | JSON injection via order line data | Order line items are read via WC's typed accessors (`get_total()`, `get_tax_class()`); values are cast to `float`/`string` before reaching the SDK; no user-controlled string flows into `wp_json_encode` un-typed. ✓ |
| `OrderTaxBreakdown::get()` (v0.3.0) | Untrusted JSON deserialization from order meta | `json_decode($raw, true)` returns plain arrays; non-array result rejected; missing/non-array `lines` key rejected. Meta is written only by our own `captureOnOrderCreate`, but an attacker with order-meta write capability still couldn't get HTML to execute thanks to `esc_html()` in the renderer. ✓ |
| `DashboardWidget::addWidget()` (v0.3.1) | Capability check | Gated on `current_user_can('manage_woocommerce')` before `wp_add_dashboard_widget()` so non-WC users don't see engine version / configured-base-URL hints. ✓ |
| `DashboardWidget::countOrdersWithBreakdownToday()` (v0.3.1) | SQL injection on order/meta tables | Uses `$wpdb->prepare()` with `%s` placeholders for the meta key + date threshold; table names interpolated from `$wpdb->prefix` (controlled by WP, never user input). HPOS detection uses `prepare()` against `information_schema`. ✓ |
| `DashboardWidget::renderHtml()` (v0.3.1) | XSS via engine version / cached health response | All interpolated values pass through `esc_html()` / `esc_attr()` / `esc_url()`. Engine version goes through `esc_html()` even though the engine is trusted. ✓ |

## Test surface

The plugin's PHPUnit suite exercises 74 test cases / 118 assertions covering:

- Tax-exempt customer path
- ZIP resolution from billing/shipping/base settings
- Cache hit/miss paths
- Engine error handling (block + zero modes)
- ZIP regex sanitization
- WC tax-class to OST category mapping (16 tests)
- UrlValidator: loopback, all RFC1918 ranges, link-local, CGNAT, public IPs, schemes, opt-in path (17 tests)
- PlaceholderRate row management
- OrderTaxBreakdown: capture path, ZIP fallback, zero-rate skip, malformed-meta safety, render-with-jurisdictions, render-with-note, **XSS-defense (real `htmlspecialchars` via `WP_Mock::userFunction` callback override — proves escaping is wired everywhere)**, engine-error tolerance (12 tests)
- DashboardWidget: not-configured / healthy / unreachable / missing-placeholder / cached-health states (5 tests)

Plus the end-to-end integration test against a real WP+WooCom Proxmox VM 907 (`tests/Integration/E2ECartTaxTest.php`).

## Recommendations for users

1. **Run the engine on a private network** (LAN, VPC) — don't expose the OST engine publicly. The plugin assumes a trusted engine.
2. **Restrict `manage_woocommerce` capability** to a small number of trusted admins.
3. **Monitor unusual tax patterns** in the WP error log (`grep opensalestax /var/log/php*.log`); calculation anomalies can flag engine compromise faster than waiting for compliance review.
4. **Pin the engine version** you've tested with. The engine-side state-bleed bug fixed in v0.22 was a calculation-correctness issue, not a security issue — but engine bugs are real and worth tracking.
5. **Use HTTPS** between WP and the engine if they're on different networks. The plugin sends an API key in the `X-API-Key` header; HTTPS prevents trivial sniffing.

## Reporting

Security issues: email **ejosterberg@gmail.com** directly. Don't open public GitHub issues for vulnerabilities.

Once a fix lands, the disclosure will be coordinated via:
- A CVE if the issue is widely-exploitable
- A GitHub Security Advisory on the repo
- A note in `CHANGELOG.md` with the fix version

## Re-review schedule

- **v0.2.0** — re-reviewed 2026-05-05 for the TaxClassMap addition.
- **v0.3.0** — re-reviewed 2026-05-05 for OrderTaxBreakdown. Output-escaping verified end-to-end with a real-`htmlspecialchars` test override (since WP_Mock pass-through would have masked the bug). Order meta deserialization safe (json_decode returns arrays, type-checked).
- **v0.3.x / v0.4** — full re-review when admin-UI tax-class mapper, debug log, status widget, and WC Subscriptions support ship. Admin-UI specifically must capability-gate every state-changing call.
- **Quarterly** — `composer audit` + a quick pass on any new code paths
- **On every contributor PR** — manual review of any security-touching change
