<template>
  <!-- NEW FEATURE - SAFE ADDITION -->
  <div class="px-next pxac">
    <px-page-header :title="$t('Chart_of_Accounts_Title')" :subtitle="$t('Chart_of_Accounts_Subtitle')">
      <template #actions>
        <px-button variant="secondary" icon="upload" @click="openImport">{{ $t('Import_Chart_of_Accounts') }}</px-button>
        <px-button variant="primary" icon="plus" @click="openCreate">{{ $t('Add') }}</px-button>
      </template>
    </px-page-header>

    <px-toolbar
      :search="search"
      :search-placeholder="$t('Search_this_table')"
      @update:search="onSearchInput"
    />

    <div v-if="isLoading" class="pxac__pad">
      <px-skeleton variant="table" :rows="10" :columns="5" />
    </div>

    <template v-else>
      <div class="pxac__tablewrap">
        <px-table
          v-if="rows.length"
          :columns="columns"
          :rows="rows"
          row-key="id"
          :sort-key="serverParams.sort.field"
          :sort-dir="serverParams.sort.type"
          has-row-actions
          @sort="onSort"
        >
          <template #cell-parent="{ row }">{{ parentName(row.parent_id) }}</template>
          <template #cell-active="{ row }">
            <px-badge :tone="row.is_active ? 'success' : 'neutral'">{{ row.is_active ? $t('Yes') : $t('No') }}</px-badge>
          </template>
          <template #row-actions="{ row }">
            <px-kebab :items="rowActions" @select="onRowAction(row, $event)" />
          </template>
        </px-table>

        <px-empty-state
          v-else
          icon="database"
          :title="$t('No_accounts_yet') || 'Sin cuentas todavía'"
          :description="$t('Chart_of_Accounts_Subtitle')"
        >
          <px-button variant="primary" icon="plus" size="sm" @click="openCreate">{{ $t('Add') }}</px-button>
        </px-empty-state>
      </div>

      <px-pagination
        v-if="rows.length"
        :page="serverParams.page"
        :per-page="Number(limit)"
        :total="Number(totalRows) || 0"
        @update:page="onPage"
        @update:perPage="onLimit"
      />
    </template>

    <!-- Create / Edit Modal -->
    <px-modal v-model="showModal" size="md" :title="editing ? $t('Edit_Account') : $t('New_Account')">
      <div class="pxac__formgrid">
        <px-field :label="$t('Code')" class="pxac__wide">
          <template #default="{ id }"><px-input :id="id" v-model.trim="form.code" :placeholder="$t('Example_Code')" /></template>
        </px-field>
        <px-field :label="$t('Name')" class="pxac__wide">
          <template #default="{ id }"><px-input :id="id" v-model.trim="form.name" :placeholder="$t('Example_Name')" /></template>
        </px-field>
        <px-field :label="$t('Type')">
          <template #default="{ id }">
            <vs-px :input-id="id" v-model="form.type" :reduce="o => o.value" :placeholder="$t('Select_Type')"
              :options="typeOptions" />
          </template>
        </px-field>
        <px-field :label="$t('Parent')">
          <template #default="{ id }">
            <vs-px :input-id="id" v-model="form.parent_id" :reduce="o => o.value" :placeholder="$t('None')"
              :options="parentOptions" />
          </template>
        </px-field>
        <px-field :label="$t('Status')" class="pxac__wide">
          <template #default="{ id }">
            <vs-px :input-id="id" v-model.number="form.is_active" :reduce="o => o.value" :clearable="false"
              :options="[{ label: $t('Active'), value: 1 }, { label: $t('Inactive'), value: 0 }]" />
          </template>
        </px-field>
      </div>

      <template #footer="{ close }">
        <div class="pxac__actionbar">
          <px-button variant="secondary" :disabled="btnLoading" @click="close">{{ $t('Cancel') }}</px-button>
          <px-button variant="primary" icon="check" :loading="btnLoading" :disabled="btnLoading || !canSave" @click="save">
            {{ btnLoading ? $t('Saving') : $t('Save') }}
          </px-button>
        </div>
      </template>
    </px-modal>

    <!-- Import CSV Modal -->
    <px-modal v-model="showImport" size="md" :title="$t('Import_Chart_of_Accounts')">
      <div class="pxac__import">
        <input type="file" accept=".csv,text/csv" @change="onImportFileChange" />
        <p class="pxac__hint">{{ $t('Only_CSV_allowed') }}</p>
      </div>

      <px-alert v-if="importErrors.length" tone="danger" :title="$t('Error')" class="pxac__importerr">
        <ul class="pxac__errlist">
          <li v-for="(err, i) in importErrors" :key="i">{{ err }}</li>
        </ul>
      </px-alert>

      <table class="pxac__reqtbl">
        <tbody>
          <tr><td>{{ $t('Code') }}</td><td><px-badge tone="success">{{ $t('Field_is_required') }}</px-badge></td></tr>
          <tr><td>{{ $t('Name') }}</td><td><px-badge tone="success">{{ $t('Field_is_required') }}</px-badge></td></tr>
          <tr><td>{{ $t('Type') }} (asset, liability, equity, income, expense)</td><td><px-badge tone="success">{{ $t('Field_is_required') }}</px-badge></td></tr>
          <tr><td>{{ $t('Parent_Code') }}</td><td><px-badge tone="neutral">{{ $t('Optional') }}</px-badge></td></tr>
          <tr><td>{{ $t('Active') }} (1/0)</td><td><px-badge tone="neutral">{{ $t('Optional') }}</px-badge></td></tr>
        </tbody>
      </table>

      <template #footer="{ close }">
        <div class="pxac__actionbar">
          <px-button variant="link" icon="download" @click="downloadExample">{{ $t('Download_exemple') }}</px-button>
          <px-button variant="secondary" :disabled="importLoading" @click="close">{{ $t('Cancel') }}</px-button>
          <px-button variant="primary" icon="check" :loading="importLoading" :disabled="importLoading || !importFile" @click="submitImport">
            {{ $t('submit') }}
          </px-button>
        </div>
      </template>
    </px-modal>
  </div>
</template>

<script>
import NProgress from "nprogress";
import PxPageHeader from "@/components/px-next/PxPageHeader.vue";
import PxToolbar from "@/components/px-next/PxToolbar.vue";
import PxTable from "@/components/px-next/PxTable.vue";
import PxPagination from "@/components/px-next/PxPagination.vue";
import PxButton from "@/components/px-next/PxButton.vue";
import PxKebab from "@/components/px-next/PxKebab.vue";
import PxBadge from "@/components/px-next/PxBadge.vue";
import PxField from "@/components/px-next/PxField.vue";
import PxInput from "@/components/px-next/PxInput.vue";
import PxModal from "@/components/px-next/PxModal.vue";
import PxAlert from "@/components/px-next/PxAlert.vue";
import PxEmptyState from "@/components/px-next/PxEmptyState.vue";
import VsPx from "@/views/app/products/next/edit/VsPx.vue";

export default {
  name: "ChartOfAccountsV2",
  components: {
    PxPageHeader, PxToolbar, PxTable, PxPagination, PxButton, PxKebab, PxBadge,
    PxField, PxInput, PxModal, PxAlert, PxEmptyState, "vs-px": VsPx
  },
  data() {
    return {
      _searchTimer: null,
      isLoading: true,
      rows: [],
      totalRows: "",
      serverParams: {
        columnFilters: {},
        sort: { field: "code", type: "asc" },
        page: 1,
        perPage: 10
      },
      search: "",
      limit: "10",
      showModal: false,
      editing: false,
      form: { id: null, code: "", name: "", type: "", parent_id: null, is_active: 1 },
      confirmOpen: false,
      toDelete: null,
      btnLoading: false,
      showImport: false,
      importFile: null,
      importErrors: [],
      importLoading: false,
    };
  },
  computed: {
    rowActions() {
      return [
        { key: "edit", label: this.$t("Edit"), icon: "pencil" },
        { key: "delete", label: this.$t("Delete"), icon: "x", tone: "danger" }
      ];
    },
    typeOptions() {
      return [
        { label: this.$t('Asset'), value: 'asset' },
        { label: this.$t('Liability'), value: 'liability' },
        { label: this.$t('Equity'), value: 'equity' },
        { label: this.$t('Income'), value: 'income' },
        { label: this.$t('Expense'), value: 'expense' }
      ];
    },
    parentOptions() {
      return [{ label: this.$t('None'), value: null }].concat(
        (this.rows || []).map(p => ({ label: `${p.code} — ${p.name}`, value: p.id }))
      );
    },
    columns() {
      return [
        { key: 'code', label: this.$t('Code'), sortable: true, strong: true },
        { key: 'name', label: this.$t('Name'), sortable: true },
        { key: 'parent', label: this.$t('Parent') },
        { key: 'type', label: this.$t('Type'), sortable: true },
        { key: 'active', label: this.$t('Active') },
      ];
    },
    canSave() { return this.form.code && this.form.name && this.form.type; }
  },
  created() {
    this.Get_Coa(1);
  },
  methods: {
    updateParams(newProps) { this.serverParams = Object.assign({}, this.serverParams, newProps); },
    onSearchInput(v) {
      this.search = v;
      if (this._searchTimer) clearTimeout(this._searchTimer);
      this._searchTimer = setTimeout(() => { this.updateParams({ page: 1 }); this.Get_Coa(1); }, 350);
    },
    onPage(p) { if (this.serverParams.page !== p) { this.updateParams({ page: p }); this.Get_Coa(p); } },
    onLimit(v) { if (this.limit !== String(v)) { this.limit = String(v); this.updateParams({ page: 1, perPage: Number(v) }); this.Get_Coa(1); } },
    onSort({ key, dir }) {
      this.updateParams({ sort: { type: dir, field: key } });
      this.Get_Coa(this.serverParams.page);
    },
    onRowAction(row, item) {
      const k = item && item.key;
      if (k === "edit") this.openEdit(row);
      else if (k === "delete") this.confirmRemove(row);
    },
    async Get_Coa(page) {
      NProgress.start(); NProgress.set(0.1);
      axios.get(
        "/accounting/v2/coa?page=" + page +
        "&SortField=" + this.serverParams.sort.field +
        "&SortType=" + this.serverParams.sort.type +
        "&search=" + this.search +
        "&limit=" + this.limit
      )
      .then(({data}) => {
        this.rows = data && data.data ? data.data : [];
        this.totalRows = data && (data.totalRows ?? 0);
        NProgress.done();
        this.isLoading = false;
      })
      .catch(() => { NProgress.done(); this.isLoading = false; });
    },
    parentName(id) {
      if (!id) return this.$t('None');
      const p = this.rows.find(x => x.id === id);
      return p ? (p.code + ' — ' + p.name) : '-';
    },
    openCreate() {
      this.editing = false;
      this.form = { id: null, code: "", name: "", type: "", parent_id: null, is_active: 1 };
      this.btnLoading = false;
      this.showModal = true;
    },
    openEdit(row) {
      this.editing = true;
      this.form = { id: row.id, code: row.code, name: row.name, type: row.type, parent_id: row.parent_id, is_active: row.is_active ? 1 : 0 };
      this.btnLoading = false;
      this.showModal = true;
    },
    closeModal() { this.btnLoading = false; this.showModal = false; },
    openImport() {
      this.importFile = null;
      this.importErrors = [];
      this.importLoading = false;
      this.showImport = true;
    },
    closeImport() { if (!this.importLoading) this.showImport = false; },
    downloadExample() {
      window.open('/import/exemples/chart_of_accounts.csv', '_blank', 'noopener');
    },
    onImportFileChange(e) {
      this.importFile = (e.target.files && e.target.files[0]) || null;
      this.importErrors = [];
    },
    async submitImport() {
      if (!this.importFile) return;
      this.importLoading = true;
      this.importErrors = [];
      const formData = new FormData();
      formData.append('file', this.importFile);
      try {
        await axios.post('/accounting/v2/coa/import', formData, { headers: { 'Content-Type': 'multipart/form-data' } });
        this.makeToast('success', this.$t('Successfully_Imported'), this.$t('Success'));
        this.showImport = false;
        this.Get_Coa(this.serverParams.page);
      } catch (e) {
        const errs = e.response && e.response.data && e.response.data.errors;
        this.importErrors = Array.isArray(errs) && errs.length ? errs : [this.$t('Operation_Failed')];
      } finally {
        this.importLoading = false;
      }
    },
    async save() {
      try {
        this.btnLoading = true;
        if (this.editing) {
          await axios.put(`/accounting/v2/coa/${this.form.id}`, this.form);
          this.makeToast('success', this.$t('Account_Updated'), this.$t('Success'));
        } else {
          await axios.post(`/accounting/v2/coa`, this.form);
          this.makeToast('success', this.$t('Account_Created'), this.$t('Success'));
        }
        this.showModal = false;
        this.Get_Coa(this.serverParams.page);
      } catch (e) { this.makeToast('danger', this.$t('Operation_Failed'), this.$t('Error')); }
      finally { this.btnLoading = false; }
    },
    confirmRemove(row) {
      this.$swal({ title: this.$t('Delete'), text: this.$t('Delete_Account_Warning'), type: 'warning', showCancelButton: true, confirmButtonText: this.$t('Delete') }).then(result => { if (result.value) this.removeRow(row); });
    },
    async removeRow(row) {
      try { await axios.delete(`/accounting/v2/coa/${row.id}`); this.makeToast('success', this.$t('Deleted_Successfully'), this.$t('Success')); this.Get_Coa(this.serverParams.page); }
      catch (e) { this.makeToast('danger', this.$t('Delete_Failed'), this.$t('Error')); }
    },
    makeToast(variant, msg, title) {
      this.$root.$bvToast.toast(msg, { title, variant, solid: true });
    }
  }
};
</script>

<style lang="scss" src="@/assets/styles/sass/px-next/production.scss"></style>

<style lang="scss" scoped>
.pxac { min-height: 100%; background: var(--pxn-bg); padding: var(--pxn-space-8) var(--pxn-space-9) var(--pxn-space-9); }
@media (max-width: 620px) { .pxac { padding: var(--pxn-space-6) var(--pxn-space-5); } }
.pxac__pad { padding: var(--pxn-space-6) 0; }
.pxac__tablewrap { margin-top: var(--pxn-space-5); }
.pxac__formgrid { display: grid; grid-template-columns: repeat(2, minmax(0, 1fr)); gap: var(--pxn-space-4) var(--pxn-space-5); }
@media (max-width: 520px) { .pxac__formgrid { grid-template-columns: minmax(0, 1fr); } }
.pxac__wide { grid-column: 1 / -1; }
.pxac__actionbar { display: flex; justify-content: flex-end; gap: var(--pxn-space-3); align-items: center; }
.pxac__import { display: flex; flex-direction: column; gap: var(--pxn-space-2); }
.pxac__hint { font-size: var(--pxn-fs-xs); color: var(--pxn-ink-3); }
.pxac__importerr { margin-top: var(--pxn-space-4); }
.pxac__errlist { margin: 0; padding-left: var(--pxn-space-6); }
.pxac__reqtbl { width: 100%; border-collapse: collapse; margin-top: var(--pxn-space-4); font-size: var(--pxn-fs-sm); }
.pxac__reqtbl td { padding: var(--pxn-space-2) var(--pxn-space-3); border-bottom: 1px solid var(--pxn-border); color: var(--pxn-ink-2); }
.pxac__reqtbl tr:last-child td { border-bottom: 0; }
.pxac__reqtbl td:last-child { text-align: right; white-space: nowrap; }
.pxac__confirmtext { font-size: var(--pxn-fs-body); color: var(--pxn-ink-2); }
</style>
