# vee-validate 3 → 4

Rama `refactor/vee-validate-4`, desde `41e2f557` (fin de la fase 5C de Bootstrap 5). Objetivo: migrar la librería de validación sin cambiar la API que consumen las vistas — `<px-validation-provider>` / `<px-validation-observer>` (y sus alias legacy `<validation-provider>` / `<validation-observer>`) siguen exponiendo el mismo contrato documentado en `resources/src/platform/validation/contract.js`.

## 1. Auditoría

- 60 usos de `<validation-provider>`/`<v-field>` en un solo archivo (`Add_product.vue`, el máximo del proyecto); ~65 vistas críticas (POS, caja, pagos, inventario, facturación) siguen con los nombres legacy `<validation-provider>`/`<validation-observer>` vía alias.
- Reglas built-in usadas: `required`, `size`, `mimes`, `email`, `confirmed` (cruzada, `reset.vue` y `profile.vue`), y una regla propia `url` (protocolo http/https). Sin `extend()` de reglas adicionales más allá de esa.
- Sin `mode=`, `bails` (siempre el valor por defecto), ni validaciones async propias. `debounce` usado en 6 campos. `vid` en 2. `immediate` en 18.
- API interna (no documentada en `contract.js`, pero usada directamente desde ~30 vistas): `$refs.xProvider.syncValue(valor)` para empujar un valor sin pasar por la detección de VNodes — típicamente seguido de `.validate()`.
- `platform/validation/vee-adapter.js` era ya el único módulo que importaba `vee-validate` (test de aislamiento existente); las vistas nunca importan la librería.

## 2. Contrato v3 congelado

Ya existía antes de esta fase: `tests/frontend/validation-contract.test.mjs` (funciones puras de `contract.js`, independientes de la librería) y dos specs E2E dedicados —`14-validation-layer.spec.js` (formulario real de plantillas de marketing + una pantalla con alias legacy) y `34-forms-validation.spec.js` (10 tipos de control de BootstrapVueNext × required/corrección/envío-único/reset, más `observer.validate()`/`setErrors()`) — que ejercitan el comportamiento observable exacto contra el bundle real. Sirvieron de suite de regresión para la migración sin tener que escribirlos de nuevo.

## 3. Estrategia: useField/useForm en vez de extends

vee-validate 4 no tiene componentes de clase heredables (`ValidationProvider`/`ValidationObserver` de la 3, sobre los que la fase 5B había montado un `extends` + `render` de compat para Vue 3). Su forma nativa es `<Field>`/`<Form>`, que no sirven aquí: PRODEX no ata la validación a un componente `<Field>` con v-model propio, envuelve el control real tal cual estaba en el marcado y lo detecta por inspección de VNodes (para que funcione dentro del slot de `<b-form-group>`, `<px-field>`, `<v-field>`…).

`PxValidationProvider`/`PxValidationObserver` se reescribieron como componentes de composición (`defineComponent` + `setup()`) sobre `useField`/`useForm`:

- La detección de campos por VNode (antes `vee-compat-provider.js`, atada a los métodos internos de la clase v3) pasa a `vee-field-bridge.js`: mismas funciones (`describeField`, `processVNodes`, `addListener`…), ahora independientes de la librería de validación (solo tocan VNodes de Vue 3).
- `installValidation()` registra reglas con `defineRule` (la 4) en vez de `extend` (la 3); los mensajes en español se registran con `configure({ generateMessage })` en vez de `localize()` (removido en la 4, que ya no reexporta i18n).
- Reglas built-in: de `vee-validate/dist/rules.js` (empaquetadas en la 3) a `@vee-validate/rules` (paquete aparte en la 4, mismo comportamiento para `required`/`size`/`mimes`/`email`/`confirmed`/`min`/`max`).
- El flag `validated` de cada campo (del que depende `VField.vue`: `v.touched || v.validated`) es el propio `field.meta.validated` de vee-validate 4, no una copia local — así lo pone `true` tanto una validación del campo como una `observer.validate()`/`setErrors()` de todo el formulario.
- `provider.syncValue(valor)` se añadió al contrato expuesto (no estaba en `contract.js`, pero rompía ~30 vistas sin él): equivale a que el control real hubiera cambiado.

## 4. Bugs encontrados y corregidos durante la migración

Ninguno estaba en el contrato v3 documentado; los cuatro aparecieron al ejercitar formularios reales (no solo el spec de validación) y se corrigieron antes de considerar la fase cerrada:

1. **Valor inicial perdido en reset.** `useField` no conoce el valor inicial real de un campo hasta que la primera detección de VNode lo informa; `observer.reset()`/`provider.reset()` sin ese dato volvían el campo a `undefined` en vez de a su valor real. Se captura en el primer `bindField` y se informa a la FORM (`stageInitialValue`) además del propio campo.
2. **Validación prematura en el montaje.** La primera vez que se ve un campo se comparaba contra el valor `undefined` por defecto y eso se leía como "cambió", disparando una validación en cuanto se montaba (antes de que el usuario tocara nada). Se distingue explícitamente el primer valor visto (solo fija el estado) de un cambio posterior.
3. **"Maximum recursive updates exceeded" en formularios con muchos campos.** Sincronizar el valor inicial de cada campo muta `formValues` — una dependencia de `form.meta`, que el observer ya había leído al empezar a renderizar antes de invocar el slot que (a través de cada provider) llama a `bindField`. En un formulario de 40+ campos (`products/next/create/index.vue`), esa mutación durante el propio render del observer lo reprograma sobre sí mismo y Vue lo corta con ese error. Se difiere a fuera del render (varios `nextTick` en tandas pequeñas, no uno por campo — ver §6) y se resolvió también un caso más fino: un valor "igual" pero con otra referencia (un multi-select recalculando su array de opciones en cada render) igual contaba como cambio y realimentaba el ciclo; ahora solo se escribe cuando el contenido de verdad difiere.
4. **`provider.setErrors()` pisado por una validación tardía.** Si una validación automática seguía en curso (promesa sin resolver) cuando algo más autoritativo pasaba — un `reset()` o un `setErrors()` — su resultado llegaba después y sobreescribía el estado correcto que esa llamada ya había dejado. El observer avisa a sus providers de cada `reset()` con un token (`provide`/`inject`); si una validación resuelve después de que el token cambió, se reaplica el resultado correcto en vez del obsoleto.

## 5. Formularios y pantallas verificados

`14-validation-layer.spec.js` y `34-forms-validation.spec.js` (los mismos specs congelados en el paso 2) pasan íntegros contra el bundle de producción. `33-forms-contract.spec.js` (grabado de BootstrapVue 2, 53 casos) pasa salvo el caso `datepicker: abierto` — una fecha "(Today)" grabada en un día distinto al de ejecución, deriva del fixture ajena a la validación. `36-bootstrap5-cutover.spec.js` (BS4 retirado, hoja BS5, utilidades) sin regresión. Las 65 pantallas con alias legacy y las ~285 restantes migradas comparten el mismo `installValidation()`, sin distinción de código entre ellas.

## 6. Rendimiento en formularios grandes (seguimiento pendiente)

`Add_product.vue` (60 campos) carga en ~150–200 ms de forma aislada (navegación directa, contexto de navegador nuevo) sin ningún error — confirmado repetidamente. Bajo la prueba E2E `15-slot-converted-pages.spec.js` (83 rutas navegadas en secuencia dentro de la MISMA pestaña) y `29-modals-matrix.spec.js` (patrón similar), esa misma ruta hace que la pestaña se quede sin memoria y la página colapse (`page.goto: Page crashed` / `Test timeout of 180000ms exceeded`) — un patrón que **no** reproduce en uso aislado ni con un puñado de navegaciones previas (verificado con 5 navegaciones seguidas: sigue en ~1,4 s). Se aplicó una mitigación (repartir el sync inicial de todos los campos en tandas de 12 entre varios `nextTick` en vez de un solo lote síncrono; ver bug 3 arriba) que bajó el caso aislado lento de 30 s a 1,4 s, pero **no elimina el colapso** bajo la prueba completa de 83 navegaciones seguidas. Sigue el único fallo real conocido de esta fase — no bloquea el uso normal de la pantalla, pero queda como seguimiento: perfilar memoria de Chrome a través de ~80 navegaciones completas seguidas para aislar si el origen es el conteo de campos de esta vista en particular, la propia prueba (patrón de estrés poco realista) o un problema más general de esta versión de Bootstrap/BootstrapVueNext bajo presión de memoria.

## 7. vee-validate 3 retirado

`package.json`/`package-lock.json`: `vee-validate` en `^4.15.1`, `@vee-validate/rules` añadido. `vee-compat-provider.js` (bridge `extends: ValidationProvider` de la fase 5B) eliminado; sustituido por `vee-field-bridge.js`. Guardas nuevas en `tests/frontend/veevalidate4-migration.test.mjs`: el paquete instalado es 4.x con `@vee-validate/rules`, el adaptador importa `useField`/`useForm` (no `ValidationProvider`/`ValidationObserver` como clase, no `extends: Validation…`), usa `defineRule`/`configure` (no `extend`/`localize`). `.npmrc` con `legacy-peer-deps=true`: el proyecto ya convivía con un conflicto de peer dependency preexistente (`lucide-vue` pide Vue 2, el proyecto usa Vue 3); `npm ci` volvía a re-resolverlo estrictamente en cuanto el lockfile cambiaba de forma significativa (como al añadir vee-validate 4), y sin ese flag fallaba en un clon limpio aunque `npm install` local lo hubiera aceptado.

## 8. Avisos de @vue/compat

Con la corrida completa (348 pruebas, 2 fallos por el problema de memoria de la sección 6 que cortan esas pruebas antes de terminar de capturar avisos): **28.043 mensajes, 32 únicos, en 319 tests** con avisos capturados. `vee-validate` ya no aparece como origen `RENDER_FUNCTION`/`PRIVATE_APIS` vía la clase `ValidationProvider` de la 3 (su render ahora es un componente de composición normal de Vue 3, sin necesitar el modo de compatibilidad de render de Vue 2 que sí usaba `extends`). Comparación exacta por instancia (atribución `warnings-by-origin.js`, sin sonda) quedó pendiente de esta corrida por tiempo — el número total baja de forma consistente con quitar una clase que forzaba `RENDER_FUNCTION`+`PRIVATE_APIS` en cada campo.

## 9. Validación

- `npm run test:frontend`: 135/0 (131 previos + 4 nuevos de esta fase).
- PHP `Unit` 1328, `Feature` 934+3 skipped (sin cambio frente a la línea base).
- `npm run test:e2e:routes`: 474 rutas tenant / 20 portal, sin cambio.
- E2E completo (`E2E_RESET=1`): 348 ejecutadas, 3 fallos — 1 preexistente (fixture de fecha, sección 5), 2 por el problema de memoria de la sección 6. El resto, incluidos los dos specs dedicados a validación y el de contrato grabado de BV2, verde.
- `npm run production`: compila limpio. `login.min.js` 743.554 → 746.938 B (+3.384; las pantallas de sesión usan `validation-provider`, así que sí cargan la librería — esperado, es la 4 en vez de la 3, no un aumento de superficie). `main.min.js` 2.270.435 → 2.275.113 B (+4.678).
- `npm ci` desde un clon limpio: correcto (con `.npmrc`, sección 7). `npm ls vee-validate @vee-validate/rules`: 4.15.1, sin ningún `vee-validate@3.x` en el árbol.
- Smoke de producción (specs `@smoke`, sin las de sonda de desarrollo 32/33/34/36): 220 ejecutadas, 10 fallos — 8 son la dependencia de orden preexistente de `31-tables-matrix.spec.js` (documentada en la fase 5C, reproduce igual antes de esta migración), 2 son el problema de memoria de la sección 6.
- Acciones de GitHub: **no está en verde.** Corrida `36071676110` (job `e2e`, 67 min, presupuesto subido a 90 min — ver §6): 325 pasaron, 3 fallaron, 1 omitida — los mismos tres fallos de siempre (el problema de memoria de la sección 6, dos veces, y la fixture de fecha preexistente); `route-snapshot` verde. No se declara el criterio de dos corridas verdes consecutivas cumplido: el fallo es real y reproducible, no un problema de infraestructura.

## 10. Fuera de alcance (sin tocar)

vue-i18n 8, Vuex 3, vue-good-table, vue-select, vue2-daterange-picker, VuePerfectScrollbar, `@vue/compat`, Vite, TypeScript.

## 11. Próxima fase recomendada

Con vee-validate ya en la 4 (composición de Vue 3, sin `extends`), el bloqueante de validación para retirar `@vue/compat` queda resuelto; el trabajo pendiente de la fase anterior (Bootstrap 5) apuntaba a vue-i18n 8 o vee-validate 3 como los dos bloqueantes restantes — con este quitado, sigue vue-i18n 8. Antes de esa migración, conviene cerrar el seguimiento de rendimiento de la sección 6 (perfilar la causa exacta del colapso de memoria bajo navegación secuencial intensiva).
