// vue2-daterange-picker con su directiva `appendToBody` reemplazada por la de Vue 3. Se resuelve como `vue2-daterange-picker` mediante un alias de
// webpack (webpack.mix.js: `vue2-daterange-picker$`), de modo que las 33 importaciones de las vistas no cambian. La librería real se importa por
// su ruta de `dist`.
import DateRangePicker from 'vue2-daterange-picker/dist/vue2-daterange-picker.umd.min.js';
import { daterangeAppendToBody } from '../directives/append-to-body.js';

const Base = DateRangePicker && DateRangePicker.default ? DateRangePicker.default : DateRangePicker;

export default { ...Base, directives: { ...Base.directives, appendToBody: daterangeAppendToBody } };
