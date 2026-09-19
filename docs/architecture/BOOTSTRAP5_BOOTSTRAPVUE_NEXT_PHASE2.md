# Bootstrap 5 + BootstrapVueNext — fase 2

Base: `916454f` (fase 1). Vue 3.5.43 + `@vue/compat` MODE 2, Vue Router 4, Unhead. Esta fase estabiliza el E2E en CI, resuelve las clases BS5 latentes, migra a BootstrapVueNext (BVN) los formularios simples de pantallas no críticas y la directiva `v-b-tooltip`, retira más consumidores directos de `$bvToast` / `$bvModal`, y aísla con datos qué avisos de compat vienen de BVN y cuáles de BootstrapVue 2 (BV2). No se toca vee-validate, Vuex, vue-i18n, Vite ni TypeScript, ni backend, rutas, permisos, tenancy o lógica de negocio. No hay corte global a la hoja Bootstrap 5: el puente sigue siendo aditivo.

## 1. CI E2E estabilizado

**Síntoma.** El servidor PHP embebido de los E2E terminaba en Actions (PHP 8.3.33) durante el login de las sesiones de `auth.setup`, y todo lo siguiente fallaba con `ERR_CONNECTION_REFUSED`. En la fase 1 el fallo se veía como `Segmentation fault (core dumped)` con `PHP_CLI_SERVER_WORKERS=4`.

**Lo que se descartó.**
- *No es solo el multi-worker.* `PHP_CLI_SERVER_WORKERS=1` **no es válido** en PHP 8.3 (`number of workers must be larger than 1`): PHP imprime el aviso y arranca en modo de un solo proceso. Con ese valor el servidor siguió muriendo (runs `35466675774`, `35467844989`, `35470654268`), así que el fallo también ocurre en modo single-process. El primer intento de la fase ("usar 1 worker") por sí solo no fue una solución.
- *No es la compilación de Blade con PCRE-JIT.* Un bucle de 12 × `view:clear` + `view:cache` con `pcre.jit=1` y `pcre.jit=0` en el runner terminó con 0 fallos de 12 en ambos modos.
- Memoria: el runner tenía 6–7 GB libres; sin líneas de `segfault`/`oom` en `dmesg`.

**Lo que no se pudo demostrar.** La causa raíz del fallo del binario de PHP en el runner (intermitente: mismo commit y mismas condiciones, unos runs mueren y otros no) no se identificó. No hay backtrace: cuando el proceso muere no queda volcado que analizar (el envoltorio con `gdb` perdió su salida al ser terminado el proceso; el volcado de núcleo con `kernel.core_pattern` no llegó a generarse porque los runs siguientes no fallaron).

**Mitigación aplicada (determinismo, no rendimiento).**
1. `tests/e2e/scripts/serve.sh`: un solo proceso por defecto (no se exporta `PHP_CLI_SERVER_WORKERS` salvo que se pida > 1), `exec php` sin reinicio silencioso. Se quitó el bucle de reinicio de la fase 1: si el servidor muere, el E2E falla de forma visible. En local, 118 tests con 1 worker pasan sin crash (12,9 min frente a 8,8 min con 4).
2. `.github/workflows/frontend-safety-net.yml`: el job `e2e` **ya no tiene `continue-on-error: true`**. Antes un run aparecía verde con el job E2E fallado; ahora un run verde significa E2E pasado.
3. Diagnóstico permanente y barato si el fallo vuelve: `ulimit -c unlimited` + `kernel.core_pattern` en el paso E2E y un paso `if: always()` que imprime `dmesg` y, si existe un volcado, `gdb bt`.

Resultado: runs consecutivos verdes `35472162519` (`72eeb08`) y `35473014195` (`be57c7f`) con 118/118, y los dos runs finales de la sección 12. **Aviso honesto**: como la causa raíz no está probada, la mitigación se apoya en la evidencia de los runs, no en una explicación; si el fallo reaparece, el paso de diagnóstico dará el backtrace.

## 2. Clases BS5 latentes (clasificación A / B / C)

En la fase 1 el puente activó 101 usos en 44 archivos escritos con nombres BS5 (`me-*`, `ms-*`, `fw-bold`, `text-end`…) que con BS4 no hacían nada. Clasificación por archivo:

| Clase | Criterio | Archivos | Ocurrencias | Decisión |
|---|---|---:|---:|---|
| **A** | Pantalla oficialmente migrada a BS5 (la fase 1 convirtió sus clases BS4) | 21 | 28 | Se mantienen |
| **B** | No migrada, no crítica, con efecto claramente intencionado y evidencia visual | 0 | 0 | — (no hay ninguna: todas las no migradas son críticas) |
| **C** | No migrada / crítica: ajustes, traslados, facturación, inventario, POS/ventas/compras, productos, daños, nómina, `settings/system`, suscripciones | 23 | 73 | **El puente NO las activa** |

Mecanismo de C: se eliminan del marcado esos tokens (eran inertes: el aspecto vuelve a ser exactamente el de antes de la fase 1) y cada eliminación queda registrada en `tests/frontend/latent-bs5-neutralized.json` (archivo, línea, texto original, tokens). Al migrar una pantalla se reponen desde ese registro. El test `bootstrap5-phase2.test.mjs` falla si vuelve a aparecer una clase BS5 en esos 23 archivos. Así la transición visual es explícita: una pantalla crítica solo recibe estilos BS5 cuando se migra a propósito. Medida: `latent_bs5_in_critical` 73 usos / 23 archivos → **0**.

## 3. Formularios simples: BV2 → BVN

Componentes migrados (registro local desde `@/platform/bootstrap`, sin registro global): `BFormGroup`, `BFormInput`, `BFormTextarea`, `BFormSelect` (+ `BFormSelectOption`), `BFormCheckbox` (booleano), `BFormRadio`, `BFormInvalidFeedback`. 34 archivos de dominios no críticos: marketing (campañas, crear campaña, informes, segmentos, ajustes, plantillas), reuniones (lista y detalle), soporte (crear ticket y detalle), proyectos, tareas, contratos, activos, reclutamiento (informes), RR. HH. (lista de empleados, tarjeta de identificadores), e-commerce administrativo (WooCommerce, Shopify) y WhatsApp.

### Contrato de eventos y v-model (Vue 3, sin adaptador de Vue 2)

| Aspecto | BV2 | BVN (esta fase) |
|---|---|---|
| `v-model` | `value` + `input` | `modelValue` + `update:modelValue` |
| `@input` en input/textarea | emite el valor | evento nativo (recibe `Event`); se usa `@update:model-value` (el codemod lo reescribe) |
| `@change` (select/date/checkbox) | emite el valor | evento nativo (`Event`). Los 6 handlers migrados leen el modelo, no el argumento: `onTypeChange`, `applyTemplate`, `onAllChange`, `loadTemplatePreview`, `syncLocationName`, `Get_*(1)` |
| `v-model.number` | `Number()` de Vue 2 | `parseFloat` de BVN: mismo resultado (`"12.5"` → `12.5`; verificado en navegador) |
| `v-model.trim` | recorta al escribir | recorta al **perder el foco**: **no se migra** (blocker) |
| Casilla booleana / radio exclusivo | `checked` | `modelValue` (verificado) |
| `switch` en casilla | `custom-switch` sin CSS en el BS4 vendorizado | **no se migra** (blocker) |
| `:value`, `:checked`, `multiple`, `@input` en select | — | **no se migran** (blockers; test de guardia) |
| Array en casilla / `b-form-checkbox-group`, `b-form-radio-group` | | **no se migran** (0 casos en los archivos migrados) |

Los wrappers de `platform/bootstrap/index.js` solo añaden marcado para conservar el aspecto (no traducen eventos): `BFormGroup` → clase `form-group` y `label-for=""` por defecto (mantiene el `fieldset > legend.col-form-label` de BV2, que la capa de diseño ya estila, también dentro de modales); `BFormSelect` → `custom-select`/`-sm`/`-lg`; casilla y radio → clase `px-bvn-check` en el input. El resto de componentes solo se marcan MODE 3.

### Hallazgo clave: componentes internos de BVN bajo compat

`BFormSelect` de BVN se compone de un componente interno no exportado. `compatConfig` solo se puede fijar en los exportados; en el interno compat aplicaba el contrato de Vue 2 (`modelValue` → `value`) y el `<select>` quedaba **sin valor**. Solución: `configureCompat({ MODE: compatModeFor })`, donde `compat/bvn-mode.js` devuelve 3 para los SFC con `<script setup>` de BVN (`__name: 'B' + mayúscula`; BV2 y el código propio usan `name`) y 2 para el resto. Es una heurística documentada y con test (`Breadcumb` no coincide). Es el único parche de compat nuevo.

### Puente CSS (BS5 sobre BS4)

- Casilla/radio: se pintan con las **mismas variables de BS4** del `custom-control` (indicador, radio 50 %, marca, foco, deshabilitado, indeterminado) y el color del tenant (`store/modules/config.js`); solo dentro de `.px-bvn-check`. En RTL el indicador va al borde de inicio (lógico): en BV2 el radio quedaba en el borde izquierdo físico y desalineado (ver §11).
- `.text-body-secondary` (BFormText de la descripción) → color de `.text-muted`.
- Tooltip (BVN, Floating UI): flecha `.tooltip-arrow` y `bs-tooltip-start|end`.
- Siguen sin definirse `gap-*` ni `form-select` (regresión de la fase 1).

## 4. vee-validate 3 con BVN

Sin migrar vee-validate. `platform/validation/vee-compat-provider.js` reconoce ahora el contrato de Vue 3 (`modelValue` + `onUpdate:modelValue`) además del de Vue 2 (`describeField` exportada y con test unitario). Verificado en navegador (spec 23, plantilla de marketing con `PxValidationObserver`/`PxValidationProvider`): `required` → estado `is-invalid` + mensaje; escribir corrige el error; vaciar lo vuelve a marcar; **submit inválido no envía nada** (0 peticiones); submit válido envía **exactamente 1** petición, cierra el modal y crea la fila; al reabrir, el formulario está vacío y sin `is-invalid` (reset). No hay ningún patrón de vee-validate que se haya dejado sin migrar por incompatibilidad.

## 5. Directivas

**`v-b-tooltip`** — 116 usos en 34 archivos. Modificadores usados: `.hover` (98), `.hover.top` (16), `.hover.bottom` (2). Migrado a la directiva de BVN (registro local `directives: { 'b-tooltip': vBTooltip }`, hooks de Vue 3) en los **18 archivos no críticos**: 63 usos. Verificado (spec 23): hover muestra `.tooltip.show.bs-tooltip-top` con el texto, el `title` nativo se retira (sin doble tooltip), flecha pintada, al salir queda oculto, el nodo oculto no captura clics (`visibility:hidden; pointer-events:none`), se desmonta con la vista, funciona sobre `<a>` y `<router-link>`. Restan 53 usos BV2 en archivos críticos (no se migran esta fase). Enfoque/teclado: los disparadores son solo `.hover` (igual que BV2); las anclas sin `href` no son enfocables (igual que antes).

**`v-b-toggle`** — 12 usos, todos sobre `b-sidebar` de BV2 (`sidebar-right`, `booking-filter-sidebar`): el `toggle` de BVN abre `BOffcanvas`, no un `b-sidebar`. **Blocker**: se migra junto con `b-sidebar` → offcanvas (Fase 3). **`v-b-popover`** — 1 uso (`components/common/customizer.vue`), sin migrar.

## 6. `CUSTOM_DIR`

Se **mantiene** (`CUSTOM_DIR: true`), con los consumidores reales identificados:

| Directiva | Archivo | Razón |
|---|---|---|
| `v-append-to-body` | `node_modules/vue-select/src/directives/appendToBody.js` | hooks `inserted`/`unbind` con `vnode.context` (12 vistas con `append-to-body`) |
| `v-append-to-body` | `node_modules/vue2-daterange-picker/src/directives/appendToBody.js` | ídem |
| `v-b-tooltip` (53), `v-b-toggle` (12), `v-b-popover` (1) | `bootstrap-vue` | vistas críticas y `b-sidebar` |

Las directivas propias `PxSelect`/`PxMenu` (`click-outside`) ya usan `mounted`/`unmounted`. Avisos `CUSTOM_DIR`: 216 → **86** en la suite E2E completa.

## 7. `BApp`

**No se introduce.** No resuelve ningún problema concreto de la arquitectura actual: BVN funciona sin él (registro local, directiva de tooltip que monta su propio nodo), el RTL lo gobierna el atributo `dir` con CSS lógico (el `rtl` de `BApp` se lee una sola vez y no sigue el cambio en caliente), Router 4 y Unhead no dependen de él, y los modales/toasts siguen en BV2. Un test impide `<b-app>` en `App.vue`. Se reevaluará cuando se migre el orquestador de modales/toast.

## 8. Componentes Px

`PxInput`, `PxTextarea`, `PxCheck`, `PxButton`, `PxField`, `PxSelect`… ya son HTML nativo (`grep '<b-'` en `components/px-next` → 0): no envuelven BV2. Cambiarles la implementación interna a BVN no aportaría nada y multiplicaría el riesgo; no se tocan (salvo el cambio de hooks de la directiva `click-outside` de `PxSelect`/`PxMenu`). `VField` sigue con su aviso benigno. La cadena `vista → Px → implementación` se conserva.

## 9. Servicios de plataforma

Vistas no críticas: `this.$bvToast.toast(...)` → `notifications.notify(...)` (65 llamadas), `this.$bvModal.show/hide(...)` → `modals.show/hide(...)` (40 llamadas), en **52 archivos**, sin cambiar el driver global. Quedan `@click="$bvModal.hide(...)"` dentro de plantillas (7, cerrar `b-modal` de BV2) y un `this.$bvModal.msgBoxConfirm` encadenado en `Customers_ecommerce.vue`, ambos pendientes. `$bvToast`: 244 → 179 menciones; `$bvModal`: 178 → 138. Test de guardia: ninguna vista no crítica llama ya a `$bvToast.toast`/`$bvModal.show|hide` desde el script.

## 10. Clases BS4 en pantallas migradas

Las pantallas de esta fase ya estaban limpias por la fase 1 (auditoría sobre los 34 archivos de formularios: 0 `ml/mr/pl/pr`, `text-left/right`, `float`, `font-weight`, `badge-*`, `custom-*`, `input-group-append/prepend`, `sr-only`, `close`, `form-row`; solo 10 `class="form-group"` y 1 `btn-block` legítimos). Quedan 1.060 líneas con clases direccionales BS4 (87 archivos: críticas + `App.vue`; medida por líneas, distinta de las 1.179 ocurrencias de la fase 1), sin cambios en esta fase por regla.

## 11. Comparación visual (paridad medida)

Método: `tests/e2e/visual/capture.js` con `VISUAL_SCREENS=screens-forms.json` (24 pantallas/estados × escritorio LTR, escritorio RTL, móvil 390 = **72 capturas**) sobre la rama base compilada y sobre esta; `compare.js` da el % de píxeles distintos.

Iteración real (cada fila fue un defecto encontrado y corregido con la captura):

| Diferencia detectada | Causa | Corrección |
|---|---|---|
| `<select>` sin valor mostrado | componente interno de BVN en MODE 2 (`modelValue`→`value`) | MODE 3 por `__name` (§3) |
| Todos los grupos +2–8 px de alto | BVN emitía `label.form-label` en vez de `fieldset > legend.col-form-label` | `label-for=""` por defecto (marcado idéntico a BV2, que la capa de diseño ya estila, incluso en modales) |
| Radios nativos y sin color del tenant | `class` va al input en el radio y al contenedor en la casilla | marca siempre en el input + `:has(> .px-bvn-check)` + reglas del tenant en `config.js` |
| Separación entre radios 20 → 28 px | mi `margin-bottom:0` ganaba a `.form-group label` de la capa de diseño | selector de especificidad mínima (`:where`) |
| Descripción del grupo en negro | BVN usa `text-body-secondary` | `.text-body-secondary` = `.text-muted` |

Resultado final: **65 de 72 capturas ≤ 0,30 %** (ruido conocido del encabezado ≈ 0,18–0,25 %, dos estados alternos de la barra superior, también entre dos capturas de la misma build) y 22 con 0,000 %. Diferencias restantes, todas explicadas:
- `empleados-lista` (2,3 %): datos demo distintos (la base de E2E se reinició entre la captura base y la final: otra empresa en la columna «Empresa»); el marcado y la geometría son iguales.
- `campana-crear` / `reclutamiento-reportes` RTL (0,56–0,60 %): el radio/casilla queda en el borde de **inicio** de la línea (propiedades lógicas). Con BV2 el indicador estaba en el borde izquierdo físico y se descolocaba en RTL según el ancho de la etiqueta. Es una mejora de RTL, no una regresión; en LTR es idéntico.
- `marketing-ajustes` / `whatsapp-ajustes` (0,36–0,45 %): sombra interior de 1 px en el indicador de la casilla (BV2 dibuja el indicador con un pseudo-elemento sobre un fondo gris; el equivalente con `box-shadow` inset queda un tono distinto en el borde).

Móvil: sin diferencias fuera del ruido, salvo lo anterior.

## 12. Atribución exacta de avisos de compat (BVN vs BV2)

En la fase 1 no se podía atribuir por la traza. Ahora `tests/e2e/specs/22-warnings-attribution.spec.js` instala `app.config.warnHandler`, que recibe la **instancia** del componente que provoca cada aviso (`instance.$.type`), y clasifica por implementación real (`bvn` = SFC con `__name: 'B…'`, `bv2` = `name: 'B…'` sin `__name`, `own` = resto). Se navega por SPA a 5 páginas con BVN y 3 con solo BV2. Resultado (instancias resueltas):

| Aviso | BVN | BV2 (páginas BV2) | propio |
|---|---:|---:|---:|
| `INSTANCE_LISTENERS` | **0** | 2 | 1 |
| `PRIVATE_APIS` | **0** | 5 | 11 |
| `RENDER_FUNCTION` | **0** | 5 | 3 |
| `COMPONENT_FUNCTIONAL` | **0** | 2 | 1 |
| `INSTANCE_EVENT_EMITTER`, `INSTANCE_EVENT_HOOKS`, `CUSTOM_DIR` | **0** | 0 | 3 (emitter) |
| `OPTIONS_BEFORE_DESTROY` | 11 (ver abajo) | 3 | 10 |

- BVN **no produce** ninguno de los avisos de contrato de Vue 2. Es coherente con el diseño: en MODE 3 esas funciones de compat están desactivadas y no avisan; y el test lo afirma.
- `OPTIONS_BEFORE_DESTROY` sobre BVN: **ningún componente de BVN declara `beforeDestroy`** (aserción del test). El aviso viene del mixin global de `vue-i18n` 8 (`beforeDestroy`, `vue-i18n.esm.js:423`), que compat aplica a cada componente aunque esté en MODE 3.
- Avisos sin instancia (los emite compat con contexto nulo): en páginas con BVN salen 6 `INSTANCE_LISTENERS` y 1 `COMPONENT_FUNCTIONAL` cuya traza empieza por `BCard`/`BCardBody`/`BCardHeader`/`BForm`/`BBadge`/`BCardText`: son los BV2 no migrados de esas mismas páginas (p. ej. las pestañas de WooCommerce); por lo dicho arriba un BVN en MODE 3 no puede emitirlos. Es la única parte que se atribuye por lógica y no por instancia.

Suite completa (118 tests con aviso): 21.114 mensajes, 35 únicos (línea base pre-fase 1: 100 tests, 18.447, 32). Por tipo respecto a la fase 1 (111 tests → 118): `PRIVATE_APIS` 4.884 → 5.298, `RENDER_FUNCTION` 1.839 → 1.971, `COMPONENT_FUNCTIONAL` 720 → 776, `OPTIONS_BEFORE_DESTROY` 5.290 → 5.882, `INSTANCE_LISTENERS` 1.060 → 1.204, `INSTANCE_EVENT_EMITTER` 273 → 277, `INSTANCE_EVENT_HOOKS` 31 → 39, **`CUSTOM_DIR` 216 → 86**, `COMPONENT_V_MODEL` 279 → 271. Suben en valor absoluto porque hay 7 tests más que recorren más pantallas (por test: 175,8 → 178,9 mensajes); ningún aviso se silenció. Lo que baja lo que ya no depende de BV2/Vue 2.

## 13. Pruebas

- `npm run test:frontend`: **119/119** (111 + 8 nuevos en `bootstrap5-phase2.test.mjs`: modo compat por componente, contrato `modelValue` de vee-validate, wrappers, registro local coherente, clases latentes neutralizadas, servicios de plataforma, `CUSTOM_DIR`, blockers de migración).
- Unit **1.328/1.328**, Feature **934 OK (3 skipped)**, route snapshot **474 tenant / 20 portal**.
- E2E completo local: **127/127**, también tras `E2E_RESET=1` (118 + spec 22 de atribución + spec 23 de formularios: 8 tests).
  - Spec 23: validación completa (required / inválido / mensaje / corrige / submit bloqueado / submit válido = 1 petición / reset), tooltip, filtros de reuniones (select y fecha: **una** petición, con el valor ya actualizado), radio y casilla (v-model, aspecto de `custom-control`), `v-model.number`, RTL, móvil 390 px, desmontaje/navegación. Los fixtures fallan ante `pageerror` / `console.error` / 5xx.
- Builds: desarrollo OK (42 avisos de compilación, los mismos de la fase 1); producción OK; `npm ci` limpio.

## 14. Métricas antes (fase 1, `916454f`) → después

Por **implementación real**: una etiqueta `<b-button>` importada localmente de `@/platform/bootstrap` cuenta como BVN.

| Métrica | Antes | Después |
|---|---:|---:|
| `<b-*>` BV2 | 5.590 (185 archivos) | 5.096 (185 archivos) |
| `<b-*>` BVN | 117 (15 archivos) | 611 (49 archivos) |
| `<b-form-*>` / `b-input-group` BV2 | 2.593 (153 archivos) | 2.099 (142 archivos) |
| `<b-form-*>` BVN | 0 | 494 (34 archivos) |
| `v-b-tooltip` BV2 / BVN | 116 / 0 | 53 / 63 |
| `v-b-toggle` / `v-b-popover` | 12 / 1 | 12 / 1 |
| `$bvToast` (menciones) | 244 en 183 archivos | 179 en 138 archivos |
| `$bvModal` (menciones) | 178 en 38 archivos | 138 en 34 archivos |
| Clases BS4 direccionales | 1.060 (87 archivos) | 1.060 (87 archivos) |
| Usos latentes BS5 en pantallas críticas | 73 (23 archivos) | **0** |
| `CUSTOM_DIR` | true | true (consumidores en §6); avisos 216 → 86 |
| Frontend / E2E | 111 / 118 | 119 / 127 |
| `main.min.js` prod | 2.566.827 B | 2.654.695 B (+87.868 B, +3,4 %; +6,4 % sobre la línea base pre-fase 1: 2.494.703) |
| `login` / `portal` / `customer-display` / `storefront` | 978.677 / 308.521 / 361.386 / 82.249 | 979.415 / 308.871 / 361.738 / 82.249 |
| Archivos JS (chunks) | 455 (427 en `bundle/`) | 455 (427) |

El crecimiento de `main` viene de los componentes de formulario de BVN (y de Floating UI por el tooltip) empaquetados con el resto del SPA.

## 15. Superficie restante

- 5.096 etiquetas BV2 en 185 archivos; 2.099 de formularios (críticos, `input-group`, `switch`, `.trim`, grupos de casillas/radios, `b-form-file`, `b-form-datepicker`).
- Tablas (`b-table` 19), tabs, dropdown, pagination, `b-sidebar` (12) y `v-b-toggle`, modal (102) y `$bvModal`/`$bvToast` (con drivers BV2), `v-b-tooltip` en críticas (53), `v-b-popover`.
- 1.060 clases direccionales BS4 en 87 archivos, 126 `input-group-append/prepend`, 124 `badge-*`, 51 `custom-*`, 15 `btn-block`, 51 `.table-responsive`.
- Terceros con hooks de directiva de Vue 2: `vue-select` y `vue2-daterange-picker` (`appendToBody`).
- Causa raíz del fallo intermitente de PHP en el runner (§1).

## 16. Propuesta de Fase 3

1. **Modales y toasts**: `BApp` con `no-orchestrator` + `rtl` reactivo a `dir`; luego cambiar los drivers de `platform/modals` y `platform/notifications` a `useModal`/`useToast` con el orquestador. Migrar los 7 `$bvModal.hide` de plantilla y el `msgBoxConfirm` restante. Desbloquea la mayoría de `$bvToast`/`$bvModal`.
2. **`b-sidebar` → `BOffcanvas` + `v-b-toggle`** (12 + 12 usos): quita el último uso relevante de BV2 en pantallas no críticas y habilita retirar `v-b-toggle`.
3. **`v-b-tooltip` en críticas** (53 usos) con una pasada visual dedicada, y `v-b-popover`; después, evaluar reemplazar o parchear `appendToBody` de `vue-select` y `vue2-daterange-picker` para poder quitar `CUSTOM_DIR`.
4. **Formularios pendientes**: `input-group` (append/prepend → hijos directos), `switch` (añadir CSS de `custom-switch` al puente), `.trim` (formatter propio que recorte al escribir), `b-form-file`, `b-form-datepicker`, grupos de casillas/radios; primero no críticos, luego un dominio crítico piloto (p. ej. clientes) con E2E de formulario.
5. **`BTable`** en una pantalla no crítica piloto (contratos o tareas) con la misma paridad visual, y decisión sobre `vue-good-table`.
6. **CI**: 2 runs verdes por commit relevante; si el fallo de PHP reaparece, usar el backtrace del paso de diagnóstico; retirar el paso de diagnóstico cuando haya 10 runs limpios.
7. **Corte parcial de estilos**: prototipo con la hoja BS5 completa + shim de clases legacy (`badge-*`, `custom-*`, `form-group`, `btn-block`) para medir el impacto real antes del corte final.
