# Handoff — opensalestax-woocommerce

> **Read first if you're a fresh agent.** Constitution + current
> state + this file are the canonical bring-up sequence.

## You are here — 2026-05-15 (v0.5.0 shipped — per-state nexus filter)

Nine releases now. v0.5.0 shipped the per-state nexus filter (mirrors Vendure v1.2 / Magento v1.4 sibling pattern). Admin toggle under WC → Settings → Tax → OpenSalesTax: when enabled, the `woocommerce_calc_tax` handler short-circuits for non-nexus states. Default off — pre-v0.5 behavior preserved. 115 tests; all quality gates green.

For the deeper "where we are" snapshot read
[`specs/current-state.md`](current-state.md).

## What's next — v0.6 candidates

Pick whichever interests you. Each item is roughly a day of
focused work; some are shorter if you stick to MVP scope.

### Tier 1 — likely shipped first

1. **Publish to Packagist.** composer.json still has the
   `repositories` VCS block pointing at the private SDK repo —
   needs to be removed once the SDK is on Packagist. The
   captain session of 2026-05-15 surfaced this in
   `../opensalestax-Odoo/portfolio/needs-eric.md` (step 4 —
   Eric flips SDK public + Packagist register). When that
   lands, drop the VCS block, push v0.4.2 (or v0.5.0).
2. **WordPress.org plugin directory submission.** Editorial /
   curated review; weeks of lead time. The repo already has a
   `readme.txt` formatted for WP-org; verify it's current
   against the actual feature set (it was generated for an
   earlier release).

### Tier 2 — feature work

3. **Internationalization (i18n).** Plugin strings are
   English-only. Add `Text Domain: opensalestax-woocommerce`
   header (already there) and wrap user-facing strings in
   `__()` / `_e()` / `_n()` calls. Add `.pot` generation to
   release tooling.
4. ~~**Per-state nexus filter.**~~ ✅ Shipped in v0.5.0 (2026-05-15).
5. **Multi-currency support gate.** WC supports multi-currency
   via add-ons. Verify the USD-only gate still works when WC
   is configured with multi-currency — the customer's checkout
   may not be in USD even though the store base is. Test +
   document.

### Tier 3 — operational

6. **WooCommerce Blocks (cart/checkout block) integration.**
   The newer WC checkout uses React-driven blocks instead of
   the legacy shortcode-driven cart. Verify the
   `woocommerce_calc_tax` filter still fires correctly in the
   blocks path (likely yes, but worth confirming + writing a
   regression test).
7. **POS / WooCommerce Mobile integration test.** WC has a POS
   add-on; verify the plugin works through that surface or
   document the limitation.
8. **HPOS-only mode optimization.** Some order meta access in
   `OrderTaxBreakdown` checks for both HPOS and legacy CPT
   paths. Once a release line drops legacy support, the code
   can simplify.

### Tier 4 — engine-side prerequisites

9. **Transaction record-back** on `woocommerce_payment_complete`
   — gated on engine adding `POST /v1/transactions`. Not on
   the engine roadmap yet.
10. **Marketplace-mode** (sub-vendor tax allocation) — gated
    on the engine adding per-vendor allocation logic. Out of
    scope per constitution.

## Standing rules

- Apache-2.0; DCO sign-off mandatory; no AI co-author trailers
- Constitution §5: USD-only / US-only — gate on US ship-to and
  valid 5-digit ZIP
- Constitution §8: fail-soft default (engine error → return
  original `$tax_rates`, log error, let checkout proceed)
- HPOS compatibility: order meta via `WC_Order::get_meta()` only
- Live-VM verification before every tag

## Known caveats

Worth knowing if you're picking up this repo:

- The **`Cache::get` numeric-key bug** (silent cache miss since
  v0.1.1, fixed in v0.3.2) is now regression-tested. Don't
  delete that test.
- The **WC Subscriptions bridge** is gated on
  `class_exists('WC_Subscriptions')` so it's a no-op in
  installations without WCS. The test suite uses stubs;
  there's no live-WCS CI environment (WCS is a paid plugin).
- `_opensalestax_breakdown` order meta is JSON-encoded. Don't
  consume it via direct meta access — use
  `OrderTaxBreakdown::get(WC_Order $order): ?array`.
- The plugin auto-creates a placeholder WC tax rate row in
  `wp_woocommerce_tax_rates` at activation so `WC_Cart::get_tax_totals()`
  has a label string. If that row goes missing, the dashboard
  widget flags it and prompts re-activation.

## Pre-flight for a fresh session

1. Read `specs/constitution.md`
2. Read `specs/current-state.md`
3. Read `specs/handoff.md` (this file)
4. Read `docs/SECURITY-REVIEW.md`
5. Skim recent commits (`git log --oneline -10`)
6. Pick a v0.5 candidate and ship it

## Portfolio context

Canonical state at `../opensalestax-Odoo/portfolio/state.md`.
