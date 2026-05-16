# Install + Configure + Verify Guide

> Step-by-step walkthrough for installing **OpenSalesTax for WooCommerce** on a real WordPress + WooCommerce site, configuring it, and verifying the integration is working.
>
> Estimated time: **~30 minutes** end-to-end if you already have WordPress + WooCommerce running. Add ~5 minutes for the OpenSalesTax engine if you don't have it deployed yet.

## Prerequisites

| Requirement | Minimum | Recommended |
|---|---|---|
| WordPress | 6.2 | 6.6+ |
| WooCommerce | 8.2 | 9.0+ |
| PHP | 8.2 | 8.3+ |
| MySQL/MariaDB | 5.7 / 10.5 | 8.0 / 11.0+ |
| OpenSalesTax engine | v0.22 | v0.24+ (state-bleed bug fixed in v0.22) |
| Composer | 2.0 | latest |

You'll also need:

- SSH access to the WordPress server (or comparable file deployment)
- Ability to run `composer install` on the server (or upload a pre-built release with `vendor/`)
- WP-Admin user with the `manage_woocommerce` capability

---

## Step 1 — Stand up an OpenSalesTax engine

The plugin is a thin client; the actual tax math runs in a separate self-hostable engine. Skip this step if you already have an engine running.

```bash
# On the host that will run the engine (any Linux box; VM, container, bare metal):
git clone https://github.com/ejosterberg/open-sales-tax
cd open-sales-tax
docker compose up -d

# Verify:
curl http://localhost:8080/v1/health
# → {"status":"ok","version":"0.35.0","database_connected":true}
```

Production: lock down the engine behind a firewall or reverse proxy. The plugin will hit it from your WordPress server, so it needs to be reachable from WP — but typically not from the public internet.

If you're already on Eric's lab LAN, the engine at **`http://10.32.161.126:8080`** is fine for testing.

---

## Step 2 — Install the plugin

Three install methods, pick whichever fits your deploy workflow.

### Method A — Git clone (recommended for now while we're in alpha)

```bash
cd /path/to/your/wordpress/wp-content/plugins/
git clone https://github.com/ejosterberg/opensalestax-for-woocommerce.git
cd opensalestax-for-woocommerce
composer install --no-dev --no-progress
```

`--no-dev` skips PHPUnit and PHPStan; you don't need them in production.

### Method B — Composer (for `wpackagist`-style sites)

When the repo flips public + lands on Packagist:

```bash
composer require ejosterberg/opensalestax-for-woocommerce
```

You'll need `composer/installers` so Composer drops the package into `wp-content/plugins/` instead of `vendor/`.

### Method C — Pre-built release ZIP

Download the latest release ZIP from the [Releases page](https://github.com/ejosterberg/opensalestax-for-woocommerce/releases) and upload via **WP Admin → Plugins → Add New → Upload Plugin**. The release ZIP bundles `vendor/` so no Composer step is needed.

---

## Step 3 — Activate the plugin

Either:

```bash
# Via WP-CLI:
wp plugin activate opensalestax-for-woocommerce
```

Or in the browser: **WP Admin → Plugins → Installed Plugins → "OpenSalesTax for WooCommerce" → Activate**.

If activation fails:

- **"Requires PHP 8.2 or newer"** — upgrade PHP. The plugin uses class-level `readonly` syntax (PHP 8.2+).
- **"WooCommerce is not active"** — activate WooCommerce first.

---

## Step 4 — Configure the plugin

Navigate to **WP Admin → WooCommerce → Settings → Tax → OpenSalesTax** (it's a subtab on the Tax settings page).

You'll see five settings:

| Setting | What to enter |
|---|---|
| **Engine base URL** | The HTTP URL of your OpenSalesTax engine, e.g. `http://10.32.161.126:8080`. **Required.** Leave the path off — the plugin appends `/v1/...` automatically. |
| **API key** | Optional. Set this only if your engine has API-key auth enabled (most self-hosted setups don't). Sent as the `X-API-Key` header. |
| **Cache TTL (minutes)** | How long to cache tax calculations. Default `60`. Set to `0` to disable caching (handy when troubleshooting). |
| **Error fallback** | What happens if the engine is unreachable. **`block`** (recommended) returns no tax line — checkout proceeds without tax until the engine is reachable. **`zero`** charges $0 tax + logs the failure. Choose based on your compliance posture. |
| **Calculation-only disclaimer** | Static text. Reminds you that the merchant remits taxes, not OpenSalesTax. |

Click **Save changes**.

You can also do this entirely from WP-CLI:

```bash
wp option update opensalestax_base_url "http://10.32.161.126:8080"
wp option update opensalestax_cache_ttl_minutes "60"
wp option update opensalestax_error_fallback "block"
```

---

## Step 5 — Verify the engine connection

On the settings page, click the **Test connection** button. You should see:

```
✓ Engine v0.35.0 is ok — DB up
```

If you see `✗ Network error contacting OpenSalesTax engine at ...`, the WP server can't reach the engine. Check:

- Firewall rules between WP and the engine
- That the engine is actually running (`curl http://your-engine:8080/v1/health` from the WP server)
- DNS / hostname resolution if you're using a hostname instead of IP

Programmatic verification via WP-CLI:

```bash
wp eval '
require ABSPATH . "wp-content/plugins/opensalestax-for-woocommerce/vendor/autoload.php";
$client = (new OpenSalesTax\WooCommerce\ClientFactory())->build();
$h = $client->health();
echo "status=" . $h->status . " version=" . $h->version . PHP_EOL;
'
# → status=ok version=0.35.0
```

---

## Step 6 — Verify a real cart computes tax correctly

Two ways to verify, depending on what's set up.

### Quick verification — programmatic cart simulation

If you have shell access to the WP server, run:

```bash
wp eval-file wp-content/plugins/opensalestax-for-woocommerce/test-cart-simulation.php
```

> **Note:** the test-cart-simulation.php file isn't shipped in production releases (it's `.gitignore`d). Copy it manually from the dev branch if you want to use it for verification, or follow the browser-based flow below.

You should see:

```
Using existing test product id=11
Customer shipping ZIP set to 55401 (Minneapolis MN)

=== Cart totals ===
  Subtotal: $100
  Total tax: $9.03
  Tax lines (0):

[PASS] OpenSalesTax computed $9.03 on a $100 Minneapolis MN cart.
```

The total tax should be the engine's correct combined rate for ZIP 55401 (state + Hennepin County + Minneapolis + transit districts).

### Browser-based verification

1. **Create a test product** (any taxable product, $100 is convenient): WP Admin → Products → Add New, set Regular price = `100.00`, Tax status = Taxable.
2. **Open the storefront** and add the product to your cart.
3. **At checkout**, set the shipping address:
   - Country: United States
   - State: Minnesota
   - ZIP: 55401
4. **Observe the tax line** in the order summary. It should show ~$9.03 (give or take a few cents depending on the engine's current rate data).

If you see no tax line at all:

- WC's tax calculation may not be enabled. Check **WC → Settings → General → Enable taxes**. After enabling, you'll see a Tax tab.
- A placeholder tax-rate row may need to exist in `wp_woocommerce_tax_rates` for WC to fire its tax-calculation flow. Run:

```bash
wp db query "INSERT INTO wp_woocommerce_tax_rates (tax_rate_country, tax_rate_state, tax_rate, tax_rate_name, tax_rate_priority, tax_rate_compound, tax_rate_shipping, tax_rate_order, tax_rate_class) VALUES ('US', '', '0.0000', 'OpenSalesTax (placeholder)', 1, 0, 0, 1, '')"
```

This inserts a single zero-rate placeholder for the US so WC's tax loop runs. The plugin then takes over the actual calculation via the `woocommerce_calc_tax` filter.

> **Tax-line breakdown** — `WC_Cart::get_tax_totals()` resolves the OpenSalesTax line via the placeholder rate row registered on activation (v0.1.1+), so the per-line breakdown displays "OpenSalesTax" correctly.

---

## Step 7 — Configure WooCommerce to actually charge tax

Make sure WC is configured to compute taxes at checkout:

```bash
wp option update woocommerce_calc_taxes "yes"
wp option update woocommerce_tax_based_on "shipping"   # or 'billing' / 'base'
wp option update woocommerce_default_country "US:MN"
```

Or in the browser: **WC → Settings → General → "Enable taxes"** checkbox.

`woocommerce_tax_based_on` controls which address the plugin reads:

- `shipping` (default) — read from the customer's shipping address
- `billing` — read from billing address
- `base` — read from the store's own base address (rarely what you want)

---

## Step 8 — (Optional) Map custom WC tax classes to OST categories

WooCommerce ships three default tax classes — Standard, Reduced rate, and Zero rate. Many shops also define custom classes like "Clothing", "Groceries", or "Gift cards." OpenSalesTax cares about the *category* (which is what drives state-level exemptions like MN's clothing exemption or grocery rules in many states), so the plugin needs to know which OST category each WC class corresponds to.

**Defaults (no configuration needed):**

| WC tax-class slug | OST category   |
|-------------------|----------------|
| `''` / `standard` | `general`      |
| `reduced-rate`    | `general`      |
| `zero-rate`       | (skip — non-taxable) |

**To map a custom class — admin UI (v0.3.3+):**

Open **WC > Settings > Tax > OpenSalesTax** and scroll to "Tax class → OST category mapping". Each WC tax class shows a dropdown of the 6 OST categories plus "Skip (non-taxable)". Save with the WC settings page's standard "Save changes" button. A "Reset all to defaults" checkbox clears every override at once.

**Or via WP-CLI:**

```bash
# See the current effective mapping
wp opensalestax tax-class-list

# Map "Clothing" WC class to OST clothing category (so MN clothing exemption applies)
wp opensalestax tax-class-set clothing clothing

# Map a "Groceries" class to groceries
wp opensalestax tax-class-set groceries groceries

# Mark a custom "Gift cards" class as non-taxable
wp opensalestax tax-class-set gift-cards ''

# Reset back to built-in defaults
wp opensalestax tax-class-reset
```

Valid OST categories: `general`, `clothing`, `groceries`, `prescription_drugs`, `prepared_food`, `digital_goods`. Unknown categories are rejected with a helpful error.

**How to know which category to pick** — look at the OST engine's `category` documentation; pick the one whose state-level rules match how your shop wants the line treated. When in doubt, leave it `general` (it's the safe default for most goods).

> **Why this matters** — without a custom mapping, every WC class gets `general`, which over-collects in states with category-specific exemptions. A MN shop selling clothing would charge sales tax on a $100 shirt that should be $0.

---

## Step 9 — Watch transactions go through

After making your first real-money sale (or test-mode):

```bash
# List recent orders + their tax totals
wp post list --post_type=shop_order --posts_per_page=5 --fields=ID,post_status,post_date

# Inspect a specific order
wp wc order get <order_id> --user=admin --fields=id,total,total_tax,billing,shipping
```

The order's `total_tax` field should match what the engine returned for that customer's address.

### WooCommerce Subscriptions integration (v0.4+)

If you're running [WooCommerce Subscriptions](https://woocommerce.com/products/woocommerce-subscriptions/), the plugin auto-detects it and recalculates tax on every renewal order — destination rates change over time (state law updates, new transit districts, customer relocation), and the default WC Subscriptions behavior is to *copy* the parent sub's original tax line forward. That's wrong if rates changed.

The plugin's `SubscriptionsBridge` hooks `wcs_renewal_order_created` and forces a fresh calculation against today's rates. The renewal order's admin page also gets the per-jurisdiction breakdown panel just like a regular order.

**Verifying it's active:**

```bash
wp eval 'echo OpenSalesTax\WooCommerce\SubscriptionsBridge::isSubscriptionsActive() ? "active" : "inactive";'
```

If you want to disable the auto-recalc and stick with the inherited tax (e.g., for testing), remove the action manually:

```php
remove_action('wcs_renewal_order_created', [opensalestax_subscriptions_bridge_instance(), 'recalcRenewalTax'], 20);
```

(There's no UI toggle; this is a power-user override.)

### Per-order jurisdiction breakdown (v0.3+)

Open any order in the WC admin (`wp-admin/admin.php?page=wc-orders&action=edit&id=<order_id>`). Below the order details you'll see an **OpenSalesTax breakdown** panel showing the engine's full state / county / city / district split — what each jurisdiction's rate was and how many cents went to each. Useful for audit reconciliation.

The breakdown is also accessible programmatically:

```bash
# Inspect the structured breakdown for accounting integrations
wp eval '$o = wc_get_order(123); print_r(OpenSalesTax\WooCommerce\OrderTaxBreakdown::get($o));'

# Or pull the raw JSON directly from order meta
wp wc order get 123 --user=admin --field=meta_data | grep _opensalestax_breakdown
```

---

## Troubleshooting

### Tax is $0 on every order

Most common cause: **WC tax calculation is disabled.** Check:

```bash
wp option get woocommerce_calc_taxes
# Should output: yes
```

Second-most common: **no rows in `wp_woocommerce_tax_rates`.** WC won't fire `woocommerce_calc_tax` if no rates match the cart. Add the placeholder row from Step 6.

### Tax is the wrong amount

- Verify the customer's shipping ZIP is correct (`wp wc order get <id>` → `shipping.postcode`)
- Verify the engine version (`wp eval '...$client->health()...'`) — engine bug fixes can change rates between minor versions
- Check the cache: `wp option delete _transient_ostax_<sha>` to flush a stale entry, or set `opensalestax_cache_ttl_minutes` to `0` temporarily

### "Engine unreachable" errors in the log

```bash
sudo tail -50 /var/log/apache2/error.log | grep opensalestax
# or
sudo tail -50 /var/log/php_errors.log | grep opensalestax
```

If you see `OpenSalesTaxNetworkException`:

- Check the engine is up: `curl -m 5 http://your-engine:8080/v1/health`
- Check WP can reach the engine (firewall / DNS)
- Check `wp option get opensalestax_base_url` is correct (no trailing slash, includes the port)

### Tax-exempt customer is still being charged tax

The plugin honors `WC()->customer->is_vat_exempt()`. If a tax-exempt customer is being charged, verify:

```bash
wp eval 'var_dump(WC()->customer->is_vat_exempt());'
```

The flag is set per-customer via the WC admin UI or programmatically. If it's `false`, the customer isn't actually marked exempt.

---

## Uninstall

Cleanly removes the plugin's tax integration:

```bash
wp plugin deactivate opensalestax-for-woocommerce
wp option delete opensalestax_base_url
wp option delete opensalestax_api_key
wp option delete opensalestax_cache_ttl_minutes
wp option delete opensalestax_error_fallback
wp option delete opensalestax_tax_class_map
wp option delete opensalestax_allow_private_nets

# Optionally remove per-order breakdown meta from existing orders:
wp db query "DELETE FROM wp_postmeta WHERE meta_key = '_opensalestax_breakdown'"
wp db query "DELETE FROM wp_wc_orders_meta WHERE meta_key = '_opensalestax_breakdown'"
```

Plugin deactivation also flushes the OpenSalesTax transient cache. To remove the placeholder tax rate too:

```bash
wp db query "DELETE FROM wp_woocommerce_tax_rates WHERE tax_rate_name = 'OpenSalesTax (placeholder)'"
```

WC's standard tax calculation (using whatever's left in `wp_woocommerce_tax_rates`) takes over.

---

## Disclaimer

> Tax calculations are provided as-is for convenience. The merchant is solely responsible for tax-collection accuracy and remittance to the appropriate jurisdictions. Verify against your state Department of Revenue before remitting.
