# Servicios de plataforma del frontend (fase 3)

Base: `fc69bb2`. Objetivo: que las vistas dejen de depender de Vue 2 / BootstrapVue para **eventos, notificaciones, confirmaciones y modales por id**, sin cambiar comportamiento ni diseño. No se tocó Vue, Router, Vuex, vue-i18n, Bootstrap, BootstrapVue, vee-validate, Vite ni TypeScript; ni POS, caja, `ModernPaymentModal` ni el flujo offline.

## APIs

Todo vive en `resources/src/platform/` (ESM puro, sin librerías nuevas, `package.json` con `"type": "module"` para que `node --test` lo cargue sin compilar):

| Módulo | API | Qué sustituye |
|---|---|---|
| `events.js` | `createEventBus()`, `events`; `$on/$once/$off/$emit` (semántica de Vue 2) y `on/once/off/emit` (`on` devuelve el "unsubscribe") | `window.Fire = new Vue()` |
| `notifications.js` | `notifications.notify(msg, opts)` (reenvía las opciones tal cual), `success/error/warning/info` | `this.$root.$bvToast.toast(msg, opts)` |
| `confirm.js` | `confirm(opciones)` → `Promise<boolean>`; presentaciones `'swal'` (defecto) y `'modal'` | `this.$swal({ showCancelButton: true, … }).then(r => r.value)` y `this.$bvModal.msgBoxConfirm(...)` |
| `modals.js` | `modals.show(id)`, `modals.hide(id)` | `this.$bvModal.show/hide(id)` |
| `adapters/vue2.js` | `installVue2Platform(rootVm)` | Único archivo que conoce `$bvToast`, `$bvModal` y `$swal` |
| `index.js` | Exporta todo; `confirmDialog` es el mismo objeto que `confirm` (en `.vue` se importa con ese nombre para no tapar el `confirm()` global del navegador) | |

Flujo: **vista → servicio PRODEX → driver → BootstrapVue/SweetAlert2**. Los drivers se instalan una vez, con la instancia raíz, al arrancar (`main.js`, `login.js`). Antes de eso, las notificaciones se guardan (máx. 50) y salen al instalar el driver.

`confirm` normaliza `title`, `message` (alias `text`), `confirmText` (alias `confirmButtonText`), `cancelText` (alias `cancelButtonText`) y `variant`; **cualquier otra clave** (`type`, `icon`, `confirmButtonColor`, `size`, `okTitle`…) pasa sin cambios al driver, así que las llamadas existentes se migran conservando textos y aspecto.

## `window.Fire` ahora

`window.Fire` **ya no es una instancia de Vue**: es el bus de `events.js`, con los mismos `$on/$off/$once/$emit`. Los 331 consumidores no cambiaron (verificado: solo usan esos métodos y comprobaciones tipo `window.Fire && window.Fire.$emit`). Se reprodujeron las reglas de Vue: varios listeners en orden, `$off()` / `$off(e)` / `$off(e, fn)` (quita la última instancia), `$once` que se auto-elimina y se cancela con el `fn` original, emisión sobre una copia (los listeners pueden desuscribirse durante la emisión), y un listener que lanza un error se reporta con `console.error` sin impedir a los demás. Es un **adaptador temporal**: código nuevo usa `events` de `@/platform`. `window.CD` (customer display, sin usos) también dejó de ser un `new Vue()`.

## Consumidores migrados

87 archivos `.vue` (86 vistas + el `customizer`), elegidos por bajo riesgo y transformados con un codemod conservador (solo patrones equivalentes; revisado y comprobado sintácticamente). Áreas: reportes (`reports/next`, `pages/reports`), HRM (sin `payrolls`), reclutamiento, marketing, reuniones, inmobiliaria, base de conocimiento, WhatsApp, soporte, proyectos, tareas, contratos, activos, servicio técnico, suscripciones de producto, perfil, traducciones, tienda (banners/mensajes/suscriptores) y el `customizer`. Migrado: 89 llamadas a toast (solo `this.$root.$bvToast`, mismo origen que el servicio), 33 `show/hide` por id, 7 `msgBoxConfirm` (presentación `modal`) y 23 confirmaciones SweetAlert (`.then(r => r.value)` → `.then(confirmed => …)`).

Excluido a propósito: POS, caja, `ModernPaymentModal`, `PosReturnModal`, `posKeyboardShortcuts`, transferencias, ventas, compras, devoluciones, cotizaciones, ajustes/daños, contabilidad, comisiones, personas, productos, facturación, ajustes del sistema y sesiones (login/reset).

## Métricas (antes → después)

| Métrica | Antes | Después |
|---|---|---|
| `new Vue()` usados como bus de eventos | 3 (`window.Fire` ×2, `window.CD`) | **0** |
| `new Vue()` en total (raíces de app) | 7 | 5 (los otros 2 eran buses) |
| Asignaciones a `window.Fire` | 2 (`new Vue()`) | 2 (`= events`, adaptador) |
| `Fire.$on/$emit/$off/$once` | 331 (68 archivos) | 331 (sin cambios, por diseño) |
| `$bvToast` (referencias / archivos) | 333 / 248 | 248 / 185 |
| `$bvToast.toast(` (llamadas) | 272 | 185 (incluye la del driver) |
| `$bvModal` (referencias / archivos) | 218 / 52 | 185 / 40 |
| `$bvModal.show` / `.hide` | 106 / 89 | 85 / 81 |
| `$bvModal.msgBoxConfirm` | 11 | 5 |
| `$swal(` directas (confirmaciones + alertas) | 350 en 93 archivos | 329 en 92 |
| Archivos migrados a servicios PRODEX | 0 | 87 `.vue` (+ `main.js`, `login.js`, `customer-display.js`) |
| `main.min.js` (producción) | 2 377 981 B | 2 393 445 B (+15 KB: servicios + 87 imports repartidos por los chunks; sin efecto en funcionalidad) |
| Chunks | 652 | 652 |

## Pruebas

- **`npm run test:frontend`** (`node --test`, sin navegador ni dependencias nuevas): 45 tests — 21 del bus (varios listeners, argumentos, `off`, `once`, errores, desuscripción durante la emisión, compatibilidad con `window.Fire`), 7 de notificaciones, 8 de confirmaciones, 3 de modales y 6 del adaptador Vue 2 con un root simulado.
- **E2E `12-platform-services`** (3 tests, contra el bundle real): `window.Fire` no es Vue y funciona; plantilla de marketing con modal por id + toast + confirmación SweetAlert (cancelar y aceptar); confirmación `modal` (activos) cancelable.
- Se añadió `npm run test:frontend` al job `route-snapshot` del workflow.

## Deuda restante

- **Consumidores:** 185 archivos con `$bvToast`, 40 con `$bvModal`, ~330 `$swal` (de ellos ~93 confirmaciones sin migrar y ~220 alertas de resultado `$swal(título, texto, 'success')`, que no son confirmaciones), 331 usos de `Fire.$…`. Las áreas de dinero/inventario/POS quedan para cuando exista cobertura E2E de esas pantallas.
- **Fuera del alcance:** `this.$bvToast.toast(` sin `$root` (34 sitios: el toast queda hijo del componente y se destruye con él; migrarlo cambiaría ese detalle), usos en plantillas (`@click="$bvModal.show('x')"`, `v-b-modal`), y las alertas `$swal(título, texto, icono)`.
- Los propios `<b-modal>`, `b-toast` (contenedor) y SweetAlert2 siguen siendo BootstrapVue/SweetAlert2 dentro del driver.

## Qué se elimina al entrar en Vue 3

- `window.Fire` (adaptador) cuando los 331 consumidores usen `events`; el bus ya no tiene nada de Vue, así que no cambia.
- `adapters/vue2.js` se reemplaza por un adaptador que use `PxToast`, `PxConfirm` y `PxModal`; **las vistas migradas y el resto de servicios no se tocan**.
- Con esto desaparecen del código de vistas las dependencias de `$bvToast`, `$bvModal.show/hide/msgBoxConfirm` y (en confirmaciones) de `vue-sweetalert2` (`$swal`), que la auditoría identificó como los puntos de acoplamiento a BootstrapVue con más usos (`$bvToast` 277, `$bvModal` 256, `$swal` 369).
