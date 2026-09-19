# Spike: PRODEX sobre Vue 3 + `@vue/compat`

Base: `4afa1b4` (Vue 2.7). Rama `spike/vue3-compat-runtime`. Es una prueba aislada: no se migró Router, Vuex, vue-i18n, BootstrapVue, Bootstrap, vee-validate, Vite ni TypeScript; no cambia diseño, rutas, backend ni tenancy.

**Veredicto: GO** (sección 8). PRODEX arranca en los cuatro entrypoints, los 58 E2E pasan bajo compat sin tocar sus expectativas y todo lo que quedó por resolver es migrable de forma incremental.

## 1. Configuración usada

| Pieza | Valor |
|---|---|
| `vue` / `@vue/compat` / `@vue/compiler-sfc` | 3.5.43 (mismas versiones; `package.json` `^3.5.43`) |
| `vue-loader` | 16.8.3 (el que exige Laravel Mix 6.0.49 para `version: 3`) |
| `vue-template-compiler` | 2.7.16 se conserva solo para el test de slots en la rama Vue 2; el test elige el compilador según la versión |
| Laravel Mix | `.vue({ version: 3, options: { compilerOptions: { compatConfig: { MODE: 2 } } } })` |
| Alias webpack | `vue` → `@vue/compat` |
| Flags del build bundler | `DefinePlugin`: `__VUE_OPTIONS_API__: true`, `__VUE_PROD_DEVTOOLS__: false`, `__VUE_PROD_HYDRATION_MISMATCH_DETAILS__: false` |
| Modo runtime | `configureCompat({ MODE: 2 })` explícito (`resources/src/platform/vue-compat.js`, primer import de `main`, `login`, `portal` y `customer-display`) |
| Avisos | **Ninguno silenciado ni desactivado**: se ven todos los avisos de deprecación en el build de desarrollo |
| `package-lock.json` | Sigue en lockfile v1 (CRLF): +259 / −401 líneas; `npm ci` en directorio limpio: OK |

`npm install` usó `--legacy-peer-deps` solo para escribir el lock (las librerías Vue 2 declaran `vue ^2` como peer); `npm ci` no lo necesita.

## 2. Cambios mínimos necesarios para arrancar

Todo el código funcional (POS, caja, pagos, inventario…) se conserva. Los cambios son de dos tipos.

**a) Plantillas que Vue 3 no compila (error de compilación, 85 errores → 0).**
- `<template v-for>` con `:key` en el hijo (40 sitios, 30 archivos: formularios de compra/venta/ajustes/traslados/cotizaciones/devoluciones y las tablas de pagos/detalle del POS y de ventas): la clave pasa al `<template>` y se quita del hijo. Mismo comportamiento de lista (una clave por iteración).
- `v-if / v-else-if` con la misma `:key` en todas las ramas (`dashboard.vue`, 45 casos): se quita la clave repetida de las ramas y se pone `:key="sectionId"` en el `<template v-for>` que las contiene.

Estos dos cambios son un error de compilación en Vue 3 y **no** son válidos en Vue 2 (Vue 2 rechaza la clave en `<template>`), así que solo existen en esta rama.

**b) Adaptadores de runtime (`resources/src/platform/`), cada uno con la evidencia del fallo que evita.**

| Adaptador | Problema real bajo compat (evidencia) | Qué hace |
|---|---|---|
| `vue-compat.js`: `Vue.use` idempotente | `[vuex] already installed` (console.error, rompía el E2E en la carga): `Vue.use` de compat ya no ignora un plugin repetido y `store/index.js` + `store/modules/auth.js` instalan Vuex dos veces | Restituye la semántica de Vue 2 sin tocar los plugins |
| `vue-compat.js`: `globalProperties` → `Vue.prototype` | `$swal` no existía: `vue-sweetalert2` 5.x detecta `Vue.config.globalProperties` y publica ahí; las instancias creadas con `new Vue()` heredan de `Vue.prototype`. Fallaba la confirmación SweetAlert (E2E `12-platform-services`) | Traspasa lo que un plugin publica en `globalProperties` a `Vue.prototype` |
| `compat/router-link.js` | vue-router 3 decide slot normal/con ámbito con `$scopedSlots.$hasNormal` (Vue 2.6): `<router-link>` se pintaba como `<span>` sin `<a>` ni navegación (E2E de navegación: 0 enlaces; aviso "trying to use a scoped slot but it didn't provide exactly one child") | Marca el slot por defecto como normal, como en Vue 2 |
| `compat/bootstrap-vue.js` | `b-form-select`, `b-form-checkbox`, `b-form-radio` y `b-form-tags` pintan `directives: [{ name: 'model' }]`; en Vue 3 esa directiva instala un `change` que llama a `el[assignKey]` y el vnode no lo tiene: **`pageerror: el[assignKey] is not a function` en cada cambio** (caja, POS, pago mixto) | Añade un asignador vacío a esos elementos (BootstrapVue ya actualiza su valor con su propio `change`) |
| `validation/vee-adapter.js` (`extends` con `render` propio) | `h is not a function` en `ValidationObserver`: `@vue/compat` solo adapta `h` de Vue 2 si `render` es propiedad directa del componente, no heredada por `extends` | `render` declarado en el propio wrapper, delegando en el original |
| `validation/vee-compat-provider.js` | Ver sección 4 | `render` del provider para VNodes de Vue 3 |

Además: `webpack.mix.js`, `package.json`/lock, las cuatro entradas (`import './platform/vue-compat'`; `main`/`login` también `patchBootstrapVueForCompat`), `router.js` y `portal/router.js` (`patchRouterLinkForCompat`), y `tests/`.

## 3. Entrypoints

| Entrypoint | Comprobación en navegador | Resultado |
|---|---|---|
| `main` (SPA tenant) | 03/05/06/07/08/09/10/11/12/15: navegación, POS, caja, offline, pago mixto, lote/serial, es/en/ar+RTL, 83 pantallas | OK |
| `login` | `01-login` (formulario, credenciales inválidas, login válido → panel) | OK |
| `portal` | `16-entrypoints`: `/portal/login` monta y el `v-model` responde; una ruta protegida sin sesión redirige al login del portal | OK |
| `customer-display` | `16-entrypoints`: token válido (endpoint real) monta `.customer-display-container` y el resumen; sin token 403 | OK |
| `storefront` | No usa Vue; el tamaño del bundle no cambia (82 249 B) | Sin cambios |

## 4. vee-validate 3 bajo compat (punto crítico)

**Hallazgo.** El `ValidationProvider` original no funciona con VNodes de Vue 3, y lo hace de dos maneras:
- Con un componente en el slot (`<b-form-input v-model>`): **excepción** `Cannot read properties of undefined (reading 'model')` en `findModelConfig` (5 excepciones al abrir `/app/products/store-classic`; el formulario de traslados quedaba en blanco).
- Con un elemento nativo: no encuentra ningún campo y **no enlaza nada** (fallo silencioso).
Causa: vee-validate 3 lee `vnode.data.model`, `vnode.componentOptions.Ctor.options.model`, y escribe listeners en `data.on` / `componentOptions.listeners`, además de leer los hijos del componente en `componentOptions.children`. Nada de eso existe en Vue 3.

**Solución (dentro de la capa PRODEX, `vee-compat-provider.js`).** Se reemplaza únicamente el `render` del provider; props, estado, flags, reglas, mensajes, observer y `validate/reset/setErrors` siguen siendo los de vee-validate. El nuevo `render` llama al slot con los slot props, localiza el campo con `v-model` (directiva `model` de un `<input|select|textarea>`, o un componente con la prop `value` / evento `onModelCompat:*`) y añade los listeners como `onInput`/`onChange`/`onBlur`. Si el campo está en el slot de otro componente (`<b-form-group>`, `VField`), envuelve ese slot para inspeccionar sus VNodes cuando el hijo lo renderiza. La escritura del estado se aplaza a una microtarea: hecha dentro de `render` provocaba `Maximum recursive updates exceeded` (bucle infinito en el formulario clásico de producto).

**Verificado en navegador** (`14-validation-layer`, 3 tests, más `03/05/07/09` que envían formularios):

| Comportamiento | Vista migrada a `px-validation-*` (plantillas de marketing) | Vista con alias legacy (Ajustes → Almacenes: `validation-observer` + `VField` + `px-input`) |
|---|---|---|
| `v-model` detectado (incluso dentro de `b-form-group` / `VField`) | OK | OK |
| `required` + mensaje "Este campo es obligatorio" | OK | OK |
| `submit` bloqueado con el formulario inválido (sin petición al servidor) | OK | OK |
| El error aparece y desaparece al escribir / vaciar | OK | OK |
| `reset()` (al reabrir el modal / `observer.reset()`) | OK | OK |
| `setErrors([...])` (error del servidor) en el provider | OK | — |
| `observer.validate()` → `false` marca los campos pendientes | OK | — |

Ninguna validación falla en silencio en las pantallas recorridas. **Límite:** el detector cubre `<input|select|textarea v-model>` y componentes con `model: { prop: 'value', event: 'input' }` (todo BootstrapVue y los `Px*`). Un componente que defina otra prop/evento en `model` y no use `v-model`, o un control cuyo valor solo esté en el DOM (sin `v-model`), no se enlaza; hoy no hay ninguno en las pantallas recorridas, pero la migración a vee-validate 4 / `PxField` con `v-model` explícito lo eliminaría por completo.

## 5. Avisos de compat (durante los 54 tests E2E con avisos)

21 024 mensajes emitidos → **36 avisos únicos**: 17 requieren migración, 4 bloquean Vue 3 puro, 8 pueden cambiar el comportamiento, 7 son benignos. Los avisos que compat repite con contador `(n)` se cuentan cada vez que salen. Reproducir: `npm run test:e2e && npm run test:e2e:compat-warnings` (el fixture vuelca los avisos a `tests/e2e/.artifacts/warnings/`).

Riesgo: **migrar** = requiere una migración planificada pero compat lo mantiene funcionando; **bloquea** = sin equivalente en Vue 3 puro; **comportamiento** = puede renderizar/actuar distinto; **benigno** = sin efecto.

| Aviso | Ocurrencias | Archivos / componentes | Riesgo | Acción futura |
|---|---:|---|---|---|
| PRIVATE_APIS | 3375 | RouterLink (librería), App (App.vue), BDropdown (librería), Index (containers/layouts/largeSidebar/index.vue) | migrar | Accesos a `$options.parent`, `$vnode`, `_uid`… (Vue 2 privado). Casi todos vienen de BootstrapVue/vee-validate/vue-i18n; se van al migrarlos. |
| OPTIONS_DESTROYED | 3174 | RouterLink (librería), App (App.vue), BDropdown (librería), Index (containers/layouts/largeSidebar/index.vue) | migrar | Renombrar `destroyed` → `unmounted` (compat lo acepta; Vue 3 puro no). |
| OPTIONS_BEFORE_DESTROY | 3045 | RouterLink (librería), App (App.vue), BDropdown (librería), Index (containers/layouts/largeSidebar/index.vue) | migrar | Renombrar `beforeDestroy` → `beforeUnmount` (42 archivos propios + librerías). |
| INSTANCE_EVENT_HOOKS | 1800 | App (App.vue), Pos (views/app/pages/pos.vue), ProductsListNext (views/app/products/next/index.vue), Templates (views/app/pages/marketing/templates.vue) | migrar | Eventos `hook:mounted|beforeDestroy…` (vee-validate, vue-select, BootstrapVue): usar `@vue:mounted` o composición. |
| RENDER_FUNCTION | 1508 | BDropdown (librería), RouterLink (librería), VuePerfectScrollbar (librería), VueGoodTable (librería) | bloquea | Funciones `render(h)` de Vue 2 (BootstrapVue, vee-validate, vue-router 3): no existen en Vue 3 puro; se resuelve al migrar cada librería. |
| COMPONENT_FUNCTIONAL | 1282 | PxShell (components/px-next/PxShell.vue), App (App.vue), BDropdown (librería), LucideIcon (librería) | bloquea | Componentes `functional: true` (RouterView/RouterLink de vue-router 3, BootstrapVue): migrar con vue-router 4 / BootstrapVue 3. |
| REACTIVE_READONLY_ROUTE | 1112 | — | benigno | vue-router 3 vuelve reactivo `$route` con `Vue.util.defineReactive`; los campos ya son de solo lectura y compat lo ignora (la ruta reactiva funciona: navegación verificada). Desaparece con vue-router 4. |
| INSTANCE_LISTENERS | 916 | BButton (librería), LucideIcon (librería), VuePerfectScrollbar (librería), BCard (librería) | migrar | `$listeners` (Px* y BootstrapVue): pasar a `$attrs` (`onX`). |
| INSTANCE_SCOPED_SLOTS | 554 | BDropdown (librería), RouterLink (librería), PxValidationObserver, BFormGroup (librería) | migrar | `$scopedSlots` (PxModal, vue-router 3, BootstrapVue): usar `$slots`. |
| WATCH_ARRAY | 524 | VueGoodTable (librería), VgtTableHeader, VgtFilterRow, VgtPagination | comportamiento | `watch` sobre arrays ya no dispara con mutaciones si no es `deep`: revisar watchers de arrays. |
| COMPONENT_ALREADY_REGISTERED | 437 | — | benigno | BootstrapVue/vue-router registran sus componentes en más de un `Vue.use` (stocky.kit, login, i18n). Sin efecto; se limpia al unificar la instalación de plugins. |
| RENDER_PROPERTY_UNDEFINED | 352 | PxValidationProvider, VueGoodTable (librería), VField (views/app/products/next/edit/VField.vue) | benigno | Lectura de propiedades internas no declaradas (`_resolvedRules`, `$veeOnInput`… de vee-validate) y `v` en el `:class` de VField (ya era `undefined` en Vue 2). Sin efecto funcional. |
| PROVIDE_OUTSIDE_SETUP | 274 | — | migrar | Plugin/librería llama a `provide()` fuera de `setup()` (vue-sweetalert2 5.x publica también con `Vue.provide`). Sin efecto: `$swal` se traspasa a `Vue.prototype`. |
| INSTANCE_CHILDREN | 274 | — | bloquea | `$children` (vue-router 3 RouterView, BootstrapVue): sin equivalente; lo elimina vue-router 4 / BootstrapVue 3. |
| GLOBAL_PRIVATE_UTIL | 249 | — | bloquea | `Vue.util` (vue-meta 2, vue-clickaway, vue-router 3): utilidades internas de Vue 2 sin equivalente. |
| INSTANCE_EVENT_EMITTER | 249 | App (App.vue), VSelect (librería), VueGoodTable (librería), BVToastPop (librería) | migrar | `vm.$on/$once/$off` (vee-validate, BootstrapVue, código propio): bus externo (`platform/events`). |
| COMPONENT_ASYNC | 220 | Index (containers/layouts/largeSidebar/index.vue), BDropdown (librería) | migrar | Rutas con `() => import()` como componente: envolver con `defineAsyncComponent` (vue-router 4 lo hace solo). |
| INSTANCE_SET | 182 | VueGoodTable (librería) | benigno | `vm.$set` (mutación nativa en Vue 3): compat lo mantiene funcionando. |
| COMPONENT_V_MODEL | 178 | Anonymous, BFormInput (librería), BModal (librería), BFormSelect (librería) | migrar | `v-model` sobre componentes con `model: { prop, event }` de Vue 2: `modelValue` / `update:modelValue`. |
| GLOBAL_MOUNT | 151 | — | migrar | `new Vue({ el }) / $mount` en los 4 entrypoints: `createApp(...).mount()`. |
| GLOBAL_PROTOTYPE | 148 | — | migrar | `Vue.prototype.$x` (plugins: vue-i18n 8, vue-cookies…): `app.config.globalProperties`. |
| GLOBAL_EXTEND | 147 | — | migrar | `Vue.extend` (BootstrapVue, vee-validate, vue-select…): `defineComponent`. |
| GLOBAL_SET | 147 | — | benigno | `Vue.set`: en Vue 3 basta la asignación directa. |
| CONFIG_OPTION_MERGE_STRATS | 139 | — | migrar | `config.optionMergeStrategies` (vuex/vue-i18n/BootstrapVue). |
| OPTIONS_DATA_FN | 138 | — | comportamiento | `data` como objeto (algún componente de librería): en Vue 3 debe ser función. |
| CUSTOM_DIR | 117 | PxSelect (components/px-next/PxSelect.vue), VueGoodTable (librería), PxMenu (components/px-next/PxMenu.vue), BButton (librería) | migrar | Directivas con hooks de Vue 2 (`bind/inserted/update/componentUpdated/unbind`): renombrar a `beforeMount/mounted/…`. |
| PLUGIN_VUE2_ONLY | 110 | — | migrar | `vue-clickaway` 2.2.2 avisa que solo soporta Vue 2 (funciona bajo compat); sustituir por `v-click-outside` propio o VueUse al migrar. |
| ATTR_FALSE_VALUE | 100 | BFormInput (librería), BFormSelect (librería), VgtTableHeader, PxCheck (components/px-next/PxCheck.vue) | comportamiento | `:attr="false"` renderiza `attr="false"` en Vue 3 en vez de quitar el atributo (BFormCheckbox `checked`…). |
| OPTIONS_DATA_MERGE | 63 | BFormInput (librería), BLink (librería), BFormTextarea (librería), BThead (librería) | comportamiento | Fusión de `data` de Vue 2 (superficial) vs Vue 3: puede cambiar valores por defecto anidados. |
| INSTANCE_ATTRS_CLASS_STYLE | 17 | BLink (librería), BModal (librería), BTh (librería), BFormCheckbox (librería) | comportamiento | `class`/`style` ya forman parte de `$attrs`. |
| resolveComponent can only be used in render() or setup(). | 15 | — | comportamiento | Alguna librería resuelve componentes fuera de render; verificar en la fase de migración de la librería. |
| CONFIG_WHITESPACE | 11 | — | benigno | Whitespace `condense` (por defecto en Vue 3). |
| Invalid prop: type check failed for prop "rtl". Expected Boolean, got String | 8 | VueGoodTable (librería) | comportamiento | vue-good-table recibe `rtl` como cadena ("ltr"/"rtl") en la prop booleana; en Vue 3 se evalúa como verdadero. Revisar pantallas RTL al migrar. |
| COMPILER_NATIVE_TEMPLATE | 4 | — | comportamiento | `<template>` sin directiva se renderiza como elemento nativo en compat. |
| INSTANCE_DELETE | 2 | BaseTransition, BModal (librería) | benigno | `vm.$delete`. |
| INSTANCE_DESTROY | 2 | — | migrar | `vm.$destroy()`. |

Avisos de compilación (build de desarrollo): 47 en total, 3 únicos: `<tr> cannot be child of <table>` (41; el navegador inserta `tbody`, sin efecto en CSR), `COMPILER_V_ON_NATIVE` (5; `@click.native` de `router-link` en `PxShell`/`PortalLayout`) y `COMPILER_V_BIND_OBJECT_ORDER` (1).

## 6. Incompatibilidades reales (no solo avisos)

1. Claves en `<template v-for>` y en ramas `v-if/else` (error de compilación en Vue 3): corregidas (sección 2).
2. `Vue.use` no idempotente y `globalProperties` vs `prototype`: corregidas con adaptadores.
3. `router-link` de vue-router 3 sin `<a>` (`$hasNormal`): corregida con adaptador; desaparece con vue-router 4.
4. Directiva `model` interna de Vue 3 sobre elementos de BootstrapVue: corregida con adaptador.
5. vee-validate 3: no detecta campos con VNodes de Vue 3: corregido en la capa PRODEX (sección 4).
6. `<tr>` directo en `<table>` y `.native` en `router-link`: avisos; sin efecto funcional hoy.
7. Sin resolver a propósito (compat lo tolera): `$listeners`, `$scopedSlots`, `beforeDestroy/destroyed`, `$children`, `Vue.util`, `hook:*`, `$on/$off`, `functional`, `render(h)`, `Vue.prototype`, `Vue.extend`, `new Vue({ el })`.

## 7. Resultados

| Prueba | Vue 2 (`4afa1b4`) | Vue 3 compat |
|---|---|---|
| `npm run test:frontend` | 63 | 63 |
| PHPUnit Unit | 1328 | 1328 |
| PHPUnit Feature | 934 OK, 3 omitidos | 934 OK, 3 omitidos |
| Route snapshot | 474 / 20 | 474 / 20 |
| E2E (misma suite, expectativas sin cambios) | 52 | **58 / 58** (+`14` ampliada: 3 tests, +`16-entrypoints`: 4 tests); dos corridas completas, la segunda tras `E2E_RESET=1` |
| Build desarrollo | 80 s, 0 avisos de compilación | 73 s, 47 avisos (3 únicos) |
| Build producción (copia fuera del repo) | OK | OK, 311 s |
| Errores de compilación | 0 | 85 → 0 tras corregir claves |
| Errores runtime en E2E | 0 | 0 (4 excepciones de librería/compat corregidas con adaptadores) |
| Avisos de compat únicos | 0 | 36 |

Los 11 tests de `13-slots-equivalence` ahora corren contra el build global de `@vue/compat` con BootstrapVue y vue-good-table reales: la sintaxis `v-slot` funciona; la antigua (`slot` / `slot-scope`) se descarta en silencio (confirma que la fase anterior era necesaria).

**Bundles** (`public/js`):

| Métrica | Vue 2 | Vue 3 compat | Diferencia |
|---|---|---|---|
| `main.min.js` (prod) | 2 394 912 B | 2 499 008 B | +104 096 B (+4.3 %) |
| `login.min.js` | 881 441 B | 986 607 B | +105 166 B |
| `portal.min.js` | 217 355 B | 313 168 B | +95 813 B |
| `customer-display.min.js` | 267 174 B | 361 372 B | +94 198 B |
| `storefront.min.js` | 82 249 B | 82 249 B | 0 |
| Chunks (`js/bundle`) | 652 | 652 | 0 |
| `main.min.js` (desarrollo) | 6 375 038 B | 6 646 121 B | +271 083 B |

Los ~+95 KB por entrypoint son el propio runtime de `@vue/compat` (con el compilador incluido: `vue.esm-bundler`).

## 8. Dependencias

**Funcionan bajo compat** (verificadas por E2E; las marcadas con * con un adaptador):
BootstrapVue 2.23 * (b-table, b-modal, b-dropdown, b-tabs, b-toast, formularios), vue-good-table 2.21, Vuex 3.6 *, vue-router 3.6 *, vue-i18n 8.26 (es/en/ar), vue-meta 2.4, vee-validate 3.4 * (solo con la capa PRODEX), vue-sweetalert2 5 *, vue-clickaway (con aviso), vue-select, vue-perfect-scrollbar, lucide-vue, SweetAlert2, vue-cookies/vue-cookie/vue-localstorage, axios, moment.

**Montan sin error en las pantallas recorridas, pero su interacción no está cubierta por E2E:** vue-apexcharts, vuedraggable, vuejs-datepicker, vue2-daterange-picker, @johmun/vue-tags-input, @pencilpix/vue2-clock-picker, vue-barcode, vue-easy-print, vue-html-to-paper, @trevoreyre/autocomplete-vue, quill.

**Deberán migrarse** (por orden de bloqueo real): vue-router 3 → 4 (RouterLink/RouterView `functional`, `$children`, `Vue.util`, reactividad de `$route`); vue-i18n 8 → 9 (`Vue.prototype`, `Vue.util`); BootstrapVue → 3 / componentes propios (`render(h)`, `functional`, `$children`, directiva `model`); vee-validate 3 → 4 (o `PxField` con `v-model` explícito; la capa PRODEX ya aísla el cambio); vue-meta → `@unhead/vue`; vue-clickaway → directiva propia; `beforeDestroy/destroyed/$listeners/$scopedSlots/.native` en el código propio.

## 9. Recomendación: GO

No hay ningún bloqueo técnico: el producto arranca y ejecuta sus flujos críticos (POS, caja, offline, pago mixto, lote, serial, idiomas y RTL, portal y pantalla del cliente) bajo Vue 3 + `@vue/compat`, con 5 adaptadores pequeños y localizados. Los 4 avisos "bloquea" pertenecen a librerías que ya tenían migración prevista (vue-router, vue-i18n, BootstrapVue).

Condiciones para continuar:
1. Esta rama es un spike: no se mezcla. Los adaptadores y las correcciones de plantillas se reintroducen en la migración incremental, con `vee-adapter.js` como punto único de validación.
2. Antes de retirar Vue 2, migrar en este orden: (1) vue-router 4 junto con el adaptador de `router-link`; (2) los `beforeDestroy/destroyed`/`$listeners`/`$scopedSlots` propios; (3) vee-validate y BootstrapVue por dominios; (4) vue-i18n y Vuex al final.
3. Añadir E2E de interacción de los widgets "sin cobertura" antes de tocarlos.
4. Repetir `npm run test:e2e:compat-warnings` en cada fase: el número de avisos únicos debe bajar.
