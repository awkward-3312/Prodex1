const { test, expect } = require('../support/fixtures');

/**
 * Forma explícita de `.sync`/`.native` (patrones de Vue 2 sin equivalente en Vue 3) sobre componentes propios de
 * px-next, contra el `vue` real (fase vue3-pure-runtime: sin @vue/compat, no queda ningún runtime de Vue 2 en
 * node_modules con el que comparar "antes/después" — las plantillas de la app ya no usan `.sync`/`.native` en
 * absoluto, así que solo se valida que la forma explícita (`:x="v" @update:x="v = $event"`, `@keyup.enter`)
 * funciona). Las 4 comprobaciones históricas de slots que usaban el paquete `vue-good-table` como librería de
 * referencia se retiraron: ese paquete ya no existe en la app (ver components/VueGoodTable.vue, fase
 * vue3-legacy-ui-dependencies) y su slot #table-row/#table-actions/#emptystate/#selected-row-actions se prueba
 * de forma funcional contra el componente real en tests/e2e/specs/27-btable-pilot.spec.js y similares.
 */
test.use({ storageState: { cookies: [], origins: [] } });

// Build global (UMD, con compilador) del `vue` real de la app.
const VUE_UMD = require.resolve('vue/dist/vue.global.js');

// `.sync` y `.native` sobre componentes propios del patrón px-next (PxPagination / PxInput / PxButton): la forma explícita
// que se usa ahora (`:x="v" @update:x="v = $event"`, `@keyup.enter`) debe comportarse igual que la antigua.
test.describe('.sync / .native: forma explícita equivalente a la antigua @smoke', () => {
  test.beforeEach(async ({ page }) => {
    await page.setContent('<!doctype html><html><body><div id="host"></div></body></html>');
    await page.addScriptTag({ path: VUE_UMD });
  });

  const run = (page, template) =>
    page.evaluate(async (tpl) => {
      const Vue = window.Vue;
      // Vue 3 real: sin `Vue.component`/`new Vue({el})` globales (API de Vue 2) — `createApp(...)` propio por
      // prueba, componentes registrados en ÉL, y `$listeners` ya no existe (los listeners viajan en `$attrs`,
      // que cae solo en la raíz salvo `inheritAttrs: false`).
      const app = Vue.createApp({
        template: `<div>${tpl}</div>`,
        data: () => ({ page: 1, perPage: 10, hits: 0, clicks: 0, outer: 0 }),
        methods: { hit() { this.hits++; }, outerClick() { this.outer++; } },
      });
      app.component('probe-pager', {
        props: ['page', 'perPage'],
        template: '<div><button id="go" @click="$emit(\'update:page\', page + 1)">go</button><button id="size" @click="$emit(\'update:perPage\', 50)">size</button></div>',
      });
      app.component('probe-input', {
        inheritAttrs: false,
        props: ['value'],
        template: '<div class="wrap"><input :value="value" v-bind="$attrs" /></div>',
      });
      app.component('probe-button', {
        template: '<button><slot /></button>',
      });
      const el = document.createElement('div');
      document.getElementById('host').appendChild(el);
      const vm = app.mount(el);
      await vm.$nextTick();
      const out = {};
      document.querySelector('#go') && document.querySelector('#go').click();
      document.querySelector('#size') && document.querySelector('#size').click();
      await vm.$nextTick();
      out.page = vm.page;
      out.perPage = vm.perPage;
      const input = vm.$el.querySelector('input');
      if (input) {
        for (const key of ['a', 'Enter', 'Enter']) input.dispatchEvent(new KeyboardEvent('keyup', { key, keyCode: key === 'Enter' ? 13 : 65, bubbles: true }));
      }
      const btn = vm.$el.querySelector('button.stop');
      if (btn) btn.click();
      out.hits = vm.hits;
      out.outer = vm.outer;
      return out;
    }, template);

  test('.sync: el padre recibe update:page y update:perPage igual que antes', async ({ page }) => {
    const expected = { page: 2, perPage: 50 };
    const neu = await run(page, '<probe-pager :page="page" @update:page="page = $event" :per-page="perPage" @update:perPage="perPage = $event" />');
    expect(neu).toMatchObject(expected);
  });

  test('.native.enter sobre un input propio == @keyup.enter (listeners reenviados al <input>)', async ({ page }) => {
    const neu = await run(page, '<probe-input :value="\'x\'" @keyup.enter="hit" />');
    expect(neu.hits).toBe(2);
  });

  test('@click.stop.native == @click.stop sobre un botón propio (no burbujea al padre)', async ({ page }) => {
    const mk = (mod) => `<div @click="outerClick"><probe-button class="stop" @click${mod}="hit">x</probe-button></div>`;
    const neu = await run(page, mk('.stop'));
    expect(neu).toMatchObject({ hits: 1, outer: 0 });
  });
});
