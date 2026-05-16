# Constitution — opensalestax-for-woocommerce

> Non-negotiable principles. Read before writing code; flag
> conflicts explicitly before deviating.

## §1. Mission

A WordPress plugin that replaces WC's built-in tax calculator
with destination-based US sales tax via a self-hosted
OpenSalesTax engine. Merchant value proposition: drop in,
point at engine, get accurate tax with no per-transaction fee.

## §2. Architecture (locked)

**In-WP-process plugin.** Wraps WooCommerce's
`woocommerce_calc_tax` filter. The handler is invoked per
taxable line during cart / checkout / order computation. The
trust boundary is the merchant's WordPress host; there is no
inbound HTTP surface, no webhook receiver, no JWT.

## §3. License

Apache-2.0. DCO sign-off mandatory on every commit. No AI
co-author trailers.

## §4. Engine-call contract

The OpenSalesTax engine HTTP API v1 is the source of truth.
The plugin calls (via the `ejosterberg/opensalestax` PHP SDK):

- `POST /v1/calculate` — per-line tax calculation by ZIP
- `GET /v1/health` — Test Connection + dashboard widget probe

The plugin NEVER imports OST internals. The HTTP API is the
contract.

## §5. USD-only / US-only

The engine is US-only / USD-only by design. The plugin's
`woocommerce_calc_tax` handler gates on US shipping country +
valid ZIP. Non-US carts fall through to WC's normal tax math.

## §6. Calculation only

Never file returns, never remit collected tax, never validate
addresses. The plugin computes tax; the merchant remits. Every
README / disclaimer carries this statement.

## §7. Trust boundary

The plugin runs inside the merchant's WordPress process. Code
loaded by WordPress is trusted; the plugin reads its
configuration from the WP options table (`wp_options`,
populated via the admin UI or WP-CLI). The OpenSalesTax engine
URL is the only outbound destination; capability checks
(`manage_woocommerce`) gate every admin-facing surface.

## §8. Fail-soft policy

When the engine is unreachable or returns 5xx, the
`calc_tax` filter returns the original `$tax_rates`
unmodified (= WC's normal tax math), logs an error, and lets
checkout proceed. Admin sees an engine-unreachable banner
(rate-limited via transient).

## §9. HPOS compatibility

Order meta access goes through `WC_Order::get_meta()` /
`update_meta_data()` to support High-Performance Order Storage.
Direct `get_post_meta()` / `update_post_meta()` is forbidden
in production code.

## §10. WC-specific integration points

- **`woocommerce_calc_tax`** — primary entry. Per-line tax computation.
- **`woocommerce_checkout_create_order`** — capture per-order
  breakdown for audit / admin display.
- **`woocommerce_refund_created`** — prorate parent breakdown
  for refunds.
- **`wcs_renewal_order_created`** — force tax recalc on WC
  Subscriptions renewals (gated on `class_exists('WC_Subscriptions')`).

## §11. Out of scope

- Filing / remittance
- Address validation / autocomplete
- Non-US jurisdictions / non-USD currency
- Marketplace-mode (sub-vendor tax allocation)
- Tax-exempt customer certificate validation
- WordPress.org plugin-directory's i18n requirements that
  conflict with the existing English-only release pattern —
  internationalization is welcome via PRs but not on the
  critical path
- Standalone HTTP / webhook server

## §12. Distribution

- Packagist: `ejosterberg/opensalestax-for-woocommerce`
- WordPress.org plugin directory: deferred (manual /
  editorial submission process)
- GitHub releases (per-tag) for direct download
