const fs = require('fs');
const path = require('path');
const { test, expect } = require('../support/fixtures');
const { env, waitForApp } = require('../support/helpers');

// Matriz de modales declarativos (`<b-modal id>`): por cada pantalla sin parámetros se abre cada modal por id con el servicio de plataforma
// (`modals.show`) y se cierra con `modals.hide`. No depende de la implementación (BootstrapVue 2 o BootstrapVueNext): se grabó sobre el build
// con BV2 (`MODALS_RECORD=1` → tests/e2e/data/modals-opened.json) y después de migrar a BVN debe abrir EXACTAMENTE los mismos modales, con el
// mismo título, sin dejar modales ni backdrops, sin errores de consola.
// Los modales cuyo contenido depende de datos/estado no abren en frío: el registro dice cuáles abrían.

const DATA = path.join(__dirname, '..', 'data');
const ENTRIES = JSON.parse(fs.readFileSync(path.join(DATA, 'modals.json'), 'utf8'));
const RECORD = process.env.MODALS_RECORD === '1';
const OPENED_FILE = path.join(DATA, 'modals-opened.json');
const recorded = !RECORD && fs.existsSync(OPENED_FILE) ? JSON.parse(fs.readFileSync(OPENED_FILE, 'utf8')) : {};
const results = {};

test.describe.configure({ mode: 'serial' });
test.describe('Modales declarativos: apertura/cierre por id @smoke', () => {
  test.use({ storageState: path.join(env.authDir, 'admin.json') });
  test.setTimeout(180_000);

  for (const entry of ENTRIES) {
    test(`${entry.file} (${entry.route}): ${entry.ids.length} modal(es)`, async ({ page }) => {
      await page.goto(entry.route);
      await waitForApp(page);
      await page.waitForTimeout(1500);
      for (const id of entry.ids) {
        const key = `${entry.file}#${id}`;
        await page.evaluate((i) => document.querySelector('#app').__vue_app__.config.globalProperties.$platform.modals.show(i), id);
        await page.waitForTimeout(700);
        const shown = await page.locator('.modal.show').count();
        const info = shown
          ? await page.evaluate(() => { const m = document.querySelector('.modal.show'); const t = m.querySelector('.modal-title'); return { title: t ? t.textContent.trim() : null, dialog: (m.querySelector('.modal-dialog') || {}).className || '' }; })
          : null;
        results[key] = shown ? { opened: true, title: info.title, size: (/modal-(sm|lg|xl)\b/.exec(info.dialog) || [])[1] || 'md', centered: /centered/.test(info.dialog), scrollable: /scrollable/.test(info.dialog) } : { opened: false };
        if (!RECORD && recorded[key] && recorded[key].opened) {
          expect(shown, `${key} debía abrir`).toBe(1);
          expect(info.title, `${key} título`).toBe(recorded[key].title);
          expect({ size: results[key].size, centered: results[key].centered, scrollable: results[key].scrollable }, `${key} diálogo`).toEqual({ size: recorded[key].size, centered: recorded[key].centered, scrollable: recorded[key].scrollable });
        }
        if (!RECORD && recorded[key] && !recorded[key].opened) expect(shown, `${key} no abría en frío`).toBe(0);
        await page.evaluate((i) => document.querySelector('#app').__vue_app__.config.globalProperties.$platform.modals.hide(i), id);
        await page.waitForTimeout(500);
        await expect(page.locator('.modal.show'), `${key} cierra`).toHaveCount(0);
        await expect(page.locator('.modal-backdrop'), `${key} sin backdrop`).toHaveCount(0);
        expect(await page.evaluate(() => document.body.classList.contains('modal-open')), `${key} modal-open`).toBe(false);
      }
    });
  }

  test.afterAll(() => {
    if (RECORD) fs.writeFileSync(OPENED_FILE, JSON.stringify(results, null, 1));
  });
});
