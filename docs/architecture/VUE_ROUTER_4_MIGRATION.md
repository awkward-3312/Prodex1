# Migración a Vue Router 4

Base: `ef6b116` (Vue 3.5.43 + `@vue/compat`, `configureCompat({ MODE: 2, … })`). `vue-router` **3.6.5 → 4.6.4**. Solo se migró el router: no se tocó Vuex, vue-i18n, vee-validate, BootstrapVue, Bootstrap, Vite ni TypeScript, ni rutas, diseño, backend, tenancy o lógica comercial. Snapshot: 474 rutas tenant y 20 de portal, con dos cambios de sintaxis inevitables (ver 2).

## 1. Cambios

**Routers (`router.js`, `portal/router.js`)**
- `new Router({ mode: 'history', … })` → `createRouter({ history: createWebHistory(), … })`; el portal usa `createWebHistory('/portal')` (antes `base: '/portal'`). `linkActiveClass: 'open'` se conserva. `scrollBehavior` devuelve `{ left: 0, top: 0 }` (antes `{ x, y }`).
- `Vue.use(Router)` y el parche `Router.prototype.push` (que tragaba `NavigationDuplicated`) se eliminan: Router 4 resuelve la navegación duplicada con un `NavigationFailure`, no con un rechazo.
- `router.addRoutes([...])` (`main.js`, rutas de Organización/Operaciones/Inventario) → `router.addRoute(route)` por elemento; el matching por especificidad de Router 4 sustituye la regla de Router 3 que dejaba el comodín al final.
- Login (`login.js`) y el resto de entrypoints con router (`main`, `portal`) montan con `mountWithRouter` (ver 3). `customer-display` no usa router.

**Plantillas (5 archivos)**
- 211 `<router-link tag="a">` → sin `tag` (Router 4 no lo admite y lo dejaría como atributo `tag="a"` en el DOM).
- 5 `@click.native` sobre `router-link` (`PxShell` ×4, `PortalLayout` ×1) → `@click` (el atributo cae en el `<a>` raíz).
- `:exact="tab.exact"` (`dashboard.vue`, barra móvil) → `:active-class="tab.exact ? '' : '…--active'"` + `exact-active-class`: mismo resultado sin la prop retirada.
- No había `append`, `event`, `replace` ni `<router-link v-slot>`.

**Guards (todos conservan la lógica; el valor de retorno sustituye a `next`)**

| Guard | Antes | Ahora |
|---|---|---|
| `beforeEnter: redirectManualSaleToPos` (`/app/sales/store`) | `next({ path: '/app/pos' })` / `next({ name: 'index_sales' })` | `return { path: '/app/pos' }` / `return { name: 'index_sales' }` |
| `beforeEnter: redirectQuotationToPos` (`/app/quotations/create_sale/:id`) | `next({ path: '/app/pos', query })` | `return { path: '/app/pos', query }` |
| `router.beforeEach` global (idioma, NProgress, sync de locale) | `async (to, from, next) => { …; next(); }` | `async (to) => { … }` |
| `router.afterEach` (loader, sidebar) | igual | igual |
| `Check_Token` (sin uso) | `next(false)` / `next()` | `return false` |
| Portal `router.beforeEach` (sin efecto) | `next()` ×3 | `() => {}` |

Redirects (`redirect: "/app/dashboard"`, y los relativos `'app/products/list'` y `'dashboard'`) se resuelven igual en Router 4 sin cambiar su sintaxis.

**Navegación programática.** Auditados `$router.push/replace`, `router.push`, `$route.params/query/hash/meta/matched`, `beforeRouteEnter/Leave/Update` (0), `router.currentRoute` (0), `onReady` (0), `router.app` (0). Nada requirió cambios: `this.$router`/`this.$route` siguen disponibles como propiedades globales de la aplicación.

## 2. Incompatibilidades encontradas

| Incompatibilidad | Efecto | Solución |
|---|---|---|
| `path: "*"` no es válido | Error al crear el router | `path: "/:pathMatch(.*)*"` (mismas URLs cubiertas; `NotFound` sigue al final por especificidad) |
| `path: "not_authorize"` sin `/` inicial | Error al crear el router | `path: "/not_authorize"`. Con Router 3 `push({ name })` ya producía la URL `/not_authorize`; ahora, además, la recarga directa de esa URL funciona |
| `new Vue({ router })` ya no instala nada | `$router`/`<router-view>` ausentes | `app.use(router)` sobre la app real antes de montar (`mountWithRouter`) |
| Vuex 3 inyecta `$store` solo vía `options.parent` | `<router-view>` de Router 4 no es un componente de Vue 2: las vistas se quedaban sin `$store` (`_modulesNamespaceMap` de undefined; login y app rotos) | `app.config.globalProperties.$store = store` en `mountWithRouter` (lo mismo que hace Vuex 4) |
| `<router-view>`/`<router-link>` se declaran `compatConfig: { MODE: 3 }` | vue-meta 2 recorre `vm.$children` de todos los componentes y lanzaba `INSTANCE_CHILDREN compat has been disabled` (página en blanco) | `INSTANCE_CHILDREN: true` en `configureCompat` (un valor global `true` habilita la clave también en componentes MODE 3) |
| Directiva de BootstrapVue sobre `router-link` | 23 plantillas usan `<router-link v-b-tooltip>`; los hooks de Vue 2 (`bind/inserted/componentUpdated`) se ejecutan en el contexto de `RouterLink` (MODE 3) y se desactivan (“compat behavior is disabled”) | `CUSTOM_DIR: true` en `configureCompat`. Cubierto por E2E (aparece el tooltip) |
| Estado activo: Router 3 lo decide por prefijo de ruta, Router 4 por registro `matched` | Un enlace cuya ruta es prefijo de otra pero no su padre ya no recibe `open`/`router-link-active` | Sin cambios de código (ver deuda). `PxShell` usa `aria-current`, que es exact-active en ambos |
| `router.push()` de Router 3 rechazaba la navegación duplicada y el proyecto lo parcheaba | — | Se elimina el parche; no hay llamadores que dependan del rechazo |

No aparecieron incompatibilidades en: parámetros opcionales (`:id?`), rutas anidadas con hijos de ruta absoluta, `props` estáticos, alias `-classic`/`legacy`, `redirect` relativo, `beforeEnter`. Las 474 + 20 rutas pasan el matcher de Router 4 (test `router4.test.mjs`) y no hay nombres duplicados.

## 3. Adaptadores

**Eliminado:** `platform/compat/router-link.js` (`patchRouterLinkForCompat`, el workaround del spike que marcaba el slot de `router-link` como normal). Router 4 lo sustituye: el enlace es un `<a href>` real, sin monkey-patch.

**Añadido (solo bajo compat, desaparece con `createApp`):**
- `platform/compat/vue-router.js` → `mountWithRouter(options, router, el)`: instancia el root sin `el`, hace `app.use(router)` en la app que hay detrás y publica `$store`. No modifica Router 4.
- `configureCompat({ MODE: 2, INSTANCE_CHILDREN: true, CUSTOM_DIR: true })` en `platform/vue-compat.js` (dos claves por vue-meta y BootstrapVue; no se desactiva ni silencia ningún aviso).

## 4. Avisos de compat retirados (mismos 54 tests, antes → después)

| Métrica | Antes (`ef6b116`) | Después |
|---|---|---|
| Mensajes totales (54 tests comunes) | 21 024 | 16 049 (−23,7 %) |
| Avisos únicos (todos los tests, agrupados) | 36 | 34 |
| Mensajes con `RouterLink`/`RouterView` como primer componente | 851 | 304 |
| `REACTIVE_READONLY_ROUTE` (“Failed making property … reactive” de `$route`) | 1 112 | 0 |
| `Component "RouterLink" has already been registered` | 437 | 0 |
| `CONFIG_OPTION_MERGE_STRATS` | 139 | 0 |
| `OPTIONS_DESTROYED` (RouterView/RouterLink de Router 3) | 3 174 | 22 |
| `GLOBAL_PRIVATE_UTIL` (`Vue.util`) | 249 | 110 |
| `COMPONENT_FUNCTIONAL` | 1 282 | 940 |
| `RENDER_FUNCTION` | 1 508 | 1 372 |
| `INSTANCE_SCOPED_SLOTS` | 554 | 418 |
| Avisos de compilación (`COMPILER_V_ON_NATIVE`) | 5 | 0 (build dev: 47 → 42 avisos) |
| `router-link tag` / `.native` en `router-link` | 211 / 5 | 0 / 0 |
| `[Vue Router warn]` durante los E2E | n/a | 0 |
| `INSTANCE_CHILDREN` | 274 | 860 (sube: la clave queda habilitada de forma explícita y vue-meta recorre también `RouterView`/`RouterLink`; sigue avisando) |

(Con los 75 tests actuales el total de mensajes es 19 367 porque hay 21 tests nuevos.)

## 5. Deuda restante

- `mountWithRouter` y `$store` global: adaptadores de compat; se sustituyen por `createApp(...).use(router).use(store)` al migrar Vuex/quitar compat.
- `INSTANCE_CHILDREN` y `CUSTOM_DIR` globales: se eliminan al migrar vue-meta (→ `@unhead/vue`) y BootstrapVue (o mover `v-b-tooltip` fuera de `<router-link>`).
- Estado activo por prefijo → por registro: revisar las clases `open` del sidebar legacy (`largeSidebar`, solo alcanzable por rutas `*-classic`/`legacy`) si se sigue usando; el sidebar px-next no depende de ello.
- `router.push({ name: 'NotFound' })` (`main.js`, `login.js`) resuelve a `/` con Router 3 y con Router 4 (mismo comportamiento); conviene pasar `pathMatch` cuando se reescriba ese manejo de 404.
- La regla de `beforeEnter` optimista de POS (`canUsePos()` devuelve `true` si los permisos aún no están hidratados) no se cambió.
- Pendiente para “Vue 3 puro”: `$children`, `beforeDestroy/destroyed`, `$listeners/$scopedSlots`, `functional`/`render(h)` de BootstrapVue y vee-validate, vue-i18n 8 y Vuex 3.
