/**
 * `test` extendido: cada test falla si la página produce errores JS graves.
 *  - `pageerror` (excepciones no controladas)
 *  - `console.error` (excepto la línea genérica "Failed to load resource", que se evalúa por red)
 *  - respuestas HTTP >= 500, o 404 de recursos estáticos propios (/js, /css, /fonts, /images)
 * Las excepciones aceptadas viven en support/allowlist.js, con su justificación.
 */
const fs = require('fs');
const pathMod = require('path');
const base = require('@playwright/test');
const allowlist = require('./allowlist');

const { expect } = base;

function classify(err) {
  return allowlist.find(
    (a) => a.kind === err.kind && a.message.test(err.message) && (!a.stack || a.stack.test(err.stack || ''))
  );
}

const test = base.test.extend({
  // Lista viva de errores del test; los tests pueden inspeccionarla.
  jsErrors: [
    async ({ page }, use, testInfo) => {
      const errors = [];
      const warnings = [];
      const origin = testInfo.project.use.baseURL || '';
      page.on('pageerror', (e) => errors.push({ kind: 'pageerror', message: e.message, stack: e.stack || '' }));
      page.on('console', (m) => {
        // Avisos ([Vue warn], deprecaciones de @vue/compat): no fallan el test; se vuelcan a tests/e2e/.artifacts/warnings.
        if (m.type() === 'warning' && /\[Vue warn\]|\[Vue Router warn\]|deprecation|compat/i.test(m.text())) {
          warnings.push({ text: m.text().slice(0, 4000), url: page.url() });
          return;
        }
        if (m.type() !== 'error') return;
        const text = m.text();
        if (/^Failed to load resource/i.test(text)) return; // cubierto por el listener de respuestas
        errors.push({ kind: 'console', message: text, stack: (m.location() && m.location().url) || '' });
      });
      page.on('response', (r) => {
        const status = r.status();
        const url = r.url();
        const sameOrigin = url.startsWith(origin);
        const staticAsset = /\/(js|css|fonts|images)\//.test(new URL(url).pathname);
        if (status >= 500 || (status === 404 && sameOrigin && staticAsset)) {
          errors.push({ kind: 'http', message: `HTTP ${status} ${r.request().method()} ${new URL(url).pathname}`, stack: '' });
        }
      });

      await use(errors);

      if (warnings.length) {
        const dir = pathMod.join(__dirname, '..', '.artifacts', 'warnings');
        fs.mkdirSync(dir, { recursive: true });
        fs.writeFileSync(pathMod.join(dir, `${testInfo.testId}.json`), JSON.stringify({ test: testInfo.titlePath.join(' › '), warnings }));
      }

      const counts = new Map();
      const real = [];
      for (const e of errors) {
        const rule = classify(e);
        if (!rule) {
          real.push(e);
          continue;
        }
        counts.set(rule.id, (counts.get(rule.id) || 0) + 1);
        if (counts.get(rule.id) > rule.maxPerTest) real.push({ ...e, message: `${e.message} [allowlist "${rule.id}" superó maxPerTest=${rule.maxPerTest}]` });
      }
      expect(
        real.map((e) => `${e.kind}: ${e.message}${e.stack ? `\n    ${String(e.stack).split('\n').slice(0, 3).join('\n    ')}` : ''}`),
        'La página produjo errores JS/HTTP graves (ver support/allowlist.js si es preexistente)'
      ).toEqual([]);
    },
    { auto: true },
  ],
});

module.exports = { test, expect };
