#!/usr/bin/env node
/**
 * Avisos de @vue/compat por ORIGEN sobre un conjunto fijo de pantallas (mismo conjunto en dos árboles → comparación limpia).
 * Como el E2E 22, instala `app.config.warnHandler` (recibe la instancia que provoca el aviso) y navega por SPA; clasifica cada aviso por la implementación
 * del componente: bvn = SFC de BootstrapVueNext (`__name: 'B…'`) o wrapper de platform/bootstrap (`compatConfig.MODE 3`), bv2 = BootstrapVue 2 (`name: 'B…'` sin `__name`), own = resto, global = sin instancia.
 *   node tests/e2e/scripts/warnings-by-origin.js [screens.json]     (requiere el entorno E2E levantado y auth.setup)
 */
const fs = require('fs');
const path = require('path');
const { chromium } = require('@playwright/test');
const env = require('../support/env');

const FILE = path.resolve(process.argv[2] || path.join(__dirname, '..', 'visual', 'screens-phase5a.json'));
const routes = [...new Set(JSON.parse(fs.readFileSync(FILE, 'utf8')).map((s) => s.url))];
(async () => {
  const browser = await chromium.launch();
  const ctx = await browser.newContext({ baseURL: env.baseURL, storageState: path.join(env.authDir, 'admin.json'), viewport: { width: 1440, height: 900 } });
  const page = await ctx.newPage();
  await page.goto('/app/dashboard');
  await page.waitForFunction(() => { const w = document.getElementById('loading_wrap'); return (!w || getComputedStyle(w).display === 'none') && document.body.innerText.trim().length > 40; }, undefined, { timeout: 30000 });
  await page.evaluate(() => {
    const app = document.querySelector('#app').__vue_app__;
    window.__warn = [];
    app.config.warnHandler = (msg, proxy) => {
      const type = proxy && proxy.$ && proxy.$.type;
      const m = /deprecation ([A-Z_]+)/.exec(msg);
      const name = type ? type.__name || type.name || '' : '';
      const kind = !type ? 'global' : (type.__name && /^B[A-Z]/.test(type.__name)) || (type.compatConfig && type.compatConfig.MODE === 3 && /^B[A-Z]/.test(name)) ? 'bvn' : !type.__name && /^B[A-Z]/.test(name) ? 'bv2' : 'own';
      window.__warn.push({ key: m ? m[1] : 'other', kind, name });
    };
  });
  for (const r of routes) {
    await page.evaluate((to) => document.querySelector('#app').__vue_app__.config.globalProperties.$router.push(to), r).catch(() => {});
    await page.waitForTimeout(2500);
  }
  const warn = await page.evaluate(() => window.__warn);
  const out = { routes: routes.length, total: warn.length, byKey: {}, bv2Names: {} };
  for (const w of warn) {
    out.byKey[w.key] = out.byKey[w.key] || { bv2: 0, bvn: 0, own: 0, global: 0 };
    out.byKey[w.key][w.kind] += 1;
    if (w.kind === 'bv2') out.bv2Names[w.name] = (out.bv2Names[w.name] || 0) + 1;
  }
  const sum = (k) => Object.values(out.byKey).reduce((n, v) => n + v[k], 0);
  out.byKind = { bv2: sum('bv2'), bvn: sum('bvn'), own: sum('own'), global: sum('global') };
  console.log(JSON.stringify(out));
  await browser.close();
})();
