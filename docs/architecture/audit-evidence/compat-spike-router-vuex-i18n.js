const { JSDOM } = require('jsdom');
const dom = new JSDOM('<!doctype html><html><head><title>t</title></head><body></body></html>', { pretendToBeVisual: true, url: 'http://localhost/' });
for (const k of ['window','document','navigator','HTMLElement','Element','Node','MutationObserver','getComputedStyle','requestAnimationFrame','cancelAnimationFrame','CustomEvent','Event','SVGElement','DOMParser','NodeFilter','localStorage','history','location']) { try { global[k] = dom.window[k]; } catch (e) {} }
process.on('uncaughtException', e => console.log('UNCAUGHT', String(e).slice(0,160)));
const V = require('vue').default || require('vue'); V.configureCompat({ MODE: 2 });
const ow = console.warn; console.warn = () => {}; const oe = console.error; console.error = () => {};
const tick = (ms = 60) => new Promise(r => setTimeout(r, ms));
const mount = async (o) => { const el = document.createElement('div'); document.body.appendChild(el); const vm = new V(Object.assign({ el }, o)); await tick(); return vm; };
(async () => {
  const out = []; const t = async (n, f) => { try { out.push('PASS ' + n + ' :: ' + (await f() || '')); } catch (e) { out.push('FAIL ' + n + ' :: ' + (e.message || e).slice(0, 220)); } };
  const VR = require('vue-router3'); const R = VR.default || VR; V.use(R);
  const VX = require('vuex3'); V.use(VX.default || VX);
  await t('VR3 history mode + async beforeEach(next) + named route params + addRoutes + "*" + push().catch', async () => {
    const router = new R({ mode: 'history', routes: [{ path: '/', component: { template: '<i>h</i>' } }, { path: '/u/:id', name: 'u', component: { template: '<i class="u">u{{$route.params.id}}</i>' } }, { path: '*', component: { template: '<i>404</i>' } }] });
    let guards = 0; router.beforeEach(async (to, from, next) => { guards++; await tick(5); next(); });
    router.addRoutes([{ path: '/late', name: 'late', component: { template: '<i>late</i>' } }]);
    const orig = R.prototype.push; R.prototype.push = function (l, a, b) { if (a || b) return orig.call(this, l, a, b); return orig.call(this, l).catch(e => e); };
    const vm = await mount({ router, template: '<div><router-link :to="{name:\'u\',params:{id:5}}" class="lk">l</router-link><router-view/></div>' });
    await router.push({ name: 'u', params: { id: 9 } }); await tick(); if (!/u9/.test(vm.$el.textContent)) throw new Error('named+params failed: ' + vm.$el.textContent);
    await router.push('/late'); await tick(); if (!/late/.test(vm.$el.textContent)) throw new Error('addRoutes route not resolved: ' + vm.$el.textContent);
    await router.push('/nope'); await tick(); if (!/404/.test(vm.$el.textContent)) throw new Error('* catch-all failed');
    const dupe = await router.push('/nope'); if (guards < 3) throw new Error('guards ran ' + guards);
    if (!window.location.pathname) throw new Error('history not used'); return 'guards=' + guards + ' path=' + window.location.pathname;
  });
  await t('router-link renders <a href> with active class (default tag)', async () => {
    const router = new R({ mode: 'history', routes: [{ path: '/x', component: { template: '<i>x</i>' } }] }); await router.push('/x');
    const vm = await mount({ router, template: '<div><router-link to="/x" class="lk">l</router-link></div>' }); const a = vm.$el.querySelector('a.lk'); if (!a || !a.getAttribute('href')) throw new Error('no <a href>: ' + vm.$el.outerHTML); return a.className;
  });
  await t('Vuex3 namespaced module + mapGetters/mapActions + dispatch', async () => {
    const S = VX.Store || VX.default.Store; const store = new S({ modules: { config: { namespaced: true, state: { c: 1 }, getters: { g: s => s.c }, mutations: { set(s, v) { s.c = v; } }, actions: { up({ commit }, v) { commit('set', v); } } } } });
    const vm = await mount({ store, computed: VX.mapGetters('config', ['g']), methods: VX.mapActions('config', ['up']), template: '<div>{{ g }}</div>' }); vm.up(7); await tick(); if (!/7/.test(vm.$el.textContent)) throw new Error('not reactive: ' + vm.$el.textContent);
  });
  await t('vue-meta metaInfo updates document.title', async () => { const M = require('vue-meta'); V.use(M.default || M, { keyName: 'metaInfo' }); await mount({ metaInfo: { title: 'PRODEX-T' }, template: '<div/>' }); await tick(100); if (document.title !== 'PRODEX-T') throw new Error('title=' + document.title); });
  await t('vue-i18n8 $t + setLocaleMessage + locale switch', async () => { const I = require('vue-i18n8'); V.use(I.default || I); const i18n = new (I.default || I)({ locale: 'es', fallbackLocale: 'es', messages: { es: { a: 'uno' } } }); const vm = await mount({ i18n, template: '<div>{{ $t("a") }}|{{ $t("zz") }}</div>' }); i18n.setLocaleMessage('ar', { a: 'واحد' }); i18n.locale = 'ar'; await tick(); return vm.$el.textContent; });
  console.warn = ow; console.error = oe; console.log(out.join('\n')); process.exit(0);
})();
