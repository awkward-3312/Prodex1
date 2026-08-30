<template>
  <section>
    <section-head
      num="06"
      title="Navegación e interacción"
      desc="Tabs, menús de acción (kebab) y modal. El motion aquí es orientación: de dónde salió el menú, hacia dónde va el modal — no decoración."
    />

    <h3 class="nv-h3">Tabs</h3>
    <div class="nv-panel">
      <div>
        <span class="nv-label">Línea (por defecto)</span>
        <px-tabs v-model="tab1" :tabs="tabsLine" />
        <div class="nv-tabpane">Contenido de «{{ tabLabel(tabsLine, tab1) }}».</div>
      </div>
      <div>
        <span class="nv-label">Segmentado</span>
        <px-tabs v-model="tab2" :tabs="tabsPill" variant="pill" />
      </div>
    </div>

    <h3 class="nv-h3">Menús de acción</h3>
    <div class="nv-panel nv-panel--row">
      <div class="nv-menudemo">
        <span class="nv-label">Kebab (fila / tarjeta)</span>
        <px-kebab
          :items="[
            { header: 'Documento' },
            { label: 'Ver factura', icon: 'file-text' },
            { label: 'Reimprimir', icon: 'printer', hint: '⌘P' },
            { label: 'Enviar por correo', icon: 'mail' },
            { divider: true },
            { label: 'Anular', icon: 'x-circle', tone: 'danger' }
          ]"
          @select="lastAction = $event.label"
        />
      </div>
      <div class="nv-menudemo">
        <span class="nv-label">Botón + menú</span>
        <px-menu
          align="start"
          :items="[
            { label: 'Exportar a Excel', icon: 'file-spreadsheet' },
            { label: 'Exportar a PDF', icon: 'file-down' },
            { label: 'Copiar enlace', icon: 'link' }
          ]"
          @select="lastAction = $event.label"
        >
          <template #trigger="{ open }">
            <px-button variant="secondary" trailing-icon="chevron-down" :class="{ 'nv-open': open }">Acciones</px-button>
          </template>
        </px-menu>
      </div>
      <p class="nv-last">Última acción: <b>{{ lastAction || '—' }}</b></p>
    </div>

    <h3 class="nv-h3">Modal</h3>
    <div class="nv-panel nv-panel--row">
      <px-button variant="secondary" @click="showForm = true">Modal de formulario</px-button>
      <px-button variant="danger" @click="showConfirm = true">Modal de confirmación</px-button>
    </div>

    <px-modal v-model="showForm" title="Ajustar existencias" subtitle="Acetaminofén 500 mg · caja 100 tab" size="md">
      <div class="nv-form">
        <px-field label="Ubicación">
          <template #default="{ id }"><px-select :id="id" v-model="navUbicacion" :options="['Piso de venta', 'Bodega interna', 'Cuarentena']" /></template>
        </px-field>
        <px-field label="Tipo de ajuste">
          <template #default="{ id }"><px-select :id="id" v-model="navAjuste" :options="['Entrada', 'Salida', 'Merma', 'Recuento']" /></template>
        </px-field>
        <px-field label="Cantidad" required>
          <template #default="{ id }"><px-input :id="id" numeric inputmode="numeric" value="1240" /></template>
        </px-field>
        <px-field label="Motivo" optional>
          <template #default="{ id }"><px-textarea :id="id" rows="2" placeholder="Diferencia de recuento físico…" /></template>
        </px-field>
      </div>
      <template #footer="{ close }">
        <px-button variant="ghost" @click="close">Cancelar</px-button>
        <px-button variant="primary" @click="close">Guardar ajuste</px-button>
      </template>
    </px-modal>

    <px-modal v-model="showConfirm" title="Anular factura 001-001-01-00045210" size="sm">
      <p>Se registrará una nota de crédito por <b class="pxn-num">L 96.00</b>. La secuencia fiscal se conserva y la acción queda en la bitácora. No puede revertirse.</p>
      <template #footer="{ close }">
        <px-button variant="ghost" @click="close">Cancelar</px-button>
        <px-button variant="danger" @click="close">Anular factura</px-button>
      </template>
    </px-modal>
  </section>
</template>

<script>
import SectionHead from "./_SectionHead.vue";
import { PxTabs, PxKebab, PxMenu, PxButton, PxModal, PxField, PxSelect, PxInput, PxTextarea } from "@/components/px-next";
export default {
  name: "NavigationSection",
  components: { SectionHead, PxTabs, PxKebab, PxMenu, PxButton, PxModal, PxField, PxSelect, PxInput, PxTextarea },
  props: { density: String, country: String },
  data() {
    return {
      tab1: "todas",
      tab2: "mes",
      lastAction: "",
      showForm: false,
      showConfirm: false,
      navUbicacion: "Piso de venta",
      navAjuste: "Recuento",
      tabsLine: [
        { value: "todas", label: "Todas", count: 1284 },
        { value: "activas", label: "Activas", count: 1190 },
        { value: "quiebre", label: "En quiebre", count: 17 },
        { value: "descontinuadas", label: "Descontinuadas", count: 77, disabled: false }
      ],
      tabsPill: [
        { value: "hoy", label: "Hoy" },
        { value: "semana", label: "Semana" },
        { value: "mes", label: "Mes" },
        { value: "ano", label: "Año" }
      ]
    };
  },
  methods: {
    tabLabel(list, v) { const t = list.find(x => x.value === v); return t ? t.label : ""; }
  }
};
</script>

<style lang="scss" scoped>
.nv-h3 { font-size: var(--pxn-fs-h3); font-weight: var(--pxn-fw-semibold); color: var(--pxn-ink); margin: var(--pxn-space-9) 0 var(--pxn-space-5); }
.nv-h3:first-of-type { margin-top: 0; }
.nv-panel { display: flex; flex-direction: column; gap: var(--pxn-space-7); padding: var(--pxn-space-7); border: 1px solid var(--pxn-border); border-radius: var(--pxn-radius-lg); background: var(--pxn-surface); }
.nv-panel--row { flex-direction: row; flex-wrap: wrap; align-items: center; gap: var(--pxn-space-6); }
.nv-label { display: block; font-size: var(--pxn-fs-xs); font-weight: var(--pxn-fw-semibold); text-transform: uppercase; letter-spacing: 0.05em; color: var(--pxn-ink-3); margin-bottom: var(--pxn-space-4); }
.nv-tabpane { margin-top: var(--pxn-space-5); font-size: var(--pxn-fs-sm); color: var(--pxn-ink-2); }
.nv-menudemo { display: flex; flex-direction: column; }
.nv-last { font-size: var(--pxn-fs-sm); color: var(--pxn-ink-2); margin: 0 0 0 auto; }
.nv-form { display: grid; grid-template-columns: 1fr 1fr; gap: var(--pxn-space-5); }
.nv-form > *:last-child { grid-column: 1 / -1; }
</style>
