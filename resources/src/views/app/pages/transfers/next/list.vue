<template>
  <div class="px-next pxtrl">
    <!--
      C3.24 — Listado de traslados px-next (preview dev-only).
      Ruta real /app/transfers/list (sigue legacy). Conserva el endpoint
      GET transfers (FinalTransferController@index), filtros (Ref, origen,
      destino, estado), búsqueda, paginación/orden, PDF por fila
      (transfer_pdf/{id}), PDF de lista, CSV, borrado individual y por
      selección (transfers/delete/by_selection) y aprobación rápida
      (transfer-workflow/{id}/approve). Estados español-first sólo en
      presentación; valores crudos intactos. Permiso transfer_view; acciones
      condicionadas por transfer_edit / transfer_delete / transfer_add.
    -->
    <div v-if="!can('transfer_view')" class="pxtrl__denied">
      <px-empty-state icon="lock" title="No tienes permiso para ver traslados"
        description="Pide a un administrador el permiso «transfer_view»." />
    </div>

    <template v-else>
      <px-page-header title="Traslados" :breadcrumbs="[{ label: 'Inventario' }, { label: 'Traslados' }]">
        <template #actions>
          <px-menu :items="exportMenu" align="end" @select="onExport">
            <template #trigger>
              <px-button variant="secondary" size="sm" icon="file-spreadsheet" trailing-icon="chevron-down">Exportar</px-button>
            </template>
          </px-menu>
          <px-button
            v-if="can('transfer_receive')"
            variant="secondary" size="sm" icon="package-check"
            @click="$router.push({ name: 'transfer_receptions' })"
          >Recepciones</px-button>
          <px-button v-if="can('transfer_add')" variant="primary" icon="plus" @click="goCreate">Nuevo traslado</px-button>
        </template>
      </px-page-header>

      <px-toolbar
        :search="search"
        search-placeholder="Buscar por referencia, origen, destino…"
        :filter-count="activeFilterCount"
        @update:search="onSearchInput"
        @open-filters="filtersOpen = !filtersOpen"
      />

      <div v-if="filtersOpen" class="pxtrl__filters">
        <div class="pxtrl__filters-grid">
          <px-field label="Referencia">
            <template #default="{ id }"><px-input :id="id" v-model="fRef" placeholder="Referencia" @keyup.enter="applyFilters" /></template>
          </px-field>
          <px-field label="Origen">
            <template #default="{ id }">
              <vs-px :input-id="id" v-model="fFrom" :reduce="o => o.value" placeholder="Cualquier origen"
                :options="warehouses.map(w => ({ label: w.name, value: w.id }))" />
            </template>
          </px-field>
          <px-field label="Destino">
            <template #default="{ id }">
              <vs-px :input-id="id" v-model="fTo" :reduce="o => o.value" placeholder="Cualquier destino"
                :options="warehouses.map(w => ({ label: w.name, value: w.id }))" />
            </template>
          </px-field>
          <px-field label="Estado">
            <template #default="{ id }">
              <px-select :id="id" :value="fStatut" :options="statutOptions" @input="v => { fStatut = v; }" />
            </template>
          </px-field>
        </div>
        <div class="pxtrl__filters-act">
          <px-button size="sm" variant="primary" icon="filter" @click="applyFilters">Filtrar</px-button>
          <px-button size="sm" variant="ghost" icon="x" @click="resetFilters">Limpiar</px-button>
        </div>
      </div>

      <px-alert v-if="error" tone="danger" title="No se pudo cargar el listado" class="pxtrl__alert">
        {{ error }}
        <template #actions><px-button size="sm" variant="secondary" @click="fetch()">Reintentar</px-button></template>
      </px-alert>

      <div v-if="selectedIds.length" class="pxtrl__bulk">
        <span>{{ selectedIds.length }} seleccionado(s)</span>
        <px-button v-if="can('transfer_delete')" size="sm" variant="danger" icon="trash-2" @click="bulkConfirmOpen = true">
          Eliminar selección
        </px-button>
        <px-button size="sm" variant="ghost" icon="x" @click="selectedIds = []">Quitar selección</px-button>
      </div>

      <div v-if="initialLoading" class="pxtrl__pad">
        <px-skeleton variant="table" :rows="10" :columns="7" />
      </div>

      <template v-else>
        <div class="pxtrl__tablewrap" :class="{ 'is-busy': refreshing }">
          <px-table
            v-if="rows.length"
            :columns="columns"
            :rows="rows"
            row-key="id"
            selectable
            :selected="selectedIds"
            :sort-key="sortColKey"
            :sort-dir="sort.type"
            has-row-actions
            @update:selected="selectedIds = $event"
            @sort="onSort"
          >
            <template #cell-Ref="{ row }">
              <a href="#" class="pxtrl__ref" @click.prevent="goDetail(row)">{{ row.Ref }}</a>
            </template>
            <template #cell-items="{ row }"><span class="pxn-num">{{ fmtQty(row.items) }}</span></template>
            <template #cell-GrandTotal="{ row }"><span class="pxn-num">{{ money(row.GrandTotal) }}</span></template>
            <template #cell-statut="{ row }">
              <px-badge :tone="statutTone(row.statut)">{{ statutLabel(row.statut) }}</px-badge>
            </template>
            <template #cell-approval_status="{ row }">
              <px-badge :tone="approvalTone(row.approval_status)">{{ approvalLabel(row.approval_status) }}</px-badge>
            </template>
            <template #cell-logistics_status="{ row }">
              <px-badge :tone="logisticsTone(row.logistics_status)">{{ logisticsLabel(row.logistics_status) }}</px-badge>
            </template>
            <template #row-actions="{ row }">
              <px-kebab :items="rowActions(row)" @select="onRowAction(row, $event)" />
            </template>
          </px-table>

          <px-empty-state v-else icon="filter" title="Sin traslados"
            description="No hay traslados que coincidan con los filtros." />
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

      <px-modal v-model="confirmOpen" title="Eliminar traslado" size="sm">
        <p class="pxtrl__confirm">
          ¿Eliminar el traslado <strong>{{ pendingDelete && pendingDelete.Ref }}</strong>? Si ya movió stock, el backend revertirá los movimientos asociados.
        </p>
        <template #footer="{ close }">
          <span class="pxtrl__grow" />
          <px-button variant="secondary" :disabled="deleting" @click="close">Cancelar</px-button>
          <px-button variant="danger" icon="trash-2" :loading="deleting" @click="doDelete">Eliminar</px-button>
        </template>
      </px-modal>

      <px-modal v-model="bulkConfirmOpen" title="Eliminar traslados" size="sm">
        <p class="pxtrl__confirm">
          ¿Eliminar {{ selectedIds.length }} traslado(s) seleccionado(s)?
        </p>
        <template #footer="{ close }">
          <span class="pxtrl__grow" />
          <px-button variant="secondary" :disabled="deleting" @click="close">Cancelar</px-button>
          <px-button variant="danger" icon="trash-2" :loading="deleting" @click="doBulkDelete">Eliminar</px-button>
        </template>
      </px-modal>

      <px-modal v-model="approveConfirmOpen" title="Aprobar traslado" size="sm">
        <p class="pxtrl__confirm">
          ¿Aprobar el traslado <strong>{{ pendingApprove && pendingApprove.Ref }}</strong>?
        </p>
        <template #footer="{ close }">
          <span class="pxtrl__grow" />
          <px-button variant="secondary" :disabled="approving" @click="close">Cancelar</px-button>
          <px-button variant="primary" icon="check" :loading="approving" @click="doApprove">Aprobar</px-button>
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
import PxSelect from "@/components/px-next/PxSelect.vue";
import PxBadge from "@/components/px-next/PxBadge.vue";
import PxAlert from "@/components/px-next/PxAlert.vue";
import PxEmptyState from "@/components/px-next/PxEmptyState.vue";
import PxModal from "@/components/px-next/PxModal.vue";
import VsPx from "@/views/app/products/next/edit/VsPx.vue";
import { getPriceDecimals, getPriceFormatSetting, formatPriceDisplay } from "@/utils/priceFormat";
import {
  statutLabel, approvalLabel, logisticsLabel,
  statutTone, approvalTone, logisticsTone
} from "./statusMaps.js";

export default {
  name: "TransferListNext",
  metaInfo: { title: "Traslados" },
  components: {
    PxPageHeader, PxToolbar, PxTable, PxPagination, PxButton, PxMenu, PxKebab,
    PxField, PxInput, PxSelect, PxBadge, PxAlert, PxEmptyState, PxModal, "vs-px": VsPx
  },
  data() {
    return {
      initialLoading: true,
      refreshing: false,
      error: null,
      transfers: [],
      warehouses: [],
      totalRows: 0,
      page: 1,
      limit: "10",
      search: "",
      _searchTimer: null,
      sort: { field: "id", type: "desc" },
      filtersOpen: false,
      fRef: "",
      fFrom: "",
      fTo: "",
      fStatut: "",
      selectedIds: [],
      confirmOpen: false,
      pendingDelete: null,
      bulkConfirmOpen: false,
      approveConfirmOpen: false,
      pendingApprove: null,
      approving: false,
      deleting: false
    };
  },
  computed: {
    ...mapGetters(["currentUserPermissions", "currentUser"]),
    columns() {
      return [
        { key: "date", label: "Fecha", sortable: true, width: "150px" },
        { key: "Ref", label: "Referencia", sortable: true, strong: true },
        { key: "from_warehouse", label: "Origen", sortable: true },
        { key: "to_warehouse", label: "Destino", sortable: true },
        { key: "items", label: "Productos", align: "right", numeric: true, sortable: true, width: "110px" },
        { key: "GrandTotal", label: "Total", align: "right", numeric: true, sortable: true, width: "130px" },
        { key: "statut", label: "Estado", sortable: true, width: "130px" },
        { key: "approval_status", label: "Aprobación", sortable: true, width: "170px" },
        { key: "logistics_status", label: "Logística", sortable: false, width: "170px" }
      ];
    },
    rows() {
      return this.transfers || [];
    },
    sortColKey() {
      if (this.sort.field === "from_warehouse_id") return "from_warehouse";
      if (this.sort.field === "to_warehouse_id") return "to_warehouse";
      return this.sort.field;
    },
    statutOptions() {
      return [
        { value: "", label: "Todos los estados" },
        { value: "completed", label: "Completado" },
        { value: "sent", label: "Enviado" },
        { value: "pending", label: "Pendiente" }
      ];
    },
    activeFilterCount() {
      return [this.fRef, this.fFrom, this.fTo, this.fStatut].filter(v => v !== "" && v != null).length;
    },
    exportMenu() {
      return [
        { key: "pdf", label: "PDF de la lista", icon: "file-text" },
        { key: "csv", label: "Excel (CSV)", icon: "file-spreadsheet" }
      ];
    }
  },
  created() {
    // Sin permiso de lectura: mostrar el estado "sin permiso" del propio
    // componente; no disparar la petición (evita que un 403 lleve la SPA a
    // not_authorize).
    if (this.can("transfer_view")) this.fetch(true);
  },
  methods: {
    can(p) {
      const list = Array.isArray(this.currentUserPermissions) ? this.currentUserPermissions : [];
      return list.includes(p);
    },
    statutLabel, approvalLabel, logisticsLabel, statutTone, approvalTone, logisticsTone,
    money(v) {
      const decimals = getPriceDecimals({ store: this.$store });
      const key = getPriceFormatSetting({ store: this.$store });
      const sym = (this.currentUser && this.currentUser.currency) || "";
      return (sym ? sym + " " : "") + formatPriceDisplay(Number(v) || 0, decimals, key);
    },
    fmtQty(v) {
      const n = Number(v);
      return Number.isFinite(n) ? String(n) : String(v == null ? "" : v);
    },
    goCreate() { this.$router.push({ name: "store_transfer" }); },
    goDetail(row) { this.$router.push({ name: "detail_transfer", params: { id: row.id } }); },
    rowActions(row) {
      const items = [
        { key: "pdf", label: "Descargar PDF", icon: "file-text" },
        { key: "view", label: "Ver detalle", icon: "eye" }
      ];
      if (this.can("transfer_edit")) items.push({ key: "edit", label: "Editar", icon: "pencil" });
      if (row.approval_status === "pending" && this.can("transfer_edit")) {
        items.push({ key: "approve", label: "Aprobar", icon: "check" });
      }
      if (this.can("transfer_delete")) items.push({ key: "delete", label: "Eliminar", icon: "trash-2", tone: "danger" });
      return items;
    },
    onRowAction(row, item) {
      const k = item && item.key;
      if (k === "pdf") this.downloadRowPdf(row);
      else if (k === "view") this.goDetail(row);
      else if (k === "edit") this.$router.push({ name: "edit_transfer", params: { id: row.id } });
      else if (k === "approve") { this.pendingApprove = row; this.approveConfirmOpen = true; }
      else if (k === "delete") { this.pendingDelete = row; this.confirmOpen = true; }
    },
    onSearchInput(v) {
      this.search = v;
      if (this._searchTimer) clearTimeout(this._searchTimer);
      this._searchTimer = setTimeout(() => { this.page = 1; this.fetch(); }, 350);
    },
    onSort({ key, dir }) {
      let field = key;
      if (key === "from_warehouse") field = "from_warehouse_id";
      else if (key === "to_warehouse") field = "to_warehouse_id";
      this.sort = { field, type: dir };
      this.fetch();
    },
    onPage(p) { if (p !== this.page) { this.page = p; this.fetch(); } },
    onLimit(v) { this.limit = String(v); this.page = 1; this.fetch(); },
    applyFilters() { this.page = 1; this.fetch(); },
    resetFilters() {
      this.search = ""; this.fRef = ""; this.fFrom = ""; this.fTo = ""; this.fStatut = "";
      this.page = 1; this.fetch();
    },
    fetch(initial) {
      if (initial) this.initialLoading = true; else this.refreshing = true;
      this.error = null;
      NProgress.start(); NProgress.set(0.1);
      const qs =
        "transfers?page=" + this.page +
        "&Ref=" + encodeURIComponent(this.fRef || "") +
        "&from_warehouse_id=" + encodeURIComponent(this.fFrom == null ? "" : this.fFrom) +
        "&to_warehouse_id=" + encodeURIComponent(this.fTo == null ? "" : this.fTo) +
        "&statut=" + encodeURIComponent(this.fStatut || "") +
        "&SortField=" + this.sort.field +
        "&SortType=" + this.sort.type +
        "&search=" + encodeURIComponent(this.search || "") +
        "&limit=" + this.limit;
      window.axios
        .get(qs, { meta: { skipErrorRedirect: true } })
        .then(response => {
          this.transfers = response.data.transfers || [];
          this.warehouses = response.data.warehouses || this.warehouses;
          this.totalRows = response.data.totalRows;
          this.selectedIds = this.selectedIds.filter(id => this.transfers.some(t => t.id === id));
          NProgress.done();
          this.initialLoading = false;
          this.refreshing = false;
        })
        .catch(err => {
          NProgress.done();
          this.error = this.errMsg(err);
          setTimeout(() => { this.initialLoading = false; this.refreshing = false; }, 300);
        });
    },
    errMsg(err) {
      return (
        (err && err.response && err.response.data && (err.response.data.message || err.response.data.error)) ||
        (err && err.message) || "Error de red."
      );
    },
    doApprove() {
      const row = this.pendingApprove;
      if (!row) return;
      this.approving = true;
      NProgress.start(); NProgress.set(0.1);
      window.axios
        .post("transfer-workflow/" + row.id + "/approve", {})
        .then(() => {
          this.approving = false;
          this.approveConfirmOpen = false;
          this.pendingApprove = null;
          this.makeToast("success", "Traslado aprobado.", "Éxito");
          NProgress.done();
          this.fetch();
        })
        .catch(err => {
          this.approving = false;
          NProgress.done();
          this.makeToast("danger", this.errMsg(err), "Error");
        });
    },
    doDelete() {
      const row = this.pendingDelete;
      if (!row) return;
      this.deleting = true;
      NProgress.start(); NProgress.set(0.1);
      window.axios
        .delete("transfers/" + row.id)
        .then(() => {
          this.deleting = false;
          this.confirmOpen = false;
          this.pendingDelete = null;
          this.makeToast("success", "Traslado eliminado.", "Éxito");
          NProgress.done();
          this.fetch();
        })
        .catch(err => {
          this.deleting = false;
          NProgress.done();
          this.makeToast("danger", this.errMsg(err), "Error");
        });
    },
    doBulkDelete() {
      if (!this.selectedIds.length) return;
      this.deleting = true;
      NProgress.start(); NProgress.set(0.1);
      window.axios
        .post("transfers/delete/by_selection", { selectedIds: this.selectedIds })
        .then(() => {
          this.deleting = false;
          this.bulkConfirmOpen = false;
          this.selectedIds = [];
          this.makeToast("success", "Traslados eliminados.", "Éxito");
          NProgress.done();
          this.fetch();
        })
        .catch(err => {
          this.deleting = false;
          NProgress.done();
          this.makeToast("danger", this.errMsg(err), "Error");
        });
    },
    downloadRowPdf(row) {
      NProgress.start(); NProgress.set(0.1);
      window.axios
        .get("transfer_pdf/" + row.id, { responseType: "blob", headers: { "Content-Type": "application/json" }, meta: { skipErrorRedirect: true } })
        .then(response => {
          const url = window.URL.createObjectURL(new Blob([response.data]));
          const link = document.createElement("a");
          link.href = url;
          link.setAttribute("download", "Transfer-" + (row.Ref || row.id) + ".pdf");
          document.body.appendChild(link);
          link.click();
          document.body.removeChild(link);
          setTimeout(() => NProgress.done(), 500);
        })
        .catch(() => { setTimeout(() => NProgress.done(), 500); this.makeToast("danger", "No se pudo descargar el PDF.", "Error"); });
    },
    onExport(item) {
      const k = item && item.key;
      if (k === "pdf") this.exportListPdf();
      else if (k === "csv") this.exportCsv();
    },
    exportRows() {
      return (this.transfers || []).map(t => [
        t.date, t.Ref, t.from_warehouse, t.to_warehouse, this.fmtQty(t.items),
        this.money(t.GrandTotal), this.statutLabel(t.statut), this.approvalLabel(t.approval_status),
        this.logisticsLabel(t.logistics_status)
      ]);
    },
    exportCsv() {
      const head = ["Fecha", "Referencia", "Origen", "Destino", "Productos", "Total", "Estado", "Aprobación", "Logística"];
      const lines = [head.join(",")].concat(
        this.exportRows().map(r => r.map(c => `"${String(c == null ? "" : c).replace(/"/g, '""')}"`).join(","))
      );
      const blob = new Blob(["﻿" + lines.join("\n")], { type: "text/csv;charset=utf-8;" });
      const link = document.createElement("a");
      link.href = URL.createObjectURL(blob);
      link.setAttribute("download", "Traslados.csv");
      document.body.appendChild(link);
      link.click();
      document.body.removeChild(link);
    },
    exportListPdf() {
      const pdf = new jsPDF("l", "pt");
      const fontPath = "/fonts/Vazirmatn-Bold.ttf";
      try {
        pdf.addFont(fontPath, "Vazirmatn", "normal");
        pdf.addFont(fontPath, "Vazirmatn", "bold");
      } catch (e) { /* ya añadida */ }
      pdf.setFont("Vazirmatn", "normal");
      const headers = ["Fecha", "Referencia", "Origen", "Destino", "Productos", "Total", "Estado", "Aprobación", "Logística"];
      const marginX = 40;
      autoTable(pdf, {
        head: [headers],
        body: this.exportRows(),
        startY: 90,
        theme: "striped",
        margin: { left: marginX, right: marginX },
        styles: { font: "Vazirmatn", fontSize: 8, cellPadding: 4, halign: "left", textColor: 33 },
        headStyles: { font: "Vazirmatn", fontStyle: "bold", fillColor: [63, 81, 181], textColor: 255 },
        alternateRowStyles: { fillColor: [245, 247, 250] },
        didDrawPage: d => {
          const pageW = pdf.internal.pageSize.getWidth();
          const pageH = pdf.internal.pageSize.getHeight();
          pdf.setFillColor(63, 81, 181);
          pdf.rect(0, 0, pageW, 54, "F");
          pdf.setTextColor(255);
          pdf.setFont("Vazirmatn", "bold");
          pdf.setFontSize(15);
          pdf.text("Lista de traslados", marginX, 34);
          pdf.setTextColor(33);
          pdf.setFontSize(8);
          pdf.text(`${d.pageNumber} / ${pdf.internal.getNumberOfPages()}`, pageW - marginX, pageH - 14, { align: "right" });
        }
      });
      pdf.save("Traslados_Lista.pdf");
    },
    makeToast(variant, msg, title) {
      this.$root.$bvToast.toast(msg, { title, variant, solid: true });
    }
  }
};
</script>

<style lang="scss" src="@/assets/styles/sass/px-next/production.scss"></style>

<style lang="scss" scoped>
.pxtrl { min-height: 100%; background: var(--pxn-bg); padding: var(--pxn-space-8) var(--pxn-space-9) var(--pxn-space-9); }
@media (max-width: 620px) { .pxtrl { padding: var(--pxn-space-6) var(--pxn-space-5); } }
.pxtrl__denied { padding: var(--pxn-space-12) 0; }
.pxtrl__pad { padding: var(--pxn-space-6) 0; }
.pxtrl__alert { margin-top: var(--pxn-space-5); }

.pxtrl__filters {
  margin-top: var(--pxn-space-4);
  padding: var(--pxn-space-5);
  border: 1px solid var(--pxn-border);
  border-radius: var(--pxn-radius-lg);
  background: var(--pxn-surface);
}
.pxtrl__filters-grid { display: grid; grid-template-columns: repeat(4, minmax(0, 1fr)); gap: var(--pxn-space-5); }
@media (max-width: 900px) { .pxtrl__filters-grid { grid-template-columns: repeat(2, minmax(0, 1fr)); } }
@media (max-width: 560px) { .pxtrl__filters-grid { grid-template-columns: minmax(0, 1fr); } }
.pxtrl__filters-act { display: flex; gap: var(--pxn-space-3); margin-top: var(--pxn-space-4); }

.pxtrl__bulk {
  display: flex; align-items: center; gap: var(--pxn-space-3);
  margin-top: var(--pxn-space-4); padding: var(--pxn-space-3) var(--pxn-space-4);
  border: 1px solid var(--pxn-border); border-radius: var(--pxn-radius-md);
  background: var(--pxn-surface-2); font-size: var(--pxn-fs-sm); color: var(--pxn-ink-2);
}

.pxtrl__tablewrap { margin-top: var(--pxn-space-5); transition: opacity var(--pxn-dur-1) var(--pxn-ease); }
.pxtrl__tablewrap.is-busy { opacity: 0.55; pointer-events: none; }
.pxtrl__ref { color: var(--pxn-primary-ink); font-weight: var(--pxn-fw-semibold); text-decoration: none; }
.pxtrl__ref:hover { text-decoration: underline; }

.pxtrl__confirm { margin: 0; font-size: var(--pxn-fs-body); color: var(--pxn-ink-2); line-height: var(--pxn-lh-snug); }
.pxtrl__grow { flex: 1; }
</style>
