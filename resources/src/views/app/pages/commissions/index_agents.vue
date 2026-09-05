<template>
  <div class="px-next pxcm">
    <px-page-header :title="$t('Sales_Agents')" :breadcrumbs="[{ label: $t('Commissions') }, { label: $t('Sales_Agents') }]">
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
          v-if="agents.length"
          :columns="columns"
          :rows="agents"
          row-key="id"
          :sort-key="serverParams.sort.field"
          :sort-dir="serverParams.sort.type"
          :has-row-actions="canRowActions"
          @sort="onSort"
        >
          <template #cell-is_active="{ row }">
            <px-badge :tone="row.is_active ? 'success' : 'neutral'">{{ row.is_active ? $t('Active') : $t('Inactive') }}</px-badge>
          </template>
          <template #cell-user="{ row }">
            {{ row.user ? (row.user.firstname + ' ' + row.user.lastname).trim() || row.user.email : '—' }}
          </template>
          <template #row-actions="{ row }">
            <px-kebab v-if="canRowActions" :items="rowActions" @select="onRowAction(row, $event)" />
          </template>
        </px-table>

        <px-empty-state
          v-else
          icon="users"
          :title="$t('No_sales_agents_yet') || 'Sin agentes de venta todavía'"
        >
          <px-button
            v-if="currentUserPermissions && currentUserPermissions.includes('commissions_add')"
            variant="primary" icon="plus" size="sm" @click="openModal()"
          >{{ $t('Add') }}</px-button>
        </px-empty-state>
      </div>

      <px-pagination
        v-if="agents.length"
        :page="serverParams.page"
        :per-page="Number(limit)"
        :total="Number(totalRows) || 0"
        @update:page="onPage"
        @update:perPage="onLimit"
      />
    </template>

    <px-modal v-model="modalOpen" size="md" :title="editMode ? $t('Edit') : $t('Add')">
      <b-form @submit.prevent="submit">
        <div class="pxcm__formgrid">
          <px-field :label="$t('Name')" required class="pxcm__wide">
            <template #default="{ id }"><px-input :id="id" v-model="form.name" /></template>
          </px-field>
          <px-field :label="$t('Code')">
            <template #default="{ id }"><px-input :id="id" v-model="form.code" /></template>
          </px-field>
          <px-field :label="$t('email')">
            <template #default="{ id }"><px-input :id="id" type="email" v-model="form.email" /></template>
          </px-field>
          <px-field :label="$t('phone')">
            <template #default="{ id }"><px-input :id="id" v-model="form.phone" /></template>
          </px-field>
          <px-field :label="$t('Link_User')">
            <template #default="{ id }">
              <vs-px :input-id="id" v-model="form.user_id" :reduce="u => u.id" :options="usersList" label="label" :placeholder="$t('PleaseSelect')" />
            </template>
          </px-field>
        </div>
        <div class="pxcm__gap">
          <px-check v-model="form.is_active" type="checkbox">{{ $t('Active') }}</px-check>
        </div>
        <px-field :label="$t('Notes')" class="pxcm__gap">
          <template #default="{ id }"><px-textarea :id="id" v-model="form.notes" :rows="2" /></template>
        </px-field>
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
import VsPx from "@/views/app/products/next/edit/VsPx.vue";

export default {
  components: {
    PxPageHeader, PxToolbar, PxTable, PxPagination, PxButton, PxKebab, PxBadge,
    PxField, PxInput, PxTextarea, PxCheck, PxModal, PxEmptyState, "vs-px": VsPx
  },
  data() {
    return {
      _searchTimer: null,
      isLoading: true,
      modalOpen: false,
      agents: [],
      totalRows: 0,
      serverParams: { sort: { field: 'id', type: 'desc' }, page: 1, perPage: 10 },
      limit: '10',
      search: '',
      editMode: false,
      usersList: [],
      form: { name: '', code: '', email: '', phone: '', user_id: null, is_active: true, notes: '' },
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
        { key: 'code', label: this.$t('Code') },
        { key: 'email', label: this.$t('email') },
        { key: 'user', label: this.$t('User') },
        { key: 'is_active', label: this.$t('Active') },
        { key: 'sale_commissions_count', label: this.$t('Commissions'), align: 'right' },
      ];
    },
  },
  created() {
    axios.get('users_list_for_select').then((res) => {
      const list = (res.data && res.data.users) || [];
      this.usersList = list.map(u => ({ id: u.id, label: ((u.firstname || '') + ' ' + (u.lastname || '')).trim() || u.username || u.email }));
    }).catch((e) => {
      this.usersList = [];
      this.makeToast('danger', (e.response && e.response.data && e.response.data.message) || this.$t('Error') || 'Could not load users');
    });
    this.load();
  },
  methods: {
    onSearchInput(v) {
      this.search = v;
      if (this._searchTimer) clearTimeout(this._searchTimer);
      this._searchTimer = setTimeout(() => { this.load(1); }, 350);
    },
    load(page) {
      page = page || 1;
      NProgress.start();
      axios.get('sales_agents', { params: { page, limit: this.limit, SortField: this.serverParams.sort.field, SortType: this.serverParams.sort.type, search: this.search } }).then((res) => {
        const d = res.data.data || res.data;
        this.agents = d.agents || [];
        this.totalRows = d.totalRows || 0;
        NProgress.done();
        this.isLoading = false;
      }).catch(() => { NProgress.done(); this.isLoading = false; });
    },
    onPage(p) { if (this.serverParams.page !== p) { this.serverParams.page = p; this.load(p); } },
    onLimit(v) { if (this.limit !== String(v)) { this.limit = String(v); this.load(1); } },
    onSort({ key, dir }) { this.serverParams.sort = { field: key, type: dir }; this.load(1); },
    onRowAction(row, item) {
      const k = item && item.key;
      if (k === 'edit') this.openModal(row);
      else if (k === 'delete') this.confirmDelete(row);
    },
    openModal(row) {
      this.editMode = !!row;
      this.form = row ? { id: row.id, name: row.name, code: row.code || '', email: row.email || '', phone: row.phone || '', user_id: row.user_id || null, is_active: !!row.is_active, notes: row.notes || '' } : { name: '', code: '', email: '', phone: '', user_id: null, is_active: true, notes: '' };
      if (!row) delete this.form.id;
      this.modalOpen = true;
    },
    resetForm() { this.form = { name: '', code: '', email: '', phone: '', user_id: null, is_active: true, notes: '' }; },
    submit() {
      const url = this.editMode ? 'sales_agents/' + this.form.id : 'sales_agents';
      const method = this.editMode ? 'put' : 'post';
      const payload = { name: this.form.name, code: this.form.code || null, email: this.form.email || null, phone: this.form.phone || null, user_id: this.form.user_id || null, is_active: this.form.is_active, notes: this.form.notes || null };
      axios[method](url, payload).then(() => { this.makeToast('success', this.$t('Success')); this.modalOpen = false; this.load(this.serverParams.page); }).catch((e) => this.makeToast('danger', (e.response && e.response.data && e.response.data.message) || this.$t('Error')));
    },
    confirmDelete(row) {
      this.$bvModal.msgBoxConfirm(this.$t('Confirm_delete')).then((ok) => {
        if (ok) axios.delete('sales_agents/' + row.id).then(() => { this.makeToast('success', this.$t('Deleted')); this.load(this.serverParams.page); }).catch((e) => this.makeToast('danger', (e.response && e.response.data && e.response.data.message) || this.$t('Error')));
      });
    },
    makeToast(variant, msg) { this.$bvToast.toast(msg, { title: this.$t('Notice'), variant, solid: true }); },
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
.pxcm__wide { grid-column: 1 / -1; }
.pxcm__actionbar { display: flex; justify-content: flex-end; gap: var(--pxn-space-3); margin-top: var(--pxn-space-7); }
</style>
