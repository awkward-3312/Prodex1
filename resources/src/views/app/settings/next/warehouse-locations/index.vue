<template>
  <div class="px-next pxwl">
    <!--
      C3.8 — Ubicaciones internas (rack / location) px-next. Ruta real
      /app/settings/Warehouse_Locations (name Warehouse_Locations). CRUD contra
      los endpoints actuales (GET/POST/PUT/DELETE warehouse_locations); filtro
      por almacén; soporta ?warehouse_id= de prefiltro. No cambia el endpoint
      warehouse_locations/by_warehouse/{id} que consumen los selects de Productos.
    -->
    <div v-if="!can('warehouse_locations')" class="pxwl__denied">
      <px-empty-state icon="lock" title="No tienes permiso para gestionar ubicaciones internas"
        description="Pide a un administrador el permiso «warehouse_locations»." />
    </div>

    <template v-else>
      <px-page-header title="Ubicaciones internas"
        :breadcrumbs="[{ label: 'Configuración' }, { label: 'Ubicaciones internas' }]">
        <template #actions>
          <px-button variant="primary" icon="plus" @click="openNew">Nueva ubicación</px-button>
        </template>
      </px-page-header>

      <px-toolbar
        :search="search"
        search-placeholder="Buscar por código, nombre…"
        :filter-count="filters.warehouse_id ? 1 : 0"
        @update:search="onSearchInput"
        @open-filters="filtersOpen = !filtersOpen"
      />

      <div v-if="filtersOpen" class="pxwl__filters">
        <px-field label="Almacén">
          <template #default="{ id }">
            <vs-px :input-id="id" v-model="filters.warehouse_id" :reduce="o => o.value" placeholder="Todos los almacenes"
              :options="warehouses.map(w => ({ label: w.name, value: w.id }))" @input="onWarehouseFilterChange" />
          </template>
        </px-field>
      </div>

      <px-alert v-if="error" tone="danger" title="No se pudo cargar el listado" class="pxwl__alert">
        {{ error }}
        <template #actions><px-button size="sm" variant="secondary" @click="fetch()">Reintentar</px-button></template>
      </px-alert>

      <div v-if="initialLoading" class="pxwl__pad">
        <px-skeleton variant="table" :rows="8" :columns="5" />
      </div>

      <template v-else>
        <div class="pxwl__tablewrap" :class="{ 'is-busy': refreshing }">
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
            <template #cell-code="{ row }"><span class="pxn-mono">{{ row.code }}</span></template>
            <template #cell-name="{ row }">{{ row.name || '—' }}</template>
            <template #cell-is_active="{ row }">
              <px-badge :tone="row.is_active ? 'success' : 'neutral'">{{ row.is_active ? 'Activa' : 'Inactiva' }}</px-badge>
            </template>
            <template #row-actions="{ row }">
              <px-kebab :items="rowActions" @select="onRowAction(row, $event)" />
            </template>
          </px-table>

          <px-empty-state v-else icon="map-pin" title="Sin ubicaciones"
            description="No hay ubicaciones internas que coincidan con los filtros." />
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

      <px-modal v-model="modalOpen" :title="editmode ? 'Editar ubicación' : 'Nueva ubicación'" size="lg">
        <validation-observer ref="obs">
          <form class="pxwl-form" @submit.prevent="submit">
            <div class="pxwl-form__grid">
              <v-field name="Almacén" label="Almacén" required :rules="{ required: true }" v-slot="{ invalid, id }">
                <vs-px :input-id="id" :invalid="invalid" v-model="loc.warehouse_id" :reduce="o => o.value"
                  placeholder="Elegir almacén" :options="warehouses.map(w => ({ label: w.name, value: w.id }))" />
              </v-field>
              <v-field name="Código" label="Código de rack / ubicación" required :rules="{ required: true }" v-slot="{ invalid, id }">
                <px-input :id="id" v-model="loc.code" :invalid="invalid" placeholder="Ej. A-01-03" />
              </v-field>
              <px-field label="Nombre de la ubicación" class="pxwl-form__wide">
                <template #default="{ id }"><px-input :id="id" v-model="loc.name" placeholder="Descripción opcional" /></template>
              </px-field>
              <px-check type="switch" v-model="loc.is_active" class="pxwl-form__wide">Activa</px-check>
            </div>
          </form>
        </validation-observer>
        <template #footer="{ close }">
          <span class="pxwl__grow" />
          <px-button variant="secondary" :disabled="submitting" @click="close">Cancelar</px-button>
          <px-button variant="primary" icon="check" :loading="submitting" @click="submit">Guardar</px-button>
        </template>
      </px-modal>

      <px-modal v-model="confirmOpen" title="Eliminar ubicación" size="sm">
        <p class="pxwl__confirm">
          ¿Eliminar la ubicación <strong>{{ pendingDelete && pendingDelete.code }}</strong>? Esta acción no se puede deshacer.
        </p>
        <template #footer="{ close }">
          <span class="pxwl__grow" />
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
import PxPageHeader from "@/components/px-next/PxPageHeader.vue";
import PxToolbar from "@/components/px-next/PxToolbar.vue";
import PxTable from "@/components/px-next/PxTable.vue";
import PxPagination from "@/components/px-next/PxPagination.vue";
import PxButton from "@/components/px-next/PxButton.vue";
import PxKebab from "@/components/px-next/PxKebab.vue";
import PxField from "@/components/px-next/PxField.vue";
import PxInput from "@/components/px-next/PxInput.vue";
import PxCheck from "@/components/px-next/PxCheck.vue";
import PxBadge from "@/components/px-next/PxBadge.vue";
import PxAlert from "@/components/px-next/PxAlert.vue";
import PxEmptyState from "@/components/px-next/PxEmptyState.vue";
import PxModal from "@/components/px-next/PxModal.vue";
import VField from "@/views/app/products/next/edit/VField.vue";
import VsPx from "@/views/app/products/next/edit/VsPx.vue";

const emptyLoc = () => ({ id: "", warehouse_id: "", code: "", name: "", is_active: true });

export default {
  name: "WarehouseLocationsNext",
  metaInfo: { title: "Ubicaciones internas" },
  components: {
    PxPageHeader, PxToolbar, PxTable, PxPagination, PxButton, PxKebab,
    PxField, PxInput, PxCheck, PxBadge, PxAlert, PxEmptyState, PxModal,
    "v-field": VField, "vs-px": VsPx
  },
  data() {
    return {
      initialLoading: true,
      refreshing: false,
      submitting: false,
      deleting: false,
      error: null,
      locations: [],
      warehouses: [],
      totalRows: 0,
      page: 1,
      limit: "10",
      search: "",
      _searchTimer: null,
      sort: { field: "id", type: "desc" },
      filtersOpen: false,
      filters: { warehouse_id: "" },
      modalOpen: false,
      editmode: false,
      loc: emptyLoc(),
      confirmOpen: false,
      pendingDelete: null
    };
  },
  computed: {
    ...mapGetters(["currentUserPermissions"]),
    columns() {
      return [
        { key: "warehouse", label: "Almacén", sortable: false },
        { key: "code", label: "Código", sortable: true, strong: true },
        { key: "name", label: "Nombre", sortable: true },
        { key: "is_active", label: "Estado", sortable: false, width: "120px" }
      ];
    },
    rows() {
      return this.locations || [];
    },
    rowActions() {
      return [
        { key: "edit", label: "Editar", icon: "pencil" },
        { key: "delete", label: "Eliminar", icon: "trash-2", tone: "danger" }
      ];
    }
  },
  created() {
    if (this.$route && this.$route.query && this.$route.query.warehouse_id) {
      this.filters.warehouse_id = this.$route.query.warehouse_id;
    }
    this.fetch(true);
  },
  methods: {
    can(p) {
      const list = Array.isArray(this.currentUserPermissions) ? this.currentUserPermissions : [];
      return list.includes(p);
    },
    makeToast(variant, msg, title) {
      this.$root.$bvToast.toast(msg, { title, variant, solid: true });
    },
    onSearchInput(v) {
      this.search = v;
      if (this._searchTimer) clearTimeout(this._searchTimer);
      this._searchTimer = setTimeout(() => { this.page = 1; this.fetch(); }, 350);
    },
    onSort({ key, dir }) { this.sort = { field: key, type: dir }; this.fetch(); },
    onPage(p) { if (p !== this.page) { this.page = p; this.fetch(); } },
    onLimit(v) { this.limit = String(v); this.page = 1; this.fetch(); },
    onWarehouseFilterChange() { this.page = 1; this.fetch(); },
    fetch(initial) {
      if (initial) this.initialLoading = true; else this.refreshing = true;
      this.error = null;
      NProgress.start(); NProgress.set(0.1);
      window.axios
        .get("warehouse_locations", {
          params: {
            page: this.page,
            limit: this.limit,
            SortField: this.sort.field,
            SortType: this.sort.type,
            search: this.search || "",
            warehouse_id: this.filters.warehouse_id || "",
            _t: Date.now()
          }
        })
        .then(response => {
          this.locations = response.data.locations || [];
          this.totalRows = response.data.totalRows || 0;
          this.warehouses = response.data.warehouses || this.warehouses;
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
    openNew() {
      this.loc = emptyLoc();
      this.loc.warehouse_id = this.filters.warehouse_id || (this.warehouses[0] ? this.warehouses[0].id : "");
      this.editmode = false;
      this.modalOpen = true;
      this.$nextTick(() => { if (this.$refs.obs) this.$refs.obs.reset(); });
    },
    onRowAction(row, item) {
      const k = item && item.key;
      if (k === "edit") {
        this.loc = { id: row.id, warehouse_id: row.warehouse_id, code: row.code, name: row.name, is_active: !!row.is_active };
        this.editmode = true;
        this.modalOpen = true;
        this.$nextTick(() => { if (this.$refs.obs) this.$refs.obs.reset(); });
      } else if (k === "delete") {
        this.pendingDelete = row;
        this.confirmOpen = true;
      }
    },
    submit() {
      const validate = this.$refs.obs
        ? this.$refs.obs.validate()
        : Promise.resolve(!!this.loc.warehouse_id && !!this.loc.code);
      validate.then(ok => {
        if (!ok) {
          this.makeToast("danger", "Completa los campos obligatorios.", "Error");
          return;
        }
        this.submitting = true;
        NProgress.start(); NProgress.set(0.1);
        const wasNew = !this.editmode;
        const newWid = this.loc.warehouse_id;
        const payload = {
          warehouse_id: this.loc.warehouse_id,
          code: this.loc.code,
          name: this.loc.name,
          is_active: this.loc.is_active
        };
        const req = this.editmode
          ? window.axios.put("warehouse_locations/" + this.loc.id, payload)
          : window.axios.post("warehouse_locations", payload);
        req
          .then(() => {
            this.submitting = false;
            this.modalOpen = false;
            NProgress.done();
            this.makeToast("success", wasNew ? "Ubicación creada." : "Ubicación actualizada.", "Éxito");
            if (wasNew) {
              this.page = 1;
              if (this.filters.warehouse_id && Number(this.filters.warehouse_id) !== Number(newWid)) {
                this.filters.warehouse_id = "";
              }
            }
            this.fetch();
          })
          .catch(() => {
            this.submitting = false;
            NProgress.done();
            this.makeToast("danger", "Datos inválidos.", "Error");
          });
      });
    },
    doDelete() {
      const row = this.pendingDelete;
      if (!row) return;
      this.deleting = true;
      NProgress.start(); NProgress.set(0.1);
      window.axios
        .delete("warehouse_locations/" + row.id)
        .then(() => {
          this.deleting = false;
          this.confirmOpen = false;
          this.pendingDelete = null;
          NProgress.done();
          this.makeToast("success", "Ubicación eliminada.", "Éxito");
          this.fetch();
        })
        .catch(() => {
          this.deleting = false;
          NProgress.done();
          this.makeToast("danger", "Datos inválidos.", "Error");
        });
    }
  }
};
</script>

<style lang="scss" src="@/assets/styles/sass/px-next/production.scss"></style>

<style lang="scss" scoped>
.pxwl { min-height: 100%; background: var(--pxn-bg); padding: var(--pxn-space-8) var(--pxn-space-9) var(--pxn-space-9); }
@media (max-width: 620px) { .pxwl { padding: var(--pxn-space-6) var(--pxn-space-5); } }
.pxwl__denied { padding: var(--pxn-space-12) 0; }
.pxwl__pad { padding: var(--pxn-space-6) 0; }
.pxwl__alert { margin-top: var(--pxn-space-5); }
.pxwl__filters { margin-top: var(--pxn-space-4); padding: var(--pxn-space-5); border: 1px solid var(--pxn-border); border-radius: var(--pxn-radius-lg); background: var(--pxn-surface); max-width: 420px; }
.pxwl__tablewrap { margin-top: var(--pxn-space-5); transition: opacity var(--pxn-dur-1) var(--pxn-ease); }
.pxwl__tablewrap.is-busy { opacity: 0.55; pointer-events: none; }
.pxwl__grow { flex: 1; }
.pxwl__confirm { margin: 0; font-size: var(--pxn-fs-body); color: var(--pxn-ink-2); line-height: var(--pxn-lh-snug); }
.pxwl-form__grid { display: grid; grid-template-columns: repeat(2, minmax(0, 1fr)); gap: var(--pxn-space-4); }
@media (max-width: 620px) { .pxwl-form__grid { grid-template-columns: minmax(0, 1fr); } }
.pxwl-form__wide { grid-column: 1 / -1; }
</style>
