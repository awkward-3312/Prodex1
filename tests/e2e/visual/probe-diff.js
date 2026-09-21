#!/usr/bin/env node
/** Imprime, por caso de tests/e2e/data/bv-probe-cases.js, el HTML normalizado BV2 vs BVN y si coinciden. `node tests/e2e/visual/probe-diff.js [filtro]` */
const path = require('path');
const { chromium } = require('@playwright/test');
const env = require('../support/env');
const cases = require('../data/bv-probe-cases.js');
const filter = process.argv[2] ? new RegExp(process.argv[2], 'i') : null;
const { normalizeHtml } = require('../support/probe');
(async () => {
  const browser = await chromium.launch();
  const ctx = await browser.newContext({ baseURL: env.baseURL, storageState: path.join(env.authDir, 'admin.json'), viewport: { width: 1440, height: 900 } });
  const page = await ctx.newPage();
  await page.goto('/app/_ui?probe=bv');
  await page.waitForFunction(() => typeof window.__pxProbe === 'function', undefined, { timeout: 30000 });
  for (const c of cases) {
    if (filter && !filter.test(c.name)) continue;
    const run = (bvn) => page.evaluate(([t, o]) => window.__pxProbe(t, o), [c.template, { bvn, data: c.data || {}, methods: c.methods || {} }]);
    const ra = await run(false);
    const rb = await run(true);
    const a = normalizeHtml(ra.html);
    const b = normalizeHtml(rb.html);
    const miss = rb.missing.length ? ` [SIN WRAPPER: ${rb.missing.join(', ')}]` : '';
    console.log(a === b && !miss ? `= ${c.name}` : `≠ ${c.name}${miss}\n   BV2: ${a}\n   BVN: ${b}`);
  }
  await browser.close();
})();
