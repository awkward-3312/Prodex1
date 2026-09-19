const { test, expect } = require('../support/fixtures');

/**
 * Equivalencia real de slots: se renderiza en el navegador, con las MISMAS librerías que usa el producto (Vue 2.7,
 * BootstrapVue 2.23, vue-good-table 2.21), la sintaxis antigua (`slot` / `slot-scope`) y la nueva (`v-slot` / `#slot`) y se
 * exige HTML idéntico. No necesita servidor ni base de datos: cubre las transformaciones que se hicieron en ~100 vistas.
 */
test.use({ storageState: { cookies: [], origins: [] } });

// Vue 2: `vue/dist/vue.js`. Vue 3 (@vue/compat): su build global (con compilador).
const IS_COMPAT = String(require('vue/package.json').version).startsWith('3');
const VUE_UMD_ID = IS_COMPAT ? '@vue/compat/dist/vue.global.js' : 'vue/dist/vue.js';
const VUE_UMD = require.resolve(VUE_UMD_ID);

const CASES = {
  'b-table: slot de celda con scope (cell(x))': {
    old: `<b-table id="t" :items="items" :fields="fields"><template slot="cell(a)" slot-scope="d">A:{{ d.value }}</template><template slot="cell(b)" slot-scope="{ item }"><i>B:{{ item.b }}</i></template></b-table>`,
    neu: `<b-table id="t" :items="items" :fields="fields"><template #cell(a)="d">A:{{ d.value }}</template><template #cell(b)="{ item }"><i>B:{{ item.b }}</i></template></b-table>`,
    expects: ['A:1', 'B:2'],
  },
  'b-dropdown: slot sin scope (button-content) en un elemento': {
    old: `<b-dropdown id="d" text="x"><span slot="button-content">CONTENIDO</span><b-dropdown-item>i</b-dropdown-item></b-dropdown>`,
    neu: `<b-dropdown id="d" text="x"><template #button-content><span>CONTENIDO</span></template><b-dropdown-item>i</b-dropdown-item></b-dropdown>`,
    expects: ['CONTENIDO'],
  },
  'b-tabs: slot title en <template>': {
    old: `<b-tabs id="tabs"><b-tab id="tab1" active><template slot="title"><b>TITULO</b></template>cuerpo</b-tab></b-tabs>`,
    neu: `<b-tabs id="tabs"><b-tab id="tab1" active><template #title><b>TITULO</b></template>cuerpo</b-tab></b-tabs>`,
    expects: ['TITULO', 'cuerpo'],
  },
  'b-modal: slots de cabecera y pie': {
    old: `<b-modal id="m" static visible><div slot="modal-title">TIT</div><div slot="modal-footer">PIE</div>cuerpo</b-modal>`,
    neu: `<b-modal id="m" static visible><template #modal-title><div>TIT</div></template><template #modal-footer><div>PIE</div></template>cuerpo</b-modal>`,
    expects: ['TIT', 'PIE', 'cuerpo'],
  },
  'vue-good-table: table-row, table-actions y emptystate': {
    old: `<vue-good-table :columns="cols" :rows="rows"><template slot="table-row" slot-scope="props"><span>R:{{ props.row.a }}</span></template><div slot="table-actions" class="x">ACT</div></vue-good-table>`,
    neu: `<vue-good-table :columns="cols" :rows="rows"><template #table-row="props"><span>R:{{ props.row.a }}</span></template><template #table-actions><div class="x">ACT</div></template></vue-good-table>`,
    expects: ['R:7', 'ACT'],
  },
  'vue-good-table: emptystate': {
    old: `<vue-good-table :columns="cols" :rows="[]"><div slot="emptystate">VACIO</div></vue-good-table>`,
    neu: `<vue-good-table :columns="cols" :rows="[]"><template #emptystate><div>VACIO</div></template></vue-good-table>`,
    expects: ['VACIO'],
  },
  'vue-good-table: selected-row-actions con v-if (la condición pasa al wrapper)': {
    old: `<vue-good-table :columns="cols" :rows="rows" :select-options="{ enabled: true }"><div slot="selected-row-actions" v-if="show">SEL</div></vue-good-table>`,
    neu: `<vue-good-table :columns="cols" :rows="rows" :select-options="{ enabled: true }"><template v-if="show" #selected-row-actions><div>SEL</div></template></vue-good-table>`,
    expects: [],
  },
  'vue-good-table: dos elementos contiguos en el mismo slot se fusionan': {
    old: `<vue-good-table :columns="cols" :rows="rows"><div slot="table-actions" class="p">UNO</div><div slot="table-actions" class="q">DOS</div></vue-good-table>`,
    neu: `<vue-good-table :columns="cols" :rows="rows"><template #table-actions><div class="p">UNO</div><div class="q">DOS</div></template></vue-good-table>`,
    expects: ['UNO', 'DOS'],
  },
};

const normalize = (html) => html.replace(/__BVID__\d+/g, '__BVID__').replace(/z-index: \d+/g, 'z-index: N').replace(/\s+/g, ' ').trim();

test.describe('Slots: sintaxis antigua y v-slot renderizan lo mismo @smoke', () => {
  test.beforeEach(async ({ page }) => {
    await page.setContent('<!doctype html><html><body><div id="host"></div></body></html>');
    for (const lib of [VUE_UMD_ID, 'bootstrap-vue/dist/bootstrap-vue.js', 'vue-good-table/dist/vue-good-table.js']) {
      await page.addScriptTag({ path: require.resolve(lib) });
    }
    await page.evaluate(() => {
      window.Vue.config.productionTip = false;
      window.Vue.config.devtools = false;
      window.Vue.use(window.bootstrapVue.BootstrapVue || window.BootstrapVue);
      const vgt = window['vue-good-table'];
      window.Vue.use(vgt.default || vgt);
    });
  });

  for (const [name, c] of Object.entries(CASES)) {
    test(name, async ({ page }) => {
      const render = (template) =>
        page.evaluate(async (tpl) => {
          const el = document.createElement('div');
          document.getElementById('host').appendChild(el);
          const vm = new window.Vue({
            el,
            template: `<div>${tpl}</div>`,
            data: () => ({
              items: [{ a: 1, b: 2 }],
              fields: ['a', 'b'],
              cols: [{ label: 'A', field: 'a' }],
              rows: [{ a: 7 }],
              show: true,
            }),
          });
          await vm.$nextTick();
          await new Promise((r) => setTimeout(r, 50));
          return vm.$el.innerHTML;
        }, template);

      const after = normalize(await render(c.neu));
      for (const fragment of c.expects) expect(after).toContain(fragment);
      if (IS_COMPAT) {
        // Bajo @vue/compat la sintaxis antigua (`slot` / `slot-scope`) se descarta en silencio: solo se exige que la nueva funcione.
        const before = normalize(await render(c.old));
        test.info().annotations.push({ type: 'sintaxis antigua bajo compat', description: before === after ? 'igual' : 'distinta (esperado)' });
        return;
      }
      const before = normalize(await render(c.old));
      for (const fragment of c.expects) expect(before).toContain(fragment);
      expect(after).toBe(before);
    });
  }
});

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
