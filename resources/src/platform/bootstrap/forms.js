// Formularios sobre BootstrapVueNext (fases 2 y 5B). Contrato de BootstrapVue 2 MEDIDO en navegador (tests/e2e/specs/33-forms-parity.spec.js: la
// misma plantilla sobre BV2 y BVN, con el orden de eventos, el tipo JS del valor y el momento en que cambia el modelo):
//   - `@input` / `@change` reciben el VALOR (cadena en los campos de texto, valor tipado en select/casilla/radio), no el `Event` nativo de BVN.
//     Campos de texto: `input` en cada pulsación con el texto tal cual (aunque el modelo lleve `.trim` / `.number`); `change` al perder el foco.
//     Select / casilla / radio: `input(valor)` → el modelo cambia → `change(valor)` en el siguiente tick (BV2 lo emitía tras `$nextTick`, con el
//     `v-model` ya actualizado y los observadores ya ejecutados).
//   - `v-model.trim` (Vue 2 sobre componente): el modelo recibe el valor recortado en cada evento, pero el campo conserva el texto que escribe
//     el usuario (los espacios internos y finales siguen ahí mientras escribe y al salir). BVN recorta el DOM al perder el foco: se implementa
//     aquí y no se le pasa el modificador.
//   - `:value` + `@input` (sin v-model), `unchecked-value` por defecto `false` (BVN: `undefined`).
// Marcado: el de Bootstrap 5 de BootstrapVueNext (`form-select`, `form-check`, `input-group-text`…). Marcas propias de PRODEX: `form-group` (contrato de la capa
// de diseño, con fieldset/legend como BV2) y, en casillas/radios/interruptores, `px-bvn-check` / `px-bvn-group` (`prodex/_controls.scss` pinta el indicador
// de la aplicación sobre `form-check`).
// Repartido en dos módulos para el tree-shaking por entrypoint (fase 5B): `form-text.js` (form, grupo, input, textarea, feedback, grupo de entrada: lo que usa
// el login) y `form-choice.js` (select, casilla, radio y sus grupos).
export * from './form-text.js';
export * from './form-choice.js';
