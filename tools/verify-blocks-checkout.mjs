// SPDX-License-Identifier: Apache-2.0
//
// Verify the FAQ claim that the plugin works through the WC Cart/Checkout
// Blocks (React-driven) flow. Drives a real product purchase as a guest
// and asserts that a tax line is rendered with a $ amount.
//
// Run from the captain workstation against wp-woocommerce-test.
//
// Output: pass/fail summary on stdout. Captures a debug screenshot on
// failure for inspection.

import { chromium } from 'playwright';

const BASE = process.env.WP_BASE || 'http://10.32.161.9';
const PRODUCT_ID = Number(process.env.WP_PRODUCT_ID || 11);

(async () => {
  const browser = await chromium.launch({ headless: true });
  const ctx = await browser.newContext({
    viewport: { width: 1280, height: 1400 },
  });
  const page = await ctx.newPage();

  console.log(`[1] Add product ${PRODUCT_ID} to cart…`);
  await page.goto(`${BASE}/?add-to-cart=${PRODUCT_ID}`, { waitUntil: 'networkidle' });
  // The "?add-to-cart=" form posts then redirects; sometimes the cart
  // session cookie only persists if we visit the product page directly.
  await page.goto(`${BASE}/?p=${PRODUCT_ID}`, { waitUntil: 'networkidle' });
  // Click the Add to Cart button (legacy single-product page).
  const addBtn = page.locator('button.single_add_to_cart_button, a.add_to_cart_button').first();
  if (await addBtn.count()) {
    await addBtn.click().catch(() => {});
    await page.waitForTimeout(1500);
  }

  console.log(`[2] Open Cart page (Blocks-based)…`);
  await page.goto(`${BASE}/cart/`, { waitUntil: 'networkidle' });
  await page.waitForTimeout(1500);

  // Confirm the Blocks cart actually rendered (not the legacy shortcode).
  const isBlocks = await page.locator('.wp-block-woocommerce-cart').count();
  if (!isBlocks) throw new Error('Cart page is not the Blocks variant');

  console.log(`[3] Go to Checkout (Blocks-based)…`);
  await page.goto(`${BASE}/checkout/`, { waitUntil: 'networkidle' });
  await page.waitForTimeout(2000);

  const isBlocksCheckout = await page.locator('.wp-block-woocommerce-checkout').count();
  if (!isBlocksCheckout) throw new Error('Checkout page is not the Blocks variant');

  // Wait for the React-driven form to actually mount (loading-skeleton →
  // real fields). The Blocks UI lazy-loads after the cart hydration.
  await page.waitForSelector('input[autocomplete="email"], #email, input[name="email"]', { timeout: 15000 }).catch(() => {});
  await page.waitForTimeout(2500);

  console.log(`[4] Fill guest checkout form…`);
  await page.fill('#email', 'block-checkout-test@example.com');
  // Default "ship to billing address" is on, so we only fill billing.
  await page.selectOption('#billing-country', { value: 'US' });
  await page.waitForTimeout(400);
  await page.fill('#billing-first_name', 'Test');
  await page.fill('#billing-last_name', 'Buyer');
  await page.fill('#billing-address_1', '375 Jackson St');
  await page.fill('#billing-city', 'St. Paul');
  await page.selectOption('#billing-state', { value: 'MN' });
  await page.fill('#billing-postcode', '55101');
  await page.fill('#billing-phone', '6125551212');

  // Click off the field to trigger Blocks to recalculate totals.
  await page.click('h2.wp-block-heading >> nth=0').catch(() => {});
  await page.waitForTimeout(4000);

  console.log(`[5] Look for the tax row in the Blocks order summary…`);
  // Blocks renders the totals into .wc-block-components-totals-item rows.
  // Tax lives in a row with the 'Tax' label.
  const taxRowText = await page.locator('.wc-block-components-totals-taxes, .wc-block-components-totals-item:has-text("Tax")').first().textContent({ timeout: 15000 }).catch(() => null);
  if (!taxRowText) {
    await page.screenshot({ path: 'C:/tmp/blocks-checkout-fail.png', fullPage: true });
    throw new Error('No Tax row found in Blocks checkout summary');
  }
  console.log(`[6] Tax row content: ${taxRowText.replace(/\s+/g, ' ').trim()}`);

  const hasDollarTax = /\$[0-9]+\.[0-9]{2}/.test(taxRowText);
  if (!hasDollarTax) {
    await page.screenshot({ path: 'C:/tmp/blocks-checkout-fail.png', fullPage: true });
    throw new Error(`Tax row exists but contains no $X.YY amount: ${taxRowText}`);
  }

  // Grab everything visible in the totals box for the report.
  const totals = await page.locator('.wc-block-components-totals-wrapper, .wc-block-components-sidebar-layout, [data-block-name*="checkout-totals"]').first().textContent().catch(() => '');
  console.log(`[7] Totals block (raw): ${totals?.replace(/\s+/g, ' ').trim().slice(0, 400)}`);

  console.log('PASS: WC Blocks checkout shows a tax line with a $ amount.');
  await browser.close();
})().catch(async (e) => {
  console.error('FAIL:', e.message);
  process.exit(1);
});
