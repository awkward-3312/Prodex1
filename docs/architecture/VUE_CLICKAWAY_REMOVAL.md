# vue-clickaway → directiva propia

Base: `584cec8` (Vue 3.5.43 + `@vue/compat` MODE 2, Vue Router 4, Unhead). `vue-clickaway` 2.2.2 eliminado; sustituido por `resources/src/platform/directives/clickaway.js`. Sin dependencias nuevas y sin tocar BootstrapVue, Bootstrap, vee-validate, Vuex, vue-i18n, Vite ni TypeScript.

## 1. Usos encontrados (auditoría antes de cambiar)

| Medida | Resultado |
|---|---|
| `import ... from "vue-clickaway"` | **1**: `containers/layouts/largeSidebar/TopNav.vue` (`import { mixin as clickaway }`) |
| `Vue.use(...)` del plugin | 0 |
| Mixin `mixins: [clickaway]` | 1 (el mismo `TopNav.vue`) |
| Directivas `v-on-clickaway` (con/sin argumento o modificadores) en plantillas | **0** en todo `resources/` |
| Aliases / wrappers | 0 |
| Dropdowns, popovers, filtros, menús u overlays controlados por click-away | **ninguno**: el mixin solo registraba una directiva que ninguna plantilla usaba (código muerto heredado de la plantilla Stocky); los menús del layout legacy se cierran con su propio estado |

Consecuencia: **no existe comportamiento de click-away en pantallas que preservar**. Se cumplió el encargo igualmente (directiva propia registrada con el mismo nombre) para que cualquier uso futuro o de plantillas heredadas siga funcionando, y se probó con interacción real de navegador. La dependencia dejaba además en cada carga los avisos `PLUGIN_VUE2_ONLY` y `GLOBAL_PRIVATE_UTIL`.

## 2. Directiva nueva

`v-on-clickaway` (nombre conservado, registrada globalmente en `main.js` con `installDirectives(Vue)`), hooks de Vue 3 `mounted` / `updated` / `unmounted`; sin `Vue.util`, `$children`, internals ni hooks de Vue 2. Semántica reproducida de la librería (solo lo que se usaba):

- Escucha `click` en `document.documentElement` (burbujeo). No escucha `touchstart`: un tap ya genera su `click`, escucharlo duplicaría el handler (probado con toques reales).
- Ejecuta el handler solo si `el.contains(event.target)` es falso; se llama con `this` = componente que declara la directiva y recibe el evento.
- Guarda de primer tick: el click que abre el elemento (y el que cambia el handler) no lo cierra; se rearma con `setTimeout(0)` al montar y cada vez que cambia el valor, igual que el `unbind + bind` de la librería.
- Un único listener por elemento (`WeakMap`), retirado (y su guarda cancelada) en `unmounted`; `mounted` repetido no duplica.
- Valor que no es función: `console.warn` en desarrollo y no se ejecuta nada; se recupera si vuelve a ser función.
- Cambio de handler: mismo listener, handler nuevo (sin volver a suscribir).

`TopNav.vue` pierde el mixin (no aportaba nada: sin directiva en la plantilla).

## 3. Dependencia eliminada

`vue-clickaway` fuera de `package.json` y `package-lock.json` (v1, diff mínimo: −1 línea en `package.json`, −16 en el lock: el paquete y su única dependencia `loose-envify`). `npm ci`, `npm ci --dry-run` y `npm ls` (sin `vue-clickaway`, sin errores) verificados en un directorio limpio.

## 4. Vue.util antes / después

| Punto | Antes | Después |
|---|---|---|
| `GLOBAL_PRIVATE_UTIL` (mismas 92 pruebas con avisos) | 166 mensajes | **0** |
| `Vue.util` en código PRODEX | 0 (solo comentarios) | 0 |
| `Vue.util` ejecutado en la app | vue-clickaway (al instalarse) | **ninguno** |
| Referencias textuales en el bundle de desarrollo | 4 (`main`) | 3: el propio aviso/getter de compat y **1 en `vue-localstorage`** |

La única referencia externa que queda es `vue-localstorage` (`Vue.util.defineReactive`), en una rama que solo se ejecuta cuando un componente declara la opción `localStorage: {…}`. PRODEX usa la librería solo como `Vue.localStorage.get/set` (`store/modules/language.js`) y ningún componente declara esa opción, así que **no se ejecuta y no emite `GLOBAL_PRIVATE_UTIL`** (0 mensajes en E2E). No se modificó `node_modules`.

## 5. Avisos retirados (92 pruebas comunes, antes → después)

| Métrica | Antes (`584cec8`) | Después |
|---|---|---|
| Mensajes totales | 18 723 | 18 395 |
| Avisos únicos (por clave) | 42 | 40 |
| Avisos únicos (herramienta, todas las pruebas) | 34 | 32 |
| `GLOBAL_PRIVATE_UTIL` | 166 | **0** |
| `PLUGIN_VUE2_ONLY` («VueClickaway 2.2.2 only supports Vue 2.x») | presente | **0** |
| `PRIVATE_APIS` | 4 613 | 4 620 (mixins globales de BootstrapVue y vee-validate) |

Atribuibles a vue-clickaway: los dos avisos anteriores (`GLOBAL_PRIVATE_UTIL` y `PLUGIN_VUE2_ONLY`, ≈ 330 mensajes en las pruebas comunes). El resto del total varía ±1 % entre corridas.

## 6. Pruebas

- `tests/frontend/clickaway-directive.test.mjs` (11), con DOM simulado: `mounted` (un listener y guarda de primer tick), click dentro / fuera con `this` y evento, `updated` (handler nuevo sin duplicar y con guarda rearmada; mismo valor no rearma), `unmounted` (idempotente, guarda cancelada), doble `mounted`, varias instancias, handler inválido (aviso, sin ejecución y recuperación), registro `on-clickaway`, dependencia/imports eliminados y ausencia de internals de Vue 2.
- E2E `20-clickaway-directive` (8), con clicks y toques reales y el build global de compat (no hay pantallas que la usen): dropdown con toggle + panel `v-if` (click dentro permanece, click fuera cierra, el click que abre no cierra); abrir/cerrar 6 veces sin handlers duplicados y con un solo listener vivo; varias instancias; overlay `v-show` siempre montado; desmontaje y regreso ×5 (0 listeners tras desmontar, sin llamadas de un componente destruido); handler dinámico; tap táctil (un solo handler); valor no función. El fixture falla ante cualquier error de consola.
- **Paridad con la librería anterior.** Los mismos escenarios se ejecutaron contra `vue-clickaway` 2.2.2 bajo compat: 6 de 7 coinciden; el de **handler dinámico falla con la librería anterior** (`TypeError: Cannot read properties of undefined (reading 'context')`: su hook `update` espera el `vnode` de Vue 2), y funciona con la directiva propia.

## 7. Deuda restante

- La directiva está registrada pero sin usos en pantallas; si se confirma que las plantillas heredadas no la necesitarán, puede retirarse (y el test asociado) en una limpieza posterior.
- `Vue.util` sigue nombrado dentro de `vue-localstorage` (rama inerte); desaparece al sustituir esa dependencia por un acceso directo a `localStorage` (uso trivial: 4 llamadas `get/set`).
- Siguiente dependencia recomendada: **BootstrapVue** (`CUSTOM_DIR`, `render(h)`, `$listeners`, `beforeDestroy`, `hook:*`, las directivas sobre `router-link` y buena parte de los avisos restantes).
