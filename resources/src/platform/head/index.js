import { createHead } from '@unhead/vue/client';
import { createMetaInfoMixin } from './meta-info-mixin.js';

export { translateMetaInfo, unsupportedKeys } from './translate-meta-info.js';

// Una sola instancia de Unhead por entrypoint (main, login).
export const head = createHead();

/** Registra la capa `metaInfo` → Unhead como mixin global. Llamar antes de crear componentes. */
export function installHead(Vue) {
  Vue.mixin(createMetaInfoMixin(head));
}
