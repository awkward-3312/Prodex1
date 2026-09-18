# Auditoría de modernización del frontend de PRODEX (Vue 2 → Vue 3)

- **Fecha:** 2026-09-18
- **Rama:** `chore/frontend-modernization-audit` (creada desde `feat/legal-pages-production`)
- **Alcance:** solo auditoría. No se cambió código de producto, dependencias, build, estilos ni APIs.
- **Documento hermano:** [`FRONTEND_MODERNIZATION_MATRIX.md`](./FRONTEND_MODERNIZATION_MATRIX.md)
- **Evidencia reproducible:** [`audit-evidence/`](./audit-evidence/README.md)

## 0. Cómo leer este documento

Cada cifra viene de un comando o script ejecutado sobre el árbol de trabajo real. Se distinguen tres niveles de certeza:

- **Medido:** contado con grep/AST/build sobre el repositorio.
- **Probado en spike:** verificado ejecutando `@vue/compat` en un directorio temporal fuera del repo (sección 15).
- **Inferido:** conclusión razonada sin prueba directa. Se marca como "inferido" y se pide verificarlo en el primer spike de código.

Dos avisos sobre el estado del repositorio al momento de auditar:

1. El árbol de trabajo ya tenía **~636 archivos modificados sin commit** (traducción de comentarios a español en `app/`, `database/`, `tests/`, más 22 archivos `public/js/prodex-*.js` sin seguimiento). Esta auditoría no los tocó. Mientras se auditaba, otro proceso siguió modificando archivos (`database/migrations/*`, `database/seeders/*`, `app/Events/CartUpdated.php`); no fue esta tarea.
2. Los builds se ejecutaron en una **copia del árbol de trabajo fuera del repo**, porque Laravel Mix escribe sobre archivos versionados (`public/js/*.min.js`, `public/mix-manifest.json`) y `CleanWebpackPlugin` vacía `public/js/*` antes de compilar.

### Conflicto con `PRODUCT.md`

`PRODUCT.md` declara "**Stack is fixed** … The redesign works within this stack — it is not a rewrite or a framework migration". Esta auditoría prepara justo esa migración. Antes de la primera fase de código hay que **actualizar `PRODUCT.md`** (decisión del dueño del producto) para que la restricción diga: "modernización técnica incremental permitida; rediseño y modernización son proyectos separados". También dice "11 shipped locales" pero enumera 10 (`ar, bn, de, en, es, fr, hi, pt, tr, ur`); `resources/lang/` tiene esas 10.

---

## 1. Arquitectura actual

```
Navegador
 ├─ /app/*           Blade resources/views/layouts/master.blade.php  →  <div id="app"> + /js/main.min.js (script clásico, no módulo)
 │                   SPA Vue 2.7 · Vuex 3 · vue-router 3 (history) · vue-i18n 8 · BootstrapVue 2 / Bootstrap 4 · vee-validate 3
 │                   + 23 scripts sueltos prodex-*.js (copiados de resources/static, cargados con ?v={{ time() }})
 ├─ /login, reset    login.min.js      (Vue 2 + BootstrapVue + vee-validate, sin router/store)
 ├─ /portal          portal.min.js     (Vue 2 + router propio, 21 .vue, sin BootstrapVue global)
 ├─ /customer-display customer-display.min.js (Vue 2 + i18n, sin router/store)
 ├─ tienda online    storefront.min.js (Alpine.js + Tailwind, sin Vue)
 └─ central (landing, checkout, Super Admin): Blade + CSS/JS propios en public/assets_super (no pasan por Mix)

Servidor: Laravel + Stancl Tenancy (BD por tenant). SPA idéntica para todos los tenants.
Datos por tenant llegan por: window.__planSummary/__appName/__uploadPath (Blade), /api/* (axios), /api/translations/{locale} (tabla `translations`).
PWA: public/sw.js escrito a mano (248 líneas), 3 manifests, sin Workbox. Registrado desde portal, customer-display y storefront.
```

Punto de partida del build: `webpack.mix.js` (22 líneas) con 5 entrypoints:

| Entrypoint | Origen | Salida | Tamaño prod | Vue |
|---|---|---|---|---|
| main | `resources/src/main.js` (156 líneas) | `public/js/main.min.js` | 2.27 MB | 2.7 |
| login | `login.js` (112) | `login.min.js` | 0.83 MB | 2.7 |
| portal | `portal.js` (38) | `portal.min.js` | 0.21 MB | 2.7 |
| customer-display | `customer-display.js` (26) | `customer-display.min.js` | 0.25 MB | 2.7 |
| storefront | `storefront.js` (514) | `storefront.min.js` | 0.08 MB | no usa Vue |

Más 652 chunks lazy en `public/js/bundle/` (77 MB en producción).

Capas de diseño coexistentes:

1. **Legacy Stocky/Gull:** Bootstrap 4.6.2 vendorizado en `resources/src/assets/styles/vendor/bootstrap` (15 415 líneas SCSS) + BootstrapVue + temas `lite-purple`, `dark-purple`, `lite-blue`.
2. **`prodex/`:** tokens `--px-*`, foundation, listas, tablas, toolbars (`assets/styles/sass/prodex/`).
3. **`px-next/`:** 26 componentes `Px*` propios (4 599 líneas, **cero dependencia de BootstrapVue**), tokens `--pxn-*`, IBM Plex autoalojada. `PxShell` ya es el layout por defecto de `/app/*` (`views/app/index.vue`).

---

## 2. Inventario cuantitativo

| Métrica | Valor | Nivel |
|---|---|---|
| Archivos `.vue` | **497** (views/app 432 · components 36 [26 en `px-next`] · containers 7 · portal 21 · `App.vue`) | medido |
| Archivos `.js` en `resources/src` | 57 | medido |
| Archivos SCSS | 376 (117 propios ≈ 16 563 líneas; resto Bootstrap vendorizado ≈ 15 415) | medido |
| Bloques `<style>` en `.vue` | 420 con `lang="scss"`; 336 archivos con `scoped`, 28 con `<style>` sin `scoped` | medido |
| LOC total `.vue` | **258 581** (mediana 311; p90 1 062; máx 19 318) | medido |
| `.vue` > 1 000 / 2 000 / 3 000 / 5 000 LOC | 53 / 17 / 7 / 3 | medido |
| Archivos con `<b-*>` (BootstrapVue) | **198** (28 mezclan `b-*` y `px-*`) | medido |
| Archivos con `<px-*>` | 201 | medido |
| Registros de ruta (SPA tenant) | **467** en `router.js` + 3 padres/6 hijos en `main.js`; portal 20 | medido (AST) |
| Llamadas `$t()` | **15 679** en 317 archivos; 3 087 claves estáticas distintas | medido |
| Llamadas a `Vuex` (`mapGetters`/`mapActions`/`$store`) | 461 helpers + 250 `$store` en 188 archivos | medido |
| Permisos: usos de `currentUserPermissions` | 1 611 en 114 archivos | medido |
| Scripts sueltos `resources/static/prodex-*.js` | 23 (4 341 líneas) | medido |
| Utilidades globales `resources/src/utils` + `plugins` + `mixins` | 9 056 líneas (incluye los 23 scripts sueltos) | medido |
| Tests PHP | Unit 153 archivos (1 321 tests) · Feature 64 archivos (934 tests) | medido |
| Tests frontend | **0** | medido |

### Layouts, entrypoints, componentes globales

- **Layouts:** `PxShellLayout` (por defecto), `largeSidebar/*` (`Sidebar.vue` 2 780 LOC, `VerticalSidebar.vue` 2 549, `TopNav`, `VerticalTopNav`; fallback legacy elegido por `getThemeMode.layout`), `PortalLayout`, `views/app/shell/index.vue` (prototipo `/app/shell`).
- **Registros globales:** `Vue.use` ×22 en 13 archivos; `Vue.component` ×21 en 3 archivos; `Vue.mixin` ×2 (ambos en `plugins/stocky.kit.js`, ambos hacen manipulación de DOM en `mounted/updated` de **todos** los componentes); `Vue.prototype` ×3 (`$uploadPath`, `$imgUrl`, `$limitReachedMessage`); `Vue.directive`/`Vue.filter` 0.
- **Globales por `Vue.component`:** `ValidationObserver`, `ValidationProvider`, `qrcode-scanner` (**usa `template:` como string → requiere el compilador de runtime**), `vue-excel-xlsx`, `lucide-icon`, `px-skeleton`, `serial-numbers-field`, `v-select`, `breadcumb`, `large-sidebar`, `px-shell-layout`, `customizer`, `vue-perfect-scrollbar`, más BootstrapVue completo (`bootstrap-vue.esm`, sin tree-shaking) y `vue-good-table` como plugin global.
- **Globales por `window`:** `window.axios`, `window.auth`, `window.Fire` (bus de eventos = `new Vue()`), `window.__planSummary`, `__appName`, `__uploadPath`, `__pageTitleSuffix`, `Html5QrcodeScanner` (de `public/assets_setup/js/qrcode.js`, script vendorizado sin versión en `package.json`), `qrcodejs` desde cdnjs (sin SRI), `Stripe` desde `js.stripe.com`.

### Componentes más grandes por LOC (top 12)

| # | Archivo | LOC | Plantilla / script / estilo |
|---|---|---|---|
| 1 | `pages/pos.vue` | 19 318 | 1 160 / 7 041 / 11 117 |
| 2 | `pages/old_pos.vue` | 10 685 | 405 / 5 152 / 5 128 — **sin referencias en `src`, `tests` ni `router`** (código muerto) |
| 3 | `pages/settings/system_settings.vue` | 7 159 | 3 844 / 2 299 / 1 016 |
| 4 | `pages/products/Add_product.vue` | 4 588 | ruta `-classic` de respaldo |
| 5 | `pages/products/Edit_product.vue` | 4 079 | ruta `-classic` de respaldo |
| 6 | `pages/sales/index_sale.vue` | 3 403 | 1 386 / 1 897 / 120 |
| 7 | `pages/sales/create_sale.vue` | 3 058 | redirigida al POS por guard; se conserva en disco |
| 8 | `dashboard/dashboard.vue` | 2 898 | ruta `dashboard-legacy` |
| 9 | `containers/layouts/largeSidebar/Sidebar.vue` | 2 780 | 2 651 de plantilla; 209 `<router-link tag=…>` |
| 10 | `components/ModernPaymentModal.vue` | 2 627 | Stripe; usado por el POS |
| 11 | `containers/layouts/largeSidebar/VerticalSidebar.vue` | 2 549 | |
| 12 | `pages/reports/AI_Reports.vue` | 2 537 | |

Hay 33 rutas con chunk `classic`/`legacy` y 45 archivos en carpetas `next/` (20 851 LOC): la migración visual a px-next dejó las versiones anteriores como respaldo. Es superficie muerta o casi muerta que se puede retirar antes de migrar (sección 12).

### Componentes más acoplados

Puntuación = imports + uso de store + 2×eventos `Fire` + `$root` + `$refs` + 2×llamadas axios.

| Archivo | Puntos | imports | store | Fire | `$root` | `$refs` | axios directo |
|---|---|---|---|---|---|---|---|
| `pos.vue` | 114 | 12 | 8 | 17 | 1 | 21 | 19 |
| `old_pos.vue` (muerto) | 99 | 11 | 7 | 16 | 1 | 16 | 16 |
| `system_settings.vue` | 90 | 6 | 14 | 20 | 1 | 23 | 3 |
| `sales/index_sale.vue` | 72 | 22 | 7 | 11 | 3 | 16 | 1 |
| `people/customers.vue` | 63 | 5 | 4 | 17 | 3 | 9 | 4 |
| `sale_return/index_sale_return.vue` | 60 | 19 | 5 | 9 | 1 | 17 | 0 |
| `purchases/index_purchase.vue` | 55 | 20 | 7 | 9 | 1 | 9 | 0 |
| `purchase_return/index_purchase_return.vue` | 55 | 20 | 7 | 9 | 1 | 9 | 0 |

Nota: `axios` directo subestima el acoplamiento a la API; muchas vistas llaman `axios` vía `window.axios` o wrappers.

---

## 3. Incompatibilidades Vue 3 (patrones Vue 2)

Clasificación: **C** = compatible sin cambio o funciona bajo `@vue/compat` (probado o documentado); **M** = requiere migración; **B** = bloqueante (no funciona bajo compat o impide arrancar).

| Patrón | Ocurrencias (archivos) | Clase | Notas |
|---|---|---|---|
| `Vue.use(...)` | 22 (13) | M | En Vue 3 es `app.use`. Bajo compat funciona. |
| `Vue.component(...)` | 21 (3) | M | Igual. |
| `Vue.mixin(...)` | 2 (1) | C→M | Ambos mixins hacen DOM (organizan el sidebar, receipt). Funciona en compat; en Vue 3 puro es global mixin desaconsejado. |
| `Vue.prototype.*` | 3 (1) | M | → `app.config.globalProperties`. Probado en spike: funciona bajo compat. |
| `Vue.directive` / `Vue.filter` / `Vue.set/delete` | 0 | — | |
| `$set` / `$delete` | **421** (36) | C→M | Funciona bajo compat (probado). En Vue 3 puro se elimina (asignación directa). |
| `$forceUpdate` | 86 (21) | C | Síntoma de reactividad frágil. Con proxies de Vue 3 algunos casos dejan de hacer falta y otros exponen bugs latentes. |
| `filters:{}` + `\|` en plantilla | 21 bloques / 39 pipes (25) | C→M | Funciona en compat (probado). Removido en Vue 3. |
| `$listeners` | 6 (5) | M | Solo en `PxButton`, `PxInput`, `PxCheck`, `PxTextarea`, `VsPx`. Vue 3: `$attrs` incluye `on*`. |
| `$scopedSlots` | 1 (1) | M | Solo `PxModal`. |
| `$children` | 0 | — | |
| `.native` | 9 (6) | C→M | Funciona en compat (probado). `PxShell` y `PortalLayout` lo usan en `router-link`. |
| `.sync` | 35 (17) | C→M | Funciona en compat (probado); → `v-model:prop`. |
| `slot-scope` | **207 (89)** | **B** | **Probado en spike: bajo compat no se renderiza el slot** en `b-table` ni en `vue-good-table`. Falla en silencio. Requiere codemod a `v-slot` **antes** de arrancar compat. `v-slot` ya funciona en Vue 2.7, así que el codemod se puede hacer hoy. |
| atributo `slot="nombre"` | **154 (75)** (heurístico) | **B** | Mismo fallo probado. |
| `$on` / `$off` / `$once` | 149 (63) | C→M | Funciona bajo compat (bus `new Vue()` probado). En Vue 3 puro se elimina → emisor externo (mitt). |
| Bus de eventos `Fire` (`Fire.$on/$emit/$off`) | 353 (68) | C→M | 40 nombres de evento distintos (`Event_sms`, `event_delete_draft_sale`, `offline-sync:*`, `ChangeLanguage`, `Event_Pos_Settings`…). |
| `$root` | 309 (228) | C | 271 son `$root.$bvToast`; 12 `$root.$on('bv::dropdown::…')`. |
| `beforeDestroy` / `destroyed` | 43 (42) / 1 | C→M | Compat lo mapea; Vue 3 usa `beforeUnmount`/`unmounted`. |
| render functions (`render(h)`) | 54 (9) | C→M | Incluye `LucideIcon.vue` (funcional, 279 archivos lo usan), reportes con `functional:true` ×5. Probado: `lucide-vue` funciona bajo compat. |
| `model: {prop, event}` | 4 (4) | M | Componentes `px-next` (`PxCheck`, `PxModal`, `PxTable`…). Vue 3: `modelValue`/`update:modelValue`. |
| `template: '<div>…'` en runtime | 1 (`main.js`, `qrcode-scanner`) | M | Exige build con compilador. Convertir a SFC/render. |
| `require()` en entrypoints | 7 (3) | M | `main.js`, `login.js`, `portal.js`. Vite/ESM lo rechaza. |
| `router.addRoutes` | 1 (`main.js`) | **B** (para Router 4) | Removido en vue-router 4 → `addRoute`. |
| ruta `path: "*"` | 1 | M | Router 4 exige `/:pathMatch(.*)*`. |
| `Router.prototype.push` parcheado | 1 | M | Router 4 ya devuelve Promise; el parche desaparece. |
| `<router-link tag/event/exact>` | 211 (209 son `tag="a"` en `Sidebar.vue`, valor por defecto) | M | En Router 4 esos props no existen y `tag="a"` queda como atributo inerte. El bloqueo real es otro: **`router-link` de vue-router 3.6.5 no renderiza `<a>` bajo compat** (spike), 645 usos. |
| `el.__vue__` (instancia desde el DOM) | 7 líneas en 4 scripts sueltos | **B** | Probado: `__vue__` es `undefined` bajo compat/Vue 3. Rompe en silencio 4 scripts (`prodex-erp-integrity-ui`, `prodex-inventory-native-menu`, `prodex-sidebar2-organizer`, `prodex-transfer-permission-ui`). |
| `::v-deep` / `>>>` / `/deep/` | 304 / 69 / 5 | C→M | `::v-deep` y `:deep()` sirven; `>>>` y `/deep/` están deprecados en el compilador de Vue 3. |
| `Vue.config.silent/productionTip/devtools` | 7 (3) | M | Sin equivalente directo. |
| Vue 2 `new Vue({...}).$mount` | 7 (4) | M | → `createApp`. |
| `vee-validate` 3 (`ValidationProvider`/`Observer`) | 590 tags (102) + 162 (123) | **B** | **Probado:** bajo compat el provider **no detecta el `<input v-model>`** y nunca marca error; `provider.validate(valor)` explícito sí funciona. Riesgo silencioso: formularios inválidos pasan sin validación de cliente. |
| `vue-meta` (`metaInfo`) | 308 (300) | C→M | Funciona bajo compat (título probado). Sin versión para Vue 3 estable; hay que reemplazar. |
| `vue-sweetalert2` (`this.$swal`) | 369 (95) | M | **Probado:** `Vue.use(VueSweetalert2)` no instala `$swal` bajo compat. Arreglable con un shim de una línea; mejor un `PxConfirm`. |

**Bloqueantes (B) reales para arrancar `@vue/compat`:** `slot-scope`/`slot=`, `vee-validate` 3, `router-link` de vue-router 3, scripts que leen `el.__vue__`. Los demás patrones funcionan bajo compat con advertencias.

---

## 4. Router

Medido con AST (`audit-evidence/scan-routes.js`):

| Métrica | Valor |
|---|---|
| Registros con `path` (tenant, `router.js`) | 467 (≈ 400 hojas + 67 con `children`) |
| Rutas con nombre | 397 |
| Lazy loading (`() => import()`) | **463 de 463** con componente (100 %); 0 estáticas |
| Chunks con `webpackChunkName` | 462 comentarios mágicos en `resources/src` |
| `redirect` | 43 (0 funciones) |
| `beforeEnter` | 2 (`redirectManualSaleToPos`, `redirectQuotationToPos`) |
| `meta` | 3 rutas, solo `templateType`. **No hay `meta.permission`/`requiresAuth`.** |
| `props` | 22 (`{ mode: "create"|"edit" }`) |
| Comodín `*` | 1; parámetro opcional: 1 (`Banners/Edit/:id?`) |
| Rutas solo desarrollo | `/app/_ui` (playground) y `/app/shell/*`, detrás de `process.env.NODE_ENV !== "production"`; CI verifica que no filtren al bundle |
| Guards globales | `beforeEach` async (carga idioma, POST `sync-locale`) y `afterEach` (loader, sidebar) |
| Modo | `history`, `linkActiveClass: "open"`, `scrollBehavior → {x:0,y:0}` |
| Portal | 20 rutas, lazy 100 %, `beforeEach` propio con `meta.guest`/`requiresAuth` |

`PRODUCT.md` menciona "~345 rutas"; la cifra medida es 467 registros (incluye padres y rutas de desarrollo).

**Los permisos no viven en el router.** Se evalúan dentro de los componentes con `currentUserPermissions.includes(...)` (1 611 usos en 114 archivos) y en el servidor. Consecuencia doble: no hay que migrar guards de permisos a Router 4, pero tampoco existe un mapa ruta→permiso que sirva de red de seguridad. Un 403 en cualquier GET navega a `not_authorize` desde el interceptor de axios (`main.js`).

Incompatibilidades con Vue Router 4: `Vue.use(Router)`, `router.addRoutes`, `path: "*"`, `mode: "history"` (→ `createWebHistory`), `Router.prototype.push` parcheado, `<router-link tag/event/exact>` (211; 209 son `tag="a"`), `.native` sobre `router-link` (2), `this.$router.go(-1)` (compatible), `router.match/currentRoute` no se usan, `next()` en guards (compatible, deprecado), `scrollBehavior` con `{x,y}` (→ `{left,top}`), `beforeEnter` (compatible).

Probado en spike (vue-router 3.6.5 bajo compat): `router-view`, modo history, `beforeEach` async con `next`, rutas con nombre y params, `addRoutes`, `*`, `push().catch` **funcionan**; **`router-link` no renderiza `<a>`** (ni con `tag` ni sin él). Causa probable (inferida): `RouterLink` de v3 lee `this.$scopedSlots.$hasNormal`, propiedad interna que compat no provee. Con 645 `<router-link>` en la app, Router 3 no es utilizable como base de compat.

---

## 5. Vuex

6 módulos, 2 con `namespaced` (`config`, `shellScope`). Medido con AST:

| Módulo | state | getters | mutations | actions | Notas |
|---|---|---|---|---|---|
| `auth` | 13 | 14 | 14 | 3 | Permisos, usuario, formato de fecha/precio/decimales |
| `config` | 4 | 4 | 7 | 7 | 529 líneas; contiene `applyPrimaryColor` (inyecta CSS de branding) |
| `largeSidebar` | 3 | 3 | 6 | 6 | |
| `compactSidebar` | 2 | 2 | 2 | 2 | |
| `shellScope` | 4 | 5 | 4 | 3 | Alcance sucursal/almacén del shell |
| `language` | 1 | 1 | 1 | 1 | Usa `vue-localstorage` |
| **Total** | **27** | **29** | **34** | **22** | |

Uso: `mapGetters` 372, `mapActions` 89 (188 archivos), `$store.getters` 27, `$store.dispatch` 15, `$store.commit` 4, `$store.state` 4. Nada de `store.subscribe/watch/registerModule/plugins`.

**Dificultad para Pinia: baja (3/10).** El store es pequeño, plano y casi solo lo consumen `mapGetters`. El coste real es tocar 188 archivos, no la lógica. Probado: Vuex 3.6.2 con módulos namespaced y `mapGetters/mapActions` funciona bajo compat, así que Vuex 3 → Vuex 4 → (opcional) Pinia puede ir después del arranque de Vue 3. Es coherente con el orden pedido (compat → router/estado/i18n), con la salvedad del Router (sección 4).

---

## 6. BootstrapVue — mapa completo

Medido: **5 825 etiquetas `<b-*>`**, 49 tipos distintos, 198 archivos. Registro global en 2 sitios (`plugins/stocky.kit.js`, `login.js`) importando `bootstrap-vue/dist/bootstrap-vue.esm` completo. `lite-purple.scss` importa `bootstrap-vue/dist/bootstrap-vue.css` (5 archivos SCSS lo referencian).

| Componente | Etiquetas | Archivos | Reemplazo | Riesgo de reemplazo |
|---|---|---|---|---|
| `b-col` | 1 418 | 121 | `<div class="col-md-6">` (props `md/lg/cols/offset` → clases) | Bajo, codemod |
| `b-form-group` | 1 079 | 110 | `PxField` (ya 813 usos) | Medio (label-for, feedback, descripción) |
| `b-form-input` | 675 | 103 | `PxInput` (538 usos) | Medio (`:state`, `trim`, `number`) |
| `b-button` | 603 | 143 | `PxButton` (990 usos) | Bajo |
| `b-form-invalid-feedback` | 384 | — | slot de error de `PxField` | Medio (acoplado a vee-validate) |
| `b-row` | 343 | 120 | `<div class="row">` | Bajo, codemod |
| `b-card` | 330 | 131 | `PxCard` (256 usos) | Bajo-medio (`header`, `title`) |
| `b-form` | 126 | — | `<form>` | Bajo |
| `b-modal` | 112 | 53 | `PxModal` (129 usos) | **Alto** (API por id, `$bvModal`, focus, POS) |
| `b-form-select` (+71 `option`) | 95 | 44 | `PxSelect` / `<select>` | Medio |
| `b-badge` | 89 | 41 | `PxBadge` (234) | Bajo |
| `b-form-checkbox` / `-radio(-group)` | 66 / 12 | 28 / 1 | `PxCheck` (77) | Bajo |
| `b-input-group` (+`append` 28, `prepend` 11) | 64 | 25 | `PxInput` con slots o markup | Medio |
| `b-alert` | 51 | 27 | `PxAlert` (145) | Bajo |
| `b-form-textarea` | 48 | 32 | `PxTextarea` (67) | Bajo |
| `b-tab` / `b-tabs` | 42 / 11 | 11 | `PxTabs` (9 usos) | Medio |
| `b-dropdown` (+`item` 21) | 20 | 8 | `PxMenu`/`PxKebab` | Medio |
| `b-table` (+`tr/th/td`) | 19 | 10 | `PxTable` | Alto (ordenamiento/paginación) |
| `b-form-file` | 18 | 15 | **No existe** `PxFileInput` | Medio |
| `b-sidebar` | 12 | — | **No existe** | Medio |
| `b-spinner` / `b-progress` / `b-list-group` / `b-pagination` / `b-form-datepicker` / otros | ≤ 12 c/u | ≤ 7 | `PxSkeleton`, `PxPagination`, faltan `PxDatePicker`, `PxProgress` | Bajo-medio |

**APIs imperativas y directivas:**

| API | Usos | Archivos | Notas |
|---|---|---|---|
| `this.$bvToast.toast(msg, {title, variant, solid})` | 277 llamadas (334 referencias; 271 vía `$root.$bvToast`) | **249** | Forma casi uniforme → **codemod mecánico**. Es el mayor retorno por esfuerzo de todo el mapa. |
| `$bvModal.show` / `.hide` / `.msgBoxConfirm` | 132 / 104 / 11 | 53 | Modales por id. Exige `PxModal` con API imperativa. |
| `this.$swal(...)` (SweetAlert2) | 369 | 95 | Es el confirm/alert dominante, mucho más que `msgBoxConfirm`. |
| `v-b-tooltip` / `v-b-toggle` / `v-b-popover` | 116 / 12 / 1 | 40 | Directivas; en Vue 3 los hooks cambian de nombre. Falta `PxTooltip`. |
| `$root.$on('bv::dropdown::show/hide')` | 12 | 2 | Acoplado al bus interno de BootstrapVue. |

**Qué tan desacoplable es PRODEX de BootstrapVue:** alto. `px-next` (26 componentes) no depende de BootstrapVue ni de `$bv*` (0 referencias) y ya cubre botones, inputs, campos, selects, checks, textarea, cards, badges, alerts, modales, tablas, paginación, tabs, menús y skeleton. Faltan cuatro piezas (`PxToast` como servicio, `PxConfirm`, `PxTooltip`, `PxDatePicker`) más `PxFileInput`. Los 198 archivos restantes son sobre todo `b-row/b-col/b-form-group/b-button/b-card`, reemplazables de forma mecánica. El punto duro son 53 modales por id y el POS (127 `b-*` solo en `pos.vue`).

**Probado en spike:** BootstrapVue 2.23.1 bajo `@vue/compat` **renderiza y funciona** en el subconjunto que usa PRODEX (sección 15), con advertencias de deprecación. No es una configuración soportada por los mantenedores; sirve como puente, no como destino.

---

## 7. Dependencias frontend

Versión actual = instalada en `node_modules`. "Usos" = archivos de `resources/src` que importan el paquete (más Blade/mix cuando aplica). Compat = compatibilidad con Vue 3.

| Paquete | Versión | Dónde se usa | ¿Necesario? | Compat Vue 3 | Acción |
|---|---|---|---|---|---|
| `vue` | 2.7.16 | todo | sí | — | **Actualizar** a 3.x vía `@vue/compat` |
| `vue-loader` | 15.11.1 | Mix | sí | no (v17 para Vue 3) | Actualizar |
| `vue-template-compiler` | 2.7.16 | Mix | sí (Vue 2) | no | Eliminar al migrar (usa `@vue/compiler-sfc`) |
| `vue-router` | 3.6.5 | `router.js`, `portal/router.js` | sí | **no** (v4) | **Reemplazar** (junto con compat) |
| `vuex` | 3.6.2 | 190 archivos | sí | v4 / Pinia | Actualizar tras arrancar Vue 3 |
| `vue-i18n` | 8.28.2 | `main.js`, `login.js`, `customer-display.js`, loader | sí | v9 (legacy API mantiene `$t`) | Actualizar (sin `$tc/$d/$n`, migración simple) |
| `bootstrap-vue` | 2.23.1 | 198 archivos | sí, hoy | **no** (proyecto sin soporte Vue 3) | **Eliminar** por olas |
| `bootstrap` | 4.6.2 (+ fork en `assets/styles/vendor`) | SCSS, 32 vistas Blade | sí (CSS) | agnóstico | Conservar hasta decidir BS5 (sección 8/16) |
| `bootstrap-icons` | 1.13.1 | 1 `.vue`, 20 Blade | sí | agnóstico | Conservar |
| `lucide-vue` | 0.517.0 | `LucideIcon.vue` (279 archivos lo usan por wrapper) | sí | **no** → `lucide-vue-next` | Reemplazar (cambio en un solo archivo) |
| `vee-validate` | 3.4.15 | `main.js`, `login.js` (globales; 123 archivos) | sí | **no** (v4 sin Provider/Observer) | **Reemplazar** con capa propia |
| `@vee-validate/i18n` | 4.15.1 | **ninguno** | **no** | v4 | **Eliminar** |
| `vue-good-table` | 2.21.11 | 73 archivos (86 tags) | sí | no (`vue-good-table-next`) | Reemplazar por `PxTable` |
| `vue-select` | 3.20.4 | `main.js` global; 76 archivos (263 tags) | sí | no (v4 beta) | Reemplazar por `PxSelect`/combobox |
| `vue2-daterange-picker` | 0.6.8 | 33 archivos | sí | no | **Reemplazar** (`PxDatePicker`) |
| `vuejs-datepicker` | 1.6.2 | 4 archivos (HRM) | sí | no | Reemplazar |
| `@pencilpix/vue2-clock-picker` | 0.1.6 | 2 (HRM) | sí | no | Reemplazar |
| `vuedraggable` | 2.24.3 | 5 archivos | sí | no (v4/`next`) | Actualizar |
| `@johmun/vue-tags-input` | 2.1.0 | 2 (productos) | sí | no | Reemplazar |
| `vue-apexcharts` | 1.7.0 | 24 archivos (48 tags) | sí | no (`vue3-apexcharts`); montó bien en spike | Actualizar |
| `apexcharts` | 5.3.5 | vía vue-apexcharts; 2 vistas Blade Super Admin | sí | agnóstico | Conservar |
| `echarts` + `echarts-gl` | 5.6.0 / 2.0.9 | `Sales3DDashboard.vue` (import dinámico, chunk de 1.6 MB) | sí (1 vista) | agnóstico | Conservar; evaluar retirar |
| `vue-echarts` | 6.7.3 | **ninguno** | **no** | v7 | **Eliminar** |
| `vue-barcode` | 1.3.0 | 7 archivos (POS, producto) | sí | no | Reemplazar (`@chenfengyuan/vue-barcode`/JsBarcode) |
| `vue-easy-print` | 0.0.8 | 3 archivos (POS, ventas) | sí | no | Reemplazar |
| `vue-html-to-paper` | 1.4.5 | plugin global; 6 archivos | sí | no | Reemplazar (ventana + `window.print`) |
| `@trevoreyre/autocomplete-vue` | 2.4.1 | `main.js` (import CSS); 1 tag en uso | mínimo | verificar | Evaluar |
| `vue-clickaway` | 2.2.2 | `TopNav.vue` (layout legacy) | mínimo | no | Eliminar con el layout legacy |
| `vue-perfect-scrollbar` | 0.2.1 | `customizer`, `TopNav` (import dinámico global) | mínimo | no | Reemplazar o CSS nativo |
| `vue-meta` | 2.4.0 | 300 vistas (`metaInfo`) | sí | no estable | Reemplazar por composable propio o `@unhead/vue` |
| `vue-sweetalert2` + `sweetalert2` | 5.0.11 / 11.4.4 (`sweetalert2` no figura en `package.json`: llega como dependencia transitiva y se importa directo en `plugins/sweetalert2.js`) | `$swal` en 95 archivos | sí | plugin no instala `$swal` bajo compat (spike) | Envolver en `PxConfirm` |
| `vue-cookie` / `vue-cookies` / `vue-localstorage` | 1.1.4 / 1.8.6 / 0.6.2 | `main.js`, `auth/*`, `store/modules/language.js` | mínimo | no / parcial | **Eliminar** (API nativa `document.cookie`/`localStorage`) |
| `vue-lazyload`, `vue-grid-layout`, `vue-navigation-bar`, `vue-simple-spinner` | 1.3.5 / 2.4.0 / 4.1.0 / 1.2.10 | **ninguno** | **no** | no | **Eliminar** |
| `mobile-device-detect` | 0.4.3 | 3 (sidebar/nav legacy) | mínimo | agnóstico | Eliminar con el layout legacy |
| `nprogress` | 0.2.0 | router + CSS | sí | agnóstico | Conservar |
| `moment` + `moment-locales-webpack-plugin` | 2.30.1 / 1.2.0 | 46 archivos; plugin sin opciones (**inferido:** recorta todos los locales salvo `en`; verificar fechas en `es`/`ar`) | sí | agnóstico | Conservar; planificar `Intl`/`date-fns` después |
| `jspdf` + `jspdf-autotable` | 3.0.3 / 5.0.2 | **62 archivos importan directamente**; 81 chunks lo incluyen duplicado | sí | agnóstico | Conservar; centralizar en un servicio con import dinámico |
| `xlsx` (SheetJS, tarball CDN) | 0.20.3 | 3 archivos | sí | agnóstico | Conservar (nota: instala desde `cdn.sheetjs.com`) |
| `quill` | 2.0.3 | `RichTextEditor.vue` | sí | agnóstico | Conservar |
| `dompurify` | 3.3.1 | 1 archivo | sí | agnóstico | Conservar |
| `@stripe/stripe-js` | 1.54.2 | `ModernPaymentModal`, `pos`, `old_pos` | sí | agnóstico | Conservar (el spec `^1.20.3` se resolvió a 1.54.2) |
| `axios` | 1.12.2 | 17 archivos + `window.axios` | sí | agnóstico | Conservar |
| `alpinejs` + `@alpinejs/*` | 3.15.11 | `storefront.js` | sí | agnóstico | Conservar |
| `laravel-mix` | 6.0.49 | build | sí | soporta Vue 3 | Conservar hasta Vite |
| `sass` + `sass-loader` | 1.93.2 / 8.0.2 | build | sí | — | Actualizar: 234 avisos `legacy-js-api` (Dart Sass 2.0 lo elimina) |
| `tailwindcss` (+3 plugins) `autoprefixer` `postcss` | 3.4.19 | solo `storefront.css` | sí | agnóstico | Conservar |
| `babel-polyfill`, `es6-promise`, `lodash.orderby`, `targets-webpack-plugin` | — | **ninguno** | **no** | — | **Eliminar** |
| `cross-env`, `copy-webpack-plugin`, `mini-css-extract-plugin`, `postcss-loader`, `resolve-url-loader`, `webpack-cli`, `clean-webpack-plugin` | — | redundantes con Mix o solo scripts (`clean-webpack-plugin` sí se usa en `webpack.mix.js`) | revisar | — | Verificar contra Mix y quitar los redundantes |
| `core-js` | 3.48.0 | dependencia de Mix/Babel | sí | agnóstico | Conservar |
| `webpack-bundle-analyzer`, `webpack-i18n-extractor-plugin` | — | sin script que los invoque | no | — | Eliminar o documentar |
| `eslint-plugin-vue` 6, `babel-eslint`, `@vue/eslint-config-prettier` | — | **no hay ESLint instalado ni configuración** | no (hoy) | v6 = Vue 2 | Reemplazar por ESLint 9 + `eslint-plugin-vue` 9 |

**Candidatas a eliminar sin riesgo funcional (0 usos verificados en `resources/src`, Blade y `webpack.mix.js`):** `@vee-validate/i18n`, `vue-echarts`, `vue-lazyload`, `vue-grid-layout`, `vue-navigation-bar`, `vue-simple-spinner`, `lodash.orderby`, `babel-polyfill`, `es6-promise`, `targets-webpack-plugin`. Cada retiro requiere `npm ci` + build antes de fusionar (fase 2, no esta auditoría).

Otros hallazgos:
- `xlsx` se instala desde `https://cdn.sheetjs.com/...tgz`: `npm ci` depende de un host externo.
- Vendorizados sin `package.json`: `public/assets_setup/js/qrcode.js` (html5-qrcode), `public/assets_setup/js/jquery*.js`, `qrcodejs` por CDN sin SRI.

---

## 8. Build

Ejecutado en copia aislada, Node 22.23.2 local (CI usa Node 20 con `--max-old-space-size=8192`):

| Comando | Resultado | Tiempo |
|---|---|---|
| `npx mix` (development) | **OK**, "webpack compiled successfully" | 72–78 s (1:12–1:18) |
| `npx mix --production` | **OK**, "webpack compiled successfully", sin `NODE_OPTIONS` | 3 min 47 s (CPU 651 %) |

Avisos: 234 (dev) / 218 (prod) `DEPRECATION WARNING [legacy-js-api]` de Dart Sass. Cero errores. Los `@import`/`slash-div`/`color-functions` están silenciados en `webpack.mix.js`.

Salida: `main.min.js` 6.16 MB dev / 2.27 MB prod; 429 chunks dev (202 MB) / 652 chunks prod (77 MB). 73 chunks > 500 KB. **81 chunks incluyen `jsPDF`, 24 `ApexCharts`, 88 `vue-good-table`**: no hay extracción de vendors compartidos (`mix.extract()` no se usa), así que cada informe pesa ≈ 1.2 MB.

Piezas relevantes:

- **Laravel Mix 6.0.49 sobre webpack 5.105.3.** `mix.vue()` detecta la versión de Vue instalada; con Vue 3 usaría `vue-loader` 16/17. Para `@vue/compat` hay que añadir alias (`vue → @vue/compat`) y `compilerOptions.compatConfig`.
- **Babel:** sin `.babelrc` ni `browserslist`; se usa el preset por defecto de Mix.
- **Loaders:** `sass-loader` 8 con regla extra en `webpack.mix.js` (API legacy).
- **Alias:** solo `@ → resources/src`.
- **Imports dinámicos:** 462 con `webpackChunkName` (magic comments; Vite ignora los nombres).
- **`chunkFilename: js/bundle/[name].[hash].js`:** `[hash]` es de la compilación completa. Cualquier cambio renombra los 652 chunks; combinado con `CleanWebpackPlugin` (vacía `public/js/*` antes de compilar) hay ventana de 404 en despliegues con pestañas abiertas.
- **PostCSS/Tailwind:** solo para `resources/css/storefront.css`. El admin no usa Tailwind (`tailwind.config.js` limita `content`).
- **`process.env.NODE_ENV`:** 1 uso (rutas solo desarrollo del router). Vite lo soporta como `import.meta.env`.
- **`require()`:** 7 usos en 3 entrypoints (CJS).
- **SCSS:** 344 `@import` (0 `@use`); 15 con prefijo `~` de webpack. Vite necesita alias o reescritura; Sass moderno los está deprecando.
- **`moment-locales-webpack-plugin`:** plugin de webpack sin equivalente Vite (habría que resolverlo con `resolve.alias` o migrar a `Intl`).
- **PWA/service worker:** `public/sw.js` es un archivo estático fuera del build (versión `prodex-pwa-v7`, `KILL_SWITCH`). Los `?v={{ time() }}` de Blade neutralizan el caché de los scripts propios.
- **Copias de assets:** `webpack.mix.js` copia 23 scripts de `resources/static` a `public/js` y las fuentes IBM Plex a `public/js/bundle/fonts/px-next` (URL absoluta que el deploy ya espeja al VPS).
- **Artefactos versionados en git:** `public/js/main.min.js` (6 MB), `login/portal/customer-display/storefront.min.js`, `prodex-sidebar2-organizer.js`, `public/mix-manifest.json`, `public/css/*`. Los builds locales ensucian el árbol (hoy varios ya aparecen modificados por builds previos: `main/login/portal.min.js`, `mix-manifest.json`).
- **Salida como script clásico:** Blade carga `/js/main.min.js` sin `type="module"`. Vite debe emitir un bundle equivalente (IIFE/`es` con `nomodule`) manteniendo nombres y rutas.

**Qué impediría migrar a Vite (a Vue 2.7 o Vue 3):**

1. Contrato de salida: nombres fijos `public/js/{main,login,portal,customer-display,storefront}.min.js`, chunks en `public/js/bundle/`, carga como script clásico desde Blade, y despliegue que espeja `public/js`.
2. `require()` en entrypoints y `~` en 15 imports SCSS.
3. `moment-locales-webpack-plugin`, `CleanWebpackPlugin`, `webpackChunkName`.
4. 23 scripts que se copian (no pasan por bundler): hay que mantener su copia o incluirlos como entradas.
5. Tests PHP que leen archivos fuente por texto (44 archivos): un cambio de sintaxis los rompe aunque el producto funcione.
6. Cache-busting con `time()` y `[hash]` global: cambia el modelo de invalidación.
7. `@vitejs/plugin-vue2` soporta Vue 2.7, así que Vite **podría** hacerse antes que Vue 3; se recomienda después (sección 16) para no mover dos ejes a la vez.

**Preexistente (no se arregló):** avisos de Sass legacy; 8 tests PHP fallan (sección 14).

---

## 9. Sistema de diseño

| Métrica | Valor |
|---|---|
| `--pxn-*` (px-next) | 10 455 usos en 233 archivos |
| `--px-*` (capa `prodex/`) | 458 usos en 75 archivos |
| `--primary-color` / `-darker` / `-soft` | 47 / 4 / 9 usos (12 / 4 / 4 archivos); definidos en tiempo de ejecución por `store/modules/config.js:applyPrimaryColor` |
| Escala de z-index `--px-z-*` | definida en `prodex/_tokens.scss` (base 1 → tooltip 1080, overlay 2100/2200); solo **24** usos de `var(--px-z-*)` frente a **153** `z-index:` literales en `.vue` y 52 en SCSS (top: `1`×72, `2055`×27, `2`×22, `10`×11, `9999`×10) |
| Colores hex en `.vue` | 5 194 en 178 archivos; 663 en SCSS propio |
| `!important` | 1 509 en `.vue`, 1 252 en SCSS propio |
| Tema oscuro | `.dark-theme`: 320 referencias en `.vue` (17 archivos) más `themes/dark-purple.scss`, `themes/dark/_dark.scss` |
| Selectores profundos | `::v-deep` 304, `>>>` 69, `/deep/` 5, `:deep()` 66 |
| RTL | `bootstrap-rtl.scss` propio (`[dir="rtl"]`) + 52 menciones en SCSS + 274 en JS/Vue (48 archivos); el layout decide `dir` con `themeMode.rtl` (`App.vue` → `metaInfo.htmlAttrs.dir`) |

**Dos capas de tokens en paralelo:** `PRODUCT.md` describe `--px-*`; el código nuevo usa `--pxn-*`. La modernización debe fijar cuál es la canónica, o mantener un mapeo.

**Branding por tenant (crítico):** `applyPrimaryColor()` inyecta un `<style>` con `--primary-color*` y **selectores Bootstrap 4 explícitos con `!important`**: `.btn-primary`, `.form-control:focus`, `.custom-control-input:checked ~ .custom-control-label::before`, `.custom-select:focus`, `.page-link`, `.nav-pills`, `.nav-tabs`, `.dropdown-item.active`, `.progress-bar`, `.badge-primary`… El color se guarda en `localStorage.primaryColor` (por origen, o sea por subdominio/dominio propio). Cualquier cambio de Bootstrap (5) o el retiro de `custom-control`/`page-link` exige reescribir este inyector; pasar a Vue 3 no lo afecta.

**Acoplamiento a Bootstrap CSS:** aunque se elimine BootstrapVue, quedan clases BS4 usadas como CSS puro: utilidades `ml-/mr-/pl-/pr-` (179 archivos), `text-left/right` (107), `font-weight-*` (90), `badge-*` (34), `form-group` (114), `input-group-append/prepend` (24), `custom-*` (7), grid `col-md-*` (24 con clases literales), `d-flex/d-none…` (100). BS5 renombra o elimina varias (`ml→ms`, `text-left→text-start`, `font-weight→fw`, `form-group`, `input-group-append`, `custom-*`). Por eso Bootstrap 5 es un proyecto aparte y **no bloquea Vue 3** (BS4 es solo CSS, sin jQuery ni Popper en el SPA).

**Conclusión:** PRODEX es muy desacoplable de BootstrapVue (componentes) y moderadamente acoplable a Bootstrap (CSS). Se puede mantener el diseño visual actual durante toda la migración de framework.

---

## 10. I18N

| Métrica | Valor |
|---|---|
| Idiomas con archivos en `resources/lang` | 10: `ar, bn, de, en, es, fr, hi, pt, tr, ur` (RTL: `ar`, `ur`) |
| Origen de las traducciones del SPA | **Tabla `translations` de la BD del tenant**, servida por `GET /api/translations/{locale}`; no se empaquetan en el bundle |
| Fallbacks en el bundle | `plugins/ui.fallback.i18n.js` (441 líneas) y `plugins/support.i18n.js` (214) |
| `$t()` | 15 679 llamadas / 317 archivos / 3 087 claves estáticas |
| `$tc`, `$d`, `$n` | **0** (sin pluralización ni formato de fecha/número de vue-i18n) |
| `this.$i18n` | 97 usos en 48 archivos |
| Fechas | `moment` en 46 archivos; `Intl.DateTimeFormat` ×12; `toLocaleDateString` ×11; formato de fecha configurable por tenant (`date_format` en `auth`) |
| Moneda/números | `toLocaleString` ×149, `Intl.NumberFormat` ×39, `utils/priceFormat.js` (formatos `comma_dot`, `dot_comma`, `space_comma`, decimales 2–3) |
| Textos fijos sin `$t` (heurístico) | ≈ 2 865 nodos de texto en 212 `.vue`, más ≈ 1 676 atributos (`placeholder/title/label/aria-label`) |
| "Guards" de español | 8 archivos `utils/spanish*Guard.js` (varios con `MutationObserver`; el de título se limita a `<title>`); los observers globales antiguos están desactivados permanentemente en `i18n.loader.js` |
| Locale en la app | `App.vue` fija `htmlAttrs.lang = 'es'` y `dir` por `themeMode.rtl`; `X-Pdf-Locale` viaja en cada request desde `localStorage.language` |

Compatibilidad: **vue-i18n 8 funciona bajo `@vue/compat` (probado)** incluyendo `setLocaleMessage` y cambio de locale. vue-i18n 9 en modo `legacy: true` conserva `$t`, `$i18n` y el uso actual, así que la migración es de bajo riesgo porque no hay `$tc/$d/$n` ni plurales. Puntos de cuidado: (a) el mensaje faltante usa `missing` + `readableMissingTranslation`; (b) formato con `{n}`/`@:` debe verificarse; (c) `moment-locales-webpack-plugin` sin opciones puede estar recortando locales de fechas (verificar antes de tocar).

**Condición de aceptación:** ninguna fase puede fusionarse si rompe un idioma o RTL. Se propone como gate: captura de las 12 pantallas críticas en `es`, `en` y `ar` (RTL) antes/después de cada fase (sección 14).

---

## 11. POS y zona de alto riesgo

`pos.vue` (19 318 LOC, 222 métodos, 66 computed, 127 `b-*`, 17 eventos `Fire`, 21 `$refs`) concentra caja, catálogo, borradores, pagos, lotes/series, impresión y sincronización offline. Su plantilla mide solo 1 160 líneas; el 57 % del archivo es CSS (11 117).

| Dependencia | Dónde vive | Riesgo en migración |
|---|---|---|
| Atajos de teclado | `mixins/posKeyboardShortcuts.js` (501 líneas) + 61 archivos con `keydown`/scanner | Alto: dependen de `$refs`, foco y `keydown` global |
| Código de barras / escáner | `vue-barcode` (7 archivos), `qrcode-scanner` global (23 tags), `Html5QrcodeScanner` externo | Medio: librería Vue 2 sin equivalente directo |
| Caja / cierres / denominaciones | `cash_register` (9 archivos), `cash_drawer` (20), denominaciones (2), `prodex-pos-optional-cash-drawer.js`, `prodex-cash-register-report-ui.js` | Alto: dinero |
| Inventario, lotes, series | `serial`/`batch` en 55/75 archivos, `SerialNumbersField`, `prodex-pos-location-*.js`, `utils/inventoryLocationAutoSelect.js`, `posOperationalLocationBridge.js` (425 líneas, parchea axios) | Alto |
| Offline | `utils/globalOfflineSync.js` (242), `utils/index.js` (680), cola `pos_offline_sales_v1` en `localStorage` (168 usos de `localStorage` en 39 archivos), `prodex-pos-location-offline.js` | **Muy alto**: bug = ventas perdidas o duplicadas |
| Service worker | `public/sw.js`; reglas: solo GET, `/api/*` y login nunca cacheados, `KILL_SWITCH` | Medio: no depende de Vue |
| Impresión / ESC-POS / QZ Tray | `utils/cashDrawerQz.js`, `vue-easy-print`, `vue-html-to-paper` (globales), `window.print` (33 archivos) | Alto: librerías Vue 2 sin mantenimiento |
| Customer display | entrypoint propio `customer-display.min.js` (Vue 2 + i18n); vista `CustomerDisplay.vue`; `cd_token_data`, `pos_customer_display_screen_id` | Bajo-medio (aislado, sin router ni store) |
| Kitchen display | 9 archivos, `KitchenDisplay` chunk | Bajo-medio |
| Pagos con tarjeta | `ModernPaymentModal.vue` (2 627 LOC, Stripe, 12 `$refs`) | Alto |
| Scripts sueltos | `prodex-pos-location-ui.js` (611), `-catalog`, `-offline`, `-delta-safety`, `-operational-lock`, `-optional-cash-drawer` (leen/parchean el DOM del POS) | Alto: dependen del marcado actual |
| Bloqueo operativo / SAR | tests `PosArtifactPreflightTest`, `PosNativeSaleIntegrityArchitectureTest`, `PosAwareSarFiscalSaleArchitectureTest`, etc. | La lógica está protegida en PHP; la UI no |

**Qué migrar al final:** (1) la interfaz de `pos.vue` y `ModernPaymentModal.vue`, (2) impresión y cajón (`QZ`, `vue-easy-print`), (3) cola offline. Antes de eso hay que extraer la **lógica pura** (cálculo de totales/descuentos, cola offline, resolución de ubicación) a módulos sin Vue con tests (fase 3), de modo que el cambio de framework no toque reglas de dinero.

`old_pos.vue` (10 685 LOC) no tiene referencias: confirmar y **borrar antes de migrar** elimina 10 685 líneas y ≈ 100 usos de Vue 2/BootstrapVue.

---

## 12. Componentes monolíticos (no refactorizar todavía)

| Archivo | LOC | Responsabilidades | Métodos / computed / watch | API directa | Riesgo |
|---|---|---|---|---|---|
| `pages/pos.vue` | 19 318 | Venta, caja, borradores, pagos, series/lotes, offline, impresión, atajos, cliente, snapshot | 222 / 66 / 3 | 19 | **10** |
| `pages/old_pos.vue` | 10 685 | Copia previa del POS | 144 / 30 / 1 | 16 | 0 si se confirma muerto |
| `settings/system_settings.vue` | 7 159 | Ajustes generales, POS, pagos, correo, apariencia (640 `b-*`) | 70 / 15 / 3 | 3 | 7 |
| `products/Add_product.vue` | 4 588 | Alta de producto (variantes, series, imágenes) | 46 / 4 / 7 | 7 | 5 (ruta `classic`) |
| `products/Edit_product.vue` | 4 079 | Edición equivalente | 40 / 10 / 2 | 1 | 5 (ruta `classic`) |
| `sales/index_sale.vue` | 3 403 | Listado, pagos, devoluciones, impresión, SAR | 75 / 11 / 2 | 1 | 8 (22 imports) |
| `sales/create_sale.vue` | 3 058 | Venta manual (redirigida al POS) | 68 / 10 / 4 | 3 | 2 (inalcanzable por guard) |
| `dashboard/dashboard.vue` | 2 898 | Dashboard legacy | 15 / 11 / 1 | 0 | 3 (`dashboard-legacy`) |
| `largeSidebar/Sidebar.vue` | 2 780 | Menú legacy, 209 `router-link tag` | 8 / 2 / 0 | 0 | 6 (bloquea Router 4) |
| `components/ModernPaymentModal.vue` | 2 627 | Cobro con Stripe/efectivo/tarjeta | 38 / 13 / 0 | 4 | 9 |
| `largeSidebar/VerticalSidebar.vue` | 2 549 | Menú vertical + scripts externos | 12 / 5 / 1 | 0 | 6 |
| `reports/AI_Reports.vue` | 2 537 | Informes IA, funcional `render(h)` | 17 / 24 / 0 | 0 | 4 |
| `people/customers.vue` | 2 471 | CRUD clientes, importación | 51 / 5 / 0 | 4 | 6 |
| `sales/edit_sale.vue` | 2 207 | Edición de venta | 52 / 7 / 1 | 1 | 7 |
| `purchases/create_purchase.vue` | 1 652 | Compras con lotes/series/ubicación | 51 / 8 / 0 | 1 | 8 |
| `purchases/index_purchase.vue` | 1 550 | Listado y acciones de compras | 51 / 5 / 0 | 0 | 6 |

"API directa" cuenta llamadas `axios.*` en el archivo; el conteo real de endpoints es mayor porque hay wrappers. Duplicación: `tasks/index_task.vue` y `projects/index_project.vue` miden 1 984 y 1 983 líneas con la misma estructura.

---

## 13. Capa `Px*`: qué abstraer primero

Ya existen 26 componentes en `resources/src/components/px-next/` (no se registran globalmente; se importan por vista). El mapa de brechas usa los usos reales:

| Componente | BootstrapVue actual (etiquetas / archivos) | `px-next` hoy (usos) | Dificultad 1-10 | Prioridad | Estado |
|---|---|---|---|---|---|
| **PxToast** (servicio) | `$bvToast` 277 llamadas / 249 archivos | `PxToast.vue` existe, 1 uso; **sin API imperativa** | 4 | **P0** | Falta el servicio (`this.$px.toast`) y el codemod |
| **PxConfirm** | `$swal` 369 / 95 + `$bvModal.msgBoxConfirm` 11 | no existe | 3 | **P0** | Envolver SweetAlert2 (mantiene el aspecto y evita el fallo de `$swal` bajo compat) |
| PxModal | `b-modal` 112 / 53; `$bvModal.show/hide` 236 | 129 usos | 5 | P1 | Falta API imperativa por id y `v-model` compatible Vue 3 |
| PxSelect | `b-form-select` 95 / 44 + `v-select` (vue-select) 263 / 76 | 58 usos | **6** | P1 | Verificar paridad con `vue-select` (búsqueda, múltiple, tags, remoto) |
| PxTable | `vue-good-table` 86 / 73 + `b-table` 19 / 10 | 136 usos | **7** | P1 | Verificar orden/paginación en servidor, selección, slots de fila |
| **PxDatePicker** (+ rango) | `b-form-datepicker` 6 / 4 + `vue2-daterange-picker` 33 / 33 + `vuejs-datepicker` 4 | no existe | **7** | P1 | Bloquea retirar 3 librerías Vue 2; debe respetar formato del tenant y RTL |
| PxTooltip (directiva) | `v-b-tooltip` 116 / ~40 (+ `v-b-toggle` 12, `v-b-popover` 1) | no existe | 3 | P1 | Los hooks de directiva cambian en Vue 3 |
| PxInput / PxField | `b-form-input` 675 / 103; `b-form-group` 1 079 / 110; `b-form-invalid-feedback` 384 | `PxInput` 538; `PxField` 813 | 3 | P1 | Ya cubierto; falta cerrar validación (sección 3, vee-validate) |
| PxButton | `b-button` 603 / 143 | 990 usos | 2 | P2 | Cubierto |
| PxCard | `b-card` 330 / 131 | 256 usos | 2 | P2 | Cubierto |
| PxBadge | `b-badge` 89 / 41 | 234 usos | 1 | P2 | Cubierto |
| PxAlert | `b-alert` 51 / 27 | 145 usos | 2 | P2 | Cubierto |
| PxPagination | `b-pagination` 8 / 5 | 115 usos | 2 | P2 | Cubierto |
| PxDropdown (= `PxMenu`/`PxKebab`) | `b-dropdown` 20 / 8 | 61 + 48 usos | 3 | P2 | Cubierto; retirar 12 escuchas `bv::dropdown::*` |
| PxTabs | `b-tabs`/`b-tab` 11 + 42 / 11 | 9 usos | 3 | P2 | Cubierto en parte |
| PxCheck / PxTextarea | `b-form-checkbox` 66, `b-form-textarea` 48 | 77 / 67 | 2 | P2 | Cubierto |
| **PxGrid** (`b-row`/`b-col`) | 343 + 1 418 | — | 2 | P1 | Codemod a clases `row`/`col-*`; no requiere componente |
| **PxFileInput** | `b-form-file` 18 / 15 | — | 4 | P2 | Falta |
| **PxInputGroup** | `b-input-group` 64 (+39 append/prepend) | — | 3 | P2 | Falta o usar slots de `PxInput` |

Prioridad recomendada de construcción: **PxToast servicio → PxConfirm → PxModal imperativo → PxTooltip → PxDatePicker → paridad de `PxSelect`/`PxTable` → PxFileInput/PxInputGroup**. Los dos primeros eliminan ≈ 600 llamadas con codemods mecánicos y no tocan plantillas.

Riesgo conocido: `PxField` no siempre detecta el `<input>` dentro de un slot con `ValidationProvider`; ya hay un parche (`provider.validate(value)`). Esto conecta con el bloqueo de vee-validate.

---

## 14. Pruebas: qué existe y qué falta

### Baseline ejecutado (árbol de trabajo tal como estaba)

| Comando | Resultado |
|---|---|
| `vendor/bin/phpunit --testsuite Unit` | **1 321 tests, 6 889 aserciones: 8 fallos**, 3 advertencias, 1 deprecación (1 min 26 s) |
| `vendor/bin/phpunit --testsuite Feature` | **934 tests, 3 595 aserciones: OK**, 3 omitidos (1 min 10 s) |
| `npx mix` (dev) | OK |
| `npx mix --production` | OK |
| Lint | **No existe** (no hay `eslint` instalado, ni configuración, ni script `lint`) |
| Tests frontend | **No existen** (ni Jest, Vitest, Cypress ni Playwright versionados) |

**Fallos preexistentes** (no se tocaron; son anteriores a esta auditoría, contra un árbol de trabajo con ~636 archivos modificados; no se verificaron contra un checkout limpio de `HEAD`):

1. `PosDraftLocationAuthorizationArchitectureTest::test_create_draft_keeps_legacy_warehouse_requests_unchanged`
2. `PosNativeSaleIntegrityArchitectureTest::test_legitimate_line_discounts_are_preserved_but_minimum_price_is_enforced`
3. `PosSaleWarehouseInvariantArchitectureTest::test_location_native_pos_sale_cannot_be_rewritten_with_synthetic_warehouse_id`
4. `PosSalesWarehouseNullableArchitectureTest::test_sale_model_keeps_warehouse_as_legacy_compatibility_pointer`
5. `SalesLocationNativeArchitectureTest::test_sale_return_location_fallback_never_overrides_explicit_selection`
6. `ShellDefaultLayoutArchitectureTest::test_excluded_fullscreen_routes_are_unchanged`
7. `ShellDomainCoverageArchitectureTest::test_every_admin_app_route_family_is_classified`
8. `TenantSchemaHealthServiceTest::test_it_reports_no_missing_requirements_when_modern_schema_exists` (reporta 46 requisitos de esquema faltantes)

Los tests 6 y 7 leen `router.js`/`PxShell.vue`: son un primer aviso de que las pruebas de arquitectura y el frontend ya están desalineados.

### Qué protege hoy al frontend

- **44 archivos de test PHP leen código fuente del frontend como texto** (246 referencias a `.vue`/`.js`; los más citados: `views/app/_ui/data/shell-nav.js` ×11, `router.js` ×9, `PxShell.vue` ×8, `main.js` ×7, `import_purchases.vue` ×6). Son 56 tests `*ArchitectureTest`. Funcionan como "red de seguridad" de contenido, no de comportamiento, y **se romperán con cualquier cambio de sintaxis** (por ejemplo `slot-scope` → `v-slot`) aunque el producto siga bien. Habrá que actualizarlos junto con cada fase.
- CI (`.github/workflows/validate*.yml`, 7 workflows): PHPUnit selectivo por dominio, `node --check` de varios scripts sueltos, build de producción (Node 20, 8 GB) y una aserción de que `_ui`/playground no se filtran al bundle.
- Documentado en PHP: la lógica de servidor de POS, SAR, transferencias e inventario está bien cubierta.

### Cobertura por área (archivos de test PHP cuyo nombre lo indica; no mide UI)

| Área | Tests PHP (archivos) | Cobertura de UI |
|---|---|---|
| Login | 3 (`TenantLogin*`) + `MobileAuthBackendTest` | Ninguna (`login.min.js` sin pruebas) |
| Dashboard | 5 (`DashboardScope*`, `OperationalDashboardCoherenceTest`) | Ninguna |
| Productos | 5 | Ninguna |
| Compras | 33 (golden masters legacy, lotes, series, ubicación) | Solo por regex sobre `.vue` |
| Ventas | 17 (golden masters de serie/devolución) | Regex |
| Inventario | 20 | Regex |
| Transferencias | 21 | Regex + 12 `node --check` |
| POS | 25 | Regex + `node --check`; **sin prueba del flujo real** |
| Caja | 13 | Ninguna |
| SAR | 11 | Regex sobre `sar_fiscal.vue` |
| Permisos | `AccessControlSecurityArchitectureTest`, `TransferCreatePermissionTest`, `PxShellRestrictedUserProbesTest` | Ninguna |
| Configuración | `SarInvoiceSettingsTest`; casi nada para 40 pantallas de ajustes | Ninguna |

### Qué falta antes de empezar (red de seguridad mínima)

1. **E2E con Playwright** contra el tenant demo: login → dashboard → lista de productos → compra → venta por POS (incluye cola offline) → apertura y cierre de caja → recepción de transferencia → configuración SAR → usuario con permisos restringidos. No hay ejecución local de la app documentada en este árbol; el servidor de demo (`demo01:8001`) no estaba activo al auditar.
2. **Snapshot del mapa de rutas** (467 registros) y de permisos por ruta, para detectar rutas perdidas.
3. **Presupuesto de consola:** cero errores de consola nuevos por pantalla (línea base conocida: 3 × 403 de transfer-logistics y un aviso de icono).
4. **Capturas es/en/ar (RTL)** de las 12 pantallas críticas.
5. **Lint de deprecaciones Vue 3** (ESLint 9 + `eslint-plugin-vue` 9 con reglas `no-deprecated-*`), en modo informe primero y "trinquete" (el conteo solo baja) después.
6. **Vitest** para lógica pura (`priceFormat`, `posKeyboardShortcuts`, `globalOfflineSync`, `inventoryLocationAutoSelect`, `utils/index.js`).
7. Reparar o aislar los 8 tests rojos, para que un rojo nuevo sea una señal.

---

## 15. `@vue/compat`: viabilidad en PRODEX

### Método

En un directorio temporal **fuera del repo** se instalaron exactamente las versiones de PRODEX contra `@vue/compat` 3.5.13 (vía alias `vue@npm:@vue/compat`), se montó en jsdom con `new Vue({...})` (la forma en que arranca `main.js`) y `configureCompat({ MODE: 2 })`, y se ejercitó el subconjunto de APIs que PRODEX usa. Los tres scripts están en `audit-evidence/`. **No es una prueba de la aplicación real** (no se compiló PRODEX con `@vue/compat`; eso exigiría cambiar `package.json` y el build), es una prueba de compatibilidad de librerías y APIs.

Cómo repetirlo:

```bash
mkdir /tmp/compat-spike && cd /tmp/compat-spike && npm init -y
npm i --legacy-peer-deps "vue@npm:@vue/compat@3.5.13" bootstrap-vue@2.23.1 jsdom@24 entities@4.5.0 \
  "vue-router3@npm:vue-router@3.6.5" "vuex3@npm:vuex@3.6.2" "vue-i18n8@npm:vue-i18n@8.28.2" \
  vee-validate@3.4.15 vue-good-table@2.21.11 vue-select@3.20.4 vue2-daterange-picker@0.6.8 \
  vuedraggable@2.24.3 vue-meta@2.4.0 vue-apexcharts@1.7.0 apexcharts@5.3.5 \
  vue-sweetalert2@5.0.11 sweetalert2@11 lucide-vue@0.517.0
node compat-spike-bootstrapvue.js && node compat-spike-failures.js && node compat-spike-router-vuex-i18n.js
```

### Resultados

**Funcionan bajo compat (con avisos de deprecación, sin errores):**

| Pieza | Prueba |
|---|---|
| BootstrapVue 2.23.1 | `b-button`, `b-card`, `b-row/col`, `b-badge`, `b-alert`; `b-form-group` + `b-form-input` con `v-model` de ida y vuelta y `:state`; `b-form-select`, `b-form-checkbox`, `b-form-textarea`; `b-modal` con `v-model` y con `$bvModal.show/hide`; `$root.$bvToast.toast`; `b-table`, `b-pagination`, `b-tabs`, `b-dropdown`; `b-form-file`, `b-form-datepicker`, `v-b-tooltip`; slots `#cell(a)` |
| vue-router 3.6.5 | `router-view`, modo history, `beforeEach` async con `next`, ruta con nombre y params, `addRoutes`, `*`, `push().catch` parcheado |
| Vuex 3.6.2 | módulo `namespaced`, `mapGetters`, `mapActions`, reactividad |
| vue-i18n 8.28.2 | `$t`, `setLocaleMessage`, cambio de `locale` |
| vue-meta 2.4.0 | `metaInfo.title` actualiza `document.title` |
| vue-select 3.20.4, vue2-daterange-picker 0.6.8, vuedraggable 2.24.3, lucide-vue 0.517 | montan y renderizan |
| vue-apexcharts 1.7.0 | monta (jsdom pide `window.Apex`/`ResizeObserver`; no es un veredicto sobre renderizado real) |
| API global | `Vue.prototype.$foo`, `Vue.mixin`, bus `new Vue()` con `$on/$emit/$off`, filtros, `$set`, `$forceUpdate`, `.sync`, `.native` |
| vue-good-table 2.21.11 | funciona con `v-slot:table-row` |

**Fallan bajo compat:**

| Fallo | Consecuencia en PRODEX | Alcance |
|---|---|---|
| `slot-scope` / `slot="x"` sobre componentes no se renderiza (afecta a `b-table` y `vue-good-table`) | Celdas y filas personalizadas desaparecen sin error | 207 + 154 ocurrencias en ~120 archivos. Se corrige con codemod a `v-slot`, que ya funciona en Vue 2.7 |
| `vee-validate` 3: `ValidationProvider` no detecta el `<input v-model>`; nunca marca error ni `invalid`. `provider.validate(valor)` explícito sí funciona | Formularios inválidos se envían sin validación de cliente | 590 providers / 162 observers en ~123 archivos |
| `router-link` de vue-router 3.6.5 no genera `<a>` (ni con `tag`) | Toda la navegación por enlaces deja de ser enlace | 645 usos |
| `vue-sweetalert2` no instala `$swal` con `Vue.use` | `this.$swal` es `undefined` | 369 usos / 95 archivos; se arregla con `Vue.prototype.$swal = Swal` o `PxConfirm` |
| `el.__vue__` es `undefined` | 4 scripts sueltos pierden el acceso a la instancia Vue | 7 líneas |

**No probado (queda para el primer spike sobre el código real):** compilación de PRODEX con `vue-loader` 17 dentro de Laravel Mix, `vue-easy-print`, `vue-html-to-paper`, `vue-barcode`, `vue-tags-input`, `vue2-clock-picker`, `vuejs-datepicker`, `autocomplete-vue`, `vue-perfect-scrollbar`, renderizado real de ApexCharts, rendimiento y tamaño de bundle bajo compat (el runtime compat es más grande), comportamiento de `keydown`/foco del POS, y Vuex/i18n con la app completa.

### Bloqueadores, en orden

1. **Router:** vue-router 3 rompe `router-link`; hay que subir a Router 4 **en el mismo hito** que Vue 3. Router 4 exige quitar 211 `tag/event/exact` de `router-link`, reescribir `addRoutes`, `*`, `scrollBehavior` y el parche de `push`. (El orden pedido colocaba Router después de compat; la evidencia dice que van juntos.)
2. **Sintaxis de slots:** codemod previo a `v-slot` (bloqueo silencioso).
3. **vee-validate 3:** decisión previa. Recomendación: capa propia (`PxValidation`) con la misma API de slots (`errors`, `invalid`, `validate`) que no dependa de leer vnodes, usando el núcleo `validate()` de vee-validate 4 o reglas propias; se puede construir y migrar sobre Vue 2.7.
4. **Scripts sueltos con `__vue__`:** sustituir por eventos/estado explícitos.
5. **BootstrapVue:** funciona en compat en el subconjunto usado, pero **no está soportado**; tratarlo como puente con fecha de retiro. Riesgo: la ruta POS (127 `b-*`, 53 modales) correría sobre una combinación no soportada.
6. **Compilador/build:** `vue-loader` 17 + `@vue/compiler-sfc`; alias `vue → @vue/compat`; `qrcode-scanner` con `template:` string requiere el build con compilador.
7. **Plugins globales:** `vue-sweetalert2` (shim), `vue-meta` (mantener bajo compat, luego reemplazar), `vue-good-table`, `vue-html-to-paper`.

### Veredicto

**Viable con condiciones; no como primer paso.** No hay bloqueadores estructurales que impidan arrancar PRODEX con compat, pero hay cuatro trabajos previos (codemod de slots, capa de validación, plan de Router 4 con reescritura de `router-link`, eliminación de `__vue__`) y un riesgo alto sin red de pruebas de frontend. La parte que asusta menos de lo esperado: Vuex 3, vue-i18n 8, BootstrapVue y la mayoría de las librerías de UI **arrancan y funcionan bajo compat en el spike**, lo que permite dejar el reemplazo de esas piezas para después del arranque. La parte más pesada de lo esperado: vee-validate y `router-link`, que fallan de un modo silencioso o total. El esfuerzo se mide por puntos de contacto, no por porcentaje: ≈ 360 ocurrencias de slots en ~120 archivos, ≈ 750 tags de validación en ~123 archivos, 645 `router-link`, 4 scripts sueltos, 1 build. Recomendación: **spike de código de 1–2 días con el build real (rama descartable) antes de comprometer la fase 5**, precedido por la red de seguridad de la fase 1.

---

## 16. Multitenancy: qué debe preservarse

- **Stancl Tenancy, una BD por tenant**; el mismo bundle sirve a todos. Rutas separadas: `routes/central*.php` (landing, checkout, Super Admin) frente a `routes/tenant*.php` (23 archivos), vistas `resources/views/central/` frente a la SPA.
- **Subdominios y dominios propios verificados (DNS + SSL):** el SPA no debe asumir origen; `localStorage` (`primaryColor`, `language`, cola offline) es por origen y así aísla tenants. El service worker se registra con `scope: '/'` por origen.
- **Datos que llegan por Blade:** `window.__planSummary` (plan y features, con `knowledge_base` forzado a `enabled`), `__appName`, `__uploadPath`, `__pageTitleSuffix`. El SPA tiene que seguir leyéndolos antes de arrancar.
- **Branding:** `--primary-color*`, logo, fuente y tema claro/oscuro por tenant en runtime; SweetAlert usa `var(--px-primary)`. Ver sección 9 (el inyector apunta a clases BS4).
- **Permisos:** 1 611 usos de `currentUserPermissions` (sin `meta` de ruta). El servidor sigue siendo la barrera definitiva (por ejemplo `SalesController@store` responde 403).
- **Planes/features:** middleware `tenant.feature:*`, `tenant.subscribed`, límites (`limit_reached` → overlay del plan en `App.vue`).
- **Aislamiento de datos:** las pruebas PHP de alcance por sucursal/almacén (`BranchInventoryScopeServiceTest`, `TransferWarehouseScopeMiddlewareTest`, etc.) deben seguir en verde en cada fase; el frontend no debe cachear respuestas `/api/*` (regla del service worker).
- **Multi-idioma por tenant:** traducciones en BD del tenant; el cambio de idioma sincroniza sesión (`POST sync-locale`).

Criterio de no regresión: probar cada fase con al menos un tenant en subdominio y otro en dominio propio, más el dominio central.

---

## 17. Bloqueadores (top 10)

| # | Bloqueador | Evidencia | Por qué bloquea |
|---|---|---|---|
| 1 | **vee-validate 3** sin equivalente en Vue 3 | 590 providers, 162 observers, ~123 archivos; fallo silencioso probado bajo compat | Se pierde validación de cliente sin aviso |
| 2 | **vue-router 3 → 4** | `router-link` no funciona bajo compat (645 usos); `addRoutes`; `*`; parche de `push`; 467 rutas | Debe ir con el arranque de Vue 3 |
| 3 | **Sintaxis vieja de slots** | 207 `slot-scope` + 154 `slot=` (~120 archivos); fallo silencioso probado | Deja celdas y filas de tabla vacías |
| 4 | **BootstrapVue 2** sin soporte Vue 3 | 5 825 tags, 198 archivos, 277 `$bvToast`, 236 `$bvModal`, 129 directivas | Funciona en compat, pero sin soporte oficial |
| 5 | **`pos.vue` (19 318 LOC) + `ModernPaymentModal` (2 627)** sin pruebas de frontend | 222 métodos, offline, dinero | Cualquier regresión pierde ventas |
| 6 | **Cero pruebas de frontend**; 44 tests PHP leen el código fuente por texto | 246 referencias; 8 tests ya en rojo | No hay red de seguridad; los tests actuales se rompen con la sintaxis |
| 7 | **Librerías solo Vue 2** en uso | good-table 73 archivos, vue-select 76, daterange 33, apexcharts 24, meta 300, tags-input, clock-picker, barcode, easy-print, html-to-paper | Cada una necesita reemplazo o wrapper |
| 8 | **Scripts sueltos acoplados al DOM y a instancias Vue** | 23 scripts, 18 archivos con `MutationObserver`, `el.__vue__` ×7, 143 `querySelector` | Fallan en silencio al cambiar marcado o framework |
| 9 | **Branding por tenant y estilos** atados a Bootstrap 4 | inyector con selectores BS4 y `!important`; 5 194 hex; 2 761 `!important` | Riesgo de romper el color de marca por tenant |
| 10 | **Contrato de build y despliegue** | salida `main.min.js` clásica, `[hash]` global, artefactos versionados, 23 copias, 15 `~` en SCSS, Sass legacy (234 avisos) | Condiciona Vite y hace frágil el despliegue |

Menores pero reales: bus `Fire` (353 usos), `$set` ×421, `$forceUpdate` ×86, `vue-meta` en 300 vistas, `moment` con locales posiblemente recortados, `xlsx` desde CDN externo, dependencia de `cdnjs` sin SRI.

**Riesgo total: 8/10** para llegar a Vue 3 completo. **4/10** para las fases 1 a 4 (no cambian el framework).

---

## 18. Estrategia recomendada

Incremental, sin reescritura, con el diseño visual actual intacto. El rediseño queda fuera de alcance. El orden base propuesto se ajusta por la evidencia en cuatro puntos:

1. **Router 4 va con `@vue/compat`, no después** (router-link roto en Router 3).
2. **Vuex 3 y vue-i18n 8 pueden esperar** hasta después del arranque (funcionan bajo compat).
3. **Antes de compat** hay tres trabajos que sí se pueden hacer en Vue 2.7: codemod de slots, capa de validación propia, eliminación de `__vue__`.
4. **Bootstrap 5 pasa al final y es opcional**: BS4 es solo CSS y no bloquea Vue 3 ni Vite; el inyector de branding y 179+ archivos de utilidades hacen que BS5 sea un proyecto aparte.

### Orden exacto

| Fase | Nombre | Qué incluye | Salida verificable |
|---|---|---|---|
| **0** | Auditoría | Este documento y la matriz | Merge de docs |
| **1** | Red de seguridad | Playwright (flujos críticos + POS offline), snapshot de rutas/permisos, capturas es/en/ar, presupuesto de consola, ESLint 9 informativo de deprecaciones Vue 3, Vitest para utilidades, arreglar o aislar los 8 tests rojos, decidir política para los 44 tests PHP que leen fuente | CI verde y estable durante una semana |
| **2** | Limpieza sin cambio de comportamiento | Borrar `old_pos.vue` (tras confirmar), dependencias sin uso (10 paquetes), rutas `classic`/`legacy` cuando el reemplazo lleve tiempo en producción, `create_sale.vue`, layout legacy `largeSidebar` si `PxShell` es el único en uso; `mix.extract()` para jsPDF/ApexCharts (tamaño de bundle) | Menos LOC y dependencias, tests iguales |
| **3** | Costuras y servicios | `PxToast` servicio + codemod (277 llamadas), `PxConfirm` sobre SweetAlert2 (369), `PxModal` imperativo, `PxTooltip`; emisor de eventos propio en lugar de `window.Fire` (353); extraer a módulos puros la lógica de POS (totales, descuentos, cola offline, ubicación) con tests; servicio único para PDF/Excel | Código con la misma UI pero sin `$bvToast/$bvModal/$swal/Fire` en vistas migradas |
| **4** | Ajustes compatibles con Vue 2.7 | Codemod `slot-scope`/`slot=` → `v-slot`; `.native` → eventos del componente; `filters` → métodos; `>>>`/`/deep/` → `:deep()`; `$listeners` → `$attrs`; `PxValidation` con la API de slots de vee-validate 3 y migración de los 123 archivos; reemplazo de los 4 scripts que usan `__vue__` por eventos/estado; `PxDatePicker`, paridad de `PxSelect` y `PxTable`, `PxFileInput` | Lint de deprecaciones en cero para esos patrones; E2E verde |
| **5** | `@vue/compat` + Router 4 | Rama con build doble (Vue 2 estable + Vue 3 compat). `vue` 3.x + `@vue/compat`, `vue-loader` 17, Router 4 (`router-link` sin `tag`, `addRoutes`, `*`), Vuex 4 opcional aquí, shim `$swal`; BootstrapVue, Vuex, vue-i18n 8 y vue-meta se mantienen tal cual. Canary con un tenant interno | E2E completo contra ambos builds; presupuesto de consola |
| **6** | Estado, i18n y librerías | Vuex 4 → (opcional) Pinia; vue-i18n 9 (`legacy: true`); `vue-meta` → composable; reemplazos de `vue-good-table`, `vue-select`, `vue2-daterange-picker`, `vue-apexcharts`, `vuedraggable`, `lucide-vue-next` (un archivo), `vue-barcode`, impresión | Sin librerías Vue 2 en `package.json` |
| **7** | Retirar BootstrapVue | Por olas: (a) HRM/Reportes/Configuración/Ajustes, (b) Ventas/Compras/Inventario/Productos, (c) **POS y `ModernPaymentModal` al final** | 0 `<b-*>` y 0 `$bv*` |
| **8** | Vue 3 puro | Quitar `@vue/compat` apagando cada flag (`configureCompat`), eliminar `$set/$on/$forceUpdate/filters/.sync` restantes | Sin `@vue/compat` |
| **9** | Vite | Piloto en `storefront`/`portal`/`customer-display` (aislados), luego `main`/`login`; mantener nombres y rutas de salida, script clásico, copia de los 23 scripts, `moment` sin plugin webpack, Sass moderno (`@use`, sin `~`) | Mismo `public/js/*` funcional; tiempos de build menores |
| **10** | TypeScript progresivo | `allowJs`; primero `utils/`, servicios, store y composables; `lang="ts"` por componente al tocarlo | Sin cambio de comportamiento |
| **11** | Bootstrap 5 (opcional) | Solo si BS4 pasa a ser un problema real: reescribir inyector de branding, codemod de utilidades (`ml→ms`, `text-left→text-start`, `font-weight→fw`), RTL nativo, retirar el fork vendorizado | Capturas es/en/ar sin diferencias |
| **12** | Interfaz del POS | El cambio de interfaz del POS ocurre al final de la fase 7 (ola c) y del 8; en el POS se aplican canary y validación con cajeros reales, con la vista `classic` de respaldo | Ver "POS al final" |

**"POS al final" se mantiene**, con una precisión: la lógica del POS se aísla temprano (fase 3, con tests) para poder mover la interfaz al final sin tocar reglas de dinero. El POS forma parte del bundle único, por lo que **arranca sobre `@vue/compat` en la fase 5**; el gate es el E2E de POS y el canary. Si el canary muestra regresión, el POS sigue en el build Vue 2 hasta corregirlo (por eso el build doble).

### Primera tarea de código recomendada tras la auditoría

**Fase 1.1: suite Playwright de humo + snapshot de rutas**, en un directorio nuevo (`tests/e2e/`) sin tocar código de producto: login, dashboard, lista de productos, venta en POS (con cola offline), apertura/cierre de caja, recepción de transferencia y usuario con permisos restringidos; más un script que vuelca los 467 registros de ruta a JSON y compara contra el snapshot. Job de CI **no bloqueante** al inicio. Todo lo posterior depende de esa red.

---

## 19. Rollback

- **Por fase, un PR y una etiqueta** (`frontend-mod-fase-N`). Revertir es `git revert` del PR; las fases 0–4 no cambian framework ni dependencias mayores.
- **Fase 5 en adelante: build doble.** El Blade elige `main.min.js` (Vue 2 estable) o `main.v3.min.js` por bandera de configuración/tenant. Volver atrás es cambiar la bandera y hacer purge del caché, sin despliegue de código.
- **Los artefactos `public/js/*` están versionados**: el último build bueno se recupera con `git checkout <etiqueta> -- public/js public/mix-manifest.json`.
- **Service worker:** `KILL_SWITCH = true` en `public/sw.js` + bump de `VERSION` desregistra y limpia cachés en el siguiente arranque; la política actual ya excluye `/api/*` y login.
- **Chunks con `[hash]`:** al desplegar, conservar el directorio de chunks anterior unos días para pestañas abiertas (hoy `CleanWebpackPlugin` lo borra).
- **Cola offline del POS:** cualquier fase que toque el POS debe probarse con ventas pendientes en `pos_offline_sales_v1` y verificar que se sincronizan tras el cambio.
- **Base de datos y API:** ninguna fase de esta auditoría cambia esquema ni contratos; las fases siguientes tampoco deben hacerlo.

---

## 20. Definition of Done

**Por fase**

1. E2E de humo verde en Vue 2 y (desde la fase 5) en ambos builds.
2. `phpunit` Unit y Feature sin fallos nuevos; los tests que leen fuente actualizados en el mismo PR.
3. Sin errores de consola nuevos (línea base documentada).
4. Capturas es/en/ar (RTL) sin diferencias visuales en las 12 pantallas críticas.
5. Build de producción OK con presupuesto de tamaño (`main.min.js` ≤ 2.27 MB, sin aumentar chunks > 500 KB).
6. Validado en un tenant con subdominio y uno con dominio propio.
7. Documento de rollback de la fase probado una vez.

**Global (Vue 3 completo)**

- `vue` 3.x sin `@vue/compat`; Router 4; estado con Pinia o Vuex 4; vue-i18n 9; sin BootstrapVue ni `$bv*`; sin librerías Vue 2 en `package.json`.
- Los 10 idiomas y RTL verificados con captura y con prueba automática de `dir`.
- POS: E2E verde (venta, offline, cierre de caja, impresión), canary sin incidentes durante el periodo acordado con operaciones.
- 0 usos de `$set`, `$on/$off`, `filters`, `slot-scope`, `.native`, `$listeners` (lint en cero).
- `PRODUCT.md` actualizado: modernización técnica permitida; rediseño separado.
- Tests PHP de arquitectura que leen fuente reemplazados por pruebas de comportamiento o actualizados.
- Vite en producción (si se completa la fase 9) con el mismo contrato de salida y despliegue.

---

## Apéndice A. Comandos y fuentes

```bash
# Inventario Vue 2 (script propio, ver audit-evidence/)
node docs/architecture/audit-evidence/scan-vue2-patterns.js <salida>
node docs/architecture/audit-evidence/scan-routes.js resources/src/router.js resources/src/portal/router.js

# Baseline
npx mix                     # development: OK, ~75 s
npx mix --production        # OK, 3 min 47 s
vendor/bin/phpunit --testsuite Unit      # 1 321 tests, 8 fallos preexistentes
vendor/bin/phpunit --testsuite Feature   # 934 tests OK, 3 omitidos
```

Los builds se ejecutaron en una copia del árbol de trabajo fuera del repo para no reescribir los artefactos versionados de `public/`.

## Apéndice B. Qué no se hizo

- No se levantó la aplicación en navegador: el servidor de demo no estaba activo y arrancarlo habría requerido servicios y datos locales que esta tarea no debía modificar. Las conclusiones de UI vienen del código y de `docs/`, no de una inspección visual.
- No se compiló PRODEX con `@vue/compat` (exigiría cambiar `package.json` y el build). La evidencia de compat viene del spike aislado.
- No se verificó si los 8 tests en rojo fallan también en un checkout limpio de `HEAD`.
- No se midió cobertura de código de PHP ni de JS.
