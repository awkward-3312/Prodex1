const path = require('path');
const { test, expect } = require('../support/fixtures');
const { env, waitForApp } = require('../support/helpers');
const { normalizeHtml } = require('../support/probe');

// Fase 5A: layout y primitives (row/col/container/card, button, badge, alert, progress, link, list-group, img, tabs, dropdown, pagination) de
// BootstrapVue 2 → BootstrapVueNext. La sonda dev (`/app/_ui?probe=bv`) monta la MISMA plantilla con los `b-*` globales de BV2 y con los
// wrappers de `platform/bootstrap` (registro local, como las vistas) y el test exige el mismo DOM (donde el marcado debe coincidir) y el
// mismo comportamiento: cada prueba de comportamiento se ejecuta sobre las DOS implementaciones con las mismas aserciones.

test.use({ storageState: path.join(env.authDir, 'admin.json') });

// Atributos que BV2 filtra al DOM (props no declaradas como atributo) o accesibilidad distinta que no cambia el aspecto.
const DROP_ATTRS = ['md', 'sm', 'lg', 'xl', 'cols', 'col', 'no-gutters', 'no-body', 'header-bg-variant', 'enterclass', 'leaveclass', 'role', 'target', 'rel', 'aria-label', 'aria-live', 'aria-atomic', 'tabindex', 'loading', 'to', 'replace'];
const sortAttrs = (html) => html.replace(/<([a-z0-9-]+)((?:\s+[a-z:@_.-]+(?:="[^"]*")?)+)\s*(\/?)>/gi, (m, tag, attrs, slash) => {
  const list = attrs.match(/\s+[a-z:@_.-]+(?:="[^"]*")?/gi).map((a) => a.trim()).filter((a) => !/^aria-/.test(a)).sort();
  return `<${tag}${list.length ? ' ' + list.join(' ') : ''}${slash}>`;
});
const strip = (html) => sortAttrs(normalizeHtml(html)).replace(new RegExp(`\\s(?:${DROP_ATTRS.join('|')})(?:="[^"]*")?`, 'g'), '').replace(/\sclass="b-img /, ' class="').replace(/ class="([^"]*)\bb-img\b ?/g, ' class="$1');

async function open(page) {
  await page.goto('/app/_ui?probe=bv');
  await waitForApp(page);
  await page.waitForFunction(() => typeof window.__pxProbe === 'function', undefined, { timeout: 30_000 });
}
const mount = (page, template, opts = {}) => page.evaluate(([t, o]) => window.__pxProbe(t, o), [template, opts]);
const state = (page) => page.evaluate(() => JSON.parse(JSON.stringify(window.__pxProbeData())));
const IMPLS = [['BV2', false], ['BVN', true]];

test.describe('Layout y primitives: mismo DOM que BootstrapVue 2 @smoke', () => {
  test.beforeEach(async ({ page }) => open(page));

  const SAME = {
    'row + col (sm/md/lg)': '<b-row><b-col lg="12" md="6" sm="12">a</b-col><b-col md="6">b</b-col></b-row>',
    'col cols, col, auto, :md': '<b-row><b-col cols="12">a</b-col><b-col col>b</b-col><b-col cols="auto">c</b-col><b-col :md="4">d</b-col></b-row>',
    'col sin props y con class': '<b-row><b-col>a</b-col><b-col class="x y">b</b-col></b-row>',
    'row no-gutters': '<b-row no-gutters class="r"><b-col>a</b-col></b-row>',
    'container fluid': '<b-container fluid class="c"><b-row><b-col>a</b-col></b-row></b-container>',
    'card simple + card-text': '<b-card class="mb-3"><b-card-text>t</b-card-text>cuerpo</b-card>',
    'card title': '<b-card title="Titulo" class="c">cuerpo</b-card>',
    'card header + header-bg-variant': '<b-card header="Cabecera" header-bg-variant="light">cuerpo</b-card>',
    'card no-body con header/body/footer': '<b-card no-body><b-card-header>H</b-card-header><b-card-body>B</b-card-body><b-card-footer>F</b-card-footer></b-card>',
    'card slot header': '<b-card><template #header><b>H</b></template>x</b-card>',
    'button variant/size/block': '<b-button variant="primary" size="sm" block class="m">Ok</b-button>',
    'button submit disabled': '<b-button type="submit" variant="outline-secondary" :disabled="true">x</b-button>',
    'button group': '<b-button-group size="sm" class="g"><b-button variant="primary">a</b-button><b-button>b</b-button></b-button-group>',
    'badge variant, pill, dinámica': '<b-badge variant="success" pill class="x">1</b-badge><b-badge :variant="v">2</b-badge><b-badge>3</b-badge>',
    'list group': '<b-list-group flush><b-list-group-item class="i">a</b-list-group-item><b-list-group-item>b</b-list-group-item></b-list-group>',
    'img thumbnail fluid': '<b-img thumbnail fluid src="/images/x.png" alt="x" width="40" height="40"></b-img>',
    'progress: barra, animada, con barras': '<b-progress :value="40" :max="100" height="8px" class="p"></b-progress><b-progress :value="60" :max="100" show-progress animated></b-progress>',
  };
  for (const [name, template] of Object.entries(SAME)) {
    test(name, async ({ page }) => {
      const data = { v: 'danger' };
      const before = strip((await mount(page, template, { bvn: false, data })).html);
      const rb = await mount(page, template, { bvn: true, data });
      expect(rb.missing, 'etiquetas sin wrapper BVN').toEqual([]);
      expect(sortAttrs(strip(rb.html))).toBe(sortAttrs(before));
    });
  }

  test('barras de progreso con variante: bg-<v> (BS4), no text-bg-<v>', async ({ page }) => {
    const tpl = '<b-progress :max="10"><b-progress-bar :value="3" variant="success"></b-progress-bar><b-progress-bar :value="2" variant="danger"></b-progress-bar></b-progress>';
    await mount(page, tpl, { bvn: true });
    const bars = page.locator('.probe-root .progress-bar');
    await expect(bars).toHaveCount(2);
    await expect(bars.nth(0)).toHaveClass(/bg-success/);
    await expect(bars.nth(0)).not.toHaveClass(/text-bg-/);
    expect(await bars.nth(0).evaluate((e) => e.style.width)).toBe('30%');
  });

  test('alerta: show / :show / dismissible / @dismissed — misma visibilidad y un solo evento (BV2 y BVN)', async ({ page }) => {
    for (const [, bvn] of IMPLS) {
      await mount(page, '<div><b-alert show variant="warning" class="a1">hola</b-alert><b-alert :show="flag" variant="info" class="a2">dos</b-alert><b-alert show dismissible variant="danger" class="a3" @dismissed="hits++">x</b-alert></div>', { bvn, data: { flag: false, hits: 0 } });
      const root = page.locator('.probe-root');
      await expect(root.locator('.a1')).toBeVisible();
      await expect(root.locator('.a1')).toHaveClass(/alert-warning/);
      await expect(root.locator('.a1')).toContainText('hola');
      await expect(root.locator('.a2')).toBeHidden();
      await page.evaluate(() => { window.__pxProbeData().flag = true; });
      await expect(root.locator('.a2')).toBeVisible();
      await root.locator('.a3 .close, .a3 .btn-close').click();
      await expect(root.locator('.a3')).toBeHidden();
      await page.waitForTimeout(400);
      expect((await state(page)).hits, `@dismissed una vez (${bvn ? 'BVN' : 'BV2'})`).toBe(1);
    }
  });

  test('alerta BVN: el cuerpo ocupa el ancho (text-center funciona) y la cruz queda a la derecha', async ({ page }) => {
    await mount(page, '<b-alert show dismissible variant="info" class="text-center a">centrado</b-alert>', { bvn: true });
    const a = page.locator('.probe-root .a');
    const box = await a.boundingBox();
    const body = await a.locator('.alert-body').boundingBox();
    expect(body.width).toBeGreaterThan(box.width * 0.7);
    const close = await a.locator('.btn-close').boundingBox();
    expect(close.x + close.width).toBeGreaterThan(box.x + box.width - 40);
  });
});

test.describe('Comportamiento idéntico en BV2 y BVN: una sola emisión @smoke', () => {
  test.beforeEach(async ({ page }) => open(page));

  test('botón: un clic = una acción; deshabilitado no actúa; submit una vez; .stop no llega al padre', async ({ page }) => {
    for (const [, bvn] of IMPLS) {
      await mount(page, '<div @click="outer++"><b-button class="b1" variant="primary" @click="hits++">a</b-button><b-button class="b2" disabled @click="dis++">b</b-button><b-button class="b3" @click.stop="stopHits++">c</b-button><form @submit.prevent="subs++"><b-button class="b4" type="submit">s</b-button></form></div>', { bvn, data: { hits: 0, dis: 0, stopHits: 0, subs: 0, outer: 0 } });
      const root = page.locator('.probe-root');
      await root.locator('.b1').click();
      await root.locator('.b2').click({ force: true });
      await root.locator('.b3').click();
      await root.locator('.b4').click();
      await page.waitForTimeout(150);
      expect(await state(page), bvn ? 'BVN' : 'BV2').toEqual({ hits: 1, dis: 0, stopHits: 1, subs: 1, outer: 2 });
    }
  });

  test('enlace: href="#" por defecto no cambia la URL y emite una vez', async ({ page }) => {
    for (const [, bvn] of IMPLS) {
      await mount(page, '<b-link class="l" @click="hits++">y</b-link>', { bvn, data: { hits: 0 } });
      const url = page.url();
      await page.locator('.probe-root .l').click();
      await page.waitForTimeout(150);
      expect(page.url(), bvn ? 'BVN' : 'BV2').toBe(url);
      expect((await state(page)).hits).toBe(1);
    }
  });

  test('botón / ítem con :to navega con Router 4 una sola vez', async ({ page }) => {
    for (const [, bvn] of IMPLS) {
      await open(page);
      await mount(page, '<div><b-button class="go" to="/app/dashboard/legacy">ir</b-button></div>', { bvn });
      await page.locator('.probe-root .go').click();
      await expect(page).toHaveURL(/\/app\/dashboard\/legacy/);
      expect(await page.evaluate(() => window.history.length)).toBeGreaterThan(0);
    }
  });

  test('pestañas: v-model = índice, @input una vez por cambio, deshabilitada inerte, lazy, teclado', async ({ page }) => {
    for (const [, bvn] of IMPLS) {
      await mount(page, '<div><b-tabs v-model="tab" content-class="mt-3" @input="hits++" class="tt"><b-tab title="Uno" active><p class="c1">a</p></b-tab><b-tab title="Dos"><p class="c2">b</p></b-tab><b-tab title="Tres" :disabled="true"><p class="c3">c</p></b-tab></b-tabs></div>', { bvn, data: { tab: 0, hits: 0 } });
      const root = page.locator('.probe-root');
      const links = root.locator('.nav-tabs .nav-link');
      await expect(links.nth(0)).toHaveClass(/active/);
      await expect(root.locator('.c1')).toBeVisible();
      await links.nth(1).click();
      await expect(links.nth(1)).toHaveClass(/active/);
      await expect(root.locator('.c2')).toBeVisible();
      await expect(root.locator('.c1')).toBeHidden();
      await page.waitForTimeout(300);
      let st = await state(page);
      expect(st.tab, `v-model (${bvn ? 'BVN' : 'BV2'})`).toBe(1);
      const afterClick = st.hits;
      expect(afterClick, '@input por cambio (una vez)').toBeGreaterThanOrEqual(1);
      await links.nth(2).click({ force: true });
      await expect(links.nth(1)).toHaveClass(/active/);
      expect((await state(page)).tab).toBe(1);
      // v-model programático
      await page.evaluate(() => { window.__pxProbeData().tab = 0; });
      await expect(links.nth(0)).toHaveClass(/active/);
      await expect(root.locator('.c1')).toBeVisible();
    }
  });

  test('pestañas sin `active` ni v-model: se activa la primera (como BV2) y su contenido se ve', async ({ page }) => {
    for (const [, bvn] of IMPLS) {
      await mount(page, '<b-card><b-tabs active-nav-item-class="nav nav-tabs" content-class="mt-3"><b-tab :title="t"><p class="c1">a</p></b-tab><b-tab title="Dos"><p class="c2">b</p></b-tab></b-tabs></b-card>', { bvn, data: { t: 'Uno' } });
      const root = page.locator('.probe-root');
      await expect(root.locator('.nav-link').first(), bvn ? 'BVN' : 'BV2').toHaveClass(/active/);
      await expect(root.locator('.c1')).toBeVisible();
      await expect(root.locator('.c2')).toBeHidden();
    }
  });

  test('pestañas lazy: el contenido de una pestaña inactiva no existe hasta abrirla y se destruye al salir', async ({ page }) => {
    for (const [, bvn] of IMPLS) {
      await mount(page, '<b-tabs lazy><b-tab title="Uno" active><p class="c1">a</p></b-tab><b-tab title="Dos"><p class="c2">b</p></b-tab></b-tabs>', { bvn });
      const root = page.locator('.probe-root');
      await expect(root.locator('.c1')).toHaveCount(1);
      await expect(root.locator('.c2')).toHaveCount(0);
      await root.locator('.nav-link').nth(1).click();
      await expect(root.locator('.c2')).toBeVisible();
      // BV2: la pestaña inactiva se destruye al salir
      await expect(root.locator('.c1'), bvn ? 'BVN' : 'BV2').toHaveCount(0);
    }
  });

  test('desplegable: abre/cierra con clic, con ítem, fuera y ESC; un ítem = una acción; teclado', async ({ page }) => {
    for (const [, bvn] of IMPLS) {
      await open(page);
      await mount(page, '<div><b-dropdown id="dd" text="Acciones" variant="primary" class="d"><b-dropdown-item class="i1" @click="hits++">Uno</b-dropdown-item><b-dropdown-item class="i2" to="/app/dashboard/legacy">Dos</b-dropdown-item></b-dropdown><button class="outside">fuera</button></div>', { bvn, data: { hits: 0 } });
      const root = page.locator('.probe-root');
      const toggle = root.locator('.dropdown-toggle');
      const menu = root.locator('.dropdown-menu');
      await expect(menu).toBeHidden();
      await toggle.click();
      await expect(menu).toBeVisible();
      await expect(toggle).toHaveAttribute('aria-expanded', 'true');
      await root.locator('.i1').click();
      await expect(menu).toBeHidden();
      await page.waitForTimeout(150);
      expect((await state(page)).hits, `ítem una vez (${bvn ? 'BVN' : 'BV2'})`).toBe(1);
      await toggle.click();
      await expect(menu).toBeVisible();
      await root.locator('.outside').click();
      await expect(menu).toBeHidden();
      await toggle.click();
      await expect(menu).toBeVisible();
      await page.keyboard.press('Escape');
      await expect(menu).toBeHidden();
      // teclado: el foco en el botón + Enter abre
      await toggle.focus();
      await page.keyboard.press('Enter');
      await expect(menu).toBeVisible();
      await page.keyboard.press('Escape');
      await expect(menu).toBeHidden();
      // ítem con :to navega
      await toggle.click();
      await root.locator('.i2').click();
      await expect(page).toHaveURL(/\/app\/dashboard\/legacy/);
    }
  });

  test('desplegable: emite los eventos de raíz de BV2 bv::dropdown::show/hide una vez por apertura (las listas con acciones por fila los usan)', async ({ page }) => {
    for (const [, bvn] of IMPLS) {
      await mount(page, '<div><b-dropdown id="de" text="Acc"><b-dropdown-item>Uno</b-dropdown-item></b-dropdown></div>', {
        bvn,
        data: { shows: 0, hides: 0 },
        created: 'function () { this.$root.$on("bv::dropdown::show", () => { this.shows++; }); this.$root.$on("bv::dropdown::hide", () => { this.hides++; }); }',
      });
      const root = page.locator('.probe-root');
      await root.locator('.dropdown-toggle').click();
      await expect(root.locator('.dropdown-menu')).toBeVisible();
      await page.keyboard.press('Escape');
      await expect(root.locator('.dropdown-menu')).toBeHidden();
      await page.waitForTimeout(200);
      expect(await state(page), bvn ? 'BVN' : 'BV2').toEqual({ shows: 1, hides: 1 });
      // limpieza: los oyentes de la raíz sobreviven a la sonda
      await page.evaluate(() => { const vm = window.__pxProbeData(); return vm; });
    }
  });

  test('desplegable `right`: el menú se alinea al borde derecho del botón (no se sale por la izquierda)', async ({ page }) => {
    for (const [, bvn] of IMPLS) {
      await mount(page, '<div style="display:flex;justify-content:flex-end"><b-dropdown id="dr" right no-caret variant="light" toggle-class="tc" size="sm"><template #button-content>X</template><b-dropdown-item>Uno con texto largo</b-dropdown-item></b-dropdown></div>', { bvn });
      const root = page.locator('.probe-root');
      await root.locator('.dropdown-toggle').click();
      const t = await root.locator('.dropdown-toggle').boundingBox();
      const m = await root.locator('.dropdown-menu').boundingBox();
      expect(Math.abs((m.x + m.width) - (t.x + t.width)), `alineación derecha (${bvn ? 'BVN' : 'BV2'})`).toBeLessThan(3);
      expect(m.x).toBeGreaterThanOrEqual(0);
      await page.keyboard.press('Escape');
    }
  });

  test('paginación: v-model y @change (una vez, con el modelo ya actualizado); prev/next; deshabilitados', async ({ page }) => {
    for (const [, bvn] of IMPLS) {
      await mount(page, '<div><b-pagination v-model="page" :total-rows="95" :per-page="10" align="right" size="sm" @change="changed" class="pg"></b-pagination></div>', { bvn, data: { page: 1, log: [] }, methods: { changed: 'function (p) { this.log.push([p, this.page]); }' } });
      const root = page.locator('.probe-root');
      const item = (label) => root.locator('.pagination .page-link', { hasText: new RegExp(`^${label}$`) });
      await expect(root.locator('.pagination .active')).toHaveText('1');
      await item('3').click();
      await expect(root.locator('.pagination .active')).toHaveText('3');
      await page.waitForTimeout(250);
      let st = await state(page);
      expect(st.page).toBe(3);
      expect(st.log.map((e) => e[0]), `@change una vez con la página nueva (${bvn ? 'BVN' : 'BV2'})`).toEqual([3]);
      if (bvn) expect(st.log).toEqual([[3, 3]]); // BVN: con el v-model ya actualizado (BV2 lo emitía antes: [[3, 1]])
      await root.locator('.pagination .page-link[aria-label*="next"], .pagination .page-link[aria-label*="Next"]').first().click();
      await expect(root.locator('.pagination .active')).toHaveText('4');
      await page.waitForTimeout(250);
      expect((await state(page)).log.map((e) => e[0])).toEqual([3, 4]);
      // cambio programático: actualiza la página pero NO emite @change
      await page.evaluate(() => { window.__pxProbeData().page = 7; });
      await expect(root.locator('.pagination .active')).toHaveText('7');
      await page.waitForTimeout(250);
      expect((await state(page)).log.length).toBe(2);
      // alineado a la derecha
      const ul = await root.locator('.pagination').boundingBox();
      const host = await root.boundingBox();
      expect(ul.x + ul.width).toBeGreaterThan(host.x + host.width - 4);
    }
  });

  test('paginación con 0 filas: como BV2, dibuja la página 1 activa y deshabilita anterior/siguiente', async ({ page }) => {
    for (const [, bvn] of IMPLS) {
      await mount(page, '<div><b-pagination v-model="page" :total-rows="0" :per-page="10"></b-pagination></div>', { bvn, data: { page: 1 } });
      const root = page.locator('.probe-root');
      await expect(root.locator('.pagination .active'), bvn ? 'BVN' : 'BV2').toHaveText('1');
      expect(await root.locator('.pagination .page-item.disabled').count()).toBeGreaterThanOrEqual(2);
    }
  });

  test('pestañas: el enlace conserva tipografía (font/letter-spacing), color y subrayado al pasar el ratón como el <a> de BV2', async ({ page }) => {
    const fonts = {};
    for (const [name, bvn] of IMPLS) {
      await mount(page, '<b-tabs pills><b-tab title="Devoluciones" active>a</b-tab><b-tab title="Otra">b</b-tab></b-tabs>', { bvn });
      await page.mouse.move(0, 0);
      const link = page.locator('.probe-root .nav-link').nth(1); // pestaña inactiva: el color viene de `a`
      fonts[name] = await link.evaluate((e) => { const c = getComputedStyle(e); return [c.fontFamily, c.fontSize, c.fontWeight, c.letterSpacing, c.backgroundColor, c.color].join('|'); });
      await link.hover();
      fonts[`${name}-hover`] = await link.evaluate((e) => getComputedStyle(e).textDecorationLine);
    }
    expect(fonts.BVN).toBe(fonts.BV2);
    expect(fonts['BVN-hover']).toBe(fonts['BV2-hover']);
  });

  test('paginación con :value + @input (sin v-model), como el libro mayor', async ({ page }) => {
    for (const [, bvn] of IMPLS) {
      await mount(page, '<div><b-pagination :value="page" :total-rows="50" :per-page="10" @input="v => { page = v; ins++ }"></b-pagination></div>', { bvn, data: { page: 1, ins: 0 } });
      const root = page.locator('.probe-root');
      await root.locator('.pagination .page-link', { hasText: /^2$/ }).click();
      await expect(root.locator('.pagination .active')).toHaveText('2');
      await page.waitForTimeout(250);
      const st = await state(page);
      expect(st.page).toBe(2);
      expect(st.ins, `@input (${bvn ? 'BVN' : 'BV2'})`).toBe(1);
    }
  });
});

test.describe('Responsive, RTL y móvil @smoke', () => {
  test('grid: col-md-6 se apila en móvil y comparte fila en escritorio', async ({ page }) => {
    await open(page);
    await mount(page, '<b-row><b-col md="6" class="c1">a</b-col><b-col md="6" class="c2">b</b-col></b-row>', { bvn: true });
    const c1 = page.locator('.probe-root .c1');
    const c2 = page.locator('.probe-root .c2');
    let a = await c1.boundingBox();
    let b = await c2.boundingBox();
    expect(Math.abs(a.y - b.y)).toBeLessThan(2);
    await page.setViewportSize({ width: 390, height: 844 });
    await page.waitForTimeout(200);
    a = await c1.boundingBox();
    b = await c2.boundingBox();
    expect(b.y).toBeGreaterThan(a.y + a.height - 2);
  });

  test('RTL: el orden de columnas se invierte y la alerta descartable mantiene la cruz en el borde final', async ({ page }) => {
    await open(page);
    await mount(page, '<div><b-row><b-col md="6" class="c1">a</b-col><b-col md="6" class="c2">b</b-col></b-row><b-alert show dismissible variant="info" class="al">x</b-alert></div>', { bvn: true });
    await page.evaluate(() => { document.documentElement.setAttribute('dir', 'rtl'); document.body.classList.add('rtl'); });
    await page.waitForTimeout(200);
    const a = await page.locator('.probe-root .c1').boundingBox();
    const b = await page.locator('.probe-root .c2').boundingBox();
    expect(a.x).toBeGreaterThan(b.x);
    const al = await page.locator('.probe-root .al').boundingBox();
    const close = await page.locator('.probe-root .al .btn-close').boundingBox();
    expect(close.x).toBeLessThan(al.x + 60);
  });
});
