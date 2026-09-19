#!/usr/bin/env node
/**
 * Clases BS5 "latentes": vistas ya escritas con nombres de BS5 (`me-2`, `ms-2`, `fw-bold`…) que con la hoja BS4 no hacían nada y con el
 * puente de la fase 1 se activan. Captura cada pantalla con el puente ACTIVO y con sus reglas eliminadas del DOM (= estado anterior).
 *   node tests/e2e/visual/latent.js <carpeta-salida>
 */
const fs = require('fs');
const path = require('path');
const { chromium } = require('@playwright/test');
const env = require('../support/env');
const OUT = path.resolve(process.argv[2] || 'latent-out');
fs.mkdirSync(OUT, { recursive: true });
const SCREENS = [
  ['producto-clasico', '/app/products/store-classic'],
  ['ajuste-clasico', '/app/adjustments/store-classic'],
  ['traslado-clasico', '/app/transfers/store-classic'],
  ['facturacion-plan', '/app/billing/current-plan'],
  ['facturacion-cambiar', '/app/billing/change-plan'],
  ['facturacion-historial', '/app/billing/history'],
  ['facturacion-pago-ok', '/app/billing/success'],
  ['facturacion-pago-fallo', '/app/billing/failed'],
  ['perfil', '/app/profile'],
  ['clientes', '/app/People/Customers'],
  ['proveedores', '/app/People/Providers'],
  ['empleado-detalle', '/app/hrm/employees/list'],
  ['plantillas-marketing', '/app/marketing/templates/email'],
  ['segmentos', '/app/marketing/segments'],
];
const KILL = /(^|[\s,])\.(m[se]|p[se])-|\.fw-|\.text-((sm|md|lg|xl)-)?(start|end)|\.float-((sm|md|lg|xl)-)?(start|end)|\.border-(start|end)|\.rounded-(start|end)/;
(async () => {
  const browser = await chromium.launch();
  for (const [vp, rtl] of [['ltr', false], ['rtl', true]]) {
    for (const [name, url] of SCREENS) {
      const ctx = await browser.newContext({ baseURL: env.baseURL, viewport: { width: 1440, height: 900 }, storageState: path.join(env.authDir, 'admin.json'), locale: 'es-ES', reducedMotion: 'reduce' });
      const page = await ctx.newPage();
      await page.goto(url, { waitUntil: 'networkidle' }).catch(() => {});
      await page.waitForTimeout(1500);
      if (rtl) await page.evaluate(() => document.documentElement.setAttribute('dir', 'rtl'));
      await page.waitForTimeout(300);
      await page.screenshot({ path: path.join(OUT, `${name}__${vp}__on.png`), fullPage: true });
      const removed = await page.evaluate((src) => {
        const rx = new RegExp(src);
        let n = 0;
        const strip = (list) => { for (let i = list.cssRules.length - 1; i >= 0; i--) { const r = list.cssRules[i]; if (r.cssRules && r.type !== 1) strip(r); else if (r.selectorText && rx.test(r.selectorText) && r.style.cssText.includes('!important')) { list.deleteRule(i); n++; } } };
        for (const s of document.styleSheets) { try { strip(s); } catch (e) {} }
        return n;
      }, KILL.source);
      await page.waitForTimeout(300);
      await page.screenshot({ path: path.join(OUT, `${name}__${vp}__off.png`), fullPage: true });
      console.log(`ok ${name} ${vp} (reglas quitadas: ${removed})`);
      await ctx.close();
    }
  }
  await browser.close();
})();
