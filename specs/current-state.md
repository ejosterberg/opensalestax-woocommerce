# Current state — opensalestax-woocommerce

**Last refresh:** 2026-05-15 (v0.5.0 shipped — per-state nexus filter)
**Status:** **v0.5.0 shipped 2026-05-15.** Mature plugin. Nine releases. v0.5 added the per-state nexus filter (mirrors Vendure v1.2 / Magento v1.4 sibling pattern). 115 tests; PHPStan max + PHP-CS-Fixer + composer audit clean.

## What's shipped

| Version | Tag | Date | Highlights |
|---|---|---|---|
| 0.5.0 | `v0.5.0` | 2026-05-15 | Per-state nexus filter — admin toggle + state allowlist short-circuits engine calls for non-nexus states |
| 0.4.1 | `v0.4.1` | 2026-05-05 | Refund handling + engine-unreachable admin notice + Cache::get regression test |
| 0.4.0 | `v0.4.0` | 2026-05-05 | WooCommerce Subscriptions bridge — force recalc on `wcs_renewal_order_created` |
| 0.3.3 | `v0.3.3` | 2026-05-05 | Admin-UI tax-class mapper (WC tax class → OST category dropdown) |
| 0.3.2 | `v0.3.2` | 2026-05-05 | Calculation debug log (50-entry ring buffer); **critical Cache::get numeric-key fix** (silent cache miss since v0.1.1) |
| 0.3.1 | `v0.3.1` | 2026-05-05 | Status dashboard widget on WP admin home; engine health + placeholder rate + today's orders count |
| 0.3.0 | `v0.3.0` | 2026-05-05 | Per-order jurisdiction breakdown view stored in `_opensalestax_breakdown` order meta |
| 0.2.0 | `v0.2.0` | 2026-05-05 | WC tax class → OST category custom mapping (six OST categories + skip) |
| 0.1.x | `v0.1.0` → `v0.1.1` | 2026-05 | Initial alpha + bug fixes |

GitHub: <https://github.com/ejosterberg/opensalestax-woocommerce>

## Quality / verification baseline

- **109 unit tests** passing on every release (per CHANGELOG /
  README claim — verified against `tests/` directory layout)
- **End-to-end on real WP + WC**: VM 907 (or successor),
  engine v0.39 — every release tagged after a live cart flow
  produces non-zero per-jurisdiction tax via the engine
- **PHPStan**: configured via `phpstan.neon`; CI runs at the
  declared level
- **PHP-CS-Fixer**: PSR-12 + risky rules clean
- **GitHub Actions** matrix CI on PHP 8.2 / 8.3 / 8.4

## Where the upstream engine is

OST engine v0.22+ (v1 HTTP API). Latest verified-against
version per CHANGELOG: **v0.39** (as of v0.4.1 ship).

## Where the platform is

- WordPress 6.2+
- WooCommerce 8.2+ (tested through 10.7)
- HPOS-compatible
- WC Subscriptions bridge gated on plugin presence

## Architectural surface (v0.4.1)

| Component | Class | Notes |
|---|---|---|
| Tax calculation handler | `OpenSalesTax\WooCommerce\TaxHandler` | Hooks `woocommerce_calc_tax`; gates US ship-to + valid ZIP; cache → SDK → result; fail-soft on engine error |
| Cache | `OpenSalesTax\WooCommerce\Cache` | WP transient-backed, 60s TTL, numeric-key safe (v0.3.2 fix) |
| Settings | `OpenSalesTax\WooCommerce\Settings` | WC → Settings → Tax → OpenSalesTax. Engine URL, API key, Test Connection, calc-log toggle |
| Tax class mapping | `OpenSalesTax\WooCommerce\TaxClassMap` | WC tax-class slug → OST category; persisted as JSON in `wp_options` |
| Order breakdown | `OpenSalesTax\WooCommerce\OrderTaxBreakdown` | Capture on order create; render on admin order-edit; refund proration |
| Dashboard widget | `OpenSalesTax\WooCommerce\DashboardWidget` | Engine reachability + placeholder rate + today's orders |
| Engine health notice | `OpenSalesTax\WooCommerce\EngineHealthNotice` | Red admin banner when engine unreachable + plugin configured |
| Calculation log | `OpenSalesTax\WooCommerce\CalculationLog` | 50-entry ring buffer; disabled by default |
| Subscriptions bridge | `OpenSalesTax\WooCommerce\SubscriptionsBridge` | Recalc on renewal; only registered when WC Subscriptions class present |
| CLI | (multiple) | `wp opensalestax test-connection / tax-class-* / recent-calcs / clear-log / ...` |

## What's NOT done

- Publication to Packagist (composer.json still has VCS-repo
  block for the SDK; remove once SDK is on Packagist — captain
  reminder in `../opensalestax-Odoo/portfolio/needs-eric.md`)
- WordPress.org plugin directory submission (curated /
  editorial process; deferred)
- Internationalization (i18n) — README is English-only;
  Spanish / French welcome via PRs
- Marketplace-mode (sub-vendor tax allocation)
- Tax-exempt customer certificate validation

## Spec-folder map

| File | Purpose |
|---|---|
| `specs/constitution.md` | Non-negotiables (license, architecture, USD/US-only, HPOS, WC integration points) |
| `specs/current-state.md` | This file |
| `specs/handoff.md` | What the next session should pick up — v0.5 candidates |

## Quality bar (going forward)

A v0.5+ release requires:
- Full unit-test suite green (PHPUnit + WP_Mock)
- PHPStan clean
- PHP-CS-Fixer clean (PSR-12 + risky)
- CI matrix green on PHP 8.2 / 8.3 / 8.4
- DCO sign-off on every commit
- Live-VM verification documented in the CHANGELOG entry
- README + CHANGELOG updated

## Sibling-project map

Canonical state at `../opensalestax-Odoo/portfolio/state.md`.
