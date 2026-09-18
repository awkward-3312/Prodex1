const { JSDOM } = require('jsdom');
const dom = new JSDOM('<!doctype html><html><body><div id="root"></div></body></html>', { pretendToBeVisual: true, url: 'http://localhost/' });
for (const k of ['window','document','navigator','HTMLElement','Element','Node','MutationObserver','getComputedStyle','requestAnimationFrame','cancelAnimationFrame','CustomEvent','Event','KeyboardEvent','MouseEvent','SVGElement','DOMParser','NodeFilter','FormData','File','FileList','Blob','HTMLInputElement','localStorage']) { try { global[k] = dom.window[k]; } catch (e) {} }
global.window = dom.window;
const log = [];
const origWarn = console.warn, origErr = console.error;
let cur = '';
console.warn = (...a) => log.push({ t: cur, l: 'warn', m: a.map(String).join(' ').slice(0, 300) });
console.error = (...a) => log.push({ t: cur, l: 'error', m: a.map(String).join(' ').slice(0, 300) });
const Vue = require('vue');
const V = Vue.default || Vue;
console.log('vue version', V.version);
V.configureCompat({ MODE: 2 });
const results = [];
async function run(name, fn) {
  cur = name; const before = log.length;
  let status = 'ok', detail = '';
  try { await fn(); } catch (e) { status = 'THROW'; detail = (e && e.message || String(e)).slice(0, 250); }
  const msgs = log.slice(before).filter(x => x.t === name);
  results.push({ name, status, detail, warn: msgs.filter(m => m.l === 'warn').length, err: msgs.filter(m => m.l === 'error').length, first: msgs.filter(m => m.l === 'error').slice(0, 2).map(m => m.m).concat(msgs.filter(m => m.l === 'warn').slice(0, 1).map(m => m.m)) });
}
const tick = (ms = 30) => new Promise(r => setTimeout(r, ms));
async function mount(opts, setup) {
  const el = document.createElement('div'); document.body.appendChild(el);
  const vm = new V(Object.assign({ el }, opts));
  await tick();
  if (setup) await setup(vm);
  return { vm, html: vm.$el ? vm.$el.outerHTML : '' };
}
let BV;
(async () => {
  await run('load bootstrap-vue (require + Vue.use)', async () => { BV = require('bootstrap-vue'); V.use(BV.BootstrapVue || BV.default || BV); });
  await run('b-button/b-card/b-row/b-col/b-badge/b-alert', async () => {
    const { html } = await mount({ template: '<div><b-card title="t"><b-row><b-col md="6"><b-button variant="primary" @click="n++">go {{n}}</b-button><b-badge variant="info">b</b-badge></b-col></b-row></b-card><b-alert show variant="warning">a</b-alert></div>', data: () => ({ n: 0 }) });
    if (!/btn-primary/.test(html) || !/col-md-6/.test(html) || !/alert-warning/.test(html)) throw new Error('markup missing: ' + html.slice(0, 200));
  });
  await run('b-form-group + b-form-input v-model + invalid-feedback + select/checkbox/textarea', async () => {
    const { vm, html } = await mount({ template: '<b-form><b-form-group label="L" label-for="x"><b-form-input id="x" v-model="v" :state="false"/><b-form-invalid-feedback>bad</b-form-invalid-feedback></b-form-group><b-form-select v-model="s" :options="[{value:1,text:\'a\'},{value:2,text:\'b\'}]"/><b-form-checkbox v-model="c">c</b-form-checkbox><b-form-textarea v-model="t"/></b-form>', data: () => ({ v: 'abc', s: 2, c: true, t: 'tt' }) });
    const inp = vm.$el.querySelector('input#x'); if (!inp || inp.value !== 'abc') throw new Error('v-model value not rendered');
    inp.value = 'zzz'; inp.dispatchEvent(new window.Event('input')); await tick(); if (vm.v !== 'zzz') throw new Error('v-model write-back failed: ' + vm.v);
    if (!/is-invalid/.test(vm.$el.outerHTML)) throw new Error('state not applied');
  });
  await run('b-modal visible + $bvModal.show/hide', async () => {
    const { vm } = await mount({ template: '<div><b-modal id="m1" title="T" static>body</b-modal></div>' });
    vm.$bvModal.show('m1'); await tick(80);
    const shown = vm.$el.querySelector('.modal.show') || vm.$el.querySelector('.modal[style*="display: block"]') ; 
    if (!shown) throw new Error('modal did not open via $bvModal.show; html=' + vm.$el.outerHTML.slice(0, 200));
    vm.$bvModal.hide('m1'); await tick(80);
  });
  await run('b-modal v-model', async () => {
    const { vm } = await mount({ template: '<div><b-modal v-model="open" static title="x">b</b-modal></div>', data: () => ({ open: false }) });
    vm.open = true; await tick(80);
    if (!vm.$el.querySelector('.modal.show') && !vm.$el.querySelector('.modal[style*="display: block"]')) throw new Error('v-model open failed');
  });
  await run('$root.$bvToast.toast', async () => {
    const { vm } = await mount({ template: '<div>x</div>' });
    vm.$root.$bvToast.toast('hello', { title: 't', variant: 'success', solid: true }); await tick(100);
    if (!document.body.querySelector('.b-toast, .toast')) throw new Error('toast not in DOM');
  });
  await run('b-table + b-pagination + b-tabs + b-dropdown', async () => {
    const { vm } = await mount({ template: '<div><b-table :items="items" :fields="fields" small/><b-pagination v-model="p" :total-rows="30" :per-page="10"/><b-tabs><b-tab title="A" active>a</b-tab><b-tab title="B">b</b-tab></b-tabs><b-dropdown text="d"><b-dropdown-item>i</b-dropdown-item></b-dropdown></div>', data: () => ({ p: 1, items: [{ a: 1, b: 2 }], fields: ['a', 'b'] }) });
    if (!vm.$el.querySelector('table tbody tr td')) throw new Error('table not rendered');
    if (!vm.$el.querySelector('.pagination')) throw new Error('pagination missing');
    if (!vm.$el.querySelector('.nav-tabs')) throw new Error('tabs missing');
  });
  await run('b-table scoped slots (v-slot + slot-scope)', async () => {
    const { vm } = await mount({ template: '<div><b-table :items="items" :fields="fields"><template #cell(a)="d">A:{{d.value}}</template></b-table><b-table :items="items" :fields="fields"><template slot="cell(b)" slot-scope="d">B:{{d.value}}</template></b-table></div>', data: () => ({ items: [{ a: 1, b: 2 }], fields: ['a', 'b'] }) });
    const t = vm.$el.textContent; if (!/A:1/.test(t)) throw new Error('#cell slot failed'); if (!/B:2/.test(t)) throw new Error('slot-scope slot failed');
  });
  await run('b-form-file + b-form-datepicker + v-b-tooltip', async () => {
    await mount({ template: '<div><b-form-file v-model="f"/><b-form-datepicker v-model="d"/><span v-b-tooltip.hover title="tip">x</span></div>', data: () => ({ f: null, d: '2026-01-01' }) });
  });
  await run('vue-router 3.6.5 (Vue.use + new Router + router-view)', async () => {
    const VR = require('vue-router3'); V.use(VR.default || VR);
    const router = new (VR.default || VR)({ mode: 'abstract', routes: [{ path: '/', component: { template: '<i>home</i>' } }, { path: '/b', component: { template: '<i>bee</i>' } }] });
    const { vm } = await mount({ router, template: '<div><router-view/></div>' });
    router.push('/b'); await tick(50);
    if (!/bee/.test(vm.$el.textContent)) throw new Error('router-view did not render /b: ' + vm.$el.outerHTML);
  });
  await run('vuex 3.6.2 (Vue.use + Store + mapGetters)', async () => {
    const VX = require('vuex3'); V.use(VX.default || VX);
    const store = new (VX.Store || VX.default.Store)({ state: { n: 1 }, getters: { dbl: s => s.n * 2 }, mutations: { inc(s) { s.n++; } } });
    const { vm } = await mount({ store, template: '<div>{{ $store.getters.dbl }}</div>' });
    store.commit('inc'); await tick(); if (!/4/.test(vm.$el.textContent)) throw new Error('store not reactive: ' + vm.$el.textContent);
  });
  await run('vue-i18n 8.28.2 ($t)', async () => {
    const I = require('vue-i18n8'); V.use(I.default || I);
    const i18n = new (I.default || I)({ locale: 'es', messages: { es: { hi: 'hola {n}' } } });
    const { vm } = await mount({ i18n, template: '<div>{{ $t("hi", {n: 1}) }}</div>' });
    if (!/hola 1/.test(vm.$el.textContent)) throw new Error('$t failed: ' + vm.$el.textContent);
  });
  await run('vee-validate 3.4.15 (ValidationProvider/Observer)', async () => {
    const VV = require('vee-validate'); const rules = require('vee-validate/dist/rules');
    VV.extend('required', rules.required);
    const { vm } = await mount({ components: { ValidationProvider: VV.ValidationProvider, ValidationObserver: VV.ValidationObserver }, template: '<ValidationObserver v-slot="{ invalid }"><ValidationProvider rules="required" v-slot="{ errors }"><div><input v-model="v"/><span class="e">{{ errors[0] }}</span><b>{{ invalid }}</b></div></ValidationProvider></ValidationObserver>', data: () => ({ v: '' }) });
    await tick(120); vm.v = 'x'; await tick(120); vm.v = ''; await tick(150);
    const t = vm.$el.textContent; if (!/required|obligat|field/i.test(t)) throw new Error('no validation message after invalid: ' + t);
  });
  await run('vue-good-table 2.21.11', async () => {
    const VGT = require('vue-good-table'); V.use(VGT.default || VGT);
    const { vm } = await mount({ template: '<vue-good-table :columns="cols" :rows="rows"><template slot="table-row" slot-scope="props"><span>R:{{props.row.a}}</span></template></vue-good-table>', data: () => ({ cols: [{ label: 'A', field: 'a' }], rows: [{ a: 7 }] }) });
    if (!/R:7/.test(vm.$el.textContent)) throw new Error('rows/slot not rendered: ' + vm.$el.textContent.slice(0, 100));
  });
  await run('vue-select 3.20.4', async () => {
    const VS = require('vue-select'); V.component('v-select', VS.default || VS);
    const { vm } = await mount({ template: '<v-select v-model="v" :options="[\'a\',\'b\']"/>', data: () => ({ v: 'a' }) });
    if (!vm.$el.querySelector('.vs__selected')) throw new Error('selected not rendered: ' + vm.$el.outerHTML.slice(0, 160));
  });
  await run('vue2-daterange-picker 0.6.8', async () => {
    const DR = require('vue2-daterange-picker'); const C = DR.default || DR; V.component('date-range-picker', C);
    await mount({ template: '<date-range-picker v-model="r" :opens="\'left\'"/>', data: () => ({ r: { startDate: '2026-01-01', endDate: '2026-01-31' } }) });
  });
  await run('vuedraggable 2.24.3', async () => {
    const D = require('vuedraggable'); V.component('draggable', D.default || D);
    const { vm } = await mount({ template: '<draggable v-model="l"><div v-for="i in l" :key="i">{{i}}</div></draggable>', data: () => ({ l: [1, 2, 3] }) });
    if (!/123/.test(vm.$el.textContent.replace(/\s/g, ''))) throw new Error('list not rendered');
  });
  await run('vue-apexcharts 1.7.0', async () => {
    const A = require('vue-apexcharts'); V.component('apexchart', A.default || A);
    await mount({ template: '<apexchart type="bar" :series="[{data:[1,2]}]" />' });
  });
  await run('vue-meta 2.4.0 (metaInfo)', async () => {
    const M = require('vue-meta'); V.use(M.default || M, { keyName: 'metaInfo' });
    await mount({ metaInfo: { title: 'X' }, template: '<div>m</div>' });
  });
  await run('vue-sweetalert2 5.0.11 ($swal)', async () => {
    const S = require('vue-sweetalert2'); V.use(S.default || S);
    const { vm } = await mount({ template: '<div/>' }); if (typeof vm.$swal !== 'function') throw new Error('$swal not installed on instance (type ' + typeof vm.$swal + ')');
  });
  await run('lucide-vue 0.517 (functional render h)', async () => {
    const L = require('lucide-vue'); const I = L.Home || L.House; if (!I) throw new Error('no icon export');
    await mount({ components: { I }, template: '<div><I :size="16"/></div>' });
  });
  await run('Vue.prototype / Vue.mixin / Fire bus (new Vue() $on/$emit)', async () => {
    V.prototype.$foo = 'bar'; V.mixin({ mounted() { this.__m = 1; } });
    const bus = new V(); let got = 0; bus.$on('e', () => got++); bus.$emit('e'); bus.$off('e'); bus.$emit('e');
    const { vm } = await mount({ template: '<div>{{ $foo }}</div>' }); if (got !== 1 || !/bar/.test(vm.$el.textContent) || vm.__m !== 1) throw new Error('got=' + got);
  });
  await run('$set / $forceUpdate / filters / .native / .sync / $listeners / $scopedSlots', async () => {
    const Child = { props: ['v'], template: '<button @click="$emit(\'update:v\', v+1)">{{ v }}<slot/></button>' };
    const { vm } = await mount({ components: { Child }, filters: { up: s => String(s).toUpperCase() }, template: '<div>{{ "ab" | up }}<Child :v.sync="n" @click.native="k++">x</Child><i>{{ o.z }}</i></div>', data: () => ({ n: 1, k: 0, o: {} }) });
    vm.$set(vm.o, 'z', 'Z'); vm.$forceUpdate(); await tick(); if (!/AB/.test(vm.$el.textContent) || !/Z/.test(vm.$el.textContent)) throw new Error('filters/$set failed: ' + vm.$el.textContent);
    const btn = vm.$el.querySelector('button'); btn.click(); await tick(); if (vm.n !== 2) throw new Error('.sync failed n=' + vm.n); if (vm.k !== 1) throw new Error('.native failed k=' + vm.k);
  });
  console.warn = origWarn; console.error = origErr;
  for (const r of results) console.log((r.status === 'ok' && !r.err ? 'PASS ' : r.status === 'ok' ? 'PASS* ' : 'FAIL ') + r.name + ' | warnings=' + r.warn + ' errors=' + r.err + (r.detail ? ' | ' + r.detail : '') + (r.first.length ? '\n      > ' + r.first.join('\n      > ').replace(/\n/g, ' ') : ''));
  process.exit(0);
})();
