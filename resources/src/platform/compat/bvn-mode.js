// Modo de @vue/compat por componente: BootstrapVueNext es Vue 3 puro y sus componentes internos (no exportados) no se pueden marcar con
// `compatConfig`. Los SFC con <script setup> compilados de BootstrapVueNext llevan `__name: 'B…'`; BootstrapVue 2 y el código propio usan `name`.
export const isBootstrapVueNext = (comp) => !!comp && typeof comp === 'object' && typeof comp.__name === 'string' && /^B[A-Z]/.test(comp.__name);

/** `MODE` de configureCompat: 3 para BootstrapVueNext, 2 para el resto. */
export const compatModeFor = (comp) => (isBootstrapVueNext(comp) ? 3 : 2);
