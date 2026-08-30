<template>
  <div class="px-next pxp" :class="{ 'pxp--compact': density === 'compact' }">
    <!--
      Fase C1 — Listado de productos px-next adoptado en /app/products/list.
      El listado anterior (index_products.vue) sigue intacto en
      /app/products/list-classic para rollback inmediato por URL.
      Paridad funcional con el listado clásico: ver / editar / crear / duplicar /
      importar / exportar (Excel + PDF) / eliminar individual / eliminación
      múltiple, cada una con su permiso. Sin endpoints nuevos, sin cambios de
      backend: búsqueda, filtros, orden y paginación 100% en el servidor
      (GET /api/products); mutaciones contra DELETE /api/products/{id} y
      POST /api/products/delete/by_selection.
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

          <px-menu :items="exportMenu" align="end" @select="onExport">
            <template #trigger>
              <px-button variant="secondary" size="sm" icon="file-spreadsheet" trailing-icon="chevron-down">
                Exportar
              </px-button>
            </template>
          </px-menu>
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

      <px-modal v-model="confirm.open" :title="confirm.title" size="sm" :persistent="confirm.busy">
        <p class="pxp__delcopy">{{ confirm.body }}</p>
        <px-alert v-if="confirm.tone === 'danger'" tone="warning" bare class="pxp__delnote">
          Esta acción no se puede deshacer.
        </px-alert>
        <template #footer="{ close }">
          <span class="pxp__bulk-sep" />
          <px-button variant="secondary" :disabled="confirm.busy" @click="close">Cancelar</px-button>
          <px-button
            :variant="confirm.tone === 'danger' ? 'danger' : 'primary'"
            :icon="confirm.icon"
            :loading="confirm.busy"
            @click="runConfirm(close)"
          >{{ confirm.cta }}</px-button>
        </template>
      </px-modal>
    </template>
  </div>
</template>

<script>
import { mapGetters } from "vuex";
import PxPageHeader from "@/components/px-next/PxPageHeader.vue";
import PxButton from "@/components/px-next/PxButton.vue";
import PxToolbar from "@/components/px-next/PxToolbar.vue";
import PxMenu from "@/components/px-next/PxMenu.vue";
import PxTable from "@/components/px-next/PxTable.vue";
import PxTag from "@/components/px-next/PxTag.vue";
import PxBadge from "@/components/px-next/PxBadge.vue";
import PxKebab from "@/components/px-next/PxKebab.vue";
import PxPagination from "@/components/px-next/PxPagination.vue";
import PxAlert from "@/components/px-next/PxAlert.vue";
import PxEmptyState from "@/components/px-next/PxEmptyState.vue";
import PxModal from "@/components/px-next/PxModal.vue";
import * as XLSX from "xlsx";
import PxProductCell from "./widgets/PxProductCell.vue";
import ProductFilterPanel from "./widgets/ProductFilterPanel.vue";
import { adaptProducts } from "./adapter";
import { makeProductFormatters } from "./format";

const EMPTY_FILTERS = { code: "", name: "", category: "", brand: "", warehouse: "", status: "" };

// key de columna px-next  ->  campo real de ordenación del backend
const SORT_MAP = { product: "name", cost: "cost", price: "price", category: "category_id", brand: "brand_id" };

const EMPTY_CONFIRM = {
  open: false, busy: false, mode: null, id: null,
  title: "", body: "", cta: "", icon: "", tone: "danger"
};

export default {
  name: "ProductsListNext",
  metaInfo: { title: "Productos" },
  components: {
    PxPageHeader, PxButton, PxToolbar, PxMenu, PxTable, PxTag, PxBadge, PxKebab,
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
      confirm: { ...EMPTY_CONFIRM },
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
    exportMenu() {
      return [
        { key: "xlsx", label: "Excel (.xlsx)", icon: "file-spreadsheet" },
        { key: "pdf", label: "PDF", icon: "file-text" }
      ];
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
    toast(variant, msg, title) {
      if (this.$bvToast) this.$bvToast.toast(msg, { title: title || "Productos", variant, solid: true });
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
        // si la página quedó fuera de rango (p. ej. tras filtrar/eliminar), reencuadra
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
      if (this.can("products_add")) items.push({ key: "duplicate", label: "Duplicar", icon: "copy" });
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
      if (item.key === "duplicate") return this.askDuplicate(row);
      if (item.key === "delete") return this.askDeleteRow(row);
    },

    // ---- confirmaciones ---------------------------------------------------
    askDeleteRow(row) {
      this.confirm = {
        ...EMPTY_CONFIRM,
        open: true, mode: "row", id: row.id,
        title: "Eliminar producto",
        body: `Se eliminará «${row.name}» (${row.code}).`,
        cta: "Eliminar", icon: "trash-2", tone: "danger"
      };
    },
    askDeleteSelection() {
      this.confirm = {
        ...EMPTY_CONFIRM,
        open: true, mode: "selection", id: null,
        title: "Eliminar selección",
        body: `Se eliminarán ${this.selectedIds.length} producto(s) seleccionado(s).`,
        cta: "Eliminar", icon: "trash-2", tone: "danger"
      };
    },
    askDuplicate(row) {
      this.confirm = {
        ...EMPTY_CONFIRM,
        open: true, mode: "duplicate", id: row.id,
        title: "Duplicar producto",
        body: `Se abrirá el formulario de alta con los datos de «${row.name}» precargados.`,
        cta: "Duplicar", icon: "copy", tone: "primary"
      };
    },
    async runConfirm(close) {
      const c = this.confirm;
      if (c.busy) return;

      if (c.mode === "duplicate") {
        close();
        this.$router.push({ name: "store_product", query: { duplicate: c.id } });
        return;
      }

      this.confirm.busy = true;
      try {
        if (c.mode === "row") {
          await window.axios.delete("products/" + c.id);
        } else if (c.mode === "selection") {
          await window.axios.post("products/delete/by_selection", { selectedIds: this.selectedIds });
        }
        this.confirm = { ...EMPTY_CONFIRM };
        this.selectedIds = [];
        this.toast("success", "Se eliminó correctamente.", "Productos");
        this.fetch();
      } catch (e) {
        this.confirm.busy = false;
        const msg =
          (e && e.response && e.response.data && (e.response.data.message || e.response.data.error)) ||
          (e && e.message) || "No se pudo completar la eliminación.";
        this.toast("danger", msg, "No se eliminó");
      }
    },

    // ---- exportación cliente (solo lectura, sin backend) ----------------
    onExport(item) {
      if (!item) return;
      if (item.key === "xlsx") return this.exportXlsx(this.exportData(this.rows), "productos");
      if (item.key === "pdf") return this.exportPdf(this.rows);
    },
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
        this.toast("info", "No hay filas para exportar.", "Exportar");
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
        this.toast("danger", "No se pudo generar el archivo.", "Exportar");
      }
    },
    async exportPdf(list) {
      const data = this.exportData(list);
      if (!data.length) {
        this.toast("info", "No hay filas para exportar.", "Exportar");
        return;
      }
      try {
        const [{ default: jsPDF }, { default: autoTable }] = await Promise.all([
          import(/* webpackChunkName: "jspdf" */ "jspdf"),
          import(/* webpackChunkName: "jspdf" */ "jspdf-autotable")
        ]);
        const pdf = new jsPDF("p", "pt");
        const headers = ["Tipo", "Nombre", "Código", "Categoría", "Costo", "Precio", "Unidad", "Existencias"];
        const body = data.map(p => [p.type, p.name, p.code, p.category, p.cost, p.price, p.unit, p.stock]);
        const marginX = 40;

        autoTable(pdf, {
          head: [headers],
          body,
          startY: 90,
          theme: "striped",
          margin: { left: marginX, right: marginX },
          styles: { fontSize: 9, cellPadding: 4, halign: "left", textColor: 33 },
          headStyles: { fontStyle: "bold", fillColor: [63, 81, 181], textColor: 255 },
          alternateRowStyles: { fillColor: [245, 247, 250] },
          columnStyles: { 4: { halign: "right" }, 5: { halign: "right" }, 7: { halign: "right" } },
          didDrawPage: d => {
            const pageW = pdf.internal.pageSize.getWidth();
            const pageH = pdf.internal.pageSize.getHeight();
            pdf.setFillColor(63, 81, 181);
            pdf.rect(0, 0, pageW, 56, "F");
            pdf.setTextColor(255);
            pdf.setFont(undefined, "bold");
            pdf.setFontSize(16);
            pdf.text("Listado de productos", marginX, 36);
            pdf.setTextColor(33);
            pdf.setFont(undefined, "normal");
            pdf.setFontSize(8);
            const pn = `${d.pageNumber} / ${pdf.internal.getNumberOfPages()}`;
            pdf.text(pn, pageW - marginX, pageH - 14, { align: "right" });
          }
        });
        pdf.save("Listado_de_productos.pdf");
      } catch (e) {
        this.toast("danger", "No se pudo generar el PDF.", "Exportar");
      }
    }
  }
};
</script>

<style lang="scss" src="@/assets/styles/sass/px-next/production.scss"></style>

<style lang="scss" scoped>
.pxp { min-height: 100%; background: var(--pxn-bg); padding: var(--pxn-space-8) var(--pxn-space-9) var(--pxn-space-11); }
@media (max-width: 620px) { .pxp { padding: var(--pxn-space-6) var(--pxn-space-5) var(--pxn-space-10); } }
.pxp__denied { padding: var(--pxn-space-12) 0; }
.pxp__alert { margin-top: var(--pxn-space-5); }
.pxp__pad { padding: var(--pxn-space-6) 0; }

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
