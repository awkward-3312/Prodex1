# Bootstrap 5 + BootstrapVueNext — fase 1

Base: `063341d` (Vue 3.5.43 + `@vue/compat` MODE 2, Vue Router 4, Unhead, directiva `on-clickaway` propia). Objetivo final: Vue 3 + Bootstrap 5 + BootstrapVueNext (BVN), sin BootstrapVue 2 (BV2) ni Bootstrap 4 (BS4). BVN **no** es un reemplazo directo de BV2: es otra librería (Vue 3, SFC/ESM, marcado BS5, props/eventos/slots distintos). Esta fase demuestra que BS5 y BVN funcionan dentro de PRODEX, fija la estrategia de corte y migra un conjunto representativo de pantallas de bajo riesgo. No se migró vee-validate, Vuex, vue-i18n, Vite ni TypeScript, y no hay cambios de backend, rutas, permisos, tenancy ni lógica de negocio.

## 1. Versiones

| | Antes | Después |
|---|---|---|
| Bootstrap (paquete npm) | 4.5.x (`^4.5.3`) | **5.3.8** (`^5.3.8`) |
| Hoja de estilos activa | BS4 vendorizado en `assets/styles/vendor/bootstrap` | **sigue BS4** + puente BS5 aditivo (ver §3) |
| BootstrapVue | 2.23.1 | 2.23.1 (se retira cuando todas las familias estén cubiertas) |
| BootstrapVueNext | — | **1.2.1** (estable; `reka-ui`, `@floating-ui/vue`, `@vueuse/core`, `@vueuse/integrations`, `focus-trap`, `@internationalized/date` como dependencias) |
| Vue / `@vue/compat` / Router / Unhead | 3.5.43 / 3.5.43 / 4.6.4 / 3.4.1 | sin cambios (MODE 2) |

`npm ci` limpio verificado (lockfile v1 CRLF conservado, `"name"` restaurado).

## 2. Inventario previo (antes de tocar código, `063341d`)

### 2.1 BootstrapVue 2

`<b-*>` en plantillas y JS: **5.714 aperturas de etiqueta en 199 archivos**. Componentes más usados:

| Componente | Usos | Componente | Usos |
|---|---:|---|---:|
| b-col | 1.390 | b-form-select-option | 71 |
| b-form-group | 1.057 | b-form-checkbox | 65 |
| b-form-input | 659 | b-input-group | 63 |
| b-button | 589 | b-alert | 51 |
| b-form-invalid-feedback | 377 | b-form-textarea | 48 |
| b-row | 340 | b-tab | 42 |
| b-card | 330 | b-input-group-append / prepend | 28 / 11 |
| b-form | 123 | b-dropdown-item / b-dropdown | 21 / 14 |
| b-modal | 102 | b-table | 19 |
| b-form-select | 95 | b-form-file | 18 |
| b-badge | 89 | b-sidebar, b-tabs | 12, 11 |

Resto (≤ 9 usos): b-spinner, b-progress(-bar), b-list-group(-item), b-pagination, b-form-radio(-group), b-form-datepicker, b-link, b-skeleton-img, b-card-*, b-button-group, b-table-simple/thead/tbody/tr/th/td, b-img, b-form-text, b-avatar.

| Otra superficie BV2 | Usos |
|---|---:|
| `v-b-tooltip` | 116 (41 archivos con cualquier `v-b-*`) |
| `v-b-toggle` / `v-b-popover` | 12 / 1 |
| `this.$bvToast` | 248 usos en 185 archivos (casi todos vía adaptadores de `platform/notifications`) |
| `this.$bvModal` | 185 usos en 40 archivos (vía `platform/modals`) |
| Plugin | `Vue.use(BootstrapVue)` global en `plugins/stocky.kit.js`; parche de directiva `model` en `platform/compat/bootstrap-vue.js` |

### 2.2 Bootstrap 4 (clases)

Clasificación por riesgo (ocurrencias / archivos en `resources/src`):

**Automático-seguro** (equivalencia exacta, LTR y RTL, mediante propiedades lógicas — ver §3):

| Patrón BS4 → BS5 | Antes | Después |
|---|---:|---:|
| `text-left/right` → `text-start/end` | 1.127 / 108 | 592 / 52 |
| `mr-N` → `me-N` | 857 / 173 | 422 / 73 |
| `font-weight-*` → `fw-*` | 273 / 90 | 129 / 44 |
| `ml-N` → `ms-N` | 129 / 67 | 78 / 36 |
| `pl-N` → `ps-N` | 19 / 14 | 9 / 8 |
| `pr-N` → `pe-N`, `float-left/right` → `float-start/end` | 1 / 1, 1 / 1 | 0 |
| `border-left/right`, `rounded-left/right` | 155 / 41 (son propiedades CSS `border-left:`, no clases) | sin cambio (0 clases convertidas) |

Total convertido: **1.177 ocurrencias en 108 archivos**. Lo que queda son las pantallas críticas (POS, caja, pagos, inventario, ventas, compras, productos, facturación/SAR, nómina…, excluidas por regla) y `App.vue` (`bodyAttrs` conserva `text-left`: el E2E 19 lo comprueba).

**Manual** (la semántica cambia; requieren revisar cada sitio):

| Patrón | Ocurrencias / archivos | Cambio en BS5 |
|---|---:|---|
| `input-group-append/prepend` | 126 / 24 | eliminados: hijos directos del `.input-group` |
| `badge-<variante>` | 124 / 35 | `text-bg-<variante>`; `badge-pill` (6) → `rounded-pill` |
| `custom-select` / `custom-control` / `custom-switch` | 32 / 16 / 3 | `form-select`, `form-check`, `form-switch` |
| `form-group` (clase suelta) | 51 / 13 | eliminada; usar utilidades de margen |
| `form-row` | 19 / 2 | `row g-*` |
| `btn-block` | 15 / 8 | `d-grid` / `w-100` |
| `no-gutters` | 3 / 3 | `g-0` |
| `thead-dark/light`, `sr-only`, `dropdown-menu-right` | 6 / 4, 2 / 2, 1 / 1 | `table-dark`, `visually-hidden`, `dropdown-menu-end` |

**Alto riesgo**: `.container` (gutter 15px → 12px), `.table-responsive` (51 / 41), reglas de `.modal`/`.close` del marcado de BV2, `data-toggle`/`data-dismiss` (0 usos: no hay JS de BS4 con jQuery), y todo el marcado de BV2, que depende de reglas de BS4 (`btn-*`, `custom-*`, `form-control`, `.b-*`).

## 3. Coexistencia BS4 / BS5 y estrategia de corte

**No pueden coexistir las hojas completas** (mismos selectores con reglas distintas: `.btn`, `.form-control`, `.badge`, `.modal`, `.container`…), ni el CSS global de BVN (redefine `.container`, `.card-deck`, `.table-responsive`…). Tampoco hace falta:

1. **Fase 1 (esta)** — hoja base BS4 intacta + **puente BS5 aditivo** (`resources/src/assets/styles/sass/bootstrap5/_bridge.scss`): solo declara clases que BS4 no tiene y que BS5/BVN generan. Ninguna regla de BS4 se sustituye. BVN se adopta **por familia y por pantalla** mediante registro local (`components: { BButton… }` importados de `@/platform/bootstrap`); el registro local vence al global de BV2 solo en esa vista.
2. **Fase 2** — familias de formulario/lista; sustituir por BVN las que ya tienen equivalente (ver §4).
3. **Corte final** — cuando todas las pantallas usen BVN o HTML BS5: cambiar la hoja base a `bootstrap/scss/bootstrap.scss` 5.3 (compila con el sass del proyecto: test `toolchain`), aplicar un shim de las clases legacy que aún queden, quitar BV2, BS4 vendorizado, `bootstrap-rtl.scss` y el puente.

Lo que **sí** coexiste hoy: BV2 (global) + BVN (local) en la misma página, incluso anidados (`<b-card>` BVN con `<b-form-group>` BV2 dentro); modales, toasts y tooltips de BV2 con BVN en pantalla (E2E 21).

### Utilidades del puente y RTL

`ms-*`, `me-*`, `ps-*`, `pe-*` (con `auto` y breakpoints), `text-start/end`, `float-start/end`, `border-start/end(-0)`, `rounded-start/end`, `fw-*`, `visually-hidden`, `text-bg-*`, `btn-close`, `alert-dismissible .btn-close`. Se implementan con propiedades **lógicas** (`margin-inline-start`, `text-align: start`, `float: inline-start`…). Eso es exactamente lo que hacía `bootstrap-rtl.scss` para `.ml-*`, `.text-left`…: en RTL se invierten solos. Por eso `ml-2 → ms-2` conserva el comportamiento en LTR **y** RTL sin tocar el idioma. Verificado en E2E con `dir="rtl"` (márgenes, padding y `float-start` pegado al borde derecho).

**El puente NO define `gap-*` ni `form-select`.** Regresión detectada por la revisión visual: `dashboard.vue` usa `d-flex … gap-2`, clase sin regla global (el `gap` real viene de `.dashboard-header-filters { gap: .75rem }`); una regla global `gap-2 !important` lo reducía a 8px y la cabecera del panel perdía 4px de alto. Igual `ModernPaymentModal` usa su propio `.form-select`. Se quitaron del puente y hay un test que lo impide.

## 4. Estrategia BV2 → BVN por familia

| Familia | Estado fase 1 | Diferencias BVN a resolver |
|---|---|---|
| BButton, BBadge, BAlert, BSpinner, BRow, BCol, BContainer, BCard(+Body/Header/Footer/Text/Title/Subtitle) | **migradas** en 15 archivos (117 etiquetas) | ver §5 |
| BFormInput/Textarea/Checkbox/Radio/Select, BFormGroup, BInputGroup | fase 2 | `value`→`modelValue`/`v-model`; `input-group-append/prepend` desaparecen; vee-validate 3 detecta el campo por `model:{prop:'value'}` → hace falta el envoltorio de `platform/validation` |
| BTable (19), BPagination (8), BTabs (11+42 tab), BDropdown, BSidebar (→ offcanvas) | fase 3+ | slots y eventos distintos (`items`/`fields`, `#cell()`), `right`→`end` en dropdown |
| BModal (102), `$bvModal`, `$bvToast`, BToaster | fase 3+ | `useModal`/`useToast` y orquestador (§6); los adaptadores `platform/modals` / `platform/notifications` permiten el cambio sin tocar vistas |
| `v-b-tooltip` (116), `v-b-toggle`, `v-b-popover` | fase 2/3 | directivas de BVN (Floating UI) en lugar de Popper de BV2 |

Las vistas importan **siempre** de `@/platform/bootstrap`, nunca de `bootstrap-vue-next` (test de guardia). `platform/bootstrap/index.js` marca los componentes de BVN con `compatConfig: { MODE: 3 }` y envuelve los que cambian de contrato:

- `BButton`: acepta `block` (BVN no lo tiene) → añade `btn-block`; el resto de props, eventos y slots pasan tal cual.
- `BBadge`: variante por defecto `secondary` (como BV2); emite `badge-<variante>` (no `text-bg-*`) para conservar el color del tenant (`store/modules/config.js` redefine `.badge-primary`).
- `BAlert`: BV2 `show` → BVN `modelValue` (`<b-alert :model-value="true">` en 2 archivos).
- `BCard`: `sub-title` (BV2) → `subtitle` (BVN).

## 5. Incompatibilidades halladas

- BVN 1.2.1 es Vue 3 puro; bajo `@vue/compat` funciona con MODE 3 por componente (sin `v-model` de Vue 2, sin `$listeners`, sin `class/style` en `$attrs` de Vue 2). Los wrappers pasan `attrs`, `slots` y `class` explícitamente.
- `createBootstrap` no se usa como orquestador: solo se instala (`bootstrapPlugin`, `components: {}`) para su registro interno.
- `BApp` lee `rtl` una sola vez y no hay `useRtl`; PRODEX cambia dirección en caliente por `dir` → se decidió no usar `BApp` aún (ver §6).
- El CSS de BVN no se carga (rompería `.container`, `.card-deck`, `.table-responsive` de BS4); los componentes migrados se pintan con las clases BS4/puente de PRODEX. Se comprobó píxel a píxel.
- BV2 y BVN comparten la clase `.badge`, `.btn`, `.alert`… con marcado equivalente (`btn btn-<variant>`, `alert alert-<variant>`), por eso la sustitución local no cambia el aspecto.
- Los `v-b-tooltip` de BV2 siguen funcionando en pantallas con BVN (calendario, `17-router4`).

## 6. BApp vs `createBootstrap` (decisión)

| Criterio | `BApp` | `createBootstrap` (plugin) |
|---|---|---|
| RTL | prop `rtl` leída una vez; el cambio de idioma en caliente no se propaga | ninguna (PRODEX usa `dir`) |
| Router 4 | sin relación | sin relación |
| Modales / toast | monta el orquestador (`BOrchestrator`) y necesita hijo único | idem vía `useToast`/`useModal` con el orquestador montado aparte |
| Portal / login (`login.min.js`, `portal.min.js`) | habría que envolver 3 entrypoints | cada entrypoint decide si lo instala |
| Riesgo con BV2 activo | dos sistemas de toast/modal a la vez (`.b-toaster` de BV2 y `BOrchestrator`) | ninguno mientras no se use el orquestador |

**Decisión fase 1: `createBootstrap` solo en `main.js` (registro), sin `BApp` ni orquestador.** Toasts y modales siguen en BV2 (adaptadores de plataforma), las vistas no dependen de `$bvToast`/`$bvModal` directamente (servicios de fase E). Fase 2/3: `BApp` con `no-orchestrator` primero, luego orquestador cuando `platform/modals` y `platform/notifications` cambien de driver; el `rtl` se pasará al reaccionar al cambio de `dir`. Un test impide `<b-app>` en `App.vue` hasta que se decida.

## 7. Migrado en la fase 1

**Componentes BVN** (registro local): BButton 10, BBadge 6, BAlert 5, BSpinner 9, BRow 23, BCol 39, BCard 24, BCardText 1 = **117 etiquetas en 15 archivos**.

**Pantallas** (12 + 3 archivos de spinner): tickets (`TicketsList`), calendario, plantillas de rol, dashboard de marketing, detalle de campaña, informe de errores, WooCommerce y Shopify (pestañas guía / clientes), panel legacy (`dashboard.vue`: BRow/BCol), no autorizado, no encontrado; spinners en `CustomerDetails`, `CustomerLedger`, `customers`.

**Clases BS4 → BS5**: 1.177 ocurrencias en 108 archivos (§2.2). **Directivas propias**: `PxSelect` y `PxMenu` (`click-outside`) pasan de `bind/unbind` a `mounted/unmounted`.

## 8. `v-b-*` y `CUSTOM_DIR`

`v-b-*`: 130 usos en 41 archivos, sin migrar (BV2). **`CUSTOM_DIR: true` se mantiene**: aún hay directivas de BV2 (`v-b-tooltip`, `v-b-toggle`, `v-b-popover`) y de `vue-good-table` con hooks de Vue 2; quitarlo ahora deshabilitaría sus hooks. Ya no quedan directivas propias con hooks de Vue 2 (test de guardia). Se puede quitar cuando esas tres directivas pasen a BVN y vue-good-table se sustituya.

## 9. Diferencias visuales (paridad medida)

Método: capturas Playwright de 16 pantallas × (escritorio LTR, escritorio RTL, móvil 390px) = 48, antes (rama base compilada) y después, comparación de píxeles (`tests/e2e/visual/capture.js` y `compare.js`).

- **Regresión encontrada y corregida**: `gap-*` del puente cambiaba la cabecera del panel legacy (4256→4252 px de alto). Tras quitarlo: 0,004 % (ruido de gráficos) y misma altura.
- **Ruido del entorno**: 12 de 48 capturas muestran ~0,176 % en un rectángulo de la cabecera (POS / Existencias), incluso entre dos capturas consecutivas de la **misma** build (dos estados alternados del ancho de la barra superior): no es regresión.
- Resto: 0,000 % en 35 capturas (incluye RTL y móvil).
- **Efecto secundario real (documentado, no revertido)**: 101 usos en 44 archivos ya estaban escritos con nombres BS5 (`me-2`, `ms-2`, `fw-bold`) y con la hoja BS4 no hacían nada; el puente los activa. Medido con `tests/e2e/visual/latent.js` (puente activo vs reglas eliminadas) en 14 pantallas no migradas: diferencias de 0,04–0,06 % — iconos con 8px de separación del texto (p. ej. botón "guardar" en ajustes clásico) y títulos en negrita (p. ej. "Razones comunes" en pago fallido). Es lo que sus autores querían, pero **es un cambio de aspecto en pantallas no migradas, incluidas algunas críticas** (ajustes/traslados clásicos, facturación). Alternativa si se rechaza: retirar del puente `fw-*` y `me/ms` hasta migrar esas vistas.

## 10. Avisos de `@vue/compat`

Suite E2E completa: 111 tests con aviso, 19.512 mensajes, 35 únicos (línea base: 100 tests, 18.447, 32). Los +3 únicos son `Invalid prop "size"` (String vs Number) de `lucide-vue` en calendario y dashboard de marketing: ya existían en el código (`size="16"`), pero el E2E de esta fase visita esas pantallas por primera vez. El aumento por tipo es proporcional a los 11 tests nuevos (p. ej. `PRIVATE_APIS` 4.620 → 4.884, `RENDER_FUNCTION` 1.755 → 1.839, `OPTIONS_BEFORE_DESTROY` 4.934 → 5.290, `COMPONENT_FUNCTIONAL` 704 → 720, `INSTANCE_EVENT_EMITTER` 260 → 273, `INSTANCE_EVENT_HOOKS` 27 → 31, `CUSTOM_DIR` 209 → 216). Ningún aviso se silenció.

Atribución a BV2: por componente que aparece primero en la traza, `PRIVATE_APIS` 529 de 4.884, `RENDER_FUNCTION` 531 de 1.839, `COMPONENT_FUNCTIONAL` 421 de 720, `OPTIONS_BEFORE_DESTROY` 610 de 5.290, `CUSTOM_DIR` 23 de 216 (aproximado: el resto son componentes propios/`vue-good-table`/`vee-validate`, cuyo aviso llega por mixins globales). **No bajan en esta fase porque BV2 sigue montado en la mayoría de pantallas**; lo comprobado: los componentes BVN montados (calendario, plantillas de rol) se ven en el árbol como `BCard`/`BButton` MODE 3 y sin `beforeDestroy` propio; el aviso `OPTIONS_BEFORE_DESTROY` que compat imprime sobre `<BCard class="mt-cal-card">` proviene del mixin global de `vue-i18n` 8 (`beforeDestroy`, línea 423 de `vue-i18n.esm.js`), que compat aplica a cualquier componente aunque esté en MODE 3. Los `INSTANCE_LISTENERS` sobre `BButton`/`BCard` en esas páginas no se pudieron atribuir con certeza a BV2 o a BVN (el BV2 del resto de la página los genera igual): **queda como punto a medir en fase 2**, aislando una página con solo BVN.

## 11. Métricas antes → después

| Métrica | Antes | Después |
|---|---:|---:|
| Bootstrap npm / hoja base | 4.5 / BS4 | 5.3.8 / BS4 + puente BS5 |
| BootstrapVue / BootstrapVueNext | 2.23.1 / — | 2.23.1 / 1.2.1 |
| `<b-*>` total / archivos | 5.714 / 199 | 5.714 / 199 (117 ahora resuelven a BVN) |
| Componentes migrados a BVN | 0 | 8 tipos, 117 etiquetas, 15 archivos |
| `v-b-*` | 130 / 41 | 130 / 41 |
| `$bvToast` / `$bvModal` | 248 / 185 | 248 / 185 (sin cambios, vía servicios) |
| Clases BS4 direccionales | 2.324 / 195 archivos | 1.179 / 87 archivos |
| Clases BS5 lógicas | 101 | 1.222 |
| `CUSTOM_DIR` | true | true (justificado, §8) |
| Bundle prod `main.min.js` | 2.494.703 B | 2.566.827 B (+72.124, +2,9 %) |
| `login` / `portal` / `customer-display` / `storefront` | 978.679 / 308.521 / 361.386 / 82.249 | 978.677 / 308.521 / 361.386 / 82.249 |
| Archivos JS en `public/js` (chunks) | 455 | 455 |
| Bundle dev `main.min.js` | ≈6,7 MB | 6,99 MB |

## 12. Pruebas

- `npm run test:frontend`: 111/111 (100 + 11 nuevos en `bootstrap5-bridge.test.mjs`).
- E2E `21-bootstrap5-bvn.spec.js` (12 tests): utilidades del puente LTR/RTL, `gap-*` no definido, BButton (variantes y emisión única del click) en calendario, navegación Router 4 desde un BButton, BAlert/BBadge/BCard en WooCommerce, BRow/BCol, navegación SPA entre pantallas migradas, modal simple de BV2 junto al puente, reglas BS4 conservadas (`.card-deck`, `.container`), RTL y móvil sin desbordamiento. Los fixtures fallan ante `pageerror`, `console.error` y 5xx.
- Toast, tooltip y modal de plataforma: cubiertos por `12-platform-services` y `17-router4` (sin cambios).

## 13. Deuda restante

- 199 archivos con `<b-*>`; 5.597 etiquetas aún son BV2.
- Familias de formulario (b-form-*), tablas, tabs, dropdown, modal/toast/orquestador, `v-b-*`.
- 1.179 clases BS4 direccionales en 87 archivos (críticas + `App.vue`), 126 `input-group-append/prepend`, 124 `badge-*`, 51 `custom-*`, 15 `btn-block`, 51 `.table-responsive`.
- 101 usos latentes de clases BS5 activados (decisión de producto, §9).
- Avisos de `@vue/compat` por mixins globales de `vue-i18n` 8 / vee-validate 3 (fuera de alcance).

## 14. Plan concreto de fase 2

1. **Formularios simples** (BFormGroup, BFormInput, BFormTextarea, BFormCheckbox/Radio, BFormSelect) en pantallas no críticas: envoltorios `platform/bootstrap` con `model` de Vue 2 para que `px-validation` (vee-validate) siga detectando el campo; `input-group-append/prepend` → hijos directos; pruebas de validación (`14-validation-layer`).
2. **`v-b-tooltip` → BVN** en pantallas migradas (116 usos) y `v-b-toggle`; retirar `CUSTOM_DIR` cuando solo queden directivas de Vue 3 (sustituir `vue-good-table` o su directiva).
3. **`BApp no-orchestrator`** en `App.vue` con `rtl` reactivo al `dir`, y decisión final sobre orquestador tras cambiar los drivers de `platform/modals`/`platform/notifications` a `useModal`/`useToast`.
4. **Migrar clases restantes** por dominio (HRM, tienda, marketing, proyectos) y decidir si se retiran `fw-*`/`me-*` del puente hasta migrar las vistas críticas (§9).
5. **Corte parcial de estilos**: prototipo con la hoja BS5 en una rama y el shim de clases legacy (`badge-*`, `custom-*`, `form-group`, `btn-block`) para medir el impacto real antes del corte final.
6. Repetir paridad visual (48+ capturas), E2E completo, warnings y bundle por familia.
