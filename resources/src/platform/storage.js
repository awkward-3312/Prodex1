// Reemplaza `vue-cookies` (2 usos reales, todos `VueCookies.isKey(...)` — nunca `this.$cookies`) y `vue-localstorage`
// (usado como `Vue.localStorage.get/set`, nunca `this.$localStorage`, sin namespace ni serialización JSON: los
// únicos valores guardados son strings planos, ver `store/modules/language.js`). Ninguno de los dos paquetes se usa
// vía inyección de instancia de componente en todo el proyecto — instalarlos como plugin Vue nunca fue necesario.
// No cambia ningún nombre de cookie/clave existente.

export function cookieIsKey(name) {
  if (typeof document === 'undefined') return false;
  const target = `${encodeURIComponent(name)}=`;
  return document.cookie.split('; ').some((entry) => entry.indexOf(target) === 0);
}

export function localStorageGet(key, defaultValue = null) {
  if (typeof window === 'undefined' || !window.localStorage) return defaultValue;
  const value = window.localStorage.getItem(key);
  return value === null ? defaultValue : value;
}

export function localStorageSet(key, value) {
  if (typeof window === 'undefined' || !window.localStorage) return value;
  window.localStorage.setItem(key, value);
  return value;
}
