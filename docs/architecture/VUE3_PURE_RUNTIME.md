# Vue 3 pure runtime — retiro de `@vue/compat`

Rama: `refactor/vue3-pure-runtime`, continuada desde `a603523b` (fin de la fase `vuex4-createapp`). Última
fase de la modernización de frontend: PRODEX corre sobre `vue@3.5.x` nativo, sin capa de compatibilidad.

## Objetivo final (cumplido)

- `@vue/compat` = 0 (package.json, lockfile, `node_modules`).
- `configureCompat` = 0, `compatConfig` = 0, `MODE: 2|3` = 0, `compatModeFor` = 0 en código propio.
- Alias de webpack `vue → @vue/compat` = 0 (`webpack.mix.js` resuelve `vue` normal).
- `platform/vue-compat.js` y `platform/compat/bvn-mode.js` eliminados.
- Sin migración a Vite ni TypeScript — Laravel Mix/webpack se mantiene, solo se quitó el alias.

## Qué rompe @vue/compat → Vue 3 real (y cómo se resolvió)

### 1. `render()` ya no recibe `h`

Vue 2 pasaba `h` como primer argumento de `render`; `@vue/compat` lo emulaba. Vue 3 real no pasa nada.

```js
// antes (con @vue/compat)         // después (Vue 3 real)
import { h } from 'vue';
render: h => h(App)                render: () => h(App)   // h importado, usado como closure
```

Afectó `main.js`, `login.js` (implícito, sin `render` propio), `portal.js`, `customer-display.js`.

### 2. Registro global de componentes async

`app.component(name, factory)` NO detecta una función simple como cargador async (a diferencia de Vue 2/
compat, y a diferencia de las rutas de vue-router 4 con `component: () => import(...)`, que sí lo resuelven
nativamente). Hace falta envolver explícito:

```js
app.component('large-sidebar', defineAsyncComponent(() => import('...')));
```

8 sitios en `login.js` (3) y `plugins/stocky.kit.js` (5): `large-sidebar`, `px-shell-layout`, `customizer`,
`vue-perfect-scrollbar`, `vue-good-table`.

### 3. `this.$set`/`$delete`/`Vue.set`/`Vue.delete` no existen

La reactividad basada en Proxy de Vue 3 los hace innecesarios para una propiedad ya dentro del árbol
reactivo — asignación directa alcanza.

```js
this.$set(this.product, "category_id", val)   →   (this.product)["category_id"] = val
```

394 sitios en 34 archivos, transformados con un script propio (paren/quote-balance aware) para no romper
expresiones anidadas.

### 4. `v-model` propio: SIEMPRE `modelValue`/`update:modelValue`

El compilador de Vue 3 compila cualquier `v-model="x"` bare a `:modelValue="x" @update:modelValue="x=$event"`
sin importar la opción `model:{prop,event}` (Vue 2), que se ignora en silencio. Todo componente propio cuyos
consumidores usan `v-model` plano tiene ahora ese contrato real, con `value`/`input` (o `checked`/`change`)
conservados como API explícita secundaria para quien los use directamente:

`VSelect`, `PxInput`, `PxTextarea`, `PxCheck`, `PxModal`, `PxSelect`, `PxTabs`, `RichTextEditor`,
`CustomFieldsForm`, `ProductFilterPanel`, `SerialNumbersField`, el `ListToolbar` inline de `CustomerLedger`.

Patrón aplicado (dual prop + `internalValue` computed + emitir ambos eventos):

```js
props: { modelValue: { default: undefined }, value: { default: undefined } },
emits: ["update:modelValue", "input"],
computed: {
  internalValue() { return this.modelValue !== undefined ? this.modelValue : this.value; }
},
methods: {
  emitValue(v) { this.$emit("update:modelValue", v); this.$emit("input", v); }
}
```

`PxSelect`, `PxTabs`, `RichTextEditor`, `CustomFieldsForm`, `ProductFilterPanel` y `SerialNumbersField` se
encontraron rotos bajo Vue 3 puro vía fallos de E2E reales (selección de serial en POS, selects/tabs px-next
en ~15 pantallas, editor de contratos, campos personalizados de cliente/proveedor, panel de filtros de
productos) — todos seguían con el contrato Vue 2 `value`/`input` pese a estar impulsados por `v-model` plano,
así que `update:modelValue` nunca se emitía ni se leía. `PxTable` tenía además un `model:{...}` muerto (0
consumidores con `v-model` real, todos usan `:selected`/`@update:selected` explícito) — se eliminó sin más.

### 5. `inheritAttrs: false` ya no fusiona `class`/`style` automáticamente

Vue 2 (y `@vue/compat` MODE 2 vía `INSTANCE_ATTRS_CLASS_STYLE`) fusionaba `class`/`style` en el elemento raíz
SIEMPRE, sin importar `inheritAttrs`. Vue 3 real no lo hace si `inheritAttrs:false` — hay que reenviar
`$attrs.class`/`$attrs.style` a mano. Corregido en `VSelect`, `PxInput`, `PxCheck` (root `:class="[..., $attrs.class]" :style="$attrs.style"`); `VsPx`/`LucideIcon` ya lo hacían bien.

### 6. `this.$on`/`$off`/`$once` no existen (solo `$emit` sobrevive)

`platform/events.js`'s `createEventBus()` (infraestructura de una fase anterior, expuesta como `events` y
`window.Fire`) es el reemplazo establecido. Migrado: el relay de emisión de dropdown de BootstrapVueNext
(`platform/bootstrap/nav.js`) y sus 6 consumidores (`providers.vue`, `Customers_ecommerce.vue`,
`Customers_without_ecommerce.vue`, `customers.vue`, `index_transfer.vue`, `index_sale.vue`).

### 7. `.sync`/`.native` no existen en el compilador de Vue 3

No hay forma de probar "sintaxis vieja" contra un compilador Vue 3 real — se eliminaron las ramas de
comparación con sintaxis antigua en los tests (siempre se saltaban de todas formas).

### 8. `Vue.config` global no existe

`window.Vue` (build UMD) no tiene `.config` — solo existe por-`app` tras `createApp()`.
`Vue.config.productionTip/silent/devtools` se quitaron de los entrypoints (sin equivalente real en Vue 3,
comentario explicativo en su lugar) y de los 2 arneses de test UMD que aún los usaban
(`13-slots-equivalence.spec.js`, `20-clickaway-directive.spec.js`, reescritos a `createApp()+mount()`).

## Retirado

- `@vue/compat` del `package.json`/lockfile (`npm uninstall`).
- `webpack.mix.js`: alias `vue → @vue/compat`.
- `platform/vue-compat.js`, `platform/compat/bvn-mode.js`: eliminados enteros.
- `platform/bootstrap/core.js`: `pure()` (antes estampaba `compatConfig:{MODE:3}` en componentes
  BootstrapVueNext) es ahora identidad — se conserva la función (no sus ~56 call sites) para no tocarlos.
- `compatConfig` residual en `PxButton`, `PxTable`, `PxShell`, `VsPx`, `LucideIcon`, `DateRangePicker`,
  `Datepicker`, `ClockPicker`, `AI_Reports.vue`, `Stock_Adjustment_Report.vue`, `Stock_Transfer_Report.vue`,
  `warehouse_report.vue`.
- Código dead-por-compat: bloque `$refs.modernPaymentModal.$on('payment-success', ...)` en `pos.vue`
  (silenciosamente no-op vía try/catch; `Reset_Pos()` ya ocurría por el binding real
  `@payment-success="onModernPaymentSuccess"`).

## No tocado deliberadamente (probado como-está, sin fallas reales)

- **VueGoodTable**: 0 usos de `v-model` por consumidores, nada que migrar.
- **vue3-apexcharts**, **vuedraggable@4**, **BootstrapVueNext**, **vee-validate 4**, **vue-i18n 11**
  (`legacy:true` se mantiene — no se migraron los ~13k `$t()` a Composition API, fuera de alcance),
  **vue-sweetalert2**: funcionan nativos bajo Vue 3 puro, verificados vía E2E extenso.

## Fallas E2E conocidas, preexistentes (no introducidas por esta fase)

Confirmadas por comparación cruzada contra el worktree `vuex4-createapp` (fase anterior, sin tocar) y/o
inspección directa — **no forman parte del alcance de esta fase**:

1. **`$unhead` sin wire-up** (`19-head-unhead.spec.js`, 1 test): `globalProperties.$unhead` nunca se asigna
   en `platform/head/*.js` (solo `app.mixin(...)`, sin `app.use(head)` ni asignación explícita). El mixin
   funciona bien (14/15 tests de esa suite pasan); solo el accessor de verificación quedó sin cablear.
2. **WooCommerce/Shopify "Desconectado"** (`27-btable-pilot.spec.js` ×4, `31-tables-matrix.spec.js` ×2,
   `21-bootstrap5-bvn.spec.js` ×3): la integración demo queda en estado "Desconectado" con un skeleton loader
   permanente — 0 `pageerror`, dependiente del estado de conexión de datos demo, no de Vue.

## Fallas de production-smoke conocidas, NO defectos (limitaciones arquitectónicas explícitas)

`npm run production` compila limpio (0 errores). El E2E completo en modo dev (`npm run dev`) es la suite de
referencia real — **el smoke contra el build de producción tiene 3 categorías de exclusión conocidas e
intencionales**, investigadas a fondo esta fase, ninguna causada por el retiro de `@vue/compat`:

1. **Ruta `/app/_ui` dev-only** (`32-bvn-layout-primitives.spec.js`, `33-forms-contract.spec.js`,
   `34-forms-validation.spec.js`, `36-bootstrap5-cutover.spec.js`): eliminada estáticamente del build de
   producción por diseño (`router.js`: `if (process.env.NODE_ENV !== "production") { ... }`, desde la fase
   original del playground px-next). Sin esa ruta, `window.__pxProbe` nunca se define — timeout de 30s por
   test, sistemático y esperado.
2. **`setVm()` depende de `__vueParentComponent`** (varios casos de `31-tables-matrix.spec.js`: sesiones,
   informe woocommerce, detalle/libro mayor de cliente, cocina, contratos, orden local): esta propiedad de
   enlace DOM↔instancia es **retirada por el propio build de producción de Vue** (confirmado por grep directo
   de `node_modules/vue/dist/*`: presente en `vue.global.js`/`vue.esm-browser.js`, AUSENTE de
   `vue.runtime.esm-bundler.js` y de las variantes `.prod`/`.cjs` — el build que webpack resuelve). Es
   comportamiento upstream de Vue, no de esta migración. Estos casos pasan limpio en modo dev.
3. **WooCommerce/Shopify "Desconectado"** (mismo gap preexistente de arriba, también visible en
   `31-tables-matrix.spec.js` shopify).

## Validación

- `npm run dev`: 42 warnings (baseline sin cambios).
- `npm run production`: 0 errores, compila limpio.
- E2E completo (dev): 331 passed, 1 skipped, 10 failed — los 10 son los preexistentes documentados arriba.
- `npm ci` + `npm ls` en directorio aislado: limpio, sin `@vue/compat`, sin `UNMET`/`invalid`/`missing`.
- PHPUnit (Unit + Feature) y snapshot de rutas: verdes.

## VPS

Node 22 requerido antes de desplegar.
