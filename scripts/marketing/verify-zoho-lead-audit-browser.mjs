import fs from 'node:fs';
import path from 'node:path';
import { pathToFileURL } from 'node:url';
import { chromium } from 'playwright';

const detailedPath = path.resolve(process.argv[2] ?? 'storage/app/marketing-reports/zoho-lead-by-lead-audit-2026-08-12.html');
const summaryPath = path.resolve(process.argv[3] ?? 'storage/app/marketing-reports/zoho-lead-marketing-strategy-report-2026-08-12.html');

for (const filePath of [detailedPath, summaryPath]) {
    if (! fs.existsSync(filePath)) {
        throw new Error(`Report is missing: ${filePath}`);
    }
}

const browser = await chromium.launch({ headless: true });
const page = await browser.newPage({ viewport: { width: 1440, height: 1000 } });
const consoleErrors = [];
const pageErrors = [];
page.on('console', (message) => {
    if (message.type() === 'error') consoleErrors.push(message.text());
});
page.on('pageerror', (error) => pageErrors.push(error.message));

try {
    const startedAt = performance.now();
    await page.goto(pathToFileURL(detailedPath).href, { waitUntil: 'load' });
    await page.waitForFunction(() => document.querySelectorAll('#rows .lead-row').length === 50);
    const detailedLoadMs = Math.round(performance.now() - startedAt);
    const detailedInitial = await page.evaluate(() => ({
        rows: document.querySelectorAll('#rows .lead-row').length,
        categories: document.querySelectorAll('#categoryStrip .cat-card').length,
        result: document.querySelector('#resultCount')?.textContent,
        title: document.title,
    }));

    await page.locator('#search').fill('Prospect Chaud');
    await page.waitForFunction(() => document.querySelectorAll('#rows .lead-row').length === 1);
    await page.locator('#rows .lead-row').click();
    await page.waitForFunction(() => document.querySelector('#detailDialog')?.open === true);
    const dialog = await page.evaluate(() => ({
        title: document.querySelector('#detailTitle')?.textContent,
        timelineItems: document.querySelectorAll('#detailBody .timeline-item').length,
    }));
    await page.locator('#closeDialog').click();

    await page.setViewportSize({ width: 390, height: 844 });
    await page.reload({ waitUntil: 'load' });
    await page.waitForFunction(() => document.querySelectorAll('#rows .lead-row').length === 50);
    const detailedMobile = await page.evaluate(() => ({
        viewport: window.innerWidth,
        documentWidth: document.documentElement.scrollWidth,
        bodyWidth: document.body.scrollWidth,
    }));

    await page.goto(pathToFileURL(summaryPath).href, { waitUntil: 'load' });
    await page.waitForFunction(() => document.querySelectorAll('#topTargets tr').length === 50);
    const summary = await page.evaluate(() => ({
        topTargets: document.querySelectorAll('#topTargets tr').length,
        strategies: document.querySelectorAll('#strategies .strategy').length,
        categories: document.querySelectorAll('#categories .category').length,
        documentWidth: document.documentElement.scrollWidth,
        viewport: window.innerWidth,
        title: document.title,
    }));

    if (detailedInitial.rows !== 50 || detailedInitial.categories !== 11) throw new Error('Detailed report did not render its expected initial rows/categories.');
    if (! dialog.title || dialog.timelineItems < 1) throw new Error('Lead detail dialog did not render a linked timeline.');
    if (detailedMobile.documentWidth > detailedMobile.viewport || detailedMobile.bodyWidth > detailedMobile.viewport) throw new Error('Detailed report has horizontal page overflow on mobile.');
    if (summary.topTargets !== 50 || summary.strategies !== 11 || summary.categories !== 11) throw new Error('Strategy report did not render its complete queues/categories.');
    if (summary.documentWidth > summary.viewport) throw new Error('Strategy report has horizontal page overflow on mobile.');
    if (consoleErrors.length || pageErrors.length) throw new Error(`Browser errors: ${JSON.stringify({ consoleErrors, pageErrors })}`);

    console.log(JSON.stringify({
        detailed: { load_ms: detailedLoadMs, initial: detailedInitial, search_result_rows: 1, dialog, mobile: detailedMobile },
        summary,
        console_errors: consoleErrors,
        page_errors: pageErrors,
    }, null, 2));
} finally {
    await browser.close();
}
