const fs = require('fs');
const path = require('path');
const { test, expect } = require('../support/fixtures');

// Directiva propia `v-on-clickaway` (platform/directives/clickaway.js), con clicks y toques reales en Chromium y el build global de
// @vue/compat. En PRODEX la directiva está registrada globalmente y ninguna pantalla la usa hoy (vue-clickaway solo se importaba
// como mixin sin `v-on-clickaway` en ninguna plantilla), así que los patrones de UI se reproducen aquí: dropdown con toggle y
// panel `v-if`, overlay `v-show`, varias instancias, handler dinámico y montaje/desmontaje repetido.
test.use({ storageState: { cookies: [], origins: [] }, hasTouch: true });

const DIRECTIVE_SOURCE = fs
  .readFileSync(path.resolve(__dirname, '../../../resources/src/platform/directives/clickaway.js'), 'utf8')
  .replace(/^export const clickaway = /m, 'window.__clickaway = ')
  .replace(/^export default .*$/m, '');

async function mount(page, template, options = {}) {
  await page.setContent('<!doctype html><html><body><div id="host"></div><div id="outside" style="margin:40px;padding:20px">fuera</div></body></html>');
  await page.addScriptTag({ path: require.resolve('@vue/compat/dist/vue.global.js') });
  await page.evaluate(() => {
    // espía de listeners de click en <html>: cuántos hay vivos en cada momento
    const html = document.documentElement;
    const add = html.addEventListener.bind(html);
    const remove = html.removeEventListener.bind(html);
    const live = new Set();
    window.__liveClickListeners = () => live.size;
    html.addEventListener = (type, fn, opts) => { if (type === 'click') live.add(fn); return add(type, fn, opts); };
    html.removeEventListener = (type, fn, opts) => { if (type === 'click') live.delete(fn); return remove(type, fn, opts); };
  });
  await page.addScriptTag({ content: DIRECTIVE_SOURCE });
  await page.evaluate(
    ({ tpl, extra }) => {
      window.Vue.config.productionTip = false;
      window.Vue.directive('on-clickaway', window.__clickaway);
      window.__calls = { closeA: 0, closeB: 0, overlay: 0 };
      const el = document.createElement('div');
      document.getElementById('host').appendChild(el);
      // eslint-disable-next-line no-new-func
      const extraData = extra ? new Function(`return (${extra})`)() : {};
      window.__vm = new window.Vue({
        el,
        template: tpl,
        data: () => ({ a: false, b: false, overlay: false, show: true, dyn: false, ...extraData }),
        methods: {
          closeA() { window.__calls.closeA += 1; this.a = false; },
          closeB() { window.__calls.closeB += 1; this.b = false; },
          closeOverlay() { window.__calls.overlay += 1; this.overlay = false; },
          noop() {},
        },
      });
    },
    { tpl: template, extra: options.data || null }
  );
}

const DROPDOWNS = `
<div>
  <div v-if="show" class="dd-a">
    <button id="ta" @click="a = !a">A</button>
    <div v-if="a" id="pa" v-on-clickaway="closeA"><span id="ia">dentro A</span></div>
  </div>
  <div class="dd-b">
    <button id="tb" @click="b = !b">B</button>
    <div v-if="b" id="pb" v-on-clickaway="closeB"><span id="ib">dentro B</span></div>
  </div>
</div>`;

// Patrón "overlay siempre montado": el disparador va DENTRO del elemento con la directiva (como en cualquier menú con v-show),
// de lo contrario el click del propio disparador contaría como "fuera" también con la librería anterior.
const OVERLAY = `
<div id="ov" v-on-clickaway="closeOverlay">
  <button id="to" @click="overlay = !overlay">O</button>
  <div v-show="overlay" id="po"><span id="io">overlay</span></div>
</div>`;

const calls = (page) => page.evaluate(() => ({ ...window.__calls }));

test.describe('Directiva v-on-clickaway propia @smoke', () => {
  test('dropdown (toggle + panel v-if): click dentro permanece, click fuera cierra, y el click que abre no lo cierra', async ({ page }) => {
    await mount(page, DROPDOWNS);
    await page.locator('#ta').click();
    await expect(page.locator('#pa')).toBeVisible();
    await page.waitForTimeout(100);
    expect((await calls(page)).closeA, 'el click que abre no cierra').toBe(0);

    await page.locator('#ia').click();
    await page.locator('#pa').click();
    await expect(page.locator('#pa')).toBeVisible();
    expect((await calls(page)).closeA).toBe(0);

    await page.locator('#outside').click();
    await expect(page.locator('#pa')).toHaveCount(0);
    expect((await calls(page)).closeA, 'un click fuera = un handler').toBe(1);
  });

  test('abrir y cerrar repetidamente: un handler por click fuera (sin duplicados) y un único listener vivo por panel abierto', async ({ page }) => {
    await mount(page, DROPDOWNS);
    for (let i = 1; i <= 6; i += 1) {
      await page.locator('#ta').click();
      await expect(page.locator('#pa')).toBeVisible();
      expect(await page.evaluate(() => window.__liveClickListeners())).toBe(1);
      await page.waitForTimeout(30);
      await page.locator('#outside').click();
      await expect(page.locator('#pa')).toHaveCount(0);
      expect((await calls(page)).closeA).toBe(i);
      expect(await page.evaluate(() => window.__liveClickListeners()), 'panel cerrado: sin listeners').toBe(0);
    }
    // cerrar con su propio toggle: el @click del toggle cierra el panel (y retira la directiva) antes de que el click llegue al
    // documento, así que el handler de click-away no se ejecuta — igual que con la librería anterior
    await page.locator('#ta').click();
    await page.waitForTimeout(30);
    await page.locator('#ta').click();
    await expect(page.locator('#pa')).toHaveCount(0);
    expect((await calls(page)).closeA).toBe(6);
    expect(await page.evaluate(() => window.__liveClickListeners())).toBe(0);
  });

  test('varias instancias: abrir B cierra A; solo el panel abierto recibe el click fuera', async ({ page }) => {
    await mount(page, DROPDOWNS);
    await page.locator('#ta').click();
    await page.waitForTimeout(30);
    await page.locator('#tb').click(); // fuera de A, dentro de la caja de B
    await expect(page.locator('#pa')).toHaveCount(0);
    await expect(page.locator('#pb')).toBeVisible();
    await page.waitForTimeout(30);
    expect(await calls(page)).toMatchObject({ closeA: 1, closeB: 0 });
    await page.locator('#ib').click();
    await expect(page.locator('#pb')).toBeVisible();
    await page.locator('#outside').click();
    await expect(page.locator('#pb')).toHaveCount(0);
    expect(await calls(page)).toMatchObject({ closeA: 1, closeB: 1 });
  });

  test('overlay con v-show (siempre montado, disparador dentro): click dentro no cierra, click fuera sí', async ({ page }) => {
    await mount(page, OVERLAY);
    expect(await page.evaluate(() => window.__liveClickListeners())).toBe(1);
    await page.locator('#to').click();
    await expect(page.locator('#po')).toBeVisible();
    await page.locator('#io').click();
    await expect(page.locator('#po')).toBeVisible();
    expect((await calls(page)).overlay).toBe(0);
    await page.locator('#outside').click();
    await expect(page.locator('#po')).toBeHidden();
    expect((await calls(page)).overlay).toBe(1);
    // reabrir y cerrar de nuevo: sigue habiendo un único listener
    await page.locator('#to').click();
    await expect(page.locator('#po')).toBeVisible();
    await page.locator('#outside').click();
    await expect(page.locator('#po')).toBeHidden();
    expect((await calls(page)).overlay).toBe(2);
    expect(await page.evaluate(() => window.__liveClickListeners())).toBe(1);
  });

  test('desmontaje y regreso: al destruir el componente se retira el listener; al volver funciona y no se acumulan', async ({ page }) => {
    await mount(page, DROPDOWNS);
    for (let i = 0; i < 5; i += 1) {
      await page.locator('#ta').click();
      await expect(page.locator('#pa')).toBeVisible();
      // desmonta el bloque completo con el panel abierto
      await page.evaluate(() => { window.__vm.show = false; });
      await expect(page.locator('.dd-a')).toHaveCount(0);
      expect(await page.evaluate(() => window.__liveClickListeners()), 'sin listeners tras desmontar').toBe(0);
      await page.locator('#outside').click();
      expect((await calls(page)).closeA, 'un componente desmontado no recibe clicks').toBe(0);
      await page.evaluate(() => { window.__vm.show = true; window.__vm.a = false; });
      await expect(page.locator('.dd-a')).toHaveCount(1);
    }
    await page.locator('#ta').click();
    await page.waitForTimeout(30);
    await page.locator('#outside').click();
    await expect(page.locator('#pa')).toHaveCount(0);
    expect((await calls(page)).closeA).toBe(1);
  });

  test('handler dinámico: al cambiar el valor de la directiva se usa el nuevo handler, sin duplicar y sin cerrar con el click del cambio', async ({ page }) => {
    await mount(
      page,
      `<div><button id="td" @click="dyn = !dyn">D</button><div id="pd" v-on-clickaway="dyn ? closeA : noop"><span id="id">x</span></div></div>`
    );
    await page.locator('#td').click(); // cambia el handler dentro del mismo click que lo provoca
    await page.waitForTimeout(30);
    expect((await calls(page)).closeA, 'el click que cambia el handler no lo ejecuta').toBe(0);
    expect(await page.evaluate(() => window.__liveClickListeners())).toBe(1);
    await page.locator('#outside').click();
    expect((await calls(page)).closeA).toBe(1);
    await page.locator('#td').click();
    await page.waitForTimeout(30);
    await page.locator('#outside').click();
    expect((await calls(page)).closeA, 'con `noop` no hay llamadas de closeA').toBe(1);
    expect(await page.evaluate(() => window.__liveClickListeners())).toBe(1);
  });

  test('toque táctil: un tap fuera cierra exactamente una vez (el click sintético no se duplica) y un tap dentro no', async ({ page }) => {
    await mount(
      page,
      `<div style="padding:10px"><button id="ta" @click="a = !a" style="width:120px;height:40px">A</button><div v-if="a" id="pa" v-on-clickaway="closeA" style="width:160px;height:60px;background:#eee"><span id="ia">dentro A</span></div></div>`
    );
    await page.locator('#ta').tap();
    await expect(page.locator('#pa')).toBeVisible();
    await page.waitForTimeout(50);
    await page.locator('#pa').tap();
    await expect(page.locator('#pa')).toBeVisible();
    expect((await calls(page)).closeA, 'tap dentro: sin handler').toBe(0);
    await page.locator('#outside').tap();
    await expect(page.locator('#pa')).toHaveCount(0);
    expect((await calls(page)).closeA, 'tap fuera: un solo handler (touch + click sintético)').toBe(1);
    expect(await page.evaluate(() => window.__liveClickListeners())).toBe(0);
  });

  test('valor que no es función: aviso y sin ejecución; sin errores de consola', async ({ page }) => {
    const warnings = [];
    page.on('console', (m) => { if (m.type() === 'warning' && /on-clickaway/.test(m.text())) warnings.push(m.text()); });
    await mount(page, `<div><div id="bad" v-on-clickaway="dyn">x</div></div>`, { data: '{ dyn: "texto" }' });
    await page.locator('#outside').click();
    expect(warnings.length).toBeGreaterThan(0);
    expect(warnings[0]).toMatch(/debe ser una función/);
  });
});
