# Bootstrap 5 + BootstrapVueNext — fase 3

Base: `983fe3e` (fase 2). Vue 3.5.43 + `@vue/compat` (MODE 2 salvo BootstrapVueNext), Vue Router 4, Unhead. Esta fase mueve la infraestructura de UI global (toast, modal, confirmación en modal), `b-sidebar` + `v-b-toggle`, `v-b-tooltip` / `v-b-popover` y los `append-to-body` de terceros a BootstrapVueNext (BVN) / Vue 3, elimina `CUSTOM_DIR` y hace un piloto de `BTable`. No se toca vee-validate, Vuex, vue-i18n, Vite ni TypeScript; sin cambios de backend, rutas, permisos, tenancy ni lógica de negocio; sin corte global de la hoja Bootstrap 5 (el puente sigue siendo aditivo); POS, caja, pagos, inventario crítico y ventas/compras no se tocan.

## 1. `BApp` y el contexto de BootstrapVueNext

**Decisión: no se usa `<BApp>`; se usa su mecanismo equivalente oficial (`BOrchestrator` con el contexto de la aplicación).**

`BApp` es, literalmente, `BOrchestrator` + `useRegistry(rtl)` + `useProvideDefaults` + la ranura por defecto (un fragmento). Los registros (orchestrator, registry, rtl, defaults) ya los instala el plugin `createBootstrap` (`platform/bootstrap/plugin.js`). Lo único que faltaba era montar `BOrchestrator`, que es lo que pinta los toasts y modales programáticos. `platform/adapters/bvn.js` lo monta **una vez** en un contenedor de `<body>` con `vnode.appContext = app._context` (el mismo mecanismo con el que BVN monta sus propios tooltips), y obtiene `useToast()` / `useModal()` con `app.runWithContext`.

Por qué no `BApp` en `App.vue`:
- Envolver la raíz cambiaría el árbol de la raíz a un fragmento (hay clases/atributos de raíz en `App.vue`) y habría que repetirlo en cada entrypoint (`main`, `login`, portal, customer-display) para tener el orquestador en todos.
- Montar el orquestador en `<body>` no toca el DOM ni el layout del shell (verificado: capturas 0,00–0,12 % en el shell), no interfiere con Router 4 ni con Unhead, y funciona igual en cualquier entrypoint que instale la plataforma.
- **RTL**: el `dir` lo gobierna el atributo del documento (Unhead) con CSS lógico; el registro `rtl` de BVN solo lo leen `BFormRating` y `BFormSpinbutton` (no se usan). El `rtl` de `BApp` se lee una sola vez y no seguiría el cambio en caliente, así que `BApp` tampoco aportaba nada aquí.
- No hay infraestructura temporal: es la arquitectura final (`BOrchestrator` + plugin).

`login.js` instala ahora también el plugin (`platform/bootstrap/plugin.js`, módulo aparte para no arrastrar los componentes). La página de login que se sirve hoy es Blade (`auth-login.js`, sin Vue), así que el toast solo se usa en el flujo de olvidar/restablecer contraseña del bundle `login`.

## 2. Servicios de plataforma → BootstrapVueNext

Las vistas siguen llamando a `notifications.notify(...)`, `modals.show/hide(id)` y `confirm(...)`; cambia solo el driver.

| Servicio | Antes | Después |
|---|---|---|
| `notifications` (toast) | `rootVm.$bvToast.toast(msg, opts)` | `useToast().create(toastProps(msg, opts))` sobre `BToast` |
| `modals.show/hide(id)` | `rootVm.$bvModal.show/hide(id)` | registro de BVN si el id lo conoce (`useModal().show/hide`); si no (los `<b-modal id>` declarativos de BV2, 102 usos), los eventos `bv::show::modal` / `bv::hide::modal` de la raíz — el mismo mecanismo interno de `$bvModal`, sin usar `$bvModal` |
| `confirm({ presentation: 'modal' })` | `rootVm.$bvModal.msgBoxConfirm(msg, opts)` | `useModal().create({...})` sobre `BModal`; `true` / `false` / `null` (cerrado) |
| `confirm({ presentation: 'swal' })` | SweetAlert2 | sin cambios (`installSweetAlertConfirm`, ahora separado y sin DOM) |

Mapeo de opciones preservado (test `adapter-vue2.test.mjs` + E2E 24): `title`, texto, `variant`, `solid`, `autoHideDelay` (por defecto 5000 ms), `noAutoHide`, `toaster` (`b-toaster-top-right` → `top-end`…), auto-hide, cerrar con la cruz, posición superior derecha, `title`/`okTitle`/`cancelTitle`/`okVariant`/`size`/`centered`/`footerClass`. `Promise<boolean>` se mantiene. `$modals` y `$platform` (`{ notifications, modals, confirm }`) quedan en `app.config.globalProperties` para plantillas y pruebas (`@click="$modals.hide('id')"` sustituye a `$bvModal.hide`).

Aspecto (el toast de BVN es marcado BS5; se reproduce el `b-toast` de BV2 con el puente): paleta de las escalas `alert-*` de BS4 (bootstrap-vue.css está compilado con la paleta por defecto, no con los colores del tema), cabecera al 85 % y cuerpo al 58 %, posición **física** derecha (BV2 no se invierte en RTL), margen de 8 px, cruz pequeña.

## 3. Consumidores `$bvToast` / `$bvModal`

Migración mecánica (`this.$bvToast.toast(...)` / `this.$root.$bvToast.toast(...)` → `notifications.notify(...)`, `$bvModal.show/hide` → `modals.show/hide`, `if (this.$root && this.$root.$bvToast)` → `notifications.hasDriver()`, `msgBoxConfirm` → `confirmDialog(..., { presentation: 'modal' })`, `@click="$bvModal.hide(...)"` → `$modals.hide(...)`) en **todas las vistas fuera de POS, caja, pagos, inventario crítico y ventas/compras** (incluidas las pantallas de login/recuperar). Un test de guardia impide que vuelvan.

| | Antes | Después |
|---|---:|---:|
| `$bvToast` (menciones) | 179 en 138 archivos | **81 en 72** |
| `$bvModal` (menciones) | 138 en 34 archivos | **96 en 16** |

**Excepciones exactas (no es una diferencia de lifecycle sino la regla «no tocar»):** todo lo que queda está en `pos.vue` (42 menciones), `sales/*` (create/edit/change_to_sale), `transfers/*`, `adjustment/*`, `damage/*`, `inventory/*`, `products/count_stock` y `opening_stock_import`, `settings/warehouses` y `warehouse_locations`, `settings/cash_drawers`, `payment_methods`, `payment_gateway`, `pos_settings`, `pos_receipt`, `ModernPaymentModal`, `reports/Dead_Stock_Report` y las variantes `next/*` de esos dominios. Siguen usando el `$bvToast`/`$bvModal` de BV2 (que sigue instalado) y se migrarán con sus dominios en la fase 4; el swap es idéntico al ya hecho. Los 7 comentarios que mencionan `$bvToast` no cuentan.

## 4. `b-sidebar` → `BOffcanvas` y `v-b-toggle`

`b-sidebar`: 12 usos en 12 pantallas de lista con panel de filtros (tareas, proyectos, reservas, empleados, clientes, proveedores, ajustes/productos/traslados/daños clásicos, pagos de devoluciones de ventas y compras). Todas usan el mismo subconjunto (`id`, `title`, `right`, `shadow`, `bg-variant`, `sidebar-class`, slot por defecto), sin `visible`, `v-model`, ni eventos. Se sustituyen por el wrapper `BSidebar` de `platform/bootstrap` (BOffcanvas), y `v-b-toggle.<id>` por `vBToggle` de BVN (registro local).

Comportamiento de BV2 preservado: sin backdrop (`noBackdrop`), la página de fondo sigue interactiva y con scroll (`bodyScrolling`), sin trampa de foco (`noTrap`), ESC y cruz cierran, mismo botón alterna, 320 px, posición derecha física (también en RTL), se renderiza en su sitio (`teleportDisabled`: las reglas `.prodex-ui …` de la capa de diseño deben alcanzarlo — sin esto el aspecto se rompía), clases `b-sidebar-header` / `b-sidebar-body` para que las reglas de diseño existentes sigan aplicando, se desmonta con la vista. En móvil la cabecera invierte el orden (medido en BV2).

`v-b-toggle`: 12 → 0 BV2. `b-sidebar`: 12 → 0 BV2.

## 5. Tooltips y popover

`v-b-tooltip` restantes (53 usos en 16 archivos, incluidas las vistas críticas) y `v-b-popover` (1) pasan a `vBTooltip` / `vBPopover` de BVN (registro local por vista, hooks de Vue 3). Modificadores usados: `.hover`, `.hover.top`, `.hover.bottom`. Verificado en navegador (E2E 26): hover, contenido, `title` nativo retirado (sin doble tooltip), placement superior, flecha, oculto sin capturar clics (`visibility`/`pointer-events`), desmontaje al navegar, RTL centrado sobre el disparador. `v-b-tooltip`: BV2 53 → 0, BVN 63 → 116; `v-b-popover`: BV2 1 → 0.

## 6. Terceros con `appendToBody` (sin parchear `node_modules`)

`vue-select` y `vue2-daterange-picker` traen la misma directiva `v-append-to-body` con hooks de Vue 2 (`inserted` / `unbind`, `vnode.context`).

Solución mínima (opción A): directivas de Vue 3 en `platform/directives/append-to-body.js` (`mounted` / `unmounted`, `binding.instance` = el componente de la librería; misma lógica: mover el desplegable a `<body>`, posicionarlo con `calculatePosition`, deshacerlo al desmontar) y wrappers que copian el componente con esa directiva: `platform/compat/vue-select.js` (registrado como `v-select` en `main.js`) y `platform/compat/daterange-picker.js` (alias de webpack `vue2-daterange-picker$`, así las 33 importaciones de las vistas no cambian). Detalles no obvios: los paquetes son CommonJS con `exports.default` (`import X` devuelve el objeto de exports completo), y `extends:` falla bajo compat con los `mixins` de Vue 2, por eso se usa spread.

Verificado con la pantalla real que usa `append-to-body` (`/app/products/store`, `VsPx`): el menú sale a `<body>`, se posiciona bajo el disparador con su ancho, conserva la distancia al disparador tras hacer scroll, se retira al cerrar y al desmontar. (`vue2-daterange-picker` no recibe `append-to-body` en ninguna vista de la app: la directiva es inerte ahí; el test comprueba que el calendario abre, cierra y desmonta.)

## 7. `CUSTOM_DIR` — eliminado

`configureCompat({ MODE: compatModeFor, CUSTOM_DIR: false })`. Ojo: en MODE 2 las funciones de compat están **activas por defecto**; quitar `CUSTOM_DIR: true` no las desactivaba, hay que ponerlo a `false` para demostrar que nadie lo usa. Consumidores que había:

| Directiva | Archivo / dependencia | Resolución |
|---|---|---|
| `click-outside` | `PxSelect.vue`, `PxMenu.vue` | fase 2 (hooks de Vue 3) |
| `v-append-to-body` | `vue-select`, `vue2-daterange-picker` | wrappers de Vue 3 (§6) |
| `v-b-tooltip` / `v-b-toggle` / `v-b-popover` | BV2 en vistas | BVN (§4, §5) |
| `v-b-visible` (BFormTextarea con `max-rows`), `v-b-hover` (BFormDatepicker) | **internas de BV2** (`node_modules/bootstrap-vue/src/directives/visible|hover`) | se les añaden los hooks de Vue 3 (`mounted/updated/unmounted`) y se retiran las claves de Vue 2 en `platform/compat/bootstrap-vue.js` (sin `vnode.context`); desaparece con BV2 |

Resultado: avisos `CUSTOM_DIR` 86 → **0** en la suite completa y E2E 28 (15 pantallas con tooltips, sidebars, `append-to-body`, textareas y datepicker de BV2: 0 avisos y sin errores).

## 8. Formularios pendientes — no migrados (blockers demostrados)

Se dejaron para después de la infraestructura y ninguno cumple la regla «no migrar lo que no puedas demostrar equivalente»:
- `v-model.trim`: Vue 2 recorta el modelo **en cada `input`**; BVN al **perder el foco**. Con BVN un watcher o la validación ven el valor sin recortar hasta el `blur` (y un envío con Enter sin `blur` lo manda sin recortar). Recortar en cada `update:modelValue` haría desaparecer el espacio que el usuario está escribiendo entre palabras. Sin forma de conservar ambas semánticas → sigue en BV2 (16 vistas).
- `switch` en casilla: el BS4 vendorizado no tiene CSS de `custom-switch`, así que BV2 pinta algo sin definir; no hay referencia visual que igualar.
- `input-group-append/prepend`, `b-form-file`, `b-form-datepicker`, grupos de casillas/radios, `multiple`, `@input` en select, `:value`/`:checked`: sin cambios respecto a la fase 2 (blockers documentados y con test de guardia).

## 9. Piloto `BTable` — `settings/woocommerce/LogsTab`

Pantalla no crítica, tabla `small`, `responsive="sm"`, `thead-class`, `class`, `fields` con `label`, `items` (computed filtrado + paginado) y cinco slots `#cell(...)` (fecha con icono, acción, dirección, estado con `b-badge`, mensaje). **Resultado: migrada sin ningún shim** (solo el marcado MODE 3 por `__name`), `BTable` exportado tal cual desde `platform/bootstrap`.

Auditoría (con datos idénticos por mock de `woocommerce/logs`, BV2 vs BVN):

| Aspecto | Resultado |
|---|---|
| fields, items, scoped slots de celda, `formatter` por slot | idéntico |
| `small`, `responsive="sm"`, `thead-class` | idéntico (`table-responsive-sm`, `table-sm`, `thead.logs-table-header`) |
| `class` | va al contenedor responsive (igual que BV2): las reglas de la vista siguen aplicando |
| paginación externa (`b-pagination` BV2 + computed), filtro `v-select` | idéntico: los items cambian sin duplicar filas |
| estado vacío / sin filas | idéntico (no usa `show-empty`) |
| sort, `busy`, row click, selección, `sticky`, `tbody-tr-attr` | **no usados por esta pantalla**: no auditados en runtime; hay que auditarlos pantalla a pantalla en la fase 4 |
| Capturas LTR / RTL / móvil | **0,00 %** de diferencia en la tabla (solo el ruido conocido del logo del encabezado) |

Estrategia de tablas propuesta para la fase 4: `BTable` sin wrapper para las tablas de solo lectura con slots (la mayoría de los 19 usos: LogsTab de Shopify, ProductsTab/StatusOverview de WooCommerce, contratos, `CustomerLedger`, `CustomerDetails`, detalles de suscripción); auditar sort/`busy`/selección solo donde se usen; `vue-good-table` (tablas de lista) es otra decisión.

## 10. Visual QA

60 capturas (20 pantallas/estados × escritorio LTR, RTL, móvil 390) antes/después con `tests/e2e/visual` (`screens-phase3.json`, `VISUAL_VARIANT=base|after`, la línea base es el build de la fase 2).

| Dominio | Resultado |
|---|---|
| Modal de confirmación (BV2 `msgBoxConfirm` vs BVN) | 0,02–0,07 % |
| 12 sidebars (LTR/RTL) | 0,06–0,12 % |
| 12 sidebars (móvil) | 0,22–0,47 %; pagos de devoluciones 2,4–3 % por los gráficos de la página (alturas de ApexCharts distintas entre capturas), no por el panel |
| Toasts (4 variantes) LTR/RTL | 0,65–0,79 % (cruz y sombra del `b-toast`); móvil ≈ 3 % (cruz) |
| Tooltip (productos clásico) | el bubble BS4 idéntico (fondo `#0a021e`, radio 4 px, 11,4 px, z 1070); la captura de página completa difiere por columnas visibles distintas de la tabla entre sesiones, no por el tooltip |
| `append-to-body`, daterange | 0,00–0,05 % |
| BTable piloto | 0,00 % |

Iteraciones reales (defectos encontrados con la captura y corregidos): sidebar sin las reglas `.prodex-ui …` (teleport a `<body>`), cabecera del sidebar (orden título/cruz por lado, móvil e RTL), colores del toast (paleta BS4 por defecto, no la del tema), toast en RTL (posición física), tamaños de la cruz.

## 11. Avisos de `@vue/compat`

Suite completa: **25.351 mensajes, 35 únicos, 156 tests** (fase 2: 21.114, 35, 118). Por test: 178,9 → 162,5 (−9 %). Antes → después (los absolutos suben porque hay 38 tests más que recorren más pantallas; ningún aviso se silenció):

| Aviso | Fase 2 | Fase 3 |
|---|---:|---:|
| `PRIVATE_APIS` | 5.298 | 6.364 |
| `RENDER_FUNCTION` | 1.971 | 2.284 |
| `COMPONENT_FUNCTIONAL` | 776 | 862 |
| `OPTIONS_BEFORE_DESTROY` | 5.882 | 7.531 |
| `INSTANCE_EVENT_HOOKS` | 39 | 48 |
| `INSTANCE_EVENT_EMITTER` | 277 | 243 |
| **`CUSTOM_DIR`** | 86 | **0** |
| `PLUGIN_VUE2_ONLY` | 0 | 0 |

Origen por el primer componente de la traza (aproximado: hay avisos sin traza; la atribución exacta por instancia está en el E2E 22 de la fase 2):

| Aviso | BV2 | vee-validate | vue-i18n (mixin global) / propio | terceros (vue-good-table, vue-select, scrollbar, lucide, router…) | sin traza |
|---|---:|---:|---:|---:|---:|
| `PRIVATE_APIS` | 613 | 157 | 3.614 | 899 | 1.081 |
| `RENDER_FUNCTION` | 626 | 157 | 377 | 836 | 288 |
| `COMPONENT_FUNCTIONAL` | 465 | 17 | 118 | 262 | 0 |
| `OPTIONS_BEFORE_DESTROY` | 1.370 | 157 | 3.852 | 1.602 | 550 |
| `INSTANCE_EVENT_EMITTER` | 0 | 0 | 0 | 83 | 160 |
| `INSTANCE_EVENT_HOOKS` | 0 | 0 | 0 | 0 | 48 |

El grupo «propio/otros» de `PRIVATE_APIS` y `OPTIONS_BEFORE_DESTROY` es sobre todo el mixin global de `vue-i18n` 8 (`beforeDestroy`) aplicado a cada componente, incluidos los de BVN (verificado en la fase 2: ningún componente de BVN declara `beforeDestroy`). Los avisos de BVN son 0 para todos los de contrato de Vue 2.

## 12. Pruebas

- `npm run test:frontend`: **118/118** (el test del adaptador se reescribió: mapeo de toast/confirm y SweetAlert; nuevos: registro coherente de `BSidebar`/`vBToggle`/`vBPopover`, claves `components`/`directives` duplicadas, guardia de servicios, `CUSTOM_DIR: false`, wrappers de `append-to-body`).
- Unit **1.328/1.328**, Feature **934 OK (3 skipped)**, route snapshot **474 tenant / 20 portal**.
- E2E completo local: **165/165** (127 + 38 nuevos), también tras `E2E_RESET=1`.
  - 24 servicios de plataforma: 4 toasts (variante/título/texto/posición/una instancia), opciones y auto-hide, varios toasts, persistencia entre vistas, confirm aceptar/cancelar/ESC/textos, confirm ×4 sin residuos, `modals.show/hide`, `$modals.hide` en plantilla.
  - 25 offcanvas: 12 pantallas (abrir, cruz, ESC, alternar, sin backdrop, sin bloqueo de scroll, 320 px), contenido interactivo, desmontaje al cambiar de vista, RTL, móvil.
  - 26 directivas y terceros: tooltip en pantalla crítica, RTL, `append-to-body` (abrir, scroll, cerrar, desmontar, reabrir), daterange.
  - 27 BTable piloto: render, slots, paginación + filtro, vacío, móvil.
  - 28 `CUSTOM_DIR`: 0 avisos en 15 pantallas.
  - Sin `pageerror` / `console.error`; sin dobles emisiones (una petición / un toast / un modal por acción).
- Builds: desarrollo OK (mismos 42 avisos de compilación); producción OK; `npm ci` limpio.
- CI (PHP 8.4, un solo proceso, sin `continue-on-error`): ver §14.

## 13. Métricas antes (fase 2) → después

Por implementación real (una etiqueta importada localmente de `@/platform/bootstrap` cuenta como BVN).

| Métrica | Antes | Después |
|---|---:|---:|
| `<b-*>` BV2 | 5.097 (185 archivos) | 5.084 (185) |
| `<b-*>` BVN | 610 (49 archivos) | 623 (57) |
| Formularios `<b-form-*>` BV2 / BVN | 2.100 / 493 | 2.100 / 493 |
| `b-sidebar` BV2 / `BOffcanvas` (BSidebar) | 12 / 0 | 0 / 12 |
| `v-b-toggle` BV2 / BVN | 12 / 0 | 0 / 12 |
| `v-b-tooltip` BV2 / BVN | 53 / 63 | 0 / 116 |
| `v-b-popover` BV2 / BVN | 1 / 0 | 0 / 1 |
| `<b-table>` BV2 / BVN | 18 / 0 | 17 / 1 |
| `$bvToast` | 179 (138 archivos) | 81 (72) |
| `$bvModal` | 138 (34) | 96 (16) |
| `CUSTOM_DIR` | 86 avisos, `true` | 0 avisos, `false` |
| Clases BS4 direccionales | 1.060 (87 archivos) | 1.060 (87) (sin cambios: las vistas tocadas ya estaban limpias) |
| `main.min.js` prod | 2.654.695 B | 2.780.532 B (+125.837, +4,7 %) |
| `login.min.js` prod | 979.415 B | 1.083.785 B (+104.370; orquestador + BToast/BModal + Floating UI) |
| `portal` / `customer-display` / `storefront` | 308.871 / 361.738 / 82.249 | 308.871 / 361.738 / 82.249 |
| Archivos JS (chunks) | 455 (427 en `bundle/`) | 455 (427) |

## 14. CI

Sigue en PHP 8.4, servidor embebido de un solo proceso, sin bucle de reinicio silencioso y sin `continue-on-error`; el paso de diagnóstico (`gdb bt`/`dmesg`) se mantiene. Runs finales: ver el commit de cierre del historial de la rama.

## 15. Superficie restante de BootstrapVue 2

- 5.084 etiquetas BV2 en 185 archivos; 2.100 de formularios (críticos, `.trim`, `input-group`, `switch`, `b-form-file`, datepicker, grupos).
- `b-table` (17), tabs, dropdown, pagination, `b-modal` declarativo (102), `$bvToast`/`$bvModal` en POS/caja/pagos/inventario/ventas/compras (81 / 96), `v-b-visible` y `v-b-hover` internos de BV2.
- 1.060 líneas con clases direccionales BS4, `input-group-append/prepend` (126), `badge-*` (124), `custom-*` (51), `btn-block` (15), `.table-responsive` (51).
- `vue-good-table` (tablas de lista), `vee-validate` 3, `vue-i18n` 8, Vuex 3.

## 16. Plan exacto de la fase 4

1. **Dominios exentos, por orden de riesgo**, con un E2E de dominio previo (hoy la cobertura E2E de estos dominios es la de las specs 05–09): ventas/compras, transferencias, ajustes/daños, inventario, pagos/caja, POS. En cada uno: `$bvToast`/`$bvModal` → servicios (81 + 96), `b-modal` declarativo → BVN cuando el modal sea simple, sidebars/tooltips ya migrados. Objetivo: `$bvToast` y `$bvModal` a 0 y poder desinstalar los drivers de BV2.
2. **`b-modal` declarativo (102)**: `modals.show/hide` ya resuelve ambos sistemas; migrar por familias (simples primero) a `BModal` con `v-model` y quitar el ramal `bv::show::modal`.
3. **Tablas**: `BTable` para las 17 restantes (lectura con slots) con la auditoría de sort/`busy`/selección/`sticky`/`tbody-tr-attr` donde se use; decidir `vue-good-table`.
4. **Formularios**: `.trim` con un formatter propio que recorte al escribir *solo si* se demuestra que el espacio interno se conserva; CSS de `custom-switch` en el puente; `input-group` (append/prepend → hijos directos); `b-form-file`, datepicker, grupos.
5. **Retirar BV2**: con `b-table`, `b-modal`, `b-dropdown`, `b-tabs`, `b-pagination` migrados, quitar `Vue.use(BootstrapVue)`, `platform/compat/bootstrap-vue.js` (incluye los hooks de `v-b-visible`/`v-b-hover`) y el CSS de BV2.
6. **Corte de estilos**: prototipo con la hoja BS5 completa + shim de clases legacy (`badge-*`, `custom-*`, `form-group`, `btn-block`) y sustitución de las reglas `.b-sidebar-*` / `.b-toast` por las de offcanvas/toast nativos.
7. **CI**: 2 runs verdes por commit relevante; retirar el paso de diagnóstico tras ~10 runs limpios.
