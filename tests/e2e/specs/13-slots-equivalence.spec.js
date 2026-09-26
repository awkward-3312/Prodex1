const { test, expect } = require('../support/fixtures');

/**
 * Equivalencia real de slots (sintaxis antigua `slot`/`slot-scope` vs nueva `v-slot`/`#slot`) para los patrones
 * `.sync`/`.native` de px-next. Las 4 comprobaciones históricas que usaban el paquete `vue-good-table` como
 * librería de referencia se retiraron: ese paquete ya no existe en la app (ver components/VueGoodTable.vue,
 * fase vue3-legacy-ui-dependencies) y su slot #table-row/#table-actions/#emptystate/#selected-row-actions se
 * prueba de forma funcional contra el componente real en tests/e2e/specs/27-btable-pilot.spec.js y similares.
 */
test.use({ storageState: { cookies: [], origins: [] } });

// Vue 2: `vue/dist/vue.js`. Vue 3 (@vue/compat): su build global (con compilador).
const IS_COMPAT = String(require('vue/package.json').version).startsWith('3');
const VUE_UMD_ID = IS_COMPAT ? '@vue/compat/dist/vue.global.js' : 'vue/dist/vue.js';
const VUE_UMD = require.resolve(VUE_UMD_ID);

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
      Vue.config.productionTip = false;
      Vue.component('probe-pager', {
        props: ['page', 'perPage'],
        template: '<div><button id="go" @click="$emit(\'update:page\', page + 1)">go</button><button id="size" @click="$emit(\'update:perPage\', 50)">size</button></div>',
      });
      Vue.component('probe-input', {
        inheritAttrs: false,
        props: ['value'],
        computed: { listeners() { return { ...this.$listeners, input: (e) => this.$emit('input', e.target.value) }; } },
        template: '<div class="wrap"><input :value="value" v-on="listeners" /></div>',
      });
      Vue.component('probe-button', {
        template: '<button v-on="$listeners"><slot /></button>',
      });
      const el = document.createElement('div');
      document.getElementById('host').appendChild(el);
      const vm = new Vue({ el, template: `<div>${tpl}</div>`, data: () => ({ page: 1, perPage: 10, hits: 0, clicks: 0, outer: 0 }), methods: { hit() { this.hits++; }, outerClick() { this.outer++; } } });
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
    if (IS_COMPAT) return; // `.sync` ya no existe en las plantillas; solo se valida la forma explícita
    await page.reload();
    await page.setContent('<!doctype html><html><body><div id="host"></div></body></html>');
    await page.addScriptTag({ path: VUE_UMD });
    const old = await run(page, '<probe-pager :page.sync="page" :per-page.sync="perPage" />');
    expect(old).toEqual(neu);
  });

  test('.native.enter sobre un input propio == @keyup.enter (listeners reenviados al <input>)', async ({ page }) => {
    const neu = await run(page, '<probe-input :value="\'x\'" @keyup.enter="hit" />');
    expect(neu.hits).toBe(2);
    if (IS_COMPAT) return; // `.native` fue eliminado en Vue 3: solo se valida la forma nueva
    await page.setContent('<!doctype html><html><body><div id="host"></div></body></html>');
    await page.addScriptTag({ path: VUE_UMD });
    const old = await run(page, '<probe-input :value="\'x\'" @keyup.native.enter="hit" />');
    expect(old.hits).toBe(neu.hits);
  });

  test('@click.stop.native == @click.stop sobre un botón propio (no burbujea al padre)', async ({ page }) => {
    const mk = (mod) => `<div @click="outerClick"><probe-button class="stop" @click${mod}="hit">x</probe-button></div>`;
    const neu = await run(page, mk('.stop'));
    expect(neu).toMatchObject({ hits: 1, outer: 0 });
    if (IS_COMPAT) return;
    await page.setContent('<!doctype html><html><body><div id="host"></div></body></html>');
    await page.addScriptTag({ path: VUE_UMD });
    const old = await run(page, mk('.stop.native'));
    expect(old).toEqual(neu);
  });
});
