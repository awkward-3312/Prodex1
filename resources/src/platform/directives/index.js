import { clickaway } from './clickaway.js';

export { clickaway };

/** Registra las directivas propias. `on-clickaway` conserva el nombre de la librería anterior para no editar las plantillas. */
export function installDirectives(Vue) {
  Vue.directive('on-clickaway', clickaway);
}
