# Security Review — opensalestax-woocommerce v0.1.1

**Reviewer:** automated audit + manual code review (2026-05-04)
**Scope:** all PHP source files in `src/` and `opensalestax-woocommerce.php`
**Methodology:** OWASP Top 10 mapped to WP-plugin-specific concerns; manual line-by-line review against CWE-driven checklist; `composer audit` against current advisories.

## Summary

| Severity | Count | Status |
|---|---|---|
| 🔴 **Critical** | 0 | — |
| 🟠 **High** | 0 | — |
| 🟡 **Medium** | 1 | Documented (residual) |
| 🟢 **Low / Informational** | 4 | 3 fixed, 1 documented |
| ✅ **Defense-in-depth** | 1 | Fixed in v0.1.1 |

**No critical or high-severity findings.** The plugin's threat model is bounded by the `manage_woocommerce` capability — every state-changing path is gated behind it. The one medium finding (SSRF via base-URL configuration) requires admin compromise to exploit and produces low payoff for an attacker; documented as a known consideration rather than blocked.

`composer audit` against the dependency tree (production + dev): **0 known CVEs**.

## Findings

### 🟡 MEDIUM — SSRF via admin-controlled base URL

**File:** `src/ClientFactory.php`
**CWE:** CWE-918 (Server-Side Request Forgery)

The plugin reads `opensalestax_base_url` from `wp_options` and uses it as the destination for HTTP requests to the engine. An attacker who has compromised an admin account (or a malicious admin) could set the URL to:

- An internal service the WP server can reach (e.g. `http://localhost:6379/` for Redis)
- AWS metadata endpoint (`http://169.254.169.254/latest/meta-data/`)
- Other sensitive intranet hosts

The plugin then issues HTTP requests to that URL on the customer's normal cart/checkout flow, potentially:

1. **Probing internal services** — the request response (or timing/error patterns) could leak information about reachable internal hosts
2. **Triggering side effects** on services that act on URL hits (e.g., GET requests with query params that an internal service interprets as commands)

**Mitigation in place (v0.1):**

- Setting the URL requires `manage_woocommerce` capability — not a low-privilege endpoint
- The HTTP method is always GET or POST with a JSON body in a well-defined shape (`/v1/health`, `/v1/states`, `/v1/rates`, `/v1/calculate`); not user-controllable arbitrary requests
- Engine response is parsed as a typed JSON shape; non-conforming responses produce errors but don't leak unparsed bytes to the customer

**Residual risk:** A compromised admin account is already game-over for the WP install; SSRF is one of many things the attacker could do. Real-world impact is bounded.

**v0.2 hardening (planned, not blocking):**

- Validate the configured base URL doesn't resolve to RFC1918 / link-local / loopback ranges by default; allow override via a `OPENSALESTAX_ALLOW_PRIVATE_NETS=1` env var for self-hosted-on-LAN setups
- Document the SSRF consideration in the install guide

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

## Test surface

The plugin's PHPUnit suite exercises 19 test cases / 21 assertions covering:

- Tax-exempt customer path
- ZIP resolution from billing/shipping/base settings
- Cache hit/miss paths
- Engine error handling (block + zero modes)
- ZIP regex sanitization
- WC tax-class to OST category mapping

Plus the end-to-end integration test against a real WP+WooCom Proxmox VM (`tests/Integration/E2ECartTaxTest.php`).

No security-specific fuzz test in v0.1; the plugin's input surface is small enough that the existing tests provide adequate coverage. v0.2 may add explicit malicious-input tests (oversized ZIPs, non-numeric amounts, unicode-edge-case categories) once the surface grows.

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

- **v0.2** — full re-review when SSRF mitigation, WC Subscriptions support, and WP-CLI commands ship
- **Quarterly** — `composer audit` + a quick pass on any new code paths
- **On every contributor PR** — manual review of any security-touching change
