# vue-meta 2 → Unhead

Base: `9a68d45` (Vue 3.5.43 + `@vue/compat` MODE 2, Vue Router 4). `vue-meta` 2.4 eliminado; `@unhead/vue` **3.4.1** (última estable; peers `vue >=3.5.18`, `webpack >=5`). Sin cambiar de bundler ni tocar BootstrapVue, Bootstrap, vee-validate, Vuex, vue-i18n, Vite ni TypeScript.

## 1. Contrato antiguo (auditoría antes de cambiar)

| Medida | Resultado |
|---|---|
| Definiciones de `metaInfo` (AST de `.vue` y `.js`) | 297 en 299 archivos: **290 objetos literales** (todos con `title` string) y **7 funciones** (`App.vue` y 6 vistas: título condicional, `this.$t(...)`, dato asíncrono) |
| Claves usadas | `title` (297), `titleTemplate` (1), `htmlAttrs` (1), `bodyAttrs` (1). Solo `App.vue` usa las tres últimas |
| `meta`, `link`, `script`, `style`, `noscript`, `base` | 0 |
| `vmid` (tag) / `$meta` | 0 (`vmid` solo aparecía como `tagIDKeyName` en la configuración del plugin) |
| `Vue.use(Meta)` | 2: `login.js` y `plugins/stocky.kit.js` (entrypoint `main`) |
| Lectura de `this.$options.metaInfo` por otra razón | 1: `stocky.kit.js` detectaba la vista del recibo POS con `metaInfo.title === 'POS Receipt'` |
| Lectores de `document.title` | 2: `spanishDocumentTitleGuard` (traduce frases con un `MutationObserver` sobre `<title>`) y la impresión de `Detail_Product` |
| Portal / pantalla del cliente | No usan `metaInfo` ni vue-meta |
| Bases | `<title>`, `lang="es"` y `class` del `<body>` se sirven desde Blade; `App.vue` los sobrescribe |

Contratos reales de PRODEX: (1) el componente más profundo fija `title`; (2) `titleTemplate` `%s | <sufijo>` reactivo (usuario → `window.__pageTitleSuffix` → «Gestión empresarial»), con `title` base `window.__appName`; (3) `htmlAttrs` (`dir` reactivo, `lang`) y `bodyAttrs` (`class` con array reactivo); (4) `metaInfo()` con `this` y datos reactivos; (5) al cambiar de ruta no queda nada de la vista anterior.

## 2. Capa de compatibilidad (`resources/src/platform/head/`)

| Archivo | Papel |
|---|---|
| `translate-meta-info.js` | Función pura `metaInfo` → entrada de Unhead: `title`, `titleTemplate`, `htmlAttrs`, `bodyAttrs`, `base`, y listas `meta/link/script/style/noscript` con `vmid`/`hid` → `key` (deduplicación de Unhead) y `json` → `innerHTML`. `unsupportedKeys` lista lo que vue-meta admitía y aquí no (`changed`, `headAttrs`… ) y el mixin avisa en desarrollo: nada se ignora en silencio |
| `meta-info-mixin.js` | Mixin global: `created()` lee `this.$options.metaInfo` (objeto o función, con `this`) y llama a `useHead(() => translate(...), { head })`. Al ser una función, Unhead la evalúa en un efecto reactivo: los datos que llegan tarde actualizan el título. `useHead` retira la entrada en `beforeUnmount` |
| `index.js` | Una instancia `createHead()` de `@unhead/vue/client` y `installHead(Vue)` |

Sin `$children`, `Vue.util` ni internals de Vue 2: solo `$options.metaInfo`, que existe igual en Vue 3 puro (la capa se puede quedar o sustituir vista a vista por `useHead`). Instalación: `installHead(Vue)` al inicio de `main.js` y `login.js`, y `app.use(head)` (provee `$unhead`) mediante `mountWithRouter(..., [head])`. Portal y pantalla del cliente no cambian.

**Orden y deduplicación.** El hijo se crea después del padre y Unhead deja ganar a la entrada más reciente para `title`/`titleTemplate` (igual que el «más profundo» de vue-meta), fusiona `htmlAttrs`/`bodyAttrs` y deduplica tags por `key` (`vmid`). Al navegar, la entrada anterior se elimina con su componente: el número de entradas vuelve al inicial (probado) y hay un único `<title>`.

**Marcador del recibo POS.** La lógica de `stocky.kit.js` ya no lee `metaInfo`: `pos_receipt.vue` declara `prodexReceiptPresentation: true` y el mixin comprueba esa opción. `metaInfo.title` queda solo para el `<head>`.

## 3. vue-meta eliminado

- `package.json` / `package-lock.json` (v1, diff mínimo: `@unhead/vue` y sus dependencias, sin `vue-meta`); `npm ci` en directorio limpio: OK.
- Eliminados `Vue.use(Meta, …)` (×2) y la configuración `keyName/attribute/tagIDKeyName/refreshOnceOnNavigation`.
- No había adaptadores ni hacks propios de vue-meta salvo la bandera siguiente.

## 4. Bandera de compat eliminada

`configureCompat({ MODE: 2, CUSTOM_DIR: true })`: **`INSTANCE_CHILDREN` eliminado.** Su único consumidor real era vue-meta (recorría `vm.$children` de todos los componentes, incluidos los MODE 3). Comprobado: ni el código propio (0) ni ninguna dependencia del bundle lee `$children` (solo el propio runtime de compat). `CUSTOM_DIR` se conserva: sigue habiendo `<router-link v-b-tooltip>` con directivas de BootstrapVue. No se silencia ningún aviso.

## 5. Avisos retirados (mismos 81 tests con avisos, antes → después)

| Métrica | Antes (`9a68d45`) | Después |
|---|---|---|
| Mensajes totales | 21 607 | 17 855 (−17,4 %) |
| Avisos únicos (herramienta, 92 tests) | 35 | 34 |
| `INSTANCE_CHILDREN` | 1 214 | **0** |
| `INSTANCE_EVENT_HOOKS` (`hook:*` de vue-meta) | 2 395 | 25 |
| `INSTANCE_EVENT_EMITTER` (`$on/$off` de vue-meta) | 356 | 253 |
| `PRIVATE_APIS` | 4 379 | 4 356 |
| `GLOBAL_PRIVATE_UTIL` (`Vue.util`) | 158 | 157 (queda vue-clickaway) |
| `OPTIONS_BEFORE_DESTROY` | 4 679 | 4 654 |

Atribuibles a vue-meta: `INSTANCE_CHILDREN` completo, ~2 370 de `INSTANCE_EVENT_HOOKS`, ~100 de `INSTANCE_EVENT_EMITTER` y ~1 de `GLOBAL_PRIVATE_UTIL` (≈3 700 mensajes). `PRIVATE_APIS` y `OPTIONS_BEFORE_DESTROY` apenas bajan porque los emiten los mixins globales de BootstrapVue y vee-validate.

## 6. Pruebas

- `tests/frontend/head-meta-info.test.mjs` (9): traducción (título, plantilla, `htmlAttrs`/`bodyAttrs`, `vmid`/`hid` → `key`, `json`), claves no soportadas, vue-meta/`$meta`/`data-vue-meta` ausentes, `INSTANCE_CHILDREN` ausente, `$children` propio 0, único lector de `$options.metaInfo` = la capa de head, y la capa sin internals de Vue 2.
- E2E `19-head-unhead` (13): título inicial + `titleTemplate` del tenant; cambio de título entre 5 rutas sin recargar (y el guard de idioma: «Purchases» → «Compras»); atrás/adelante; `metaInfo()` con función; sufijo reactivo; sin entradas huérfanas (mismo nº de entradas y un solo `<title>`); `lang`/`dir`/clases del `<body>` sin duplicados; 404; recibo POS; 403 (usuario restringido); login (título de Blade + bundle con Unhead y sin vue-meta); `/password/reset` (Blade) y portal sin metadata.
- Reset/forgot: son páginas Blade (`sessions/forgot|reset.vue` no tienen ruta); se prueba que conservan el título del servidor.
- No hay script `npm run test`; el equivalente del repo es `npm run test:frontend` (90 tests).

## 7. Deuda restante

- La capa `metaInfo` → Unhead es temporal: las 297 vistas conservan `metaInfo`; migrarlas a `useHead` es opcional y mecánico cuando cada vista pase a Composition/`<script setup>`.
- `htmlAttrs.dir` sigue pasando `" "` (espacio) cuando no es RTL, tal como hacía vue-meta.
- Unhead añade clases al `<body>` sin borrar las existentes (vue-meta reescribía el atributo entero): comportamiento más seguro; sin cambios visibles.
- Quedan `Vue.util` (vue-clickaway) y los `hook:*`/`$on` de BootstrapVue, vee-validate y vue-select.
- Siguiente dependencia recomendada: **vue-clickaway → directiva propia** (último `Vue.util`; trivial) y después **BootstrapVue** (`CUSTOM_DIR`, `render(h)`, `$listeners`).
