const fs = require('fs');
const path = require('path');
const { test, expect } = require('../support/fixtures');
const { env, waitForApp } = require('../support/helpers');

// Matriz de paridad de formularios (fase 5B). Cada escenario monta la MISMA plantilla con los `b-form-*` de BootstrapVue 2 y con los wrappers de
// `platform/bootstrap` (sonda dev `/app/_ui?probe=bv`), ejecuta las mismas acciones de usuario y compara, sobre AMBAS implementaciones:
//   - el registro ORDENADO de eventos y observadores (nombre, tipo JS del valor, valor),
//   - el modelo final y su tipo, y el estado real del DOM (valor, seleccionado, altura…).
// El contrato queda además volcado en tests/e2e/.artifacts/forms-contract.json (adjunto del test) para la documentación.

test.use({ storageState: path.join(env.authDir, 'admin.json') });
test.setTimeout(90_000);

const W = 'function (n) { this.log.push(["watch", typeof n, JSON.stringify(n)]) }';
const EV = 'function (n, v) { this.log.push([n, typeof v, (v && v.target) ? "EVENT" : v]) }';
const root = (page) => page.locator('.probe-root');

async function open(page) {
  await page.goto('/app/_ui?probe=bv');
  await waitForApp(page);
  await page.waitForFunction(() => typeof window.__pxProbe === 'function', undefined, { timeout: 30_000 });
}
async function record(page, sc, bvn) {
  const r = await page.evaluate(([t, o]) => window.__pxProbe(t, o), [sc.tpl, { bvn, data: { log: [], ...(sc.data || {}) }, methods: { ev: EV, ...(sc.methods || {}) }, watch: sc.watch || {} }]);
  expect(r.missing, 'etiquetas sin wrapper BVN').toEqual([]);
  await sc.actions(page);
  await page.waitForTimeout(200);
  const data = await page.evaluate(() => JSON.parse(JSON.stringify(window.__pxProbeData(), (k, v) => (v instanceof File ? `File:${v.name}` : v))));
  const dom = sc.dom ? await sc.dom(page) : null;
  const html = sc.html ? await normHtml(page) : null;
  return { log: data.log, model: data.v, modelType: typeof data.v, dom, html };
}
// Marcado normalizado: sin ids/for/aria-* que referencian ids, comentarios, ni estilos que calcula Popper.
async function normHtml(page, selector = '.probe-root') {
  return page.evaluate((sel) => {
    const clone = document.querySelector(sel).cloneNode(true);
    const walker = document.createTreeWalker(clone, NodeFilter.SHOW_COMMENT);
    const comments = [];
    while (walker.nextNode()) comments.push(walker.currentNode);
    comments.forEach((c) => c.remove());
    clone.querySelectorAll('*').forEach((el) => {
      ['id', 'for', 'aria-labelledby', 'aria-describedby', 'aria-controls', 'aria-activedescendant', 'x-placement'].forEach((a) => el.removeAttribute(a));
      if (el.classList.contains('dropdown-menu')) el.removeAttribute('style');
      if (el.getAttribute('class') === '') el.removeAttribute('class');
      // el orden de atributos y de clases no es contrato
      const attrs = [...el.attributes].map((a) => [a.name, a.name === 'class' ? a.value.split(/\s+/).filter(Boolean).sort().join(' ') : a.value]).sort((x, y) => (x[0] < y[0] ? -1 : 1));
      [...el.attributes].forEach((a) => el.removeAttribute(a.name));
      attrs.forEach(([n, v]) => el.setAttribute(n, v));
    });
    return clone.innerHTML.replace(/\s+/g, ' ').replace(/> </g, '><');
  }, selector);
}
const recorded = {};
test.afterAll(() => {
  const dir = path.join(__dirname, '..', '.artifacts');
  fs.mkdirSync(dir, { recursive: true });
  fs.writeFileSync(path.join(dir, 'forms-contract.json'), JSON.stringify(recorded, null, 1));
});


// ---- archivos / fechas ----
const fs2 = require('fs');
const os = require('os');
const tmp = fs2.mkdtempSync(path.join(os.tmpdir(), 'px-file-'));
const F1 = path.join(tmp, 'a.txt');
const F2 = path.join(tmp, 'b.png');
fs2.writeFileSync(F1, 'hola');
fs2.writeFileSync(F2, 'x');
const FEV = 'function (n, v) { const d = (x) => (x && x.name) ? x.name : (x && x.target ? "EVENT" : x); this.log.push([n, v === null ? "null" : Array.isArray(v) ? "array" : typeof v, Array.isArray(v) ? v.map(d).join("|") : d(v)]) }';
const FW = 'function (n) { this.log.push(["watch", n === null ? "null" : Array.isArray(n) ? "array" : (n && n.constructor && n.constructor.name), Array.isArray(n) ? n.map(f => f.name).join("|") : (n && n.name)]) }';
const FILE = (attrs) => `<div><b-form-file v-model="v" class="f" @input="ev('input', $event)" @change="ev('change', $event)" @reset="ev('reset')"${attrs}></b-form-file></div>`;
const fileDom = (page) => page.evaluate(() => { const i = document.querySelector('.probe-root input[type=file]'); return [i.files.length, document.querySelector('.probe-root .form-file-text').textContent]; });
const DP = (attrs) => `<div style="width:320px"><b-form-datepicker v-model="v" class="dp" @input="ev('input', $event)" @change="ev('change', $event)" @shown="ev('shown')" @hidden="ev('hidden')"${attrs}></b-form-datepicker></div>`;

const input = (mod, extra = '') => `<div><b-form-input v-model${mod}="v" class="i" ${extra} @input="ev('input', $event)" @change="ev('change', $event)" @focus="ev('focus')" @blur="ev('blur')"></b-form-input><button class="o">o</button></div>`;
const type = (txt) => async (page) => { await root(page).locator('.i').click(); await page.keyboard.type(txt); await root(page).locator('.o').click(); };
const domValue = (page) => root(page).locator('.i').inputValue();

const SCENARIOS = {
  // ---------------- texto ----------------
  'input: escribir, focus/input/change/blur en orden y v-model string': { tpl: input(''), data: { v: '' }, watch: { v: W }, actions: type('ab'), dom: domValue },
  'input: borrar con Backspace': { tpl: input(''), data: { v: 'hola' }, watch: { v: W }, actions: async (p) => { await root(p).locator('.i').click(); await p.keyboard.press('End'); await p.keyboard.press('Backspace'); await p.keyboard.press('Backspace'); await root(p).locator('.o').click(); }, dom: domValue },
  'input: pegar (fill) y valor inicial numérico sin .number': { tpl: input(''), data: { v: 5 }, watch: { v: W }, actions: async (p) => { await root(p).locator('.i').fill('pegado'); await root(p).locator('.o').click(); }, dom: domValue },
  'input: cambio programático del modelo actualiza el DOM sin emitir input/change': { tpl: input(''), data: { v: 'a' }, watch: { v: W }, actions: async (p) => { await p.evaluate(() => { window.__pxProbeData().v = 'zzz'; }); await p.waitForTimeout(100); }, dom: domValue },
  'input: disabled y readonly no aceptan escritura': { tpl: '<div><b-form-input v-model="v" class="i" disabled @input="ev(\'input\', $event)"></b-form-input><b-form-input v-model="v" class="r" readonly @input="ev(\'input\', $event)"></b-form-input></div>', data: { v: 'x' }, watch: { v: W }, actions: async (p) => { await root(p).locator('.r').click(); await p.keyboard.type('yy'); await root(p).locator('.i').click({ force: true }).catch(() => {}); }, dom: async (p) => [await root(p).locator('.i').inputValue(), await root(p).locator('.r').inputValue()] },
  'input: maxlength': { tpl: input('', 'maxlength="3"'), data: { v: '' }, watch: { v: W }, actions: type('abcdef'), dom: domValue },
  'input: :value + @input sin v-model (una vía)': { tpl: '<div><b-form-input :value="v" class="i" @input="ev(\'input\', $event)"></b-form-input></div>', data: { v: 'ini' }, actions: async (p) => { await root(p).locator('.i').click(); await p.keyboard.type('X'); }, dom: domValue },
  // ---------------- .trim ----------------
  'trim: "San Pedro Sula" — el espacio no desaparece mientras se escribe': { tpl: input('.trim'), data: { v: '' }, watch: { v: W }, actions: async (p) => { await root(p).locator('.i').click(); await p.keyboard.type('San '); const mid = await domValue(p); await p.keyboard.type('Pedro '); const mid2 = await domValue(p); await p.keyboard.type('Sula'); await p.evaluate(([a, b]) => { window.__pxProbeData().log.push(['dom-mid', a, b]); }, [mid, mid2]); await root(p).locator('.o').click(); }, dom: domValue },
  'trim: espacios alrededor, pegar y salir': { tpl: input('.trim'), data: { v: '' }, watch: { v: W }, actions: async (p) => { await root(p).locator('.i').fill('  hola mundo  '); await root(p).locator('.o').click(); }, dom: domValue },
  'trim: vacío y solo espacios': { tpl: input('.trim'), data: { v: 'x' }, watch: { v: W }, actions: async (p) => { await root(p).locator('.i').fill('   '); await root(p).locator('.o').click(); }, dom: domValue },
  'trim: espacios entre palabras': { tpl: input('.trim'), data: { v: '' }, watch: { v: W }, actions: type('a  b   c'), dom: domValue },
  // ---------------- .number ----------------
  'number: 1.5': { tpl: input('.number'), data: { v: '' }, watch: { v: W }, actions: type('1.5'), dom: domValue },
  'number: punto decimal parcial "1."': { tpl: input('.number'), data: { v: '' }, watch: { v: W }, actions: type('1.'), dom: domValue },
  'number: texto inválido "12ab"': { tpl: input('.number'), data: { v: '' }, watch: { v: W }, actions: type('12ab'), dom: domValue },
  'number: negativo, borrar y vacío': { tpl: input('.number'), data: { v: '' }, watch: { v: W }, actions: async (p) => { await root(p).locator('.i').click(); await p.keyboard.type('-5'); await p.keyboard.press('Backspace'); await p.keyboard.press('Backspace'); await root(p).locator('.o').click(); }, dom: domValue },
  'number: cero': { tpl: input('.number'), data: { v: '' }, watch: { v: W }, actions: type('0'), dom: domValue },
  'number: type="number" step min': { tpl: input('.number', 'type="number" step="0.5" min="0"'), data: { v: '' }, watch: { v: W }, actions: type('2.5'), dom: domValue },
  'number: pegar "  7 "': { tpl: input('.number'), data: { v: '' }, watch: { v: W }, actions: async (p) => { await root(p).locator('.i').fill('7'); await root(p).locator('.o').click(); }, dom: domValue },
  // ---------------- select ----------------
  'select: options tipadas (string, número, booleano, null, objeto) — input(valor) → modelo → change(valor)': {
    tpl: '<div><b-form-select v-model="v" :options="o" class="s" @input="ev(\'input\', $event)" @change="ev(\'change\', $event)"></b-form-select></div>',
    data: { v: 'a', o: [{ value: 'a', text: 'A' }, { value: 'b', text: 'B' }, { value: 3, text: 'Tres' }, { value: true, text: 'Si' }, { value: null, text: 'Nada' }, { value: { id: 1 }, text: 'Obj' }] },
    watch: { v: W }, actions: async (p) => { const s = root(p).locator('.s'); for (const i of [2, 3, 4, 5]) await s.selectOption({ index: i }); }, dom: (p) => root(p).locator('.s').evaluate((e) => e.selectedIndex),
  },
  'select: opciones declarativas, null deshabilitada como placeholder': {
    tpl: '<div><b-form-select v-model="v" class="s" @change="ev(\'change\', $event)"><b-form-select-option :value="null" disabled>Elija</b-form-select-option><b-form-select-option value="x">X</b-form-select-option><b-form-select-option :value="5">Cinco</b-form-select-option></b-form-select></div>',
    data: { v: null }, watch: { v: W }, actions: async (p) => { const s = root(p).locator('.s'); await s.selectOption({ index: 1 }); await s.selectOption({ index: 2 }); }, dom: (p) => root(p).locator('.s').evaluate((e) => e.selectedIndex),
  },
  'select: value-field / text-field y opción deshabilitada': {
    tpl: '<div><b-form-select v-model="v" :options="o" value-field="id" text-field="name" class="s" @change="ev(\'change\', $event)"></b-form-select></div>',
    data: { v: 1, o: [{ id: 1, name: 'Uno' }, { id: 2, name: 'Dos', disabled: true }, { id: 3, name: 'Tres' }] }, watch: { v: W }, actions: async (p) => { await root(p).locator('.s').selectOption({ index: 2 }); }, dom: (p) => root(p).locator('.s').evaluate((e) => [e.selectedIndex, [...e.options].map((o) => o.disabled)]),
  },
  'select: :value + @change sin v-model': { tpl: '<div><b-form-select :value="v" :options="o" class="s" @change="ev(\'change\', $event)"></b-form-select></div>', data: { v: 10, o: [10, 25, 50] }, actions: async (p) => { await root(p).locator('.s').selectOption({ index: 1 }); }, dom: (p) => root(p).locator('.s').evaluate((e) => e.selectedIndex) },
  // ---------------- textarea ----------------
  'textarea: multilínea, eventos y max-rows (altura)': {
    tpl: '<div><b-form-textarea v-model="v" class="t" rows="2" max-rows="4" @input="ev(\'input\', $event)" @change="ev(\'change\', $event)" @blur="ev(\'blur\')"></b-form-textarea><button class="o">o</button></div>', data: { v: '' }, watch: { v: W },
    actions: async (p) => { await root(p).locator('.t').click(); await p.keyboard.type('a'); for (let i = 0; i < 6; i++) { await p.keyboard.press('Enter'); await p.keyboard.type(`l${i}`); } await root(p).locator('.o').click(); },
    dom: (p) => root(p).locator('.t').evaluate((e) => ({ h: e.style.height, sh: e.scrollHeight, ch: e.clientHeight })),
  },
  'textarea: .trim y reset por modelo': { tpl: '<div><b-form-textarea v-model.trim="v" class="t" rows="3"></b-form-textarea></div>', data: { v: 'inicial' }, watch: { v: W }, actions: async (p) => { await root(p).locator('.t').fill('  con espacios  \n dos '); await p.evaluate(() => { window.__pxProbeData().v = ''; }); await p.waitForTimeout(100); }, dom: (p) => root(p).locator('.t').inputValue() },
  // ---------------- casilla ----------------
  'checkbox: booleano (true/false, no undefined)': { tpl: '<div><b-form-checkbox v-model="v" class="c" @input="ev(\'input\', $event)" @change="ev(\'change\', $event)">A</b-form-checkbox></div>', data: { v: false }, watch: { v: W }, actions: async (p) => { await root(p).locator('.c label').click(); await root(p).locator('.c label').click(); } },
  'checkbox: unchecked-value personalizado': { tpl: '<div><b-form-checkbox v-model="v" :unchecked-value="\'no\'" class="c" @change="ev(\'change\', $event)">A</b-form-checkbox></div>', data: { v: 'no' }, watch: { v: W }, actions: async (p) => { await root(p).locator('.c label').click(); await root(p).locator('.c label').click(); } },
  'checkbox: :checked-value se ignora igual que en BV2 (el valor marcado es `true`)': { tpl: '<div><b-form-checkbox v-model="v" :checked-value="\'si\'" :unchecked-value="\'no\'" class="c" @change="ev(\'change\', $event)">A</b-form-checkbox></div>', data: { v: 'no' }, watch: { v: W }, actions: async (p) => { await root(p).locator('.c label').click(); await root(p).locator('.c label').click(); } },
  'checkbox: array con :value (alta, baja y orden)': { tpl: '<div><b-form-checkbox v-model="v" value="a" class="c1" @change="ev(\'change\', $event)">A</b-form-checkbox><b-form-checkbox v-model="v" value="b" class="c2">B</b-form-checkbox></div>', data: { v: [] }, watch: { v: W }, actions: async (p) => { await root(p).locator('.c2 label').click(); await root(p).locator('.c1 label').click(); await root(p).locator('.c2 label').click(); } },
  'checkbox: disabled no cambia': { tpl: '<div><b-form-checkbox v-model="v" disabled class="c">A</b-form-checkbox></div>', data: { v: false }, watch: { v: W }, actions: async (p) => { await root(p).locator('.c label').click({ force: true }); } },
  'switch: teclado (Espacio) y modelo': { tpl: '<div><b-form-checkbox v-model="v" switch class="c" @change="ev(\'change\', $event)">A</b-form-checkbox></div>', data: { v: false }, watch: { v: W }, actions: async (p) => { await root(p).locator('.c input').focus(); await p.keyboard.press('Space'); await p.keyboard.press('Space'); await p.keyboard.press('Space'); } },
  'checkbox-group: array de opciones (orden de alta)': { tpl: '<div><b-form-checkbox-group v-model="v" :options="o" @change="ev(\'change\', $event)"></b-form-checkbox-group></div>', data: { v: ['b'], o: [{ value: 'a', text: 'A' }, { value: 'b', text: 'B' }, { value: 'c', text: 'C' }] }, watch: { v: W }, actions: async (p) => { for (const t of ['C', 'A', 'B']) await root(p).locator('label', { hasText: t }).click(); } },
  // ---------------- radio ----------------
  'radio: v-model exclusivo y nombre común': { tpl: '<div><b-form-radio v-model="v" value="a" name="r" @change="ev(\'change\', $event)">A</b-form-radio><b-form-radio v-model="v" value="b" name="r">B</b-form-radio></div>', data: { v: 'a' }, watch: { v: W }, actions: async (p) => { await root(p).locator('label', { hasText: 'B' }).click(); await root(p).locator('label', { hasText: 'A' }).click(); }, dom: (p) => root(p).locator('input').evaluateAll((els) => els.map((e) => e.checked)) },
  'radio-group: botones, valores primitivos (string, número, booleano)': { tpl: '<div><b-form-radio-group v-model="v" :options="o" buttons button-variant="outline-primary" @change="ev(\'change\', $event)"></b-form-radio-group></div>', data: { v: 'a', o: [{ value: 'a', text: 'A' }, { value: 2, text: 'Dos' }, { value: true, text: 'T' }] }, watch: { v: W }, actions: async (p) => { for (const t of ['Dos', 'T']) await root(p).locator('label', { hasText: t }).click(); } },
  'radio-group: teclado (flechas) recorre las opciones': { tpl: '<div><b-form-radio-group v-model="v" :options="o" stacked></b-form-radio-group></div>', data: { v: 'a', o: [{ value: 'a', text: 'A' }, { value: 'b', text: 'B' }, { value: 'c', text: 'C' }] }, watch: { v: W }, actions: async (p) => { await root(p).locator('input').first().focus(); await p.keyboard.press('ArrowDown'); await p.keyboard.press('ArrowDown'); } },

  // ---------------- archivo ----------------
  'file: un archivo — change(Event) → input(File) → modelo File; marcado custom-file': { tpl: FILE('' ), data: { v: null }, watch: { v: FW }, methods: { ev: FEV }, html: true, actions: async (p) => { await p.setInputFiles('.probe-root input[type=file]', F1); }, dom: fileDom },
  'file: multiple — arreglo (también con un solo archivo)': { tpl: FILE(' multiple'), data: { v: null }, watch: { v: FW }, methods: { ev: FEV }, html: true, actions: async (p) => { await p.setInputFiles('.probe-root input[type=file]', [F1, F2]); await p.setInputFiles('.probe-root input[type=file]', [F2]); }, dom: fileDom },
  'file: vaciar la selección y reset por modelo (input(null) de vuelta)': { tpl: FILE(''), data: { v: null }, watch: { v: FW }, methods: { ev: FEV }, actions: async (p) => { await p.setInputFiles('.probe-root input[type=file]', F1); await p.setInputFiles('.probe-root input[type=file]', []); await p.setInputFiles('.probe-root input[type=file]', F2); await p.evaluate(() => { window.__pxProbeData().v = null; }); await p.waitForTimeout(100); }, dom: fileDom },
  'file: placeholder, drop-placeholder y browse-text': { tpl: FILE(' placeholder="Elegir" drop-placeholder="Suelta" browse-text="Ex"'), data: { v: null }, methods: { ev: FEV }, html: true, actions: async () => {} },
  'file: accept, disabled, state, size, required y name': { tpl: FILE(' accept="image/*" disabled :state="false" size="sm" required name="n1"'), data: { v: null }, methods: { ev: FEV }, html: true, actions: async () => {} },
  'file: sin v-model, solo @change': { tpl: '<div><b-form-file accept=".png" @change="ev(\'change\', $event)"></b-form-file></div>', data: { v: null }, methods: { ev: FEV }, actions: async (p) => { await p.setInputFiles('.probe-root input[type=file]', F2); }, dom: fileDom },
  'file: soltar archivos (drop) filtra por accept y emite change → input': {
    tpl: FILE(' multiple accept=".txt"'), data: { v: null }, watch: { v: FW }, methods: { ev: FEV }, dom: fileDom,
    actions: async (p) => {
      const dt = await p.evaluateHandle(() => { const d = new DataTransfer(); d.items.add(new File(['a'], 'uno.txt', { type: 'text/plain' })); d.items.add(new File(['b'], 'dos.png', { type: 'image/png' })); return d; });
      await p.dispatchEvent('.probe-root .custom-file', 'dragover', { dataTransfer: dt });
      const during = await p.locator('.probe-root .form-file-text').textContent();
      await p.dispatchEvent('.probe-root .custom-file', 'drop', { dataTransfer: dt });
      await p.evaluate((t) => { window.__pxProbeData().log.push(['dragging-label', t]); }, during);
    },
  },
  // ---------------- selector de fecha ----------------
  'datepicker: cerrado con fecha y con placeholder': { tpl: DP(' placeholder="Desde" reset-button size="sm"'), data: { v: '2026-09-15' }, watch: { v: W }, html: true, actions: async () => {} },
  'datepicker: cerrado sin fecha (placeholder)': { tpl: DP(' placeholder="Desde"'), data: { v: '' }, watch: { v: W }, html: true, actions: async () => {} },
  'datepicker: abierto (cabecera, navegación, rejilla, pie)': { tpl: DP(' reset-button today-button close-button'), data: { v: '2026-09-15' }, watch: { v: W }, html: true, actions: async (p) => { await root(p).locator('.dp button').first().click(); await p.waitForTimeout(300); } },
  'datepicker: elegir un día cierra y emite input(YYYY-MM-DD)': { tpl: DP(' reset-button'), data: { v: '2026-09-15' }, watch: { v: W }, dom: async (p) => [await root(p).locator('.dp label').last().textContent(), await root(p).locator('.dp .dropdown-menu.show').count()], actions: async (p) => { await root(p).locator('.dp button').first().click(); await root(p).locator('[data-date="2026-09-20"]').click(); await p.waitForTimeout(300); } },
  'datepicker: Restablecer vacía el modelo': { tpl: DP(' reset-button'), data: { v: '2026-09-15' }, watch: { v: W }, dom: async (p) => await root(p).locator('.dp label').last().textContent(), actions: async (p) => { await root(p).locator('.dp button').first().click(); await root(p).getByRole('button', { name: 'Reset' }).click(); await p.waitForTimeout(300); } },
  'datepicker: teclado (flechas + Enter) y navegación de mes': { tpl: DP(''), data: { v: '2026-09-15' }, watch: { v: W }, dom: async (p) => await root(p).locator('.dp label').last().textContent(), actions: async (p) => { await root(p).locator('.dp button').first().click(); await p.waitForTimeout(200); await p.keyboard.press('ArrowRight'); await p.keyboard.press('ArrowDown'); await p.keyboard.press('PageUp'); await p.keyboard.press('Enter'); await p.waitForTimeout(300); } },
  'datepicker: min/max deshabilitan días': { tpl: DP(' min="2026-09-10" max="2026-09-20"'), data: { v: '2026-09-15' }, watch: { v: W }, dom: async (p) => { await root(p).locator('.dp button').first().click(); await root(p).locator('[data-date="2026-09-25"]').click({ force: true }); await root(p).locator('[data-date="2026-09-12"]').click(); await p.waitForTimeout(200); return root(p).locator('.dp label').last().textContent(); }, actions: async () => {} },
  'datepicker: disabled y state inválido': { tpl: DP(' disabled :state="false"'), data: { v: '2026-09-15' }, html: true, actions: async () => {} },
  // ---------------- marcador de carga ----------------
  'skeleton-img: marcado, tamaño, aspecto y animación idénticos': { tpl: '<div style="width:300px"><b-skeleton-img class="rounded-xl shadow-soft" height="110px"></b-skeleton-img><b-skeleton-img animation="fade" width="100px" height="50px"></b-skeleton-img></div>', data: {}, html: true, dom: (p) => p.evaluate(() => [...document.querySelectorAll('.probe-root .b-skeleton')].map((e) => { const r = e.getBoundingClientRect(); const s = getComputedStyle(e); return [r.width, r.height, s.backgroundColor, s.borderRadius, s.animationName]; })), actions: async () => {} },
  // ---------------- formulario ----------------
  'form: Enter y botón submit emiten UN submit cada uno': { tpl: '<div><b-form @submit.prevent="ev(\'submit\', 1)"><b-form-input v-model="v" class="i"></b-form-input><b-button type="submit" class="b">ok</b-button></b-form></div>', data: { v: '' }, actions: async (p) => { await root(p).locator('.i').click(); await p.keyboard.press('Enter'); await root(p).locator('.b').click(); } },
};

test.describe('Paridad de formularios BV2 vs BVN @smoke', () => {
  test.beforeEach(async ({ page }) => open(page));

  for (const [name, sc] of Object.entries(SCENARIOS)) {
    test(name, async ({ page }) => {
      const bv2 = await record(page, sc, false);
      const bvn = await record(page, sc, true);
      recorded[name] = { BV2: bv2 };
      expect(bvn.log, 'eventos y observadores, en orden').toEqual(bv2.log);
      expect(bvn.model, 'modelo final').toEqual(bv2.model);
      expect(bvn.modelType, 'tipo del modelo').toBe(bv2.modelType);
      expect(bvn.dom, 'estado del DOM').toEqual(bv2.dom);
      if (sc.html && bvn.html !== bv2.html) {
        let i = 0;
        while (i < bv2.html.length && bv2.html[i] === bvn.html[i]) i += 1;
        throw new Error(`marcado distinto en ${i}:\n  BV2: …${bv2.html.slice(Math.max(0, i - 120), i + 260)}\n  BVN: …${bvn.html.slice(Math.max(0, i - 120), i + 260)}`);
      }
    });
  }
});
