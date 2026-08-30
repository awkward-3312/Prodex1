<template>
  <div class="px-next pxdmg">
    <!--
      C3.2 — Listado de daños px-next. Ruta real: /app/damages/list (name index_damage).
      Conserva endpoint (damages?…), filtros, paginación/orden, borrado individual,
      PDF por fila (damage_pdf/{id}), PDF de la lista y CSV, y el detalle en modal
      (damages/detail/{id}), igual que index_Damage.vue legacy.
      Daños SOLO resta stock: no hay selector de tipo en ninguna vista.
    -->
    <div v-if="!can('damage_view')" class="pxdmg__denied">
      <px-empty-state icon="lock" title="No tienes permiso para ver daños"
        description="Pide a un administrador el permiso «damage_view»." />
    </div>

    <template v-else>
      <px-page-header title="Daños" :breadcrumbs="[{ label: 'Inventario' }, { label: 'Daños' }]">
        <template #actions>
          <px-menu :items="exportMenu" align="end" @select="onExport">
            <template #trigger>
              <px-button variant="secondary" size="sm" icon="file-spreadsheet" trailing-icon="chevron-down">Exportar</px-button>
            </template>
          </px-menu>
          <px-button v-if="can('damage_view')" variant="primary" icon="plus" @click="goCreate">Nuevo daño</px-button>
        </template>
      </px-page-header>

      <px-toolbar
        :search="search"
        search-placeholder="Buscar por referencia, almacén…"
        :filter-count="activeFilterCount"
        @update:search="onSearchInput"
        @open-filters="filtersOpen = !filtersOpen"
      />

      <div v-if="filtersOpen" class="pxdmg__filters">
        <div class="pxdmg__filters-grid">
          <px-field label="Fecha">
            <template #default="{ id }"><px-input :id="id" type="date" v-model="fDate" /></template>
          </px-field>
          <px-field label="Referencia">
            <template #default="{ id }"><px-input :id="id" v-model="fRef" placeholder="Referencia" /></template>
          </px-field>
          <px-field label="Almacén">
            <template #default="{ id }">
              <vs-px :input-id="id" v-model="fWarehouse" :reduce="o => o.value" placeholder="Todos los almacenes"
                :options="warehouses.map(w => ({ label: w.name, value: w.id }))" />
            </template>
          </px-field>
        </div>
        <div class="pxdmg__filters-act">
          <px-button size="sm" variant="primary" icon="filter" @click="applyFilters">Filtrar</px-button>
          <px-button size="sm" variant="ghost" icon="x" @click="resetFilters">Limpiar</px-button>
        </div>
      </div>

      <px-alert v-if="error" tone="danger" title="No se pudo cargar el listado" class="pxdmg__alert">
        {{ error }}
        <template #actions><px-button size="sm" variant="secondary" @click="fetch()">Reintentar</px-button></template>
      </px-alert>

      <div v-if="initialLoading" class="pxdmg__pad">
        <px-skeleton variant="table" :rows="10" :columns="5" />
      </div>

      <template v-else>
        <div class="pxdmg__tablewrap" :class="{ 'is-busy': refreshing }">
          <px-table
            v-if="rows.length"
            :columns="columns"
            :rows="rows"
            row-key="id"
            :sort-key="sortColKey"
            :sort-dir="sort.type"
            has-row-actions
            @sort="onSort"
          >
            <template #cell-items="{ row }"><span class="pxn-num">{{ row.items }}</span></template>
            <template #row-actions="{ row }">
              <px-kebab :items="rowActions(row)" @select="onRowAction(row, $event)" />
            </template>
          </px-table>

          <px-empty-state v-else icon="filter" title="Sin daños"
            description="No hay daños que coincidan con los filtros." />
        </div>

        <px-pagination
          v-if="rows.length"
          :page="page"
          :per-page="Number(limit)"
          :total="Number(totalRows) || 0"
          :per-page-options="['10', '25', '50', '100']"
          @update:page="onPage"
          @update:perPage="onLimit"
        />
      </template>

      <px-modal v-model="confirmOpen" title="Eliminar daño" size="sm">
        <p class="pxdmg__confirm">
          ¿Eliminar el registro de daño <strong>{{ pendingDelete && pendingDelete.Ref }}</strong>? Se revertirán los movimientos de stock asociados.
        </p>
        <template #footer="{ close }">
          <span class="pxdmg__grow" />
          <px-button variant="secondary" :disabled="deleting" @click="close">Cancelar</px-button>
          <px-button variant="danger" icon="trash-2" :loading="deleting" @click="doDelete">Eliminar</px-button>
        </template>
      </px-modal>

      <px-modal v-model="detailOpen" :title="'Detalle de daño'" size="lg">
        <div v-if="detailLoading" class="pxdmg__detail-loading">Cargando…</div>
        <template v-else>
          <dl class="pxdmg__detail-dl">
            <div><dt>Fecha</dt><dd>{{ detail.damage.date || '—' }}</dd></div>
            <div><dt>Referencia</dt><dd class="pxn-mono">{{ detail.damage.Ref || '—' }}</dd></div>
            <div><dt>Almacén</dt><dd>{{ detail.damage.warehouse || '—' }}</dd></div>
          </dl>
          <div class="pxdmg-dtbl__wrap pxn-scroll">
            <table class="pxdmg-dtbl">
              <thead>
                <tr><th>Producto</th><th>Código</th><th class="is-right">Cantidad</th></tr>
              </thead>
              <tbody>
                <tr v-if="!detail.details.length"><td colspan="3" class="pxdmg-dtbl__empty">Sin líneas.</td></tr>
                <tr v-for="(d, i) in detail.details" :key="i">
                  <td>{{ d.name }}</td>
                  <td class="pxn-mono">{{ d.code }}</td>
                  <td class="is-right pxn-num">{{ fmt(d.quantity) }} {{ d.unit }}</td>
                </tr>
              </tbody>
            </table>
          </div>
          <p v-if="detail.damage.note" class="pxdmg__detail-note">{{ detail.damage.note }}</p>
        </template>
        <template #footer="{ close }">
          <span class="pxdmg__grow" />
          <px-button variant="secondary" @click="close">Cerrar</px-button>
        </template>
      </px-modal>
    </template>
  </div>
</template>

<script>
import { mapGetters } from "vuex";
import NProgress from "nprogress";
import jsPDF from "jspdf";
import autoTable from "jspdf-autotable";
import { getPriceDecimals } from "@/utils/priceFormat";
import PxPageHeader from "@/components/px-next/PxPageHeader.vue";
import PxToolbar from "@/components/px-next/PxToolbar.vue";
import PxTable from "@/components/px-next/PxTable.vue";
import PxPagination from "@/components/px-next/PxPagination.vue";
import PxButton from "@/components/px-next/PxButton.vue";
import PxMenu from "@/components/px-next/PxMenu.vue";
import PxKebab from "@/components/px-next/PxKebab.vue";
import PxField from "@/components/px-next/PxField.vue";
import PxInput from "@/components/px-next/PxInput.vue";
import PxAlert from "@/components/px-next/PxAlert.vue";
import PxEmptyState from "@/components/px-next/PxEmptyState.vue";
import PxModal from "@/components/px-next/PxModal.vue";
import VsPx from "@/views/app/products/next/edit/VsPx.vue";

export default {
  name: "DamageListNext",
  metaInfo: { title: "Daños" },
  components: {
    PxPageHeader, PxToolbar, PxTable, PxPagination, PxButton, PxMenu, PxKebab,
    PxField, PxInput, PxAlert, PxEmptyState, PxModal, "vs-px": VsPx
  },
  data() {
    return {
      initialLoading: true,
      refreshing: false,
      error: null,
      damages: [],
      warehouses: [],
      totalRows: "",
      page: 1,
      limit: "10",
      search: "",
      _searchTimer: null,
      sort: { field: "id", type: "desc" },
      filtersOpen: false,
      fDate: "",
      fRef: "",
      fWarehouse: "",
      confirmOpen: false,
      pendingDelete: null,
      deleting: false,
      detailOpen: false,
      detailLoading: false,
      detail: { damage: {}, details: [] }
    };
  },
  computed: {
    ...mapGetters(["currentUserPermissions"]),
    priceDecimals() {
      return getPriceDecimals({ store: this.$store });
    },
    columns() {
      return [
        { key: "date", label: "Fecha", sortable: true, width: "130px" },
        { key: "Ref", label: "Referencia", sortable: true, strong: true },
        { key: "warehouse_name", label: "Almacén", sortable: true },
        { key: "items", label: "Productos", align: "right", numeric: true, sortable: true, width: "120px" }
      ];
    },
    rows() {
      return this.damages || [];
    },
    sortColKey() {
      return this.sort.field === "warehouse_id" ? "warehouse_name" : this.sort.field;
    },
    activeFilterCount() {
      return [this.fDate, this.fRef, this.fWarehouse].filter(v => v !== "" && v != null).length;
    },
    exportMenu() {
      return [
        { key: "pdf", label: "PDF de la lista", icon: "file-text" },
        { key: "xlsx", label: "Excel (CSV)", icon: "file-spreadsheet" }
      ];
    }
  },
  created() {
    this.fetch(true);
  },
  methods: {
    can(p) {
      const list = Array.isArray(this.currentUserPermissions) ? this.currentUserPermissions : [];
      return list.includes(p);
    },
    fmt(n) {
      const v = Number(n);
      if (!Number.isFinite(v)) return "0";
      return v.toFixed(this.priceDecimals);
    },
    goCreate() {
      this.$router.push({ name: "store_damage" });
    },
    rowActions(row) {
      const items = [{ key: "pdf", label: "Descargar PDF", icon: "file-text" }, { key: "view", label: "Ver detalle", icon: "eye" }];
      if (this.can("adjustment_edit")) items.push({ key: "edit", label: "Editar", icon: "pencil" });
      if (this.can("adjustment_delete")) items.push({ key: "delete", label: "Eliminar", icon: "trash-2", tone: "danger" });
      return items;
    },
    onRowAction(row, item) {
      const k = item && item.key;
      if (k === "pdf") this.downloadRowPdf(row);
      else if (k === "view") this.openDetail(row);
      else if (k === "edit") this.$router.push({ name: "edit_damage", params: { id: row.id } });
      else if (k === "delete") { this.pendingDelete = row; this.confirmOpen = true; }
    },
    onSearchInput(v) {
      this.search = v;
      if (this._searchTimer) clearTimeout(this._searchTimer);
      this._searchTimer = setTimeout(() => { this.page = 1; this.fetch(); }, 350);
    },
    onSort({ key, dir }) {
      this.sort = { field: key === "warehouse_name" ? "warehouse_id" : key, type: dir };
      this.fetch();
    },
    onPage(p) { if (p !== this.page) { this.page = p; this.fetch(); } },
    onLimit(v) { this.limit = String(v); this.page = 1; this.fetch(); },
    applyFilters() { this.page = 1; this.fetch(); },
    resetFilters() {
      this.search = ""; this.fDate = ""; this.fRef = ""; this.fWarehouse = "";
      this.page = 1; this.fetch();
    },
    fetch(initial) {
      if (initial) this.initialLoading = true; else this.refreshing = true;
      this.error = null;
      NProgress.start(); NProgress.set(0.1);
      const wh = this.fWarehouse == null ? "" : this.fWarehouse;
      const qs =
        "damages?page=" + this.page +
        "&Ref=" + encodeURIComponent(this.fRef || "") +
        "&warehouse_id=" + encodeURIComponent(wh) +
        "&date=" + encodeURIComponent(this.fDate || "") +
        "&SortField=" + this.sort.field +
        "&SortType=" + this.sort.type +
        "&search=" + encodeURIComponent(this.search || "") +
        "&limit=" + this.limit;
      window.axios
        .get(qs)
        .then(response => {
          this.damages = response.data.damages || [];
          this.warehouses = response.data.warehouses || this.warehouses;
          this.totalRows = response.data.totalRows;
          NProgress.done();
          this.initialLoading = false;
          this.refreshing = false;
        })
        .catch(err => {
          NProgress.done();
          this.error =
            (err && err.response && err.response.data && (err.response.data.message || err.response.data.error)) ||
            (err && err.message) || "Error de red.";
          setTimeout(() => { this.initialLoading = false; this.refreshing = false; }, 300);
        });
    },
    openDetail(row) {
      this.detailOpen = true;
      this.detailLoading = true;
      this.detail = { damage: {}, details: [] };
      NProgress.start(); NProgress.set(0.1);
      window.axios
        .get("damages/detail/" + row.id)
        .then(response => {
          this.detail = {
            damage: response.data.damage || {},
            details: response.data.details || []
          };
          this.detailLoading = false;
          NProgress.done();
        })
        .catch(() => {
          this.detailLoading = false;
          NProgress.done();
          this.makeToast("danger", "No se pudo cargar el detalle.", "Error");
        });
    },
    doDelete() {
      const row = this.pendingDelete;
      if (!row) return;
      this.deleting = true;
      NProgress.start(); NProgress.set(0.1);
      window.axios
        .delete("damages/" + row.id)
        .then(() => {
          this.deleting = false;
          this.confirmOpen = false;
          this.pendingDelete = null;
          this.makeToast("success", "Registro de daño eliminado.", "Éxito");
          NProgress.done();
          this.fetch();
        })
        .catch(() => {
          this.deleting = false;
          NProgress.done();
          this.makeToast("danger", "No se pudo eliminar el registro.", "Error");
        });
    },
    downloadRowPdf(row) {
      NProgress.start(); NProgress.set(0.1);
      window.axios
        .get("damage_pdf/" + row.id, { responseType: "blob", headers: { "Content-Type": "application/json" } })
        .then(response => {
          const url = window.URL.createObjectURL(new Blob([response.data]));
          const link = document.createElement("a");
          link.href = url;
          link.setAttribute("download", "Damage-" + (row.Ref || row.id) + ".pdf");
          document.body.appendChild(link);
          link.click();
          setTimeout(() => NProgress.done(), 500);
        })
        .catch(() => setTimeout(() => NProgress.done(), 500));
    },
    onExport(item) {
      const k = item && item.key;
      if (k === "pdf") this.exportListPdf();
      else if (k === "xlsx") this.exportCsv();
    },
    exportCsv() {
      const head = ["Fecha", "Referencia", "Almacén", "Productos"];
      const lines = [head.join(",")].concat(
        (this.damages || []).map(a =>
          [a.date, a.Ref, a.warehouse_name, a.items].map(c => `"${String(c == null ? "" : c).replace(/"/g, '""')}"`).join(",")
        )
      );
      const blob = new Blob(["﻿" + lines.join("\n")], { type: "text/csv;charset=utf-8;" });
      const link = document.createElement("a");
      link.href = URL.createObjectURL(blob);
      link.setAttribute("download", "Danos.csv");
      document.body.appendChild(link);
      link.click();
      document.body.removeChild(link);
    },
    exportListPdf() {
      const self = this;
      const pdf = new jsPDF("p", "pt");
      const fontPath = "/fonts/Vazirmatn-Bold.ttf";
      try {
        pdf.addFont(fontPath, "Vazirmatn", "normal");
        pdf.addFont(fontPath, "Vazirmatn", "bold");
      } catch (e) { /* ya añadida */ }
      pdf.setFont("Vazirmatn", "normal");
      const headers = ["Fecha", "Referencia", "Almacén", "Productos"];
      const body = (self.damages || []).map(a => [a.date, a.Ref, a.warehouse_name, a.items]);
      const marginX = 40;
      autoTable(pdf, {
        head: [headers],
        body: body,
        startY: 110,
        theme: "striped",
        margin: { left: marginX, right: marginX },
        styles: { font: "Vazirmatn", fontSize: 9, cellPadding: 4, halign: "left", textColor: 33 },
        headStyles: { font: "Vazirmatn", fontStyle: "bold", fillColor: [63, 81, 181], textColor: 255 },
        alternateRowStyles: { fillColor: [245, 247, 250] },
        didDrawPage: d => {
          const pageW = pdf.internal.pageSize.getWidth();
          const pageH = pdf.internal.pageSize.getHeight();
          pdf.setFillColor(63, 81, 181);
          pdf.rect(0, 0, pageW, 60, "F");
          pdf.setTextColor(255);
          pdf.setFont("Vazirmatn", "bold");
          pdf.setFontSize(16);
          pdf.text("Lista de daños", marginX, 38);
          pdf.setTextColor(33);
          pdf.setFontSize(8);
          pdf.text(`${d.pageNumber} / ${pdf.internal.getNumberOfPages()}`, pageW - marginX, pageH - 14, { align: "right" });
        }
      });
      pdf.save("Danos_Lista.pdf");
    },
    makeToast(variant, msg, title) {
      this.$root.$bvToast.toast(msg, { title, variant, solid: true });
    }
  }
};
</script>

<style lang="scss" src="@/assets/styles/sass/px-next/production.scss"></style>

<style lang="scss" scoped>
.pxdmg { min-height: 100%; background: var(--pxn-bg); padding: var(--pxn-space-8) var(--pxn-space-9) var(--pxn-space-9); }
@media (max-width: 620px) { .pxdmg { padding: var(--pxn-space-6) var(--pxn-space-5); } }
.pxdmg__denied { padding: var(--pxn-space-12) 0; }
.pxdmg__pad { padding: var(--pxn-space-6) 0; }
.pxdmg__alert { margin-top: var(--pxn-space-5); }

.pxdmg__filters {
  margin-top: var(--pxn-space-4);
  padding: var(--pxn-space-5);
  border: 1px solid var(--pxn-border);
  border-radius: var(--pxn-radius-lg);
  background: var(--pxn-surface);
}
.pxdmg__filters-grid { display: grid; grid-template-columns: repeat(3, minmax(0, 1fr)); gap: var(--pxn-space-5); }
@media (max-width: 720px) { .pxdmg__filters-grid { grid-template-columns: minmax(0, 1fr); } }
.pxdmg__filters-act { display: flex; gap: var(--pxn-space-3); margin-top: var(--pxn-space-4); }

.pxdmg__tablewrap { margin-top: var(--pxn-space-5); transition: opacity var(--pxn-dur-1) var(--pxn-ease); }
.pxdmg__tablewrap.is-busy { opacity: 0.55; pointer-events: none; }

.pxdmg__confirm { margin: 0; font-size: var(--pxn-fs-body); color: var(--pxn-ink-2); line-height: var(--pxn-lh-snug); }
.pxdmg__grow { flex: 1; }

.pxdmg__detail-loading { padding: var(--pxn-space-6); text-align: center; color: var(--pxn-ink-3); }
.pxdmg__detail-dl { display: grid; grid-template-columns: repeat(3, minmax(0, 1fr)); gap: var(--pxn-space-4); margin: 0 0 var(--pxn-space-5); }
@media (max-width: 560px) { .pxdmg__detail-dl { grid-template-columns: minmax(0, 1fr); } }
.pxdmg__detail-dl dt { font-size: var(--pxn-fs-xs); color: var(--pxn-ink-3); text-transform: uppercase; letter-spacing: 0.04em; }
.pxdmg__detail-dl dd { margin: var(--pxn-space-1) 0 0; font-size: var(--pxn-fs-sm); color: var(--pxn-ink); }
.pxdmg-dtbl__wrap { border: 1px solid var(--pxn-border); border-radius: var(--pxn-radius-md); overflow-x: auto; }
.pxdmg-dtbl { width: 100%; border-collapse: collapse; font-size: var(--pxn-fs-sm); }
.pxdmg-dtbl th {
  text-align: left; padding: var(--pxn-space-3) var(--pxn-space-4);
  font-size: var(--pxn-fs-xs); font-weight: var(--pxn-fw-semibold);
  text-transform: uppercase; letter-spacing: 0.04em; color: var(--pxn-ink-3);
  background: var(--pxn-surface-2); border-bottom: 1px solid var(--pxn-border); white-space: nowrap;
}
.pxdmg-dtbl td { padding: var(--pxn-space-3) var(--pxn-space-4); border-bottom: 1px solid var(--pxn-border); }
.pxdmg-dtbl tr:last-child td { border-bottom: 0; }
.pxdmg-dtbl .is-right { text-align: right; }
.pxdmg-dtbl__empty { text-align: center; color: var(--pxn-ink-3); }
.pxdmg__detail-note {
  margin: var(--pxn-space-5) 0 0; padding: var(--pxn-space-4);
  background: var(--pxn-surface-2); border-radius: var(--pxn-radius-md);
  font-size: var(--pxn-fs-sm); color: var(--pxn-ink-2); white-space: pre-wrap;
}
</style>
