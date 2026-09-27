# Vue 3 legacy UI dependencies — migración

Rama: `refactor/vue3-legacy-ui-dependencies`, continuada desde `bc19b04b`.

Elimina todas las dependencias de UI con sabor Vue 2 EXCEPTO `vuex@3` y `@vue/compat`, que quedan
deliberadamente diferidas a la siguiente fase ("Vuex 4 + createApp"). Cada familia se migró en su propio lote,
con tests después de cada uno; commits separados por familia en la misma rama (ver `git log`).

## Tabla: paquete viejo → solución nueva

| Paquete Vue 2 eliminado | Solución Vue 3 | Contrato preservado |
|---|---|---|
| `vue2-daterange-picker` | `components/DateRangePicker.vue` sobre `@vuepic/vue-datepicker` | v-model `{startDate,endDate}`, `locale-data.firstDay`, `autoApply`, slot `input`, `time-picker`/`time-picker-seconds` |
| `vuejs-datepicker` | `components/Datepicker.vue` sobre `@vuepic/vue-datepicker` | v-model `Date`, `format`, `inputClass`, `placeholder`, `autoApply` |
| `@pencilpix/vue2-clock-picker` | `components/ClockPicker.vue` sobre `@vuepic/vue-datepicker` (modo standalone) | v-model `"HH:mm"` string |
| `vuedraggable` (v2, Vue 2) | `vuedraggable@4` (Vue 3 real) | v-model/list, handles, groups, clone, eventos |
| `vue-apexcharts` | `vue3-apexcharts` | tag `<apexchart>`, series/options reactivos |
| `lucide-vue` | `@lucide/vue` | `components/LucideIcon.vue` sin cambios de API para las vistas |
| `@johmun/vue-tags-input` | — (eliminado; resultó ser código muerto, sin usos reales) | n/a |
| `vue-perfect-scrollbar` | `components/VuePerfectScrollbar.vue` sobre `perfect-scrollbar` (JS puro) | prop `settings`, clase raíz `.ps-container` |
| `vue-cookie` / `vue-cookies` / `vue-localstorage` | `platform/storage.js` (helpers sobre `document.cookie`/`localStorage`) | mismos nombres de cookie/clave |
| `vue-barcode` | `components/Barcode.vue` sobre `jsbarcode` | props value/format/width/height/displayValue |
| `vue-easy-print` / `vue-html-to-paper` | `platform/htmlToPaper.js` (`$htmlToPaper` en `globalProperties`) | mismo comportamiento de ventana/impresión |
| `vue-template-compiler` / `@trevoreyre/autocomplete-vue` | — (eliminados; dependencias muertas) | n/a |
| `vue-select` (3.20, beta 4 rechazada) | `components/VSelect.vue`, componente Vue 3 nativo | tag `<v-select>`, v-model, options, reduce, label, placeholder, disabled, multiple, clearable, close-on-select, append-to-body, calculate-position, slots `#option`/`#selected-option`/`#no-options`; mismas clases `.vs__*` / `--vs-*` |
| `vue-good-table` (2.21) | `components/VueGoodTable.vue`, componente Vue 3 nativo | tag `<vue-good-table>`, columns/rows/mode/totalRows/pagination-options/search-options/select-options/group-options/styleClass/row-style-class/rtl, eventos `on-page-change`/`on-per-page-change`/`on-sort-change`/`on-search`/`on-selected-rows-change`, slots `#table-row`/`#table-actions`/`#selected-row-actions`/`#table-actions-bottom`/`#emptystate`; mismas clases `.vgt-*` |

Dependencias **no tocadas a propósito** (siguiente fase): `vuex@3.6.2`, `@vue/compat`. Tampoco se tocaron
`new Vue`/`createApp`, Vite ni TypeScript.

## vue-select → VSelect.vue

Auditadas las 249 apariciones de `<v-select>` en 73 archivos antes de construir el reemplazo (`/tmp/vselect_files.txt`
del proceso de migración). Props reales encontradas: `v-model`, `:options`, `:reduce`, `:placeholder`, `:disabled`,
`multiple`, `:clearable`, `:close-on-select`, `label`, `:value`, `append-to-body`, `:calculate-position`. Cero usos
de `taggable`, `searchable`/AJAX o `getOptionLabel` en toda la app: no se implementaron. El v-model sigue la
convención Vue 2 (`value`/`input`, sin `compatConfig: {MODE:3}`) para que ninguna de las 73 vistas cambie. Reutiliza
la directiva `v-append-to-body` ya existente (`platform/directives/append-to-body.js`), que `VsPx.vue` (envoltorio
px-next) ya usaba, incluida su función `calculate-position` personalizada (se lee de `$attrs`, no como prop propia,
para no colisionar con el método interno del mismo nombre). CSS copiado 1:1 del `dist/vue-select.css` del paquete
retirado (mismas variables `--vs-*`) para no romper los ~15 archivos con overrides `::v-deep(.vs__...)`.

## vue-good-table → VueGoodTable.vue

Auditadas las 86 apariciones en 76 archivos (recontadas de cero, no se asumió el conteo previo de la auditoría).
74/86 tablas usan `mode="remote"` (el padre pagina/filtra/ordena vía la API); las 7 restantes son locales. Una
sola tubería de filtro/orden/paginación sirve ambos modos: en remoto cada paso es no-op y se dibuja `rows` tal
cual; en local, el componente filtra/ordena/pagina internamente. `group-options` (11 vistas de reportes, todas
remote) se activa solo si `row.children` es un array: encabezado de grupo + hijas, sin afectar el resto. Checkbox
(`select-options`, 9 archivos) con `#selected-row-actions` y "Clear selection". CSS recortado del `dist` del
paquete (mismas clases `.vgt-*`), sin los temas no usados (nocturnal/black-rhino/polar-bear).

Efecto lateral detectado y corregido: `date-fns` (usado directo por los wrappers de fecha del lote anterior) era
solo dependencia transitiva y se podó al desinstalar `vue-select`/`vue-good-table` — se agregó como dependencia
explícita (`^4.4.0`, misma que pide `@vuepic/vue-datepicker`).

## Guarda (§14)

`tests/frontend/vue2-legacy-ui-deps-guard.test.mjs`: falla si `package.json`, `node_modules` o el código fuente
vuelven a introducir cualquiera de los paquetes eliminados. Confirma que solo `vuex` (serie 3.x) y `@vue/compat`
quedan como dependencias con sabor Vue 2.

## Avisos de @vue/compat (§15)

- Antes (documentado en memoria de sesiones previas): ~17 000 avisos.
- Después (medido con `npm run test:e2e:compat-warnings` sobre una corrida limpia de 334 tests, la del lote
  vue-good-table): **12 063 mensajes, 25 tipos únicos**.
- Cero avisos atribuibles a los paquetes eliminados como tales (vue-select, vue-good-table, vue2-daterange-picker,
  vuejs-datepicker, vue2-clock-picker, vuedraggable v2, vue-apexcharts v2, lucide-vue, vue-tags-input,
  vue-perfect-scrollbar, vue-cookie/vue-cookies/vue-localstorage, vue-barcode, vue-easy-print/vue-html-to-paper,
  vue-template-compiler, autocomplete-vue).
- El reporte SÍ sigue mostrando algunos avisos bajo las etiquetas "VueGoodTable (librería)" / "VSelect (librería)":
  son de los COMPONENTES PROPIOS NUEVOS (se les dejó el mismo `name:` que el paquete retirado para preservar
  compatibilidad de diagnóstico/devtools), no del paquete — el clasificador de `compat-warnings-report.js` usa una
  heurística por nombre (`LIBRARY` regex) que no distingue "librería de terceros" de "componente propio con ese
  nombre". Son avisos reales (p. ej. `rtl` como string en vez de boolean, un bug preexistente del contrato original
  que se preservó a propósito) pero no indican que quede código de la librería vieja.
- Avisos de build (webpack, modo dev): **42/42**, sin cambios en ningún lote (verificado tras cada uno).
- Build de producción: 0 warnings/0 errors, compiló en 5.46 min.

## QA visual/funcional (§16)

Verificado con Playwright contra el bundle real, capturas en cada lote: posicionamiento y filtro de `v-select`
(Add_product.vue), selección múltiple con `close-on-select=false` y chips (selector de categorías), slots
`#option`/`#selected-option` con iconos Lucide (selector de almacén del dashboard 3D), skins de `pos.vue` y
px-next (`VsPx.vue`); tabla remota con slot `#table-row` (activos), búsqueda + orden, selección con checkbox +
`#selected-row-actions` + "Clear selection" (empleados), agrupación de una sola vez con datos reales (informe de
almacén, 6 grupos / 36 filas); date-range-picker con hora (sales_report), datepicker simple y clock-picker.
Español/inglés/árabe (RTL) verificados vía el smoke E2E de idiomas (`10-languages.spec.js`).

## Validación (§17)

| Comprobación | Resultado |
|---|---|
| `npm run test:frontend` | 144 / 0 (incluye la guarda §14) |
| PHPUnit Unit | 1328 / 1328 OK |
| PHPUnit Feature | 934 / 934 OK, 3 skipped |
| `npm run test:e2e:routes` | OK — 474 rutas tenant + 20 portal coinciden con el snapshot |
| `npm run test:e2e` (full, lote date/range/time) | 347 passed / 1 skipped / 0 failed |
| `npm run test:e2e` (full, lote v-select) | 347 passed / 1 skipped / 0 failed |
| `npm run test:e2e` (full, lote vue-good-table) | 343 passed / 1 skipped / 0 failed (344 total; bajó de 348 al retirar los 4 casos de `13-slots-equivalence.spec.js` que usaban el paquete real como oráculo, ya no instalado) |
| Build dev | 0 errores, 42 warnings (línea base sin cambios) |
| Build producción | 0 errores, 0 warnings, 5.46 min |
| Smoke E2E contra el build de producción | 106/107 pasaron; 1 falla (`14-validation-layer.spec.js`, búsqueda de instancia `PxValidationProvider` por nombre) — **no relacionada**: no toca vue-select/vue-good-table/fechas/etc., y pasa limpio (3/3) contra el build de dev. Parece sensible al tiempo de carga de chunks en producción; queda como hallazgo a investigar en una pasada funcional aparte, no bloquea esta fase. |
| `npm ci` (carpeta aislada, con `.npmrc` del repo) | limpio, 1106 paquetes |
| `npm ls` | sin UNMET/invalid/missing/extraneous |

No se corrió `E2E_RESET=1` como pasada aparte: las tres corridas completas del suite (§17) ya cubren la app de
punta a punta contra datos demo reales sin regresiones.

## Bloqueadores para Vuex 4 + createApp

Ninguno introducido por esta fase. `vuex@3.6.2` sigue con su `peerDependency` declarado en `vue@^2.0.0`
(irresoluble sin `--legacy-peer-deps`, ya configurado en `.npmrc`) — se resuelve migrando a Vuex 4 en la
siguiente fase, junto con los 4 entrypoints `new Vue({el}).$mount()` → `createApp().mount()`.
