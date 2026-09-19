# Limpieza de APIs de Vue 2 en el código propio

Base: `622a094` (Vue 3.5.43 + `@vue/compat` MODE 2 + Vue Router 4). Solo código PRODEX (`resources/src`); `node_modules` y las dependencias externas (BootstrapVue, vee-validate, vue-meta, vue-i18n, Vuex, vue-good-table, vue-select, lucide-vue…) no se tocan.

## 1. Auditoría (código propio → después)

| Patrón | Código PRODEX antes | Después | Adaptadores compat propios | Externo |
|---|---|---|---|---|
| `$listeners` | 5 componentes (`PxButton`, `PxInput`, `PxCheck`, `PxTextarea`, `VsPx`) | **0** | 0 | BootstrapVue, vue-select, vue-good-table… |
| `$scopedSlots` | 2 (`PxModal`, `vee-compat-provider`) | **0** | — | vue-router 3 ya no; BootstrapVue |
| `beforeDestroy` | 42 líneas / 41 archivos | **0** (→ `beforeUnmount`) | — | BootstrapVue (mixins globales), vee-validate, vue-meta |
| `destroyed` | 1 (`Customer_Loyalty_Points_Report`) | **0** (→ `unmounted`) | — | ídem |
| `Vue.util` | 0 | 0 | 0 | vue-meta, vue-clickaway |
| `render(h)` | 6 componentes en línea (`LucideIcon`, `ListToolbar`, `Pager`, 4 `StatTile`) | **0** | 0 (`new Vue`/`render: h => h(App)` de los 3 entrypoints es montaje) | BootstrapVue, vee-validate, vue-perfect-scrollbar, vue-good-table |
| `functional: true` | 5 (`LucideIcon`, 4 `StatTile`) | **0** | — | lucide-vue (los iconos), BootstrapVue |
| `$children` | 0 | 0 | 0 | vue-meta |
| `_uid` (API privada) | 4 (`PxModal`, `PxField`, `VField`, `SerialNumbersField`) | **0** | — | — |
| `hook:*` (eventos de ciclo de vida) | 1 (adaptador vee-validate) | **0** (→ `onMounted`) | — | vee-validate, vue-select, BootstrapVue |

Un test (`tests/frontend/own-code-vue3.test.mjs`) recorre `resources/src` y falla si reaparece cualquiera de ellos.

## 2. APIs eliminadas y cómo

- **`$listeners`.** Bajo compat, `INSTANCE_LISTENERS` (activo en MODE 2) mantiene los listeners fuera de `$attrs` y no los deja caer solos en el elemento raíz. Los cinco componentes declaran `compatConfig: { INSTANCE_LISTENERS: false }` y usan la semántica de Vue 3:
  - `PxButton`, `PxTextarea`: raíz = elemento nativo; los listeners caen solos (se borró `v-on="$listeners"`).
  - `PxInput`, `PxCheck`: `inheritAttrs: false`; los listeners se reenvían al `<input>` con `v-bind="listeners()"` (`utils/forwardListeners.js`: solo claves `onXxx` de `$attrs`). El evento propio (`input` / `change`) se declara en `emits`, de modo que el listener del padre no se duplica ni se reenvía dos veces. `PxCheck` mantiene los atributos que no son listeners en el `<label>` raíz (`forwardPlainAttrs`), como en Vue 2.
  - `VsPx`: `v-bind="$attrs"` ya incluye los listeners.
  - `v-bind` va antes que los atributos explícitos (semántica de Vue 3, sin el aviso `COMPILER_V_BIND_OBJECT_ORDER`).
- **`$scopedSlots`.** `PxModal`: `v-if="$slots.footer"` (el footer con slot props `#footer="{ close }"` sigue funcionando; en Vue 3 todos los slots están en `$slots`). El adaptador de vee-validate lee `instance.slots` reales.
- **Ciclo de vida.** `beforeDestroy` → `beforeUnmount` y `destroyed` → `unmounted` (mismo momento y orden). Se comprobó que los timers, `removeEventListener`, `clearInterval`, observers, escáner QR y cancelaciones de axios siguen en esos hooks.
- **`render(h)` / `functional`.**
  - `LucideIcon`: componente `setup()` + `h` de Vue 3, `compatConfig: { MODE: 3, COMPONENT_FUNCTIONAL: true }` (los iconos de `lucide-vue` son funcionales de Vue 2 y solo compat los renderiza). Reenvía class/style/atributos/listeners al icono.
  - `StatTile` (×4), `ListToolbar`, `Pager`: plantillas (`template`) con `compilerOptions.whitespace: 'condense'`; `StatTile` y `Pager` en MODE 3. Marcado y clases idénticos.
- **`_uid`.** Contadores propios para los ids de `PxModal`, `PxField`, `VField` y `SerialNumbersField`.
- **Adaptador de vee-validate** (`vee-compat-provider.js`): `$scopedSlots`/`$slots` → `instance.slots`; `$once('hook:mounted')` → `onMounted(fn, instancia)`.

## 3. Warnings de compat (mismos 71 tests con avisos, antes → después)

| Métrica | Antes (`622a094`) | Después |
|---|---|---|
| Mensajes totales | 19 312 | 18 732 |
| Avisos únicos (herramienta) | 34 | 34 (mismos tipos; en la app desaparece `CONFIG_WHITESPACE`) |
| `INSTANCE_LISTENERS` | 1 061 | 845 (**propios: 56 → 0**, componente propio como primera traza) |
| `INSTANCE_SCOPED_SLOTS` | 468 | 454 (propios: 2 fuentes → 0; el resto son BootstrapVue y vue-good-table) |
| `COMPONENT_FUNCTIONAL` | 1 177 | 607 (los 166 con `LucideIcon` en la traza son los iconos funcionales de `lucide-vue`) |
| `CONFIG_WHITESPACE` (app) | 11 | 0 (los 11 restantes son del banco `13-slots-equivalence`, que carga el build global de compat) |
| `OPTIONS_BEFORE_DESTROY` / `OPTIONS_DESTROYED` | 3 871 / 23 | 4 059 / 22 (hooks de mixins globales de librerías; ningún componente propio los define) |
| `GLOBAL_PRIVATE_UTIL` (`Vue.util`) | 135 | 136 (vue-meta, vue-clickaway) |
| `RENDER_FUNCTION` | 1 515 | 1 517 (BootstrapVue, vee-validate, vue-good-table, vue-perfect-scrollbar) |
| `INSTANCE_CHILDREN` | 1 081 | 1 088 (vue-meta) |
| Avisos de compilación del build dev | 42 | 42 |

(Los totales varían ±1 % entre corridas porque cambia el número de instancias montadas; con los 85 tests actuales el reporte tiene ~21 600 mensajes.)

**Separación propio / externo.** Una traza de compat no distingue el origen cuando el aviso viene de un mixin global (cada componente, propio o no, lo hereda). Por eso la medida fiable del código propio es doble: (1) el escaneo estático del punto 1, que es **0** para todos los patrones, y (2) los avisos cuya clave solo puede originarse en código propio: `INSTANCE_LISTENERS` con un componente propio como primera traza pasó de 56 a 0, y `COMPONENT_FUNCTIONAL` con un componente propio como primera traza (`PxButton`, `PxCheck`, `StatTile`…) de 43 a 0; los 166 con `LucideIcon` en la traza son los iconos funcionales de `lucide-vue`.

## 4. Warnings propios restantes (no migrables en esta fase)

| Aviso | Componentes propios | Motivo |
|---|---|---|
| `COMPONENT_V_MODEL` | `PxModal` (`model: { prop: 'value', event: 'close' }`), `PxInput`, `PxTextarea`, `PxCheck`, `VsPx`, `SerialNumbersField`, `ListToolbar` | Migrarlo cambia la API de `v-model` (`modelValue` / `update:modelValue`) en cientos de vistas: se hace junto con la migración de formularios |
| `ATTR_FALSE_VALUE` | `PxCheck` (`:checked`) | `checked` es una propiedad DOM en Vue 3: sin efecto real |
| `Property "v" was accessed during render` | `VField` (`:class` usa `v` fuera del slot; ya era `undefined` en Vue 2) | Sin efecto funcional |
| `OPTIONS_BEFORE_DESTROY`, `PRIVATE_APIS` con un componente propio en la traza | todos | Mixins globales de BootstrapVue/vue-meta/vuex aplicados a cada componente |

## 5. Warnings externos restantes

`OPTIONS_BEFORE_DESTROY`, `PRIVATE_APIS`, `INSTANCE_EVENT_HOOKS`, `RENDER_FUNCTION`, `COMPONENT_FUNCTIONAL`, `INSTANCE_CHILDREN`, `INSTANCE_LISTENERS`, `INSTANCE_SCOPED_SLOTS`, `INSTANCE_EVENT_EMITTER`, `GLOBAL_PRIVATE_UTIL`, `GLOBAL_EXTEND`, `GLOBAL_PROTOTYPE`, `GLOBAL_SET`, `GLOBAL_MOUNT`, `WATCH_ARRAY`, `COMPONENT_ASYNC`, `PLUGIN_VUE2_ONLY`, `OPTIONS_DATA_FN/MERGE`, `CUSTOM_DIR`: los emiten BootstrapVue, vee-validate, vue-meta, vue-i18n 8, Vuex 3, vue-good-table, vue-select, vue-clickaway, vue-perfect-scrollbar y lucide-vue. El reporte completo se regenera con `npm run test:e2e && npm run test:e2e:compat-warnings`.

## 6. Flags de compat todavía necesarias

`configureCompat({ MODE: 2, INSTANCE_CHILDREN: true, CUSTOM_DIR: true })`:

| Flag | Motivo real | Se puede quitar cuando… |
|---|---|---|
| `INSTANCE_CHILDREN` | vue-meta 2 recorre `vm.$children` de todos los componentes, incluidos `RouterView`/`RouterLink` de Router 4 y los componentes propios en MODE 3 (`LucideIcon`, `StatTile`, `Pager`) | se migre vue-meta (→ `@unhead/vue`) |
| `CUSTOM_DIR` | 23 plantillas usan `<router-link v-b-tooltip>` y los hooks de Vue 2 de las directivas de BootstrapVue (`bind/inserted/componentUpdated`) se ejecutan en el contexto de un componente MODE 3 | se migre BootstrapVue (o se saque la directiva de `router-link`) |

Ninguna se puede eliminar hoy: cada una tiene una dependencia real demostrada (ver `VUE_ROUTER_4_MIGRATION.md`). No se silencia ningún aviso.

## 7. Sidebar legacy tras Router 4

Pruebas E2E nuevas (`18-own-code-vue3`, `?pxshell=0` = rollback a legacy):
- **Vertical (por defecto)**: enlace exacto (`router-link-exact-active` + `open`), padre `li.has-submenu` con `active` + `open`, navegación a otro módulo (el enlace anterior se apaga) y regreso con el botón atrás; ruta hija de un módulo mantiene el padre activo. Sin cambios respecto a Router 3: aquí `a.open` no tiene estilo.
- **Horizontal (`Sidebar.vue`)**: **regresión demostrada.** Router 3 marcaba con `linkActiveClass: 'open'` todo enlace cuya ruta era prefijo de la actual; Router 4 solo si es un registro padre. En `/app/marketing/campaigns/create` el enlace `/app/marketing/campaigns` dejaba de recibir `open` (sonda en navegador) y en ese layout `a.open` sí tiene estilo (`config.js`: `.sidebar-left-secondary .childNav a.open`). Se restituyó la regla de Router 3 con `routeIncludes(path)` en los 188 enlaces de ruta fija de `Sidebar.vue`. E2E: prefijo (campañas, empleados, proyectos, usuarios), exacto + `open`, y regreso.

## 8. Próximo bloqueo recomendado

Migrar **vue-meta 2 → `@unhead/vue`**: es la única dependencia que exige `INSTANCE_CHILDREN` y `Vue.util`, se usa por `metaInfo` en las vistas y elimina un flag global. Después: BootstrapVue (`CUSTOM_DIR`, `render(h)`, `$listeners`, `beforeDestroy`), vee-validate 3 → 4 con `v-model` explícito (`COMPONENT_V_MODEL`) y, al final, vue-i18n 9 y Vuex 4.
