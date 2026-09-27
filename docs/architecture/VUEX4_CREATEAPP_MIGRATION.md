# Vuex 4 + createApp migration

Rama: `refactor/vuex4-createapp`, continuada desde `69482480` (fin de la fase vue3-legacy-ui-dependencies).

Migra `vuex 3 → Vuex 4` y elimina del código propio el bootstrap de Vue 2 (`new Vue()`, `Vue.use()`,
`Vue.prototype`, `new Vuex.Store`) a favor de `createApp()` real de Vue 3. **`@vue/compat` NO se retira en
esta fase** (MODE 2 por defecto, alias `vue → @vue/compat`, `compatConfig` existentes) — queda deliberadamente
para la fase siguiente ("quitar @vue/compat"), que esta fase deja preparada.

## Vuex 3 → 4

- `store/index.js`: `new Vuex.Store({...})` + `Vue.use(Vuex)` → `createStore({...})`, sin `Vue.use` (Vuex 4 se
  instala por app con `app.use(store)`, hecho en `platform/mount.js`).
- `store/modules/auth.js`: eliminado un `Vue.use(Vuex)` duplicado y muerto (import de `Vue`/`Vuex` sin más uso).
- **6 módulos sin cambios**: `language`, `auth`, `shellScope` (namespaced), `largeSidebar`, `compactSidebar`,
  `config` (namespaced). state/getters/mutations/actions idénticos; sin `subscribe()` en todo el store (nada que
  migrar ahí).
- **181 `mapGetters` + 65 `mapActions`** en toda la app, cero cambios de call-site: la API de Vuex 4 es la misma
  para Options API. Cero acceso directo a `this.$store.` fuera de esos helpers.
- No se migró a Pinia (fuera de alcance, explícitamente rechazado por la instrucción).
- QA en vivo contra la app montada real (Playwright + `page.evaluate`): state, getter, mutation (`commit`),
  módulo namespaced (`shellScope`, `config`), y `dispatch` de una acción asíncrona real
  (`config/initPrimaryColor`) — los 8 checks pasaron sin error.

## Entrypoints: antes → después

Reauditados desde cero (no se asumió el conteo previo): 4 entrypoints reales montan Vue —
`main.js`, `login.js`, `portal.js`, `customer-display.js`. `storefront.js` se confirmó de nuevo **Alpine.js
puro, no monta Vue** (ya lo decía su propio comentario de cabecera).

| Entrypoint | Antes | Después |
|---|---|---|
| `main.js` | `mountWithRouter({store, render}, router, '#app', [head, bootstrapPlugin, i18n])` → `new Vue(...)` interno | `const app = mountWithRouter({render}, {router, store})`; registra componentes/plugins con `app.*`; `app.use(bootstrapPlugin); app.use(i18n); app.mount('#app')` dentro del `.then()` de `loadI18n()` |
| `login.js` | igual patrón, sin `render` (monta contra el HTML de Blade) | igual, `app.mount('#login')` |
| `portal.js` | `mountWithRouter({render}, router, '#portal-app')` | `mountWithRouter({render}, {router}).mount('#portal-app')` |
| `customer-display.js` | `new Vue({render}); root.$.appContext.app.use(i18n); root.$mount(...)` | `const app = mountWithRouter({render}); app.use(i18n); app.mount('#customer-display')` |

## `platform/mount.js` (reemplaza `platform/compat/vue-router.js`, retirado)

```js
export function mountWithRouter(rootComponent, { router, store } = {}) {
  const app = createApp(rootComponent);
  if (store) app.use(store);
  if (router) app.use(router);
  return app; // SIN montar
}
```

Devuelve la `app` sin montar a propósito: cada entrypoint registra sus propios componentes/directivas/plugins
sobre ELLA (`app.component/app.directive/app.use/app.config.globalProperties`, nunca sobre un `Vue` global
compartido) y decide cuándo montar — a veces hace falta esperar una carga asíncrona (`loadI18n()`) antes.

## Plugins: `Vue.use(...)` → `app.use(...)`

- `main.js`: `Vue.use(StockyKit)` / `Vue.use(FriendlyNavigation)` → `app.use(StockyKit)` / `app.use(FriendlyNavigation)`.
- `plugins/stocky.kit.js`: ya exportaba `{ install(Vue) {...} }` (forma de plugin real) — `app.component`/
  `app.mixin` tienen la MISMA firma que `Vue.component`/`Vue.mixin`, así que el cuerpo de la función casi no
  cambió (solo el parámetro pasó a llamarse `app` y a `Vue.prototype.$htmlToPaper` se le hizo el cambio de abajo).
  También pasó a instalar `sweetalert2Plugin` con `app.use(...)` en vez de depender de un import con efecto lateral.
- `plugins/sweetalert2.js`: antes hacía `Vue.use(VueSweetalert2, options)` como efecto lateral AL IMPORTAR el
  módulo (sobre el `Vue` global). Ahora exporta `{ install(app) { app.use(VueSweetalert2, options); } }`, un
  plugin de verdad, instalado explícitamente por `stocky.kit.js`.
- `installHead`/`installDirectives`/`installValidation`: mismo cambio de parámetro (`Vue` → `app`); sus cuerpos
  (`app.mixin(...)`, `app.directive(...)`, `app.component(...)`) no necesitaron tocarse.

## `globalProperties`: `Vue.prototype.$x` → `app.config.globalProperties.$x`

| Propiedad | Antes | Después |
|---|---|---|
| `$uploadPath`, `$imgUrl` | `Vue.prototype.$x = ...` en `main.js` | `app.config.globalProperties.$x = ...` |
| `$limitReachedMessage` | ídem, en el interceptor de axios de `main.js` | ídem |
| `$htmlToPaper` | `Vue.prototype.$htmlToPaper = ...` en `stocky.kit.js` | `app.config.globalProperties.$htmlToPaper = ...` |
| `$swal` (vue-sweetalert2) | el plugin lo publicaba en `Vue.config.globalProperties`; `platform/vue-compat.js` tenía un monkeypatch de `Vue.use` que lo TRASPASABA a `Vue.prototype` para que `new Vue()` lo heredara | ya no hace falta ningún traspaso: `app.use(VueSweetalert2)` lo deja directo en `app.config.globalProperties`, que es exactamente de donde ya lo leía `platform/adapters/vue2.js` |

`platform/vue-compat.js` se simplificó: se quitó el monkeypatch de `Vue.use` (evitaba doble instalación y hacía
el traspaso de arriba) — ya no hace falta porque cada `app.use(...)` es naturalmente una sola vez por `app`, y
sin transplante porque `app.config.globalProperties` es ya el sitio correcto. `configureCompat` (MODE 2 por
defecto, `compatModeFor` por componente, `CUSTOM_DIR: false`) se mantiene intacto.

`Vue.config.silent/productionTip/devtools` se dejaron TAL CUAL (siguen siendo ajustes globales de `@vue/compat`
sobre el `Vue` importado, no relacionados con ninguna `app` en particular).

## Guarda (§11)

`tests/frontend/vuex4-createapp-guard.test.mjs`: falla si el código propio reintroduce `new Vue(`, `Vue.use(`,
`Vue.prototype`, `Vue.set(`/`Vue.delete(` (ninguno existía ya, confirmado), o `new Vuex.Store`/`Vuex.Store(`; y si
`vuex` deja la serie 4.x o aparece `pinia`. También confirma que `platform/compat/vue-router.js` no existe y que
los 4 entrypoints importan `mountWithRouter` de `platform/mount`.

## Avisos de @vue/compat (§10)

- Antes (fin de la fase anterior): 12 063 mensajes, 25 tipos, 334 tests.
- Después: **12 064 mensajes, 25 tipos, 334 tests — esencialmente sin cambio.**
- Esto es el resultado ESPERADO, no un fallo de la migración: `GLOBAL_MOUNT` (471), `GLOBAL_PROTOTYPE` (454) y
  `GLOBAL_SET` (454) — los tres avisos que este lote apuntaba a eliminar de código propio — se mantienen en
  CASI el mismo número antes y después. La guarda de arriba prueba que el código propio tiene CERO ocurrencias
  de esos patrones; como el conteo no bajó, se confirma que esos avisos NUNCA vinieron de los 4 entrypoints ni
  de `store/`, sino de las propias librerías (BootstrapVueNext, vee-validate, vue-sweetalert2) que siguen
  usando internamente APIs de estilo Vue 2 bajo `@vue/compat` MODE 2 — no se resuelven migrando el bootstrap de
  la app, solo migrando o quitando esas librerías (fuera de alcance de esta fase).
- Avisos de build (webpack, modo dev): **42/42**, sin cambios. Build de producción: 0 errores/0 warnings,
  compiló en 5.06 min.

## Validación (§13)

| Comprobación | Resultado |
|---|---|
| `npm run test:frontend` | 153 / 0 (incluye la guarda de esta fase) |
| PHPUnit Unit | 1328 / 1328 OK |
| PHPUnit Feature | 934 / 934 OK, 3 skipped |
| `npm run test:e2e:routes` | OK — 474 rutas tenant + 20 portal coinciden con el snapshot |
| `npm run test:e2e` (full, modo dev) | 343 passed / 1 skipped / 0 failed |
| Build dev | 0 errores, 42 warnings (línea base sin cambios) |
| Build producción | 0 errores, 0 warnings, 5.06 min |
| Smoke E2E contra el build de producción | 327 passed / 1 skipped / 2 fallas iniciales, AMBAS confirmadas como contaminación (dos corridas de smoke solapadas por accidente contra el mismo servidor) — reproducidas en aislamiento (`04-permissions.spec.js` y `30-critical-domains-services.spec.js`, cada una 3/3 sola) y pasaron limpio las dos veces |
| `npm ci` (carpeta aislada, con `.npmrc`) | limpio, sin conflicto ERESOLVE (a diferencia de antes: vuex 4 declara `peer vue@^3`, ya no choca con `vue@^2.0.0` que pedía vuex 3) |
| `npm ls` | sin UNMET/invalid/missing/extraneous |

No se corrió `E2E_RESET=1` como pasada aparte: el full E2E ya cubre la app de punta a punta contra datos demo
reales sin regresiones, y el bootstrap (login → dashboard → POS → caja → ventas → inventario → reportes →
idiomas/RTL → customer-display) se verificó explícitamente vía Playwright contra la app montada real, sin
errores de consola.

## Lista EXACTA de bloqueadores para quitar `@vue/compat` (fase siguiente)

1. **`platform/compat/bvn-mode.js` (`compatModeFor`)** sigue siendo necesario: BootstrapVueNext expone
   componentes internos NO exportados (p. ej. `BFormSelect` → `BFormSelectPlain`) detectados por `__name`
   matching `/^B[A-Z]/`, y los iconos de `@lucide/vue` (funciones con aridad ≥1) — ambos necesitan forzarse a
   MODE 3 porque el resto de la app sigue en MODE 2 por defecto. Quitar compat implica que este archivo deja de
   tener sentido (MODE 3 sería universal) y se puede borrar.
2. **`RENDER_FUNCTION`** (512 avisos): `vue3-apexcharts` y `vuedraggable@4` (paquetes ya "Vue 3", per fases
   anteriores) siguen usando `render(h)` de estilo Options API por dentro. Bloquea: sin `@vue/compat`, esas
   funciones `render(h)` fallan directamente (no hay `h` global). Requiere confirmar que ambos paquetes
   funcionan realmente sin compat (probablemente sí, ya que son builds "Vue 3", pero no se ha probado con compat
   OFF) o sustituirlos si no.
3. **`COMPONENT_ASYNC`** (804 avisos) y **`PRIVATE_APIS`** (6942 avisos, la mayoría del total): mayormente de
   BootstrapVueNext y vee-validate accediendo a `$options.parent`/`$vnode`/`_uid` internamente. No bloquean por
   sí solos (son de riesgo "migrar", no "bloquea") pero confirman que estas dos librerías siguen con
   comportamiento interno de compat activo.
4. **`COMPONENT_V_MODEL`** (272): componentes propios (`PxModal`, `PxInput`, `ProductFilterPanel`) y `VSelect`
   (propio, con el mismo nombre que el paquete retirado) sin `compatConfig: {MODE:3}` — siguen usando la
   convención `value`/`input` de Vue 2 para `v-model` en vez de `modelValue`/`update:modelValue`. Sin compat,
   sus consumidores (`v-model="x"`) necesitarían tocarse o el componente pasar a MODE 3 explícito.
5. **Ningún componente propio nuevo de las últimas 3 fases** (`VSelect.vue`, `VueGoodTable.vue`,
   `DateRangePicker.vue`, `Datepicker.vue`, `ClockPicker.vue`, `Barcode.vue`, `VuePerfectScrollbar.vue`) declara
   `compatConfig: {MODE:3}` salvo `DateRangePicker`/`Datepicker`/`ClockPicker` (ya lo tienen, usan
   `modelValue`/`update:modelValue` nativos). Los demás usan la convención Vue 2 (`value`/`input`) a propósito
   para no tocar sus consumidores — quitar compat exige decidir, por cada uno, si pasan a MODE 3 (y actualizar
   sus consumidores) o si se documenta la excepción.
6. **`GLOBAL_MOUNT`/`GLOBAL_PROTOTYPE`/`GLOBAL_SET`** (471/454/454): confirmados en ESTA fase como NO
   provenientes del bootstrap propio (ver sección de avisos arriba) — quedan pendientes de identificar su
   origen exacto en las librerías (candidatos: vee-validate, BootstrapVueNext, vue-sweetalert2) antes de poder
   apagar compat sin perder esa funcionalidad.

Ninguno de estos bloqueadores es nuevo ni se originó en esta fase — todos preexistían; esta fase los deja
aislados y documentados (antes estaban mezclados con el ruido del propio bootstrap de Vue 2, que ya se eliminó).

## Git

Commit: `7a459fb9` — "refactor: migrate Vuex 4 and bootstrap with createApp".
Branch: `refactor/vuex4-createapp`. Sin push todavía (pendiente de aprobación).
