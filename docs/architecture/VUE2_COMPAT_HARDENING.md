# Endurecimiento Vue 2 para `@vue/compat` (fase 4)

Base: `9b79172`. Vue sigue en 2.7.16; no se instaló Vue 3 ni `@vue/compat`. Sin cambios en Router, Vuex, vue-i18n, Bootstrap, BootstrapVue, diseño, rutas, backend ni tenancy. `window.Fire` se conserva.

## 1. Cambios realizados

### `.__vue__` (13 → 0)
Cuatro scripts sueltos (`resources/static/prodex-*.js`) leían la instancia interna de Vue desde el DOM (`el.__vue__`) para obtener el router, los permisos y el editor de permisos. Ahora usan un **puente explícito** `window.__prodexBridge` (`platform/legacy-bridge.js`, instalado en `main.js` tras montar la app): `navigate(path)` (nunca rechaza), `getPermissions()`, `planFeature(key)` y `permissionEditor()/registerPermissionEditor()`. Es un adaptador temporal para scripts fuera del bundle; no expone internos de Vue. Un test recorre `resources/src` y `resources/static` y falla si reaparece `.__vue__`, `.__vue_app__`, `._vnode`, `.$vnode` o `__vueParentComponent`.

Los dos tests PHP de arquitectura que fijaban el texto antiguo (`router.push(...)`, `resolveRouter`) se actualizaron al nuevo contrato (`window.__prodexBridge`, `bridge.navigate(...)`, ausencia de `__vue__`).

### Slots (`slot="…"` 154 y `slot-scope` 204 → 0)
96 archivos `.vue` migrados a `v-slot` / `#nombre` con un codemod basado en el AST del compilador de Vue (`vue-template-compiler`), no con expresiones regulares. Reglas aplicadas: `slot="x"` sobre un elemento → `<template #x>` envolviéndolo; `slot="x"` + `slot-scope="p"` sobre `<template>` → `<template #x="p">`; elementos contiguos con el mismo slot se fusionan; un `v-if` del elemento pasa a la `<template>`. La semántica se comprobó con BootstrapVue 2.23 (`b-table`, `b-dropdown`, `b-tabs`, `b-modal`) y vue-good-table (`table-row`, `table-actions`, `emptystate`, `selected-row-actions`) y con los slots de los componentes propios: no quedó ningún slot sin migrar ni documentado como imposible.

### Capa de validación PRODEX sobre vee-validate 3
`resources/src/platform/validation/`:

| Archivo | Contenido |
|---|---|
| `vee-adapter.js` | **Único** módulo que importa `vee-validate`. Exporta `PxValidationProvider` / `PxValidationObserver` (`extends` de los componentes de vee-validate: mismas props, slot props, detección de `v-model` y métodos) e `installValidation(Vue)` (mensajes en español, reglas, regla `url`, registro de componentes; alias globales antiguos `ValidationProvider` / `ValidationObserver` mientras haya pantallas sin migrar) |
| `contract.js` | Contrato documentado (props, slot props, `validate`, `reset`, `setErrors`, `handleSubmit`) y helpers sin dependencia de la librería: `validateForm`, `resetForm`, `setFormErrors`, `submitForm` |
| `index.js` | Exportaciones |

`main.js` y `login.js` dejaron de importar vee-validate: llaman a `installValidation(Vue)`. Las vistas usan `<px-validation-provider>` / `<px-validation-observer>` (mismo comportamiento y slot props). Al migrar a Vue 3 solo se reescribe `vee-adapter.js`.

Consumidores migrados (renombrado de etiquetas, sin tocar lógica): 59 archivos de HRM, personas, reclutamiento, marketing, activos, tareas, servicio técnico, proyectos, reuniones, base de conocimiento, contratos, reservas, perfil y ajustes no fiscales. **No** se migraron (lista en `tests/frontend/validation-legacy-allowlist.txt`, con guarda): POS, caja, pagos, ventas, compras, devoluciones, cotizaciones, transferencias, inventario/ajustes/daños, productos, gastos, depósitos, cuentas, facturación/SAR, suscripciones de producto, sesiones y `CustomFieldsForm` (lo usa el POS): 65 archivos.

### APIs obsoletas de Vue 2
- `.sync` (24, todas en `PxTable` / `PxToolbar` / `PxPagination` / `CustomerLedger`) → forma explícita `:x="v" @update:x="v = $event"`, con el evento en camelCase que emiten esos componentes.
- `.native` sobre componentes propios que ya reenvían `$listeners` al elemento nativo (`px-input`, `px-button`; 4 usos) → `@keyup.enter` / `@click.stop`.

## 2. Métricas (antes → después)

| Métrica | Antes | Después |
|---|---|---|
| `.__vue__` | 13 (4 archivos) | **0** |
| `slot-scope` | 204 (88 archivos) | **0** |
| `slot="…"` | 154 (75 archivos) | **0** |
| `.native` | 9 (6 archivos) | 5 (2 archivos) |
| `.sync` (modificador en plantillas) | 24 | **0** |
| `$listeners` | 6 (5 archivos) | 6 (5 archivos) |
| `$scopedSlots` | 1 | 1 |
| `$children` | 0 | 0 |
| `beforeDestroy` | 42 (41 archivos) | 42 (41 archivos) |
| `destroyed` | 1 | 1 |
| `<ValidationProvider>` (etiquetas) | 583 (101 archivos) | 339 (55 archivos) |
| `<ValidationObserver>` (etiquetas) | 159 (122 archivos) | 88 (63 archivos) |
| `<px-validation-provider>` / `-observer>` | 0 / 0 | 244 / 71 (más 2 y 2 menciones en comentarios) |
| Importaciones directas de `vee-validate` | 4 líneas en 2 archivos (`main.js`, `login.js`) | **1** archivo (`vee-adapter.js`) |
| Archivos de vista migrados a la capa de validación | 0 | 59 |

(`.sync` antes contaba 35 coincidencias en 17 archivos; 11 eran selectores CSS `.sync-card`, no el modificador.)

## 3. Bloqueadores eliminados
`.__vue__` (rompía con `@vue/compat`: `el.__vue__` undefined) · `slot` / `slot-scope` (`@vue/compat` los descartaba en silencio) · acoplamiento directo a la librería de validación (la detección de `v-model` de vee-validate 3 no funciona en compat; ahora hay un único punto que cambiar) · `.sync` · `.native` sobre componentes propios.

## 4. Bloqueadores restantes
- **`.native` en `<router-link>`** (`PxShell.vue` ×4, `PortalLayout.vue` ×1): vue-router 3 no emite `click` en `router-link`; vue-router 4 tampoco admite `.native`. Se resuelve con la migración del router, no antes.
- **`$listeners`** (`PxInput`, `PxButton`, `PxCheck`, `PxTextarea`, `VsPx`): soportado por `@vue/compat` con aviso; en Vue 3 real hay que pasar a `$attrs` (`onX`).
- **`$scopedSlots`** (`PxModal`): el pie usa `#footer="{ close }"` (slot con parámetros, solo visible en `$scopedSlots` de Vue 2). En Vue 3 `$slots` lo cubre.
- **`beforeDestroy` (42) / `destroyed` (1)**: Vue 2.7 no acepta `beforeUnmount` / `unmounted`; `@vue/compat` los acepta con aviso. Renombrar antes de tiempo rompería Vue 2.
- **Validación**: 65 pantallas (dinero, inventario crítico, facturación, POS, sesiones) siguen con las etiquetas antiguas (alias en `installValidation`); migrarlas es un renombrado idéntico cuando exista cobertura E2E de esas pantallas. Además, la detección de `v-model` de vee-validate 3 dentro de slots de componentes (ver `px-next-veevalidate-pxfield-slot`) sigue siendo un bloqueador propio de vee-validate 3 en compat.
- `router-link` de vue-router 3 sin `<a>` y `$swal` no instalado bajo compat (hallazgos del spike de la auditoría): fuera de esta fase.
- 15 vistas con slots migrados no tienen ruta sin parámetros y no entran en el recorrido E2E (se compilan y comparten patrones con las demás).
- `window.Fire` y `installVue2Platform`/`legacy-bridge` son adaptadores temporales.

## 5. Estado de validación

| Prueba | Resultado |
|---|---|
| `npm run test:frontend` | 63 tests OK (bus, notificaciones, confirmaciones, modales, adaptador, puente legacy, scripts estáticos con guarda de internos de Vue, guarda AST de slots antiguos, contrato de validación y guarda de importaciones de vee-validate) |
| PHPUnit Unit | 1328 / 1328 |
| PHPUnit Feature | 934 OK, 3 omitidos |
| E2E | 52 / 52 (50 tests + 2 de sesión; dos corridas completas, la segunda tras `E2E_RESET=1`; incluye 3 specs nuevas: `13-slots-equivalence` 11 tests en navegador con los UMD reales de Vue/BootstrapVue/vue-good-table; `14-validation-layer`; `15-slot-converted-pages`, recorrido de 83 pantallas migradas con fallo ante cualquier error JS o 5xx) |
| Route snapshot | 474 tenant / 20 portal, sin cambios |
| Build desarrollo | OK |
| Build producción (copia fuera del repo) | OK; `main.min.js` 2 394 912 B (+1 467 B frente a fase 3), 652 chunks |

Excepción registrada en `allowlist.js`: `GET /images/tenants/<id>/products/` 404 en la lista clásica de productos (producto sin imagen; preexistente, la plantilla de la celda no cambió).

## 6. Qué falta antes de probar `@vue/compat`
1. Un spike en rama aparte con `@vue/compat` (modo 2 por componente) y `vue-loader` 16/17; los bloqueadores restantes de la sección 4 aparecerán como avisos, no como fallos silenciosos.
2. Decidir el destino de `router-link` (vue-router 4) y de `vue-i18n` (v9 legacy API) antes de la migración real: compat no los sustituye.
3. Sustituir `vue-sweetalert2` (`$swal`) por el servicio `confirm` en las 93 confirmaciones pendientes (fase de servicios).
4. Cobertura E2E de POS, caja, pagos e inventario crítico para poder migrar las 65 pantallas restantes a la capa de validación.
5. Renombrar `beforeDestroy` / `destroyed` y `$listeners` en el momento del salto real (no antes).
