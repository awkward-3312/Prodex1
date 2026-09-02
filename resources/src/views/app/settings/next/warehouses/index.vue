<template>
  <div class="px-next pxwh">
    <!--
      C3.7 — Almacenes / centros de distribución px-next. Ruta real
      /app/settings/Warehouses (name Warehouses). CRUD completo contra los
      endpoints actuales (GET/POST/PUT/DELETE warehouses); los límites del plan
      y validaciones los aplica el backend (se muestran vía message). Entidad
      maestra delicada: el borrado es una DESACTIVACIÓN que conserva historial.
    -->
    <div v-if="!can('warehouse')" class="pxwh__denied">
      <px-empty-state icon="lock" title="No tienes permiso para gestionar almacenes"
        description="Pide a un administrador el permiso «warehouse»." />
    </div>

    <template v-else>
      <px-page-header title="Almacenes y centros de distribución"
        :breadcrumbs="[{ label: 'Configuración' }, { label: 'Almacenes / CD' }]">
        <template #actions>
          <px-button variant="primary" icon="plus" @click="openNew">Nuevo almacén / CD</px-button>
        </template>
      </px-page-header>

      <p class="pxwh__lead">
        Un almacén/CD es una instalación logística independiente. Las bodegas internas de una sucursal se crean como
        ubicaciones de inventario dentro de esa sucursal y no consumen otro almacén del plan.
      </p>

      <px-toolbar
        :search="search"
        search-placeholder="Buscar por nombre, ciudad…"
        :filter-count="null"
        @update:search="onSearchInput"
      />

      <px-alert v-if="error" tone="danger" title="No se pudo cargar el listado" class="pxwh__alert">
        {{ error }}
        <template #actions><px-button size="sm" variant="secondary" @click="fetch()">Reintentar</px-button></template>
      </px-alert>

      <div v-if="initialLoading" class="pxwh__pad">
        <px-skeleton variant="table" :rows="8" :columns="6" />
      </div>

      <template v-else>
        <div class="pxwh__tablewrap" :class="{ 'is-busy': refreshing }">
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
            <template #cell-default_inventory_location="{ row }">
              {{ row.default_inventory_location ? row.default_inventory_location.name : 'Pendiente de inicializar' }}
            </template>
            <template #cell-email="{ row }">{{ row.email || '—' }}</template>
            <template #cell-city="{ row }">{{ row.city || '—' }}</template>
            <template #cell-country="{ row }">{{ row.country || '—' }}</template>
            <template #row-actions="{ row }">
              <px-kebab :items="rowActions" @select="onRowAction(row, $event)" />
            </template>
          </px-table>

          <px-empty-state v-else icon="warehouse" title="Sin almacenes"
            description="Crea tu primer almacén / centro de distribución." />
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

      <px-modal v-model="modalOpen" :title="editmode ? 'Editar almacén / CD' : 'Nuevo almacén / CD'" size="lg">
        <px-alert tone="info" bare class="pxwh__modal-note">
          <lucide-icon name="info" :size="13" />
          PRODEX creará una ubicación logística «Inventario principal». Este almacén/CD no pertenece a una sucursal.
        </px-alert>
        <validation-observer ref="obs">
          <form class="pxwh-form" @submit.prevent="submit">
            <div class="pxwh-form__grid">
              <v-field name="Nombre" label="Nombre" required :rules="{ required: true }" v-slot="{ invalid, id }" class="pxwh-form__wide">
                <px-input :id="id" v-model="wh.name" :invalid="invalid" placeholder="Ej. Centro de distribución principal" />
              </v-field>
              <px-field label="Teléfono">
                <template #default="{ id }"><px-input :id="id" v-model="wh.mobile" /></template>
              </px-field>
              <px-field label="País">
                <template #default="{ id }"><px-input :id="id" v-model="wh.country" /></template>
              </px-field>
              <px-field label="Ciudad">
                <template #default="{ id }"><px-input :id="id" v-model="wh.city" /></template>
              </px-field>
              <px-field label="Correo">
                <template #default="{ id }"><px-input :id="id" type="email" v-model="wh.email" /></template>
              </px-field>
              <px-field label="Código postal">
                <template #default="{ id }"><px-input :id="id" v-model="wh.zip" /></template>
              </px-field>
            </div>
          </form>
        </validation-observer>
        <template #footer="{ close }">
          <span class="pxwh__grow" />
          <px-button variant="secondary" :disabled="submitting" @click="close">Cancelar</px-button>
          <px-button variant="primary" icon="check" :loading="submitting" @click="submit">Guardar</px-button>
        </template>
      </px-modal>

      <px-modal v-model="confirmOpen" title="Desactivar almacén / CD" size="sm">
        <p class="pxwh__confirm">
          Se conservará el historial. No desactives un almacén con operaciones pendientes.
          ¿Desactivar <strong>{{ pendingDelete && pendingDelete.name }}</strong>?
        </p>
        <template #footer="{ close }">
          <span class="pxwh__grow" />
          <px-button variant="secondary" :disabled="deleting" @click="close">Cancelar</px-button>
          <px-button variant="danger" icon="archive" :loading="deleting" @click="doDelete">Desactivar</px-button>
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
import PxAlert from "@/components/px-next/PxAlert.vue";
import PxEmptyState from "@/components/px-next/PxEmptyState.vue";
import PxModal from "@/components/px-next/PxModal.vue";
import VField from "@/views/app/products/next/edit/VField.vue";

const empty = () => ({ id: null, name: "", mobile: "", email: "", zip: "", country: "Honduras", city: "" });

export default {
  name: "WarehousesNext",
  metaInfo: { title: "Almacenes / CD" },
  components: {
    PxPageHeader, PxToolbar, PxTable, PxPagination, PxButton, PxKebab,
    PxField, PxInput, PxAlert, PxEmptyState, PxModal, "v-field": VField
  },
  data() {
    return {
      initialLoading: true,
      refreshing: false,
      submitting: false,
      deleting: false,
      error: null,
      warehouses: [],
      totalRows: 0,
      page: 1,
      limit: "10",
      search: "",
      _searchTimer: null,
      sort: { field: "id", type: "desc" },
      modalOpen: false,
      editmode: false,
      wh: empty(),
      confirmOpen: false,
      pendingDelete: null
    };
  },
  computed: {
    ...mapGetters(["currentUserPermissions"]),
    columns() {
      return [
        { key: "name", label: "Nombre", sortable: true, strong: true },
        { key: "city", label: "Ciudad", sortable: true },
        { key: "country", label: "País", sortable: true },
        { key: "default_inventory_location", label: "Inventario principal", sortable: false },
        { key: "email", label: "Correo", sortable: true }
      ];
    },
    rows() {
      return this.warehouses || [];
    },
    rowActions() {
      return [
        { key: "edit", label: "Editar", icon: "pencil" },
        { key: "delete", label: "Desactivar", icon: "archive", tone: "danger" }
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
    fetch(initial) {
      if (initial) this.initialLoading = true; else this.refreshing = true;
      this.error = null;
      NProgress.start(); NProgress.set(0.1);
      window.axios
        .get("warehouses", {
          params: { page: this.page, SortField: this.sort.field, SortType: this.sort.type, search: this.search || "", limit: this.limit }
        })
        .then(({ data }) => {
          this.warehouses = data.warehouses || [];
          this.totalRows = data.totalRows || 0;
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
      this.wh = empty();
      this.editmode = false;
      this.modalOpen = true;
      this.$nextTick(() => { if (this.$refs.obs) this.$refs.obs.reset(); });
    },
    onRowAction(row, item) {
      const k = item && item.key;
      if (k === "edit") {
        this.wh = {
          id: row.id, name: row.name || "", mobile: row.mobile || "", email: row.email || "",
          zip: row.zip || "", country: row.country || "", city: row.city || ""
        };
        this.editmode = true;
        this.modalOpen = true;
        this.$nextTick(() => { if (this.$refs.obs) this.$refs.obs.reset(); });
      } else if (k === "delete") {
        this.pendingDelete = row;
        this.confirmOpen = true;
      }
    },
    payload() {
      return {
        name: this.wh.name,
        mobile: this.wh.mobile || null,
        email: this.wh.email || null,
        zip: this.wh.zip || null,
        country: this.wh.country || null,
        city: this.wh.city || null
      };
    },
    submit() {
      const validate = this.$refs.obs ? this.$refs.obs.validate() : Promise.resolve(!!this.wh.name);
      validate.then(ok => {
        if (!ok) {
          this.makeToast("danger", "Completa los campos obligatorios.", "Error");
          return;
        }
        this.submitting = true;
        NProgress.start(); NProgress.set(0.1);
        const req = this.editmode
          ? window.axios.put("warehouses/" + this.wh.id, this.payload())
          : window.axios.post("warehouses", this.payload());
        req
          .then(() => {
            this.submitting = false;
            this.modalOpen = false;
            NProgress.done();
            this.makeToast("success", this.editmode ? "Almacén/CD actualizado correctamente." : "Almacén/CD creado correctamente.", "Éxito");
            this.page = this.editmode ? this.page : 1;
            this.fetch();
          })
          .catch(error => {
            this.submitting = false;
            NProgress.done();
            const msg = (error.response && error.response.data && error.response.data.message) ||
              (this.editmode ? "No se pudo actualizar el almacén/CD." : "No se pudo crear el almacén/CD.");
            this.makeToast("danger", msg, "Error");
          });
      });
    },
    doDelete() {
      const row = this.pendingDelete;
      if (!row) return;
      this.deleting = true;
      NProgress.start(); NProgress.set(0.1);
      window.axios
        .delete("warehouses/" + row.id)
        .then(() => {
          this.deleting = false;
          this.confirmOpen = false;
          this.pendingDelete = null;
          NProgress.done();
          this.makeToast("success", "Almacén/CD desactivado.", "Éxito");
          this.fetch();
        })
        .catch(() => {
          this.deleting = false;
          NProgress.done();
          this.makeToast("danger", "No se pudo desactivar el almacén/CD.", "Error");
        });
    }
  }
};
</script>

<style lang="scss" src="@/assets/styles/sass/px-next/production.scss"></style>

<style lang="scss" scoped>
.pxwh { min-height: 100%; background: var(--pxn-bg); padding: var(--pxn-space-8) var(--pxn-space-9) var(--pxn-space-9); }
@media (max-width: 620px) { .pxwh { padding: var(--pxn-space-6) var(--pxn-space-5); } }
.pxwh__denied { padding: var(--pxn-space-12) 0; }
.pxwh__pad { padding: var(--pxn-space-6) 0; }
.pxwh__alert { margin-top: var(--pxn-space-5); }
.pxwh__lead { margin: var(--pxn-space-3) 0 var(--pxn-space-4); font-size: var(--pxn-fs-sm); color: var(--pxn-ink-3); max-width: 70ch; }
.pxwh__tablewrap { margin-top: var(--pxn-space-5); transition: opacity var(--pxn-dur-1) var(--pxn-ease); }
.pxwh__tablewrap.is-busy { opacity: 0.55; pointer-events: none; }
.pxwh__grow { flex: 1; }
.pxwh__confirm { margin: 0; font-size: var(--pxn-fs-body); color: var(--pxn-ink-2); line-height: var(--pxn-lh-snug); }
.pxwh__modal-note { margin: 0 0 var(--pxn-space-4); }
.pxwh__modal-note :deep(svg) { vertical-align: -2px; margin-right: var(--pxn-space-2); }
.pxwh-form { display: block; }
.pxwh-form__grid { display: grid; grid-template-columns: repeat(2, minmax(0, 1fr)); gap: var(--pxn-space-4); }
@media (max-width: 620px) { .pxwh-form__grid { grid-template-columns: minmax(0, 1fr); } }
.pxwh-form__wide { grid-column: 1 / -1; }
</style>
