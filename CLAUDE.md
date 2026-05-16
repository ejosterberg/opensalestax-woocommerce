# CLAUDE.md — opensalestax-for-woocommerce

> Project memory for Claude sessions on the WooCommerce
> plugin. Read this AND `specs/constitution.md` +
> `specs/handoff.md` before writing code.

## Mission

A WordPress plugin that replaces WC's built-in tax calculator
(plus competitive paid alternatives — TaxJar, Avalara, WC Tax)
with destination-based US sales tax computed by a self-hosted
OpenSalesTax engine. Free, open-source, Apache-2.0.

## Stack

- **Language:** PHP 8.2+
- **Platforms:** WordPress 6.2+ / WooCommerce 8.2+ (tested
  through WC 10.5 / 10.7); HPOS-aware
- **Runtime dep:** `ejosterberg/opensalestax` PHP SDK (^0.1)
- **Distribution:** Packagist (`ejosterberg/opensalestax-for-woocommerce`)
  + WordPress.org plugin directory (submission deferred)
- **License:** Apache-2.0
- **Tests:** PHPUnit 9.6 + WP_Mock 1.0 + PHPStan + PHP-CS-Fixer
  + GitHub Actions matrix; live-VM verification on each release

## Architectural anchors

- **In-WP-process plugin.** Wraps WC's `woocommerce_calc_tax`
  filter. On every taxable line, the handler reads the
  customer's ZIP, resolves the WC tax class to an OST category
  (via configurable mapping), checks tax-exempt status, consults
  the cache, and (on miss) calls the engine via the SDK. No
  separate server, no webhook receiver, no JWT.
- **WC tax class → OST category mapping.** Persisted as JSON
  in `wp_options['opensalestax_tax_class_map']`. Configurable
  via Admin UI (WC → Settings → Tax → OpenSalesTax) and WP-CLI
  (`wp opensalestax tax-class-list / -set / -reset`). Default
  for unmapped classes is `general`; the four built-in WC
  classes (`''`, `standard`, `reduced-rate`, `zero-rate`) have
  sensible defaults.
- **Per-order jurisdiction breakdown.** On checkout, the plugin
  re-runs the engine call against the order's
  destination + lines and stores the full breakdown as JSON in
  `_opensalestax_breakdown` order meta. Renders as a table in
  the admin order-edit screen.
- **Refund handling.** On `woocommerce_refund_created`, prorates
  the parent order's stored breakdown and stores the negated
  version on the refund. No engine round-trip.
- **WC Subscriptions bridge.** Forces tax recalculation on
  `wcs_renewal_order_created` so renewals pick up today's rates
  instead of inheriting the parent subscription's tax line.
- **Cache layer.** WP transient-backed, 60s TTL. Numeric-string
  keys handled correctly (v0.3.2 fix).
- **Dashboard widget + engine-unreachable admin notice.** Both
  capability-gated to `manage_woocommerce`; both share a 60s
  health-probe transient so they don't multiply engine calls.
- **Calculation debug log.** 50-entry ring buffer, disabled by
  default; toggleable via Admin UI or WP-CLI.

## Architectural anchors NOT to violate

- USD-only / US-only (engine constitution)
- Calculation only — no filing, no remittance, no address validation
- No standalone HTTP / webhook server
- No SDK fork — depend on `ejosterberg/opensalestax` upstream

## What NOT to do

- Don't change the `woocommerce_calc_tax` filter integration
  pattern without an ADR. v0.1.x explored alternatives;
  filter-based is the one merchant-friendly approach.
- Don't add per-merchant SaaS keys / phoning home / telemetry —
  this is self-hosted, period.
- Don't break HPOS compatibility. Order meta access goes
  through `WC_Order::get_meta()` / `update_meta_data()`, never
  `get_post_meta()` directly.
- Don't accept commits without DCO sign-off.
- Don't introduce non-Apache-2.0-compatible dependencies.

## Releasing

- Single `main` branch; semver tags `vX.Y.Z` (no
  alpha/beta in this project's release line — every tag is a
  shipped point release).
- GitHub release per tag.
- Distributed via Packagist (`ejosterberg/opensalestax-for-woocommerce`).
- WordPress.org plugin directory submission deferred (manual /
  editorial; no time pressure).
- Live-VM verification on every release before tagging — log
  the verified-against engine version in the CHANGELOG.

## Sibling-project map

See `../opensalestax-Odoo/portfolio/state.md` for the
canonical list of all OST connector projects in this
portfolio. WooCommerce is one of ~18.
