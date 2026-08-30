<template>
  <section>
    <section-head
      num="04"
      title="Datos y estado"
      desc="Tarjetas, KPI, badges de estado, etiquetas de entidad, avatares y celdas de entidad. Las cifras aparecen de inmediato — sin animación de conteo."
    />

    <!-- Regional formatting: same figures, tenant-country presentation -->
    <h3 class="dd-h3">Formato regional <span class="dd-note">mismo dato · presentación según país y moneda del tenant · Centroamérica-first</span></h3>
    <div class="dd-region">
      <div class="dd-region__scroll pxn-scroll">
      <table class="dd-region__table pxn-num">
        <thead>
          <tr>
            <th class="l">País / moneda</th>
            <th>Total de venta</th>
            <th>Cantidad</th>
            <th>Fecha y hora</th>
            <th>% margen</th>
          </tr>
        </thead>
        <tbody>
          <tr v-for="(c, k) in countries" :key="k" :class="{ 'is-current': k === country }">
            <td class="l">
              <button type="button" class="dd-region__pick pxn-ring" @click="$emit('country', k)">
                <span class="dd-region__flag">{{ k }}</span>{{ c.name }} · {{ c.currency }}
              </button>
            </td>
            <td>{{ money(486230.5, k) }}</td>
            <td>{{ num(1284, k) }}</td>
            <td>{{ dt('2026-08-27T14:22:00', k) }}</td>
            <td>{{ pct(31.6, k) }}</td>
          </tr>
        </tbody>
      </table>
      </div>
      <p class="dd-region__note">
        RTN, CAI y correlativos son ejemplos del <b>módulo Honduras</b>, no primitivos universales:
        el sistema de diseño los trata como <i>identificación tributaria / autorización fiscal / secuencia fiscal</i>,
        y su formato y validación concretos dependen del país.
      </p>
    </div>

    <!-- KPIs -->
    <div class="dd-kpihead">
      <h3 class="dd-h3">KPI / stats</h3>
      <label class="dd-country">
        <span>Formato del tenant</span>
        <select :value="country" @change="$emit('country', $event.target.value)">
          <option v-for="(c, k) in countries" :key="k" :value="k">{{ c.name }} · {{ c.currency }}</option>
        </select>
      </label>
    </div>
    <div class="pxn-grid pxn-grid-3">
      <px-card v-for="k in kpis" :key="k.key">
        <px-stat
          :label="k.label"
          :value="formatKpi(k)"
          :delta="k.delta"
          :delta-tone="k.tone"
          :sub="k.sub"
          :icon="iconFor(k.key)"
        />
      </px-card>
    </div>

    <!-- Cards -->
    <h3 class="dd-h3">Tarjetas y secciones</h3>
    <div class="pxn-grid pxn-grid-3">
      <px-card title="Resumen de caja" subtitle="Turno actual · Sucursal SPS">
        <template #actions><px-kebab :items="[{ label: 'Ver movimientos', icon: 'list' }, { label: 'Arqueo', icon: 'calculator' }]" /></template>
        <dl class="dd-defs">
          <div><dt>Apertura</dt><dd class="pxn-num">{{ money(5000) }}</dd></div>
          <div><dt>Ventas en efectivo</dt><dd class="pxn-num">{{ money(38240.5) }}</dd></div>
          <div><dt>Retiros</dt><dd class="pxn-num">−{{ money(12000) }}</dd></div>
          <div class="dd-defs__total"><dt>Esperado en caja</dt><dd class="pxn-num">{{ money(31240.5) }}</dd></div>
        </dl>
      </px-card>

      <px-card title="Próximos vencimientos" subtitle="Lotes en 30 días">
        <ul class="dd-list">
          <li v-for="b in expiring" :key="b.sku">
            <span class="dd-list__name">{{ b.name }}</span>
            <span class="dd-list__meta pxn-mono">{{ b.sku }}</span>
            <px-badge tone="warning" icon="calendar-clock">{{ b.days }} d</px-badge>
          </li>
        </ul>
      </px-card>

      <px-card interactive title="Tienda en línea" subtitle="Últimas 24 h">
        <div class="dd-store">
          <px-stat label="Pedidos" :value="String(28)" delta="6" delta-tone="up" sub="vs. ayer" />
          <hr class="pxn-divider" />
          <p class="pxn-fs-sm pxn-muted">3 pedidos requieren confirmación de pago manual.</p>
        </div>
      </px-card>
    </div>

    <!-- Badges: status -->
    <h3 class="dd-h3">Badges de estado <span class="dd-note">semánticos · siempre con icono + texto</span></h3>
    <div class="dd-panel">
      <div class="dd-badges">
        <px-badge tone="success" icon="check">Emitida</px-badge>
        <px-badge tone="warning" icon="clock">Pendiente</px-badge>
        <px-badge tone="danger" icon="x">Anulada</px-badge>
        <px-badge tone="info" icon="truck">En tránsito</px-badge>
        <px-badge tone="neutral" icon="file">Borrador</px-badge>
        <px-badge tone="success" solid icon="check">Sólida</px-badge>
        <px-badge tone="danger" dot>Con punto</px-badge>
      </div>
      <div class="dd-opstates">
        <span class="dd-note">Estados operativos</span>
        <div class="dd-badges">
          <px-badge v-for="(s, i) in opStates" :key="i" :tone="s.tone" :icon="s.icon">{{ s.label }}</px-badge>
        </div>
      </div>
    </div>

    <!-- Entity tags -->
    <h3 class="dd-h3">Etiquetas de entidad <span class="dd-note">categorías / tipos / centros de costo · paleta auxiliar de baja saturación</span></h3>
    <div class="dd-panel">
      <div class="dd-badges">
        <px-tag v-for="t in cats" :key="t" :label="t" :hue="t" />
      </div>
      <div class="dd-badges">
        <px-tag label="Mayorista" hue="teal" removable @remove="() => {}" />
        <px-tag label="Contado" hue="slate" removable @remove="() => {}" />
        <px-tag label="Centro de costo 4100" hue="indigo" removable @remove="() => {}" />
      </div>
    </div>

    <!-- Avatars + entity cells -->
    <h3 class="dd-h3">Avatares y celdas de entidad</h3>
    <div class="dd-panel">
      <div class="dd-avatars">
        <px-avatar name="Betzabé Escobar" size="xs" />
        <px-avatar name="Óscar Munguía" size="sm" status="online" />
        <px-avatar name="Keyla Rodríguez" size="md" status="busy" />
        <px-avatar name="Distribuidora El Progreso" size="lg" shape="square" icon="building-2" />
      </div>
      <div class="dd-cells">
        <px-entity-cell
          v-for="c in customers"
          :key="c.name"
          :name="c.name"
          :secondary="c.secondary"
          shape="square"
          icon="store"
        >
          <template #badge><px-tag :label="c.tag" :hue="c.tag" /></template>
        </px-entity-cell>
      </div>
    </div>
  </section>
</template>

<script>
import SectionHead from "./_SectionHead.vue";
import { PxCard, PxStat, PxBadge, PxTag, PxAvatar, PxEntityCell, PxKebab } from "@/components/px-next";
import { KPIS, OPERATIONAL_STATES, CUSTOMERS } from "../data/mock";
import { money as fmtMoney, percent, number, date as fmtDate, COUNTRIES } from "../data/format";

export default {
  name: "DataDisplaySection",
  components: { SectionHead, PxCard, PxStat, PxBadge, PxTag, PxAvatar, PxEntityCell, PxKebab },
  props: { density: String, country: { type: String, default: "HN" } },
  data() {
    return {
      kpis: KPIS,
      opStates: OPERATIONAL_STATES,
      customers: CUSTOMERS,
      countries: COUNTRIES,
      cats: ["Farmacia", "Abarrotes", "Bebidas", "Ferretería", "Cuidado personal", "Limpieza"],
      expiring: [
        { name: "Amoxicilina 500 mg · blíster 21", sku: "AMX-500-21", days: 14 },
        { name: "Frijol rojo seleccionado 2 lb", sku: "ABA-FR-900", days: 5 },
        { name: "Jugo de naranja 1 L", sku: "BEB-JN-1000", days: 6 }
      ]
    };
  },
  methods: {
    money(v, c) { return fmtMoney(v, { country: c || this.country }); },
    num(v, c) { return number(v, { country: c || this.country }); },
    pct(v, c) { return percent(v, { country: c || this.country }); },
    dt(iso, c) { return fmtDate(iso, { country: c || this.country, withTime: true }); },
    formatKpi(k) {
      if (k.kind === "money") return fmtMoney(k.raw, { country: this.country });
      if (k.kind === "percent") return percent(k.raw, { country: this.country });
      if (k.kind === "ratio") return number(k.raw, { country: this.country, decimals: 1 }) + "×";
      return number(k.raw, { country: this.country });
    },
    iconFor(key) {
      return {
        ventas_hoy: "trending-up", ticket: "receipt", margen: "percent",
        por_cobrar: "coins", sku_quiebre: "package-search", rotacion: "refresh-cw"
      }[key] || "circle";
    }
  }
};
</script>

<style lang="scss" scoped>
.dd-h3 { font-size: var(--pxn-fs-h3); font-weight: var(--pxn-fw-semibold); color: var(--pxn-ink); margin: var(--pxn-space-9) 0 var(--pxn-space-5); }
.dd-h3:first-of-type { margin-top: 0; }
.dd-kpihead { display: flex; align-items: flex-end; justify-content: space-between; gap: var(--pxn-space-5); flex-wrap: wrap; }
.dd-kpihead .dd-h3 { margin: 0 0 var(--pxn-space-5); }

.dd-region { display: flex; flex-direction: column; gap: var(--pxn-space-4); }
.dd-region__scroll { overflow-x: auto; border: 1px solid var(--pxn-border); border-radius: var(--pxn-radius-lg); }
.dd-region__table { width: 100%; min-width: 560px; border-collapse: separate; border-spacing: 0; font-size: var(--pxn-fs-sm); }
.dd-region__table th, .dd-region__table td { padding: var(--pxn-space-4) var(--pxn-space-5); border-bottom: 1px solid var(--pxn-border); text-align: right; white-space: nowrap; }
.dd-region__table th.l, .dd-region__table td.l { text-align: left; }
.dd-region__table thead th { background: var(--pxn-surface-2); font-size: var(--pxn-fs-xs); text-transform: uppercase; letter-spacing: 0.04em; color: var(--pxn-ink-2); }
.dd-region__table tbody td { color: var(--pxn-ink); }
.dd-region__table tr:last-child td { border-bottom: 0; }
.dd-region__table tr.is-current td { background: var(--pxn-selected-bg); }
.dd-region__pick { display: inline-flex; align-items: center; gap: var(--pxn-space-3); border: 0; background: transparent; font: inherit; font-size: var(--pxn-fs-sm); font-weight: var(--pxn-fw-medium); color: var(--pxn-ink); cursor: pointer; }
.dd-region__pick:hover { color: var(--pxn-primary-ink); }
.dd-region__flag { font-family: var(--pxn-font-mono); font-size: 10px; font-weight: var(--pxn-fw-bold); padding: 2px 5px; border-radius: 4px; background: var(--pxn-surface-3); color: var(--pxn-ink-2); }
.dd-region__note { margin: 0; font-size: var(--pxn-fs-xs); color: var(--pxn-ink-3); line-height: var(--pxn-lh-snug); }
.dd-region__note i { font-style: italic; color: var(--pxn-ink-2); }
.dd-note { margin-left: var(--pxn-space-3); font-size: var(--pxn-fs-xs); font-weight: var(--pxn-fw-regular); color: var(--pxn-ink-3); }
.dd-panel { display: flex; flex-direction: column; gap: var(--pxn-space-6); padding: var(--pxn-space-7); border: 1px solid var(--pxn-border); border-radius: var(--pxn-radius-lg); background: var(--pxn-surface); }
.dd-badges { display: flex; flex-wrap: wrap; align-items: center; gap: var(--pxn-space-4); }
.dd-opstates { display: flex; flex-direction: column; gap: var(--pxn-space-4); }

.dd-country { display: inline-flex; align-items: center; gap: var(--pxn-space-3); font-size: var(--pxn-fs-xs); font-weight: var(--pxn-fw-medium); color: var(--pxn-ink-3); margin-bottom: var(--pxn-space-5); }
.dd-country select { height: 32px; border: 1px solid var(--pxn-border-control); border-radius: var(--pxn-radius-md); background: var(--pxn-surface); padding: 0 var(--pxn-space-4); font: inherit; font-size: var(--pxn-fs-sm); color: var(--pxn-ink); }

.dd-defs { display: flex; flex-direction: column; gap: var(--pxn-space-4); }
.dd-defs > div { display: flex; align-items: baseline; justify-content: space-between; gap: var(--pxn-space-5); }
.dd-defs dt { font-size: var(--pxn-fs-sm); color: var(--pxn-ink-2); }
.dd-defs dd { margin: 0; font-size: var(--pxn-fs-body); color: var(--pxn-ink); font-weight: var(--pxn-fw-medium); }
.dd-defs__total { border-top: 1px solid var(--pxn-border); padding-top: var(--pxn-space-4); }
.dd-defs__total dt { font-weight: var(--pxn-fw-semibold); color: var(--pxn-ink); }
.dd-defs__total dd { font-size: var(--pxn-fs-h3); font-weight: var(--pxn-fw-bold); }

.dd-list { list-style: none; margin: 0; padding: 0; display: flex; flex-direction: column; gap: var(--pxn-space-4); }
.dd-list li { display: grid; grid-template-columns: 1fr auto auto; align-items: center; gap: var(--pxn-space-4); }
.dd-list__name { font-size: var(--pxn-fs-sm); color: var(--pxn-ink); white-space: nowrap; overflow: hidden; text-overflow: ellipsis; }
.dd-list__meta { font-size: var(--pxn-fs-xs); color: var(--pxn-ink-3); }

.dd-store { display: flex; flex-direction: column; gap: var(--pxn-space-5); }
.dd-avatars { display: flex; align-items: center; gap: var(--pxn-space-5); }
.dd-cells { display: grid; grid-template-columns: repeat(2, minmax(0, 1fr)); gap: var(--pxn-space-5); }
@media (max-width: 780px) { .dd-cells { grid-template-columns: minmax(0, 1fr); } }
</style>
