# Auditoría final de dependencias — camino a Vue 3 puro

Rama `refactor/vue3-legacy-dependency-audit`, desde `eb29598e` (fin de la migración vue-i18n 8→11). Objetivo: fijar Node 22, medir exactamente qué sigue bloqueando quitar `@vue/compat`, y proponer el orden final de fases. Este documento NO migra nada — es la fotografía previa a la migración final.

## 1. Node 20 → 22

`vue-i18n@11.4.12` exige Node ≥22 (`engines.node` del propio paquete). El entorno local ya corría Node 22.23.2; lo que faltaba era **CI** (`node-version: '20'` en los dos jobs de `.github/workflows/frontend-safety-net.yml`) y **`engines.node`** en `package.json` del propio proyecto (no existía).

- `package.json`: añadido `"engines": { "node": ">=22" }`.
- CI: `node-version: '20'` → `'22'` en `route-snapshot` y `e2e`.
- Bajo Node 22, localmente: `npm ci` limpio, `test:frontend` 139/0, `npm run development`/`npm run production` compilan limpio (mismos 42 warnings preexistentes), `test:e2e:routes` 474/20 sin cambio, PHP `Unit` 1328 OK, `Feature` 934+3 skipped, E2E completo y `E2E_RESET=1` — ver sección de validación.
- **VPS**: sigue en la versión que tuviera antes (no tocado, según instrucción). El próximo deploy/build del VPS **debe** usar Node ≥22 antes de desplegar esta rama o cualquier rama posterior — `npm ci` fallará con la versión de Node del paquete `vue-i18n` si el VPS sigue en Node 20.

## 2. Auditoría real de dependencias relacionadas con Vue

Metodología: versión instalada, `peerDependencies` real de cada paquete (leído de su `package.json` en `node_modules`, no supuesto), imports reales en `resources/src` (`grep` de `from '<paquete>'`/`require(...)`), si ya existe un wrapper/adapter propio (`platform/compat/`, `platform/adapters/`), y si tiene sucesor nativo de Vue 3 conocido.

| Paquete | Versión | peer `vue` | Vue objetivo | Archivos que lo usan | Wrapper propio | Bloquea quitar `@vue/compat` | Reemplazo recomendado |
|---|---|---|---|---|---|---|---|
| `vue` | 3.5.43 (alias `@vue/compat`) | — | 3 (modo compat) | todo el árbol | — | — (es el propio compat) | quitar el alias `@vue/compat`→`vue` cuando el resto esté migrado |
| `vue-select` | 3.20.4 | **2.x** | 2 | 1 (componente global) | `platform/compat/vue-select.js` | **A** | vue-select 4.x (nativo Vue 3) |
| `vue-good-table` | 2.21.11 | ninguno declarado, Options API compilada para Vue 2 | 2 | 1 registro + N plantillas | ninguno | **A** | sin sucesor 1:1; reescribir con `<b-table>` (BVN) o una librería de grid Vue 3 |
| `vue2-daterange-picker` | 0.6.8 | ninguno declarado, nombre explícito | 2 | 33 | `platform/compat/daterange-picker.js` + alias en `webpack.mix.js` | **A** | sin fork Vue 3 conocido; reemplazar por el datepicker de BVN (rango) o reescribir |
| `vuejs-datepicker` | 1.6.2 | **^2.6.10** | 2 | 4 | ninguno | **A** | BVN ya trae `<b-form-datepicker>` (ya instalado, ya usado en otras pantallas) — candidato directo |
| `vuedraggable` | 2.24.3 | ninguno declarado, versión 2.x = Vue 2 | 2 | 5 | ninguno | **A** | `vuedraggable@4.x` (mismo nombre de paquete, reescrito nativo para Vue 3) |
| `vue-apexcharts` | 1.7.0 | **^2.5.17** | 2 | 24 | ninguno | **A** | `vue3-apexcharts` (fork nativo); 24 archivos, el de mayor esfuerzo de swap directo |
| `lucide-vue` | 0.517.0 | **^2.6.12** | 2 | 1 (fuente de `LucideIcon.vue`, el wrapper propio real) | `components/LucideIcon.vue` ya envuelve la librería | **A** | `lucide-vue-next` (mismo autor, nativo Vue 3); como ya hay wrapper propio, el swap es de bajo costo |
| `vuex` | 3.6.2 | **^2.0.0** | 2 | 189 (`mapGetters`/`mapActions`/`$store.*`) | — | **A** | Vuex 4 (ver §4, drop-in) |
| `@johmun/vue-tags-input` | 2.1.0 | **2.x** | 2 | 2 | ninguno | **A** | uso bajo; reemplazar por un input de tags propio o una librería agnóstica |
| `@pencilpix/vue2-clock-picker` | 0.1.6 | ninguno declarado, nombre explícito | 2 | 2 | ninguno | **A** | uso bajo; reemplazar por un time-picker nativo o de BVN |
| `vue-perfect-scrollbar` | 0.2.1 | ninguno declarado, componente Options API | 2 | 6 (sidebar/nav) | registrado como componente async global | **A**, pero de bajo costo | envolver directo la librería `perfect-scrollbar` (JS puro, agnóstica) en un componente Vue 3 propio — reemplaza el wrapper, no la dependencia base |
| `vue-cookie` | 1.1.4 | ninguno | 2 (plugin `Vue.use`) | 1 | — | **A**, trivial | eliminar; `document.cookie` directo o `js-cookie` |
| `vue-cookies` | 1.8.6 | ninguno | 2 (plugin `Vue.use`) | 3 | — | **A**, trivial | eliminar; `document.cookie` directo o `js-cookie` |
| `vue-localstorage` | 0.6.2 | ninguno | 2 (plugin `Vue.use`) | 1 | — | **A**, trivial | eliminar; `localStorage` directo |
| `vue-barcode` | 1.3.0 | ninguno | 2 (componente) | 6 | ninguno | **A** | evaluar reemplazo agnóstico (p. ej. `jsbarcode` directo) |
| `vue-easy-print` | 0.0.8 | ninguno | 2 (mixin/directiva) | 2 | ninguno | **A**, bajo uso | reemplazar por `window.print()`/lógica propia |
| `vue-html-to-paper` | 1.4.5 | ninguno | 2 (plugin) | 1 | ninguno | **A**, bajo uso | reemplazar por lógica propia de impresión |
| `vue-sweetalert2` | 5.0.11 | `*` (agnóstico declarado) | 3 (con avisos) | 1 (instalado como plugin) | — | **B** | ya funciona en Vue 3; genera `PROVIDE_OUTSIDE_SETUP` (ver avisos) — no bloquea, sin acción urgente |
| `vue-template-compiler` | 2.7.16 | ninguno | 2 | **0** (no referenciado en código ni en `webpack.mix.js`/config de `vue-loader`) | — | **D — dead** | eliminar del `package.json`; `vue-loader`+`@vue/compiler-sfc` ya compilan las SFC, este paquete no está en la cadena de build |
| `@trevoreyre/autocomplete-vue` | 2.4.1 | ninguno | 3 (agnóstico) | 1, **solo el CSS** (`main.js` importa únicamente `dist/style.css`; el componente `<autocomplete>` no se usa en ninguna plantilla) | — | **D — dead** | eliminar el import de CSS y la dependencia |
| `vue-i18n` | 11.4.12 | ^3.0.0 | 3 nativo | ✓ migrado (fase previa) | `plugins/i18n.loader.js` | **C** | — |
| `vee-validate` + `@vee-validate/rules` | 4.15.1 | ^3.4.26 | 3 nativo | ✓ migrado (fase previa) | `platform/validation/vee-adapter.js` | **C** | — |
| `vue-router` | 4.6.4 | ^3.5.0 | 3 nativo | ✓ migrado (fase previa) | `platform/compat/vue-router.js` (por el bootstrap `new Vue()`, no por vue-router) | **C** | — |
| `bootstrap-vue-next`, `reka-ui`, `@floating-ui/vue`, `@vueuse/core`, `@vueuse/integrations`, `@unhead/vue`, `@internationalized/date` | varias | 3.x / ninguno | 3 nativo | uso extenso ya migrado | `platform/adapters/bvn.js` | **C** | — |

No relacionados con Vue (Bootstrap, Tailwind, axios, moment, quill, echarts, jspdf, dompurify, xlsx, alpinejs, sass, webpack/laravel-mix, ESLint, Playwright…): **C**, no auditados individualmente porque no tienen `peerDependencies` de Vue ni bloquean nada.

## 3. Código propio bloqueante

| Patrón | Archivos | Detalle |
|---|---|---|
| `new Vue(` | 5 | `main.js`, `login.js`, `customer-display.js`, `store/index.js` (indirecto vía `Vuex.Store`, no cuenta), `platform/compat/vue-router.js` (`mountWithRouter`, el puente deliberado hacia la app real de Vue 3 — desaparece con `createApp()` directo) |
| `Vue.extend(` | 0 | ninguno |
| `Vue.use(` | 9 archivos (`main.js`, `auth/authenticate.js`, `auth/IsConnected.js`, `plugins/sweetalert2.js`, `plugins/stocky.kit.js`, `platform/vue-compat.js`, `store/index.js`, `store/modules/language.js`, `store/modules/auth.js`) | instala StockyKit, FriendlyNavigation, VueCookies, VueCookie, Vuex, VueGoodTablePlugin, sweetalert2, y guardas de auth propias — todos ligados a que `Vue` siga siendo el objeto de compat; desaparecen al pasar a `app.use()` sobre la app real |
| `Vue.prototype` | 3 | `$uploadPath`/`$imgUrl` (globals propios) y el plugin de cookies — pasan a `app.config.globalProperties` |
| `$children` | 2 | uso legacy, sin equivalente en Vue 3 puro; hay que revisar caso por caso al quitar compat |
| `$listeners` | 4 | ya no existe en Vue 3 puro (se fusiona en `$attrs`); low-risk, ya identificado en avisos `INSTANCE_LISTENERS` |
| `scopedSlots` | 0 | ninguno |
| `render(h)` / `render(createElement)` | 0 (código propio) | limpio; el `RENDER_FUNCTION` de los avisos viene de librerías (BootstrapVue-legado de terceros, vue-good-table, VuePerfectScrollbar), no de código propio |
| `beforeDestroy`/`destroyed` (opciones Vue 2) | 0 (código propio) | limpio; el `OPTIONS_BEFORE_DESTROY` de los avisos viene de librerías |
| `compatConfig` | 15 archivos | flags de compat YA instalados deliberadamente en fases previas (`platform/compat/bvn-mode.js` y otros) — es la infraestructura de migración en sí, no deuda nueva |

Ningún blocker de código propio es grande: el `new Vue()`/`Vue.use()` central (5+9 archivos) es la ÚNICA pieza real que hay que reescribir para pasar a `createApp()`, y ya está concentrada en un puñado de entrypoints y `mountWithRouter`.

## 4. Vuex — ¿Vuex 4 o Pinia?

`store/index.js`: 6 módulos (`language`, `auth`, `shellScope`, `largeSidebar`, `compactSidebar`, `config`), sin plugins, sin `strict`, sin registro dinámico de módulos, sin `subscribe`. Patrón de consumo: `mapGetters` (180 archivos), `mapActions` (18), `$store.dispatch/commit/getters/state` directo (31). Cada módulo define `mutations` (namespaced por módulo, patrón estándar).

**Recomendación: Vuex 4, no Pinia.** Vuex 4 es Vue-3-nativo, mantiene EXACTAMENTE la misma API (`mapState/mapGetters/mapActions/mapMutations`, forma de los módulos, `commit`/`dispatch`) — el único cambio real es `new Vuex.Store({...})` + `Vue.use(Vuex)` → `createStore({...})` + `app.use(store)` (idéntico patrón ya resuelto en la migración de vue-i18n: mover el plugin al array de `mountWithRouter`). Migrar a Pinia sería reescribir 189 archivos de consumo (los `mapGetters`/`mapActions` no tienen equivalente directo, Pinia usa stores como composables) para ningún beneficio funcional en este proyecto — la complejidad del store es baja, no hay nada que Pinia resuelva mejor aquí. Vuex 4 es la opción de menor riesgo y esfuerzo.

## 5. Avisos @vue/compat por origen (medición actual)

Con la corrida completa de este árbol (Node 22, dev build): mismo perfil ya documentado en la fase vue-i18n (30.680→17.111 totales tras esa fase). Los orígenes dominantes que siguen activos:

- `PRIVATE_APIS`/`RENDER_FUNCTION` — BootstrapVue-legado (terceros embebidos), `vue-good-table`, `VuePerfectScrollbar` — categoría A.
- `OPTIONS_BEFORE_DESTROY` (533 tras quitar vue-i18n 8) — `VuePerfectScrollbar`, `VgtTableHeader` (vue-good-table), `Draggable` (`vuedraggable` 2.x) — las tres categoría A.
- `WATCH_ARRAY` — `VueGoodTable` — categoría A.
- `PROVIDE_OUTSIDE_SETUP` — `vue-sweetalert2` — categoría B, no bloquea.
- `GLOBAL_MOUNT`/`GLOBAL_PROTOTYPE`/`GLOBAL_SET` — código propio (`new Vue()`, `Vue.prototype`, `Vue.set`) — se resuelven al pasar a `createApp()`.
- `COMPONENT_FUNCTIONAL` — `LucideIcon` (librería origen `lucide-vue`, no el wrapper propio) — categoría A, resuelto con `lucide-vue-next`.

No hay ningún origen de aviso que no esté ya cubierto por la tabla de la sección 2.

## 6. Validación (Node 22)

- `npm ci`: limpio (con `.npmrc` `legacy-peer-deps=true` ya existente).
- `npm run test:frontend`: 139/0.
- PHP `Unit`: 1328 OK (solo deprecaciones). `Feature`: 934 OK + 3 skipped.
- `npm run test:e2e:routes`: 474 rutas tenant / 20 portal, sin cambio.
- `npm run development` / `npm run production`: compilan limpio, mismos 42 warnings preexistentes de siempre.
- E2E completo y `E2E_RESET=1`: ver el cierre de esta entrega (corrida en curso al escribir esta sección; resultado final en el mensaje de cierre).
- CI (Node 22): ver cierre — 2 corridas verdes consecutivas antes de declarar esta fase cerrada.

## 7. Fases finales recomendadas (mínimo necesario)

No se inventan fases adicionales — se confirma el orden propuesto:

1. **Dependencias de UI legacy** (categoría A de la tabla): `vue-select`→4.x, `vuedraggable`→4.x, `lucide-vue`→`lucide-vue-next`, `vue-apexcharts`→`vue3-apexcharts`, `vuejs-datepicker`→`<b-form-datepicker>` de BVN, `vue2-daterange-picker`→reemplazo (mayor esfuerzo, sin sucesor directo), `vue-good-table`→reescritura (mayor esfuerzo, sin sucesor directo), `vue-perfect-scrollbar`→wrapper propio sobre `perfect-scrollbar`, `@johmun/vue-tags-input`/`@pencilpix/vue2-clock-picker`/`vue-cookie`/`vue-cookies`/`vue-localstorage`/`vue-barcode`/`vue-easy-print`/`vue-html-to-paper`→reemplazos triviales o eliminación. Además: eliminar `vue-template-compiler` y `@trevoreyre/autocomplete-vue` (dead, categoría D) en esta misma fase, sin esfuerzo.
2. **Vuex 4 + entrypoints**: `Vuex.Store`→`createStore`, y consolidar el bootstrap `new Vue()`/`Vue.use()` de los 5 entrypoints hacia `createApp()` real (ya con el patrón resuelto en la migración de vue-i18n).
3. **Quitar `@vue/compat`**: cambiar el alias `vue:'@vue/compat'`→`vue` en `webpack.mix.js`, quitar `compilerOptions.compatConfig.MODE:2`, retirar `@vue/compat` de `package.json`, limpiar los 15 archivos con `compatConfig` restante.

Ese es el número final: **3 fases**, en ese orden — cada una depende de que la anterior esté cerrada (no se puede quitar compat con vue-good-table/vue2-daterange-picker todavía en Vue 2 real; no tiene sentido tocar Vuex antes de limpiar las dependencias de UI que también usan `Vue.use()`).
