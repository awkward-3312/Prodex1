// Modo de @vue/compat por componente: BootstrapVueNext es Vue 3 puro y sus componentes internos (no exportados) no se pueden marcar con
// `compatConfig`. Los SFC con <script setup> compilados de BootstrapVueNext llevan `__name: 'B…'`; BootstrapVue 2 y el código propio usan `name`.
export const isBootstrapVueNext = (comp) => !!comp && typeof comp === 'object' && typeof comp.__name === 'string' && /^B[A-Z]/.test(comp.__name);

// `@lucide/vue` (sucesor Vue-3-nativo de `lucide-vue`) exporta cada icono como una función simple `(props, {slots})
// => vnode` — un componente funcional idiomático de Vue 3, NO una fábrica de componente asíncrono de Vue 2. Bajo
// MODE 2, `@vue/compat` trata CUALQUIER función pasada a `h()` como candidata a ser esa fábrica y la invoca con
// `(resolve, reject)` en vez de `(props, {slots})` ("Cannot read properties of undefined (reading 'default')" al
// leer `slots.default` con `slots` siendo en realidad la función `reject`). El chequeo se repite en cada nivel de
// anidamiento (el wrapper por icono de `createLucideIcon` Y el componente base compartido de la librería), así que
// no basta con un `compatConfig` en `LucideIcon.vue` (solo cubre el nivel más externo).
//
// Distinción segura: los ÚNICOS componentes de la app pasados como función son (a) fábricas de import dinámico
// reales, siempre `() => import(...)` — cero parámetros declarados — y (b) los componentes funcionales de
// `@lucide/vue` — uno o dos parámetros (`props`, `{ slots }`). La aridad de la función basta para no confundirlos.
const isFunctionalComponent = (comp) => typeof comp === 'function' && comp.length >= 1;

/** `MODE` de configureCompat: 3 para BootstrapVueNext y componentes funcionales de Vue 3 (p. ej. iconos de `@lucide/vue`), 2 para el resto. */
export const compatModeFor = (comp) => (isBootstrapVueNext(comp) || isFunctionalComponent(comp) ? 3 : 2);
