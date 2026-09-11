import { after, before, beforeEach, test } from 'node:test';
import assert from 'node:assert/strict';
import { readFile } from 'node:fs/promises';
import { chromium } from '@playwright/test';

let browser;
let page;
const script = (await readFile(new URL('../../resources/js/app.js', import.meta.url), 'utf8')).replace("import './bootstrap';", '');

before(async () => { browser = await chromium.launch({ headless: true }); });
after(async () => { await browser?.close(); });
beforeEach(async () => {
    await page?.close();
    page = await browser.newPage();
    await page.route('**/*', route => route.abort());
    await page.setContent(`<body data-page="test">
        <div id="metric"></div><table id="campaign_table"><tbody></tbody></table>
        <table id="automation_table"><tbody></tbody></table>
        <table id="interest_table"><tbody></tbody></table><table id="product_table"><tbody></tbody></table>
        <span id="total_ad_spend"></span><span id="total_ad_result"></span><span id="avg_ad_cpr"></span>
        <div id="item-timeline"></div><div id="history-modal" class="modal" hidden></div>
        <div id="product-detail"></div><div id="product-modal" class="modal" hidden></div>
        <div id="automation-modal" class="modal" hidden><input value="unsaved edit"></div>
    </body>`);
    await page.addScriptTag({ content: script });
});

test('dynamic names, attributes, logs and product links cannot inject markup or scripts', async () => {
    const attack = `"'><img src=x onerror="window.xss=1">&quot;`;
    await page.evaluate(async attack => {
        renderCampaignTable([{ campaign_id: attack, campaign_name: attack, level: attack }]);
        renderAutomationTable([{ id: attack, campaign_name: attack, ad_account: attack, log: attack, conversion: attack }]);
        renderInterest([{ name: attack, topic: attack }]);
        renderProducts([{ name: attack, category: attack, rating: attack, image: 'javascript:alert(1)', detail_url: 'javascript:alert(1)' }]);
        window.fetch = async () => ({ ok: true, json: async () => ({ data: [{ time: attack, text: [attack] }] }) });
        await historyTask('test');
    }, attack);
    assert.equal(await page.locator('#campaign_table .campaign-name').textContent(), attack);
    assert.equal(await page.locator('#automation_table td').nth(1).textContent(), attack);
    assert.equal(await page.locator('#item-timeline li').textContent(), attack);
    assert.equal(await page.locator('#interest_table tr').getAttribute('data-topic'), attack);
    await page.locator('[data-product-detail]').click();
    assert.equal(await page.locator('#product-detail h3').textContent(), attack);
    assert.equal(await page.locator('#product-detail a').getAttribute('href'), '');
    assert.equal(await page.locator('[onerror]').count(), 0);
    assert.equal(await page.evaluate(() => window.xss), undefined);
});

test('polling reads only local tasks, does not overlap, and preserves open edits', async () => {
    await page.evaluate(() => {
        window.timers = [];
        window.setTimeout = (callback, delay) => { window.timers.push({ callback, delay }); };
        window.calls = [];
        window.fetch = async url => {
            window.calls.push(url);
            return new Promise(resolve => { window.resolveRequest = () => resolve({ ok: true, json: async () => ({ data: [] }) }); });
        };
        startAutomationPolling();
    });
    assert.equal(await page.evaluate(() => window.timers[0].delay), 45000);
    await page.evaluate(() => { window.poll = window.timers.shift().callback(); });
    await page.evaluate(() => { window.second = loadAutomationTasks(true); });
    assert.equal(await page.evaluate(() => window.calls.length), 1);
    await page.evaluate(async () => { window.resolveRequest(); await Promise.all([window.poll, window.second]); });
    await page.evaluate(async () => {
        document.querySelector('#automation-modal').hidden = false;
        await window.timers.shift().callback();
    });
    assert.equal(await page.locator('#automation-modal input').inputValue(), 'unsaved edit');
    assert.equal(await page.evaluate(() => window.calls.length), 1);
    await page.evaluate(async () => {
        document.querySelector('#automation-modal').hidden = true;
        Object.defineProperty(document, 'hidden', { configurable: true, value: true });
        await window.timers.shift().callback();
    });
    assert.equal(await page.evaluate(() => window.calls.length), 1);
    assert.ok((await page.evaluate(() => window.calls)).every(url => url.startsWith('/get-automation-task/')));
});

test('failed polling retries quietly and recovers', async () => {
    await page.evaluate(async () => {
        window.timers = [];
        window.setTimeout = (callback, delay) => { window.timers.push({ callback, delay }); };
        window.fetch = async () => { throw new Error('offline'); };
        startAutomationPolling();
        await window.timers.shift().callback();
    });
    assert.equal(await page.locator('.toast-lite').count(), 0);
    assert.equal(await page.evaluate(() => window.timers[0].delay), 45000);
    await page.evaluate(async () => {
        window.fetch = async () => ({ ok: true, json: async () => ({ data: [] }) });
        await window.timers.shift().callback();
    });
    assert.equal(await page.locator('#total_ad_result').textContent(), '0');
});
