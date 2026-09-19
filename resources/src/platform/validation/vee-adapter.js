// Único módulo del frontend que importa vee-validate. Las vistas usan <px-validation-provider> y <px-validation-observer>
// (contrato en ./contract.js); al migrar a una librería de validación de Vue 3 solo se sustituye este archivo.
import { ValidationObserver, ValidationProvider, extend, localize } from 'vee-validate';
import * as veeRules from 'vee-validate/dist/rules.js';

// Mismo comportamiento que los componentes de vee-validate 3: se hereda todo (props, inject/provide del observer,
// detección de v-model, slot props, métodos validate/reset/setErrors/handleSubmit) y solo cambia el nombre público.
export const PxValidationProvider = { name: 'PxValidationProvider', extends: ValidationProvider };
export const PxValidationObserver = { name: 'PxValidationObserver', extends: ValidationObserver };

const MESSAGES_ES = {
  required: 'Este campo es obligatorio',
  required_if: 'Este campo es obligatorio',
  regex: 'Este campo debe tener un formato válido',
  mimes: 'Este archivo debe tener un tipo válido',
  size: (_, { size }) => `El tamaño del archivo debe ser menor de ${size}`,
  min: 'Este campo debe tener al menos {length} caracteres',
  max: (_, { length }) => `Este campo no puede tener más de ${length} caracteres`,
};

const urlRule = {
  validate(value) {
    if (!value) return false;
    try {
      const parsed = new URL(value);
      return parsed.protocol === 'http:' || parsed.protocol === 'https:';
    } catch (e) {
      return false;
    }
  },
  message: 'Este campo debe contener una URL válida (http:// o https://)',
};

/**
 * Registra reglas, mensajes y componentes. `legacyAliases` mantiene los nombres globales antiguos
 * (`ValidationProvider` / `ValidationObserver`) para las pantallas que todavía no se migraron
 * (POS, caja, pagos, inventario crítico, facturación).
 */
export function installValidation(Vue, { legacyAliases = true, extraRules = {} } = {}) {
  localize({ es: { messages: MESSAGES_ES } });
  localize('es');
  Object.keys(veeRules).forEach((rule) => extend(rule, veeRules[rule]));
  extend('url', urlRule);
  Object.keys(extraRules).forEach((rule) => extend(rule, extraRules[rule]));

  Vue.component('PxValidationProvider', PxValidationProvider);
  Vue.component('PxValidationObserver', PxValidationObserver);
  if (legacyAliases) {
    Vue.component('ValidationProvider', ValidationProvider);
    Vue.component('ValidationObserver', ValidationObserver);
  }
}

export { extend as extendRule };
