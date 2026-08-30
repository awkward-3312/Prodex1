<template>
  <div class="px-next pxrbr">
    <!--
      C3.17a — Registro global de lotes px-next (solo lectura). Ruta real
      /app/reports/batch_register_report (name batch_register_report). Endpoint
      GET report/batches/register sin cambios. Filtros: almacén, proveedor,
      ventana de caducidad, estado, rango de fecha de compra. El nº de lote y la
      acción «Historial» enlazan a batch_history_report (sin cambios de ruta).
      Estado español-first (Activo/Vencido/En cuarentena/Dado de baja) — solo
      presentación; valor raw intacto. Precisión monetaria backend conservada.
    -->
    <div v-if="!can('Batch_Register_Report')" class="pxrbr__denied">
      <px-empty-state icon="lock" title="No tienes permiso para este reporte"
        description="Pide a un administrador el permiso «Batch_Register_Report»." />
    </div>

    <template v-else>
      <px-page-header title="Registro de lotes" :breadcrumbs="[{ label: 'Informes' }, { label: 'Registro de lotes' }]">
        <template #actions>
          <px-button variant="secondary" size="sm" icon="printer" @click="doPrint">Imprimir</px-button>
        </template>
      </px-page-header>

      <px-toolbar
        :search="search"
        search-placeholder="Buscar por producto, nº de lote…"
        :filter-count="activeFilterCount"
        @update:search="onSearchInput"
        @open-filters="filtersOpen = !filtersOpen"
      />

      <div v-if="filtersOpen" class="pxrbr__filters">
        <div class="pxrbr__filters-grid">
          <px-field label="Almacén">
            <template #default="{ id }">
              <vs-px :input-id="id" v-model="filters.warehouse_id" :reduce="o => o.value" placeholder="Todos"
                :options="warehouses.map(w => ({ label: w.name, value: w.id }))" @input="onFilterChange" />
            </template>
          </px-field>
          <px-field label="Proveedor">
            <template #default="{ id }">
              <vs-px :input-id="id" v-model="filters.supplier_id" :reduce="o => o.value" placeholder="Todos"
                :options="suppliers.map(s => ({ label: s.name, value: s.id }))" @input="onFilterChange" />
            </template>
          </px-field>
          <px-field label="Ventana de caducidad">
            <template #default="{ id }">
              <px-select :id="id" :value="filters.expiry_window" :options="expiryOptions" @input="v => { filters.expiry_window = v; onFilterChange(); }" />
            </template>
          </px-field>
          <px-field label="Estado">
            <template #default="{ id }">
              <px-select :id="id" :value="filters.status" :options="statusOptions" @input="v => { filters.status = v; onFilterChange(); }" />
            </template>
          </px-field>
          <px-field label="Compra desde">
            <template #default="{ id }"><px-input :id="id" type="date" v-model="filters.purchase_date_from" @change="onFilterChange" /></template>
          </px-field>
          <px-field label="Compra hasta">
            <template #default="{ id }"><px-input :id="id" type="date" v-model="filters.purchase_date_to" @change="onFilterChange" /></template>
          </px-field>
        </div>
      </div>

      <px-alert v-if="error" tone="danger" title="No se pudo cargar el reporte" class="pxrbr__alert">
        {{ error }}
        <template #actions><px-button size="sm" variant="secondary" @click="fetch()">Reintentar</px-button></template>
      </px-alert>

      <div v-if="initialLoading" class="pxrbr__pad">
        <px-skeleton variant="table" :rows="10" :columns="8" />
      </div>

      <template v-else>
        <div class="pxrbr__tablewrap" :class="{ 'is-busy': refreshing }">
          <px-table
            v-if="rows.length"
            :columns="columns"
            :rows="rows"
            row-key="id"
            :sort-key="sort.field"
            :sort-dir="sort.type"
            has-row-actions
            @sort="onSort"
          >
            <template #cell-product="{ row }">
              <div class="pxrbr-prod">
                <div class="pxrbr-prod__main">
                  <strong>{{ row.product_name }}</strong>
                  <span v-if="row.product_code" class="pxrbr-prod__code">[{{ row.product_code }}]</span>
                </div>
                <div v-if="row.generic_name" class="pxrbr-prod__sub">
                  {{ row.generic_name }}<span v-if="row.strength"> · {{ row.strength }}</span><span v-if="row.dosage_form"> · {{ row.dosage_form }}</span>
                </div>
                <px-badge v-if="row.variant_name" tone="neutral">{{ row.variant_name }}</px-badge>
              </div>
            </template>
            <template #cell-batch_no="{ row }">
              <button type="button" class="pxrbr-link" @click="openHistory(row)">{{ row.batch_no || '—' }}</button>
            </template>
            <template #cell-expiry_date="{ row }">
              <template v-if="row.expiry_date">
                <div>{{ row.expiry_date }}</div>
                <small class="pxrbr-exp" :class="expiryClass(row.expiry_bucket)">
                  <template v-if="row.expiry_bucket === 'expired'">Vencido ({{ Math.abs(row.days_to_expiry) }} d)</template>
                  <template v-else-if="row.expiry_bucket === 'near'">Vence en {{ row.days_to_expiry }} d</template>
                  <template v-else>Vigente</template>
                </small>
              </template>
              <span v-else class="pxn-muted">—</span>
            </template>
            <template #cell-qty="{ row }"><span class="pxn-num">{{ fmtNum(row.qty) }}</span></template>
            <template #cell-unit_cost="{ row }">
              <span v-if="row.unit_cost !== null && row.unit_cost !== undefined" class="pxn-num">{{ money(row.unit_cost) }}</span>
              <span v-else class="pxn-muted">—</span>
            </template>
            <template #cell-value="{ row }">
              <span v-if="row.value !== null && row.value !== undefined" class="pxn-num">{{ money(row.value) }}</span>
              <span v-else class="pxn-muted">—</span>
            </template>
            <template #cell-status="{ row }">
              <px-badge :tone="statusTone(row.status)">{{ statusLabel(row.status) }}</px-badge>
            </template>
            <template #cell-origin_purchase_ref="{ row }">
              <router-link v-if="row.origin_purchase_id" class="pxrbr-link" :to="{ name: 'detail_purchase', params: { id: row.origin_purchase_id } }">
                {{ row.origin_purchase_ref }}
              </router-link>
              <span v-else class="pxn-muted">—</span>
            </template>
            <template #cell-origin_supplier_name="{ row }">{{ row.origin_supplier_name || '—' }}</template>
            <template #row-actions="{ row }">
              <px-button size="sm" variant="secondary" icon="file-text" @click="openHistory(row)">Historial</px-button>
            </template>
          </px-table>

          <px-empty-state v-else icon="package" title="Sin lotes"
            description="No hay lotes que coincidan con los filtros." />
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
    </template>
  </div>
</template>

<script>
import { mapGetters } from "vuex";
import NProgress from "nprogress";
import { getPriceDecimals } from "@/utils/priceFormat";
import PxPageHeader from "@/components/px-next/PxPageHeader.vue";
import PxToolbar from "@/components/px-next/PxToolbar.vue";
import PxTable from "@/components/px-next/PxTable.vue";
import PxPagination from "@/components/px-next/PxPagination.vue";
import PxButton from "@/components/px-next/PxButton.vue";
import PxField from "@/components/px-next/PxField.vue";
import PxInput from "@/components/px-next/PxInput.vue";
import PxSelect from "@/components/px-next/PxSelect.vue";
import PxBadge from "@/components/px-next/PxBadge.vue";
import PxAlert from "@/components/px-next/PxAlert.vue";
import PxEmptyState from "@/components/px-next/PxEmptyState.vue";
import VsPx from "@/views/app/products/next/edit/VsPx.vue";
import { printTableDoc } from "../reportUtils.js";
import { batchStatusLabel, batchStatusTone, expiryBucketTone } from "../batchStatus.js";

export default {
  name: "BatchRegisterReportNext",
  metaInfo: { title: "Registro de lotes" },
  components: {
    PxPageHeader, PxToolbar, PxTable, PxPagination, PxButton,
    PxField, PxInput, PxSelect, PxBadge, PxAlert, PxEmptyState, "vs-px": VsPx
  },
  data() {
    return {
      initialLoading: true,
      refreshing: false,
      error: null,
      report: [],
      totalRows: 0,
      page: 1,
      limit: "25",
      search: "",
      _searchTimer: null,
      sort: { field: "expiry_date", type: "asc" },
      filtersOpen: false,
      warehouses: [],
      suppliers: [],
      filters: {
        warehouse_id: "",
        supplier_id: "",
        expiry_window: "all",
        status: "",
        purchase_date_from: "",
        purchase_date_to: ""
      }
    };
  },
  computed: {
    ...mapGetters(["currentUser", "currentUserPermissions"]),
    priceDecimals() {
      return getPriceDecimals({ store: this.$store });
    },
    currency() {
      return (this.currentUser && this.currentUser.currency) || "";
    },
    expiryOptions() {
      return [
        { value: "all", label: "Todas" },
        { value: "expired", label: "Vencidas" },
        { value: "near", label: "Próximas a vencer" },
        { value: "valid", label: "Vigentes" },
        { value: "expired_or_near", label: "Vencidas + próximas" }
      ];
    },
    statusOptions() {
      return [
        { value: "", label: "Todos" },
        { value: "active", label: "Activo" },
        { value: "quarantined", label: "En cuarentena" },
        { value: "expired", label: "Vencido" },
        { value: "written_off", label: "Dado de baja" }
      ];
    },
    columns() {
      return [
        { key: "product", label: "Producto", sortable: false },
        { key: "batch_no", label: "Nº de lote", sortable: true, strong: true, width: "150px" },
        { key: "warehouse_name", label: "Almacén", sortable: false },
        { key: "mfg_date", label: "Fabricación", sortable: false, width: "130px" },
        { key: "expiry_date", label: "Caducidad", sortable: true, width: "150px" },
        { key: "qty", label: "Cantidad", align: "right", numeric: true, sortable: true, width: "110px" },
        { key: "unit_cost", label: "Coste unit.", align: "right", numeric: true, sortable: false, width: "120px" },
        { key: "value", label: "Valor", align: "right", numeric: true, sortable: false, width: "120px" },
        { key: "status", label: "Estado", sortable: true, width: "130px" },
        { key: "origin_purchase_ref", label: "Ref. compra", sortable: false, width: "130px" },
        { key: "origin_supplier_name", label: "Proveedor", sortable: false }
      ];
    },
    rows() {
      return this.report || [];
    },
    activeFilterCount() {
      let n = 0;
      if (this.filters.warehouse_id !== "" && this.filters.warehouse_id != null) n++;
      if (this.filters.supplier_id !== "" && this.filters.supplier_id != null) n++;
      if (this.filters.expiry_window !== "all") n++;
      if (this.filters.status !== "") n++;
      if (this.filters.purchase_date_from) n++;
      if (this.filters.purchase_date_to) n++;
      return n;
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
    statusLabel: batchStatusLabel,
    statusTone: batchStatusTone,
    expiryClass(bucket) {
      return "is-" + expiryBucketTone(bucket);
    },
    fmtNum(v) {
      if (v === null || v === undefined || v === "") return "";
      const n = Number(v);
      if (Number.isNaN(n)) return v;
      return Number.isInteger(n) ? n.toString() : n.toFixed(2);
    },
    money(v) {
      if (v === null || v === undefined || v === "") return "—";
      const n = Number(v);
      if (Number.isNaN(n)) return String(v);
      const s = n.toFixed(this.priceDecimals);
      return this.currency ? this.currency + " " + s : s;
    },
    openHistory(row) {
      this.$router.push({ name: "batch_history_report", params: { id: row.id } });
    },
    onSearchInput(v) {
      this.search = v;
      if (this._searchTimer) clearTimeout(this._searchTimer);
      this._searchTimer = setTimeout(() => { this.page = 1; this.fetch(); }, 350);
    },
    onSort({ key, dir }) { this.sort = { field: key, type: dir }; this.fetch(); },
    onPage(p) { if (p !== this.page) { this.page = p; this.fetch(); } },
    onLimit(v) { this.limit = String(v); this.page = 1; this.fetch(); },
    onFilterChange() { this.page = 1; this.fetch(); },
    fetch(initial) {
      if (initial) this.initialLoading = true; else this.refreshing = true;
      this.error = null;
      NProgress.start(); NProgress.set(0.1);
      const params = {
        page: this.page,
        limit: this.limit,
        SortField: this.sort.field,
        SortType: this.sort.type,
        search: this.search || "",
        expiry_window: this.filters.expiry_window
      };
      if (this.filters.warehouse_id !== "" && this.filters.warehouse_id != null) params.warehouse_id = this.filters.warehouse_id;
      if (this.filters.supplier_id !== "" && this.filters.supplier_id != null) params.supplier_id = this.filters.supplier_id;
      if (this.filters.status !== "") params.status = this.filters.status;
      if (this.filters.purchase_date_from) params.purchase_date_from = this.filters.purchase_date_from;
      if (this.filters.purchase_date_to) params.purchase_date_to = this.filters.purchase_date_to;
      window.axios
        .get("report/batches/register", { params })
        .then(response => {
          this.report = response.data.batches || [];
          this.totalRows = response.data.totalRows || 0;
          this.warehouses = response.data.warehouses || this.warehouses;
          this.suppliers = response.data.suppliers || this.suppliers;
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
    doPrint() {
      const headers = ["Producto", "Nº de lote", "Almacén", "Fabricación", "Caducidad", "Cantidad", "Coste unit.", "Valor", "Estado", "Ref. compra", "Proveedor"];
      const rows = (this.report || []).map(r => [
        (r.product_name || "") + (r.product_code ? " [" + r.product_code + "]" : ""),
        r.batch_no || "",
        r.warehouse_name || "",
        r.mfg_date || "—",
        r.expiry_date || "—",
        this.fmtNum(r.qty),
        r.unit_cost !== null && r.unit_cost !== undefined ? this.money(r.unit_cost) : "—",
        r.value !== null && r.value !== undefined ? this.money(r.value) : "—",
        this.statusLabel(r.status),
        r.origin_purchase_ref || "—",
        r.origin_supplier_name || "—"
      ]);
      const ok = printTableDoc({ title: "Informes / Registro de lotes", headers, rows, landscape: true });
      if (!ok) this.$root.$bvToast.toast("Permite las ventanas emergentes para imprimir.", { title: "Aviso", variant: "warning", solid: true });
    }
  }
};
</script>

<style lang="scss" src="@/assets/styles/sass/px-next/production.scss"></style>

<style lang="scss" scoped>
.pxrbr { min-height: 100%; background: var(--pxn-bg); padding: var(--pxn-space-8) var(--pxn-space-9) var(--pxn-space-9); }
@media (max-width: 620px) { .pxrbr { padding: var(--pxn-space-6) var(--pxn-space-5); } }
.pxrbr__denied { padding: var(--pxn-space-12) 0; }
.pxrbr__pad { padding: var(--pxn-space-6) 0; }
.pxrbr__alert { margin-top: var(--pxn-space-5); }
.pxrbr__filters { margin-top: var(--pxn-space-4); padding: var(--pxn-space-5); border: 1px solid var(--pxn-border); border-radius: var(--pxn-radius-lg); background: var(--pxn-surface); }
.pxrbr__filters-grid { display: grid; grid-template-columns: repeat(3, minmax(0, 1fr)); gap: var(--pxn-space-5); }
@media (max-width: 900px) { .pxrbr__filters-grid { grid-template-columns: repeat(2, minmax(0, 1fr)); } }
@media (max-width: 620px) { .pxrbr__filters-grid { grid-template-columns: minmax(0, 1fr); } }
.pxrbr__tablewrap { margin-top: var(--pxn-space-5); transition: opacity var(--pxn-dur-1) var(--pxn-ease); }
.pxrbr__tablewrap.is-busy { opacity: 0.55; pointer-events: none; }
.pxrbr-prod { display: flex; flex-direction: column; gap: 2px; white-space: normal; }
.pxrbr-prod__main { display: flex; align-items: baseline; gap: var(--pxn-space-2); }
.pxrbr-prod__code { font-size: var(--pxn-fs-xs); color: var(--pxn-ink-3); }
.pxrbr-prod__sub { font-size: var(--pxn-fs-xs); color: var(--pxn-ink-3); }
.pxrbr-exp { display: block; font-size: var(--pxn-fs-xs); }
.pxrbr-exp.is-danger { color: var(--pxn-danger-ink); }
.pxrbr-exp.is-warning { color: var(--pxn-warning-ink); }
.pxrbr-exp.is-success { color: var(--pxn-success-ink); }
.pxrbr-link {
  border: 0; background: none; padding: 0; font: inherit; cursor: pointer;
  color: var(--pxn-primary-ink); font-weight: var(--pxn-fw-medium); text-decoration: none;
}
.pxrbr-link:hover { text-decoration: underline; }
</style>
