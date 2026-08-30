<template>
  <div class="px-next pxsn">
    <!--
      C3.6 — Listado de números de serie / IMEI px-next. Ruta real
      /app/serial_numbers/list (name index_serial_numbers). Conserva endpoint
      (GET serial_numbers), búsqueda, filtros (almacén + estado), orden,
      paginación y el permiso `serial_numbers`. Español-first SOLO en la
      presentación de los estados técnicos; el valor raw viaja intacto.
      No toca los flujos de venta/compra que asignan seriales.
    -->
    <div v-if="!can('serial_numbers')" class="pxsn__denied">
      <px-empty-state icon="lock" title="No tienes permiso para ver números de serie"
        description="Pide a un administrador el permiso «serial_numbers»." />
    </div>

    <template v-else>
      <px-page-header title="Números de serie / IMEI" :breadcrumbs="[{ label: 'Inventario' }, { label: 'Números de serie' }]" />

      <px-toolbar
        :search="search"
        search-placeholder="Buscar por nº de serie, producto…"
        :filter-count="activeFilterCount"
        @update:search="onSearchInput"
        @open-filters="filtersOpen = !filtersOpen"
      />

      <div v-if="filtersOpen" class="pxsn__filters">
        <div class="pxsn__filters-grid">
          <px-field label="Almacén">
            <template #default="{ id }">
              <vs-px :input-id="id" v-model="warehouse_id" :reduce="o => o.value" placeholder="Todos los almacenes"
                :options="warehouses.map(w => ({ label: w.name, value: w.id }))" @input="applyFilters" />
            </template>
          </px-field>
          <px-field label="Estado">
            <template #default="{ id }">
              <px-select :id="id" :value="status" :options="statusFilterOptions" @input="v => { status = v; applyFilters(); }" />
            </template>
          </px-field>
        </div>
        <div class="pxsn__filters-act">
          <px-button size="sm" variant="ghost" icon="x" @click="resetFilters">Limpiar</px-button>
        </div>
      </div>

      <px-alert v-if="error" tone="danger" title="No se pudo cargar el listado" class="pxsn__alert">
        {{ error }}
        <template #actions><px-button size="sm" variant="secondary" @click="fetch()">Reintentar</px-button></template>
      </px-alert>

      <div v-if="initialLoading" class="pxsn__pad">
        <px-skeleton variant="table" :rows="10" :columns="7" />
      </div>

      <template v-else>
        <div class="pxsn__tablewrap" :class="{ 'is-busy': refreshing }">
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
            <template #cell-serial_number="{ row }"><span class="pxn-mono">{{ row.serial_number }}</span></template>
            <template #cell-status="{ row }">
              <px-badge :tone="statusTone(row.status)">{{ statusLabel(row.status) }}</px-badge>
            </template>
            <template #cell-provider_name="{ row }">{{ row.provider_name || '—' }}</template>
            <template #cell-client_name="{ row }">{{ row.client_name || '—' }}</template>
            <template #row-actions="{ row }">
              <px-button size="sm" variant="secondary" icon="history" @click="goDetail(row)">Historial</px-button>
            </template>
          </px-table>

          <px-empty-state v-else icon="scan-barcode" title="Sin números de serie"
            description="No hay seriales que coincidan con los filtros." />
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
import PxPageHeader from "@/components/px-next/PxPageHeader.vue";
import PxToolbar from "@/components/px-next/PxToolbar.vue";
import PxTable from "@/components/px-next/PxTable.vue";
import PxPagination from "@/components/px-next/PxPagination.vue";
import PxButton from "@/components/px-next/PxButton.vue";
import PxField from "@/components/px-next/PxField.vue";
import PxSelect from "@/components/px-next/PxSelect.vue";
import PxBadge from "@/components/px-next/PxBadge.vue";
import PxAlert from "@/components/px-next/PxAlert.vue";
import PxEmptyState from "@/components/px-next/PxEmptyState.vue";
import VsPx from "@/views/app/products/next/edit/VsPx.vue";
import { SERIAL_STATUS_LABELS, serialStatusTone } from "./status.js";

export default {
  name: "SerialNumberListNext",
  metaInfo: { title: "Números de serie" },
  components: {
    PxPageHeader, PxToolbar, PxTable, PxPagination, PxButton, PxField, PxSelect,
    PxBadge, PxAlert, PxEmptyState, "vs-px": VsPx
  },
  data() {
    return {
      initialLoading: true,
      refreshing: false,
      error: null,
      serials: [],
      warehouses: [],
      totalRows: "",
      page: 1,
      limit: "10",
      search: "",
      _searchTimer: null,
      sort: { field: "id", type: "desc" },
      filtersOpen: false,
      warehouse_id: "",
      status: ""
    };
  },
  computed: {
    ...mapGetters(["currentUserPermissions"]),
    columns() {
      return [
        { key: "serial_number", label: "Nº de serie / IMEI", sortable: true, strong: true },
        { key: "product_name", label: "Producto", sortable: false },
        { key: "warehouse_name", label: "Almacén", sortable: false },
        { key: "status", label: "Estado", sortable: true, width: "150px" },
        { key: "provider_name", label: "Proveedor", sortable: false },
        { key: "client_name", label: "Cliente", sortable: false }
      ];
    },
    rows() {
      return this.serials || [];
    },
    statusFilterOptions() {
      return [{ value: "", label: "Todos los estados" }].concat(
        Object.keys(SERIAL_STATUS_LABELS).map(k => ({ value: k, label: SERIAL_STATUS_LABELS[k] }))
      );
    },
    activeFilterCount() {
      return [this.warehouse_id, this.status].filter(v => v !== "" && v != null).length;
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
    statusLabel(s) {
      return SERIAL_STATUS_LABELS[s] || s;
    },
    statusTone(s) {
      return serialStatusTone(s);
    },
    goDetail(row) {
      this.$router.push({ name: "detail_serial_number", params: { id: row.id } });
    },
    onSearchInput(v) {
      this.search = v;
      if (this._searchTimer) clearTimeout(this._searchTimer);
      this._searchTimer = setTimeout(() => { this.page = 1; this.fetch(); }, 350);
    },
    onSort({ key, dir }) {
      this.sort = { field: key, type: dir };
      this.fetch();
    },
    onPage(p) { if (p !== this.page) { this.page = p; this.fetch(); } },
    onLimit(v) { this.limit = String(v); this.page = 1; this.fetch(); },
    applyFilters() { this.page = 1; this.fetch(); },
    resetFilters() {
      this.warehouse_id = ""; this.status = ""; this.search = "";
      this.page = 1; this.fetch();
    },
    fetch(initial) {
      if (initial) this.initialLoading = true; else this.refreshing = true;
      this.error = null;
      NProgress.start(); NProgress.set(0.1);
      window.axios
        .get("serial_numbers", {
          params: {
            page: this.page,
            SortField: this.sort.field,
            SortType: this.sort.type,
            search: this.search || "",
            status: this.status || "",
            warehouse_id: this.warehouse_id || "",
            limit: this.limit
          }
        })
        .then(response => {
          this.serials = response.data.serials || [];
          this.totalRows = response.data.totalRows;
          if (response.data.warehouses) this.warehouses = response.data.warehouses;
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
    }
  }
};
</script>

<style lang="scss" src="@/assets/styles/sass/px-next/production.scss"></style>

<style lang="scss" scoped>
.pxsn { min-height: 100%; background: var(--pxn-bg); padding: var(--pxn-space-8) var(--pxn-space-9) var(--pxn-space-9); }
@media (max-width: 620px) { .pxsn { padding: var(--pxn-space-6) var(--pxn-space-5); } }
.pxsn__denied { padding: var(--pxn-space-12) 0; }
.pxsn__pad { padding: var(--pxn-space-6) 0; }
.pxsn__alert { margin-top: var(--pxn-space-5); }
.pxsn__filters { margin-top: var(--pxn-space-4); padding: var(--pxn-space-5); border: 1px solid var(--pxn-border); border-radius: var(--pxn-radius-lg); background: var(--pxn-surface); }
.pxsn__filters-grid { display: grid; grid-template-columns: repeat(2, minmax(0, 1fr)); gap: var(--pxn-space-5); }
@media (max-width: 720px) { .pxsn__filters-grid { grid-template-columns: minmax(0, 1fr); } }
.pxsn__filters-act { display: flex; gap: var(--pxn-space-3); margin-top: var(--pxn-space-4); }
.pxsn__tablewrap { margin-top: var(--pxn-space-5); transition: opacity var(--pxn-dur-1) var(--pxn-ease); }
.pxsn__tablewrap.is-busy { opacity: 0.55; pointer-events: none; }
</style>
