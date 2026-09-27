#!/usr/bin/env node
/**
 * Captura de paridad visual de los modales declarativos (tests/e2e/data/modals.json + modals-opened.json): cada modal que abre en frío, en
 * escritorio LTR, RTL y móvil. `node tests/e2e/visual/modals.js <carpeta>`; comparar con `compare.js`.
 */
const fs = require('fs');
const path = require('path');
const { chromium } = require('@playwright/test');
const env = require('../support/env');
const OUT = path.resolve(process.argv[2] || 'modals-out');
fs.mkdirSync(OUT, { recursive: true });
const DATA = path.join(__dirname, '..', 'data');
const entries = JSON.parse(fs.readFileSync(path.join(DATA, 'modals.json'), 'utf8'));
const opened = JSON.parse(fs.readFileSync(path.join(DATA, 'modals-opened.json'), 'utf8'));
const ONLY = process.env.ONLY ? new RegExp(process.env.ONLY) : null; // ONLY=<regex sobre views/app/pages/...> limita las pantallas
const VIEWPORTS = [['ltr', { width: 1440, height: 900 }, false], ['rtl', { width: 1440, height: 900 }, true], ['movil', { width: 390, height: 844 }, false]];
const slug = (s) => s.replace(/[^a-zA-Z0-9]+/g, '_').slice(0, 80);
(async () => {
  const browser = await chromium.launch();
  for (const [vp, viewport, rtl] of VIEWPORTS) {
    for (const e of entries) {
      if (ONLY && !ONLY.test(e.file)) continue;
      const ids = e.ids.filter((i) => opened[`${e.file}#${i}`] && opened[`${e.file}#${i}`].opened);
      if (!ids.length) continue;
      const ctx = await browser.newContext({ baseURL: env.baseURL, viewport, storageState: path.join(env.authDir, 'admin.json'), locale: 'es-ES', reducedMotion: 'reduce' });
      const page = await ctx.newPage();
      page.on('pageerror', (er) => console.log(`  pageerror ${e.file}/${vp}: ${er.message.slice(0, 100)}`));
      try {
        await page.goto(e.route, { waitUntil: 'domcontentloaded' });
        await page.waitForFunction(() => { const w = document.getElementById('loading_wrap'); return (!w || getComputedStyle(w).display === 'none') && document.body.innerText.trim().length > 30; }, undefined, { timeout: 30_000 });
        await page.waitForTimeout(1500);
        if (rtl) await page.evaluate(() => { document.documentElement.setAttribute('dir', 'rtl'); document.body.classList.add('rtl'); });
        await page.addStyleTag({ content: '*,*::before,*::after{animation:none!important;transition:none!important;caret-color:transparent!important}' });
        for (const id of ids) {
          await page.evaluate((i) => document.querySelector('#app').__vue_app__.config.globalProperties.$platform.modals.show(i), id);
          await page.waitForTimeout(900);
          await page.screenshot({ path: path.join(OUT, `${slug(e.file.replace('views/app/', ''))}__${slug(id)}__${vp}.png`), fullPage: false });
          await page.evaluate((i) => document.querySelector('#app').__vue_app__.config.globalProperties.$platform.modals.hide(i), id);
          await page.waitForTimeout(500);
        }
        console.log('ok', e.file, vp);
      } catch (err) { console.log('FAIL', e.file, vp, String(err.message).slice(0, 100)); }
      await ctx.close();
    }
  }
  await browser.close();
})();
