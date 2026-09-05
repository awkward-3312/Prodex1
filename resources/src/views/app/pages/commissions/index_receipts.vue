<template>
  <div class="px-next pxcm">
    <px-page-header :title="$t('Commission_Receipts')" :breadcrumbs="[{ label: $t('Commissions') }, { label: $t('Commission_Receipts') }]">
      <template #actions>
        <px-button
          v-if="currentUserPermissions && currentUserPermissions.includes('commissions_add')"
          variant="primary" icon="plus" @click="openCreateModal()"
        >{{ $t('Add') }}</px-button>
      </template>
    </px-page-header>

    <px-toolbar
      :search="search"
      :search-placeholder="$t('Search_this_table')"
      @update:search="onSearchInput"
    />

    <div v-if="isLoading" class="pxcm__pad">
      <px-skeleton variant="table" :rows="8" :columns="5" />
    </div>

    <template v-else>
      <div class="pxcm__tablewrap">
        <px-table
          v-if="receipts.length"
          :columns="columns"
          :rows="receipts"
          row-key="id"
          :sort-key="serverParams.sort.field"
          :sort-dir="serverParams.sort.type"
          has-row-actions
          @sort="onSort"
        >
          <template #cell-paid_at="{ row }">{{ formatDate(row.paid_at) }}</template>
          <template #cell-amount="{ row }"><span class="pxn-num">{{ formatMoney(row.amount) }}</span></template>
          <template #cell-agent="{ row }">{{ row.sales_agent ? row.sales_agent.name : '—' }}</template>
          <template #row-actions="{ row }">
            <px-button variant="ghost" size="sm" icon-only icon="eye" :title="$t('View')" @click="viewReceipt(row)" />
          </template>
        </px-table>

        <px-empty-state
          v-else
          icon="receipt"
          :title="$t('No_commission_receipts_yet') || 'Sin recibos de comisión todavía'"
        >
          <px-button
            v-if="currentUserPermissions && currentUserPermissions.includes('commissions_add')"
            variant="primary" icon="plus" size="sm" @click="openCreateModal()"
          >{{ $t('Add') }}</px-button>
        </px-empty-state>
      </div>

      <px-pagination
        v-if="receipts.length"
        :page="serverParams.page"
        :per-page="Number(limit)"
        :total="Number(totalRows) || 0"
        @update:page="onPage"
        @update:perPage="onLimit"
      />
    </template>

    <!-- View Modal -->
    <px-modal v-model="viewOpen" size="md" :title="$t('Commission_Receipt')">
      <div v-if="viewReceiptData" class="pxcm__viewlist">
        <div><strong>Ref:</strong> {{ viewReceiptData.Ref }}</div>
        <div><strong>{{ $t('Sales_Agent') }}:</strong> {{ viewReceiptData.sales_agent ? viewReceiptData.sales_agent.name : '—' }}</div>
        <div><strong>{{ $t('Amount') }}:</strong> <span class="pxn-num">{{ formatMoney(viewReceiptData.amount) }}</span></div>
        <div><strong>{{ $t('Paid_At') }}:</strong> {{ formatDate(viewReceiptData.paid_at) }}</div>
      </div>
      <template #footer="{ close }">
        <div class="pxcm__actionbar"><px-button variant="secondary" @click="close">{{ $t('Close') }}</px-button></div>
      </template>
    </px-modal>

    <!-- Create Modal -->
    <px-modal v-model="createOpen" size="md" :title="`${$t('Add')} ${$t('Commission_Receipt')}`">
      <b-form @submit.prevent="submitCreateReceipt">
        <px-field :label="$t('Sales_Agent')">
          <template #default="{ id }">
            <vs-px :input-id="id" v-model="createForm.sales_agent_id" :reduce="a => a.id" :options="agentsList" label="name"
              :placeholder="$t('PleaseSelect')" @input="onCreateAgentSelect" />
          </template>
        </px-field>

        <px-field v-if="createForm.sales_agent_id" :label="$t('Approved_Commissions') || 'Approved commissions'" class="pxcm__gap">
          <template #default>
            <div class="pxcm__checkbox-list pxn-scroll">
              <label v-for="c in approvedCommissions" :key="c.id" class="pxcm__checkbox-item">
                <input type="checkbox" :value="c.id" v-model="createForm.commission_ids">
                <span>{{ c.sale ? c.sale.Ref : '' }} — {{ formatMoney(c.commission_amount) }}</span>
              </label>
              <span v-if="!approvedCommissions.length" class="pxcm__muted">{{ $t('NodataAvailable') }}</span>
            </div>
            <p class="pxcm__hint">{{ $t('Total') }}: <span class="pxn-num">{{ formatMoney(createForm.amount) }}</span></p>
          </template>
        </px-field>

        <div class="pxcm__formgrid pxcm__gap">
          <px-field :label="$t('Ref')">
            <template #default="{ id }"><px-input :id="id" v-model="createForm.Ref" maxlength="192" /></template>
          </px-field>
          <px-field :label="$t('Amount')" required>
            <template #default="{ id }"><px-input :id="id" v-model.number="createForm.amount" type="number" step="0.01" min="0" /></template>
          </px-field>
          <px-field :label="$t('Paid_At')" required>
            <template #default="{ id }"><px-input :id="id" v-model="createForm.paid_at" type="date" /></template>
          </px-field>
          <px-field :label="$t('Payment_Method') || 'Payment method'">
            <template #default="{ id }">
              <vs-px :input-id="id" v-model="createForm.payment_method_id" :reduce="p => p.id" :options="paymentMethodsList" label="name" :placeholder="$t('PleaseSelect')" />
            </template>
          </px-field>
        </div>

        <px-field :label="$t('Notes')" class="pxcm__gap">
          <template #default="{ id }"><px-textarea :id="id" v-model="createForm.notes" :rows="2" /></template>
        </px-field>

        <div class="pxcm__actionbar">
          <px-button variant="secondary" type="button" @click="createOpen = false">{{ $t('Cancel') }}</px-button>
          <px-button variant="primary" type="submit" icon="check"
            :disabled="!createForm.sales_agent_id || !createForm.commission_ids.length || !createForm.amount">{{ $t('Submit') }}</px-button>
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
import PxField from "@/components/px-next/PxField.vue";
import PxInput from "@/components/px-next/PxInput.vue";
import PxTextarea from "@/components/px-next/PxTextarea.vue";
import PxModal from "@/components/px-next/PxModal.vue";
import PxEmptyState from "@/components/px-next/PxEmptyState.vue";
import VsPx from "@/views/app/products/next/edit/VsPx.vue";

export default {
  components: {
    PxPageHeader, PxToolbar, PxTable, PxPagination, PxButton,
    PxField, PxInput, PxTextarea, PxModal, PxEmptyState, "vs-px": VsPx
  },
  data() {
    return {
      _searchTimer: null,
      isLoading: true,
      viewOpen: false,
      createOpen: false,
      receipts: [],
      totalRows: 0,
      serverParams: { sort: { field: 'paid_at', type: 'desc' }, page: 1, perPage: 10 },
      limit: '10',
      search: '',
      viewReceiptData: null,
      agentsList: [],
      paymentMethodsList: [],
      approvedCommissions: [],
      createForm: {
        sales_agent_id: null,
        commission_ids: [],
        Ref: '',
        amount: 0,
        paid_at: '',
        payment_method_id: null,
        notes: '',
      },
    };
  },
  watch: {
    'createForm.commission_ids'() {
      this.updateCreateAmountFromSelection();
    },
    createOpen(v) {
      if (v) this.onCreateModalShow();
      else this.resetCreateForm();
    },
  },
  computed: {
    ...mapGetters(['currentUserPermissions']),
    columns() {
      return [
        { key: 'Ref', label: this.$t('Ref'), strong: true },
        { key: 'agent', label: this.$t('Sales_Agent') },
        { key: 'amount', label: this.$t('Amount'), align: 'right' },
        { key: 'paid_at', label: this.$t('Paid_At') },
      ];
    },
  },
  created() { this.load(); },
  methods: {
    onSearchInput(v) {
      this.search = v;
      if (this._searchTimer) clearTimeout(this._searchTimer);
      this._searchTimer = setTimeout(() => { this.load(1); }, 350);
    },
    load(page) {
      page = page || 1;
      NProgress.start();
      axios.get('commission_receipts', { params: { page, limit: this.limit, SortField: this.serverParams.sort.field, SortType: this.serverParams.sort.type, search: this.search } }).then((res) => {
        const d = res.data.data || res.data;
        this.receipts = d.receipts || [];
        this.totalRows = d.totalRows || 0;
        NProgress.done();
        this.isLoading = false;
      }).catch(() => { NProgress.done(); this.isLoading = false; });
    },
    onPage(p) { if (this.serverParams.page !== p) { this.serverParams.page = p; this.load(p); } },
    onLimit(v) { if (this.limit !== String(v)) { this.limit = String(v); this.load(1); } },
    onSort({ key, dir }) { this.serverParams.sort = { field: key, type: dir }; this.load(1); },
    formatDate(v) { return v ? new Date(v).toLocaleDateString() : '—'; },
    formatMoney(v) { return v != null ? Number(v).toLocaleString(undefined, { minimumFractionDigits: 2 }) : '—'; },
    viewReceipt(row) {
      axios.get('commission_receipts/' + row.id).then((res) => {
        this.viewReceiptData = (res.data.data || res.data);
        this.viewOpen = true;
      });
    },
    openCreateModal() {
      this.createOpen = true;
    },
    onCreateModalShow() {
      this.resetCreateForm();
      axios.get('commission_receipts/new_ref').then((res) => {
        const d = res.data.data || res.data;
        if (d && d.Ref) this.createForm.Ref = d.Ref;
      }).catch(() => {});
      axios.get('sales_agents_list_for_select').then((res) => {
        const d = res.data.data || res.data;
        this.agentsList = Array.isArray(d) ? d : (d.agents || []);
      }).catch(() => { this.agentsList = []; });
      axios.get('payment_methods', { params: { limit: '-1' } }).then((res) => {
        this.paymentMethodsList = (res.data.methods || res.data.data?.methods || []);
      }).catch(() => { this.paymentMethodsList = []; });
      const today = new Date().toISOString().slice(0, 10);
      this.createForm.paid_at = today;
    },
    onCreateAgentSelect() {
      this.createForm.commission_ids = [];
      this.approvedCommissions = [];
      if (!this.createForm.sales_agent_id) return;
      axios.get('commission_report', {
        params: { sales_agent_id: this.createForm.sales_agent_id, status: 'approved', limit: '-1' },
      }).then((res) => {
        const d = res.data.data || res.data;
        this.approvedCommissions = d.commissions || [];
      }).catch(() => { this.approvedCommissions = []; });
    },
    updateCreateAmountFromSelection() {
      let sum = 0;
      this.createForm.commission_ids.forEach((id) => {
        const c = this.approvedCommissions.find((x) => x.id === id);
        if (c && c.commission_amount != null) sum += Number(c.commission_amount);
      });
      this.createForm.amount = Math.round(sum * 100) / 100;
    },
    resetCreateForm() {
      this.createForm = {
        sales_agent_id: null,
        commission_ids: [],
        Ref: '',
        amount: 0,
        paid_at: new Date().toISOString().slice(0, 10),
        payment_method_id: null,
        notes: '',
      };
      this.approvedCommissions = [];
    },
    submitCreateReceipt() {
      if (!this.createForm.sales_agent_id || !this.createForm.commission_ids.length || this.createForm.amount == null) return;
      NProgress.start();
      axios.post('commission_receipts', {
        sales_agent_id: this.createForm.sales_agent_id,
        commission_ids: this.createForm.commission_ids,
        Ref: this.createForm.Ref || undefined,
        amount: this.createForm.amount,
        paid_at: this.createForm.paid_at,
        payment_method_id: this.createForm.payment_method_id || undefined,
        notes: this.createForm.notes || undefined,
      }).then(() => {
        NProgress.done();
        this.createOpen = false;
        this.$toast.success(this.$t('Created_successfully') || 'Created successfully');
        this.load(1);
      }).catch((err) => {
        NProgress.done();
        const msg = (err.response && err.response.data && err.response.data.message) || err.message || 'Error';
        this.$toast.error(msg);
      });
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
.pxcm__viewlist { display: flex; flex-direction: column; gap: var(--pxn-space-3); font-size: var(--pxn-fs-sm); color: var(--pxn-ink-2); }
.pxcm__checkbox-list { max-height: 200px; overflow-y: auto; border: 1px solid var(--pxn-border); border-radius: var(--pxn-radius-md); padding: var(--pxn-space-3); }
.pxcm__checkbox-item { display: flex; align-items: center; gap: var(--pxn-space-3); padding: var(--pxn-space-2) 0; font-size: var(--pxn-fs-sm); color: var(--pxn-ink); cursor: pointer; }
.pxcm__muted { color: var(--pxn-ink-3); font-size: var(--pxn-fs-sm); }
.pxcm__hint { margin-top: var(--pxn-space-2); font-size: var(--pxn-fs-xs); color: var(--pxn-ink-3); }
</style>
