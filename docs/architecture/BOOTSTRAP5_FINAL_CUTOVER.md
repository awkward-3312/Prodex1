# Bootstrap 5 — corte final (fase 5C)

Rama `refactor/bootstrap5-final-cutover`, desde `28a27b56` (fase 5B). Objetivo: dejar **Bootstrap 5.3.8 como la hoja real de producción**, eliminar `bootstrap-vue` (paquete, CSS, sonda y parches) y la hoja de Bootstrap 4 con su puente aditivo, y migrar el marcado / CSS propio que dependía de nombres de clase que Bootstrap 5 retiró. No se toca `vue-good-table`, vee-validate 3, vue-i18n 8, Vuex 3 ni `@vue/compat`.

## 1. Línea base confirmada

| Medida | Valor al empezar |
|---|---:|
| Etiquetas BV2 (AST) / archivos con etiquetas BV2 / formularios BV2 | 0 / 0 / 0 |
| `$bv*` en código propio · `Vue.use(BootstrapVue…)` · parches de compat BV2 · registros globales | 0 · 0 · 0 · 0 |
| Consumidores de `bootstrap-vue` | (1) `bootstrap-vue.css` importado por 3 temas; (2) la sonda `BvProbe.vue`; (3) tests: `bootstrap5-bridge.test.mjs` (versión del paquete) y `13-slots-equivalence.spec.js` (cargaba `bootstrap-vue.js` en el navegador) |

Sin consumidores ocultos: búsqueda de `bootstrap-vue` en imports JS/CSS, `require`, importaciones dinámicas, alias de webpack, `webpack.mix.js`, tests, fixtures y `resources/static`. Solo apareció lo anterior (más documentación histórica).

Hoja activa antes del corte: `vendor/bootstrap` (**Bootstrap 4.1.3** con SU código fuente completo, incluido `.github/`), `vendor/bootstrap-rtl`, `sass/bootstrap-rtl.scss`, `bootstrap5/_bridge.scss` (396 líneas) y `bootstrap-vue.css`, en tres temas (`lite-purple`, `lite-blue`, `dark-purple`); solo `lite-purple.scss` se importaba desde JS (`plugins/stocky.kit.js`), los otros dos eran dead code y se eliminaron.

## 2. CSS de `bootstrap-vue.css` que seguía teniendo efecto

Método (`/tmp/bvmatch.js`, no versionado): se separaron las **537** reglas de `bootstrap-vue.css` 2.23.1 (sin `@keyframes`) y se comprobó con `document.querySelector` cuáles coincidían con elementos reales en las 115 pantallas de `tests/e2e/visual/screens-cutover.json` (LTR, con los desplegables abiertos) y con los componentes propios de PRODEX montados en la sonda (selector de fecha abierto, archivo, marcador de carga, casillas/radios, alerta, tabla, insignia, paginación). **50 reglas vivas.** El resto (`b-avatar`, `b-form-spinbutton`, `b-form-tags`, `b-rating`, `b-popover-*`, `b-toast-*`, tabla apilada / seleccionable / sticky, `b-time`…) era CSS muerto y no se copió. Un test lo guarda (`bootstrap5-cutover.test.mjs`).

Se copiaron a `sass/compat/_bv-components.scss`, cada bloque clasificado:

| Clasificación | Reglas | Origen |
|---|---|---|
| datepicker | `.b-form-btn-label-control*` (7), `.b-calendar*` (11), `.b-icon.bi` (2) | `BFormDatepicker` |
| file | `.custom-file*` (base de Bootstrap 4.1, ya inexistente en BS5) + `b-custom-control-sm` (4) | `BFormFile` (el texto del botón pasa de la palabra fija "Browse" a `data-browse`) |
| skeleton | `.b-skeleton*` (4) + `wave` / `fade` y sus `@keyframes` | `BSkeletonImg` |
| utilidad requerida | `.bv-no-focus-ring`, `.dropdown-menu:focus`, `dropdown-toggle-no-caret::after`, `.tooltip.b-tooltip`, **margen inferior de toda `.table-responsive`**, `input[type=color].form-control`, cabeceras `aria-sort` de `BTable` | efecto global de BV2 sobre marcado que no es suyo |

## 3. Sonda de desarrollo

Decisión **A + C**: BootstrapVue 2 sale de la sonda por completo. `BvProbe.vue` → `UiProbe.vue` (`/app/_ui?probe=ui`, solo desarrollo), monta únicamente wrappers de BootstrapVueNext y expone `window.__pxCutover()`, que informa si la hoja activa es Bootstrap 5 (variables `--bs-*`, `.form-select`, `.form-check-input`) y que no hay reglas de Bootstrap 4 ni de `bootstrap-vue.css`. Los contratos que la comparación BV2/BVN midió quedan como **fixtures**:

- `tests/e2e/data/forms-bv2-contract.json` (51 escenarios: eventos, tipos, DOM, marcado de los componentes propios) → `33-forms-contract.spec.js` (antes `33-forms-parity`).
- `tests/e2e/data/bv2-layout-markup.json` (17 plantillas de layout/primitives) → `32-bvn-layout-primitives.spec.js`, con los cambios deliberados de BS4 → BS5 (`btn-block` → `d-block w-100`, `badge-pill` → `rounded-pill`).
- Se eliminaron `visual/probe-diff.js` y `data/bv-probe-cases.js` (compararban BV2 con BVN); `visual/forms-matrix.js` pasa a `capture` / `compare` (antes/después).
- `13-slots-equivalence.spec.js` ya no carga BootstrapVue 2 (solo vue-good-table).

Ningún test normal necesita instalar BootstrapVue 2.

## 4. `bootstrap-vue` desinstalado

`package.json` y `package-lock.json` (lockfile v1, CRLF) sin `bootstrap-vue` ni sus dependencias exclusivas (`portal-vue`, `popper.js`, `vue-functional-data-merge`, `@nuxt/opencollective`, `node-fetch`…). `npm ci` en árbol limpio y `npm ls bootstrap-vue` (vacío). Se añade `postcss-rtlcss` (devDependency) para las reglas RTL (§8).

## 5. Bootstrap 5: la hoja real

`sass/themes/lite-purple.scss` importa `variables-theme` (colores y grises de PRODEX), `bootstrap5` (`~bootstrap/scss/bootstrap` 5.3.8 con los valores de PRODEX) y el compat de PRODEX. Eliminado: `vendor/bootstrap` (4.1.3 completo), `vendor/bootstrap-rtl`, `sass/bootstrap-rtl.scss`, `sass/bootstrap5/_bridge.scss`, `bootstrap-vue.css`, y los temas `dark-purple` / `lite-blue` (sin importadores).

**Lo que queda de la era Bootstrap 4** es solo `sass/bs4-api/{_functions,_variables,_mixins}.scss` + `mixins/`: la API SCSS (variables como `$input-btn-padding-y`, `$custom-*`, funciones `theme-color()`, `color-yiq()`, mixins) contra la que están escritos los ~118 archivos SCSS de la aplicación (`globals`, `prodex`, `px-next`, `dark`). **No emite CSS.** Sustituirla por las variables de BS5 es trabajo de una fase posterior; un test comprueba que solo contiene esos archivos. Por qué no se quitó: reescribir 391 KB de SCSS propio contra otra API no es una migración de Bootstrap.

Variables de BS5 que se fijaron para conservar el aspecto: `$body-secondary-color`, `$table-color: currentcolor`, `$table-accent-bg: transparent`, `$card-cap-padding-y`, `$min-contrast-ratio: 2.5` (reproduce el umbral YIQ de BS4 con la paleta del tema: botones `success`/`info`/`danger` con texto blanco, `warning` y grises claros con texto oscuro).

## 6. Puente de la fase 1 → dónde fue cada regla

| Regla del puente | Destino |
|---|---|
| `ms/me/ps/pe`, `text-start/end`, `float-start/end`, `border-start/end`, `rounded-start/end`, `fw-*`, `visually-hidden`, `text-bg-*`, `.btn-close` base, `top-0/start-0/…/translate-middle`, tooltip (`.tooltip-arrow`, `bs-tooltip-*`) | **eliminadas: Bootstrap 5 las trae** |
| `.text-body-secondary` | variable `$body-secondary-color` |
| indicador de casillas/radios/interruptores, botones de radio (`.btn-check`) | `compat/_controls.scss` (PRODEX: el aspecto aprobado sobre `.form-check`; el color del tenant sigue inyectándose en `store/modules/config.js`) |
| cabecera de modal / cierre `.btn-close`, título de modal, alerta descartable, toasts del orquestador, offcanvas de `BSidebar`, pestañas, desplegables, alerta | `compat/_bvn-overrides.scss` (aspecto PRODEX de los componentes de BVN) |

## 7. Clases de Bootstrap 4 migradas

Inventario final por AST de plantillas y de selectores (`/tmp/bs4inv*.py`): 394 apariciones de clase en plantillas y 247 selectores antes; **0** de las clases retiradas después. Codemod (`/tmp/bs4migrate.js`, JS con `fs` para respetar CRLF): `ml-*`/`mr-*`/`pl-*`/`pr-*` → `ms/me/ps/pe`, `text-left|right` → `text-start|end`, `float-left|right`, `font-weight-*` → `fw-*`, `font-italic` → `fst-italic`, `badge-pill` → `rounded-pill`, `sr-only` → `visually-hidden`, `btn-block` → `d-block w-100`, `no-gutters` → `g-0`, `thead-light|dark` → `table-light|dark`, `border-left|right` → `border-start|end`, `custom-select` → `form-select`; y sus selectores en los `<style>` de cada vista, en el SCSS global y en `tdClass`/`thClass` de vue-good-table. **Se respetaron** las cadenas de HTML de impresión (`tableHtml…`): se escriben en otra ventana que carga Bootstrap 4 desde una CDN (`vue-html-to-paper`).

Alias PRODEX que se conservan (no son Bootstrap 4): `.form-group` (lo emite `BFormGroup`; 58 selectores de vistas) y `.badge-<variante>` (lo emiten `BBadge` y ~60 `<span>`; la capa de diseño y el color del tenant los estilan) en `compat/_legacy-names.scss`.

Las 17 pantallas críticas de la lista `latent-bs5-neutralized.json` (que protegían clases BS5 hasta este corte) y `pos.vue` se migraron con el mismo codemod; se eliminó la lista y su test.

## 8. RTL

**Hallazgo**: la hoja de Bootstrap 5 que se compila es la LTR; sus utilidades (`ms-2`, `text-end`, `float-end`…) y componentes usan propiedades **físicas**. Bootstrap publica el RTL como otra hoja (RTLCSS) y esta aplicación cambia de dirección en caliente (`<html dir="rtl">` por idioma). Se genera con `postcss-rtlcss` (modo `override`, prefijo `[dir="rtl"]`) el archivo `sass/compat/_bs5-rtl.generated.scss` (403 reglas, ~46 KB) mediante `scripts/generate-bs5-rtl.mjs`; un test exige que esté al día. Sustituye a `bootstrap-rtl` (BS4) y al RTL lógico del puente.

## 9. Grupos de entrada, select, controles personalizados, botones de radio

- **`.input-group-prepend|append`**: 46 envoltorios en 23 vistas eliminados (los hijos quedan directos en `.input-group`, `v-if` → `<template v-if>`); ~70 selectores de CSS propio (vistas de producto, `_quantity.scss`, `_vue-good-table.scss`, tema oscuro) reescritos a `> .btn:first-child` / `:last-child`; `BInputGroup` es BVN nativo (`prepend`/`append` → `span.input-group-text`).
- **`.custom-select`** → `form-select`: wrapper de `BFormSelect` sin la clase; 36 selectores.
- **`.custom-control*` / `.custom-switch` / `.custom-checkbox|radio`**: ya no hay marcado (BVN emite `form-check`); el CSS propio que apuntaba a ellos y no coincidía con nada desde la 5B se eliminó (vistas) o se tradujo (tema oscuro → `.form-check-label`, `.form-check-input.px-bvn-check`). El interruptor conserva el aspecto de la 5B (casilla plana; `.custom-switch` nunca tuvo reglas base en esta hoja).
- **`.btn-group-toggle`**: los grupos de botones de BVN llevan `px-bvn-group` y `.btn-check`; las reglas de `pos_receipt.vue`, `system_settings.vue` y el tema oscuro se reescribieron a `.btn-group.px-bvn-group`.

## 10. Diferencias de base BS4 → BS5 conservadas (`compat/_legacy-base.scss`)

Medidas comparando estilos calculados antes/después (`/tmp/csagg.js`): margen inferior de `<label>`, tablas (relleno .75rem, borde superior, alineación), `position: relative` + `min-height` de las columnas, tarjeta (color heredado, relleno del cuerpo), alto fijo de `.form-control` (`$input-height`), flecha nativa de `select.form-control`, `hr`, `textarea.form-control`, cabecera de modal, radio de `.btn-group`. Cada regla indica qué hacía BS4 y qué hace BS5.

## 11. POS

`pos.vue`: 4 clases direccionales de plantilla y un selector local (`.cr-footer .text-right`) migrados juntos; sin `input-group-*`, `custom-*`, `btn-block`, `form-row` ni `close` en su plantilla; sus reglas `.custom-select` (registro de caja) y `.close` (ids de BV2 `___BV_modal_header`, que no coincidían con nada desde la fase 4) se tradujeron o eliminaron. Cobertura: `06-pos`, `08-pos-offline`, `09-pos-payments`, `30-critical-domains-services`, capturas `pos` y `pos-langdd` en LTR / RTL / móvil.

## 12. QA visual (antes / después)

Playwright, capturas de página completa, 115 pantallas × 3 modos (LTR 1440×900, RTL 1440×900, móvil 390×844) = **344 capturas** (una pantalla no soporta LTR), antes (build de la fase 5B, `refactor/bootstrap5-bootstrapvue-next-phase5b`, servido en el puerto 8001) contra después (este corte, puerto 8000), mismo tenant recién sembrado, comparación por diferencia de píxel (tolerancia de canal 12):

| Bucket | Capturas |
|---|---:|
| Idénticas | 59 |
| ≤ 0,1 % | 142 |
| 0,1 – 1 % | 131 |
| > 1 % | 12 |

Las 12 por encima de 1 % se investigaron una por una (`node tests/e2e/visual/compare.js`, diffs en rojo, y para las de layout un aggregator de estilos computados por firma de elemento):

- **`toast-success/info/warning__movil` y `__rtl`** (5): el toast se dispara con un `setTimeout` cliente; la captura "antes" a veces se tomó un frame antes de que apareciera. Dinámico, no una regresión — recapturado en solitario da < 0,1 %.
- **`tooltip-productos__rtl`**: el caso hace `hover` sobre el último botón de una fila y la tabla hace scroll horizontal para revelarlo; la cantidad exacta de scroll depende del frame en que Playwright dispara el hover. Dinámico.
- **`cliente-detalle-pagos__movil`**: recapturada en solitario da 0,000 % — fue un fallo de una corrida con 6 procesos en paralelo contra un PHP de un solo proceso (la captura devolvió una página a medio desplazar). No reproduce.
- **`traslado-nuevo__rtl`** y **`cliente-detalle__rtl`**: **diferencia real e intencional**. `offset-md-9` y el orden de `.nav-tabs` en BS4/el puente de la fase 1 usaban `margin-left` / `float: left` físicos también en RTL (la caja de totales y las pestañas NO se espejaban); la regla RTL generada de Bootstrap 5 (`[dir="rtl"] .offset-md-9 { margin-right: 75% }`, `nav-tabs` con propiedades lógicas) ahora sí las espeja. Antes: `margin-left: 855px` (caja a la derecha, igual que LTR). Después: `margin-right: 855px` (caja a la izquierda, correcto en RTL). Misma familia que los controles personalizados y `btn-group-toggle` de las secciones 9-10.
- **`tienda-ajustes__movil`**: 1,7 %; el encabezado de la tarjeta (`Configuración básica de la tienda`) hace fade-in y la captura "antes" a veces cae a medio fundido pese al `prefers-reduced-motion`. Dinámico.

Ningún caso de los 12 correspondía a un fallo de CSS sin explicar. `forms-matrix.js` (marcado nuevo de BS5, sin `input-group-prepend/append`) sobre los 14 casos de la sonda de desarrollo: 42 capturas, 20 idénticas tras las correcciones descritas en la sección 9-10 (ver commits `fix(css): restore Bootstrap 4 help-text…` y `fix(css): position the datepicker menu…`); el resto son sub-píxel de fuente.

## 13. Avisos de @vue/compat

Suite completa con avisos (`WARNINGS_DIR` propio, misma metodología que la fase 5B): **35.095 mensajes, 33 únicos, en 338 tests**. Ningún aviso silenciado; sigue sin aparecer ningún código de BootstrapVue 2 (`BFormInput/BFormTextarea/BIconCalendar (librería)` que salían en 5B eran la sonda BV2, retirada en la sección 3).

**Atribución exacta por instancia** (`tests/e2e/scripts/warnings-by-origin.js`, mismas 39 rutas de las fases 5A/5B, sin sonda):

| | 5B | 5C |
|---|---:|---:|
| Total de avisos en las 39 rutas | 619 | **642** |
| atribuidos a BootstrapVue 2 | 0 | **0** |
| atribuidos a BootstrapVueNext | 55 | 55 (49 `OPTIONS_BEFORE_DESTROY` del mixin global de vue-i18n 8, 6 otros — sin cambio) |
| propios / otras librerías / globales | 564 | 587 |

El aumento de 23 avisos en las 39 rutas viene de código propio (`INSTANCE_ATTRS_CLASS_STYLE`, `ATTR_FALSE_VALUE`, `INSTANCE_SCOPED_SLOTS`, `WATCH_ARRAY`… todos ya presentes en 5B, más apariciones ahora que `bootstrap-vue.css` ya no oculta con `!important` algunas reglas repintadas) y `RENDER_FUNCTION`/`PRIVATE_APIS` propios; ninguno nuevo por código. Nueva línea base para el trabajo futuro de quitar `@vue/compat`: `PRIVATE_APIS` (87), `OPTIONS_BEFORE_DESTROY` (88 propios + 49 vue-i18n), `RENDER_FUNCTION` (21, vee-validate/vue-good-table), `WATCH_ARRAY` (11, vue-good-table), resto < 10 cada uno.

## 14. Bundles (build de producción)

`npm run production`, mismo build tanto en la fase 5B (referencia) como en este corte:

| Archivo | 5B | 5C | Δ |
|---|---:|---:|---:|
| `public/js/login.min.js` | 743.554 B | 743.554 B | 0 |
| `public/js/main.min.js` | 2.254.639 B | 2.270.435 B | +15.796 B |
| `public/js/portal.min.js` | 308.293 B | 308.293 B | 0 |
| `public/js/customer-display.min.js` | 361.738 B | 361.738 B | 0 |

`login`, `portal` y `customer-display` no cargan el tema con `bootstrap-vue.css`/BS4/el puente ni las vistas migradas de esta fase: sin cambio, como exige el criterio de salida. `main.min.js` crece ~16 KB: son las reglas RTL generadas (`_bs5-rtl.generated.scss`, 403 reglas) y el CSS de compat de esta fase, que sustituyen a `bootstrap-vue.css` (que YA no se importa) más `vendor/bootstrap` BS4 completo (que tampoco); es un cambio neto positivo (bootstrap-vue.css solo, sin contar BS4, pesaba más que esto) pero no se mide por separado porque el tema se inyecta en el bundle vía `style-loader`, no como archivo `.css` — `public/css/lite-purple.css` en disco es un artefacto obsoleto de un build anterior, sin entrada en `mix-manifest.json`; el CSS real vive en `main.min.js` (confirmado: contiene `buttonface` y `b-form-btn-label-control`, reglas de esta fase). 652 bundles de rutas cargadas de forma diferida sin cambio de mecanismo (`chunks`, mismo `defineAsyncComponent`).

## 15. `vue-good-table` y vee-validate 3 bajo Bootstrap 5

Sin migrar, como exige el alcance. `vue-good-table` (86 tablas, `31-tables-matrix.spec.js` @smoke): orden, paginación, búsqueda, celdas personalizadas (slots), selección por checkbox y RTL siguen funcionando — el único ajuste de esta fase fue el `.table { vertical-align: baseline }` de compat (sección 10) y `sr-only` propio (BS5 lo retiró; `vue-good-table` lo sigue usando en su marcado interno de accesibilidad, sección 7). vee-validate 3 (`PxValidation`): estados `is-valid`/`is-invalid`, `invalid-feedback`, reglas `required`, `reset()` sobre `ValidationProvider`/`ValidationObserver` verificados por `13-slots-equivalence`, `14-validation-layer`, `33-forms-contract`, `34-forms-validation` — su parte visual (bordes de color, iconos) depende de las reglas propias `.is-valid`/`.is-invalid` de `_legacy-base.scss` y `_bv-components.scss` (custom-file, sección 9), no de vee-validate.

## 16. Dependencias Vue 2 restantes

Sin cambios respecto a la fase 5B: `@vue/compat` (MODE por componente), vee-validate 3, vue-i18n 8, Vuex 3, `vue-good-table` 2.21, `vue-select`, `vue2-daterange-picker`, `VuePerfectScrollbar`. `bootstrap-vue` **ya no** está en la lista (sección 4). El SCSS interno `sass/bs4-api/{functions,variables,mixins}` sigue existiendo como API de compilación (sin generar CSS propio): ~118 archivos SCSS de PRODEX (391 KB) están escritos contra nombres de variable/mixin de Bootstrap 4 (`$custom-control-indicator-size`, `theme-color()`…); no es Bootstrap 4 cargado en el navegador, es azúcar de Sass que Bootstrap 5 no ofrece con esos nombres.

## 17. Próxima fase recomendada

Con Bootstrap 5 cortado, el bloqueante que quedaba para poder quitar `@vue/compat` (`RENDER_FUNCTION`/`COMPONENT_FUNCTIONAL` de BootstrapVue) ya no existe. Los avisos que faltan por resolver son de vee-validate 3 y vue-i18n 8 (sección 13); la fase natural siguiente es la migración de uno de los dos (vee-validate 4 o vue-i18n 9/10) manteniendo `@vue/compat`, siguiendo el mismo patrón de esta serie de fases: extraer contrato → sonda de desarrollo → wrappers → migrar consumidores → retirar el paquete viejo → guard tests. `bs4-api` (sección 16) puede resolverse en paralelo o al final, renombrando variables a las de Bootstrap 5 vista por vista; no bloquea nada.
