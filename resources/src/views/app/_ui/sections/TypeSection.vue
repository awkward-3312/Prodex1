<template>
  <section>
    <section-head
      num="02"
      title="Tipografía — IBM Plex Sans + Plex Mono (aprobada)"
      desc="Se evaluaron cuatro familias sobre el mismo specimen (PageHeader, KPI, tabla densa, formulario, modal). IBM Plex Sans + Plex Mono quedó aprobada en Fase A. Source Sans 3 y Public Sans eran candidatas del comparador; sus archivos de fuente se retiraron del código. La comparación escrita se conserva aquí como referencia."
    />

    <div class="ty-eval">
      <div class="ty-eval__scroll pxn-scroll">
        <table class="ty-eval__table">
          <thead>
            <tr><th>Criterio</th><th>IBM Plex Sans ✓</th><th>Source Sans 3</th><th>Public Sans</th><th>system-ui</th></tr>
          </thead>
          <tbody>
            <tr v-for="r in evalRows" :key="r.k">
              <th scope="row">{{ r.k }}</th>
              <td>{{ r.plex }}</td><td>{{ r.source }}</td><td>{{ r.public }}</td><td>{{ r.system }}</td>
            </tr>
          </tbody>
        </table>
      </div>
      <p class="ty-eval__lean">
        <lucide-icon name="flag" :size="13" />
        <b>Aprobada: IBM Plex Sans + IBM Plex Mono.</b> Cifras de tabla, SKU/códigos y
        campos fiscales en monoespaciada real; pareja nativa; OFL-1.1, self-host.
        El comparador vivo abajo mantiene IBM Plex frente a <code>system-ui</code> (control sin webfont).
      </p>
    </div>

    <div class="ty-specimens">
      <article v-for="c in candidates" :key="c.id" class="ty-spec" :data-pxn-type="c.id">
        <header class="ty-spec__bar">
          <b>{{ c.label }}</b>
          <span class="pxn-mono">{{ c.stack }}</span>
        </header>

        <div class="ty-spec__body">
          <!-- pangram + diacritics + numerals -->
          <p class="ty-spec__pangram">El veloz murciélago hindú comía feliz cardillo y kiwi.</p>
          <p class="ty-spec__glyphs">
            áéíóú ÁÉÍÓÚ ñÑ ¿? ¡! üÜ — L 1,234.56 · Q 8.900,00 · ₡ 512 340 · US$ 1,204.80
            <span class="pxn-num"> 0123456789</span>
          </p>

          <!-- PageHeader -->
          <div class="ty-spec__ph">
            <span class="ty-spec__crumb">Inventario / Existencias</span>
            <h3>Existencias por sucursal</h3>
            <p>1.284 SKU · 4 sucursales · actualizado hoy 14:02</p>
          </div>

          <!-- KPI -->
          <div class="ty-spec__kpis">
            <div v-for="k in specKpis" :key="k.l" class="ty-spec__kpi">
              <span class="ty-spec__kpi-l">{{ k.l }}</span>
              <span class="ty-spec__kpi-v pxn-num">{{ k.v }}</span>
              <span class="ty-spec__kpi-d" :class="k.t">{{ k.d }}</span>
            </div>
          </div>

          <!-- dense table -->
          <table class="ty-spec__table pxn-num">
            <thead><tr><th>SKU</th><th>Producto</th><th class="r">Stock</th><th class="r">Costo</th><th class="r">Precio</th></tr></thead>
            <tbody>
              <tr v-for="row in specRows" :key="row[0]">
                <td class="pxn-mono">{{ row[0] }}</td><td class="l">{{ row[1] }}</td>
                <td class="r">{{ row[2] }}</td><td class="r">{{ row[3] }}</td><td class="r">{{ row[4] }}</td>
              </tr>
            </tbody>
          </table>

          <!-- form -->
          <div class="ty-spec__form">
            <label>Razón social<input type="text" value="Distribuidora El Progreso S. de R.L." readonly /></label>
            <label>{{ 'Identificación tributaria (RTN)' }}<input class="pxn-mono" type="text" value="18016000559001" readonly /></label>
          </div>

          <!-- modal -->
          <div class="ty-spec__modal">
            <div class="ty-spec__modal-h">Anular factura 001-001-01-00045210</div>
            <p>Esta acción registra una nota de crédito y no puede revertirse. La secuencia fiscal se conserva.</p>
            <div class="ty-spec__modal-f"><span class="btn ghost">Cancelar</span><span class="btn danger">Anular</span></div>
          </div>
        </div>
      </article>
    </div>
  </section>
</template>

<script>
import SectionHead from "./_SectionHead.vue";
export default {
  name: "TypeSection",
  components: { SectionHead },
  props: { density: String, country: String },
  data() {
    return {
      candidates: [
        { id: "plex", label: "IBM Plex Sans + Plex Mono · aprobada", stack: '"IBM Plex Sans" / "IBM Plex Mono"' },
        { id: "system", label: "system-ui · control (sin webfont)", stack: "-apple-system, Segoe UI, Roboto…" }
      ],
      specKpis: [
        { l: "Ventas de hoy", v: "L 486,230.50", d: "▲ 8,4 %", t: "up" },
        { l: "Ticket promedio", v: "L 742.18", d: "▼ 1,2 %", t: "down" },
        { l: "Margen bruto", v: "31,6 %", d: "▲ 0,4 pp", t: "up" }
      ],
      specRows: [
        ["ACE-500-100", "Acetaminofén 500 mg · caja 100", "1,240", "38.50", "62.00"],
        ["BEB-CC-2500", "Coca-Cola 2.5 L retornable", "540", "18.40", "27.00"],
        ["FER-CEM-425", "Cemento gris 42.5 kg", "312", "178.00", "214.00"],
        ["ABA-FR-900", "Frijol rojo seleccionado 2 lb", "88", "34.00", "47.50"]
      ],
      evalRows: [
        { k: "Legibilidad 11–13 px", plex: "Muy buena; ojo medio, formas abiertas", source: "Excelente; la más neutra a tamaño chico", public: "Muy buena; diseñada para UI densa", system: "Depende del SO; consistente en macOS/Win" },
        { k: "Personalidad", plex: "Definida, “ingenierada”, no genérica", source: "Cálida, discreta", public: "Institucional, sobria", system: "Ninguna propia" },
        { k: "Español + á é í ó ú ñ", plex: "Sólido, acentos bien pesados", source: "Sólido", public: "Sólido", system: "Depende del SO" },
        { k: "Números / tabular-nums", plex: "Lining + tnum reales, ideales para tabla", source: "tnum disponible", public: "tnum disponible", system: "Según fuente del SO" },
        { k: "Densidad", plex: "Buen ancho, aguanta filas 36 px", source: "Compacta", public: "Compacta", system: "Variable" },
        { k: "Pesos", plex: "100–700 (usamos 400/500/600/700)", source: "200–900", public: "100–900", system: "400/500/600/700 típicos" },
        { k: "Mono para SKU / fiscal", plex: "IBM Plex Mono — pareja nativa", source: "Sin pareja; ui-monospace", public: "Sin pareja; ui-monospace", system: "ui-monospace" },
        { k: "Rendimiento (latin, woff2)", plex: "~22–24 KB/peso + mono ~15 KB", source: "~15–16 KB/peso", public: "~14–15 KB/peso", system: "0 KB (sin descarga)" },
        { k: "Licencia / self-hosting", plex: "OFL · self-host ✔", source: "OFL · self-host ✔", public: "OFL · self-host ✔", system: "n/a" }
      ]
    };
  }
};
</script>

<style lang="scss" scoped>
.ty-eval { margin-bottom: var(--pxn-space-9); }
.ty-eval__scroll { overflow-x: auto; border: 1px solid var(--pxn-border); border-radius: var(--pxn-radius-lg); }
.ty-eval__table { width: 100%; min-width: 720px; border-collapse: separate; border-spacing: 0; font-size: var(--pxn-fs-sm); }
.ty-eval__table th, .ty-eval__table td { padding: var(--pxn-space-4) var(--pxn-space-5); text-align: left; border-bottom: 1px solid var(--pxn-border); vertical-align: top; }
.ty-eval__table thead th { background: var(--pxn-surface-2); font-size: var(--pxn-fs-xs); text-transform: uppercase; letter-spacing: 0.04em; color: var(--pxn-ink-2); }
.ty-eval__table tbody th { font-weight: var(--pxn-fw-semibold); color: var(--pxn-ink); white-space: nowrap; }
.ty-eval__table td { color: var(--pxn-ink-2); }
.ty-eval__table tr:last-child th, .ty-eval__table tr:last-child td { border-bottom: 0; }
.ty-eval__lean { display: inline-flex; align-items: center; gap: var(--pxn-space-3); margin-top: var(--pxn-space-5); font-size: var(--pxn-fs-sm); color: var(--pxn-ink-2); }

.ty-specimens { display: grid; grid-template-columns: repeat(2, minmax(0, 1fr)); gap: var(--pxn-space-6); }
@media (max-width: 1200px) { .ty-specimens { grid-template-columns: minmax(0, 1fr); } }

.ty-spec { border: 1px solid var(--pxn-border); border-radius: var(--pxn-radius-lg); overflow: hidden; background: var(--pxn-surface); font-family: var(--pxn-font-sans); }
.ty-spec__bar { display: flex; align-items: baseline; justify-content: space-between; gap: var(--pxn-space-4); padding: var(--pxn-space-4) var(--pxn-space-6); background: var(--pxn-surface-2); border-bottom: 1px solid var(--pxn-border); }
.ty-spec__bar b { font-size: var(--pxn-fs-sm); color: var(--pxn-ink); }
.ty-spec__bar span { font-size: 10px; color: var(--pxn-ink-3); }
.ty-spec__body { padding: var(--pxn-space-6); display: flex; flex-direction: column; gap: var(--pxn-space-6); min-width: 0; }
.ty-spec__body > * { min-width: 0; }

.ty-spec__pangram { font-size: 20px; font-weight: var(--pxn-fw-semibold); letter-spacing: -0.015em; color: var(--pxn-ink); line-height: 1.3; }
.ty-spec__glyphs { font-size: var(--pxn-fs-body); color: var(--pxn-ink-2); overflow-wrap: anywhere; }
.ty-spec__pangram, .ty-spec__ph h3 { overflow-wrap: anywhere; }

.ty-spec__ph { border-top: 1px solid var(--pxn-border); padding-top: var(--pxn-space-5); }
.ty-spec__crumb { font-size: var(--pxn-fs-xs); color: var(--pxn-ink-3); }
.ty-spec__ph h3 { font-size: 24px; font-weight: var(--pxn-fw-bold); letter-spacing: -0.025em; color: var(--pxn-ink); margin-top: 2px; }
.ty-spec__ph p { font-size: var(--pxn-fs-sm); color: var(--pxn-ink-3); margin-top: var(--pxn-space-3); }

.ty-spec__kpis { display: grid; grid-template-columns: repeat(3, 1fr); gap: var(--pxn-space-4); }
.ty-spec__kpi { display: flex; flex-direction: column; gap: 2px; }
.ty-spec__kpi-l { font-size: var(--pxn-fs-xs); color: var(--pxn-ink-2); }
.ty-spec__kpi-v { font-size: 20px; font-weight: var(--pxn-fw-bold); letter-spacing: -0.02em; color: var(--pxn-ink); }
.ty-spec__kpi-d { font-size: var(--pxn-fs-xs); font-weight: var(--pxn-fw-semibold); }
.ty-spec__kpi-d.up { color: var(--pxn-success-ink); }
.ty-spec__kpi-d.down { color: var(--pxn-danger-ink); }

.ty-spec__table { width: 100%; border-collapse: separate; border-spacing: 0; border: 1px solid var(--pxn-border); border-radius: var(--pxn-radius-md); overflow: hidden; font-size: var(--pxn-fs-sm); display: block; overflow-x: auto; }
.ty-spec__table thead, .ty-spec__table tbody { display: table; width: 100%; min-width: 340px; }
.ty-spec__table th, .ty-spec__table td { padding: var(--pxn-space-3) var(--pxn-space-4); border-bottom: 1px solid var(--pxn-border); white-space: nowrap; }
.ty-spec__table thead th { background: var(--pxn-surface-2); font-size: 10px; text-transform: uppercase; letter-spacing: 0.04em; color: var(--pxn-ink-2); text-align: left; }
.ty-spec__table .r { text-align: right; }
.ty-spec__table .l { white-space: normal; color: var(--pxn-ink); }
.ty-spec__table td { color: var(--pxn-ink-2); }
.ty-spec__table tr:last-child td { border-bottom: 0; }

.ty-spec__form { display: grid; grid-template-columns: repeat(auto-fit, minmax(200px, 1fr)); gap: var(--pxn-space-4); }
.ty-spec__form label { display: flex; flex-direction: column; gap: var(--pxn-space-2); font-size: var(--pxn-fs-xs); font-weight: var(--pxn-fw-medium); color: var(--pxn-ink-2); }
.ty-spec__form input { height: 34px; padding: 0 var(--pxn-space-4); border: 1px solid var(--pxn-border-control); border-radius: var(--pxn-radius-md); background: var(--pxn-surface-2); font: inherit; font-size: var(--pxn-fs-sm); color: var(--pxn-ink); }

.ty-spec__modal { border: 1px solid var(--pxn-border); border-radius: var(--pxn-radius-md); box-shadow: var(--pxn-shadow-menu); overflow: hidden; }
.ty-spec__modal-h { padding: var(--pxn-space-4) var(--pxn-space-5); font-weight: var(--pxn-fw-semibold); font-size: var(--pxn-fs-sm); border-bottom: 1px solid var(--pxn-border); color: var(--pxn-ink); }
.ty-spec__modal p { padding: var(--pxn-space-4) var(--pxn-space-5); font-size: var(--pxn-fs-sm); color: var(--pxn-ink-2); }
.ty-spec__modal-f { display: flex; justify-content: flex-end; gap: var(--pxn-space-3); padding: var(--pxn-space-4) var(--pxn-space-5); background: var(--pxn-surface-2); border-top: 1px solid var(--pxn-border); }
.ty-spec__modal-f .btn { font-size: var(--pxn-fs-xs); font-weight: var(--pxn-fw-medium); padding: 6px 12px; border-radius: var(--pxn-radius-sm); }
.ty-spec__modal-f .ghost { color: var(--pxn-ink-2); border: 1px solid var(--pxn-border-control); }
.ty-spec__modal-f .danger { background: var(--pxn-danger); color: #fff; }
</style>
