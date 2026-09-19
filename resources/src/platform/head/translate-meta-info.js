// Contrato `metaInfo` de vue-meta 2 → entrada de Unhead. Función pura (sin Vue ni Unhead), válida también en Vue 3 puro.
//
// Contrato que PRODEX usa de verdad (auditoría del repo): `title` (297 vistas: 290 literales y 7 funciones con lógica), y solo en
// App.vue `titleTemplate`, `htmlAttrs` y `bodyAttrs`. No hay `meta`, `link`, `script`, `vmid` ni API imperativa; aun así se traducen
// `meta`/`link`/`script`/`style`/`noscript`/`base` y `vmid`/`hid` (→ `key`, la deduplicación de Unhead) para no dejar una API
// a medias. Las claves que vue-meta admitía y aquí no se soportan se listan en `unsupportedKeys` (nunca se ignoran en silencio).

const TAG_LISTS = ['meta', 'link', 'script', 'style', 'noscript'];
const PASS_THROUGH = ['title', 'titleTemplate', 'htmlAttrs', 'bodyAttrs', 'base'];
const IGNORED = ['changed', '__dangerouslyDisableSanitizers', 'headAttrs'];

function translateTag(tag) {
  if (!tag || typeof tag !== 'object') return tag;
  const { vmid, hid, json, once, template, callback, ...rest } = tag;
  const out = { ...rest };
  const key = vmid != null ? vmid : hid;
  if (key != null) out.key = key;
  if (json != null && out.innerHTML == null) out.innerHTML = json;
  return out;
}

/** Devuelve la entrada de Unhead equivalente a un objeto `metaInfo` ya evaluado. */
export function translateMetaInfo(info) {
  const input = {};
  if (!info || typeof info !== 'object') return input;

  PASS_THROUGH.forEach((key) => {
    const value = info[key];
    if (value === undefined || value === null || value === '') return;
    input[key] = value;
  });

  TAG_LISTS.forEach((name) => {
    const list = info[name];
    if (!Array.isArray(list) || !list.length) return;
    input[name] = list.map(translateTag);
  });

  return input;
}

/** Claves de un `metaInfo` que esta capa no traduce (para avisar en desarrollo). */
export function unsupportedKeys(info) {
  if (!info || typeof info !== 'object') return [];
  const known = new Set([...PASS_THROUGH, ...TAG_LISTS, ...IGNORED]);
  return Object.keys(info).filter((key) => !known.has(key));
}
