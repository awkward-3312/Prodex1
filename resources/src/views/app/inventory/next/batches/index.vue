<template>
  <div class="px-next pxbat">
    <!--
      C3.5 — Lotes y vencimientos px-next. Ruta real: /app/products/Batches (name batches).
      Conserva: listado, filtros (almacén, estado, ventana de caducidad), paginación,
      edición de metadata (PUT product_batches/{id}), write-off
      (POST product_batches/{id}/writeoff), eliminación (DELETE product_batches/{id}),
      expiración/estado, producto, almacén, cantidades, y la DOBLE nomenclatura de
      permisos: ver = view_batches | batch_view; gestionar = manage_batches |
      batch_manage; dar de baja / eliminar = writeoff_batches | batch_writeoff.
      Español-first SOLO en la presentación del estado; el valor raw viaja intacto.
    -->
    <div v-if="!canView" class="pxbat__denied">
      <px-empty-state icon="lock" title="No tienes permiso para ver lotes"
        description="Pide a un administrador el permiso «view_batches» (o «batch_view»)." />
    </div>

    <template v-else>
      <px-page-header title="Lotes y vencimientos" :breadcrumbs="[{ label: 'Productos' }, { label: 'Lotes' }]" />

      <px-toolbar
        :search="search"
        search-placeholder="Buscar por producto, nº de lote…"
        :filter-count="activeFilterCount"
        @update:search="onSearchInput"
        @open-filters="filtersOpen = !filtersOpen"
      />

      <div v-if="filtersOpen" class="pxbat__filters">
        <div class="pxbat__filters-grid">
          <px-field label="Almacén">
            <template #default="{ id }">
              <vs-px :input-id="id" v-model="filters.warehouse_id" :reduce="o => o.value" placeholder="Todos"
                :options="warehouses.map(w => ({ label: w.name, value: w.id }))" @input="onFilterChange" />
            </template>
          </px-field>
          <px-field label="Estado">
            <template #default="{ id }">
              <px-select :id="id" :value="filters.status" :options="statusOptions" @input="v => { filters.status = v; onFilterChange(); }" />
            </template>
          </px-field>
          <px-field label="Ventana de caducidad">
            <template #default="{ id }">
              <px-select :id="id" :value="filters.expiry_window" :options="expiryOptions" @input="v => { filters.expiry_window = v; onFilterChange(); }" />
            </template>
          </px-field>
        </div>
        <p class="pxbat__filters-note">Días de aviso de vencimiento: <strong class="pxn-num">{{ expiryWarningDays }}</strong></p>
      </div>

      <px-alert v-if="error" tone="danger" title="No se pudo cargar el listado" class="pxbat__alert">
        {{ error }}
        <template #actions><px-button size="sm" variant="secondary" @click="fetch()">Reintentar</px-button></template>
      </px-alert>

      <div v-if="initialLoading" class="pxbat__pad">
        <px-skeleton variant="table" :rows="10" :columns="7" />
      </div>

      <template v-else>
        <div class="pxbat__tablewrap" :class="{ 'is-busy': refreshing }">
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
              <div class="pxbat-prod">
                <div class="pxbat-prod__main">
                  <strong>{{ row.product_name }}</strong>
                  <span v-if="row.product_code" class="pxbat-prod__code">[{{ row.product_code }}]</span>
                </div>
                <div v-if="row.generic_name" class="pxbat-prod__sub">
                  {{ row.generic_name }}<span v-if="row.strength"> · {{ row.strength }}</span><span v-if="row.dosage_form"> · {{ row.dosage_form }}</span>
                </div>
                <px-badge v-if="row.variant_name" tone="neutral">{{ row.variant_name }}</px-badge>
              </div>
            </template>

            <template #cell-expiry_date="{ row }">
              <template v-if="row.expiry_date">
                <div>{{ row.expiry_date }}</div>
                <small class="pxbat-exp" :class="{
                  'is-expired': row.expiry_bucket === 'expired',
                  'is-near': row.expiry_bucket === 'near',
                  'is-valid': row.expiry_bucket === 'valid'
                }">
                  <template v-if="row.expiry_bucket === 'expired'">Vencido ({{ Math.abs(row.days_to_expiry) }} d)</template>
                  <template v-else-if="row.expiry_bucket === 'near'">Vence en {{ row.days_to_expiry }} d</template>
                  <template v-else>{{ row.days_to_expiry }} d</template>
                </small>
              </template>
              <span v-else class="pxn-muted">—</span>
            </template>

            <template #cell-qty="{ row }"><span class="pxn-num">{{ formatNumber(row.qty) }}</span></template>
            <template #cell-unit_cost="{ row }">
              <span v-if="row.unit_cost !== null && row.unit_cost !== undefined" class="pxn-num">{{ formatNumber(row.unit_cost) }}</span>
              <span v-else class="pxn-muted">—</span>
            </template>

            <template #cell-status="{ row }">
              <px-badge :tone="statusTone(row.status)">{{ statusLabel(row.status) }}</px-badge>
            </template>

            <template #row-actions="{ row }">
              <px-kebab :items="rowActions(row)" @select="onRowAction(row, $event)" />
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

      <!-- ===== Editar lote ===== -->
      <px-modal v-model="editOpen" title="Editar lote" size="md">
        <validation-observer ref="editObs">
          <form class="pxbat-form" @submit.prevent="submitEdit">
            <px-field label="Producto">
              <template #default="{ id }"><px-input :id="id" :value="editing.product_name" disabled /></template>
            </px-field>
            <div class="pxbat-form__row2">
              <v-field name="Nº de lote" label="Nº de lote" required :rules="{ required: true, max: 100 }" v-slot="{ invalid, id }">
                <px-input :id="id" v-model="editing.batch_no" :invalid="invalid" />
              </v-field>
              <px-field label="Estado">
                <template #default="{ id }">
                  <px-select :id="id" :value="editing.status" :options="statusEditOptions" @input="v => editing.status = v" />
                </template>
              </px-field>
            </div>
            <div class="pxbat-form__row2">
              <px-field label="Caducidad">
                <template #default="{ id }"><px-input :id="id" type="date" v-model="editing.expiry_date" /></template>
              </px-field>
              <px-field label="Fabricación">
                <template #default="{ id }"><px-input :id="id" type="date" v-model="editing.mfg_date" /></template>
              </px-field>
            </div>
            <div class="pxbat-form__row2">
              <px-field label="Cantidad">
                <template #default="{ id }"><px-input :id="id" inputmode="decimal" :value="String(editing.qty == null ? '' : editing.qty)" @input="v => editing.qty = v" /></template>
              </px-field>
              <px-field label="Coste unitario">
                <template #default="{ id }"><px-input :id="id" inputmode="decimal" :value="String(editing.unit_cost == null ? '' : editing.unit_cost)" @input="v => editing.unit_cost = v" /></template>
              </px-field>
            </div>
            <p class="pxbat-form__hint">Ajustar la cantidad aquí corrige la metadata del lote; no genera un movimiento de inventario.</p>
            <px-field label="Nota">
              <template #default="{ id }"><px-textarea :id="id" v-model="editing.notes" :rows="2" /></template>
            </px-field>
          </form>
        </validation-observer>
        <template #footer="{ close }">
          <span class="pxbat__grow" />
          <px-button variant="secondary" :disabled="submitting" @click="close">Cancelar</px-button>
          <px-button variant="primary" icon="check" :loading="submitting" @click="submitEdit">Guardar</px-button>
        </template>
      </px-modal>

      <!-- ===== Dar de baja ===== -->
      <px-modal v-model="writeOffOpen" title="Dar de baja" size="md">
        <px-alert tone="warning" class="pxbat__woalert">
          Vas a dar de baja el lote <strong>{{ writingOff.batch_no }}</strong> de
          <strong>{{ writingOff.product_name }}</strong> ({{ formatNumber(writingOff.qty) }} unidades). Esta acción descuenta el stock del lote.
        </px-alert>
        <px-field label="Motivo">
          <template #default="{ id }"><px-textarea :id="id" v-model="writingOff.reason" :rows="3" /></template>
        </px-field>
        <template #footer="{ close }">
          <span class="pxbat__grow" />
          <px-button variant="secondary" :disabled="submitting" @click="close">Cancelar</px-button>
          <px-button variant="danger" icon="trash-2" :loading="submitting" @click="submitWriteOff">Dar de baja</px-button>
        </template>
      </px-modal>

      <!-- ===== Eliminar ===== -->
      <px-modal v-model="deleteOpen" title="Eliminar lote" size="sm">
        <p class="pxbat__confirm">¿Eliminar el lote <strong>{{ pendingDelete && pendingDelete.batch_no }}</strong>? Esta acción no se puede deshacer.</p>
        <template #footer="{ close }">
          <span class="pxbat__grow" />
          <px-button variant="secondary" :disabled="submitting" @click="close">Cancelar</px-button>
          <px-button variant="danger" icon="trash-2" :loading="submitting" @click="doDelete">Eliminar</px-button>
        </template>
      </px-modal>
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
import PxKebab from "@/components/px-next/PxKebab.vue";
import PxField from "@/components/px-next/PxField.vue";
import PxInput from "@/components/px-next/PxInput.vue";
import PxSelect from "@/components/px-next/PxSelect.vue";
import PxTextarea from "@/components/px-next/PxTextarea.vue";
import PxBadge from "@/components/px-next/PxBadge.vue";
import PxAlert from "@/components/px-next/PxAlert.vue";
import PxEmptyState from "@/components/px-next/PxEmptyState.vue";
import PxModal from "@/components/px-next/PxModal.vue";
import VField from "@/views/app/products/next/edit/VField.vue";
import VsPx from "@/views/app/products/next/edit/VsPx.vue";

// Presentación español-first del estado. El valor raw NO se toca.
const STATUS_LABELS = {
  active: "Activo",
  expired: "Vencido",
  quarantined: "En cuarentena",
  written_off: "Dado de baja"
};

export default {
  name: "BatchesNext",
  metaInfo: { title: "Lotes y vencimientos" },
  components: {
    PxPageHeader, PxToolbar, PxTable, PxPagination, PxButton, PxKebab,
    PxField, PxInput, PxSelect, PxTextarea, PxBadge, PxAlert, PxEmptyState, PxModal,
    "v-field": VField, "vs-px": VsPx
  },
  data() {
    return {
      initialLoading: true,
      refreshing: false,
      submitting: false,
      error: null,
      batches: [],
      warehouses: [],
      totalRows: 0,
      page: 1,
      limit: "10",
      search: "",
      _searchTimer: null,
      sort: { field: "expiry_date", type: "asc" },
      expiryWarningDays: 90,
      filtersOpen: false,
      filters: { warehouse_id: "", status: "all", expiry_window: "all" },
      editOpen: false,
      editing: {
        id: null, product_name: "", batch_no: "", expiry_date: "", mfg_date: "",
        qty: 0, unit_cost: null, status: "active", notes: ""
      },
      writeOffOpen: false,
      writingOff: { id: null, batch_no: "", product_name: "", qty: 0, reason: "" },
      deleteOpen: false,
      pendingDelete: null
    };
  },
  computed: {
    ...mapGetters(["currentUserPermissions"]),
    priceDecimals() {
      return getPriceDecimals({ store: this.$store });
    },
    canView() {
      const p = this.currentUserPermissions || [];
      return p.includes("view_batches") || p.includes("batch_view");
    },
    canManage() {
      const p = this.currentUserPermissions || [];
      return p.includes("manage_batches") || p.includes("batch_manage");
    },
    canWriteOff() {
      const p = this.currentUserPermissions || [];
      return p.includes("writeoff_batches") || p.includes("batch_writeoff");
    },
    statusOptions() {
      return [
        { value: "all", label: "Todos" },
        { value: "active", label: "Activo" },
        { value: "quarantined", label: "En cuarentena" },
        { value: "expired", label: "Vencido" },
        { value: "written_off", label: "Dado de baja" }
      ];
    },
    statusEditOptions() {
      return [
        { value: "active", label: "Activo" },
        { value: "quarantined", label: "En cuarentena" },
        { value: "expired", label: "Vencido" },
        { value: "written_off", label: "Dado de baja" }
      ];
    },
    expiryOptions() {
      return [
        { value: "all", label: "Todos" },
        { value: "expired", label: "Vencidos" },
        { value: "near", label: "Próximos a vencer" },
        { value: "valid", label: "Vigentes" }
      ];
    },
    activeFilterCount() {
      let n = 0;
      if (this.filters.warehouse_id !== "" && this.filters.warehouse_id != null) n++;
      if (this.filters.status !== "all") n++;
      if (this.filters.expiry_window !== "all") n++;
      return n;
    },
    columns() {
      return [
        { key: "product", label: "Producto", sortable: false },
        { key: "batch_no", label: "Nº de lote", sortable: true, strong: true, width: "150px" },
        { key: "warehouse_name", label: "Almacén", sortable: false },
        { key: "expiry_date", label: "Caducidad", sortable: true, width: "150px" },
        { key: "qty", label: "Cantidad", align: "right", numeric: true, sortable: true, width: "110px" },
        { key: "unit_cost", label: "Coste unit.", align: "right", numeric: true, sortable: true, width: "110px" },
        { key: "status", label: "Estado", sortable: true, width: "130px" }
      ];
    },
    rows() {
      return this.batches || [];
    }
  },
  created() {
    this.fetch(true);
  },
  methods: {
    statusLabel(raw) {
      return STATUS_LABELS[raw] || raw;
    },
    statusTone(raw) {
      switch (raw) {
        case "active": return "success";
        case "quarantined": return "warning";
        case "expired": return "danger";
        case "written_off": return "neutral";
        default: return "neutral";
      }
    },
    formatNumber(v) {
      if (v === null || v === undefined || v === "") return "";
      const n = Number(v);
      if (Number.isNaN(n)) return v;
      return Number.isInteger(n) ? n.toString() : n.toFixed(this.priceDecimals);
    },
    makeToast(variant, msg, title) {
      this.$root.$bvToast.toast(msg, { title, variant, solid: true });
    },
    rowActions(row) {
      const items = [];
      if (this.canManage) items.push({ key: "edit", label: "Editar", icon: "pencil" });
      if (this.canWriteOff && row.status !== "written_off") items.push({ key: "writeoff", label: "Dar de baja", icon: "trash-2", tone: "danger" });
      if (this.canWriteOff) items.push({ key: "delete", label: "Eliminar", icon: "x", tone: "danger" });
      return items;
    },
    onRowAction(row, item) {
      const k = item && item.key;
      if (k === "edit") this.openEdit(row);
      else if (k === "writeoff") this.openWriteOff(row);
      else if (k === "delete") { this.pendingDelete = row; this.deleteOpen = true; }
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
        status: this.filters.status,
        expiry_window: this.filters.expiry_window
      };
      if (this.filters.warehouse_id !== "" && this.filters.warehouse_id != null) {
        params.warehouse_id = this.filters.warehouse_id;
      }
      window.axios
        .get("product_batches", { params })
        .then(response => {
          this.batches = response.data.batches || [];
          this.totalRows = response.data.totalRows || 0;
          this.warehouses = response.data.warehouses || this.warehouses;
          if (response.data.expiry_warning_days) this.expiryWarningDays = response.data.expiry_warning_days;
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
    openEdit(row) {
      this.editing = {
        id: row.id,
        product_name: row.product_name,
        batch_no: row.batch_no,
        expiry_date: row.expiry_date || "",
        mfg_date: row.mfg_date || "",
        qty: row.qty,
        unit_cost: row.unit_cost,
        status: row.status,
        notes: row.notes || ""
      };
      this.editOpen = true;
      this.$nextTick(() => { if (this.$refs.editObs) this.$refs.editObs.reset(); });
    },
    submitEdit() {
      // El observer vive dentro del modal (v-if + transición); si aún no montó,
      // validamos el requerido mínimo a mano para no perder el clic.
      const validate = this.$refs.editObs
        ? this.$refs.editObs.validate()
        : Promise.resolve(!!(this.editing.batch_no && String(this.editing.batch_no).length <= 100));
      validate.then(ok => {
        if (!ok) {
          this.makeToast("danger", "Completa el formulario correctamente.", "Error");
          return;
        }
        this.submitting = true;
        NProgress.start(); NProgress.set(0.1);
        const num = v => {
          if (v === "" || v == null) return null;
          const n = parseFloat(String(v).replace(",", "."));
          return Number.isFinite(n) ? n : null;
        };
        const payload = {
          batch_no: this.editing.batch_no,
          expiry_date: this.editing.expiry_date || null,
          mfg_date: this.editing.mfg_date || null,
          qty: num(this.editing.qty),
          unit_cost: num(this.editing.unit_cost),
          status: this.editing.status,
          notes: this.editing.notes
        };
        window.axios
          .put("product_batches/" + this.editing.id, payload)
          .then(() => {
            this.submitting = false;
            this.editOpen = false;
            NProgress.done();
            this.makeToast("success", "Lote actualizado.", "Éxito");
            this.fetch();
          })
          .catch(() => {
            this.submitting = false;
            NProgress.done();
            this.makeToast("danger", "No se pudo actualizar el lote.", "Error");
          });
      });
    },
    openWriteOff(row) {
      this.writingOff = { id: row.id, batch_no: row.batch_no, product_name: row.product_name, qty: row.qty, reason: "" };
      this.writeOffOpen = true;
    },
    submitWriteOff() {
      this.submitting = true;
      NProgress.start(); NProgress.set(0.1);
      window.axios
        .post("product_batches/" + this.writingOff.id + "/writeoff", { reason: this.writingOff.reason })
        .then(() => {
          this.submitting = false;
          this.writeOffOpen = false;
          NProgress.done();
          this.makeToast("success", "Lote dado de baja.", "Éxito");
          this.fetch();
        })
        .catch(() => {
          this.submitting = false;
          NProgress.done();
          this.makeToast("danger", "No se pudo dar de baja el lote.", "Error");
        });
    },
    doDelete() {
      const row = this.pendingDelete;
      if (!row) return;
      this.submitting = true;
      NProgress.start(); NProgress.set(0.1);
      window.axios
        .delete("product_batches/" + row.id)
        .then(() => {
          this.submitting = false;
          this.deleteOpen = false;
          this.pendingDelete = null;
          NProgress.done();
          this.makeToast("success", "Lote eliminado.", "Éxito");
          this.fetch();
        })
        .catch(() => {
          this.submitting = false;
          NProgress.done();
          this.makeToast("danger", "No se pudo eliminar el lote.", "Error");
        });
    }
  }
};
</script>

<style lang="scss" src="@/assets/styles/sass/px-next/production.scss"></style>

<style lang="scss" scoped>
.pxbat { min-height: 100%; background: var(--pxn-bg); padding: var(--pxn-space-8) var(--pxn-space-9) var(--pxn-space-9); }
@media (max-width: 620px) { .pxbat { padding: var(--pxn-space-6) var(--pxn-space-5); } }
.pxbat__denied { padding: var(--pxn-space-12) 0; }
.pxbat__pad { padding: var(--pxn-space-6) 0; }
.pxbat__alert { margin-top: var(--pxn-space-5); }

.pxbat__filters {
  margin-top: var(--pxn-space-4);
  padding: var(--pxn-space-5);
  border: 1px solid var(--pxn-border);
  border-radius: var(--pxn-radius-lg);
  background: var(--pxn-surface);
}
.pxbat__filters-grid { display: grid; grid-template-columns: repeat(3, minmax(0, 1fr)); gap: var(--pxn-space-5); }
@media (max-width: 720px) { .pxbat__filters-grid { grid-template-columns: minmax(0, 1fr); } }
.pxbat__filters-note { margin: var(--pxn-space-4) 0 0; font-size: var(--pxn-fs-sm); color: var(--pxn-ink-3); }

.pxbat__tablewrap { margin-top: var(--pxn-space-5); transition: opacity var(--pxn-dur-1) var(--pxn-ease); }
.pxbat__tablewrap.is-busy { opacity: 0.55; pointer-events: none; }

.pxbat-prod { display: flex; flex-direction: column; gap: 2px; white-space: normal; }
.pxbat-prod__main { display: flex; align-items: baseline; gap: var(--pxn-space-2); }
.pxbat-prod__code { font-size: var(--pxn-fs-xs); color: var(--pxn-ink-3); }
.pxbat-prod__sub { font-size: var(--pxn-fs-xs); color: var(--pxn-ink-3); }

.pxbat-exp { display: block; font-size: var(--pxn-fs-xs); }
.pxbat-exp.is-expired { color: var(--pxn-danger-ink); }
.pxbat-exp.is-near { color: var(--pxn-warning-ink); }
.pxbat-exp.is-valid { color: var(--pxn-success-ink); }

.pxbat__grow { flex: 1; }
.pxbat__confirm { margin: 0; font-size: var(--pxn-fs-body); color: var(--pxn-ink-2); line-height: var(--pxn-lh-snug); }
.pxbat__woalert { margin: 0 0 var(--pxn-space-4); }

.pxbat-form { display: flex; flex-direction: column; gap: var(--pxn-space-4); }
.pxbat-form__row2 { display: grid; grid-template-columns: repeat(2, minmax(0, 1fr)); gap: var(--pxn-space-4); }
@media (max-width: 560px) { .pxbat-form__row2 { grid-template-columns: minmax(0, 1fr); } }
.pxbat-form__hint { margin: 0; font-size: var(--pxn-fs-xs); color: var(--pxn-ink-3); }
</style>
