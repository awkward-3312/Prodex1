# Bootstrap 5 + BootstrapVueNext — fase 4

Rama `refactor/bootstrap5-bootstrapvue-next-phase4`, desde `52a3380` (fase 3). Objetivo: sacar de BootstrapVue 2 (BV2) la dependencia estructural de los dominios críticos (ventas/compras, transferencias, ajustes/mermas, inventario, pagos, caja, POS): `$bvToast` y `$bvModal` a 0, `<b-modal>` declarativo y `b-table` a BootstrapVueNext (BVN), y decidir `vue-good-table`. Sin cambios de lógica de negocio ni de backend.

## 1. Línea base confirmada (antes de tocar nada)

`$bvToast` 81 · `$bvModal` 96 · `<b-modal>` 101 etiquetas en 52 vistas (102 en la medición de la fase 3, que contaba una mención en un comentario) · `<b-table>` 19 BV2 (18 `b-table` + 1 `b-table-simple`; la fase 3 contaba 17) · `<b-*>` BV2 5.084 en 185 archivos · formularios BV2 2.100 · `CUSTOM_DIR` `false` · E2E 165/165 · Unit 1.328 · Feature 934 (3 skipped) · rutas 474 / 20 · frontend 118/118.

## 2. Cobertura añadida ANTES de migrar (por dominio)

Regla de la fase: ningún dominio se migra sin prueba previa que no dependa de la implementación.

| Spec | Qué prueba | Dominio | Grabada sobre |
|---|---|---|---|
| `29-modals-matrix` | Los **101 modales declarativos** (37 pantallas): se abren por id con `modals.show`, se cierran con `modals.hide`; se compara con lo grabado (`tests/e2e/data/modals-opened.json`): abre / título / tamaño / centrado / scrollable, sin backdrop residual, sin `modal-open`. 82 ids grabados, **72 abren en frío** (los otros 10 dependen de datos o de una ruta con permiso: `open_scan` en edición de ajustes/venta/traslado/merma, `form_Update_Detail` en edición de venta/traslado, `contract-template-modal`, `pdf-preview-modal`, `Note_Modal`; se comprueba que siguen sin abrir en frío). | todos (POS, ventas, traslados, ajustes, mermas, inventario, personas…) | **BV2** |
| `31-tables-matrix` | Los **patrones de `b-table`** con datos deterministas (mock de API o datos asignados a la instancia): firma del `<table>` (clases `table-*`, envoltorio responsive, cabeceras, filas, texto, `aria-busy`) + captura por tabla; orden local asc/desc con `aria-sort`; Shopify (módulo sin acceso en el tenant E2E → API simulada). | ventas de cliente, libro mayor, ajustes de sistema, facturas de suscripción, contratos, cocina, WooCommerce, Shopify | **BV2** (la firma; Shopify y el orden local sobre BVN) |
| `30-critical-domains-services` | Traslados/ajustes/mermas: enviar vacío → toast de error (variante, título, una sola instancia, se cierra solo) y escáner por id; POS: +/−/eliminar línea, confirmación «vaciar carrito» (modal por id, «Sí» y ESC), cerrar el modal de pago con la X y reabrirlo reiniciado (`$refs.paymentModal.show/hide` + `@hidden`), **cobro en efectivo** → venta creada, factura abierta por id y cerrada. | POS, traslados, ajustes, mermas | BVN (complementa a 29; los flujos POS previos ya estaban en 06–09) |

Ya existían (fases 1–3) y siguen verdes: 05 inventario/traslados, 06 POS (SKU + Enter, carrito), 07 caja (abrir/cerrar), 08 offline + sincronización, 09 pago mixto, lote y serial, 24 servicios de plataforma.

Límite honesto: `30` no pudo grabarse sobre BV2 (se escribió con los modales ya en BVN); la protección de los modales POS frente a BV2 es la matriz 29 + los 165 E2E previos, que pasan sin cambios salvo el ajuste de `closeTopModal` (`.btn-close` además de `.close`).

## 3. `$bvToast` y `$bvModal` → 0

- **`$bvToast` 81 → 0** (≈70 archivos): `this.$bvToast.toast(msg, opts)` → `notifications.notify(msg, opts)`; `this.$bvToast && …` → `notifications.hasDriver()` / sin guarda (siempre existió en BV2). Opciones conservadas (variante, título, `solid`, `autoHideDelay`, `noAutoHide`, `toaster` → posición, callbacks) por `toastProps()` del driver de la fase 3.
- **`$bvModal` 96 → 0**: `show/hide` → `modals.show/hide` (script) y `$modals.show/hide` (plantilla); `msgBoxConfirm` → `confirm(..., { presentation: 'modal' })`. En POS quedaban patrones defensivos (`this.$bvModal && this.$bvModal.show && …`, `typeof this.$bvModal.show === 'function'`, `this.$bvModal?.…`): se reemplazaron por la llamada directa (BV2 siempre lo instalaba).
- Guardia: `tests/frontend/bootstrap5-phase2.test.mjs` ya no tiene la lista de dominios exentos; falla si **cualquier** archivo propio (views, components, containers, mixins, utils, layouts, store, routes) usa `$bvToast`/`$bvModal` fuera de comentarios. Solo quedan menciones históricas en comentarios de `platform/`.
- Corrección de un defecto de la fase 3: el driver de `modals.show` llamaba a `useModal().show(id)`, que solo resuelve modales de la pila/orquestador; con `<b-modal>` de BVN declarativo no abría nada. Ahora resuelve por el registro: `useModal().get(id).show()/hide()`. (En la fase 3 no había ningún modal declarativo de BVN, por eso no se vio.)

## 4. `<b-modal>` declarativo → BVN (101 → 0 BV2)

Wrapper `BModal` en `platform/bootstrap/index.js`. Contrato de BV2 conservado:

- `id`, `title`, `size`, `centered`, `scrollable`, `modal-class`, `body-class`, `ok-only/ok-title/ok-variant/ok-disabled`, `no-close-on-backdrop/esc`, `visible` y `v-model`; eventos `@show/@shown/@hide/@hidden/@ok/@cancel` con `preventDefault()` (`@ok.prevent` funciona).
- Renombres de BVN: `hide-footer` → `noFooter`, `hide-header` → `noHeader`, `hide-header-close` → `noHeaderClose`, `static` → `teleportDisabled`.
- **Ciclo de vida**: BV2 solo renderiza el contenido mientras el modal está abierto y lo destruye al cerrar (los formularios se reinician, `mounted` se repite); BVN monta siempre. Por defecto `lazy` + `unmountLazy` (la vista puede sobrescribirlos).
- **Foco** (hallazgo del CI, prueba intermitente `14-validation-layer` bajo carga): BVN mueve **siempre** el foco al contenedor del modal al terminar la animación (~300 ms), de modo que quien ya escribía en el primer campo lo perdía (y el `blur` reiniciaba los errores del proveedor de validación); BV2 lo movía solo si el foco no estaba ya dentro. El wrapper pasa `focus: false` y aplica la regla de BV2 en `shown`. Reproducido en local con 6 workers (2 de 14 fallaban) y corregido (16 repeticiones × 8 workers en verde); spec `30` cubre la regla. Es una diferencia de comportamiento real corregida, no un ajuste de la prueba.
- `$refs.x.show()/hide()/toggle()` expuestos (los usan `ModernPaymentModal`, `PosReturnModal` y `customers.vue`).
- Apertura por id: `modals.show/hide` → registro de BVN. **El ramal `bv::show::modal`/`bv::hide::modal` se eliminó** (no queda ningún consumidor de BV2); un id no montado es un no-op, como en BV2. `tests/frontend/bootstrap5-phase4.test.mjs` prohíbe `bv::…::modal`, `v-b-modal` y `msgBox*`.
- Migración por codemod (52 vistas, incluidos POS, caja, pagos, ajustes, transferencias, ventas): importa y registra `BModal` de `@/platform/bootstrap`. Las plantillas no cambian (`<b-modal>` resuelve al registro local). Test estático: toda vista con `<b-modal>` importa y registra el wrapper.

**Restos de BV2 en modales: ninguno.** Lista exacta de `<b-modal>` de BV2 restantes: vacía.

### Diferencias visuales de cabecera (corregidas en el puente, `_bridge.scss`)

BVN teletransporta el modal a `<body>` y pinta `btn-close` (SVG) en lugar del `.close` `×` de BS4. Se reproduce el aspecto de BV2: glifo `×` (1.2195rem, 700, opacidad .5, relleno/margen negativo de `.close`), título 1rem/700 (regla de `.prodex-ui .modal-title`, que un modal en `<body>` no alcanza), y en móvil la cruz absoluta de `_globals.scss` (`button.close`). Para `modal-class="px-next"` (POS) se replican las reglas de `.close` también en `.btn-close`.

Se probó `teleportDisabled` por defecto (modal dentro de la vista, como se supuso al ver los estilos `.prodex-ui`): **rompía 170 de 216 capturas** (controles con otro tamaño, layouts) y se descartó.

Hallazgo: `posKeyboardShortcuts.js` inyecta CSS con selectores `#OpenRegisterModal___BV_modal_header` etc. (**sin** guion bajo final). Los ids reales de BV2 terminan en `_` (`…_header_`), así que esas reglas nunca se aplicaron (código muerto en BV2 y en BVN); se dejan como están para no cambiar el aspecto. El único que sí coincidía era `…___BV_modal_outer_ .modal-dialog` (margen 12 px en móvil): se convirtió a `#Id .modal-dialog`. `_interactions.scss`/`_modal_overrides.scss` referencian ids de modales HRM ya inexistentes (muertos).

## 5. Tablas: `b-table` BV2 19 → 0

Migradas por patrón, con el wrapper `BTable` (+ `BTableSimple`, `BThead`, `BTbody`, `BTr`, `BTh`, `BTd`):

| Patrón | Dónde | Resultado |
|---|---|---|
| `small` + `responsive="sm"` + `show-empty` + `empty-text` + slots de celda | sesiones (`system_settings`) | firma igual |
| `small striped responsive` sin slots | 3 tablas del informe de no mapeados (WooCommerce) dentro de un modal | igual |
| `bordered responsive`, `fields` dinámicos, slots | facturas de suscripción | igual |
| `striped hover responsive` + `busy` + `#table-busy` + `sortable` | detalle de cliente (4) | igual; orden local asc/desc y `aria-sort` verificados |
| `striped hover responsive small` + `head-variant="light"` + `class` | libro mayor (4) | ver abajo |
| `small` / `small striped hover` | contratos (tareas, plantillas) | igual |
| `b-table-simple` + `b-thead/b-tbody/b-tr/b-th/b-td` | cocina | igual |
| `striped hover responsive` + slots | Shopify tiendas y registros | contrato comprobado (sin firma previa, ver §2) |

Diferencias y decisiones:

1. `head-variant="light|dark"`: BV2 emite `thead.thead-light` (lo estila la base BS4); BVN emite `table-light` (otro gris). El wrapper pasa `thead-<v>` y no `headVariant`.
2. `busy`: BV2 quita `table-hover`; BVN lo deja y añade `b-table-busy` (ambos `aria-busy="true"`). Sin diferencia visible; se normaliza en la firma.
3. Etiquetas de cabecera en inglés de los `fields` (`Action`, `Name`, `Ref`, `Due`, `Status`): BV2 las anida en un `<div>`; BVN las pone directamente en el `<th>` y `utils/spanishUiGuard.js` (que traduce nodos de texto bajo `TH`) ahora las traduce (`Acción`, `Nombre`…). Es un cambio visible menor y coherente con el resto de la interfaz; las capturas con diferencias (0,2–3,4 %) son solo eso.
4. Con `class`+`responsive` BVN pone la clase en el envoltorio `div.table-responsive`, BV2 en el `<table>`. Ninguna hoja de estilos usada depende de ello (las reglas son descendientes: `.logs-table thead th`, `.table-modern`, `.km-table`).
5. `settings/woocommerce/StatusOverviewTab.vue` no se monta en ninguna ruta (código muerto); se migró igual (mismo patrón que `LogsTab`), sin prueba de pantalla.

Capturas de tabla BV2 → BVN: 18 tablas, 12 idénticas (0,000 %), 6 con diferencias por la traducción del guard (§ punto 3).

## 6. `vue-good-table`: decisión A (mantener) y plan de la fase 5

Auditoría:

- **73 archivos, 86 `<vue-good-table>`**; `mode="remote"` en 66 archivos; `pagination-options` 73; `search-options` 64; `@on-sort-change` 54; `@on-page-change` 66; slots `#table-row` 67 y `#table-actions` 42; `#selected-row-actions` 10 (`select-options` 9); `group-options` 11; `sort-options` 1; `row-style-class` 1; `theme=` 4.
- v2.21.11 es **solo Vue 2** (render con `h`, `$listeners`). Corre hoy bajo `@vue/compat` MODE 2 (73 pantallas en E2E), con los avisos `RENDER_FUNCTION`/`PRIVATE_APIS`/`COMPONENT_FUNCTIONAL` atribuibles a terceros (§ 9). Bloqueo para Vue 3 puro: no existe build de Vue 3 de esa versión; la línea Vue 3 es `vue-good-table-next` (paquete distinto, API muy parecida pero con cambios en slots y en el evento de ordenación).
- Opción B (BTable de BVN): no tiene modo remoto con paginación/búsqueda/ordenación server-side integrada, ni selección con acciones ni agrupación: obligaría a reescribir 73 pantallas de lista.
- Opción C (`vue-good-table-next`): es la sustitución de menor riesgo, pero son 73 archivos con contrato de slots y eventos.
- Opción D (`PxTable` propio): la más limpia a largo plazo; coste alto.

**Decisión: A, mantener en la fase 4.** No es simple ni seguro (73 pantallas de lista y reportes críticos). **Plan de la fase 5** en §16.

## 7. Formularios críticos

**Ninguno migrado en la fase 4** (decisión deliberada): el alcance ya incluía POS, cinco dominios, 101 modales, `$bvToast`/`$bvModal` y las tablas, y la regla es no migrar controles sin equivalencia demostrada. Los formularios BV2 siguen en 2.100 etiquetas; los no-triviales (`.trim`, `switch`, `file`, `select multiple`, grupos, datepicker, `input-group`) permanecen como en la fase 3.

## 8. POS

Solo se retiraron dependencias de BV2 (sin refactor ni división de `pos.vue`): 15 `<b-modal>` → `BModal`; ~42 usos de `$bvModal`/`$bvToast` → servicios. Cobertura: 06 (carga, catálogo, SKU/escáner), 07 (caja), 08 (offline + sync), 09 (mixto, lote, serial), 30 (líneas, confirmación por id + ESC, modal de pago reiniciable, cobro en efectivo, factura), 29 (15 modales POS por id, incl. apertura/cierre de caja, cliente rápido, factura, detalle de línea). Sin `pageerror`. Sin cambios de lógica.

## 9. Avisos de `@vue/compat`

Suite completa: **32.019 mensajes, 32 únicos, 213 tests** (fase 3: 25.351, 35, 156). Por test: **162,5 → 150,3 (−7,5 %)**. Los absolutos suben porque hay 57 tests más que recorren más pantallas; ningún aviso se silenció.

| Aviso | Fase 3 (total / por test) | Fase 4 (total / por test) |
|---|---:|---:|
| `PRIVATE_APIS` | 6.364 / 40,8 | 7.589 / 35,6 |
| `RENDER_FUNCTION` | 2.284 / 14,6 | 2.743 / 12,9 |
| `COMPONENT_FUNCTIONAL` | 862 / 5,5 | 1.103 / 5,2 |
| `OPTIONS_BEFORE_DESTROY` | 7.531 / 48,3 | 9.325 / 43,8 |
| `INSTANCE_EVENT_HOOKS` | 48 / 0,31 | 79 / 0,37 |
| `INSTANCE_EVENT_EMITTER` | 243 / 1,56 | 236 / 1,11 |
| `CUSTOM_DIR` | 0 | 0 |
| `PLUGIN_VUE2_ONLY` | 0 | 0 |

`INSTANCE_EVENT_HOOKS` sube por tests nuevos que abren más pantallas con `vee-validate`/`vue-select` (hooks `hook:`); no lo produce código migrado.

**Atribución exacta** (spec 22, `app.config.warnHandler` con la instancia que emite cada aviso; páginas con BVN vs páginas con BV2):

- Componentes de **BVN**: 0 avisos de contrato de Vue 2 (`PRIVATE_APIS`, `RENDER_FUNCTION`, `COMPONENT_FUNCTIONAL`, `INSTANCE_LISTENERS`, `INSTANCE_EVENT_*`, `CUSTOM_DIR`), también con los 101 `BModal` y las 20 `BTable` nuevos. Solo aparece `OPTIONS_BEFORE_DESTROY`, y es el mixin global de `vue-i18n` 8 (ningún componente de BVN declara `beforeDestroy`).
- Páginas con **BV2** (listas de productos, ajustes, clientes): `PRIVATE_APIS` 4, `RENDER_FUNCTION` 4, `INSTANCE_SCOPED_SLOTS` 3, `INSTANCE_LISTENERS` 2, `OPTIONS_DATA_MERGE` 2, `ATTR_FALSE_VALUE` 1 atribuidos a BV2.
- **vee-validate 3** (`PxValidationObserver/Provider`): `RENDER_FUNCTION`, `INSTANCE_LISTENERS`, `INSTANCE_SCOPED_SLOTS`, `RENDER_PROPERTY_UNDEFINED` (`_resolvedRules`).
- **vue-i18n 8**: `OPTIONS_BEFORE_DESTROY` (mixin global, la mayor parte de los 9.325), y `GLOBAL_PROTOTYPE`.
- **vue-good-table 2.21**: `RENDER_FUNCTION`, `WATCH_ARRAY` (996), `INSTANCE_SET`, `RENDER_PROPERTY_UNDEFINED`, prop `rtl` mal tipada (68).
- Otros terceros: `vue-select` (`INSTANCE_EVENT_EMITTER`, `COMPONENT_V_MODEL`), `VuePerfectScrollbar` y `LucideIcon` (`RENDER_FUNCTION`/`COMPONENT_FUNCTIONAL`), `vue-router` 4 (`RouterView`, `TRANSITION`), `vue-sweetalert2` (`PROVIDE_OUTSIDE_SETUP`).

Migrar los 101 modales y las 20 tablas de BV2 quitó de BV2 sus avisos de esas familias (`BModal`/`BTable` de BV2 emitían `RENDER_FUNCTION`, `PRIVATE_APIS`, `COMPONENT_FUNCTIONAL`, `INSTANCE_SCOPED_SLOTS`), coherente con la bajada por test.

## 10. Adaptador y parches de BV2

- `platform/adapters/bvn.js`: eliminado el ramal `bv::show::modal`/`bv::hide::modal` y el `rootVm.$root.$emit` (−12 líneas útiles; el driver de modales es ahora 2 líneas sobre el registro de BVN). `platform/adapters/vue2.js`: sin cambios (SweetAlert2 sigue siendo el `confirm` por defecto; `$modals`/`$platform`).
- `platform/compat/bootstrap-vue.js` (50 líneas): **se mantiene íntegro**. Sus dos parches siguen teniendo consumidores reales: (a) asignador `onUpdate:modelValue` vacío para `BFormSelect/BFormCheckbox/BFormRadio/BFormTags` de BV2 (formularios BV2 que quedan: 2.100 etiquetas); (b) hooks de Vue 3 para las directivas internas `v-b-visible` (`BFormTextarea` con `max-rows`) y `v-b-hover` (`BFormDatepicker`). Se eliminarán cuando esos componentes migren (fase 5).
- Nuevo en el puente de BS5 (`bootstrap5/_bridge.scss`): cabecera de modal (cruz `×`, título, cruz móvil). Nada que retirar del puente todavía.

## 11. CSS Bootstrap 5

Solo se tocó el puente de modales (§4). Sin codemod masivo. Las clases BS4 de las vistas migradas no cambiaron. Conteos (herramienta de fase 3): direccionales 1.060 (sin cambios), `badge-*`, `custom-*`, `input-group-append/prepend`, `sr-only`, `close`, `form-row` sin cambios (no se migró ningún formulario).

## 12. Visual QA

Modales: 72 modales que abren en frío × 3 vistas (LTR, RTL, móvil 390 px) = **216 capturas** BV2 vs BVN (`tests/e2e/visual/modals.js` + `compare.js`, tolerancia de canal 12).

- Diferencia máxima **3,8 %** (móvil, guía de atajos), 1,7 % en escritorio; **62 capturas idénticas** (≤ 0,05 %) y el resto por un desplazamiento de **1 px** del texto interior (subpíxel de la altura de la cabecera), revisado a ojo en pares representativos (Cerrar caja, Cliente rápido, Detalle de línea, Pay Due, Nuevo almacén, guía de atajos): mismo layout, mismos controles.
- Corregidos durante la fase (tras la primera captura): título del modal (1rem/700), cruz `×` (tamaño, posición, móvil), cabecera sin título (POS/ventas/traslados), margen de diálogo. La primera pasada tenía 18 capturas con diferencias > 0,05 % en cabecera; el intento de renderizar en la vista las empeoró a 170 y se revirtió.
- Tablas: 18 capturas, 12 idénticas, 6 con el texto de cabecera traducido (§5).
- LTR/RTL: la cruz usa propiedades lógicas; RTL verificado en las 72 capturas.

## 13. Pruebas

- `npm run test:frontend`: **123/123** (118 + 5 nuevos en `bootstrap5-phase4.test.mjs`: registro del wrapper en toda vista con `<b-modal>`/`<b-table>`/`<b-table-simple>`, sin `bv::…::modal`/`v-b-modal`/`msgBox*`, driver por registro, `lazy+unmountLazy` y renombres del `BModal`, `head-variant` → `thead-*`; y la guardia `$bvToast`/`$bvModal` sin exenciones).
- Unit **1.328/1.328** (se actualizó `PosAuxiliaryModalVisualConsistencyArchitectureTest`: `this.$bvModal.show("Quick_Add_Customer")` → `modals.show("Quick_Add_Customer")`), Feature **934 OK (3 skipped)**, route snapshot **474 tenant / 20 portal**.
- E2E completo: **221 pasan, 1 skipped preexistente (popover BVN de la spec 26), 0 fallos** (222 tests, con los 2 de `setup`: los 165 previos + matriz de modales + 12 de tablas + 11 de dominios críticos), también tras `E2E_RESET=1`.
- Builds: desarrollo OK (mismos 42 avisos de compilación), producción OK, `npm ci` limpio.

## 14. Métricas antes (fase 3) → después

| Métrica | Antes | Después |
|---|---:|---:|
| `<b-*>` BV2 | 5.084 (185 archivos) | 4.956 (182) |
| `<b-*>` BVN | 623 (57) | 751 (97) |
| Formularios `<b-form-*>` BV2 / BVN | 2.100 / 493 | 2.100 / 493 |
| `$bvToast` | 81 | 0 |
| `$bvModal` | 96 | 0 |
| `<b-modal>` BV2 / BVN | 101 / 0 | 0 / 101 |
| `<b-table>` BV2 / BVN | 18 (+1 simple) / 1 | 0 / 20 (+1 simple) |
| `vue-good-table` | 73 archivos / 86 | 73 / 86 (sin cambios) |
| Parches en `compat/bootstrap-vue.js` | 2 grupos | 2 grupos (con consumidores) |
| Código del adaptador con BV2 | ramal `bv::*::modal` (≈12 líneas) | 0 |
| `CUSTOM_DIR` | `false` | `false` |
| `main.min.js` prod | 2.780.532 B | 2.782.924 B (+2.392, +0,09 %) |
| `login.min.js` prod | 1.083.785 B | 1.083.825 B (+40) |
| `portal` / `customer-display` / `storefront` | 308.871 / 361.738 / 82.249 | igual |
| Archivos JS (chunks) | 455 (427 en `bundle/`) | 455 (427) |

## 15. CI

PHP 8.4, servidor embebido de un solo proceso, sin bucle de reinicio y sin `continue-on-error`; el paso de diagnóstico (`gdb`/`dmesg`/core) **se mantiene** (no se acumulan aún ~10 ejecuciones limpias desde el arreglo de PHP). Se añadió la rama `refactor/bootstrap5-bootstrapvue-next-phase4` a `frontend-safety-net.yml` (push + condición del job `e2e`). Evidencia: el primer run (`35536212761`, commit `8e9b961`) falló en `e2e` por una prueba intermitente real (`14-validation-layer`, ver el hallazgo de foco en §4; 220 pasan, 1 falla, con un reintento); tras el arreglo (`9f5cb11`), **dos ejecuciones consecutivas del mismo commit** (run `35538792732`, intento 1 y intento 2) con `route-snapshot` y `e2e` en verde. Solo cambia la documentación después de `9f5cb11`.

## 16. Superficie restante de BootstrapVue 2 y plan exacto de la fase 5

**Superficie** (4.956 etiquetas en 182 archivos): `b-col` 1.351, `b-form-group` 846, `b-button` 577, `b-form-input` 536, `b-row` 317, `b-form-invalid-feedback` 308, `b-card` 306, `b-form` 123, `b-badge` 83, `b-form-select` 69, `b-input-group` 63 (+ append/prepend 39), `b-form-checkbox` 58, `b-alert` 46, `b-tab(s)` 53, `b-form-textarea` 35, `b-form-file` 18, `b-dropdown(-item)` 35, `b-progress` 9, `b-form-radio-group` 8, `b-pagination` 7, `b-form-datepicker` 6. Más: `vue-good-table` (73), `vee-validate` 3, `vue-i18n` 8, Vuex 3, y las directivas internas `v-b-visible`/`v-b-hover`.

**Bloqueos para desinstalar BV2**: (1) 2.100 etiquetas de formulario (críticas, `.trim`, `switch`, `file`, `select multiple`, `input-group`, datepicker); (2) layout (`b-row/b-col/b-card/b-button/b-badge`) — hay wrappers para button/badge/card/row/col pero no se aplicaron en masa; (3) `b-tabs`, `b-dropdown`, `b-pagination`, `b-progress`, `b-alert`; (4) `v-b-visible`/`v-b-hover` de BV2 (textarea `max-rows`, datepicker); (5) `@vue/compat` sigue necesario por vue-good-table, vee-validate 3, vue-i18n 8, vue-select, vue2-daterange-picker, lucide-vue (Vue 2).

**Fase 5 (orden por riesgo):**
1. Layout y contenedores por codemod verificado con captura: `b-row/b-col/b-container/b-card*` → wrappers ya existentes (1.351 + 317 + 306 etiquetas); ola por dominio con captura LTR/RTL/móvil.
2. `b-button`/`b-badge`/`b-alert`/`b-progress`/`b-link` restantes (wrappers existentes).
3. `b-tabs`/`b-tab`, `b-dropdown`, `b-pagination` a BVN con prueba por patrón (como esta fase).
4. Formularios: `.trim` con formatter propio, `custom-switch` en el puente, `input-group` append/prepend → hijos directos, `b-form-file`, datepicker, `select multiple`; al migrar `BFormTextarea`(max-rows)/`BFormDatepicker` se elimina `v-b-visible`/`v-b-hover` y con ellos el parche de directivas de `compat/bootstrap-vue.js`; al migrar `BFormSelect/Checkbox/Radio/Tags` se elimina el asignador vacío.
5. `vue-good-table` → `vue-good-table-next` (o `PxTable`) detrás de un wrapper que conserve `columns/rows/mode="remote"/pagination-options/search-options` y los slots `#table-row`/`#table-actions`; migrar por familias de lista (reportes primero) con prueba de orden/paginación/búsqueda server-side.
6. Cuando el layout y los formularios estén en BVN: quitar el registro global de BV2 (`Vue.use(BootstrapVue)`), `bootstrap-vue` de `package.json` y el puente aditivo de BS5 (corte a Bootstrap 5).
7. Después: `vee-validate` 4, `vue-i18n` moderno, retirar `@vue/compat`.
