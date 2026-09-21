// Registro global de BootstrapVue 2 LIMITADO a lo que las vistas todavía usan (fase 5A): formularios (`b-form`, `b-form-group`, `b-form-input`,
// `b-form-textarea`, `b-form-select`, `b-form-checkbox`, `b-form-radio(-group)`, `b-form-file`, `b-form-datepicker`, `b-form-invalid-feedback`,
// `b-form-text`), grupos de entrada (`b-input-group*`) y `b-skeleton-img`. Antes: `Vue.use(BootstrapVue)` registraba los ~90 componentes, directivas
// y los servicios `$bvModal` / `$bvToast` (sin consumidores desde la fase 4). La lista sale del inventario por AST
// (tests/frontend/inventory/bootstrap-inventory.mjs) y un test la mantiene sincronizada. Desaparece con el último formulario de BV2.
import {
  FormPlugin, FormGroupPlugin, FormInputPlugin, FormTextareaPlugin, FormSelectPlugin, FormCheckboxPlugin, FormRadioPlugin, FormFilePlugin,
  FormDatepickerPlugin, InputGroupPlugin, SkeletonPlugin,
} from 'bootstrap-vue/dist/bootstrap-vue.esm.js';

export const BOOTSTRAP_VUE_REMAINING_PLUGINS = [
  FormPlugin, FormGroupPlugin, FormInputPlugin, FormTextareaPlugin, FormSelectPlugin, FormCheckboxPlugin, FormRadioPlugin, FormFilePlugin,
  FormDatepickerPlugin, InputGroupPlugin, SkeletonPlugin,
];

export default {
  install(Vue) {
    BOOTSTRAP_VUE_REMAINING_PLUGINS.forEach((plugin) => Vue.use(plugin));
  },
};
