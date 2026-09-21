# Bootstrap 5 + BootstrapVueNext — fase 5A (layout y primitives)

Rama `refactor/bootstrap5-bootstrapvue-next-phase5a`, desde `3e8261e` (fase 4). Objetivo: sacar de BootstrapVue 2 (BV2) todo el layout y las primitives que se pueden migrar con riesgo bajo/medio **antes** de atacar los formularios: `row/col/container/card*`, `button(-group)`, `badge`, `alert`, `spinner`, `progress`, `link`, `list-group`, `img`, `avatar`, `tabs`, `dropdown*` y `pagination`. No se migran los formularios, no se sustituye `vue-good-table`, no se desinstala BV2, sin corte global a Bootstrap 5 ni cambios de backend/lógica.

## 1. Línea base confirmada (por AST)

Las cifras de la fase 4 (4.956 etiquetas / 182 archivos) salían de una regex; ahora se cuentan con el AST del compilador de Vue (`tests/frontend/inventory/bootstrap-inventory.mjs`, sin comentarios ni cadenas). Una etiqueta es de BVN si su archivo importa el wrapper con ese nombre de `@/platform/bootstrap`; si no, es de BV2.

| Medida | Fase 4 (regex) | Fase 4 (AST, base de esta fase) |
|---|---:|---:|
| `<b-*>` BV2 | 4.956 (182 archivos) | **4.953 (180 archivos)** (la regex contaba 3 etiquetas en comentarios/cadenas) |
| `<b-*>` BVN | 751 | 751 (97 archivos) |
| Formularios BV2 (`b-form*`, `b-input-group*`) | 2.100 | 2.136 (la fase 4 no contaba `b-form` en algunos conteos) |

Cero confirmado: `$bvToast` 0, `$bvModal` 0, `<b-modal>` BV2 0, `<b-table>` BV2 0, `b-sidebar` BV2 0, `v-b-tooltip` BV2 0 (los 34 usos están registrados localmente con la directiva de BVN), `CUSTOM_DIR` `false`. Pruebas de partida: frontend 124, Unit 1.328, Feature 934 (3 skipped), rutas 474 / 20, E2E 221 + 1 skipped.

## 2. Método: auditoría antes de migrar, contrato probado sobre las dos implementaciones

1. **Auditoría de uso real** (AST): por cada etiqueta BV2, qué atributos, eventos, slots y directivas se usan (`inventory --json` → `attrs`). Solo se migra lo que se usa: `b-col` (`md` 1.298, `sm` 570, `lg` 455, `cols` 14, `col` 1), `b-row` (`no-gutters` 1), `b-card` (`no-body` 20, `title` 31, `header` 5, `header-bg-variant` 2), `b-button` (`variant`, `size`, `block` 35, `type`, `:disabled`, `:to` 1, `href`, `tag` 1), `b-badge` (`pill` 11), `b-alert` (`show` 46, `dismissible` 4, `@dismissed` 2), `b-progress` (`height`, `value`, `max`, `show-progress`, `animated`), `b-tabs` (`v-model` 9, `content-class` 9, `lazy` 2, `pills` 1, `@input` 1, `active-nav-item-class` 2), `b-dropdown` (`right` 13, `no-caret` 13, `toggle-class` 13, `menu-class` 3, `offset`, `boundary`, `dropup`, slot `#button-content`), `b-pagination` (`v-model` 7, `@change` 6, `align` 3, `:value`+`@input` 1).
2. **Sonda de contratos** (solo desarrollo, `/app/_ui?probe=bv`): monta la MISMA plantilla con los `b-*` de BV2 (registrados localmente solo en la sonda) y con los wrappers de `platform/bootstrap` (registro local, como las vistas). `tests/e2e/specs/32-bvn-layout-primitives.spec.js` (38 pruebas) exige el mismo DOM normalizado donde el marcado debe coincidir y **el mismo comportamiento con las mismas aserciones sobre BV2 y BVN** (una sola emisión, teclado, clic fuera, ESC, RTL, móvil).
3. **Migración con codemod verificado** (`resources/src/views|components|containers`): 156 archivos, 608 registros locales; las plantillas no cambian (`<b-col>` resuelve al registro local). Las plantillas en cadena dentro de `<script>` se buscaron aparte (solo `CustomerLedger`: dos componentes en línea).
4. **Captura visual** antes/después (39 pantallas × LTR/RTL/móvil) y **E2E completo**.

## 3. Layout migrado (BV2 1.979 → 0)

`BRow`, `BCol`, `BContainer`, `BCard`, `BCardBody/Header/Footer/Title/Text`. Salvo dos casos, BVN emite el mismo DOM que BV2 (`col-lg-12 col-md-6 col-sm-12`, `col-12`, `col`, `col-auto`, `card > card-body`, título, cabecera con `bg-light`, `no-body`, slots `header/footer`):

- **`no-gutters`**: BVN lo traduce a `g-0` (BS5, la hoja BS4 no lo tiene). El wrapper de `BRow` emite la clase `.no-gutters` de BS4.
- BV2 filtraba al DOM atributos de props no declaradas (`md="6"`, `cols`, `no-body`…); BVN no. Sin efecto visual.

Sin `b-card-group`, `overlay`, `img-*` ni `deck` en uso (auditado).

## 4. Primitives migradas (BV2 148 + 579 → 0)

| Componente | Decisión |
|---|---|
| `BButton` (579), `BButtonGroup` | Wrapper de la fase 2 (`block` → `btn-block`); nuevo: `target="_blank"` sin `rel` añade `rel="noopener"` como BV2. Un clic = una acción, `disabled` inerte, `submit` una vez, `.stop` no llega al padre (probado en BV2 y BVN). `tag="a"`: BV2 pintaba `href="#"`, BVN no (1 uso, sin efecto). |
| `BBadge` (83) | `pill` → clase `badge-pill` de BS4 (BVN emite `rounded-pill`, sin el relleno horizontal). |
| `BAlert` (46) | `show` (booleano o nº de segundos) ↔ `modelValue`; `@dismissed` ↔ `close`; sigue aceptando `model-value`. BVN envuelve el cuerpo en `.d-flex > .alert-body`: el puente lo hace crecer (`text-center` funciona) y la cruz `btn-close` queda en el borde final (probado en LTR y RTL). |
| `BProgress` / `BProgressBar` | La variante se pinta con `bg-<v>` (BS4), no `text-bg-<v>` (BS5, otro color de texto). |
| `BLink`, `BListGroup(Item)`, `BImg`, `BAvatar`, `BSpinner` | Directos (pure). `b-link` con `href="#"` no cambia la URL y emite una vez (probado). |
| `b-skeleton-img` (4) | **No migrado**: BVN no tiene equivalente; sigue en BV2 (y por eso `BAspect` de BV2 aparece en los avisos). |

## 5. Pestañas (BV2 53 → 0)

Contrato usado y probado: `v-model` = índice, `@input` una vez por cambio, pestaña deshabilitada inerte, `active` inicial, `lazy`, `pills`, `content-class`, `active-nav-item-class`, cambio programático del modelo, teclado. Hallazgos que costaron una vuelta cada uno:

- **`v-model`**: en BVN `modelValue` es el **id** de la pestaña y el índice va en `index`. El wrapper traduce (`modelValue` numérico → `index`, `update:index` → `update:modelValue` + `input`).
- **`BTab` no puede envolverse**: BVN solo reconoce como pestañas los hijos cuyo `type` es exactamente su `BTab` (`tab.type === BTab`); un wrapper deja la lista vacía y **no se activa ninguna pestaña** (lo destapó la captura visual de `employee_details`, 6,5 % de diferencia). `BTab` se exporta tal cual.
- **`lazy`**: BV2 destruye el contenido de la pestaña inactiva al salir; BVN lo conserva salvo `unmountLazy` (prop de cada `BTab`). `BTabs` clona los hijos con `unmountLazy` cuando hay `lazy` (lo destapó `31-tables-matrix` en el detalle de cliente).
- **Marcado**: BVN pinta `<button class="nav-link">` (BV2 `<a>`); el puente le quita el aspecto de botón y hereda tipografía sin pisar `.prodex-ui .nav-tabs .nav-link`.

## 6. Desplegables (BV2 35 → 0)

`BDropdown`, `BDropdownItem/Divider/Header/Form`. Prueba: abrir/cerrar por clic, ítem, clic fuera y ESC; teclado (Enter en el botón); ítem con `:to` navega con Router 4 una sola vez; `right` alinea el borde derecho del menú con el del botón; eventos de raíz.

- **Identidad de BV2**: el CSS del shell (`#user-dd`, `#lang-dd`, `#notif-dd`) y del POS (`button#lang-dd__BV_toggle_`, `.b-dropdown`) engancha `div#id.b-dropdown.btn-group.dropdown` > `button#id__BV_toggle_`. BVN pone el `id` en el botón y su envoltorio no lo lleva. El wrapper usa `noWrapper` y pinta el envoltorio de BV2, pasando `id__BV_toggle_` al botón (cero cambios de CSS).
- **Eventos de raíz** `bv::dropdown::show|hide`: 6 listas con acciones por fila (`customers`, `providers`, `index_sale`, `index_transfer`, `Customers_*`) los usan para dar altura a la tabla mientras el menú está abierto (`showDropdown`). El wrapper los emite (probado, una vez por apertura).
- `right` → `placement="bottom-end"`; `boundary="window"` → `viewport`; `offset` directo.
- **Ancho del menú**: BVN lo posiciona `absolute` dentro de un envoltorio del ancho del botón y se contrae al ancho mínimo (la rejilla de idiomas del POS partía «Chino simplificado»); el puente fija `width: max-content`.
- **Descartado**: `strategy="fixed"` (para escapar de contenedores con `overflow`) descolocaba el menú dentro de la cabecera del POS (ancestros con `transform`/`filter`). El recorte por `overflow` de las tablas lo resuelven las vistas con `showDropdown`.
- Diferencia aceptada: `right` en BVN alinea de verdad el borde del menú con el del botón; el popper de BV2 medía el menú antes de que la rejilla tomara su ancho final y lo dejaba ~55 px desplazado en el POS.

## 7. Paginación (BV2 7 → 0)

`v-model`, `:value` + `@input`, `align` (`left/right` ↔ `start/end`), `size`, `@change`. Hallazgos:

- **`@change` de BV2 se emitía ANTES de actualizar el v-model** (un manejador que leía `this.page` veía la página anterior; medido en la sonda: `[3, page=1]`). BVN emite `page-click` antes del v-model, así que el wrapper emite `change` tras `nextTick` con el modelo ya actualizado. `CustomerDetails` usa `@change="fetchSales"` leyendo `this.salesPage`: el cambio corrige un desfase de una página (documentado; el resto de manejadores reciben la página por argumento).
- BV2 dibuja siempre la página 1 (también con 0 filas); BVN no dibuja ninguna: el wrapper fuerza `total-rows` ≥ 1 (lo destapó la captura visual del detalle de cliente).
- El cambio programático del modelo no emite `@change` (igual que BV2).

## 8. Otras familias y limpieza

- `BCollapse/BNav/BNavbar/BBreadcrumb`: **sin uso** (0 etiquetas). `b-list-group`: migrado.
- **Registro global de BV2 reducido** (`platform/compat/bootstrap-vue-forms.js`): `Vue.use(BootstrapVue)` registraba ~90 componentes, directivas y los servicios `$bvModal`/`$bvToast` (sin consumidores desde la fase 4). Ahora solo se registran los plugins de formularios, `input-group` y `skeleton` (lo único que queda). Un test verifica que toda etiqueta BV2 en uso está cubierta por esa lista.
- **Plantillas en cadena** (`template: \`…\``): `CustomerLedger.vue` tenía `ListToolbar`/`Pager` con `b-button`/`b-pagination` que dependían del registro global (y `$parent.$t`, que con un padre de BVN —`expose`— ya no existe; se cambió a `$t`). Un test prohíbe etiquetas `b-*` (salvo formularios) en cadenas de `<script>`.
- Formularios: no se tocó ningún control (sección 12).

## 9. Clases Bootstrap 4 en las vistas migradas

Codemod solo sobre `class="…"` de plantillas de los 156 archivos migrados, con lógica start/end (RTL): `ml/mr/pl/pr-*` → `ms/me/ps/pe-*`, `text-left/right` → `text-start/end`, `float-*`, `font-weight-*` → `fw-*`, `sr-only` → `visually-hidden`, `border/rounded-left/right`. Se omite la clase en un archivo si aparece en su `<script>` o `<style>` (selectores, `classList`), y **no se toca ningún archivo de las pantallas críticas con clases BS5 neutralizadas** (`latent-bs5-neutralized.json`, 23 archivos: la guardia de la fase 2 sigue verde). Sin corte global de la hoja.

| Clase (atributos `class` de plantillas) | Antes | Después |
|---|---:|---:|
| direccionales (`ml/mr/pl/pr`, `text-left/right`, `float-*`, `border/rounded-*`) | 575 (77 archivos) | 194 (29) |
| `font-weight-*` | 95 (33) | 52 (21) |
| `badge-*`, `custom-*`, `input-group-append/prepend`, `sr-only`, `btn-block` | 133 / 6 / 7 / 2 / 7 | igual (los `badge-*` los emite el wrapper) |

En RTL, `ml-auto` (que BS4 no invierte: la hoja solo invierte `ml-0…5`) pasa a `ms-auto` (inline-start): 9 usos en 13 archivos (informes y ajustes) colocan sus acciones en el lado final en RTL. Es la diferencia visual esperada de esas pantallas (ver §11).

## 10. Avisos de `@vue/compat`

Suite completa (250 tests con avisos; fase 4: 213): **33.136 mensajes, 34 únicos → 132,5 por test** (fase 4: 32.019 / 150,3; **−11,8 % por test**). Ningún aviso silenciado.

| Aviso | Fase 4 (total / por test) | Fase 5A (total / por test) |
|---|---:|---:|
| `PRIVATE_APIS` | 7.589 / 35,6 | 8.190 / 32,8 |
| `RENDER_FUNCTION` | 2.743 / 12,9 | 2.445 / 9,8 |
| `COMPONENT_FUNCTIONAL` | 1.103 / 5,2 | 572 / 2,3 |
| `OPTIONS_BEFORE_DESTROY` | 9.325 / 43,8 | 11.518 / 46,1 |
| `INSTANCE_EVENT_HOOKS` | 79 / 0,37 | 79 / 0,32 |
| `INSTANCE_EVENT_EMITTER` | 236 / 1,11 | 220 / 0,88 |
| `CUSTOM_DIR` / `PLUGIN_VUE2_ONLY` | 0 / 0 | 0 / 0 |

`OPTIONS_BEFORE_DESTROY` sube por test porque ahora hay muchos más componentes de BVN por pantalla y **cada componente** (BVN incluido) recibe el mixin global de `vue-i18n` 8 con `beforeDestroy`; ningún componente de BVN lo declara.

**Atribución exacta por instancia** (`tests/e2e/scripts/warnings-by-origin.js`: `app.config.warnHandler` con la instancia que emite cada aviso, mismas 39 pantallas, árbol de la fase 4 vs este):

| | Fase 4 | Fase 5A |
|---|---:|---:|
| Total de avisos en las 39 pantallas | 728 | 687 |
| Atribuidos a **BV2** | **93** | **57 (−39 %)** — solo formularios (`BFormInput/Group/Select/Checkbox/Radio(Group)/Textarea/File`) y `BAspect` |
| Atribuidos a BVN (mixin de i18n) | 20 | 40 |
| Propios / sin instancia | 588 / 27 | 576 / 14 |

Por aviso (BV2): `PRIVATE_APIS` 17 → 10, `RENDER_FUNCTION` 17 → 10, `INSTANCE_SCOPED_SLOTS` 14 → 8, `COMPONENT_FUNCTIONAL` 4 → 3. Componentes de BVN: 0 avisos de contrato de Vue 2 (el `COMPONENT_FUNCTIONAL` que aparece sobre un `BCardBody` es un `b-form-invalid-feedback` funcional de BV2 dentro de su slot; el E2E 22 lo acota). Resto de orígenes: `vee-validate` 3 (`PxValidationObserver/Provider`, `RENDER_PROPERTY_UNDEFINED`), `vue-i18n` 8 (`OPTIONS_BEFORE_DESTROY`, `GLOBAL_PROTOTYPE`), `vue-good-table` (`RENDER_FUNCTION`, `WATCH_ARRAY`, `INSTANCE_SET`), otros (`vue-select`, `VuePerfectScrollbar`, `LucideIcon`).

## 11. Visual QA

39 pantallas (layout, tarjetas, tablas de lista, formularios largos, pestañas, paginación, desplegables abiertos, POS, detalle de empleado/cliente/proveedor, ajustes de sistema…) × LTR / RTL / móvil = **129 capturas**, fase 4 vs 5A (`tests/e2e/visual/screens-phase5a.json`, `capture.js`, `compare.js`, tolerancia de canal 12).

- **110 de 129 idénticas (≤ 0,05 %)**; con las 13 pantallas de datos volátiles recapturadas sobre el estado actual de la base (la primera comparación mezclaba las ventas que crea el E2E entre las dos capturas: dashboard y detalle de cliente mostraban datos, no interfaz).
- Diferencias reales corregidas por la captura: **pestañas** (letra del enlace-botón; ninguna pestaña activa en `employee_details` → 6,5 %), **paginación con 0 filas** (faltaba la página 1), **menú de idiomas del POS** (ancho), **cabecera de tablas del libro mayor**. Todas cubiertas después con una prueba de contrato.
- Diferencias aceptadas: (1) menú de idiomas del POS/topbar alineado al borde del botón (1,8 % escritorio, 7 % móvil; §6); (2) informes con `ml-auto` en RTL (1,7 %; §9); (3) sub-0,4 % de 1 px de texto en `servicio-nuevo`, `almacenes` RTL.
- Los cambios ya aceptados en la fase 4 (traducción de algunas etiquetas de tablas por `spanishUiGuard`, 1 px de texto en modales) no empeoran: las capturas de modales y tablas de la fase 4 siguen pasando.

## 12. Formularios: aislados como siguiente bloque (no migrados)

2.136 etiquetas BV2 en 128 archivos son lo único que queda de BV2 (más 4 `b-skeleton-img`). Bloqueos exactos (AST + `v-model` modifiers):

| Patrón | Uso | Motivo |
|---|---:|---|
| `b-form-group` | 846 (`:label` 675) | contenedor de todos los campos; se migra con sus controles (marcado `fieldset/legend` ya resuelto en la fase 2) |
| `b-form-input` | 536 (`v-model` 523, `type` 179) | `.trim` 42 y `.number` 203 en `v-model`: BVN lo implementa distinto (recorta al escribir); requiere formatter probado |
| `b-form-invalid-feedback` | 308 | requiere que el control padre ya sea BVN (estado `:state`) |
| `b-form` | 123 (`@submit` 123) | contenedor `<form>`; trivial pero acoplado a `validation-observer` |
| `b-form-select` | 68 (`:options` 47) | patrón simple ya demostrado (fase 2); pendiente de barrido |
| `b-input-group` | 63 (+ `append` 28, `b-input-group-append/prepend` 39) | BVN elimina append/prepend: hijos directos |
| `b-form-checkbox` | 58 (`switch` 50) | `switch` (`custom-switch`) sin CSS en el puente |
| `b-form-textarea` | 35 (`max-rows` 5) | `max-rows` usa `v-b-visible` interno de BV2 |
| `b-form-file` | 16 (`multiple` 8, `accept` 15) | placeholders/drop-placeholder, archivos múltiples |
| `b-form-radio-group` | 8 (`buttons` 7) | grupo de botones |
| `b-form-datepicker` | 6 | `v-b-hover` interno de BV2 |
| `b-form-checkbox-group` | 1 | grupo |

## 13. Adaptador y parches de BV2

- `platform/compat/bootstrap-vue.js`: eliminado `BFormTags` de la lista de parcheados (0 consumidores). **Se mantienen**: el asignador `onUpdate:modelValue` para `BFormSelect/BFormCheckbox/BFormRadio` (formularios BV2) y los hooks de Vue 3 de `v-b-visible` (`BFormTextarea` con `max-rows`, 5) y `v-b-hover` (`BFormDatepicker`, 6). Ninguno queda sin consumidor.
- `platform/compat/bootstrap-vue-forms.js` (nuevo): el registro global reducido (§8).
- `platform/adapters/*`: sin cambios (sin código de BV2).

## 14. Inventario de `vue-good-table` (para la fase 5C; sin cambios de implementación)

**86 tablas en 73 archivos** (`tests/frontend/inventory/vue-good-table.json`, regenerable con `node tests/frontend/inventory/bootstrap-inventory.mjs --vgt`; un test falla si cambia). 83 en `views/app/pages`, 3 en `dashboard`.

| Función | Tablas |
|---|---:|
| paginación (`pagination-options`) | 85 |
| celdas personalizadas (`#table-row`) | 80 |
| modo servidor (`mode="remote"`) + `@on-page-change` + `@on-per-page-change` | 74 |
| búsqueda (`search-options`) | 73 |
| ordenación (`@on-sort-change`) | 56 (`sort-options` 2) |
| acciones de cabecera (`#table-actions`) | 48 (`#table-actions-bottom` 2) |
| agrupación (`group-options`) | 16 (ninguna con `collapsable`) |
| selección (`select-options`) + `#selected-row-actions` | 9 / 10 |
| `row-style-class` | 3 |
| `rtl` | 2 |
| `#emptystate` | 1 |
| clic de fila, filas expandibles, cabecera fija, números de línea, modo compacto | **0** |

12 tablas son de modo cliente (dashboard, informes, ajustes de sistema, checklists de servicio); 74 de servidor. Bloqueo Vue 3: v2.21.11 es solo Vue 2 (render con `h`, `$listeners`); avisos atribuibles: `RENDER_FUNCTION`, `WATCH_ARRAY` (996 en la suite), `INSTANCE_SET`, prop `rtl` mal tipada. Clasificación: **A (mantener)** en 5A; el contrato realmente usado es pequeño (sin filas expandibles, selección solo en 9, agrupación en 16), lo que favorece `vue-good-table-next` detrás de un wrapper de columnas/filas/eventos.

## 15. Pruebas

- Frontend **131/131** (124 + 7: inventario por AST con las familias migradas a 0, registro reducido, exportaciones y contrato de los wrappers, plantillas en cadena, inventario de `vue-good-table`).
- Unit **1.328/1.328**, Feature **934 OK (3 skipped)**, rutas **474 / 20** (el snapshot cambia una línea: el componente de la ruta de desarrollo `/app/_ui`, que ahora sirve también la sonda con `?probe=bv`; no existe en producción).
- E2E completo **@@E2E@@**, también tras `E2E_RESET=1`. Nuevos: `32-bvn-layout-primitives` (38): DOM idéntico BV2 vs BVN (17 casos), progreso, alerta (visibilidad, `:show`, `@dismissed` una vez, RTL), botón/enlace/`:to` (una emisión), pestañas (v-model, `@input`, deshabilitada, lazy y destrucción, primera activa sin `active`, tipografía), desplegable (clic, ítem, fuera, ESC, teclado, `:to`, `right`, eventos de raíz), paginación (`@change` una vez con el modelo actualizado, programático sin `@change`, 0 filas, `:value`+`@input`), grid responsive y RTL.
- Builds: desarrollo OK (42 avisos de compilación, los de siempre), producción OK, `npm ci` limpio.

## 16. Métricas antes (fase 4, AST) → después

| Métrica | Antes | Después |
|---|---:|---:|
| `<b-*>` BV2 | 4.953 (180 archivos) | **2.140 (144)** |
| `<b-*>` BVN | 751 (97) | **3.564 (171)** |
| layout BV2 / BVN | 1.979 / 87 | 0 / 2.066 |
| botones BV2 / BVN | 579 / 10 | 0 / 589 |
| primitives (badge, alert, progress, link, img, avatar, spinner, list-group) BV2 / BVN | 148 / 20 | 0 / 168 (+ `b-skeleton-img` 4 BV2) |
| tabs BV2 / BVN | 53 / 0 | 0 / 53 |
| dropdown BV2 / BVN | 35 / 0 | 0 / 35 |
| pagination BV2 / BVN | 7 / 0 | 0 / 7 |
| progress BV2 / BVN (incluido arriba) | 12 / 0 | 0 / 12 |
| formularios BV2 / BVN | 2.136 / 493 | 2.136 / 493 |
| clases BS4 direccionales / `font-weight-*` (plantillas) | 575 / 95 | 194 / 52 |
| avisos por test (suite) | 150,3 | 132,5 |
| avisos atribuidos a BV2 (39 pantallas) | 93 | 57 |
| @@BUNDLE_ROWS@@ |

## 17. CI

PHP 8.4, servidor embebido de un solo proceso, sin bucle de reinicio y sin `continue-on-error` en el E2E. Se añadió la rama `refactor/bootstrap5-bootstrapvue-next-phase5a` (push + condición del job). @@CI@@

## 18. Superficie restante de BV2, blockers exactos y plan de la fase 5B

**Superficie**: 2.136 etiquetas de formulario en 128 archivos (§12) + 4 `b-skeleton-img`; `v-b-visible`/`v-b-hover` internos; `@vue/compat` por `vue-good-table`, `vee-validate` 3, `vue-i18n` 8, `vue-select`, `vue2-daterange-picker`, `lucide-vue`.

**Blockers para desinstalar BV2**: los formularios, `b-skeleton-img` (o sustituirlo por `BPlaceholder`), y quitar el registro de `platform/compat/bootstrap-vue-forms.js` con el último formulario.

**Fase 5B (formularios, por riesgo creciente)**, cada paso con su contrato probado en la sonda BV2 vs BVN y captura visual:
1. `b-form` (123) y `b-form-invalid-feedback` (308): sin lógica propia.
2. `b-form-group` (846) y `b-form-input` (536) **sin** `.trim`/`.number`: el patrón ya demostrado en la fase 2 (~300 `v-model` simples).
3. `b-form-select` (68), `b-form-textarea` sin `max-rows` (30), `b-form-checkbox` booleano (8) y `b-form-radio` simples.
4. `.trim` (42) y `.number` (203): formatter propio que preserve el espacio interno; test de escritura real.
5. `b-input-group` (63): `append/prepend` → hijos directos (bloque coordinado con las 39 etiquetas `-append/-prepend`).
6. `switch` (50): CSS de `custom-switch` en el puente + prueba de estado.
7. `b-form-file` (16), `b-form-datepicker` (6, con `v-b-hover`), `b-form-textarea` con `max-rows` (5, con `v-b-visible`), grupos y `radio buttons` (8): al terminar se eliminan los parches de `platform/compat/bootstrap-vue.js` y `bootstrap-vue-forms.js`.
8. `b-skeleton-img` → `BPlaceholder`. Después: desinstalar `bootstrap-vue` y cortar a la hoja Bootstrap 5 (fase 5D), `vue-good-table-next` (5C), `vee-validate` 4, `vue-i18n` moderno.
