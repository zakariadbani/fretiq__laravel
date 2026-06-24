const { chromium } = require('@playwright/test');
const { pathToFileURL } = require('url');
const path = require('path');
(async () => {
  const browser = await chromium.launch();
  const page = await browser.newPage();
  await page.setViewportSize({ width: 1200, height: 630 });
  await page.goto(pathToFileURL(path.resolve('resources/landing/og-card.html')).href);
  await page.screenshot({ path: 'public/og-image.png' });
  await browser.close();
  const fs = require('fs');
  const b = fs.readFileSync('public/og-image.png');
  console.log('OG image:', b.readUInt32BE(16) + 'x' + b.readUInt32BE(20), '(' + b.length + ' bytes)');
})();
