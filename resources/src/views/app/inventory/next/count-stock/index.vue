<template>
  <div class="px-next pxcnt">
    <!--
      C3.3 — Conteo de stock px-next. Ruta real: /app/products/count_stock (name count_stock).
      Conserva: almacén, categoría, fecha, listado histórico, generación
      (POST store_count_stock), descarga del archivo, permiso count_stock y
      endpoints actuales (GET count_stock?…).
    -->
    <div v-if="!can('count_stock')" class="pxcnt__denied">
      <px-empty-state icon="lock" title="No tienes permiso para el conteo de stock"
        description="Pide a un administrador el permiso «count_stock»." />
    </div>

    <template v-else>
      <px-page-header title="Conteo de stock" :breadcrumbs="[{ label: 'Productos' }, { label: 'Conteo de stock' }]">
        <template #actions>
          <px-button variant="primary" icon="plus" @click="openNew">Nuevo conteo</px-button>
        </template>
      </px-page-header>

      <px-toolbar
        :search="search"
        search-placeholder="Buscar por almacén, categoría…"
        :filter-count="null"
        @update:search="onSearchInput"
      />

      <px-alert v-if="error" tone="danger" title="No se pudo cargar el listado" class="pxcnt__alert">
        {{ error }}
        <template #actions><px-button size="sm" variant="secondary" @click="fetch()">Reintentar</px-button></template>
      </px-alert>

      <div v-if="initialLoading" class="pxcnt__pad">
        <px-skeleton variant="table" :rows="10" :columns="4" />
      </div>

      <template v-else>
        <div class="pxcnt__tablewrap" :class="{ 'is-busy': refreshing }">
          <px-table
            v-if="rows.length"
            :columns="columns"
            :rows="rows"
            row-key="id"
            :sort-key="sort.field"
            :sort-dir="sort.type"
            @sort="onSort"
          >
            <template #cell-file_stock="{ row }">
              <a v-if="row.file_stock" class="pxcnt__dl" :href="fileHref(row)" target="_blank" rel="noopener">
                <lucide-icon name="download" :size="14" /> Descargar
              </a>
              <span v-else class="pxn-muted">—</span>
            </template>
          </px-table>

          <px-empty-state v-else icon="clipboard-list" title="Sin conteos"
            description="Todavía no se ha generado ningún conteo de stock." />
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

      <px-modal v-model="modalOpen" title="Generar conteo de stock" size="md">
        <validation-observer ref="obs">
          <form class="pxcnt-form" @submit.prevent="submit">
            <v-field name="Fecha" label="Fecha" required :rules="{ required: true }" v-slot="{ invalid, id }">
              <px-input :id="id" type="date" v-model="stock.date" :invalid="invalid" />
            </v-field>
            <v-field name="Almacén" label="Almacén" required :rules="{ required: true }" v-slot="{ invalid, id }">
              <vs-px
                :input-id="id"
                :invalid="invalid"
                v-model="stock.warehouse_id"
                :reduce="o => o.value"
                placeholder="Elegir almacén"
                :options="warehouses.map(w => ({ label: w.name, value: w.id }))"
              />
            </v-field>
            <px-field label="Categoría">
              <template #default="{ id }">
                <vs-px
                  :input-id="id"
                  v-model="stock.category_id"
                  :reduce="o => o.value"
                  placeholder="Todas las categorías"
                  :options="categories.map(c => ({ label: c.name, value: c.id }))"
                />
              </template>
            </px-field>
          </form>
        </validation-observer>
        <template #footer="{ close }">
          <span class="pxcnt__grow" />
          <px-button variant="secondary" :disabled="submitting" @click="close">Cancelar</px-button>
          <px-button variant="primary" icon="check" :loading="submitting" @click="submit">Generar</px-button>
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
import PxField from "@/components/px-next/PxField.vue";
import PxInput from "@/components/px-next/PxInput.vue";
import PxAlert from "@/components/px-next/PxAlert.vue";
import PxEmptyState from "@/components/px-next/PxEmptyState.vue";
import PxModal from "@/components/px-next/PxModal.vue";
import VField from "@/views/app/products/next/edit/VField.vue";
import VsPx from "@/views/app/products/next/edit/VsPx.vue";

export default {
  name: "CountStockNext",
  metaInfo: { title: "Conteo de stock" },
  components: {
    PxPageHeader, PxToolbar, PxTable, PxPagination, PxButton,
    PxField, PxInput, PxAlert, PxEmptyState, PxModal, "v-field": VField, "vs-px": VsPx
  },
  data() {
    return {
      initialLoading: true,
      refreshing: false,
      error: null,
      stocks: [],
      warehouses: [],
      categories: [],
      totalRows: "",
      page: 1,
      limit: "10",
      search: "",
      _searchTimer: null,
      sort: { field: "id", type: "desc" },
      modalOpen: false,
      submitting: false,
      stock: {
        id: "",
        date: new Date().toISOString().slice(0, 10),
        warehouse_id: "",
        category_id: ""
      }
    };
  },
  computed: {
    ...mapGetters(["currentUserPermissions"]),
    columns() {
      return [
        { key: "date", label: "Fecha", sortable: true, width: "140px" },
        { key: "warehouse_name", label: "Almacén", sortable: true },
        { key: "category_name", label: "Categoría", sortable: true },
        { key: "file_stock", label: "Archivo", sortable: false, width: "160px" }
      ];
    },
    rows() {
      return this.stocks || [];
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
    fileHref(row) {
      return this.$imgUrl("count_stock", row.file_stock);
    },
    makeToast(variant, msg, title) {
      this.$root.$bvToast.toast(msg, { title, variant, solid: true });
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
    fetch(initial) {
      if (initial) this.initialLoading = true; else this.refreshing = true;
      this.error = null;
      NProgress.start(); NProgress.set(0.1);
      const qs =
        "count_stock?page=" + this.page +
        "&SortField=" + this.sort.field +
        "&SortType=" + this.sort.type +
        "&search=" + encodeURIComponent(this.search || "") +
        "&limit=" + this.limit;
      window.axios
        .get(qs)
        .then(response => {
          this.stocks = response.data.stocks || [];
          this.warehouses = response.data.warehouses || this.warehouses;
          this.categories = response.data.categories || this.categories;
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
    openNew() {
      this.stock = {
        id: "",
        date: new Date().toISOString().slice(0, 10),
        warehouse_id: "",
        category_id: ""
      };
      this.modalOpen = true;
      this.$nextTick(() => { if (this.$refs.obs) this.$refs.obs.reset(); });
    },
    submit() {
      // El observer vive dentro del modal (v-if + transición); si aún no montó,
      // validamos los requeridos a mano para no perder el clic.
      const validate = this.$refs.obs
        ? this.$refs.obs.validate()
        : Promise.resolve(!!this.stock.date && !!this.stock.warehouse_id);
      validate.then(ok => {
        if (!ok) {
          this.makeToast("danger", "Completa el formulario correctamente.", "Error");
          return;
        }
        this.submitting = true;
        NProgress.start(); NProgress.set(0.1);
        window.axios
          .post("store_count_stock", {
            date: this.stock.date,
            warehouse_id: this.stock.warehouse_id,
            category_id: this.stock.category_id
          })
          .then(() => {
            this.submitting = false;
            this.modalOpen = false;
            NProgress.done();
            this.makeToast("success", "Conteo generado correctamente.", "Éxito");
            this.fetch();
          })
          .catch(() => {
            this.submitting = false;
            NProgress.done();
            this.makeToast("danger", "No se pudo generar el conteo.", "Error");
          });
      });
    }
  }
};
</script>

<style lang="scss" src="@/assets/styles/sass/px-next/production.scss"></style>

<style lang="scss" scoped>
.pxcnt { min-height: 100%; background: var(--pxn-bg); padding: var(--pxn-space-8) var(--pxn-space-9) var(--pxn-space-9); }
@media (max-width: 620px) { .pxcnt { padding: var(--pxn-space-6) var(--pxn-space-5); } }
.pxcnt__denied { padding: var(--pxn-space-12) 0; }
.pxcnt__pad { padding: var(--pxn-space-6) 0; }
.pxcnt__alert { margin-top: var(--pxn-space-5); }
.pxcnt__tablewrap { margin-top: var(--pxn-space-5); transition: opacity var(--pxn-dur-1) var(--pxn-ease); }
.pxcnt__tablewrap.is-busy { opacity: 0.55; pointer-events: none; }
.pxcnt__dl { display: inline-flex; align-items: center; gap: var(--pxn-space-2); color: var(--pxn-primary-ink); font-weight: var(--pxn-fw-medium); font-size: var(--pxn-fs-sm); }
.pxcnt__dl:hover { text-decoration: underline; }
.pxcnt__grow { flex: 1; }
.pxcnt-form { display: flex; flex-direction: column; gap: var(--pxn-space-4); }
</style>
