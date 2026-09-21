// Normalización del HTML de la sonda BV2 vs BVN: ids generados, orden de clases y de atributos, comentarios y espacios.
function normalizeHtml(html) {
  return String(html)
    .replace(/<!--[\s\S]*?-->/g, '')
    .replace(/__BVID__\d+/g, '__BVID__')
    .replace(/\bid="(?:bv|__BV)[^"]*"/g, '')
    .replace(/class="([^"]*)"/g, (m, c) => `class="${c.split(/\s+/).filter(Boolean).sort().join(' ')}"`)
    .replace(/\s+/g, ' ')
    .replace(/> </g, '><')
    .trim();
}
module.exports = { normalizeHtml };
