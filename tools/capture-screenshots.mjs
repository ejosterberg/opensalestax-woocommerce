// SPDX-License-Identifier: Apache-2.0
//
// One-shot capture of WP-org screenshots for the OpenSalesTax for
// WooCommerce plugin. Run from the captain workstation against the
// wp-woocommerce-test VM (10.32.161.9). Not shipped in the WP-org
// release ZIP — kept in the repo for reproducibility.
//
// Usage:
//   node tools/capture-screenshots.mjs
//
// Output: assets/screenshot-1.png .. screenshot-4.png

import { chromium } from 'playwright';
import fs from 'node:fs';
import path from 'node:path';

const BASE = process.env.WP_BASE || 'http://10.32.161.9';
const USER = process.env.WP_USER || 'admin';
const PASS = process.env.WP_PASS || 'WpScreenshot2026!';
const OUT = path.resolve(import.meta.dirname, '..', 'assets');
fs.mkdirSync(OUT, { recursive: true });

async function login(page) {
  await page.goto(`${BASE}/wp-login.php`, { waitUntil: 'domcontentloaded' });
  await page.fill('#user_login', USER);
  await page.fill('#user_pass', PASS);
  await page.click('#wp-submit');
  // Wait for either wp-admin OR an error to render.
  await page.waitForLoadState('networkidle', { timeout: 30000 });
  const url = page.url();
  if (!/wp-admin/.test(url)) {
    const err = await page.locator('#login_error').textContent().catch(() => '');
    throw new Error(`login failed; landed at ${url}; error=${err}`);
  }
}

async function shot(page, name, opts = {}) {
  const file = path.join(OUT, name);
  await page.screenshot({ path: file, fullPage: opts.full ?? false });
  console.log(`Saved ${file}`);
}

(async () => {
  const browser = await chromium.launch({ headless: true });
  const ctx = await browser.newContext({
    viewport: { width: 1440, height: 900 },
    deviceScaleFactor: 1,
  });
  const page = await ctx.newPage();

  await login(page);

  // ---- Screenshot 1: settings page (top — engine URL through Test connection)
  // Hide the WC "store coming soon" + "doing great" banners and the
  // disclaimer notice so the form starts higher up the page.
  await page.goto(
    `${BASE}/wp-admin/admin.php?page=wc-settings&tab=tax&section=opensalestax`,
    { waitUntil: 'networkidle' }
  );
  await page.waitForSelector('input[name="opensalestax_base_url"]', { timeout: 15000 });
  await page.addStyleTag({ content: `
    .woocommerce-store-alert, #wp-admin-bar-revisions, .wp-pointer,
    .notice-info, .notice-warning, .updated.notice, .woocommerce-layout__notice-list-hide
      { display: none !important; }
    /* Compress the disclaimer block so the form fits in one viewport. */
    #mainform .opensalestax-disclaimer { display: none !important; }
  `});
  // Use a taller viewport just for the settings shot so we get the whole
  // strategy form (engine URL through Test Connection) in one frame.
  await page.setViewportSize({ width: 1440, height: 1300 });
  await page.evaluate(() => window.scrollTo(0, 0));
  await page.waitForTimeout(400);
  await shot(page, 'screenshot-1.png');
  // Restore standard viewport for the rest.
  await page.setViewportSize({ width: 1440, height: 900 });

  // ---- Screenshot 2: tax-class mapping table further down ----
  await page.evaluate(() => {
    const headings = Array.from(document.querySelectorAll('h2, h3, .titledesc, table.opensalestax-class-map caption, table.opensalestax-class-map, table caption'));
    const target = headings.find((h) => /tax class|OST category|WC tax class/i.test(h.textContent || ''));
    if (target) {
      const top = target.getBoundingClientRect().top + window.scrollY - 24;
      window.scrollTo({ top, behavior: 'instant' });
    } else {
      window.scrollTo(0, document.body.scrollHeight * 0.55);
    }
  });
  await page.waitForTimeout(500);
  await shot(page, 'screenshot-2.png');

  // ---- Screenshot 3: dashboard widget on WP admin home ----
  await page.goto(`${BASE}/wp-admin/index.php`, { waitUntil: 'networkidle' });
  // Try to scroll the OpenSalesTax dashboard widget into view.
  await page.waitForTimeout(800);
  const widgetFound = await page.evaluate(() => {
    const headings = Array.from(document.querySelectorAll('.postbox h2, .postbox .hndle'));
    const target = headings.find((h) => /OpenSalesTax/i.test(h.textContent || ''));
    if (target) {
      const box = target.closest('.postbox');
      if (box) {
        box.scrollIntoView({ block: 'center' });
        return true;
      }
    }
    return false;
  });
  await page.waitForTimeout(400);
  if (!widgetFound) {
    console.warn('WARN: Dashboard widget not found; capturing dashboard as-is.');
  }
  await shot(page, 'screenshot-3.png');

  // ---- Screenshot 4: recent-calculations debug log (cropped tight) ----
  // Use a tall viewport so the whole settings page renders, then clip
  // around the "Recent calculations" heading + table for a focused shot.
  await page.setViewportSize({ width: 1440, height: 1800 });
  await page.goto(
    `${BASE}/wp-admin/admin.php?page=wc-settings&tab=tax&section=opensalestax`,
    { waitUntil: 'networkidle' }
  );
  await page.addStyleTag({ content: `
    .woocommerce-store-alert, #wp-admin-bar-revisions, .wp-pointer,
    .notice-info, .notice-warning, .updated.notice, .woocommerce-layout__notice-list-hide
      { display: none !important; }
  `});
  await page.evaluate(() => window.scrollTo(0, 0));
  await page.waitForTimeout(600);
  const clip = await page.evaluate(() => {
    const headings = Array.from(document.querySelectorAll('h2, h3, .titledesc'));
    const target = headings.find((h) => /Recent calculations/i.test(h.textContent || ''));
    if (!target) return null;
    let el = target;
    let table = null;
    while (el && (el = el.nextElementSibling)) {
      if (el.tagName === 'TABLE') { table = el; break; }
      const inner = el.querySelector?.('table');
      if (inner) { table = inner; break; }
    }
    const rect = target.getBoundingClientRect();
    const tableRect = (table || target).getBoundingClientRect();
    const x = Math.max(0, rect.left - 60);
    const y = Math.max(0, rect.top + window.scrollY - 32);
    const right = Math.max(rect.right, tableRect.right) + 80;
    const bottom = Math.max(rect.bottom, tableRect.bottom) + window.scrollY + 40;
    const width = Math.max(1320, right - x);
    return { x, y, width, height: bottom - y };
  });
  if (clip && clip.width > 0 && clip.height > 0) {
    await page.screenshot({ path: path.join(OUT, 'screenshot-4.png'), clip, fullPage: true });
    console.log(`Saved ${path.join(OUT, 'screenshot-4.png')} (clipped ${Math.round(clip.width)}x${Math.round(clip.height)})`);
  } else {
    await shot(page, 'screenshot-4.png', { full: true });
  }

  await browser.close();
})().catch((e) => {
  console.error(e);
  process.exit(1);
});
