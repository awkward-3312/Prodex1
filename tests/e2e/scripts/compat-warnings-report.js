#!/usr/bin/env node
/**

 * (WARNINGS_DIR=ruta permite leer otra carpeta de avisos.)
 * Agrupa los avisos de @vue/compat capturados durante los E2E (tests/e2e/.artifacts/warnings/*.json, los escribe
 * support/fixtures.js) y los imprime como tabla Markdown: aviso, ocurrencias, componentes/archivos, riesgo y acción.
 *
 *   node tests/e2e/scripts/compat-warnings-report.js            # tabla completa
 *   node tests/e2e/scripts/compat-warnings-report.js --json     # datos crudos
 */
const fs = require('fs');
const path = require('path');

const ROOT = path.resolve(__dirname, '..', '..', '..');
const DIR = process.env.WARNINGS_DIR || path.join(ROOT, 'tests', 'e2e', '.artifacts', 'warnings');
const SRC = path.join(ROOT, 'resources', 'src');

// Clasificación de cada aviso conocido. riesgo: benigno | migrar | bloquea | comportamiento
const KNOWN = {
  PRIVATE_APIS: ['migrar', 'Accesos a `$options.parent`, `$vnode`, `_uid`… (Vue 2 privado). Casi todos vienen de BootstrapVue/vee-validate/vue-i18n; se van al migrarlos.'],
  OPTIONS_DESTROYED: ['migrar', 'Renombrar `destroyed` → `unmounted` (compat lo acepta; Vue 3 puro no).'],
  OPTIONS_BEFORE_DESTROY: ['migrar', 'Renombrar `beforeDestroy` → `beforeUnmount` (42 archivos propios + librerías).'],
  RENDER_FUNCTION: ['bloquea', 'Funciones `render(h)` de Vue 2 (BootstrapVue, vee-validate, vue-router 3): no existen en Vue 3 puro; se resuelve al migrar cada librería.'],
  COMPONENT_FUNCTIONAL: ['bloquea', 'Componentes `functional: true` (RouterView/RouterLink de vue-router 3, BootstrapVue): migrar con vue-router 4 / BootstrapVue 3.'],
  COMPONENT_ASYNC: ['migrar', 'Rutas con `() => import()` como componente: envolver con `defineAsyncComponent` (vue-router 4 lo hace solo).'],
  INSTANCE_LISTENERS: ['migrar', '`$listeners` (Px* y BootstrapVue): pasar a `$attrs` (`onX`).'],
  INSTANCE_SCOPED_SLOTS: ['migrar', '`$scopedSlots` (PxModal, vue-router 3, BootstrapVue): usar `$slots`.'],
  INSTANCE_CHILDREN: ['bloquea', '`$children` (vue-router 3 RouterView, BootstrapVue): sin equivalente; lo elimina vue-router 4 / BootstrapVue 3.'],
  INSTANCE_EVENT_HOOKS: ['migrar', 'Eventos `hook:mounted|beforeDestroy…` (vee-validate, vue-select, BootstrapVue): usar `@vue:mounted` o composición.'],
  INSTANCE_EVENT_EMITTER: ['migrar', '`vm.$on/$once/$off` (vee-validate, BootstrapVue, código propio): bus externo (`platform/events`).'],
  INSTANCE_SET: ['benigno', '`vm.$set` (mutación nativa en Vue 3): compat lo mantiene funcionando.'],
  GLOBAL_SET: ['benigno', '`Vue.set`: en Vue 3 basta la asignación directa.'],
  GLOBAL_EXTEND: ['migrar', '`Vue.extend` (BootstrapVue, vee-validate, vue-select…): `defineComponent`.'],
  GLOBAL_MOUNT: ['migrar', '`new Vue({ el }) / $mount` en los 4 entrypoints: `createApp(...).mount()`.'],
  GLOBAL_PROTOTYPE: ['migrar', '`Vue.prototype.$x` (plugins: vue-i18n 8, vue-cookies…): `app.config.globalProperties`.'],
  GLOBAL_PRIVATE_UTIL: ['bloquea', '`Vue.util` (vue-meta 2, vue-clickaway, vue-router 3): utilidades internas de Vue 2 sin equivalente.'],
  CONFIG_OPTION_MERGE_STRATS: ['migrar', '`config.optionMergeStrategies` (vuex/vue-i18n/BootstrapVue).'],
  CONFIG_SILENT: ['benigno', '`Vue.config.silent = true` (main.js): no existe en Vue 3; ver docs (los avisos NO se silencian).'],
  OPTIONS_DATA_FN: ['comportamiento', '`data` como objeto (algún componente de librería): en Vue 3 debe ser función.'],
  OPTIONS_DATA_MERGE: ['comportamiento', 'Fusión de `data` de Vue 2 (superficial) vs Vue 3: puede cambiar valores por defecto anidados.'],
  WATCH_ARRAY: ['comportamiento', '`watch` sobre arrays ya no dispara con mutaciones si no es `deep`: revisar watchers de arrays.'],
  ATTR_FALSE_VALUE: ['comportamiento', '`:attr="false"` renderiza `attr="false"` en Vue 3 en vez de quitar el atributo (BFormCheckbox `checked`…).'],
  ATTR_ENUMERATED_COERCION: ['comportamiento', 'Atributos enumerados (`draggable`, `contenteditable`) con booleano.'],
  COMPONENT_V_MODEL: ['migrar', '`v-model` sobre componentes con `model: { prop, event }` de Vue 2: `modelValue` / `update:modelValue`.'],
  CUSTOM_DIR: ['migrar', 'Directivas con hooks de Vue 2 (`bind/inserted/update/componentUpdated/unbind`): renombrar a `beforeMount/mounted/…`.'],
  TRANSITION_CLASSES: ['comportamiento', '`v-enter`→`v-enter-from`, `v-leave`→`v-leave-from` en CSS de transiciones.'],
  TRANSITION_GROUP_ROOT: ['comportamiento', '`<transition-group>` ya no renderiza `<span>` por defecto.'],
  COMPILER_V_ON_NATIVE: ['migrar', '`@evento.native` (router-link en PxShell/PortalLayout).'],
  COMPILER_V_BIND_OBJECT_ORDER: ['comportamiento', '`v-bind="obj"` es sensible al orden respecto a atributos sueltos.'],
  COMPILER_V_BIND_SYNC: ['migrar', '`.sync`: ya eliminado del código propio.'],
  COMPILER_V_IF_V_FOR_PRECEDENCE: ['comportamiento', '`v-if` y `v-for` en el mismo elemento: cambia la precedencia.'],
  COMPILER_NATIVE_TEMPLATE: ['comportamiento', '`<template>` sin directiva se renderiza como elemento nativo en compat.'],
  COMPILER_FILTERS: ['migrar', 'Filtros `{{ x | f }}`.'],
  FILTERS: ['migrar', 'Filtros de Vue 2.'],
  ATTR_ENUMERATED: ['comportamiento', 'Atributos enumerados.'],
  'Invalid prop: type check failed for prop "rtl". Expected Boolean, got String': ['comportamiento', 'vue-good-table recibe `rtl` como cadena ("ltr"/"rtl") en la prop booleana; en Vue 3 se evalúa como verdadero. Revisar pantallas RTL al migrar.'],
  GLOBAL_OBSERVABLE: ['migrar', '`Vue.observable` → `reactive`.'],
  CONFIG_KEY_CODES: ['migrar', '`Vue.config.keyCodes`.'],
  CONFIG_WHITESPACE: ['benigno', 'Whitespace `condense` (por defecto en Vue 3).'],
  PROPS_DEFAULT_THIS: ['comportamiento', 'Función `default` de una prop sin acceso a `this` (vee-validate `mode`/`bails`).'],
  PROPS_ARRAY: ['comportamiento', 'Props tipadas Array/Object con `default`.'],
  V_ON_KEYCODE_MODIFIER: ['migrar', 'Modificadores numéricos `@keyup.13`.'],
  INSTANCE_DELETE: ['benigno', '`vm.$delete`.'],
  INSTANCE_ATTRS_CLASS_STYLE: ['comportamiento', '`class`/`style` ya forman parte de `$attrs`.'],
  INSTANCE_DESTROY: ['migrar', '`vm.$destroy()`.'],
  INSTANCE_GET_ATTRS: ['migrar', '`$attrs`'],
  GLOBAL_DELETE: ['benigno', '`Vue.delete`.'],
  GLOBAL_NEXT_TICK: ['benigno', '`Vue.nextTick`.'],
  GLOBAL_COMPONENT: ['benigno', '`Vue.component`.'],
  REACTIVE_READONLY_ROUTE: ['benigno', 'vue-router 3 vuelve reactivo `$route` con `Vue.util.defineReactive`; los campos ya son de solo lectura y compat lo ignora (la ruta reactiva funciona: navegación verificada). Desaparece con vue-router 4.'],
  COMPONENT_ALREADY_REGISTERED: ['benigno', 'BootstrapVue/vue-router registran sus componentes en más de un `Vue.use` (stocky.kit, login, i18n). Sin efecto; se limpia al unificar la instalación de plugins.'],
  RENDER_PROPERTY_UNDEFINED: ['benigno', 'Lectura de propiedades internas no declaradas (`_resolvedRules`, `$veeOnInput`… de vee-validate) y `v` en el `:class` de VField (ya era `undefined` en Vue 2). Sin efecto funcional.'],
  PROVIDE_OUTSIDE_SETUP: ['migrar', 'Plugin/librería llama a `provide()` fuera de `setup()` (vue-sweetalert2 5.x publica también con `Vue.provide`). Sin efecto: `$swal` se traspasa a `Vue.prototype`.'],
  PLUGIN_VUE2_ONLY: ['migrar', '`vue-clickaway` 2.2.2 avisa que solo soporta Vue 2 (funciona bajo compat); sustituir por `v-click-outside` propio o VueUse al migrar.'],
  UNHANDLED_ERROR_IN_HOOK: ['bloquea', 'Error no controlado en un hook: revisar (no debe haber ninguno).'],
  'resolveComponent can only be used in render() or setup().': ['comportamiento', 'Alguna librería resuelve componentes fuera de render; verificar en la fase de migración de la librería.'],
};

const LIBRARY = /^(B[A-Z]|Vue|Router|Transition|KeepAlive|Validation|Vs|Lucide|Vue[A-Z]|Vuetify|Popover|Tooltip|VSelect|VueGoodTable|PerfectScrollbar|Apex|Multiselect)/;

function loadComponentIndex() {
  const index = new Map();
  const walk = (dir) => {
    for (const entry of fs.readdirSync(dir, { withFileTypes: true })) {
      const full = path.join(dir, entry.name);
      if (entry.isDirectory()) walk(full);
      else if (entry.name.endsWith('.vue')) {
        const base = entry.name.replace(/\.vue$/, '');
        const rel = path.relative(ROOT, full);
        const add = (n) => index.set(n, [...(index.get(n) || []), rel]);
        add(base);
        add(base.replace(/[-_]+(\w)/g, (_, c) => c.toUpperCase()).replace(/^\w/, (c) => c.toUpperCase()));
        const text = fs.readFileSync(full, 'utf8');
        const m = text.match(/\bname:\s*["']([A-Za-z0-9_-]+)["']/);
        if (m) add(m[1]);
      }
    }
  };
  walk(SRC);
  return index;
}

function classify(text) {
  const dep = text.match(/\(deprecation ([A-Z0-9_]+)\)/);
  if (dep) return { key: dep[1], kind: 'deprecation' };
  if (/^\[Vue warn\]: Property "([^"]+)" was accessed during render/.test(text)) return { key: 'RENDER_PROPERTY_UNDEFINED', kind: 'vue' };
  if (/^\[Vue warn\]: Failed making property/.test(text)) return { key: 'REACTIVE_READONLY_ROUTE', kind: 'vue' };
  if (/provide\(\) can only be used inside setup/.test(text)) return { key: 'PROVIDE_OUTSIDE_SETUP', kind: 'vue' };
  if (/has already been registered in target app/.test(text)) return { key: 'COMPONENT_ALREADY_REGISTERED', kind: 'vue' };
  if (/only supports Vue 2/.test(text)) return { key: 'PLUGIN_VUE2_ONLY', kind: 'library' };
  if (/\[Vue Router warn\]/.test(text)) return { key: `VUE_ROUTER_WARN: ${text.replace(/\[Vue Router warn\]:\s*/, '').replace(/"[^"]*"/g, '"…"').split('\n')[0].slice(0, 80)}`, kind: 'router' };
  if (/router-link.*scoped slot/.test(text)) return { key: 'ROUTER_LINK_SCOPED_SLOT', kind: 'router' };
  if (/\[vue-router\]/.test(text)) return { key: `VUE_ROUTER: ${text.replace(/\[vue-router\]\s*/, '').split(':')[0].slice(0, 70)}`, kind: 'router' };
  if (/Unhandled error during execution/.test(text)) return { key: 'UNHANDLED_ERROR_IN_HOOK', kind: 'vue' };
  if (/\[Vue warn\]/.test(text)) return { key: text.replace(/\[Vue warn\]:\s*/, '').replace(/\s+at <.*/s, '').replace(/ with value ".*$/, '').slice(0, 90), kind: 'vue' };
  return { key: text.split('\n')[0].slice(0, 90), kind: 'other' };
}

function frames(text) {
  return [...text.matchAll(/at <([A-Za-z0-9_]+)/g)].map((m) => m[1]);
}

function main() {
  if (!fs.existsSync(DIR)) {
    console.error(`No hay avisos capturados en ${path.relative(ROOT, DIR)}. Ejecuta antes: npm run test:e2e`);
    process.exit(1);
  }
  const index = loadComponentIndex();
  const groups = new Map();
  let total = 0;
  for (const file of fs.readdirSync(DIR)) {
    const { test, warnings } = JSON.parse(fs.readFileSync(path.join(DIR, file), 'utf8'));
    for (const w of warnings) {
      total += 1;
      const { key, kind } = classify(w.text);
      const g = groups.get(key) || { key, kind, count: 0, tests: new Set(), components: new Map(), sample: w.text.split('\n')[0].slice(0, 200) };
      g.count += 1;
      g.tests.add(test);
      const first = frames(w.text)[0];
      if (first) g.components.set(first, (g.components.get(first) || 0) + 1);
      groups.set(key, g);
    }
  }
  const rows = [...groups.values()].sort((a, b) => b.count - a.count);
  if (process.argv.includes('--json')) {
    console.log(JSON.stringify(rows.map((r) => ({ ...r, tests: r.tests.size, components: Object.fromEntries(r.components) })), null, 2));
    return;
  }
  console.log(`Avisos capturados: ${total} mensajes, ${rows.length} únicos, en ${fs.readdirSync(DIR).length} tests\n`);
  console.log('| Aviso | Ocurrencias | Archivos / componentes | Riesgo | Acción futura |');
  console.log('|---|---:|---|---|---|');
  for (const r of rows) {
    const known = KNOWN[r.key];
    const comps = [...r.components.entries()].sort((a, b) => b[1] - a[1]).slice(0, 4).map(([name]) => {
      const files = index.get(name);
      if (files && files.length && !LIBRARY.test(name)) return `${name} (${files[0].replace('resources/src/', '')})`;
      return `${name}${LIBRARY.test(name) ? ' (librería)' : ''}`;
    });
    const risk = known ? known[0] : r.kind === 'library' ? 'migrar' : r.kind === 'router' ? 'bloquea' : 'comportamiento';
    const action = known ? known[1] : '';
    console.log(`| ${r.key.replace(/\|/g, '/')} | ${r.count} | ${comps.join(', ') || '—'} | ${risk} | ${action || r.sample.replace(/\|/g, '/')} |`);
  }
}

main();
