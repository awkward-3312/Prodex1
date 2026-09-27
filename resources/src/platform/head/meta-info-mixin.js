// Capa de compatibilidad temporal: cada componente con la opción `metaInfo` (objeto o función, como en vue-meta 2) registra una
// entrada en Unhead. Sin `$children`, `Vue.util` ni internals de Vue 2: solo `this.$options.metaInfo`, que existe igual en Vue 3.
//
// - `metaInfo()` se evalúa con `this` = componente dentro de un efecto reactivo (`useHead` recibe una función), así que un
//   título que depende de datos que llegan tarde (`this.article.title`) se actualiza solo.
// - La entrada se elimina en `beforeUnmount` (lo hace `useHead`), de modo que al cambiar de ruta no queda ningún tag anterior.
// - Orden: el hijo se crea después que el padre, y Unhead deja ganar a la entrada más reciente para `title` / `titleTemplate`
//   (igual que vue-meta: el componente más profundo manda) y fusiona `htmlAttrs` / `bodyAttrs`.
import { useHead } from '@unhead/vue';
import { translateMetaInfo, unsupportedKeys } from './translate-meta-info.js';

export function createMetaInfoMixin(head) {
  return {
    created() {
      const metaInfo = this.$options && this.$options.metaInfo;
      if (!metaInfo) return;
      const source = () => {
        const info = typeof metaInfo === 'function' ? metaInfo.call(this) : metaInfo;
        if (process.env.NODE_ENV !== 'production') {
          const extra = unsupportedKeys(info);
          if (extra.length) console.warn(`[head] metaInfo con claves no soportadas por la capa Unhead: ${extra.join(', ')}`);
        }
        return translateMetaInfo(info);
      };
      useHead(source, { head });
    },
  };
}
