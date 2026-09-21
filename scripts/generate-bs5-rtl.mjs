#!/usr/bin/env node
// Genera las reglas RTL de Bootstrap 5 para PRODEX.
//
// La hoja de Bootstrap 5 que se compila es la LTR: `ms-2`, `text-end`, `float-end`, `.form-select` (flecha a la derecha), `.form-check`, `.dropdown-menu-end`,
// los radios de `.input-group` y `.btn-group`… usan propiedades FÍSICAS. Bootstrap publica el RTL como otra hoja (RTLCSS) y la aplicación cambia de dirección
// en caliente (`<html dir="rtl">` según el idioma), así que aquí se procesa la MISMA compilación con `postcss-rtlcss` (modo `override`, prefijo
// `[dir="rtl"]`) y se guardan SOLO las reglas RTL en `compat/_bs5-rtl.generated.scss`. El resto de la aplicación conserva sus propios `[dir="rtl"]`.
//   node scripts/generate-bs5-rtl.mjs           # reescribe el archivo
//   node scripts/generate-bs5-rtl.mjs --check   # falla si el archivo no está al día (lo ejecuta un test)
import fs from 'node:fs';
import path from 'node:path';
import { createRequire } from 'node:module';
import { fileURLToPath, pathToFileURL } from 'node:url';

const require = createRequire(import.meta.url);
const ROOT = path.resolve(path.dirname(fileURLToPath(import.meta.url)), '..');
const THEMES = path.join(ROOT, 'resources/src/assets/styles/sass/themes');
export const OUTPUT = path.join(ROOT, 'resources/src/assets/styles/sass/compat/_bs5-rtl.generated.scss');

export async function generate() {
  const sass = require('sass');
  const postcss = require('postcss');
  const rtl = require('postcss-rtlcss');
  const tilde = { findFileUrl: (url) => (url.startsWith('~') ? pathToFileURL(`${ROOT}/node_modules/${url.slice(1)}`) : null) };
  const { css } = sass.compileString('@import "variables-theme";\n@import "bootstrap5";\n', {
    importers: [tilde], loadPaths: [THEMES, path.join(ROOT, 'node_modules')], quietDeps: true,
    silenceDeprecations: ['import', 'global-builtin', 'color-functions', 'mixed-decls', 'slash-div', 'legacy-js-api', 'abs-percent', 'if-function'], logger: sass.Logger.silent,
  });
  const flipped = await postcss([rtl({ mode: 'override', rtlPrefix: '[dir="rtl"]', ltrPrefix: '[dir="ltr"]', processUrls: false })]).process(css, { from: undefined });
  const root = postcss.parse(flipped.css);
  const keep = postcss.root();
  const isRtl = (rule) => rule.selectors.every((s) => s.startsWith('[dir="rtl"]'));
  root.each((node) => {
    if (node.type === 'rule' && isRtl(node)) keep.append(node.clone());
    else if (node.type === 'atrule' && node.nodes && /^(media|supports)$/.test(node.name)) {
      const wrapper = node.clone({ nodes: [] });
      node.each((inner) => { if (inner.type === 'rule' && isRtl(inner)) wrapper.append(inner.clone()); });
      if (wrapper.nodes.length) keep.append(wrapper);
    }
  });
  const banner = '// GENERADO por scripts/generate-bs5-rtl.mjs (postcss-rtlcss sobre la compilación de Bootstrap 5.3 con las variables de PRODEX). No editar a mano:\n'
    + '// `node scripts/generate-bs5-rtl.mjs` lo regenera y un test comprueba que está al día.\n';
  return banner + keep.toString().replace(/\r\n/g, '\n') + '\n';
}

if (process.argv[1] === fileURLToPath(import.meta.url)) {
  const next = await generate();
  if (process.argv.includes('--check')) {
    const current = fs.existsSync(OUTPUT) ? fs.readFileSync(OUTPUT, 'utf8') : '';
    if (current !== next) { console.error('compat/_bs5-rtl.generated.scss desactualizado: ejecuta `node scripts/generate-bs5-rtl.mjs`'); process.exit(1); }
    console.log('reglas RTL de Bootstrap 5 al día');
  } else {
    fs.writeFileSync(OUTPUT, next);
    console.log(`escrito ${path.relative(ROOT, OUTPUT)} (${next.length} bytes, ${next.split('\n').length} líneas)`);
  }
}
