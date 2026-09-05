<template>
  <!-- NEW FEATURE - SAFE ADDITION -->
  <div class="px-next pxac">
    <px-page-header :title="$t('Journal_Entries_Title')" :subtitle="$t('Journal_Entries_Subtitle')">
      <template #actions>
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
          <template #cell-date="{ row }">{{ formatDisplayDate(row.date) }}</template>
          <template #cell-status="{ row }">
            <px-badge :tone="row.status === 'posted' ? 'success' : 'warning'">{{ statusLabel(row.status) }}</px-badge>
          </template>
          <template #row-actions="{ row }">
            <px-kebab :items="rowActions(row)" @select="onRowAction(row, $event)" />
          </template>
        </px-table>

        <px-empty-state
          v-else
          icon="clipboard-list"
          :title="$t('No_Data') || 'Sin asientos todavía'"
          :description="$t('Journal_Entries_Subtitle')"
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
    <px-modal v-model="showModal" size="lg" :title="editing ? $t('Edit_Entry') : $t('New_Entry')">
      <div class="pxac__je-head">
        <px-field :label="$t('Date')" class="pxac__je-date">
          <template #default="{ id }"><px-input :id="id" type="date" v-model="entry.date" /></template>
        </px-field>
        <px-field :label="$t('Description')" class="pxac__je-desc">
          <template #default="{ id }"><px-input :id="id" v-model.trim="entry.description" :placeholder="$t('Description_Placeholder')" /></template>
        </px-field>
      </div>

      <div class="pxac__je-tblwrap pxn-scroll">
        <table class="pxac__je-tbl">
          <thead>
            <tr>
              <th class="pxac__je-col-acct">{{ $t('Account') }}</th>
              <th class="is-right">{{ $t('Debit') }}</th>
              <th class="is-right">{{ $t('Credit') }}</th>
              <th>{{ $t('Memo') }}</th>
              <th class="pxac__je-col-x"></th>
            </tr>
          </thead>
          <tbody>
            <tr v-for="(l, idx) in entry.lines" :key="idx">
              <td>
                <vs-px v-model="l.coa_id" :reduce="o => o.value" :placeholder="$t('Select_Account')"
                  :invalid="showErrors && !l.coa_id"
                  :options="accounts.map(a => ({ label: `${a.code} — ${a.name}`, value: a.id }))" />
              </td>
              <td><px-input v-model.number="l.debit" type="text" class="is-right" :invalid="showErrors && !validRow(l)" @input="onAmountChange(idx, 'debit')" /></td>
              <td><px-input v-model.number="l.credit" type="text" class="is-right" :invalid="showErrors && !validRow(l)" @input="onAmountChange(idx, 'credit')" /></td>
              <td><px-input v-model.trim="l.memo" /></td>
              <td class="is-right">
                <px-button variant="ghost" size="sm" icon-only icon="x" :disabled="entry.lines.length <= 1" @click="removeLine(idx)" />
              </td>
            </tr>
          </tbody>
        </table>
      </div>

      <div class="pxac__je-foot">
        <px-button variant="secondary" size="sm" icon="plus" @click="addLine">{{ $t('Add_Line') }}</px-button>
        <div class="pxac__je-totals">
          <span>{{ $t('Total_Debit') }}: <strong class="pxn-num">{{ toMoney(totals.debit) }}</strong></span>
          <span>{{ $t('Total_Credit') }}: <strong class="pxn-num">{{ toMoney(totals.credit) }}</strong></span>
          <px-badge :tone="balanced ? 'success' : 'warning'">{{ balanced ? $t('Balanced') : $t('Not_Balanced') }}</px-badge>
        </div>
      </div>

      <template #footer="{ close }">
        <div class="pxac__actionbar">
          <px-button variant="secondary" :disabled="btnLoading" @click="close">{{ $t('Cancel') }}</px-button>
          <px-button variant="primary" icon="check" :loading="btnLoading" :disabled="btnLoading || !entry.date || !linesValid" @click="save">
            {{ btnLoading ? $t('Saving') : $t('Save') }}
          </px-button>
        </div>
      </template>
    </px-modal>

    <!-- View Modal -->
    <px-modal v-model="showView" size="lg" :title="$t('Journal_Number', { number: current && current.id })">
      <div class="pxac__je-viewmeta">
        <div><strong>{{ $t('Date') }}:</strong> {{ current && current.date }}</div>
        <div><strong>{{ $t('Description') }}:</strong> {{ current && (current.description || '-') }}</div>
      </div>
      <div class="pxac__je-tblwrap pxn-scroll">
        <table class="pxac__je-tbl">
          <thead>
            <tr><th>{{ $t('Account') }}</th><th class="is-right">{{ $t('Debit') }}</th><th class="is-right">{{ $t('Credit') }}</th><th>{{ $t('Memo') }}</th></tr>
          </thead>
          <tbody>
            <tr v-for="(l, idx) in (current && current.lines || [])" :key="idx">
              <td>{{ accountName(l.coa_id) }}</td>
              <td class="is-right pxn-num">{{ toMoney(l.debit) }}</td>
              <td class="is-right pxn-num">{{ toMoney(l.credit) }}</td>
              <td>{{ l.memo || '' }}</td>
            </tr>
          </tbody>
        </table>
      </div>
      <template #footer="{ close }">
        <div class="pxac__actionbar">
          <px-button variant="secondary" @click="close">{{ $t('Close') }}</px-button>
        </div>
      </template>
    </px-modal>
  </div>
</template>

<script>
import NProgress from "nprogress";
import Util from '../../../../utils';
import {
  formatPriceDisplay as formatPriceDisplayHelper,
  getPriceFormatSetting,
  getPriceDecimals
} from "../../../../utils/priceFormat";
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
import PxEmptyState from "@/components/px-next/PxEmptyState.vue";
import VsPx from "@/views/app/products/next/edit/VsPx.vue";

export default {
  name: "JournalEntriesV2",
  components: {
    PxPageHeader, PxToolbar, PxTable, PxPagination, PxButton, PxKebab, PxBadge,
    PxField, PxInput, PxModal, PxEmptyState, "vs-px": VsPx
  },
  data() {
    return {
      _searchTimer: null,
      isLoading: true,
      rows: [],
      accounts: [],
      totalRows: "",
      serverParams: {
        columnFilters: {},
        sort: { field: "date", type: "desc" },
        page: 1,
        perPage: 10
      },
      search: "",
      limit: "10",
      showModal: false,
      editing: false,
      entry: { id: null, date: "", description: "", lines: [] },
      showView: false,
      current: null,
      btnLoading: false,
      postingId: null,
      showErrors: false,
      price_format_key: null,
    };
  },
  computed: {
    // Monetary precision (2 or 3) driven by the "Enable 3 Decimal Pricing" setting.
    priceDecimals() {
      return getPriceDecimals({ store: this.$store });
    },
    columns() {
      return [
        { key: 'date', label: this.$t('Date'), sortable: true },
        { key: 'description', label: this.$t('Description'), sortable: true },
        { key: 'reference_type', label: this.$t('Source') },
        { key: 'status', label: this.$t('Status') },
      ];
    },
    totals() {
      return this.entry.lines.reduce((acc,l) => {
        acc.debit += Number(l.debit || 0); acc.credit += Number(l.credit || 0); return acc;
      }, { debit: 0, credit: 0 });
    },
    balanced() { return Math.abs(this.totals.debit - this.totals.credit) < 0.0001; },
    linesValid() { return this.entry.lines.every(l => this.validRow(l)); }
  },
  created() {
    this.Get_Journals(1);
    this.fetchAccounts();
  },
  methods: {
    updateParams(newProps) { this.serverParams = Object.assign({}, this.serverParams, newProps); },
    onSearchInput(v) {
      this.search = v;
      if (this._searchTimer) clearTimeout(this._searchTimer);
      this._searchTimer = setTimeout(() => { this.updateParams({ page: 1 }); this.Get_Journals(1); }, 350);
    },
    onPage(p) { if (this.serverParams.page !== p) { this.updateParams({ page: p }); this.Get_Journals(p); } },
    onLimit(v) { if (this.limit !== String(v)) { this.limit = String(v); this.updateParams({ page: 1, perPage: Number(v) }); this.Get_Journals(1); } },
    onSort({ key, dir }) { this.updateParams({ sort: { type: dir, field: key } }); this.Get_Journals(this.serverParams.page); },
    formatDisplayDate(value) {
      if (!value) return '';
      const dateFormat = this.$store.getters.getDateFormat || Util.getDateFormat(this.$store);
      return Util.formatDisplayDate(value, dateFormat);
    },
    rowActions(row) {
      const items = [{ key: "view", label: this.$t("View"), icon: "eye" }];
      if (row.status !== 'posted') {
        items.push({ key: "post", label: this.$t("Post"), icon: "check" });
        items.push({ key: "edit", label: this.$t("Edit"), icon: "pencil" });
        items.push({ key: "delete", label: this.$t("Delete"), icon: "x", tone: "danger" });
      }
      return items;
    },
    onRowAction(row, item) {
      const k = item && item.key;
      if (k === "view") this.view(row);
      else if (k === "post") this.post(row);
      else if (k === "edit") this.tryEdit(row);
      else if (k === "delete") this.tryDelete(row);
    },
    async Get_Journals(page) {
      NProgress.start(); NProgress.set(0.1);
      axios.get(
        "/accounting/v2/journal-entries?page=" + page +
        "&SortField=" + this.serverParams.sort.field +
        "&SortType=" + this.serverParams.sort.type +
        "&search=" + this.search +
        "&limit=" + this.limit
      )
      .then(({data}) => {
        // paginator shape (data, total) or custom
        this.rows = (data && (data.data || data.rows || [])) || [];
        this.totalRows = (data && (data.total ?? data.totalRows ?? this.rows.length)) || 0;
        NProgress.done(); this.isLoading = false;
      })
      .catch(() => { NProgress.done(); this.isLoading = false; });
    },
    async fetchAccounts() {
      try {
        const { data } = await axios.get("/accounting/v2/coa", { params: { limit: -1, SortField: 'code', SortType: 'asc', active: 1 } });
        this.accounts = (data && data.data) || [];
      } catch (e) {}
    },
    openCreate() {
      this.editing = false;
      this.entry = { id: null, date: new Date().toISOString().slice(0,10), description: "", lines: [ { coa_id: null, debit: 0, credit: 0, memo: "" }, { coa_id: null, debit: 0, credit: 0, memo: "" } ] };
      this.btnLoading = false;
      this.showErrors = false;
      this.showModal = true;
    },
    view(j) { this.current = j; this.showView = true; },
    tryEdit(j) {
      if (j.status === 'posted') return;
      this.editing = true;
      this.entry = { id: j.id, date: j.date, description: j.description || "", lines: (j.lines || []).map(l => ({ coa_id: l.coa_id, debit: l.debit, credit: l.credit, memo: l.memo })) };
      this.btnLoading = false;
      this.showErrors = false;
      this.showModal = true;
    },
    tryDelete(j) {
      if (j.status === 'posted') return;
      this.$swal({ title: this.$t('Delete'), text: this.$t('Delete_Draft_Entry_Question'), type: 'warning', showCancelButton: true, confirmButtonText: this.$t('Delete') })
        .then(r => { if (r.value) this.remove(j); });
    },
    addLine() { this.entry.lines.push({ coa_id: null, debit: 0, credit: 0, memo: "" }); },
    closeModal() { this.btnLoading = false; this.showErrors = false; this.showModal = false; },
    async save() {
      try {
        this.btnLoading = true;
        this.showErrors = true;
        if (!this.linesValid) { this.btnLoading = false; return this.makeToast('danger', this.$t('Complete_Lines_Message'), this.$t('Validation')); }
        const payload = { date: this.entry.date, description: this.entry.description, lines: this.entry.lines };
        if (this.editing && this.entry.id) {
          await axios.put(`/accounting/v2/journal-entries/${this.entry.id}`, payload);
          this.makeToast('success', this.$t('Entry_Updated'), this.$t('Success'));
        } else {
          await axios.post(`/accounting/v2/journal-entries`, payload);
          this.makeToast('success', this.$t('Entry_Created_Draft'), this.$t('Success'));
        }
        this.showModal = false; this.Get_Journals(this.serverParams.page);
      } catch (e) { this.makeToast('danger', this.$t('Operation_Failed'), this.$t('Error')); }
      finally { this.btnLoading = false; }
    },
    async remove(j) {
      try { await axios.delete(`/accounting/v2/journal-entries/${j.id}`); this.makeToast('success', this.$t('Deleted_Successfully'), this.$t('Success')); this.Get_Journals(this.serverParams.page); } catch (e) { this.makeToast('danger', this.$t('Delete_Failed'), this.$t('Error')); }
    },
    async post(j) {
      this.postingId = j.id;
      try {
        await axios.post(`/accounting/v2/journal-entries/${j.id}/post`);
        this.makeToast('success', this.$t('Posted_Successfully'), this.$t('Success'));
        this.Get_Journals(this.serverParams.page);
      } catch (e) {
        const fallback = this.$t('Post_Failed');
        const msg = (e && e.response && e.response.data && (e.response.data.message || e.response.data.error)) || fallback;
        this.makeToast('danger', msg, this.$t('Error'));
      } finally { this.postingId = null; }
    },
    // Price formatting for display only (does NOT affect calculations or stored values)
    // Uses the global/system price_format setting when available; otherwise falls back
    // to the existing toLocaleString behavior to preserve current behavior.
    toMoney(v) {
      try {
        const n = parseFloat(v || 0);
        const key = this.price_format_key || getPriceFormatSetting({ store: this.$store });
        if (key) {
          this.price_format_key = key;
        }
        const effectiveKey = key || null;
        return formatPriceDisplayHelper(n, this.priceDecimals, effectiveKey);
      } catch (e) {
        const n = parseFloat(v || 0);
        return n.toLocaleString(undefined,{minimumFractionDigits:this.priceDecimals, maximumFractionDigits:this.priceDecimals});
      }
    },
    accountName(id) { const a = this.accounts.find(x => x.id === id); return a ? `${a.code} — ${a.name}` : id; },
    statusLabel(status) {
      if (!status) return '';
      if (status === 'posted') return this.$t('Journal_Status_Posted');
      if (status === 'draft') return this.$t('Journal_Status_Draft');
      return this.$t(status);
    },
    onAmountChange(idx, field) {
      const l = this.entry.lines[idx];
      const val = Number(l[field] || 0);
      if (val < 0 || isNaN(val)) l[field] = 0;
      if (field === 'debit' && val > 0) l.credit = 0;
      if (field === 'credit' && val > 0) l.debit = 0;
    },
    validRow(l) {
      const debit = Number(l.debit || 0);
      const credit = Number(l.credit || 0);
      if (!l.coa_id) return false;
      const hasOne = (debit > 0) !== (credit > 0);
      const nonNegative = debit >= 0 && credit >= 0;
      return hasOne && nonNegative;
    },
    removeLine(idx) {
      if (this.entry.lines.length <= 1) { return this.makeToast('warning', this.$t('At_Least_One_Line'), this.$t('Notice')); }
      this.entry.lines.splice(idx,1);
    },
    makeToast(variant, msg, title) { this.$root.$bvToast.toast(msg, { title, variant, solid: true }); }
  }
};
</script>

<style lang="scss" src="@/assets/styles/sass/px-next/production.scss"></style>

<style lang="scss" scoped>
.pxac { min-height: 100%; background: var(--pxn-bg); padding: var(--pxn-space-8) var(--pxn-space-9) var(--pxn-space-9); }
@media (max-width: 620px) { .pxac { padding: var(--pxn-space-6) var(--pxn-space-5); } }
.pxac__pad { padding: var(--pxn-space-6) 0; }
.pxac__tablewrap { margin-top: var(--pxn-space-5); }
.pxac__actionbar { display: flex; justify-content: flex-end; gap: var(--pxn-space-3); align-items: center; }

.pxac__je-head { display: grid; grid-template-columns: 200px 1fr; gap: var(--pxn-space-4); margin-bottom: var(--pxn-space-5); }
@media (max-width: 520px) { .pxac__je-head { grid-template-columns: minmax(0, 1fr); } }

.pxac__je-tblwrap { border: 1px solid var(--pxn-border); border-radius: var(--pxn-radius-md); overflow-x: auto; }
.pxac__je-tbl { width: 100%; border-collapse: collapse; font-size: var(--pxn-fs-sm); }
.pxac__je-tbl th {
  padding: var(--pxn-space-3) var(--pxn-space-3); text-align: left;
  font-size: var(--pxn-fs-xs); font-weight: var(--pxn-fw-semibold); text-transform: uppercase; letter-spacing: 0.04em;
  color: var(--pxn-ink-3); background: var(--pxn-surface-2); border-bottom: 1px solid var(--pxn-border); white-space: nowrap;
}
.pxac__je-tbl td { padding: var(--pxn-space-2) var(--pxn-space-3); border-bottom: 1px solid var(--pxn-border); vertical-align: middle; }
.pxac__je-tbl tr:last-child td { border-bottom: 0; }
.pxac__je-tbl .is-right { text-align: right; }
.pxac__je-tbl ::v-deep .pxn-input.is-right input,
.pxac__je-tbl ::v-deep input.is-right { text-align: right; }
.pxac__je-tbl ::v-deep .pxn-input[aria-invalid="true"],
.pxac__je-tbl ::v-deep .vs__dropdown-toggle.is-invalid,
.pxac__je-tbl ::v-deep .is-invalid .vs__dropdown-toggle { border-color: var(--pxn-danger); }
.pxac__je-col-acct { width: 38%; }
.pxac__je-col-x { width: 36px; }

.pxac__je-foot { display: flex; align-items: center; justify-content: space-between; gap: var(--pxn-space-4); margin-top: var(--pxn-space-4); flex-wrap: wrap; }
.pxac__je-totals { display: flex; align-items: center; gap: var(--pxn-space-5); font-size: var(--pxn-fs-sm); color: var(--pxn-ink-2); flex-wrap: wrap; }
.pxac__je-viewmeta { display: flex; flex-direction: column; gap: var(--pxn-space-2); margin-bottom: var(--pxn-space-4); font-size: var(--pxn-fs-sm); color: var(--pxn-ink-2); }
</style>
