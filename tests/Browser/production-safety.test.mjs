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
    assert.equal(await page.locator('#automation_table .campaign-name').textContent(), attack);
    assert.equal(await page.locator('#automation_table .log-cell .log-text').textContent(), attack);
    assert.equal(await page.locator('#item-timeline li').textContent(), attack);
    assert.equal(await page.locator('#interest_table tr').getAttribute('data-topic'), attack);
    await page.locator('[data-product-detail]').click();
    assert.equal(await page.locator('#product-detail h3').textContent(), attack);
    assert.equal(await page.locator('#product-detail a').getAttribute('href'), '');
    assert.equal(await page.locator('[onerror]').count(), 0);
    assert.equal(await page.evaluate(() => window.xss), undefined);
});

test('automation table renders compact grouped metrics and action menu', async () => {
    await page.evaluate(() => {
        renderAutomationTable([{
            id: 'task-1',
            campaign_name: 'Campaign CPR',
            ad_account: 'Account CPR',
            event_flow: 'lp_to_wa',
            conversion: 'purchase',
            status: 'true',
            automation_status: 'active',
            meta_status: 'PAUSED',
            meta_effective_status: 'PAUSED',
            current_budget: 100000,
            current_spend: 75919,
            current_hasil: 1,
            current_cpr: 75919,
            cpr_cap: 25000,
            metrics_stale: true,
        }]);
    });

    const cells = page.locator('#automation_table tbody td');
    assert.match(await cells.nth(0).textContent(), /Campaign CPR/);
    assert.match(await cells.nth(1).textContent(), /Account CPR/);
    assert.match(await cells.nth(2).textContent(), /Paused/);
    assert.match(await cells.nth(3).textContent(), /PAUSED/);
    assert.match(await cells.nth(4).textContent(), /Rp\. 100\.000,-/);
    assert.match(await cells.nth(5).textContent(), /Rp\. 75\.919,-/);
    assert.match(await cells.nth(6).textContent(), /1/);
    assert.match(await cells.nth(7).textContent(), /Rp\. 75\.919,-/);
    assert.doesNotMatch(await cells.nth(7).textContent(), /stale/i);
    assert.equal(await cells.nth(7).locator('strong').getAttribute('title'), 'Metrik Meta belum diperbarui; menampilkan data terakhir.');
    assert.match(await cells.nth(7).getAttribute('class'), /text-danger/);
    assert.match(await cells.nth(8).textContent(), /Rp\. 25\.000,-/);
    assert.deepEqual(await page.locator('.actions-cell button').evaluateAll(buttons => buttons.map(button => button.textContent)), ['Log', 'Update', 'Turun Budget', 'Aktifkan', 'Hapus']);
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
    assert.equal(await page.evaluate(() => window.timers[0].delay), 5000);
    await page.evaluate(() => { window.poll = window.timers.shift().callback(); });
    await page.evaluate(() => { window.second = loadAutomationTasks({ background: true, localOnly: true }); });
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
    assert.ok((await page.evaluate(() => window.calls)).every(url => url.startsWith('/get-automation-task/') && url.includes('local=1')));
});

test('failed polling retries quietly and recovers', async () => {
    await page.evaluate(async () => {
        window.timers = [];
        window.setTimeout = (callback, delay) => { window.timers.push({ callback, delay }); };
        renderAutomationTable([{
            id: 'task-visible',
            campaign_name: 'Visible Campaign',
            ad_account: 'Visible Account',
            event_flow: 'lp_to_wa',
            conversion: 'purchase',
            automation_status: 'active',
            meta_status: 'ACTIVE',
            current_budget: 100000,
            current_spend: 42074,
            current_hasil: 1,
            current_cpr: 42074,
            cpr_cap: 24555,
        }]);
        window.fetch = async () => { throw new Error('offline'); };
        startAutomationPolling();
        await window.timers.shift().callback();
    });
    assert.equal(await page.locator('.toast-lite').count(), 0);
    assert.equal(await page.locator('#automation_table .campaign-name').textContent(), 'Visible Campaign');
    assert.equal(await page.locator('#total_ad_spend').textContent(), 'Rp. 42.074,-');
    assert.equal(await page.evaluate(() => window.timers[0].delay), 5000);
    await page.evaluate(async () => {
        window.fetch = async () => ({ ok: true, json: async () => ({ data: [] }) });
        await window.timers.shift().callback();
    });
    assert.equal(await page.locator('#total_ad_result').textContent(), '0');
});
