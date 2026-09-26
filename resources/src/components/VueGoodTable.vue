<template>
  <div class="vgt-wrap" :class="{ rtl }">
    <div v-if="searchOptions.enabled || $slots['table-actions'] || (selectOptions.enabled && selectedRows.length)" class="vgt-global-search vgt-clearfix">
      <div v-if="searchOptions.enabled" class="vgt-global-search__input vgt-pull-left">
        <input
          v-model="searchTerm"
          type="text"
          class="vgt-input"
          :placeholder="searchOptions.placeholder || 'Search table'"
          @input="onSearchInput"
        />
      </div>
      <div v-if="selectOptions.enabled && selectedRows.length" class="vgt-selected-row-actions vgt-pull-left">
        <slot name="selected-row-actions" />
        <a href="#" @click.prevent="clearSelection">{{ selectOptions.clearSelectionText || 'Clear selection' }}</a>
      </div>
      <div class="vgt-global-search__actions vgt-pull-right">
        <slot name="table-actions" />
      </div>
    </div>

    <div class="vgt-inner-wrap">
      <div class="vgt-responsive">
        <table class="vgt-table" :class="styleClass">
          <thead>
            <tr>
              <th v-if="selectOptions.enabled" class="vgt-checkbox-col">
                <input type="checkbox" :checked="allSelected" @change="toggleSelectAll" />
              </th>
              <th
                v-for="column in visibleColumns"
                :key="column.field"
                :class="[column.thClass, { sortable: column.sortable !== false, [sortClassFor(column)]: true }]"
                @click="column.sortable !== false && toggleSort(column)"
              >
                {{ column.label }}
                <button v-if="column.sortable !== false" type="button" tabindex="-1" />
              </th>
            </tr>
          </thead>
          <tbody>
            <template v-for="(row, rIndex) in pagedRows" :key="rowKey(row, rIndex)">
              <tr v-if="isGroupRow(row) && groupOptions.headerPosition !== 'bottom'" class="vgt-row-header">
                <th :colspan="totalColspan">{{ row.label }}</th>
              </tr>
              <template v-if="isGroupRow(row)">
                <tr v-for="(child, cIndex) in row.children" :key="rowKey(child, cIndex)" :class="rowClass(child)">
                  <td v-if="selectOptions.enabled" class="vgt-checkbox-col">
                    <input type="checkbox" :checked="isSelected(child)" @change="toggleSelect(child)" />
                  </td>
                  <td v-for="column in visibleColumns" :key="column.field" :class="column.tdClass">
                    <slot name="table-row" :row="child" :column="column" :formattedRow="formattedRow(child)" :index="cIndex">
                      {{ formattedRow(child)[column.field] }}
                    </slot>
                  </td>
                </tr>
              </template>
              <tr v-else :class="rowClass(row)">
                <td v-if="selectOptions.enabled" class="vgt-checkbox-col">
                  <input type="checkbox" :checked="isSelected(row)" @change="toggleSelect(row)" />
                </td>
                <td v-for="column in visibleColumns" :key="column.field" :class="column.tdClass">
                  <slot name="table-row" :row="row" :column="column" :formattedRow="formattedRow(row)" :index="rIndex">
                    {{ formattedRow(row)[column.field] }}
                  </slot>
                </td>
              </tr>
              <tr v-if="isGroupRow(row) && groupOptions.headerPosition === 'bottom'" class="vgt-row-header">
                <th :colspan="totalColspan">{{ row.label }}</th>
              </tr>
            </template>
            <tr v-if="pagedRows.length === 0">
              <td :colspan="totalColspan" class="vgt-text-disabled" style="text-align:center;padding:1em;">
                <slot name="emptystate">No data available</slot>
              </td>
            </tr>
          </tbody>
        </table>
      </div>
    </div>

    <div v-if="paginationOptions.enabled" class="vgt-wrap__footer vgt-clearfix">
      <div class="footer__row-count vgt-pull-left">
        <span class="footer__row-count__label">{{ paginationOptions.rowsPerPageLabel || 'Rows per page:' }}</span>
        <select v-if="perPageDropdown.length" v-model.number="currentPerPage" class="footer__row-count__select" @change="onPerPageChange">
          <option v-for="n in perPageDropdown" :key="n" :value="n">{{ n }}</option>
        </select>
        <span v-else class="footer__row-count__select">{{ currentPerPage }}</span>
      </div>
      <div class="footer__navigation vgt-pull-right">
        <span class="footer__navigation__page-info">
          {{ rangeStart }} - {{ rangeEnd }} {{ paginationOptions.ofLabel || 'of' }} {{ totalRowsComputed }}
        </span>
        <button
          type="button"
          class="footer__navigation__page-btn"
          :class="{ disabled: currentPage <= 1 }"
          :disabled="currentPage <= 1"
          @click="goToPage(currentPage - 1)"
        >{{ paginationOptions.prevLabel || 'Prev' }}</button>
        <button
          type="button"
          class="footer__navigation__page-btn"
          :class="{ disabled: currentPage >= pageCount }"
          :disabled="currentPage >= pageCount"
          @click="goToPage(currentPage + 1)"
        >{{ paginationOptions.nextLabel || 'Next' }}</button>
      </div>
    </div>
  </div>
</template>

<script>
// Reemplaza el paquete `vue-good-table` (Vue 2, registrado globalmente vía `plugins/stocky.kit.js`) por un
// componente Vue 3 nativo de PRODEX, registrado igual como `<vue-good-table>`. Contrato auditado sobre las 86
// tablas reales (76 vistas, `/tmp/vgt_files.txt`): columns/rows/mode/totalRows/pagination-options/search-options/
// select-options/group-options/styleClass/row-style-class/rtl, eventos on-page-change/on-per-page-change/
// on-sort-change/on-search/on-selected-rows-change, slots #table-row/#table-actions/#selected-row-actions/
// #table-actions-bottom/#emptystate. `sort-options` (2 usos) y `#table-actions-bottom` (2 usos) no cambian el
// comportamiento por defecto de esta implementación y no se leen explícitamente.
// 74/86 tablas usan mode="remote" (el padre ya pagina/filtra/ordena vía la API — este componente solo dibuja
// `rows` tal cual y reenvía los eventos); las 7 restantes son mode local/ausente y este componente pagina/filtra/
// ordena internamente. `group-options` (11 vistas de reportes, todas remote) solo aparece cuando `row.children`
// es un array: se dibuja como fila de encabezado ("Row_header") + hijas, sin afectar el resto de las tablas.
export default {
  name: 'VueGoodTable',
  props: {
    columns: { type: Array, required: true },
    rows: { type: Array, default: () => [] },
    mode: { type: String, default: 'client' },
    totalRows: { type: Number, default: 0 },
    styleClass: { type: [String, Array, Object], default: 'vgt-table' },
    rowStyleClass: { type: [String, Function], default: null },
    rtl: { type: Boolean, default: false },
    searchOptions: { type: Object, default: () => ({ enabled: false }) },
    paginationOptions: { type: Object, default: () => ({ enabled: false }) },
    selectOptions: { type: Object, default: () => ({ enabled: false }) },
    groupOptions: { type: Object, default: () => ({ enabled: false }) },
    sortOptions: { type: Object, default: () => ({ enabled: true }) },
  },
  emits: ['on-page-change', 'on-per-page-change', 'on-sort-change', 'on-search', 'on-selected-rows-change'],
  data() {
    const initialPerPage = (this.paginationOptions && this.paginationOptions.perPage) || 10;
    return {
      searchTerm: '',
      sortField: null,
      sortType: null,
      currentPage: (this.paginationOptions && this.paginationOptions.setCurrentPage) || 1,
      currentPerPage: initialPerPage,
      selectedRows: [],
    };
  },
  computed: {
    isRemote() { return this.mode === 'remote'; },
    visibleColumns() { return this.columns.filter((c) => c.hidden !== true); },
    totalColspan() { return this.visibleColumns.length + (this.selectOptions.enabled ? 1 : 0); },
    perPageDropdown() { return Array.isArray(this.paginationOptions.perPageDropdown) ? this.paginationOptions.perPageDropdown : []; },
    // pipeline única para ambos modos: en remoto cada paso es no-op (el padre ya lo hizo) y se dibuja `rows` tal cual.
    searchedRows() {
      if (this.isRemote || !this.searchTerm) return this.rows;
      const term = this.searchTerm.toLowerCase();
      return this.rows.filter((row) => this.visibleColumns.some((c) => String(this.formattedRow(row)[c.field] ?? '').toLowerCase().includes(term)));
    },
    sortedRows() {
      if (this.isRemote || !this.sortField || !this.sortType) return this.searchedRows;
      const { sortField, sortType } = this;
      return [...this.searchedRows].sort((a, b) => {
        const av = a[sortField];
        const bv = b[sortField];
        if (av == null && bv == null) return 0;
        if (av == null) return sortType === 'asc' ? -1 : 1;
        if (bv == null) return sortType === 'asc' ? 1 : -1;
        if (av < bv) return sortType === 'asc' ? -1 : 1;
        if (av > bv) return sortType === 'asc' ? 1 : -1;
        return 0;
      });
    },
    totalRowsComputed() { return this.isRemote ? this.totalRows : this.sortedRows.length; },
    pageCount() { return Math.max(1, Math.ceil(this.totalRowsComputed / this.currentPerPage)); },
    pagedRows() {
      if (this.isRemote || !this.paginationOptions.enabled) return this.sortedRows;
      const start = (this.currentPage - 1) * this.currentPerPage;
      return this.sortedRows.slice(start, start + this.currentPerPage);
    },
    rangeStart() { return this.totalRowsComputed === 0 ? 0 : (this.currentPage - 1) * this.currentPerPage + 1; },
    rangeEnd() { return Math.min(this.currentPage * this.currentPerPage, this.totalRowsComputed); },
    allSelected() {
      const flat = this.flatPagedRows;
      return flat.length > 0 && flat.every((r) => this.isSelected(r));
    },
    flatPagedRows() {
      const out = [];
      this.pagedRows.forEach((row) => { this.isGroupRow(row) ? out.push(...row.children) : out.push(row); });
      return out;
    },
  },
  watch: {
    'paginationOptions.setCurrentPage'(v) { if (v) this.currentPage = v; },
  },
  methods: {
    formattedRow(row) {
      const out = { ...row };
      this.visibleColumns.forEach((c) => { out[c.field] = c.formatFn ? c.formatFn(row[c.field], row) : row[c.field]; });
      return out;
    },
    isGroupRow(row) { return this.groupOptions.enabled && row && Array.isArray(row.children); },
    rowKey(row, index) { return row && row.id != null ? row.id : index; },
    rowClass(row) {
      if (typeof this.rowStyleClass === 'function') return this.rowStyleClass(row);
      return this.rowStyleClass || '';
    },
    sortClassFor(column) {
      if (this.sortField !== column.field) return '';
      return this.sortType === 'asc' ? 'sorting-asc' : this.sortType === 'desc' ? 'sorting-desc' : '';
    },
    toggleSort(column) {
      if (this.sortField !== column.field) { this.sortField = column.field; this.sortType = 'asc'; }
      else if (this.sortType === 'asc') { this.sortType = 'desc'; }
      else { this.sortField = null; this.sortType = null; }
      this.$emit('on-sort-change', [{ field: this.sortField, type: this.sortType || 'none' }]);
    },
    onSearchInput() {
      this.currentPage = 1;
      this.$emit('on-search', { searchTerm: this.searchTerm });
    },
    goToPage(page) {
      const clamped = Math.min(Math.max(1, page), this.pageCount);
      if (clamped === this.currentPage) return;
      this.currentPage = clamped;
      this.$emit('on-page-change', { currentPage: clamped, currentPerPage: this.currentPerPage });
    },
    onPerPageChange() {
      this.currentPage = 1;
      this.$emit('on-per-page-change', { currentPage: 1, currentPerPage: this.currentPerPage });
    },
    isSelected(row) { return this.selectedRows.some((r) => r === row || (row && r && row.id != null && r.id === row.id)); },
    toggleSelect(row) {
      this.selectedRows = this.isSelected(row) ? this.selectedRows.filter((r) => r !== row && !(row && r.id != null && r.id === row.id)) : [...this.selectedRows, row];
      this.$emit('on-selected-rows-change', { selectedRows: this.selectedRows });
    },
    toggleSelectAll() {
      this.selectedRows = this.allSelected ? [] : [...this.flatPagedRows];
      this.$emit('on-selected-rows-change', { selectedRows: this.selectedRows });
    },
    clearSelection() {
      this.selectedRows = [];
      this.$emit('on-selected-rows-change', { selectedRows: this.selectedRows });
    },
  },
};
</script>
<style src="./vue-good-table.css"></style>
