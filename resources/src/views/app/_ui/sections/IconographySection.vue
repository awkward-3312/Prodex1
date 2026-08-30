<template>
  <section>
    <section-head
      num="09"
      title="Iconografía"
      desc="Un solo set: Lucide (ya presente en PRODEX como lucide-vue / <lucide-icon>). Trazo 1.5–2, tamaños 13 / 15 / 16 / 17 px según contexto. El icono acompaña al texto — casi nunca va solo."
    />

    <div class="ic-rules">
      <div class="ic-rule"><b>Tamaño</b><span>13 en badges y meta · 15 en menús y campos · 16 en botones · 17 en el rail</span></div>
      <div class="ic-rule"><b>Color</b><span>hereda del texto (<code>currentColor</code>). Nunca el acento salvo estado activo.</span></div>
      <div class="ic-rule"><b>Uso</b><span>refuerza, no sustituye. Todo icono-solo lleva <code>aria-label</code>.</span></div>
    </div>

    <div class="ic-groups">
      <div v-for="g in groups" :key="g.name" class="ic-group">
        <span class="ic-group__name">{{ g.name }}</span>
        <div class="ic-grid">
          <div v-for="ic in g.icons" :key="ic" class="ic-cell" :title="ic">
            <lucide-icon :name="ic" :size="18" />
            <code>{{ ic }}</code>
          </div>
        </div>
      </div>
    </div>

    <div class="ic-context">
      <span class="ic-context__label">En contexto</span>
      <div class="ic-context__row">
        <px-badge tone="success" icon="check">Emitida</px-badge>
        <px-badge tone="warning" icon="calendar-clock">Por vencer</px-badge>
        <px-button size="sm" variant="secondary" icon="arrow-left-right">Trasladar</px-button>
        <px-button size="sm" variant="ghost" icon="printer" icon-only aria-label="Imprimir" />
        <span class="ic-inline"><lucide-icon name="building-2" :size="13" /> Sucursal SPS</span>
      </div>
    </div>
  </section>
</template>

<script>
import SectionHead from "./_SectionHead.vue";
import { PxBadge, PxButton } from "@/components/px-next";
export default {
  name: "IconographySection",
  components: { SectionHead, PxBadge, PxButton },
  props: { density: String, country: String },
  data() {
    return {
      groups: [
        { name: "Operación", icons: ["scan-line", "receipt", "shopping-cart", "boxes", "package", "arrow-left-right", "warehouse", "clipboard-list"] },
        { name: "Estado", icons: ["check", "clock", "x", "shield", "calendar-clock", "alert-triangle", "cloud-check", "x-circle"] },
        { name: "Datos", icons: ["bar-chart-3", "table", "trending-up", "trending-down", "percent", "hash", "filter", "arrow-right-left"] },
        { name: "Acción", icons: ["plus", "pencil", "trash-2", "download", "printer", "mail", "more-vertical", "settings-2"] },
        { name: "Entidad", icons: ["store", "building-2", "users", "truck", "id-card", "user", "map-pin", "landmark"] }
      ]
    };
  }
};
</script>

<style lang="scss" scoped>
.ic-rules { display: grid; grid-template-columns: repeat(3, 1fr); gap: var(--pxn-space-4); margin-bottom: var(--pxn-space-8); }
@media (max-width: 820px) { .ic-rules { grid-template-columns: minmax(0, 1fr); } }
.ic-rule { padding: var(--pxn-space-5); border: 1px solid var(--pxn-border); border-radius: var(--pxn-radius-md); background: var(--pxn-surface); }
.ic-rule b { display: block; font-size: var(--pxn-fs-sm); color: var(--pxn-ink); margin-bottom: var(--pxn-space-2); }
.ic-rule span { font-size: var(--pxn-fs-xs); color: var(--pxn-ink-3); }

.ic-groups { display: flex; flex-direction: column; gap: var(--pxn-space-7); }
.ic-group__name { display: block; font-size: var(--pxn-fs-xs); font-weight: var(--pxn-fw-semibold); text-transform: uppercase; letter-spacing: 0.05em; color: var(--pxn-ink-3); margin-bottom: var(--pxn-space-4); }
.ic-grid { display: grid; grid-template-columns: repeat(auto-fill, minmax(128px, 1fr)); gap: var(--pxn-space-3); }
.ic-cell {
  display: flex; align-items: center; gap: var(--pxn-space-3);
  padding: var(--pxn-space-4);
  border: 1px solid var(--pxn-border); border-radius: var(--pxn-radius-md);
  background: var(--pxn-surface); color: var(--pxn-ink-2);
}
.ic-cell code { font-size: 10px; color: var(--pxn-ink-3); overflow: hidden; text-overflow: ellipsis; white-space: nowrap; }

.ic-context { margin-top: var(--pxn-space-8); padding: var(--pxn-space-6); border: 1px solid var(--pxn-border); border-radius: var(--pxn-radius-lg); background: var(--pxn-surface); }
.ic-context__label { display: block; font-size: var(--pxn-fs-xs); font-weight: var(--pxn-fw-semibold); text-transform: uppercase; letter-spacing: 0.05em; color: var(--pxn-ink-3); margin-bottom: var(--pxn-space-5); }
.ic-context__row { display: flex; flex-wrap: wrap; align-items: center; gap: var(--pxn-space-4); }
.ic-inline { display: inline-flex; align-items: center; gap: var(--pxn-space-2); font-size: var(--pxn-fs-sm); color: var(--pxn-ink-3); }
</style>
