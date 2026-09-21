# Bootstrap 5 + BootstrapVueNext — fase 5B (formularios, archivos, fechas y marcadores de carga)

Rama `refactor/bootstrap5-bootstrapvue-next-phase5b`, desde `96fd8f0` (fase 5A). Objetivo: sacar de BootstrapVue 2 (BV2) **toda** la superficie de formularios que quedaba (`b-form*`, `b-input-group*`, `b-form-file`, `b-form-datepicker`, `b-skeleton-img`), retirar los parches de compatibilidad que solo existían por ella y corregir la regresión de bundle del login. **Resultado: 0 etiquetas de BV2 en la aplicación** (por AST) y 0 registros globales de BV2.

## 1. Línea base confirmada (por AST) y matriz inicial

`node tests/frontend/inventory/bootstrap-inventory.mjs --json` sobre `96fd8f0`: **2.140** etiquetas BV2 en **144** archivos (formularios 2.136 + 4 `b-skeleton-img`); `$bvToast` / `$bvModal` / `b-modal` / `b-table` / layout / primitives / pestañas / desplegables / paginación BV2 = 0; `CUSTOM_DIR: false`.

| Etiqueta BV2 | Usos | | Etiqueta BV2 | Usos |
|---|---:|---|---|---:|
| `b-form-group` | 846 | | `b-form` | 123 |
| `b-form-input` | 536 | | `b-form-select` | 68 |
| `b-form-invalid-feedback` | 308 | | `b-input-group` | 63 |
| `b-form-checkbox` | 58 | | `b-form-textarea` | 35 |
| `b-input-group-append` / `-prepend` | 28 / 11 | | `b-form-select-option` | 28 |
| `b-form-file` | 16 | | `b-form-radio-group` | 8 |
| `b-form-datepicker` | 6 | | `b-skeleton-img` | 4 |
| `b-form-checkbox-group`, `b-form-text` | 1 + 1 | | | |

Modificadores y contratos usados (AST, no estimaciones): `v-model.trim` 28 (input) + 3 (textarea); `v-model.number` 82; `switch` 50; `:unchecked-value` 19 y `:checked-value` 18; `multiple` (file) 8; `max-rows` 5; `buttons` (radio) 7; `size` 42/17/5; `@input` 32 (input) + 4 (datepicker); `@change` 11 (input) + 29 (select) + 11 (file); `:state` 184 (input). Sin `b-form-tags`, `b-form-rating`, `b-form-spinbutton`, `b-form-timepicker`.

## 2. Método: contrato de BV2 MEDIDO, no deducido

`tests/e2e/specs/33-forms-parity.spec.js` monta la MISMA plantilla con BV2 (registrado localmente solo en la sonda de desarrollo `/app/_ui?probe=bv`) y con los wrappers de `platform/bootstrap`, ejecuta las mismas acciones de usuario y compara: **orden de eventos y observadores (nombre, tipo JS, valor), modelo final y su tipo, estado del DOM y —donde aplica— el marcado normalizado**. 53 casos. El contrato de BV2 queda volcado en `tests/e2e/data/forms-bv2-contract.json`.

Hallazgos que cambian cosas respecto a lo que dice la documentación de BV2:

- `@input` / `@change` en input, textarea, select, casilla y radio entregan **el valor**, no el `Event` nativo (BVN entrega el `Event`). En select/casilla/radio: `input(valor)` → el modelo cambia → `change(valor)` en el siguiente tick, con los observadores ya ejecutados. En campos de texto: `input` en cada pulsación y `change` al perder el foco.
- `checked-value` **no hace nada** en BV2 (solo cuenta `value`); `unchecked-value` es `false` por defecto (BVN: `undefined`).
- `.number` ya era equivalente en BVN (mismo tipo, mismos valores parciales `"1."`, `"12ab"`, vacío); no se toca.
- `.trim`: BV2 recorta el **modelo** en cada evento y deja el texto del campo tal cual (el usuario puede escribir "San Pedro Sula"); BVN recorta el DOM al perder el foco. Se implementa en el wrapper y no se pasa el modificador a BVN.
- `b-form-file`: `change(Event)` → `input(File | File[])`; con `multiple` siempre arreglo; vaciar la selección da `null`; poner el modelo en `null` limpia el `<input>` y devuelve `input(null)`; soltar archivos filtra por `accept`, deja los archivos en el `<input>` y emite `change` → `input`.
- `b-form-datepicker`: `input('YYYY-MM-DD')`; cerrar el menú (`hidden`) ocurre **después** de los observadores; RePág = mes anterior (aunque `aria-keyshortcuts` diga lo contrario); la rejilla solo pinta las semanas que toca el mes.

## 3. Módulos de `platform/bootstrap` (partido por familia)

| Módulo | Contenido |
|---|---|
| `core.js` | `pure`, `wrapper`, `truthyAttr`, `toList` |
| `form-text.js` | `BForm`, `BFormRow`, `BFormText`, feedback, `BFormGroup`, `BFormInput`, `BFormTextarea`, `BInputGroup(Text)` |
| `form-choice.js` | `BFormSelect(Option/Group)`, `BFormCheckbox(Group)`, `BFormRadio(Group)` |
| `forms.js` | barril de los dos anteriores |
| `file.js`, `datepicker.js`, `skeleton.js` | `BFormFile`, `BFormDatepicker`, `BSkeletonImg` de PRODEX (Vue 3 puro, marcado de BV2) |
| `layout.js`, `buttons.js`, `primitives.js`, `feedback.js`, `nav.js`, `table.js`, `overlay.js` | resto de familias (5A) |
| `index.js` | barril de todo + `bootstrapPlugin` |

## 4. Formularios migrados

- **`BForm`, `BFormGroup`, feedback, `BFormText`**: `BFormGroup` emite `label-for=""` cuando la vista no lo pone para conservar `fieldset > legend.col-form-label` de BV2.
- **`BFormInput` / `BFormTextarea`**: traducción de eventos (valor en vez de `Event`), `:value` sin `v-model`, `.trim`. `max-rows` de textarea: BVN reproduce altura, auto-crecimiento y desplazamiento (caso `textarea: … max-rows` idéntico a BV2), por lo que **`v-b-visible` desaparece**.
- **`BFormSelect`**: `custom-select` (+ `-sm|-lg`), eventos con valor tipado (string, número, booleano, `null`, objeto), `value-field/text-field`, opciones deshabilitadas, `:value` + `@change` sin `v-model`.
- **Casillas y switches**: `unchecked-value=false`; array con `:value` (alta, baja, orden); `switch` **sin cambio visual** (ver §8). **Radios**: exclusión mutua, `name`, teclado (flechas). **Grupos**: `px-bvn-group`, botones (`buttons`) con `.btn-check` + etiqueta.
- **`BInputGroup`**: la prop `prepend|append` sigue emitiendo `div.input-group-prepend|append > div.input-group-text` (marcado de BV2). Los `<b-input-group-append|prepend>` de las vistas (39) se sustituyen por `<div class="input-group-append|prepend">` planos: el tema (`_vue-good-table.scss`), la hoja RTL y ~220 reglas de vistas apuntan a esas clases. **Bloqueante de 5C** (§13).
- **`BFormFile`** (16 usos), **`BFormDatepicker`** (6), **`BSkeletonImg`** (4): componentes de PRODEX con el marcado exacto de BV2 (custom-file, `b-form-btn-label-control` + `b-calendar`, `b-aspect` 16:9 + `b-skeleton`), verificados por marcado normalizado. No se sustituye por `<input type="date">`.
- Plantillas en cadena dentro de `<script>` (`CustomerLedger.vue`) registran sus wrappers (`ListToolbar.components`); el AST de plantillas no las ve, las guarda un test.

## 5. Validación (vee-validate 3, sin tocar)

`PxValidationProvider/Observer` detectan el campo por `modelValue`. `tests/e2e/specs/34-forms-validation.spec.js` (13 casos) recorre input, `.trim`, `.number`, textarea, select, casilla, switch, radio, file y datepicker: required → mensaje y estado inválido → corrección → envío bloqueado / válido **una sola vez** → reset; además `observer.validate()` y `setErrors()` (error de servidor). Los fixtures fallan ante `console.error`, `pageerror` o 5xx.

## 6. Dominios críticos

`35-critical-forms.spec.js`: ventas, compras, cotizaciones, transferencias, ajustes, daños, gastos, productos, configuración (sistema, POS, métodos de pago, cajas), RR. HH. (empleados, nóminas) y POS: cargan sin errores, sin ningún `__BVID__`, y sus campos, selects y casillas responden. Payload, watchers, permisos y lógica del POS no se tocaron (las vistas solo cambian el registro local de componentes). Las specs 05–09 y 30 cubren la lógica de negocio.

## 7. Parches y registro global de BV2

| Parche | Consumidor | Estado |
|---|---|---|
| asignador `onUpdate:modelValue` en `<select>`/`<input>` de BFormSelect/Checkbox/Radio | BV2 en la app | **eliminado** |
| `v-b-visible` (textarea `max-rows`) | BV2 en la app | **eliminado** |
| `v-b-hover` (datepicker) | BV2 en la app | **eliminado** |
| `platform/compat/bootstrap-vue.js` y `bootstrap-vue-forms.js` | — | **borrados** |
| `Vue.use(BootstrapVueRemaining)` (`stocky.kit.js`, `login.js`) | — | **eliminado** |

Una copia de los tres parches vive **solo** en la sonda de desarrollo (`views/app/_ui/BvProbe.vue`, ruta inexistente en producción) porque el lado BV2 de las specs de paridad los necesita. El paquete `bootstrap-vue` sigue instalado por: la sonda, el CSS `bootstrap-vue.css` que los temas importan (`.b-calendar*`, `.b-form-btn-label-control`, `.b-skeleton*`, `.b-aspect*`, `.custom-file`) y el `@import` de los tres temas. Ningún código de aplicación lo importa.

## 8. Puente CSS (`bootstrap5/_bridge.scss`, `store/modules/config.js`)

- Casillas/radios: selectores `.px-bvn-check` **y** `.px-bvn-group > .form-check > input`; sombra con el mixin `box-shadow` (BS4 con `$enable-shadows: false` no la pinta), fondo del estado inválido `lighten($form-feedback-invalid-color, 25%)`.
- **Switch**: en esta hoja `.custom-switch` NO tiene reglas base (solo `b-custom-control-sm|lg` de bootstrap-vue.css), así que en BV2 un switch es una casilla plana, cuadrada y sin marca. Se conserva exactamente ese aspecto.
- Botones de radio/casilla: `.btn-check` + `label.btn`; el primer botón recupera el radio (`--px-radius-md|sm` de la capa de diseño), `z-index` del seleccionado, look "contorno" unificado del tema. El color primario del tenant se inyecta en runtime (`config.js`): se añadieron `.btn-check:checked + .btn-primary|outline-primary` y los selectores de grupo.
- No se añadieron reglas globales para `.input-group > .btn` (habrían cambiado vistas escritas a mano).

## 9. Bundles (producción, `mix --production`, bytes)

| Entrypoint | 5A (`96fd8f0`) | 5B | Δ |
|---|---:|---:|---:|
| `login.min.js` | 1.191.934 | **743.554** | −37,6 % (fase 4: 1.083.825) |
| `main.min.js` | 2.523.520 | **2.254.639** | −10,7 % |
| `portal.min.js` | 308.871 | 308.293 | −0,2 % |
| `customer-display.min.js` | 361.738 | 361.738 | 0 |

Causa de la regresión del login (+108 KB en 5A): `signIn.vue` importaba del barril y `buttons.js`/`forms.js` arrastraban módulos de BVN sin uso (listas, imagen, avatar, casillas, radios, select). Ahora el login importa `buttons.js` + `form-text.js` directamente; el resto de familias quedan fuera. Lo que queda de `bootstrap-vue-next` en el login (≈297 KB de fuente, 52 módulos) es el orquestador que `createBootstrap` necesita para los avisos (BApp, BModal, BToast, scroll lock) más Button/Form/Group/Input/Link.

Módulos más pesados (tamaño de fuente antes de minificar; lista completa en `/tmp/5b_heaviest.txt` del CI local): `main` — `xlsx` 1.008 KB, `@vue/compat` 664 KB, estilos propios 579 KB, `vue-good-table` 346 KB, `bootstrap-vue-next` 239 KB, `axios` 206 KB; `login` — `@vue/compat` 664 KB, `bootstrap-vue-next` 297 KB, `axios` 206 KB, `router.js` 131 KB, `vue-router` 107 KB, `vee-validate` 95 KB. Auditoría de tree-shaking: cada wrapper lleva `/*#__PURE__*/`, `platform/bootstrap` declara `sideEffects: false`, y el marcado de componentes internos (`BLink`, `BCloseButton`) va dentro de una llamada pura para que no sea un efecto de módulo.

## 10. QA visual (`tests/e2e/visual/forms-matrix.js`)

La misma plantilla en BV2 y en BVN, capturada en LTR / RTL / móvil (14 casos × 3), con % de píxeles distintos (tolerancia de canal 12):

- **LTR y móvil: idénticos a BV2** (0,000 %) en input (estados), select, textarea, casilla, switch, grupos de casillas, radios, botones de radio, grupos de entrada (texto y botones, incluido `size="sm"`), archivo, selector de fecha cerrado y marcador de carga. El selector de fecha abierto difiere 0,3–0,4 % solo por la animación del anillo de foco en curso (medido: mismos estilos calculados tras la transición).
- **RTL**: diferencias deliberadas en casillas, radios, switches y grupos de botones. BV2 posicionaba el indicador y el redondeo con propiedades físicas (`left`, `border-top-left-radius`), de modo que en RTL el indicador quedaba a la izquierda del texto y los grupos de botones mal redondeados. Los wrappers usan propiedades lógicas: el indicador queda en el borde inicial (derecho en RTL) y el redondeo sigue el sentido del texto. Grupos de entrada, inputs, selects y textareas en RTL: idénticos.

## 11. Clases BS4 en las vistas tocadas

Las vistas tocadas usan 240 apariciones de clases BS4 en plantilla, de las cuales solo 161 son direccionales (`mr-*`, `ml-*`, `text-right`, `font-weight-bold`, `sr-only`); 17 de los 36 archivos están en la lista de pantallas críticas con clases BS5 neutralizadas (`tests/frontend/latent-bs5-neutralized.json`, guardada por test) y `pos.vue` tiene CSS propio (`.cr-footer .text-right`) que dejaría de coincidir. Se conservan; `close` (34) e `input-group-append|prepend` (39) están ligadas a CSS de BS4 (§4). Sin corte de la hoja global.

## 12. Avisos de @vue/compat

Suite completa (`npm run test:e2e:compat-warnings`, 326 tests con avisos, **incluye** las specs de paridad/validación que montan BV2 a propósito en la sonda de desarrollo): **35.189 mensajes, 34 únicos → 107,9 por test** (fase 5A: 32.061 / 35 / 127,2 por test; −15,2 %). Ningún aviso silenciado. Los `BFormInput/BFormTextarea/BFormFile/BIconCalendar (librería)` que aparecen en `INSTANCE_LISTENERS`, `COMPONENT_V_MODEL`, `OPTIONS_DATA_MERGE`, `ATTR_FALSE_VALUE`, `COMPONENT_FUNCTIONAL` y `INSTANCE_ATTRS_CLASS_STYLE` son el lado BV2 de la sonda (`BvProbe`), no la aplicación; `Vue received a Component that was made a reactive object` (181) también sale solo de la sonda.

**Atribución exacta por instancia** (`tests/e2e/scripts/warnings-by-origin.js`, las mismas 39 rutas de 5A, sin sonda):

| | 5A | 5B |
|---|---:|---:|
| Total de avisos en las 39 rutas | 687 | **619** |
| atribuidos a BootstrapVue 2 | 57 | **0** |
| atribuidos a BootstrapVueNext | 55 | 55 (49 `OPTIONS_BEFORE_DESTROY` del mixin global de vue-i18n 8, sin `beforeDestroy` propio; 6 otros) |
| propios / otras librerías | 575 | 562 (+2 globales) |

Por aviso en BV2: `PRIVATE_APIS`, `RENDER_FUNCTION`, `INSTANCE_SCOPED_SLOTS`, `COMPONENT_FUNCTIONAL`, `COMPONENT_V_MODEL`, `ATTR_FALSE_VALUE`, `INSTANCE_EVENT_HOOKS`, `INSTANCE_EVENT_EMITTER`, `OPTIONS_BEFORE_DESTROY`, `CUSTOM_DIR` = **0** en las 39 pantallas (E2E 22 ahora exige 0 en lugar de > 0). Orígenes restantes: `vee-validate` 3 (`PRIVATE_APIS`, `RENDER_FUNCTION`, `INSTANCE_LISTENERS`, `INSTANCE_SCOPED_SLOTS`), `vue-i18n` 8 (`OPTIONS_BEFORE_DESTROY`, `GLOBAL_PROTOTYPE`), `vue-good-table` (`RENDER_FUNCTION`, `WATCH_ARRAY`, `INSTANCE_SET`), `vue-select`, `VuePerfectScrollbar`, `LucideIcon`.

## 13. Bloqueantes para la fase 5C

1. **CSS de BV2** (`bootstrap-vue.css`, importado por los tres temas): `.b-calendar*`, `.b-form-btn-label-control`, `.b-form-date-*`, `.b-skeleton*`, `.b-aspect*`, `.b-custom-control-*` y las reglas de `.custom-file` deben copiarse a un parcial de PRODEX antes de quitar el `@import`.
2. **Marcado BS4 de grupos de entrada** (`input-group-prepend|append`, 39 en plantillas + ~220 referencias CSS entre tema, RTL y vistas) y `custom-select`, `custom-control`, `btn-group-toggle`: migrar reglas y marcado a la vez.
3. **Paquete `bootstrap-vue`**: solo lo usa la sonda de desarrollo (y el CSS del punto 1); se puede desinstalar cuando el punto 1 esté hecho y la sonda se retire o pase a comparar contra capturas.
4. **RTL**: el corte a BS5 sustituye `bootstrap-rtl.scss`; los wrappers ya usan propiedades lógicas.
5. Clases BS4 de las pantallas críticas (lista de 17) y `pos.vue`.
6. `vue-good-table` (73 archivos), vee-validate 3, vue-i18n 8, Vuex 3: fuera de alcance de 5B.
