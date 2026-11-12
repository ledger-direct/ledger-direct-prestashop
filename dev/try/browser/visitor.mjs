// Walks the try shop in a real browser the way a prospect does: home page, add a miniature to the
// cart, guest checkout, a LedgerDirect payment option, place order, payment page in state "waiting".
// A screenshot of every step goes to the output folder; exits non-zero on the first failure.
//
//   npm install
//   CHROME=/path/to/chrome node visitor.mjs [base url] [screenshot dir] [XRP|RLUSD|USDC]
import puppeteer from 'puppeteer-core';
import { readFileSync } from 'node:fs';
// The article to buy: the first miniature of the catalogue, found on the home page by its URL key —
// never "the first product", which changes with the catalogue.
const PRODUCT = JSON.parse(readFileSync(new URL('../catalog/catalog.json', import.meta.url))).products[0].slug;
const BASE = process.argv[2] ?? 'http://localhost:8089';
const OUT = process.argv[3] ?? '.';
const ASSET = process.argv[4] ?? 'XRP';
const browser = await puppeteer.launch({
  executablePath: process.env.CHROME ?? '/Applications/Google Chrome.app/Contents/MacOS/Google Chrome',
  headless: 'new', args: ['--no-sandbox', '--window-size=1280,1000'], defaultViewport: { width: 1280, height: 1000 },
});
const page = await browser.newPage();
const errors = [];
page.on('pageerror', (e) => errors.push(e.message));
page.on('console', (m) => { if (m.type() === 'error') errors.push(m.text()); });
const step = async (name, fn) => {
  const t = Date.now();
  try { await fn(); } catch (e) {
    await page.screenshot({ path: `${OUT}/fail-${ASSET}-${name}.png`, fullPage: true });
    console.log(`FAIL  ${name}: ${e.message}`); console.log('console errors:', errors.slice(-5)); await browser.close(); process.exit(1);
  }
  await page.screenshot({ path: `${OUT}/${ASSET}-${name}.png`, fullPage: true });
  console.log(`ok    ${name} (${((Date.now() - t) / 1000).toFixed(1)} s)`);
};
const pause = (ms) => new Promise((r) => setTimeout(r, ms));
// The checkout reloads its steps by XHR behind a spinner; a click while it runs is lost.
const settled = () => page.waitForFunction(
  () => ![...document.querySelectorAll('.spinner-border, .page-loader, .js-loading, .loading')].some((e) => e.offsetParent),
  { timeout: 30000 },
);
const fill = async (selector, value) => {
  await page.waitForSelector(selector, { visible: true, timeout: 30000 });
  await page.$eval(selector, (e) => { e.value = ''; });
  await page.type(selector, value);
};
const clickVisible = async (selector) => {
  await page.waitForSelector(selector, { visible: true, timeout: 30000 });
  await settled();
  await page.click(selector);
};

const productLink = `.product-miniature a[href*="${PRODUCT}"]`;
await step('1-home', async () => {
  await page.goto(`${BASE}/`, { waitUntil: 'networkidle0', timeout: 60000 });
  await page.waitForSelector(productLink, { timeout: 20000 });
});
await step('2-add-to-cart', async () => {
  // The product page's own add-to-cart button: the listing's quick add differs between themes.
  await Promise.all([page.waitForNavigation({ timeout: 60000 }), page.click(productLink)]);
  await clickVisible('[data-button-action="add-to-cart"]');
  await page.waitForFunction(() => /[1-9]/.test(document.querySelector('.cart-products-count, .header-block__badge, .blockcart .header-block__badge')?.textContent ?? ''), { timeout: 30000 });
});
await step('3-checkout-personal', async () => {
  await page.goto(`${BASE}/order`, { waitUntil: 'networkidle0', timeout: 60000 });
  const form = '#customer-form';
  await fill(`${form} [name="firstname"]`, 'Try');
  await fill(`${form} [name="lastname"]`, 'Visitor');
  await fill(`${form} [name="email"]`, 'visitor@example.invalid');
  for (const box of ['psgdpr', 'customer_privacy']) {
    const input = await page.$(`${form} input[name="${box}"]`);
    if (input && !(await input.evaluate((e) => e.checked))) await input.click();
  }
  await clickVisible(`${form} button[name="continue"]`);
});
await step('4-checkout-address', async () => {
  const form = '.js-address-form form, #delivery-address';
  await page.waitForSelector(`${form} [name="address1"]`, { visible: true, timeout: 60000 });
  await page.select('[name="id_country"]', '1'); // Germany
  await settled(); await pause(1000);
  await fill('[name="address1"]', '1 Testnet Way');
  await fill('[name="postcode"]', '10115');
  await fill('[name="city"]', 'Berlin');
  const phone = await page.$('[name="phone"]');
  if (phone) await fill('[name="phone"]', '0301234567');
  await clickVisible('button[name="confirm-addresses"]');
});
await step('5-checkout-delivery', async () => {
  await clickVisible('button[name="confirmDeliveryOption"]');
});
await step('6-payment-option', async () => {
  // The LedgerDirect options share the module; the label names the asset.
  await page.waitForSelector('input[name="payment-option"]', { timeout: 60000 });
  await settled();
  const labels = await page.$$eval('.payment-option label, [id^="payment-option-"][id$="-container"] label', (els) => els.map((e) => e.textContent.trim().replace(/\s+/g, ' ')));
  console.log('      payment options:', labels.filter(Boolean).join(' | '));
  const id = await page.evaluate((asset) => {
    const wanted = new RegExp(`\\b${asset}\\b`);
    for (const input of document.querySelectorAll('input[name="payment-option"]')) {
      const label = document.querySelector(`label[for="${input.id}"]`);
      if (label && wanted.test(label.textContent)) return input.id;
    }
    return null;
  }, ASSET);
  if (!id) throw new Error(`no payment option for ${ASSET}`);
  await page.click(`label[for="${id}"]`);
  const terms = await page.$('input[name^="conditions_to_approve"]');
  if (terms && !(await terms.evaluate((e) => e.checked))) await terms.click();
});
await step('7-place-order', async () => {
  const button = '#payment-confirmation button, .js-payment-confirmation button';
  await page.waitForFunction((s) => { const b = document.querySelector(s); return b && !b.disabled; }, { timeout: 20000 }, button);
  await settled(); await pause(500);
  await Promise.all([page.waitForNavigation({ timeout: 60000 }), page.click(button)]);
  await page.waitForFunction(() => location.href.includes('/module/ledgerdirect/payment'), { timeout: 60000 });
  await page.waitForSelector('[data-ld-page]', { timeout: 30000 });
});
const view = await page.evaluate(() => ({
  url: location.href,
  state: document.querySelector('[data-ld-page]')?.getAttribute('data-ld-state'),
  amount: document.querySelector('[data-ld-page]')?.getAttribute('data-ld-amount-requested'),
  asset: document.querySelector('[data-ld-page]')?.getAttribute('data-ld-asset'),
}));
console.log('      payment page:', JSON.stringify(view));
if (errors.length) console.log('      console errors:', errors.slice(0, 5));
await browser.close();
if (view.state !== 'waiting' || !view.amount || view.asset !== ASSET || !view.url.includes('key=')) {
  console.log('FAIL  the payment page is not the expected one');
  process.exit(1);
}
