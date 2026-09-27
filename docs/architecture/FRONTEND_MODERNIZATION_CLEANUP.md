# Limpieza verificada del frontend (fase 2)

Base: `22722be`. Solo se eliminó código y dependencias con **cero referencias demostradas**. No se tocó Vue, Router, Vuex, BootstrapVue, Bootstrap, vee-validate, Vite ni TypeScript, ni el diseño, ni las rutas.

## 1. `old_pos.vue` — eliminado

`resources/src/views/app/pages/old_pos.vue` (10 685 líneas). Evidencia, sobre el repo completo (sin `node_modules`, `vendor`, `.git`, `storage`):

| Comprobación | Resultado |
|---|---|
| Menciones de `old_pos` / `oldpos` / `old-pos` / `OldPos` (insensible a mayúsculas) | Solo el propio archivo, **comentarios** de `pos.vue` ("ported from old_pos") y la documentación de arquitectura |
| `import` / `import()` / `require()` con esa ruta | Ninguno. Todos los imports dinámicos de `resources/src` y `resources/static` usan cadenas literales; no hay `require.context`, `import.meta.glob` ni `webpackInclude` |
| Rutas construidas por concatenación (`pages/` + variable) | Ninguna |
| `router.js` | La ruta `pos` (`/app/pos`) importa `./views/app/pages/pos`; ninguna importa `old_pos` |
| Tests PHP, workflows, `deploy/`, Blade, `public/sw.js`, manifests, `routes/`, `app/`, `config/` | Cero menciones; los 3 tests que leen `pages/pos.vue` apuntan a `pos.vue` |
| Registro por nombre de componente (`OldPos`, `old-pos`) | Ninguno |
| Detección de módulos huérfanos por análisis de imports (antes y después de borrar) | Era el único archivo de `views/` sin importador que no fuese un punto de entrada; **borrarlo no deja ningún módulo nuevo huérfano** (sus imports compartidos —`ModernPaymentModal`, `CustomFieldsForm`, `posKeyboardShortcuts`, utilidades— los usa `pos.vue`) |
| Historial | 3 commits; nunca se enlazó tras la migración a `pos.vue` |

`pos.vue` no se tocó. Los tests que dependen del POS (`PosOnlyManualSale…`, `QuotationSaleTraceability…`, `PosAuxiliaryModal…`), la ruta `/app/pos` y los 36 E2E siguen en verde.

## 2. Dependencias

Revalidadas en `22722be` con un escaneo de todo el repo (`resources/`, `webpack.mix.js`, `tailwind.config.js`, `tests/`, `.github/`, `deploy/`, `app/`, `routes/`, `config/`, `public/` sin bundles ni `.min.js`) buscando el nombre del paquete, `require`, `Vue.use`, `Vue.component`, directivas y etiquetas que registraría cada uno, globals y uso desde Blade. Además, ninguna de las 10 aparece en `requires` de otro paquete del lockfile.

| Dependencia | Estado | Evidencia |
|---|---|---|
| `@vee-validate/i18n` | **Eliminada** | 0 usos. vee-validate 3 se localiza con `localize()` propio (`main.js`, `login.js`) |
| `vue-echarts` | **Eliminada** | 0 usos. `Sales3DDashboard.vue` importa `echarts` y `echarts-gl` directamente (se conservan) |
| `vue-lazyload` | **Eliminada** | 0 usos de `VueLazyload` ni de la directiva `v-lazy` |
| `vue-grid-layout` | **Eliminada** | 0 usos. Las únicas coincidencias eran `updateDashboardGridLayout` (PHP) y un bundle de Tailwind |
| `vue-navigation-bar` | **Eliminada** | 0 usos. Solo queda un bloque SCSS `.vnb {…}` en `themes/dark/_dark.scss` que estiliza sus clases; con el paquete fuera no genera nada y **no se tocó** (estilos) |
| `vue-simple-spinner` | **Eliminada** | 0 usos |
| `lodash.orderby` | **Eliminada** | 0 usos |
| `babel-polyfill` | **Eliminada** | 0 usos (ni `@babel/polyfill`) |
| `es6-promise` | **Eliminada** | 0 usos |
| `targets-webpack-plugin` | **Eliminada** | 0 usos; `webpack.mix.js` no lo referencia |

Ninguna se conservó: las 10 revalidaron como sin uso. **Se conservan** las que la auditoría marcó como "mínimo uso" pero sí tienen usos (`vue-perfect-scrollbar`, `vue-clickaway`, `mobile-device-detect`, `vue-cookie`, `vue-cookies`, `vue-localstorage`, `@trevoreyre/autocomplete-vue`, `echarts`, `echarts-gl`, etc.); retirarlas es otra fase.

### package.json y package-lock.json

- `package.json`: −10 líneas (10 dependencias de `dependencies`; sin cambios en `devDependencies` ni en scripts).
- `package-lock.json` **sigue en `lockfileVersion` 1** y conserva su formato (CRLF, 4 espacios): **−444 líneas, 0 insertadas**. Se eliminaron los 10 paquetes y, de forma iterativa, los 47 paquetes que solo ellos necesitaban (`@interactjs/*`, `rollup` y plugins, `tippy.js`, `@popperjs/core`, `vue-demi`, `babel-runtime`, `element-resize-detector`, …). Las banderas `dev` no cambian (el análisis de alcanzabilidad reproduce las 1 175 banderas existentes sin discrepancias).
- Total: **57 paquetes menos** (`npm ci --dry-run`: 1 172 → 1 115).
- `npm ci` en un directorio limpio con solo `package.json` + `package-lock.json`: OK; `npm ls` reporta los mismos 9 problemas de peers que antes (`eslint`, `jquery`, `popper.js`…), ninguno nuevo.

## 3. Métricas antes / después

Medido, sin optimizar nada. Builds de producción en copias fuera del repo (Node 22); `node_modules` con `npm ci` desde un directorio limpio.

| Métrica | Antes (`22722be`) | Después | Diferencia |
|---|---|---|---|
| Archivos `.vue` | 497 | 496 | −1 |
| Líneas `.vue` (`wc -l`) | 258 089 | 247 405 | −10 684 |
| Dependencias directas (`dependencies` / `devDependencies`) | 60 / 20 | 50 / 20 | −10 |
| Paquetes instalados (`npm ci --dry-run`) | 1 172 | 1 115 | −57 |
| `node_modules` (`du -sk`) | 713 676 KB | 653 420 KB | −60 256 KB (−8.4 %) |
| `package-lock.json` | lockfileVersion 1 | lockfileVersion 1 | −444 líneas, 0 insertadas |
| `main.min.js` (producción) | 2 377 942 B | 2 377 981 B | +39 B (hash de módulos) |
| `login` / `portal` / `customer-display` / `storefront` | 865 365 / 217 356 / 264 442 / 82 249 B | 865 403 / 217 362 / 264 444 / 82 249 B | ≈ 0 |
| Chunks en `public/js/bundle` | 652 | 652 | 0 |
| Tamaño total de bundles (`public/js`, `du -sk`) | 82 932 KB | 82 936 KB | ≈ 0 |

**Los bundles no cambian y es lo esperado:** webpack solo empaqueta lo que se importa. `old_pos.vue` no lo importaba nadie y las 10 dependencias no se importaban, así que nunca estuvieron en `main.min.js` ni en los chunks. La ganancia es de mantenimiento: −10 684 líneas que un desarrollador podía confundir con el POS real (varios bloques de `pos.vue` se portaron de ahí, según sus comentarios), −57 paquetes que instalar, auditar y actualizar (incluidos `rollup`, `@interactjs/*`, `tippy.js`), y −60 MB de `node_modules`.

## 4. Lo que NO se borró (candidatos futuros)

Todo esto sigue existiendo y funcionando; se documenta para fases posteriores.

**Módulos sin ningún importador** (análisis de imports; ahora 15 archivos de `resources/src`, sin contar puntos de entrada):
`components/TableComponent.vue`, `components/arrow/arrowIcon.vue`, `views/app/pages/settings/woocommerce/StatusOverviewTab.vue`, `views/app/pages/two_factor_verify.vue`, `utils/spanishCommerceIntegrationGuard.js`, `utils/spanishLegacyDocumentGuard.js`, `utils/spanishPermissionsUiGuard.js`, `utils/spanishSettingsUiGuard.js` (observers que `i18n.loader.js` declara desactivados), `auth/IsConnected.js`, `auth/authenticate.js`, `assets/styles/vendor/bootstrap/.babelrc.js`, y los `index.vue` de `dashboard/`, `accounts/`, `pages/` y `sessions/`. Cada uno necesita una comprobación propia (algunos `index.vue` pueden ser puntos de entrada por directorio).

**Superficie legacy con referencias** (rutas activas, respaldo por URL): las 33 rutas `classic`/`legacy`, `dashboard/dashboard.vue`, `Add_product.vue` / `Edit_product.vue`, `create_sale.vue` (redirigida al POS), el layout `largeSidebar/*`, BootstrapVue, los scripts `prodex-*`.

**Dependencias con usos reales pero prescindibles más adelante:** `vue-clickaway`, `mobile-device-detect` (solo layout legacy), `vue-cookie`/`vue-cookies`/`vue-localstorage`.

## 5. Comportamiento protegido

Sin cambios: 474 rutas tenant y 20 de portal (snapshot), login, dashboard, permisos, inventario, transferencias, POS, caja, offline, pago mixto, lotes, seriales, es/en/ar + RTL. Suite: Unit 1 328/1 328, Feature 934 (3 omitidos), E2E 36/36 (también tras reset completo), ambos builds OK.
