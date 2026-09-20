#!/usr/bin/env node
/**
 * Captura de paridad visual (herramienta manual, no forma parte de `npm run test:e2e`).
 *
 *   node tests/e2e/visual/capture.js <carpeta-salida>        # requiere el entorno E2E levantado y las sesiones (auth.setup)
 *
 * Toma capturas de un conjunto de pantallas en escritorio LTR, escritorio RTL (dir="rtl", sin cambiar el idioma del tenant) y móvil.
 * Se ejecuta una vez sobre el árbol anterior y otra sobre el nuevo, y `compare.js` calcula la diferencia de píxeles.
 */
const fs = require('fs');
const path = require('path');
const { chromium } = require('@playwright/test');
const env = require('../support/env');

const OUT = path.resolve(process.argv[2] || 'visual-out');
const ONLY = (process.argv[3] || '').split(',').filter(Boolean); // nombres de pantalla a capturar (vacío = todas)
fs.mkdirSync(OUT, { recursive: true });

// VISUAL_SCREENS=<archivo.json> reemplaza la lista: [{ name, url, user?, click?: "texto|selector", waitFor?: "selector" }].
const SCREENS_FILE = process.env.VISUAL_SCREENS ? JSON.parse(fs.readFileSync(path.resolve(process.env.VISUAL_SCREENS), 'utf8')).map((s) => [s.name, s.url, s.user || 'admin', s]) : null;
const SCREENS = SCREENS_FILE || [
  ['tickets', '/app/support/tickets'],
  ['calendario', '/app/meeting/calendar'],
  ['plantillas-rol', '/app/organization/role-templates'],
  ['marketing-dashboard', '/app/marketing/dashboard'],
  ['campanas', '/app/marketing/campaigns'],
  ['errores-informe', '/app/reports/report_error_logs'],
  ['woocommerce', '/app/woocommerce'],
  ['shopify', '/app/shopify'],
  ['dashboard-legacy', '/app/dashboard/legacy?pxshell=0'],
  ['no-encontrado', '/app/no-existe-visual'],
  ['empleados', '/app/hrm/employees/list'],
  ['clientes', '/app/People/Customers'],
  ['webhooks', '/app/settings/webhooks/list'],
  ['proyectos', '/app/projects/list'],
  ['inmuebles', '/app/realestate/properties'],
  ['no-autorizado', '/app/products/list', 'restricted'],
];
const VIEWPORTS = [
  ['ltr', { width: 1440, height: 900 }, false],
  ['rtl', { width: 1440, height: 900 }, true],
  ['movil', { width: 390, height: 844 }, false],
];

(async () => {
  const browser = await chromium.launch();
  for (const [vpName, viewport, rtl] of VIEWPORTS) {
    for (const [name, url, user = 'admin', extra = {}] of SCREENS) {
      if (ONLY.length && !ONLY.includes(name)) continue;
      const ctx = await browser.newContext({ baseURL: env.baseURL, viewport, storageState: path.join(env.authDir, `${user}.json`), locale: 'es-ES', reducedMotion: 'reduce' });
      const page = await ctx.newPage();
      page.on('pageerror', (e) => console.log(`  pageerror ${name}/${vpName}: ${e.message.slice(0, 120)}`));
      try {
        await page.goto(url, { waitUntil: 'domcontentloaded' });
        await page.waitForFunction(() => { const w = document.getElementById('loading_wrap'); return (!w || getComputedStyle(w).display === 'none') && document.body.innerText.trim().length > 30; }, undefined, { timeout: 30_000 });
        if (rtl) await page.evaluate(() => { document.documentElement.setAttribute('dir', 'rtl'); document.body.classList.add('rtl'); });
        await page.addStyleTag({ content: '*,*::before,*::after{animation:none!important;transition:none!important;caret-color:transparent!important}' });
        if (/woocommerce|shopify/.test(name)) {
          const tab = page.getByRole('tab', { name: /gu[ií]a|guide/i }).first();
          if (await tab.count()) await tab.click();
        }
        if (extra.click) {
          const target = extra.click.startsWith('css:') ? page.locator(extra.click.slice(4)).first() : page.locator('button, a.btn, .btn').filter({ hasText: new RegExp(extra.click, 'i') }).first();
          await target.click();
          await page.waitForTimeout(600);
        }
        const variant = process.env.VISUAL_VARIANT === 'base' ? 'evalBase' : 'eval';
        const script = extra[variant] || (variant === 'evalBase' ? extra.eval : null);
        if (script) { await page.evaluate(`(() => { ${script}; return null; })()`); await page.waitForTimeout(700); }
        if (extra.hover) { await page.locator(extra.hover).first().hover(); await page.waitForTimeout(700); }
        if (extra.wait) await page.waitForTimeout(extra.wait);
        if (extra.waitFor) await page.waitForSelector(extra.waitFor, { timeout: 10_000 });
        await page.waitForTimeout(1500);
        await page.screenshot({ path: path.join(OUT, `${name}__${vpName}.png`), fullPage: extra.viewportOnly ? false : true });
        console.log('ok', name, vpName);
      } catch (e) {
        console.log('FAIL', name, vpName, String(e.message).slice(0, 100));
      }
      await ctx.close();
    }
  }
  await browser.close();
})();
