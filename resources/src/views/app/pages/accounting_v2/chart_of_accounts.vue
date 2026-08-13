<template>
  <!-- NEW FEATURE - SAFE ADDITION -->
  <div class="main-content">
    <div class="d-flex align-items-center justify-content-between mb-3">
      <div>
        <h4 class="mb-1">{{ $t('Chart_of_Accounts_Title') }}</h4>
        <div class="text-muted small">{{ $t('Chart_of_Accounts_Subtitle') }}</div>
      </div>
    </div>
    <div v-if="isLoading" class="loading_page spinner spinner-primary mr-3"></div>
    <div class="card wrapper" v-if="!isLoading">
      <vue-good-table
        mode="remote"
        :columns="columns"
        :totalRows="totalRows"
        :rows="rows"
        @on-page-change="onPageChange"
        @on-per-page-change="onPerPageChange"
        @on-sort-change="onSortChange"
        @on-search="onSearch"
        :search-options="{ enabled: true, placeholder: $t('Search_this_table') }"
        :pagination-options="{ enabled: true, mode: 'records', nextLabel: $t('Next'), prevLabel: $t('Prev') }"
        styleClass="table-hover tableOne vgt-table"
      >
        <div slot="table-actions" class="mt-2 mb-3">
          <b-button @click="openCreate()" class="btn-rounded" variant="btn btn-primary btn-icon m-1">
            <lucide-icon name="plus" />
            {{ $t('Add') }}
          </b-button>
          <b-button @click="openImport()" class="btn-rounded" variant="btn btn-outline-primary btn-icon m-1">
            <lucide-icon name="upload" />
            {{ $t('Import_Chart_of_Accounts') }}
          </b-button>
        </div>

        <template slot="table-row" slot-scope="props">
          <span v-if="props.column.field == 'parent'">{{ parentName(props.row.parent_id) }}</span>
          <span v-else-if="props.column.field == 'active'">
            <span class="badge" :class="props.row.is_active ? 'badge-success' : 'badge-secondary'">{{ props.row.is_active ? $t('Yes') : $t('No') }}</span>
          </span>
          <span v-else-if="props.column.field == 'actions'">
            <a @click="openEdit(props.row)" :title="$t('Edit')" v-b-tooltip.hover>
              <lucide-icon class="text-25 text-success cursor-pointer" name="pencil" />
            </a>
            <a :title="$t('Delete')" v-b-tooltip.hover @click="confirmRemove(props.row)">
              <lucide-icon class="text-25 text-danger cursor-pointer" name="x" />
            </a>
          </span>
        </template>
      </vue-good-table>
    </div>

    <!-- Create / Edit Modal -->
    <div class="modal fade" tabindex="-1" role="dialog" :class="{ show: showModal }" :style="{ display: showModal ? 'block' : 'none' }">
      <div class="modal-dialog" role="document">
        <div class="modal-content">
          <div class="modal-header">
            <h5 class="modal-title">{{ editing ? $t('Edit_Account') : $t('New_Account') }}</h5>
            <button type="button" class="close" @click="closeModal"><span>&times;</span></button>
          </div>
          <div class="modal-body">
            <div class="form-group">
              <label>{{ $t('Code') }}</label>
              <input v-model.trim="form.code" class="form-control" :placeholder="$t('Example_Code')" />
            </div>
            <div class="form-group">
              <label>{{ $t('Name') }}</label>
              <input v-model.trim="form.name" class="form-control" :placeholder="$t('Example_Name')" />
            </div>
            <div class="form-row">
              <div class="form-group col-md-6">
                <label>{{ $t('Type') }}</label>
                <select v-model="form.type" class="form-control">
                  <option disabled value="">{{ $t('Select_Type') }}</option>
                  <option value="asset">{{ $t('Asset') }}</option>
                  <option value="liability">{{ $t('Liability') }}</option>
                  <option value="equity">{{ $t('Equity') }}</option>
                  <option value="income">{{ $t('Income') }}</option>
                  <option value="expense">{{ $t('Expense') }}</option>
                </select>
              </div>
              <div class="form-group col-md-6">
                <label>{{ $t('Parent') }}</label>
                <select v-model="form.parent_id" class="form-control">
                  <option :value="null">{{ $t('None') }}</option>
                  <option v-for="p in rows" :key="p.id" :value="p.id">{{ p.code }} — {{ p.name }}</option>
                </select>
              </div>
            </div>
            <div class="form-group">
              <label>{{ $t('Status') }}</label>
              <select v-model.number="form.is_active" class="form-control">
                <option :value="1">{{ $t('Active') }}</option>
                <option :value="0">{{ $t('Inactive') }}</option>
              </select>
            </div>
          </div>
          <div class="modal-footer">
            <button type="button" class="btn btn-outline-secondary" @click="closeModal" :disabled="btnLoading">{{ $t('Cancel') }}</button>
            <button type="button" class="btn btn-primary" @click="save" :disabled="btnLoading || !canSave">
              <span v-if="btnLoading" class="spinner-border spinner-border-sm mr-2"></span>
              <span>{{ btnLoading ? $t('Saving') : $t('Save') }}</span>
            </button>
          </div>
        </div>
      </div>
    </div>

    <!-- Import CSV Modal -->
    <div class="modal fade" tabindex="-1" role="dialog" :class="{ show: showImport }" :style="{ display: showImport ? 'block' : 'none' }">
      <div class="modal-dialog" role="document">
        <div class="modal-content">
          <div class="modal-header">
            <h5 class="modal-title">{{ $t('Import_Chart_of_Accounts') }}</h5>
            <button type="button" class="close" @click="closeImport"><span>&times;</span></button>
          </div>
          <div class="modal-body">
            <div class="form-group">
              <input type="file" accept=".csv,text/csv" @change="onImportFileChange" />
              <small class="form-text text-muted">{{ $t('Only_CSV_allowed') }}</small>
            </div>

            <div v-if="importErrors.length" class="alert alert-danger" style="max-height: 220px; overflow-y: auto;">
              <ul class="mb-0 pl-3">
                <li v-for="(err, i) in importErrors" :key="i">{{ err }}</li>
              </ul>
            </div>

            <table class="table table-bordered table-sm mt-3 mb-0">
              <tbody>
                <tr>
                  <td>{{ $t('Code') }}</td>
                  <th><span class="badge badge-outline-success">{{ $t('Field_is_required') }}</span></th>
                </tr>
                <tr>
                  <td>{{ $t('Name') }}</td>
                  <th><span class="badge badge-outline-success">{{ $t('Field_is_required') }}</span></th>
                </tr>
                <tr>
                  <td>{{ $t('Type') }} (asset, liability, equity, income, expense)</td>
                  <th><span class="badge badge-outline-success">{{ $t('Field_is_required') }}</span></th>
                </tr>
                <tr>
                  <td>{{ $t('Parent_Code') }}</td>
                  <th><span class="badge badge-outline-secondary">{{ $t('Optional') }}</span></th>
                </tr>
                <tr>
                  <td>{{ $t('Active') }} (1/0)</td>
                  <th><span class="badge badge-outline-secondary">{{ $t('Optional') }}</span></th>
                </tr>
              </tbody>
            </table>
          </div>
          <div class="modal-footer">
            <b-button :href="'/import/exemples/chart_of_accounts.csv'" variant="info" size="sm">{{ $t('Download_exemple') }}</b-button>
            <button type="button" class="btn btn-outline-secondary" @click="closeImport" :disabled="importLoading">{{ $t('Cancel') }}</button>
            <button type="button" class="btn btn-primary" @click="submitImport" :disabled="importLoading || !importFile">
              <span v-if="importLoading" class="spinner-border spinner-border-sm mr-2"></span>
              <span>{{ $t('submit') }}</span>
            </button>
          </div>
        </div>
      </div>
    </div>

    <!-- Delete Confirm Modal -->
    <div class="modal fade" tabindex="-1" role="dialog" :class="{ show: confirmOpen }" :style="{ display: confirmOpen ? 'block' : 'none' }">
      <div class="modal-dialog" role="document">
        <div class="modal-content">
          <div class="modal-header">
            <h5 class="modal-title">{{ $t('Delete_Account_Title') }}</h5>
            <button type="button" class="close" @click="confirmOpen=false"><span>&times;</span></button>
          </div>
          <div class="modal-body">
            <p class="mb-0">
              {{ $t('Delete_Account_Question') }}
              <strong>{{ toDelete ? `${toDelete.code} — ${toDelete.name}` : '' }}</strong>?
            </p>
          </div>
          <div class="modal-footer">
            <button type="button" class="btn btn-outline-secondary" @click="confirmOpen=false">{{ $t('Cancel') }}</button>
            <button type="button" class="btn btn-danger" @click="remove()">{{ $t('Delete') }}</button>
          </div>
        </div>
      </div>
    </div>
  </div>
</template>

<script>
import NProgress from "nprogress";

export default {
  name: "ChartOfAccountsV2",
  data() {
    return {
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
    columns() {
      return [
        { label: this.$t('Code'), field: 'code', tdClass: 'text-left', thClass: 'text-left' },
        { label: this.$t('Name'), field: 'name', tdClass: 'text-left', thClass: 'text-left' },
        { label: this.$t('Parent'), field: 'parent', sortable: false, tdClass: 'text-left', thClass: 'text-left' },
        { label: this.$t('Type'), field: 'type', tdClass: 'text-left', thClass: 'text-left' },
        { label: this.$t('Active'), field: 'active', sortable: false, tdClass: 'text-center', thClass: 'text-center' },
        { label: this.$t('Action'), field: 'actions', sortable: false, tdClass: 'text-left', thClass: 'text-left' },
      ];
    },
    canSave() { return this.form.code && this.form.name && this.form.type; }
  },
  created() {
    this.Get_Coa(1);
  },
  methods: {
    updateParams(newProps) { this.serverParams = Object.assign({}, this.serverParams, newProps); },
    onPageChange({ currentPage }) { if (this.serverParams.page !== currentPage) { this.updateParams({ page: currentPage }); this.Get_Coa(currentPage); } },
    onPerPageChange({ currentPerPage }) { if (this.limit !== currentPerPage) { this.limit = currentPerPage; this.updateParams({ page: 1, perPage: currentPerPage }); this.Get_Coa(1); } },
    onSortChange(params) {
      if (!params || !params.length) return;
      this.updateParams({ sort: { type: params[0].type, field: params[0].field } });
      this.Get_Coa(this.serverParams.page);
    },
    onSearch(value) { this.search = value.searchTerm; this.Get_Coa(this.serverParams.page); },
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

<style scoped>
.sortable { cursor: pointer; }
.modal { background: rgba(0,0,0,.35); }
</style>



