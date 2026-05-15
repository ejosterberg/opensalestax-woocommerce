# Handoff — opensalestax-woocommerce

> **Read first if you're a fresh agent.** Constitution + current
> state + this file are the canonical bring-up sequence.

## You are here — 2026-05-15 (v0.5.0 shipped + WP-org submission package complete)

Nine releases now. v0.5.0 shipped the per-state nexus filter (mirrors Vendure v1.2 / Magento v1.4 sibling pattern). Admin toggle under WC → Settings → Tax → OpenSalesTax: when enabled, the `woocommerce_calc_tax` handler short-circuits for non-nexus states. Default off — pre-v0.5 behavior preserved. 115 tests; all quality gates green.

**WP-org submission package complete** (captain session 2026-05-15 evening):

* `assets/icon-128x128.png` + `icon-256x256.png` — purple WC-tinted tile with stylized $ + OST chip.
* `assets/banner-1544x500.png` + `banner-772x250.png` — title "OpenSalesTax for WooCommerce", subtitle "Free, self-hosted US sales tax for WooCommerce."
* `assets/screenshot-1.png` … `screenshot-4.png` — main settings, tax-class mapping, dashboard widget, recent-calculations log; all ≥1280 px wide.
* `readme.txt` — added `== Screenshots ==` section; bumped Tested up to 6.9 / WC tested up to 10.7 to match the live verification VM.
* WC Cart/Checkout Blocks integration verified end-to-end on VM 907 — guest checkout to St. Paul MN renders an "OpenSalesTax" tax row with the correct destination-based amount.
* Quality gate re-verified: 115 unit tests green, PHPStan max clean, PHP-CS-Fixer dry-run clean, composer audit clean.

**Pending Eric's action:** submit to WP-org plugin directory using the assets in `assets/` (Eric provides the WP-org account in a separate step; the captain does NOT submit autonomously per `policy.md`).

**Live verification VM (reusable for future sessions):**

* VMID 907 on pmvm1 — `wp-woocommerce-test` at `10.32.161.9`
* SSH alias `wp-woocommerce-test` (ed25519 key `~/.ssh/proxmox_workshop`); user `ejosterberg`
* WP 6.9.4 + WC 10.7.0 + PHP 8.4 + MariaDB 11.8 + plugin v0.5.0 (deployed from local git, dependencies via Packagist)
* Engine `http://10.32.161.126:8080` (v0.58.0)
* WP admin: `http://10.32.161.9/wp-admin/` — user `admin` / password `WpScreenshot2026!` (rotate before next reuse)
* Helper scripts in `tools/`: `make-assets.py` (regenerates icons/banners), `capture-screenshots.mjs` (regenerates the four screenshots), `verify-blocks-checkout.mjs` (asserts the FAQ Blocks-checkout claim).

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
2. ~~**WordPress.org plugin directory submission package.**~~
   ✅ Polish complete (2026-05-15). `assets/` + readme
   `== Screenshots ==` + Tested-up-to bumps shipped. Eric
   submits to WP-org (curated review, weeks of lead time)
   when he's ready — captain does not submit per
   `portfolio/policy.md`.

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
