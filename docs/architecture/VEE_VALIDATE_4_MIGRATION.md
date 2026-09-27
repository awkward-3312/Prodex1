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

`14-validation-layer.spec.js` y `34-forms-validation.spec.js` (los mismos specs congelados en el paso 2) pasan íntegros contra el bundle de producción (16/16). `33-forms-contract.spec.js` (grabado de BootstrapVue 2, 53 casos) pasa salvo el caso `datepicker: abierto` — una fecha "(Today)" grabada en un día distinto al de ejecución, deriva del fixture ajena a la validación y a esta fase (preexistente, ver §9). `36-bootstrap5-cutover.spec.js` (BS4 retirado, hoja BS5, utilidades) sin regresión. Las 65 pantallas con alias legacy y las ~285 restantes migradas comparten el mismo `installValidation()`, sin distinción de código entre ellas.

## 6. Colapso de memoria bajo navegación secuencial intensiva — RESUELTO

### Causa raíz

`bindField()` en `vee-adapter.js` tenía DOS rutas de escritura del valor de un campo: la del sync inicial (ya diferida a `nextTick` desde el bug 3 de §4) y la de **estado estable** (cambios posteriores al montaje, detectados en cada pasada de `processVNodes`). La segunda escribía `field.value.value = newValue` de forma **síncrona, dentro del propio render de `<PxValidationObserver>`**:

```js
// ANTES (bug):
if (mounted) {
  onFieldValue(described.value, false);   // escribe formValues YA, durante el render
}
```

`field.value.value` tiene un setter de vee-validate 4 que escribe directamente en `formValues` — una dependencia reactiva que el observer **ya leyó** al empezar a renderizar, antes de invocar el slot que (a través de cada provider) llama a `bindField`. Mutar esa dependencia desde dentro del propio render reprograma el efecto de render del observer sobre sí mismo. Vue lo corta a los ~100 ciclos con `"Maximum recursive updates exceeded in component <PxValidationObserver>"`.

En navegación aislada (una sola carga de `/app/products/store-classic`) esto no se veía: rara vez hay un campo cuyo valor "vivo" difiera del ya almacenado en la primera pasada tras el montaje. Bajo navegación secuencial intensiva (83 rutas en la misma pestaña, `15-slot-converted-pages.spec.js`/`29-modals-matrix.spec.js`), el campo `category` de `Add_product.vue` (un input oculto sentinel `product.category_id` compartiendo slot con un `<v-select multiple>` real no atado a la validación) entraba en esta ruta cientos de veces por segundo, saturando el hilo principal hasta el colapso (`Page crashed` / timeout) — no una fuga de memoria acumulada entre navegaciones, sino un patrón de mutar-durante-el-propio-render que Vue detecta y penaliza con reintentos masivos de render antes de cortar.

### Fix aplicado

`resources/src/platform/validation/vee-adapter.js`: la ruta de estado-estable ahora encola la escritura por el mismo mecanismo de batching que ya diferían los syncs iniciales (renombrado `scheduleInitialSync` → `scheduleFormWrite`, ya que ahora sirve a ambos casos), con una guarda `deepEqual` previa para no encolar jobs sin cambio real:

```js
if (mounted) {
  if (!deepEqual(field.value.value, described.value)) {
    scheduleFormWrite(() => onFieldValue(described.value, false));
  }
}
```

Ninguna escritura de `formValues` ocurre ya de forma síncrona dentro del render del observer, ni en el sync inicial ni en cambios posteriores.

### Auditoría de retenciones (§3/§7 del encargo)

- `vee-field-bridge.js` (sospechoso prioritario): completamente stateless — sin Maps/Sets de módulo, sin caché de VNodes, sin timers propios; `describeField`/`processVNodes`/`addListener` operan solo sobre el VNode/lista recibidos por parámetro. El único cierre creado en cada render (`wrappedSlot`) vive solo en el árbol de VNodes transitorio que Vue ya gestiona — no hay retención más allá de eso. Confirmado limpio, nunca fue el origen de la fuga.
- `vee-adapter.js`: la única estructura módulo-nivel es `pendingFormWrites` (array de `{ fn }`), con cancelación explícita en `onBeforeUnmount` del provider (`cancelPendingInitialSync()`, pone `fn = null`) — ningún VNode ni instancia de componente se guarda ahí, solo closures que se anulan al desmontar antes de su turno. `provide`/`inject` (token de reset) usa el propio mecanismo de Vue, limpiado por el framework al desmontar. Ningún `useField`/`useForm` se crea más de una vez por componente. `keepValueOnUnmount`/`keepValues` no se usan en ningún punto (verificado, §5 del encargo).
- Contadores dev-only (`__pxCounters`, expuestos como `window.__pxValidationCounters` solo fuera de producción): `providersMounted`/`Unmounted`, `observersMounted`/`Unmounted`, `pendingSyncJobs`, `pendingSyncJobsCancelled`. Confirmado ausente del bundle de producción (`grep -rl __pxValidationCounters public/js/` sin resultados tras `npm run production`).

### Medición de memoria (§9 del encargo)

CDP (`Performance.getMetrics` tras `HeapProfiler.collectGarbage` ×4), 3 ciclos de las 83 rutas de `slot-converted-routes.json`, midiendo en `/app/products/store-classic` y al final de cada ciclo:

| ciclo | punto | heap JS (bytes) | nodos DOM | providers | observers | pendingSyncJobs |
|---|---|---|---|---|---|---|
| 0 | store-classic | 29.019.148 | 3125 | 22 | 5 | 0 |
| 0 | fin de ciclo | 19.724.456 | 3203 | 0 | 3 | 0 |
| 1 | store-classic | 29.091.748 | 3133 | 22 | 5 | 0 |
| 1 | fin de ciclo | 19.713.052 | 3203 | 0 | 3 | 0 |
| 2 | store-classic | 29.092.440 | 3133 | 22 | 5 | 0 |
| 2 | fin de ciclo | 18.950.060 | 3189 | 0 | 3 | 0 |

Heap en `store-classic` prácticamente plano entre ciclos (+72.600 B ciclo 0→1, +692 B ciclo 1→2 — no lineal, no creciente). `pendingSyncJobs` en 0 en cada medición: sin jobs colgados. Cada `page.goto` en este entorno es una navegación real de Playwright (recarga completa), por eso el conteo de providers es idéntico en cada ciclo en vez de acumularse — el criterio real (§9 del encargo) es que no colapse y el heap no crezca de forma acumulada, y ambos se cumplen: `CRASHED: false`, 3/3 ciclos completos.

Reproducción directa con los specs reales (misma pestaña, 83 navegaciones seguidas): `15-slot-converted-pages.spec.js` — verde, 4,9 min (antes: colapso). `29-modals-matrix.spec.js` (39 casos, incluye `Add_product` con 5 modales) — verde, 39/39, `store-classic` en 14,9–17,2 s por corrida.

## 7. vee-validate 3 retirado

`package.json`/`package-lock.json`: `vee-validate` en `^4.15.1`, `@vee-validate/rules` añadido. `vee-compat-provider.js` (bridge `extends: ValidationProvider` de la fase 5B) eliminado; sustituido por `vee-field-bridge.js`. Guardas nuevas en `tests/frontend/veevalidate4-migration.test.mjs`: el paquete instalado es 4.x con `@vee-validate/rules`, el adaptador importa `useField`/`useForm` (no `ValidationProvider`/`ValidationObserver` como clase, no `extends: Validation…`), usa `defineRule`/`configure` (no `extend`/`localize`). `.npmrc` con `legacy-peer-deps=true`: el proyecto ya convivía con un conflicto de peer dependency preexistente (`lucide-vue` pide Vue 2, el proyecto usa Vue 3); `npm ci` volvía a re-resolverlo estrictamente en cuanto el lockfile cambiaba de forma significativa (como al añadir vee-validate 4), y sin ese flag fallaba en un clon limpio aunque `npm install` local lo hubiera aceptado.

## 8. Avisos de @vue/compat

Con el fix de la sección 6, la corrida completa ya no corta pruebas a mitad por el colapso de memoria: **30.680 mensajes, 31 únicos, en 338 tests** con avisos capturados (antes del fix: 28.043 mensajes, 32 únicos, en 319 tests — 19 tests menos porque el colapso cortaba esas pruebas antes de terminar de capturar avisos). `vee-validate` ya no aparece como origen `RENDER_FUNCTION`/`PRIVATE_APIS`: esos orígenes ahora se atribuyen solo a `VuePerfectScrollbar`/`VueGoodTable`/`VSelect`/`LucideIcon`, ninguno a la clase `ValidationProvider` de la 3 (eliminada, sección 7).

Un aviso nuevo, no presente con la 3: `injection "Symbol(vee-validate-form-context)" not found` (170 ocurrencias, origen `PxValidationProvider`, riesgo "comportamiento"). Es el propio `useFormContext()` de vee-validate 4 avisando cuando un provider se usa sin un observer que lo envuelva — un patrón que el contrato de PRODEX sí soporta (providers independientes). Vee-validate 3 no emitía este aviso porque su `inject` interno no pasaba por el mecanismo de warning de Vue. Benigno, sin efecto funcional (confirmado: `runValidate`/`reset`/`setErrors` funcionan igual con o sin observer envolvente, specs de §5 y §9). Queda anotado para una limpieza cosmética futura (pasar `{ optional: true }` o silenciarlo explícitamente donde el patrón es intencional), fuera del alcance de esta fase.

## 9. Validación

- `npm run test:frontend`: 135/0 (131 previos + 4 nuevos de esta fase).
- PHP `Unit` 1328 OK (solo deprecaciones, sin fallos). `Feature` 934 OK + 3 skipped (sin cambio frente a la línea base).
- `npm run test:e2e:routes`: 474 rutas tenant / 20 portal, sin cambio.
- E2E completo, limpio (sin builds concurrentes): 348 ejecutadas, **346 pasaron**, 1 fallo (preexistente, fixture de fecha del datepicker, sección 5 — ajeno a esta fase), 1 omitida (preexistente). **0 fallos por memoria.**
- `E2E_RESET=1` (tenant/contenedor recreados desde cero) + smoke (`--grep @smoke`, 334 casos): **332 pasaron**, el mismo único fallo preexistente de fecha, 1 omitida. Mismo resultado que la corrida limpia — reproducible en un entorno recién provisionado.
- `npm run production`: compila limpio (8,9 min). `__pxValidationCounters` (instrumentación dev/test de la sección 6) confirmado ausente del bundle (`grep -rl __pxValidationCounters public/js/` sin resultados).
- `npm ci` desde un clon limpio: correcto (con `.npmrc`, sección 7). `npm ls vee-validate @vee-validate/rules`: 4.15.1, sin ningún `vee-validate@3.x` en el árbol, sin conflictos de peer dependency sin resolver.
- Reproducción directa del colapso (`15-slot-converted-pages.spec.js`, `29-modals-matrix.spec.js`): ambos verdes — ver tabla de memoria y tiempos en la sección 6.
- CI: `timeout-minutes` restaurado de 90 a 45 en `.github/workflows/frontend-safety-net.yml` (la corrida local completa tarda ~31 min sin el colapso; 45 min deja margen razonable, igual que antes de esta rama).

## 10. Fuera de alcance (sin tocar)

vue-i18n 8, Vuex 3, vue-good-table, vue-select, vue2-daterange-picker, VuePerfectScrollbar, `@vue/compat`, Vite, TypeScript.

## 11. Próxima fase recomendada

Con vee-validate ya en la 4 (composición de Vue 3, sin `extends`) y el colapso de memoria de la sección 6 resuelto, esta fase queda cerrada sin seguimientos pendientes propios. El bloqueante de validación para retirar `@vue/compat` queda resuelto; el trabajo pendiente de la fase anterior (Bootstrap 5) apuntaba a vue-i18n 8 o vee-validate 3 como los dos bloqueantes restantes — con este quitado, sigue **vue-i18n 8** como próxima migración.

Pendientes menores fuera del alcance de esta fase (no bloquean el cierre): la limpieza cosmética del aviso `injection "Symbol(vee-validate-form-context)" not found` (sección 8) y la fixture de fecha del datepicker en `33-forms-contract.spec.js` (preexistente, ajena a vee-validate — el fixture `forms-bv2-contract.json` graba el HTML de un día concreto marcado "(Today)"; se desalinea según pasa el calendario).
