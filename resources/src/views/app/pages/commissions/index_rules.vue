<template>
  <div class="px-next pxcm">
    <px-page-header :title="$t('Commission_Rules')" :breadcrumbs="[{ label: $t('Commissions') }, { label: $t('Commission_Rules') }]">
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

    <div class="pxcm__filterbar">
      <px-field :label="$t('Filter_by_Program')">
        <template #default="{ id }">
          <vs-px :input-id="id" v-model="filterProgramId" :reduce="p => p.id" :options="programsList" label="name"
            :placeholder="$t('Filter_by_Program')" @input="load(1)" />
        </template>
      </px-field>
    </div>

    <div v-if="isLoading" class="pxcm__pad">
      <px-skeleton variant="table" :rows="8" :columns="7" />
    </div>

    <template v-else>
      <div class="pxcm__tablewrap">
        <px-table
          v-if="rules.length"
          :columns="columns"
          :rows="rules"
          row-key="id"
          :sort-key="serverParams.sort.field"
          :sort-dir="serverParams.sort.type"
          :has-row-actions="canRowActions"
          @sort="onSort"
        >
          <template #cell-type="{ row }">{{ row.type }} ({{ row.value }}{{ row.type === 'percentage' ? '%' : '' }})</template>
          <template #cell-source="{ row }">{{ row.source === 'sale_total' ? $t('Sale_Total') : $t('Paid_Amount') }}</template>
          <template #cell-program="{ row }">{{ row.commission_program ? row.commission_program.name : '—' }}</template>
          <template #cell-agent="{ row }">{{ row.sales_agent ? row.sales_agent.name : '—' }}</template>
          <template #cell-is_active="{ row }">
            <px-badge :tone="row.is_active ? 'success' : 'neutral'">{{ row.is_active ? $t('Active') : $t('Inactive') }}</px-badge>
          </template>
          <template #row-actions="{ row }">
            <px-kebab v-if="canRowActions" :items="rowActions" @select="onRowAction(row, $event)" />
          </template>
        </px-table>

        <px-empty-state
          v-else
          icon="percent"
          :title="$t('No_commission_rules_yet') || 'Sin reglas de comisión todavía'"
        >
          <px-button
            v-if="currentUserPermissions && currentUserPermissions.includes('commissions_add')"
            variant="primary" icon="plus" size="sm" @click="openModal()"
          >{{ $t('Add') }}</px-button>
        </px-empty-state>
      </div>

      <px-pagination
        v-if="rules.length"
        :page="serverParams.page"
        :per-page="Number(limit)"
        :total="Number(totalRows) || 0"
        @update:page="onPage"
        @update:perPage="onLimit"
      />
    </template>

    <px-modal v-model="modalOpen" size="lg" :title="editMode ? $t('Edit') : $t('Add')">
      <b-form @submit.prevent="submit">
        <px-field :label="$t('Commission_Program')" required>
          <template #default="{ id }">
            <vs-px :input-id="id" v-model="form.commission_program_id" :reduce="p => p.id" :options="programsList" label="name" :placeholder="$t('PleaseSelect')" />
          </template>
        </px-field>
        <px-field :label="$t('Name')" required class="pxcm__gap">
          <template #default="{ id }"><px-input :id="id" v-model="form.name" maxlength="192" /></template>
        </px-field>

        <div class="pxcm__formgrid pxcm__formgrid--3 pxcm__gap">
          <px-field :label="$t('Type')">
            <template #default="{ id }">
              <vs-px :input-id="id" v-model="form.type" :reduce="o => o.value" :clearable="false"
                :options="[{ label: $t('Percentage'), value: 'percentage' }, { label: $t('Fixed'), value: 'fixed' }]" />
            </template>
          </px-field>
          <px-field :label="$t('Source')">
            <template #default="{ id }">
              <vs-px :input-id="id" v-model="form.source" :reduce="o => o.value" :clearable="false"
                :options="[{ label: $t('Sale_Total'), value: 'sale_total' }, { label: $t('Paid_Amount'), value: 'paid_amount' }]" />
            </template>
          </px-field>
          <px-field :label="$t('Value')" required>
            <template #default="{ id }"><px-input :id="id" v-model.number="form.value" type="number" step="0.01" min="0" /></template>
          </px-field>
        </div>

        <div class="pxcm__formgrid pxcm__formgrid--3 pxcm__gap">
          <px-field :label="$t('Min_Threshold')">
            <template #default="{ id }"><px-input :id="id" v-model="form.min_threshold" type="number" step="0.01" min="0" /></template>
          </px-field>
          <px-field :label="$t('Max_Cap')">
            <template #default="{ id }"><px-input :id="id" v-model="form.max_cap" type="number" step="0.01" min="0" /></template>
          </px-field>
          <px-field :label="$t('Priority')">
            <template #default="{ id }"><px-input :id="id" v-model.number="form.priority" type="number" min="0" /></template>
          </px-field>
        </div>

        <div class="pxcm__formgrid pxcm__gap">
          <px-field :label="$t('Applies_To')">
            <template #default="{ id }">
              <vs-px :input-id="id" v-model="form.applies_to" :reduce="o => o.value" :clearable="false"
                :options="[{ label: $t('All_Agents'), value: 'all_agents' }, { label: $t('Specific_Agent'), value: 'specific_agent' }]" />
            </template>
          </px-field>
          <px-field v-if="form.applies_to === 'specific_agent'" :label="$t('Sales_Agent')">
            <template #default="{ id }">
              <vs-px :input-id="id" v-model="form.sales_agent_id" :reduce="a => a.id" :options="agentsList" label="name" :placeholder="$t('PleaseSelect')" />
            </template>
          </px-field>
        </div>

        <div class="pxcm__gap">
          <px-check v-model="form.is_active" type="checkbox">{{ $t('Active') }}</px-check>
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
import PxCheck from "@/components/px-next/PxCheck.vue";
import PxModal from "@/components/px-next/PxModal.vue";
import PxEmptyState from "@/components/px-next/PxEmptyState.vue";
import VsPx from "@/views/app/products/next/edit/VsPx.vue";

export default {
  metaInfo: { title: 'Commission Rules' },
  components: {
    PxPageHeader, PxToolbar, PxTable, PxPagination, PxButton, PxKebab, PxBadge,
    PxField, PxInput, PxCheck, PxModal, PxEmptyState, "vs-px": VsPx
  },
  data() {
    return {
      _searchTimer: null,
      isLoading: true,
      modalOpen: false,
      rules: [],
      totalRows: 0,
      serverParams: { sort: { field: 'priority', type: 'desc' }, page: 1, perPage: 10 },
      limit: '10',
      search: '',
      filterProgramId: null,
      programsList: [],
      agentsList: [],
      editMode: false,
      form: { commission_program_id: null, name: '', type: 'percentage', source: 'sale_total', value: 0, min_threshold: '', max_cap: '', applies_to: 'all_agents', sales_agent_id: null, priority: 0, is_active: true },
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
        { key: 'program', label: this.$t('Program') },
        { key: 'type', label: this.$t('Type') },
        { key: 'source', label: this.$t('Source') },
        { key: 'agent', label: this.$t('Agent') },
        { key: 'is_active', label: this.$t('Active') },
        { key: 'priority', label: this.$t('Priority'), align: 'right' },
      ];
    },
  },
  created() {
    this.loadPrograms();
    this.loadAgents();
    this.load();
  },
  methods: {
    onSearchInput(v) {
      this.search = v;
      if (this._searchTimer) clearTimeout(this._searchTimer);
      this._searchTimer = setTimeout(() => { this.load(1); }, 350);
    },
    loadPrograms() {
      axios.get('commission_programs', { params: { limit: '-1' } }).then((res) => {
        const d = res.data.data || res.data;
        this.programsList = (d.programs || []).map(p => ({ id: p.id, name: p.name }));
      }).catch(() => {});
    },
    loadAgents() {
      axios.get('sales_agents_list_for_select').then((res) => {
        const d = res.data.data || res.data;
        this.agentsList = Array.isArray(d) ? d : (d.agents || []);
      }).catch(() => {});
    },
    load(page = 1) {
      NProgress.start();
      const params = { page, limit: this.limit, SortField: this.serverParams.sort.field, SortType: this.serverParams.sort.type, search: this.search };
      if (this.filterProgramId) params.commission_program_id = this.filterProgramId;
      axios.get('commission_rules', { params }).then((res) => {
        const d = res.data.data || res.data;
        this.rules = d.rules || [];
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
    openModal(row = null) {
      this.editMode = !!row;
      if (row) {
        this.form = { id: row.id, commission_program_id: row.commission_program_id, name: row.name, type: row.type, source: row.source, value: parseFloat(row.value), min_threshold: row.min_threshold || '', max_cap: row.max_cap || '', applies_to: row.applies_to, sales_agent_id: row.sales_agent_id || null, priority: row.priority || 0, is_active: !!row.is_active };
      } else this.resetForm();
      this.modalOpen = true;
    },
    resetForm() {
      this.form = { commission_program_id: this.filterProgramId || null, name: '', type: 'percentage', source: 'sale_total', value: 0, min_threshold: '', max_cap: '', applies_to: 'all_agents', sales_agent_id: null, priority: 0, is_active: true };
      delete this.form.id;
    },
    submit() {
      const url = this.editMode ? `commission_rules/${this.form.id}` : 'commission_rules';
      const method = this.editMode ? 'put' : 'post';
      const payload = { ...this.form, min_threshold: this.form.min_threshold || null, max_cap: this.form.max_cap || null, sales_agent_id: this.form.applies_to === 'specific_agent' ? this.form.sales_agent_id : null };
      if (this.editMode) delete payload.id;
      axios[method](url, payload).then(() => {
        this.makeToast('success', this.$t('Success'));
        this.modalOpen = false;
        this.load(this.serverParams.page);
      }).catch((e) => this.makeToast('danger', e.response?.data?.message || this.$t('Error')));
    },
    confirmDelete(row) {
      this.$bvModal.msgBoxConfirm(this.$t('Confirm_delete')).then((ok) => {
        if (ok) axios.delete(`commission_rules/${row.id}`).then(() => { this.makeToast('success', this.$t('Deleted')); this.load(this.serverParams.page); }).catch((e) => this.makeToast('danger', e.response?.data?.message || this.$t('Error')));
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
.pxcm__filterbar { margin-top: var(--pxn-space-4); max-width: 320px; }
.pxcm__tablewrap { margin-top: var(--pxn-space-5); }
.pxcm__gap { margin-top: var(--pxn-space-5); }
.pxcm__formgrid { display: grid; grid-template-columns: repeat(2, minmax(0, 1fr)); gap: var(--pxn-space-4) var(--pxn-space-5); }
.pxcm__formgrid--3 { grid-template-columns: repeat(3, minmax(0, 1fr)); }
@media (max-width: 640px) { .pxcm__formgrid, .pxcm__formgrid--3 { grid-template-columns: minmax(0, 1fr); } }
.pxcm__actionbar { display: flex; justify-content: flex-end; gap: var(--pxn-space-3); margin-top: var(--pxn-space-7); }
</style>
