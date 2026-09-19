// Contrato PRODEX de validación de formularios. Independiente de la librería (sin imports de vee-validate).
//
// <px-validation-observer ref="form" v-slot="{ invalid, handleSubmit }">   -> formulario
//   observer.validate()  -> Promise<boolean>   valida todos los campos y los marca como validados
//   observer.reset()     -> void               limpia errores y flags
//   observer.setErrors({ campo: ['mensaje'] }) -> errores del servidor por nombre/vid de campo
//   slot props: valid, invalid, errors, pending, dirty, pristine, touched, untouched, validated, passed, failed,
//               handleSubmit(fn), validate(), reset()
//
// <px-validation-provider name="..." rules="required" v-slot="{ errors, valid, invalid, validate, reset, ... }"> -> campo
//   provider.validate(value?) -> Promise<{ valid, errors, ... }>
//   provider.reset()          -> void
//   provider.setErrors([...]) -> errores manuales
//   slot props: errors, valid, invalid, failed, passed, dirty, pristine, touched, untouched, validated, pending,
//               required, validate(eventOrValue), reset(), aria, ariaInput, ariaMsg, classes, flags
//
// Las funciones siguientes son la forma recomendada de usar el observer desde código (con guardas).

/** Valida el formulario. Sin observer (p. ej. ref aún sin montar) resuelve `fallback` (por defecto true). */
export function validateForm(observer, fallback = true) {
  if (!observer || typeof observer.validate !== 'function') return Promise.resolve(fallback);
  return Promise.resolve(observer.validate()).then(Boolean);
}

/** Limpia errores y flags; no falla si el observer no existe. */
export function resetForm(observer) {
  if (observer && typeof observer.reset === 'function') observer.reset();
}

/** Aplica errores del servidor `{ campo: 'msg' | ['msg'] }`. Devuelve true si se aplicaron. */
export function setFormErrors(observer, errors) {
  if (!observer || typeof observer.setErrors !== 'function' || !errors || typeof errors !== 'object') return false;
  const normalized = {};
  Object.keys(errors).forEach((field) => {
    const value = errors[field];
    normalized[field] = Array.isArray(value) ? value : [value];
  });
  observer.setErrors(normalized);
  return true;
}

/** Valida y, solo si es válido, ejecuta `onValid`. Resuelve el resultado de `onValid` o `undefined`. */
export function submitForm(observer, onValid) {
  return validateForm(observer).then((ok) => (ok ? onValid() : undefined));
}
