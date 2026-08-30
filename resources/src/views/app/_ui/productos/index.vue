<template>
  <div class="px-next pxn-doc pxp" :class="{ 'pxn-doc--compact': density === 'compact' }">
    <!--
      ============================================================================
      DIRECTION CONTRACT · px-next · "Panel de operación" — B2 Productos (listado)
      references: /app/products (index_products.vue) · mode: Operate · seed: brief
      ----------------------------------------------------------------------------
      THESIS: encontrar y reconocer un producto rápido. La identidad (miniatura +
        nombre + SKU) manda; categoría/marca son metadata; costo/precio/stock se
        leen alineados; el estado es un badge. Endpoint real (/api/products),
        paginación/orden/filtros/búsqueda 100% en servidor.
      OWN-WORLD: tabla ERP densa px-next, hairlines, un acento del tenant, cifras
        tabulares, primera columna fija al hacer scroll horizontal.
      STORY: buscar → (afinar en Filtros) → escanear → abrir/editar un producto
        (cambio de contexto a la pantalla real) o seleccionar varios para una
        acción en bloque.
      FIRST VIEWPORT: cabecera + buscador + total; la tabla con las primeras
        filas; densidad cómoda por defecto.
      FORM: listado operativo. #2 del brief fijado (tras el dashboard).
      FINISH: sin revisar/documentar = sin terminar.
      ============================================================================
    -->

    <div v-if="!can('products_view')" class="pxp__denied">
      <px-empty-state
        icon="lock"
        title="No tienes permiso para ver productos"
        description="Pide a un administrador el permiso «products_view»."
      />
    </div>

    <template v-else>
      <px-page-header title="Productos" :breadcrumbs="[{ label: 'Inventario' }, { label: 'Productos' }]">
        <template #title-badge>
          <px-badge tone="info" icon="sparkles">Preview B2</px-badge>
        </template>
        <template #meta>
          <span><lucide-icon name="package" :size="13" /> {{ formatInt(total) }} productos</span>
          <span v-if="activeFilterCount"><lucide-icon name="filter" :size="13" /> {{ activeFilterCount }} filtro(s) activo(s)</span>
          <span v-if="refreshing" class="pxp__refreshing"><span class="pxp__spin" /> actualizando…</span>
        </template>
        <template #actions>
          <px-button
            v-if="can('products_add')"
            variant="primary"
            icon="plus"
            @click="goCreate"
          >Nuevo producto</px-button>
        </template>
      </px-page-header>

      <px-toolbar
        :search="search"
        search-placeholder="Buscar por nombre, código, categoría o marca…"
        :filter-count="activeFilterCount"
        :views="densityViews"
        :view="density"
        @update:search="onSearchInput"
        @open-filters="filtersOpen = true"
        @update:view="density = $event"
      >
        <template #trail>
          <px-button
            v-if="can('product_import')"
            variant="ghost"
            size="sm"
            icon="download"
            @click="goImport"
          >Importar</px-button>
          <px-button
            variant="secondary"
            size="sm"
            icon="file-spreadsheet"
            @click="exportXlsx(exportData(rows), 'productos')"
          >Exportar</px-button>
        </template>
      </px-toolbar>

      <!-- chips de filtros activos (para entender por qué está filtrada la lista) -->
      <div v-if="activeChips.length" class="pxp__chips">
        <px-tag
          v-for="chip in activeChips"
          :key="chip.key"
          :label="chip.label"
          :hue="chip.key"
          removable
          @remove="clearFilter(chip.key)"
        />
        <button type="button" class="pxp__chips-clear pxn-ring" @click="clearAllFilters">Limpiar todo</button>
      </div>

      <!-- barra de acciones en bloque -->
      <div v-if="selectedIds.length" class="pxp__bulk">
        <span class="pxp__bulk-count pxn-num">{{ selectedIds.length }} seleccionado(s)</span>
        <span class="pxp__bulk-sep" />
        <px-button
          variant="secondary"
          size="sm"
          icon="file-spreadsheet"
          @click="exportXlsx(exportData(selectedRows), 'productos-seleccion')"
        >Exportar selección</px-button>
        <px-button
          v-if="can('products_delete')"
          variant="danger"
          size="sm"
          icon="trash-2"
          @click="askDeleteSelection"
        >Eliminar selección</px-button>
        <px-button variant="ghost" size="sm" @click="selectedIds = []">Quitar selección</px-button>
      </div>

      <px-alert v-if="error" tone="danger" title="No se pudo cargar el listado" class="pxp__alert">
        {{ error }}
        <template #actions><px-button size="sm" variant="secondary" @click="fetch()">Reintentar</px-button></template>
      </px-alert>

      <div v-if="initialLoading" class="pxp__pad">
        <px-skeleton variant="table" :rows="10" :columns="8" />
      </div>

      <template v-else>
        <div class="pxp__tablewrap" :class="{ 'is-busy': refreshing }">
          <px-table
            v-if="rows.length"
            :columns="columns"
            :rows="rows"
            row-key="id"
            selectable
            :selected="selectedIds"
            :density="density"
            sticky-first
            has-row-actions
            :sort-key="sortColKey"
            :sort-dir="sort.type"
            @update:selected="selectedIds = $event"
            @sort="onSort"
          >
            <template #cell-product="{ row }">
              <px-product-cell :row="row" />
            </template>

            <template #cell-type="{ row }">
              <span class="pxp-type" :class="`is-${row.type}`">{{ row.typeLabel }}</span>
            </template>

            <template #cell-category="{ row }">
              <span v-if="row.categoryPrimary" class="pxp-cats">
                <px-tag :label="row.categoryPrimary" :hue="row.categoryPrimary" />
                <span v-if="row.categoryExtra" class="pxp-cats__more" :title="row.categories.join(', ')">+{{ row.categoryExtra }}</span>
              </span>
              <span v-else class="pxn-muted">—</span>
            </template>

            <template #cell-brand="{ row }">
              <span :class="row.brand ? '' : 'pxn-muted'">{{ row.brand || '—' }}</span>
            </template>

            <template #cell-cost="{ row }">
              <span class="pxn-num" :class="{ 'pxn-muted': row.costMissing }">{{ row.costMissing ? '—' : fmt.money(row.cost) }}</span>
            </template>

            <template #cell-price="{ row }">
              <span class="pxn-num" :class="{ 'pxn-muted': row.priceMissing }">{{ row.priceMissing ? '—' : fmt.money(row.price) }}</span>
            </template>

            <template #cell-stock="{ row }">
              <span
                class="pxn-num"
                :class="{ 'pxn-muted': row.qtyUnavailable, 'pxp-stock--zero': row.qtyValue === 0 }"
              >{{ row.qtyLabel }}</span>
            </template>

            <template #cell-status="{ row }">
              <px-badge :tone="row.active ? 'success' : 'neutral'" :icon="row.active ? 'check' : 'minus'">
                {{ row.active ? 'Activo' : 'Inactivo' }}
              </px-badge>
            </template>

            <template #row-actions="{ row }">
              <px-kebab :items="rowActions(row)" @select="onRowAction(row, $event)" />
            </template>
          </px-table>

          <div v-else class="pxp__pad">
            <px-empty-state
              v-if="hasActiveQuery"
              icon="search-x"
              title="Sin resultados"
              description="Ningún producto coincide con la búsqueda o los filtros. Ajústalos o límpialos."
            >
              <px-button variant="secondary" icon="power" @click="clearAllFilters">Limpiar búsqueda y filtros</px-button>
            </px-empty-state>
            <px-empty-state
              v-else
              icon="package-search"
              title="Aún no hay productos"
              description="Cuando registres productos aparecerán aquí."
            />
          </div>
        </div>

        <px-pagination
          v-if="rows.length"
          :page="page"
          :per-page="limit"
          :total="total"
          :per-page-options="['10', '25', '50', '100']"
          @update:page="onPage"
          @update:perPage="onLimit"
        />
      </template>

      <product-filter-panel
        v-model="filtersOpen"
        :filters="filters"
        :categories="meta.categories"
        :brands="meta.brands"
        :warehouses="meta.warehouses"
        @apply="applyFilters"
      />

      <px-modal v-model="deleteModal.open" :title="deleteModal.title" size="sm">
        <p class="pxp__delcopy">{{ deleteModal.body }}</p>
        <px-alert tone="info" bare class="pxp__delnote">
          Vista previa B2: la eliminación <strong>no se ejecuta</strong>. Este paso solo valida el menú y la confirmación.
        </px-alert>
        <template #footer="{ close }">
          <span class="pxp__bulk-sep" />
          <px-button variant="secondary" @click="close">Cancelar</px-button>
          <px-button variant="danger" icon="trash-2" @click="confirmDeleteNoop(close)">Entiendo, continuar</px-button>
        </template>
      </px-modal>

      <p class="pxp__note">
        <lucide-icon name="sparkles" :size="13" />
        Candidato experimental de <code>/app/_ui/productos</code>. No reemplaza <code>/app/products</code>.
        Datos reales de <code>/api/products</code> · búsqueda, filtros, orden y paginación en el servidor.
      </p>
    </template>
  </div>
</template>

<script>
import { mapGetters } from "vuex";
import {
  PxPageHeader, PxBadge, PxButton, PxToolbar, PxTable, PxTag, PxKebab,
  PxPagination, PxAlert, PxEmptyState, PxModal
} from "@/components/px-next";
import * as XLSX from "xlsx";
import PxProductCell from "./widgets/PxProductCell.vue";
import ProductFilterPanel from "./widgets/ProductFilterPanel.vue";
import { adaptProducts } from "./adapter";
import { makeProductFormatters } from "./format";

const EMPTY_FILTERS = { code: "", name: "", category: "", brand: "", warehouse: "", status: "" };

// key de columna px-next  ->  campo real de ordenación del backend
const SORT_MAP = { product: "name", cost: "cost", price: "price", category: "category_id", brand: "brand_id" };

export default {
  name: "PxNextProductsPreview",
  components: {
    PxPageHeader, PxBadge, PxButton, PxToolbar, PxTable, PxTag, PxKebab,
    PxPagination, PxAlert, PxEmptyState, PxModal, PxProductCell, ProductFilterPanel
  },
  data() {
    return {
      initialLoading: true,
      refreshing: false,
      error: null,
      rows: [],
      total: 0,
      meta: { categories: [], brands: [], warehouses: [] },
      search: "",
      filters: { ...EMPTY_FILTERS },
      sort: { field: "id", type: "desc" },
      page: 1,
      limit: 25,
      density: "comfortable",
      selectedIds: [],
      filtersOpen: false,
      deleteModal: { open: false, title: "", body: "", mode: null, id: null },
      densityViews: [
        { value: "comfortable", icon: "rows-2", label: "Densidad cómoda" },
        { value: "compact", icon: "menu", label: "Densidad compacta" }
      ],
      columns: [
        { key: "product", label: "Producto", sortable: true, width: "300px" },
        { key: "type", label: "Tipo" },
        { key: "category", label: "Categoría", sortable: true },
        { key: "brand", label: "Marca", sortable: true },
        { key: "cost", label: "Costo", align: "right", numeric: true, sortable: true },
        { key: "price", label: "Precio", align: "right", numeric: true, sortable: true },
        { key: "stock", label: "Existencias", align: "right", numeric: true },
        { key: "status", label: "Estado" }
      ],
      exportColumns: [
        { label: "Código", field: "code" },
        { label: "Nombre", field: "name" },
        { label: "Tipo", field: "type" },
        { label: "Categoría", field: "category" },
        { label: "Marca", field: "brand" },
        { label: "Costo", field: "cost" },
        { label: "Precio", field: "price" },
        { label: "Unidad", field: "unit" },
        { label: "Existencias", field: "stock" },
        { label: "Estado", field: "status" }
      ]
    };
  },
  computed: {
    ...mapGetters(["currentUserPermissions", "currentUser"]),
    fmt() {
      return makeProductFormatters({
        currency: this.currentUser && this.currentUser.currency,
        store: this.$store
      });
    },
    sortColKey() {
      const found = Object.keys(SORT_MAP).find(k => SORT_MAP[k] === this.sort.field);
      return found || null;
    },
    selectedRows() {
      const set = new Set(this.selectedIds);
      return this.rows.filter(r => set.has(r.id));
    },
    activeFilterCount() {
      return Object.keys(EMPTY_FILTERS).filter(k => String(this.filters[k] || "").trim() !== "").length;
    },
    hasActiveQuery() {
      return this.activeFilterCount > 0 || String(this.search || "").trim() !== "";
    },
    activeChips() {
      const chips = [];
      const s = String(this.search || "").trim();
      if (s) chips.push({ key: "search", label: `Buscar: “${s}”` });
      if (this.filters.code) chips.push({ key: "code", label: `Código: ${this.filters.code}` });
      if (this.filters.name) chips.push({ key: "name", label: `Nombre: ${this.filters.name}` });
      if (this.filters.category) chips.push({ key: "category", label: `Categoría: ${this.optLabel(this.meta.categories, this.filters.category)}` });
      if (this.filters.brand) chips.push({ key: "brand", label: `Marca: ${this.optLabel(this.meta.brands, this.filters.brand)}` });
      if (this.filters.warehouse) chips.push({ key: "warehouse", label: `Almacén: ${this.optLabel(this.meta.warehouses, this.filters.warehouse)}` });
      if (this.filters.status) chips.push({ key: "status", label: `Estado: ${this.filters.status === "1" ? "Activos" : "Inactivos"}` });
      return chips;
    }
  },
  created() {
    // Estado no-reactivo de la consulta (no puede ir en data(): Vue no proxya
    // propiedades con prefijo "_"/"$").
    this.reqSeq = 0;
    this.abortCtl = null;
    this.searchTimer = null;
    this.fetch();
  },
  beforeDestroy() {
    if (this.searchTimer) clearTimeout(this.searchTimer);
    if (this.abortCtl) this.abortCtl.abort();
  },
  methods: {
    can(p) {
      const list = Array.isArray(this.currentUserPermissions) ? this.currentUserPermissions : [];
      return list.includes(p);
    },
    formatInt(v) { return this.fmt.number(v, 0); },
    optLabel(opts, value) {
      const o = (opts || []).find(x => String(x.value) === String(value));
      return o ? o.label : value;
    },

    // ---- fetch server-side, con guarda de carrera + abort ------------------
    async fetch() {
      const seq = ++this.reqSeq;
      if (this.abortCtl) this.abortCtl.abort();
      const controller = typeof AbortController !== "undefined" ? new AbortController() : null;
      this.abortCtl = controller;

      if (this.rows.length) this.refreshing = true;
      this.error = null;

      const params = {
        page: this.page,
        limit: this.limit,
        SortField: this.sort.field,
        SortType: this.sort.type,
        search: this.search || "",
        code: this.filters.code || "",
        name: this.filters.name || "",
        category_id: this.filters.category || "",
        brand_id: this.filters.brand || "",
        warehouse_id: this.filters.warehouse || "",
        status: this.filters.status || ""
      };

      try {
        const { data } = await window.axios.get("products", {
          params,
          signal: controller ? controller.signal : undefined,
          meta: { skipInitialLoader: true }
        });
        if (seq !== this.reqSeq) return; // respuesta obsoleta
        const adapted = adaptProducts(data, this.$imgUrl && this.$imgUrl.bind(this));
        this.rows = adapted.rows;
        this.total = adapted.total;
        this.meta = { categories: adapted.categories, brands: adapted.brands, warehouses: adapted.warehouses };
        // si la página quedó fuera de rango (p. ej. tras filtrar), reencuadra
        const lastPage = Math.max(1, Math.ceil(this.total / this.limit));
        if (this.page > lastPage) { this.page = lastPage; return this.fetch(); }
        this.selectedIds = this.selectedIds.filter(id => this.rows.some(r => r.id === id));
      } catch (e) {
        if (e && (e.name === "CanceledError" || e.code === "ERR_CANCELED" || e.name === "AbortError")) return;
        if (seq !== this.reqSeq) return;
        this.error =
          (e && e.response && e.response.data && (e.response.data.message || e.response.data.error)) ||
          (e && e.message) || "Error de red.";
        this.rows = [];
      } finally {
        if (seq === this.reqSeq) {
          this.initialLoading = false;
          this.refreshing = false;
          this.abortCtl = null;
        }
      }
    },

    onSearchInput(v) {
      this.search = v;
      if (this.searchTimer) clearTimeout(this.searchTimer);
      this.searchTimer = setTimeout(() => {
        this.page = 1;
        this.fetch();
      }, 300);
    },
    onSort({ key, dir }) {
      const field = SORT_MAP[key] || key;
      this.sort = { field, type: dir === "asc" ? "asc" : "desc" };
      this.page = 1;
      this.fetch();
    },
    onPage(p) {
      const next = Number(p) || 1;
      if (next === this.page) return;
      this.page = next;
      this.fetch();
    },
    onLimit(v) {
      const next = Number(v) || 25;
      if (next === this.limit) return;
      this.limit = next;
      this.page = 1;
      this.fetch();
    },
    applyFilters(next) {
      this.filters = { ...EMPTY_FILTERS, ...next };
      this.page = 1;
      this.fetch();
    },
    clearFilter(key) {
      if (key === "search") {
        this.search = "";
      } else {
        this.filters = { ...this.filters, [key]: "" };
      }
      this.page = 1;
      this.fetch();
    },
    clearAllFilters() {
      this.search = "";
      this.filters = { ...EMPTY_FILTERS };
      this.page = 1;
      this.fetch();
    },

    // ---- navegación a pantallas reales (cambio de contexto) ---------------
    goCreate() { this.$router.push({ name: "store_product" }); },
    goImport() { this.$router.push({ name: "import_products" }); },
    openView(id) { this.$router.push({ name: "detail_product", params: { id } }); },
    openEdit(id) { this.$router.push({ name: "edit_product", params: { id } }); },

    rowActions(row) {
      const items = [];
      if (this.can("products_view")) items.push({ key: "view", label: "Ver detalle", icon: "eye" });
      if (this.can("products_edit")) items.push({ key: "edit", label: "Editar", icon: "pencil" });
      if (this.can("products_delete")) {
        if (items.length) items.push({ divider: true });
        items.push({ key: "delete", label: "Eliminar", icon: "trash-2", tone: "danger" });
      }
      return items;
    },
    onRowAction(row, item) {
      if (!item) return;
      if (item.key === "view") return this.openView(row.id);
      if (item.key === "edit") return this.openEdit(row.id);
      if (item.key === "delete") return this.askDeleteRow(row);
    },
    askDeleteRow(row) {
      this.deleteModal = {
        open: true,
        mode: "row",
        id: row.id,
        title: "Eliminar producto",
        body: `Se eliminaría «${row.name}» (${row.code}).`
      };
    },
    askDeleteSelection() {
      this.deleteModal = {
        open: true,
        mode: "selection",
        id: null,
        title: "Eliminar selección",
        body: `Se eliminarían ${this.selectedIds.length} producto(s) seleccionado(s).`
      };
    },
    confirmDeleteNoop(close) {
      close();
      this.$bvToast && this.$bvToast.toast(
        "Vista previa B2: no se ejecutó ningún borrado.",
        { title: "Acción no ejecutada", variant: "info", solid: true }
      );
    },

    // ---- exportación cliente (solo lectura, sin backend) ----------------
    exportData(list) {
      return (list || []).map(r => ({
        code: r.code,
        name: r.name,
        type: r.typeLabel,
        category: r.categories.join(" / "),
        brand: r.brand || "",
        cost: r.costMissing ? "" : this.fmt.money(r.cost, { withSymbol: false }),
        price: r.priceMissing ? "" : this.fmt.money(r.price, { withSymbol: false }),
        unit: r.unit,
        stock: r.qtyLabel,
        status: r.active ? "Activo" : "Inactivo"
      }));
    },
    exportXlsx(rows, fileName) {
      if (!rows || !rows.length) {
        this.$bvToast && this.$bvToast.toast("No hay filas para exportar.", { title: "Exportar", variant: "info", solid: true });
        return;
      }
      try {
        const mapped = rows.map(row => {
          const out = {};
          this.exportColumns.forEach(c => { out[c.label] = row[c.field] != null ? row[c.field] : ""; });
          return out;
        });
        const ws = XLSX.utils.json_to_sheet(mapped);
        const wb = XLSX.utils.book_new();
        XLSX.utils.book_append_sheet(wb, ws, "productos");
        XLSX.writeFile(wb, `${fileName}.xlsx`);
      } catch (e) {
        this.$bvToast && this.$bvToast.toast("No se pudo generar el archivo.", { title: "Exportar", variant: "danger", solid: true });
      }
    }
  }
};
</script>

<style lang="scss" src="@/assets/styles/sass/px-next/index.scss"></style>

<style lang="scss" scoped>
.pxp { min-height: 100%; background: var(--pxn-bg); padding: var(--pxn-space-8) var(--pxn-space-9) var(--pxn-space-11); }
@media (max-width: 620px) { .pxp { padding: var(--pxn-space-6) var(--pxn-space-5) var(--pxn-space-10); } }
.pxp__denied { padding: var(--pxn-space-12) 0; }
.pxp__alert { margin-top: var(--pxn-space-5); }
.pxp__pad { padding: var(--pxn-space-6) 0; }
.pxp__note {
  display: block; margin-top: var(--pxn-space-9); padding-top: var(--pxn-space-5);
  border-top: 1px solid var(--pxn-border);
  font-size: var(--pxn-fs-xs); line-height: var(--pxn-lh-normal); color: var(--pxn-ink-3);
}
.pxp__note code { font-size: 0.92em; white-space: nowrap; }
.pxp__note :deep(svg) { vertical-align: -2px; margin-right: var(--pxn-space-2); }

.pxp__refreshing { display: inline-flex; align-items: center; gap: var(--pxn-space-3); color: var(--pxn-ink-3); }
.pxp__spin, .pxp__bulk .pxp__spin {
  width: 11px; height: 11px; border-radius: 50%;
  border: 2px solid var(--pxn-border-strong); border-top-color: var(--pxn-primary);
  animation: pxp-spin 0.7s linear infinite;
}
@keyframes pxp-spin { to { transform: rotate(360deg); } }
@media (prefers-reduced-motion: reduce) { .pxp__spin { animation-duration: 0s; } }

.pxp__chips {
  display: flex; flex-wrap: wrap; align-items: center; gap: var(--pxn-space-3);
  margin-top: var(--pxn-space-5);
}
.pxp__chips-clear {
  border: 0; background: transparent; cursor: pointer;
  font-size: var(--pxn-fs-xs); font-weight: var(--pxn-fw-medium);
  color: var(--pxn-primary-ink); padding: var(--pxn-space-2) var(--pxn-space-3);
  border-radius: var(--pxn-radius-sm);
}
.pxp__chips-clear:hover { background: var(--pxn-primary-soft); }

.pxp__bulk {
  display: flex; flex-wrap: wrap; align-items: center; gap: var(--pxn-space-4);
  margin-top: var(--pxn-space-5); padding: var(--pxn-space-4) var(--pxn-space-5);
  border: 1px solid var(--pxn-primary-border);
  background: var(--pxn-primary-soft);
  border-radius: var(--pxn-radius-md);
}
.pxp__bulk-count { font-weight: var(--pxn-fw-semibold); color: var(--pxn-primary-ink); }
.pxp__bulk-sep { flex: 1; }

.pxp__tablewrap { margin-top: var(--pxn-space-5); position: relative; transition: opacity var(--pxn-dur-2) var(--pxn-ease); }
.pxp__tablewrap.is-busy { opacity: 0.55; pointer-events: none; }

.pxp-type {
  display: inline-block; font-size: var(--pxn-fs-xs); font-weight: var(--pxn-fw-medium);
  color: var(--pxn-ink-2);
}
.pxp-type.is-variable { color: var(--pxn-primary-ink); }

.pxp-cats { display: inline-flex; align-items: center; gap: var(--pxn-space-3); min-width: 0; }
.pxp-cats__more { font-size: var(--pxn-fs-xs); color: var(--pxn-ink-3); font-weight: var(--pxn-fw-medium); }

.pxp-stock--zero { color: var(--pxn-warning-ink); font-weight: var(--pxn-fw-semibold); }

.pxp__delcopy { color: var(--pxn-ink-2); }
.pxp__delnote { margin-top: var(--pxn-space-4); }

:deep(.pxn-pg) { margin-top: var(--pxn-space-5); }
</style>
