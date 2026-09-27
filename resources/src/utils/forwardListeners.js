// Reenvía al elemento nativo SOLO los listeners recibidos (`onClick`, `onKeyup`, …) de `$attrs`.
// En Vue 3 los listeners del padre viajan en `$attrs`; un componente con `inheritAttrs: false` (como PxInput) que antes
// hacía `v-on="$listeners"` usa `v-bind="forwardListeners($attrs)"`. Los atributos que no son listeners siguen sin reenviarse.
export function forwardListeners(attrs) {
  const listeners = {};
  Object.keys(attrs || {}).forEach((key) => {
    if (/^on[A-Z]/.test(key)) listeners[key] = attrs[key];
  });
  return listeners;
}

// Atributos que no son listeners ni class/style (class/style los aplica el propio componente raíz).
export function forwardPlainAttrs(attrs) {
  const plain = {};
  Object.keys(attrs || {}).forEach((key) => {
    if (!/^on[A-Z]/.test(key) && key !== 'class' && key !== 'style') plain[key] = attrs[key];
  });
  return plain;
}
