/**
 * The public forms, in a real browser, logged out.
 *
 * Opens the SugarCommerce Playground's demo page (`/?sc-playground-form=1`,
 * an order form sent over REST with the unsaved-changes guard on and a
 * review form that posts and redirects) at 1440 and 390 pixels, and checks
 * what the PHP suite cannot: the refusals listed and focused, a summary link
 * reaching its field, a slug following the name and refusing a taken one,
 * an address relaying itself for a new country and hinting at a bad ZIP
 * code, a location found and mapped, a video previewed, changed fields
 * marked and unmarked once sent, a soft limit warning, an order accepted
 * without a reload, a rating chosen by pointer and by arrow key, and a
 * review accepted and redirected.
 *
 * Usage, with the playground active and puppeteer-core installed:
 *
 *   node tests/browser/front-form.js [https://site]
 *
 * Set CHROME to the browser to drive. Exits non-zero on any failed check
 * or page error.
 */

'use strict';

const puppeteer = require('puppeteer-core');
const [ , , site ] = process.argv;
const URL_ = String( site || 'https://freshlysqueezedsamples.local' ).replace( /\/$/, '' ) + '/?sc-playground-form=1';
const wait = (ms) => new Promise((r) => setTimeout(r, ms));
const fails = [];
const check = (ok, what) => { console.log((ok ? 'ok   ' : 'FAIL ') + what); if (!ok) fails.push(what); };
(async () => {
	const browser = await puppeteer.launch({ executablePath: process.env.CHROME || '/Applications/Google Chrome.app/Contents/MacOS/Google Chrome', headless: 'new', acceptInsecureCerts: true, args: ['--ignore-certificate-errors'] });
	const page = await browser.newPage();
	const errs = [];
	page.on('pageerror', (e) => errs.push(e.message));
	page.on('console', (m) => { if (m.type() === 'error' && !/422|status of 4/.test(m.text())) errs.push(m.text()); });
	page.on('dialog', (d) => { errs.push('dialog ' + d.type()); d.accept(); });
	for (const w of [1440, 390]) {
		await page.setViewport({ width: w, height: 900 });
		await page.goto(URL_, { waitUntil: 'networkidle2' });
		const O = '#sc_playground_order';
		// Empty REST submit -> summary + field errors, no reload.
		await page.$eval(O, (f) => { f.noValidate = true; });
		await page.click(O + ' .sugarcommerce-form__submit');
		await page.waitForSelector(O + ' .sugarcommerce-form__notice--error', { timeout: 10000 });
		const n = await page.$$eval(O + ' .sugarcommerce-form__notice li', (l) => l.length);
		check(n >= 3, w + ' empty order lists refusals (' + n + ')');
		check(await page.$eval(O + ' #sc_playground_order_email_', (e) => e.getAttribute('aria-invalid') === 'true'), w + ' email marked invalid');
		check(await page.evaluate(() => document.activeElement.classList.contains('sugarcommerce-form__notice')), w + ' focus on summary');
		// Summary link focuses its field.
		await page.click(O + ' .sugarcommerce-form__notice a[data-field="email"]');
		check(await page.evaluate(() => document.activeElement.id === 'sc_playground_order_email_'), w + ' summary link focuses field');
		// Fill.
		await page.type(O + ' #sc_playground_order_name_', 'Ada Lovelace');
		check(await page.$eval(O + ' #sc_playground_order_handle_', (e) => e.value) === 'ada-lovelace', w + ' slug follows the name');
		await page.$eval(O + ' #sc_playground_order_handle_', (e) => { e.value = ''; });
		await page.type(O + ' #sc_playground_order_handle_', 'shop');
		await page.waitForFunction(() => /in use/.test(document.querySelector('#sc_playground_order .field-kit__slug-status').textContent), { timeout: 8000 }).catch(() => {});
		check(/in use/.test(await page.$eval(O + ' .field-kit__slug-status', (e) => e.textContent)), w + ' taken slug says so');
		await page.$eval(O + ' #sc_playground_order_handle_', (e) => { e.value = ''; });
		await page.type(O + ' #sc_playground_order_handle_', 'ada-gift');
		await page.type(O + ' #sc_playground_order_email_', 'ada@example.com');
		// Physical -> address appears; change country to US -> state select + ZIP label.
		await page.click(O + ' [data-field-key="format"] .field-kit__card:nth-child(2)');
		await wait(300);
		check(await page.$eval(O + ' [data-field-key="address"]', (e) => !e.hidden), w + ' address shown by condition');
		check(/Postcode/.test(await page.$eval(O + ' label[for="sc_playground_order_address__postcode"]', (e) => e.textContent)), w + ' GB says Postcode');
		await page.select(O + ' #sc_playground_order_address__country', 'US');
		await page.waitForFunction(() => /ZIP/.test(document.querySelector('label[for="sc_playground_order_address__postcode"]').textContent), { timeout: 8000 }).catch(() => {});
		check(/ZIP/.test(await page.$eval(O + ' label[for="sc_playground_order_address__postcode"]', (e) => e.textContent)), w + ' US says ZIP code');
		check(await page.$eval(O + ' .field-kit__address-state-select', (e) => !e.disabled && !e.hidden && e.options.length > 50), w + ' US has a state list');
		const line = await page.$eval(O + ' [data-part="city"]', (e) => [...e.parentNode.children].map((c) => c.dataset.part).join(','));
		check(line === 'city,state,postcode', w + ' US puts city, state, ZIP on one line (' + line + ')');
		await page.type(O + ' #sc_playground_order_address__line1', '1 Infinite Loop');
		await page.type(O + ' #sc_playground_order_address__city', 'Cupertino');
		await page.select(O + ' .field-kit__address-state-select', 'CA');
		await page.type(O + ' #sc_playground_order_address__postcode', '9501');
		await page.click(O + ' #sc_playground_order_address__line1');
		check(/ZIP code/i.test(await page.$eval(O + ' .field-kit__address-hint', (e) => e.textContent)), w + ' bad ZIP hinted');
		await page.type(O + ' #sc_playground_order_address__postcode', '4');
		await page.click(O + ' #sc_playground_order_address__line1');
		check('' === await page.$eval(O + ' .field-kit__address-hint', (e) => e.textContent), w + ' good ZIP clears hint');
		await page.type(O + ' #sc_playground_order_weight_', '2.5');
		// Location find.
		await page.type(O + ' #sc_playground_order_pickup_', 'Leeds');
		await page.click(O + ' .field-kit__location-find');
		await page.waitForFunction(() => document.querySelector('#sc_playground_order .field-kit__location-lat').value !== '', { timeout: 8000 }).catch(() => {});
		check(await page.$eval(O + ' .field-kit__location-lat', (e) => e.value) === '53.8008', w + ' location found');
		check(await page.$eval(O + ' .field-kit__location-map', (e) => !e.hidden && /openstreetmap/.test(e.querySelector('iframe').src)), w + ' map shown');
		// Embed preview.
		await page.type(O + ' #sc_playground_order_video_', 'https://www.youtube.com/watch?v=dQw4w9WgXcQ');
		await page.waitForSelector(O + ' .field-kit__embed-preview iframe', { timeout: 8000 }).catch(() => {});
		check(!!await page.$(O + ' .field-kit__embed-preview iframe[src*="youtube-nocookie"]'), w + ' video preview');
		// Changed marks from the guard.
		check(await page.$$eval(O + ' .field-kit__field--changed', (l) => l.length) >= 4, w + ' guard marks changed fields');
		await page.type(O + ' #sc_playground_order_note_', 'Happy birthday from all of us at the lab, with love and a very long note indeed that goes on past the soft limit of one hundred and twenty.');
		check(await page.$eval(O + ' [data-field-key="note"] .field-kit__count', (e) => e.classList.contains('field-kit__count--soft')), w + ' soft limit warns');
		await page.click(O + ' #sc_playground_order_terms_');
		await page.$eval(O, (f) => { f.noValidate = false; });
		await page.click(O + ' .sugarcommerce-form__submit');
		await page.waitForSelector(O + ' .sugarcommerce-form__notice--success', { timeout: 10000 }).catch(() => {});
		const msg = await page.$eval(O + ' .sugarcommerce-form__notice', (e) => e.className + ' | ' + e.textContent);
		check(/success/.test(msg) && /Ada/.test(msg), w + ' order accepted: ' + msg.slice(0, 300));
		check(await page.$$eval(O + ' .field-kit__field--changed', (l) => l.length) === 0, w + ' guard reset after sending');
		// Review form: posts, PRG.
		const R = '#sc_playground_review';
		await page.click(R + ' .sugarcommerce-form__submit');
		await page.waitForNavigation({ waitUntil: 'networkidle2' }).catch(() => {});
		// Browser validation may block an empty submit; force a server one.
		await page.$eval(R, (f) => { f.noValidate = true; });
		await page.click(R + ' .sugarcommerce-form__submit');
		await page.waitForNavigation({ waitUntil: 'networkidle2' });
		check(!!await page.$(R + ' .sugarcommerce-form__notice--error'), w + ' review refused on the server');
		await page.click(R + ' label[data-value="4.5"]');
		check(await page.$eval(R + ' input[value="4.5"]', (e) => e.checked), w + ' half star chosen by click');
		await page.focus(R + ' input[value="4.5"]');
		await page.keyboard.press('ArrowLeft');
		check(await page.$eval(R + ' input[value="4"]', (e) => e.checked), w + ' arrow key moves rating');
		await page.type(R + ' #sc_playground_review_email_', 'r@example.com');
		await page.click(R + ' .sugarcommerce-form__submit');
		await page.waitForNavigation({ waitUntil: 'networkidle2' });
		check(/sc_playground_review=sent/.test(page.url()) && !!await page.$(R + ' .sugarcommerce-form__notice--success'), w + ' review accepted and redirected');
	}
	console.log(errs.length ? 'ERRORS:\n' + errs.join('\n') : 'no page errors');
	await browser.close();
	process.exit(fails.length || errs.length ? 1 : 0);
})();
