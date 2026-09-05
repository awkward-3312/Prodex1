<template>
  <div class="px-next pxcm">
    <px-page-header :title="$t('Commission_Programs')" :breadcrumbs="[{ label: $t('Commissions') }, { label: $t('Commission_Programs') }]">
      <template #actions>
        <px-button
          v-if="currentUserPermissions && currentUserPermissions.includes('commissions_add')"
          variant="primary" icon="plus" @click="openModal()"
        >{{ $t('Add') }}</px-button>
      </template>
    </px-page-header>

    <px-toolbar
      :search="search"
      :search-placeholder="$t('Search_this_table')"
      @update:search="onSearchInput"
    />

    <div v-if="isLoading" class="pxcm__pad">
      <px-skeleton variant="table" :rows="8" :columns="6" />
    </div>

    <template v-else>
      <div class="pxcm__tablewrap">
        <px-table
          v-if="programs.length"
          :columns="columns"
          :rows="programs"
          row-key="id"
          :sort-key="serverParams.sort.field"
          :sort-dir="serverParams.sort.type"
          :has-row-actions="canRowActions"
          @sort="onSort"
        >
          <template #cell-is_active="{ row }">
            <px-badge :tone="row.is_active ? 'success' : 'neutral'">{{ row.is_active ? $t('Active') : $t('Inactive') }}</px-badge>
          </template>
          <template #cell-valid_from="{ row }">{{ row.valid_from ? formatDate(row.valid_from) : '—' }}</template>
          <template #cell-valid_to="{ row }">{{ row.valid_to ? formatDate(row.valid_to) : '—' }}</template>
          <template #row-actions="{ row }">
            <px-kebab v-if="canRowActions" :items="rowActions" @select="onRowAction(row, $event)" />
          </template>
        </px-table>

        <px-empty-state
          v-else
          icon="award"
          :title="$t('No_commission_programs_yet') || 'Sin programas de comisión todavía'"
        >
          <px-button
            v-if="currentUserPermissions && currentUserPermissions.includes('commissions_add')"
            variant="primary" icon="plus" size="sm" @click="openModal()"
          >{{ $t('Add') }}</px-button>
        </px-empty-state>
      </div>

      <px-pagination
        v-if="programs.length"
        :page="serverParams.page"
        :per-page="Number(limit)"
        :total="Number(totalRows) || 0"
        @update:page="onPage"
        @update:perPage="onLimit"
      />
    </template>

    <px-modal v-model="modalOpen" size="md" :title="editMode ? $t('Edit') : $t('Add')">
      <b-form @submit.prevent="submit">
        <px-field :label="$t('Name')" required>
          <template #default="{ id }"><px-input :id="id" v-model="form.name" maxlength="192" /></template>
        </px-field>
        <px-field :label="$t('Description')" class="pxcm__gap">
          <template #default="{ id }"><px-textarea :id="id" v-model="form.description" :rows="2" maxlength="500" /></template>
        </px-field>
        <div class="pxcm__gap">
          <px-check v-model="form.is_active" type="checkbox">{{ $t('Active') }}</px-check>
        </div>
        <div class="pxcm__formgrid pxcm__gap">
          <px-field :label="$t('Valid_From')">
            <template #default="{ id }"><px-input :id="id" type="date" v-model="form.valid_from" /></template>
          </px-field>
          <px-field :label="$t('Valid_To')">
            <template #default="{ id }"><px-input :id="id" type="date" v-model="form.valid_to" /></template>
          </px-field>
        </div>
        <div class="pxcm__actionbar">
          <px-button variant="secondary" type="button" @click="modalOpen = false">{{ $t('Cancel') }}</px-button>
          <px-button variant="primary" type="submit" icon="check">{{ $t('Submit') }}</px-button>
        </div>
      </b-form>
    </px-modal>
  </div>
</template>

<script>
import { mapGetters } from 'vuex';
import NProgress from 'nprogress';
import PxPageHeader from "@/components/px-next/PxPageHeader.vue";
import PxToolbar from "@/components/px-next/PxToolbar.vue";
import PxTable from "@/components/px-next/PxTable.vue";
import PxPagination from "@/components/px-next/PxPagination.vue";
import PxButton from "@/components/px-next/PxButton.vue";
import PxKebab from "@/components/px-next/PxKebab.vue";
import PxBadge from "@/components/px-next/PxBadge.vue";
import PxField from "@/components/px-next/PxField.vue";
import PxInput from "@/components/px-next/PxInput.vue";
import PxTextarea from "@/components/px-next/PxTextarea.vue";
import PxCheck from "@/components/px-next/PxCheck.vue";
import PxModal from "@/components/px-next/PxModal.vue";
import PxEmptyState from "@/components/px-next/PxEmptyState.vue";

export default {
  metaInfo: { title: 'Commission Programs' },
  components: {
    PxPageHeader, PxToolbar, PxTable, PxPagination, PxButton, PxKebab, PxBadge,
    PxField, PxInput, PxTextarea, PxCheck, PxModal, PxEmptyState
  },
  data() {
    return {
      _searchTimer: null,
      isLoading: true,
      modalOpen: false,
      programs: [],
      totalRows: 0,
      serverParams: { sort: { field: 'id', type: 'desc' }, page: 1, perPage: 10 },
      limit: '10',
      search: '',
      editMode: false,
      form: { name: '', description: '', is_active: true, valid_from: '', valid_to: '' },
    };
  },
  computed: {
    ...mapGetters(['currentUserPermissions']),
    canRowActions() {
      const p = this.currentUserPermissions || [];
      return p.includes('commissions_edit') || p.includes('commissions_delete');
    },
    rowActions() {
      const p = this.currentUserPermissions || [];
      const items = [];
      if (p.includes('commissions_edit')) items.push({ key: 'edit', label: this.$t('Edit'), icon: 'pencil' });
      if (p.includes('commissions_delete')) items.push({ key: 'delete', label: this.$t('Del'), icon: 'x', tone: 'danger' });
      return items;
    },
    columns() {
      return [
        { key: 'name', label: this.$t('Name'), strong: true },
        { key: 'description', label: this.$t('Description') },
        { key: 'is_active', label: this.$t('Active') },
        { key: 'valid_from', label: this.$t('Valid_From') },
        { key: 'valid_to', label: this.$t('Valid_To') },
        { key: 'commission_rules_count', label: this.$t('Rules'), align: 'right' },
      ];
    },
  },
  created() {
    this.load();
  },
  methods: {
    onSearchInput(v) {
      this.search = v;
      if (this._searchTimer) clearTimeout(this._searchTimer);
      this._searchTimer = setTimeout(() => { this.serverParams.page = 1; this.load(1); }, 350);
    },
    load(page = 1) {
      NProgress.start();
      const params = {
        page,
        limit: this.limit,
        SortField: this.serverParams.sort.field,
        SortType: this.serverParams.sort.type,
        search: this.search,
      };
      axios.get('commission_programs', { params }).then((res) => {
        const d = res.data.data || res.data;
        this.programs = d.programs || [];
        this.totalRows = d.totalRows || 0;
        NProgress.done();
        this.isLoading = false;
      }).catch(() => {
        NProgress.done();
        this.isLoading = false;
      });
    },
    onPage(p) { if (this.serverParams.page !== p) { this.serverParams.page = p; this.load(p); } },
    onLimit(v) { if (this.limit !== String(v)) { this.limit = String(v); this.serverParams.perPage = Number(v); this.load(1); } },
    onSort({ key, dir }) {
      this.serverParams.sort = { field: key, type: dir };
      this.load(1);
    },
    onRowAction(row, item) {
      const k = item && item.key;
      if (k === 'edit') this.openModal(row);
      else if (k === 'delete') this.confirmDelete(row);
    },
    formatDate(v) {
      if (!v) return '—';
      return new Date(v).toLocaleDateString();
    },
    openModal(row = null) {
      this.editMode = !!row;
      if (row) {
        this.form = {
          id: row.id,
          name: row.name,
          description: row.description || '',
          is_active: !!row.is_active,
          valid_from: row.valid_from ? row.valid_from.slice(0, 10) : '',
          valid_to: row.valid_to ? row.valid_to.slice(0, 10) : '',
        };
      } else {
        this.resetForm();
      }
      this.modalOpen = true;
    },
    resetForm() {
      this.form = { name: '', description: '', is_active: true, valid_from: '', valid_to: '' };
      delete this.form.id;
    },
    submit() {
      const url = this.editMode ? `commission_programs/${this.form.id}` : 'commission_programs';
      const method = this.editMode ? 'put' : 'post';
      const payload = {
        name: this.form.name,
        description: this.form.description,
        is_active: this.form.is_active,
        valid_from: this.form.valid_from || null,
        valid_to: this.form.valid_to || null,
      };
      axios[method](url, payload).then(() => {
        this.makeToast('success', this.$t('Success'));
        this.modalOpen = false;
        this.load(this.serverParams.page);
      }).catch((e) => {
        this.makeToast('danger', e.response?.data?.message || this.$t('Error'));
      });
    },
    confirmDelete(row) {
      this.$bvModal.msgBoxConfirm(this.$t('Confirm_delete')).then((ok) => {
        if (ok) {
          axios.delete(`commission_programs/${row.id}`).then(() => {
            this.makeToast('success', this.$t('Deleted'));
            this.load(this.serverParams.page);
          }).catch((e) => this.makeToast('danger', e.response?.data?.message || this.$t('Error')));
        }
      });
    },
    makeToast(variant, msg, title = '') {
      this.$bvToast.toast(msg, { title: title || this.$t('Notice'), variant, solid: true });
    },
  },
};
</script>

<style lang="scss" src="@/assets/styles/sass/px-next/production.scss"></style>

<style lang="scss" scoped>
.pxcm { min-height: 100%; background: var(--pxn-bg); padding: var(--pxn-space-8) var(--pxn-space-9) var(--pxn-space-9); }
@media (max-width: 620px) { .pxcm { padding: var(--pxn-space-6) var(--pxn-space-5); } }
.pxcm__pad { padding: var(--pxn-space-6) 0; }
.pxcm__tablewrap { margin-top: var(--pxn-space-5); }
.pxcm__gap { margin-top: var(--pxn-space-5); }
.pxcm__formgrid { display: grid; grid-template-columns: repeat(2, minmax(0, 1fr)); gap: var(--pxn-space-4) var(--pxn-space-5); }
@media (max-width: 480px) { .pxcm__formgrid { grid-template-columns: minmax(0, 1fr); } }
.pxcm__actionbar { display: flex; justify-content: flex-end; gap: var(--pxn-space-3); margin-top: var(--pxn-space-7); }
</style>
