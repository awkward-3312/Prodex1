<template>
  <div class="px-next pxadj">
    <!--
      C3.1 — Listado de ajustes de inventario px-next (preview dev-only).
      Ruta real: /app/adjustments/list (sigue legacy). Conserva endpoint, filtros,
      paginación/orden, borrado individual, PDF por fila, PDF de lista y Excel.
    -->
    <div v-if="!can('adjustment_view')" class="pxadj__denied">
      <px-empty-state icon="lock" title="No tienes permiso para ver ajustes"
        description="Pide a un administrador el permiso «adjustment_view»." />
    </div>

    <template v-else>
      <px-page-header title="Ajustes de inventario" :breadcrumbs="[{ label: 'Inventario' }, { label: 'Ajustes' }]">
        <template #actions>
          <px-menu :items="exportMenu" align="end" @select="onExport">
            <template #trigger>
              <px-button variant="secondary" size="sm" icon="file-spreadsheet" trailing-icon="chevron-down">Exportar</px-button>
            </template>
          </px-menu>
          <px-button v-if="can('adjustment_add')" variant="primary" icon="plus" @click="goCreate">Nuevo ajuste</px-button>
        </template>
      </px-page-header>

      <px-toolbar
        :search="search"
        search-placeholder="Buscar por referencia, almacén…"
        :filter-count="activeFilterCount"
        @update:search="onSearchInput"
        @open-filters="filtersOpen = !filtersOpen"
      />

      <div v-if="filtersOpen" class="pxadj__filters">
        <div class="pxadj__filters-grid">
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
        <div class="pxadj__filters-act">
          <px-button size="sm" variant="primary" icon="filter" @click="applyFilters">Filtrar</px-button>
          <px-button size="sm" variant="ghost" icon="x" @click="resetFilters">Limpiar</px-button>
        </div>
      </div>

      <px-alert v-if="error" tone="danger" title="No se pudo cargar el listado" class="pxadj__alert">
        {{ error }}
        <template #actions><px-button size="sm" variant="secondary" @click="fetch()">Reintentar</px-button></template>
      </px-alert>

      <div v-if="initialLoading" class="pxadj__pad">
        <px-skeleton variant="table" :rows="10" :columns="5" />
      </div>

      <template v-else>
        <div class="pxadj__tablewrap" :class="{ 'is-busy': refreshing }">
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

          <px-empty-state v-else icon="filter" title="Sin ajustes"
            description="No hay ajustes que coincidan con los filtros." />
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

      <px-modal v-model="confirmOpen" title="Eliminar ajuste" size="sm">
        <p class="pxadj__confirm">
          ¿Eliminar el ajuste <strong>{{ pendingDelete && pendingDelete.Ref }}</strong>? Se revertirán los movimientos de stock asociados.
        </p>
        <template #footer="{ close }">
          <span class="pxadj__grow" />
          <px-button variant="secondary" :disabled="deleting" @click="close">Cancelar</px-button>
          <px-button variant="danger" icon="trash-2" :loading="deleting" @click="doDelete">Eliminar</px-button>
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
  name: "AdjustmentListNext",
  metaInfo: { title: "Ajustes de inventario" },
  components: {
    PxPageHeader, PxToolbar, PxTable, PxPagination, PxButton, PxMenu, PxKebab,
    PxField, PxInput, PxAlert, PxEmptyState, PxModal, "vs-px": VsPx
  },
  data() {
    return {
      initialLoading: true,
      refreshing: false,
      error: null,
      adjustments: [],
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
      deleting: false
    };
  },
  computed: {
    ...mapGetters(["currentUserPermissions"]),
    columns() {
      return [
        { key: "date", label: "Fecha", sortable: true, width: "130px" },
        { key: "Ref", label: "Referencia", sortable: true, strong: true },
        { key: "warehouse_name", label: "Almacén", sortable: true },
        { key: "items", label: "Productos", align: "right", numeric: true, sortable: true, width: "120px" }
      ];
    },
    rows() {
      return this.adjustments || [];
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
    goCreate() {
      this.$router.push({ name: "store_adjustment" });
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
      else if (k === "view") this.$router.push({ name: "detail_adjustment", params: { id: row.id } });
      else if (k === "edit") this.$router.push({ name: "edit_adjustment", params: { id: row.id } });
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
        "adjustments?page=" + this.page +
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
          this.adjustments = response.data.adjustments || [];
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
    doDelete() {
      const row = this.pendingDelete;
      if (!row) return;
      this.deleting = true;
      NProgress.start(); NProgress.set(0.1);
      window.axios
        .delete("adjustments/" + row.id)
        .then(() => {
          this.deleting = false;
          this.confirmOpen = false;
          this.pendingDelete = null;
          this.makeToast("success", "Ajuste eliminado.", "Éxito");
          NProgress.done();
          this.fetch();
        })
        .catch(() => {
          this.deleting = false;
          NProgress.done();
          this.makeToast("danger", "No se pudo eliminar el ajuste.", "Error");
        });
    },
    downloadRowPdf(row) {
      NProgress.start(); NProgress.set(0.1);
      window.axios
        .get("adjustment_pdf/" + row.id, { responseType: "blob", headers: { "Content-Type": "application/json" } })
        .then(response => {
          const url = window.URL.createObjectURL(new Blob([response.data]));
          const link = document.createElement("a");
          link.href = url;
          link.setAttribute("download", "Adjustment-" + (row.Ref || row.id) + ".pdf");
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
        (this.adjustments || []).map(a =>
          [a.date, a.Ref, a.warehouse_name, a.items].map(c => `"${String(c == null ? "" : c).replace(/"/g, '""')}"`).join(",")
        )
      );
      const blob = new Blob(["﻿" + lines.join("\n")], { type: "text/csv;charset=utf-8;" });
      const link = document.createElement("a");
      link.href = URL.createObjectURL(blob);
      link.setAttribute("download", "Ajustes.csv");
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
      const body = (self.adjustments || []).map(a => [a.date, a.Ref, a.warehouse_name, a.items]);
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
          pdf.text("Lista de ajustes", marginX, 38);
          pdf.setTextColor(33);
          pdf.setFontSize(8);
          pdf.text(`${d.pageNumber} / ${pdf.internal.getNumberOfPages()}`, pageW - marginX, pageH - 14, { align: "right" });
        }
      });
      pdf.save("Ajustes_Lista.pdf");
    },
    makeToast(variant, msg, title) {
      this.$root.$bvToast.toast(msg, { title, variant, solid: true });
    }
  }
};
</script>

<style lang="scss" src="@/assets/styles/sass/px-next/production.scss"></style>

<style lang="scss" scoped>
.pxadj { min-height: 100%; background: var(--pxn-bg); padding: var(--pxn-space-8) var(--pxn-space-9) var(--pxn-space-9); }
@media (max-width: 620px) { .pxadj { padding: var(--pxn-space-6) var(--pxn-space-5); } }
.pxadj__denied { padding: var(--pxn-space-12) 0; }
.pxadj__pad { padding: var(--pxn-space-6) 0; }
.pxadj__alert { margin-top: var(--pxn-space-5); }

.pxadj__filters {
  margin-top: var(--pxn-space-4);
  padding: var(--pxn-space-5);
  border: 1px solid var(--pxn-border);
  border-radius: var(--pxn-radius-lg);
  background: var(--pxn-surface);
}
.pxadj__filters-grid { display: grid; grid-template-columns: repeat(3, minmax(0, 1fr)); gap: var(--pxn-space-5); }
@media (max-width: 720px) { .pxadj__filters-grid { grid-template-columns: minmax(0, 1fr); } }
.pxadj__filters-act { display: flex; gap: var(--pxn-space-3); margin-top: var(--pxn-space-4); }

.pxadj__tablewrap { margin-top: var(--pxn-space-5); transition: opacity var(--pxn-dur-1) var(--pxn-ease); }
.pxadj__tablewrap.is-busy { opacity: 0.55; pointer-events: none; }

.pxadj__confirm { margin: 0; font-size: var(--pxn-fs-body); color: var(--pxn-ink-2); line-height: var(--pxn-lh-snug); }
.pxadj__grow { flex: 1; }
</style>
