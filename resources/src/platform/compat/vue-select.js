// vue-select con su directiva `appendToBody` reemplazada por la de Vue 3 (ver platform/directives/append-to-body.js). Se registra como `v-select`.
import VueSelect from 'vue-select';
import { vSelectAppendToBody } from '../directives/append-to-body.js';

// El paquete es CommonJS con `exports.default`: sin `__esModule` el import por defecto devuelve el objeto de exports completo.
const Base = VueSelect && VueSelect.default ? VueSelect.default : VueSelect;

export default { ...Base, directives: { ...Base.directives, appendToBody: vSelectAppendToBody } };
