<template>
  <div class="px-next pxcfg">
    <px-page-header
      :title="$t('Incoming_Logs') || 'Incoming Logs'"
      :breadcrumbs="[
        { label: $t('Settings'), href: '#/app/settings/System_settings' },
        { label: $t('Webhooks'), href: '#/app/settings/webhooks/list' },
        { label: $t('Incoming_Logs') || 'Incoming Logs' }
      ]"
    >
      <template #actions>
        <px-button variant="ghost" size="sm" icon="arrow-left" @click="$router.push('/app/settings/webhooks/list')">{{ $t('Back') || 'Back to Webhooks' }}</px-button>
      </template>
    </px-page-header>

    <div v-if="isLoading" class="pxcfg__pad">
      <px-skeleton variant="table" :rows="10" :columns="6" />
    </div>

    <template v-else>
      <px-toolbar :search="search" :search-placeholder="$t('Search_this_table')" @update:search="onSearchInput">
        <template #filters>
          <px-input
            style="min-width: 180px"
            :value="sourceFilter"
            placeholder="Filter source…"
            @input="v => sourceFilter = v"
            @change="Get_Logs(1)"
          />
          <vs-px
            style="min-width: 190px"
            :options="statusOptions.map(o => ({ label: o.text, value: o.value }))"
            :reduce="o => o.value"
            :value="statusFilter"
            :clearable="false"
            @input="v => { statusFilter = v || ''; Get_Logs(1); }"
          />
        </template>
      </px-toolbar>

      <div class="pxcfg__tablewrap">
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
          <template #cell-status="{ row }">
            <px-badge :tone="statusTone(row.status)">{{ row.status }}</px-badge>
          </template>
          <template #cell-signature_valid="{ row }">
            <px-badge :tone="row.signature_valid ? 'success' : 'danger'">{{ row.signature_valid ? 'Valid' : 'Invalid' }}</px-badge>
          </template>
          <template #row-actions="{ row }">
            <px-button variant="ghost" size="sm" icon-only icon="eye" aria-label="View" @click="Show_Log(row)" />
          </template>
        </px-table>
        <px-empty-state v-else icon="file-text" title="Sin entrantes" description="Aún no hay registros de webhooks entrantes." />
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

    <px-modal v-model="modalOpen" :title="$t('Incoming_Details') || 'Incoming Webhook'" size="lg">
      <div v-if="active" class="pxcfg__deflist">
        <div class="pxcfg__defrow"><span>Source</span><b>{{ active.source }}</b></div>
        <div class="pxcfg__defrow"><span>Event</span><b>{{ active.event || '—' }}</b></div>
        <div class="pxcfg__defrow"><span>Status</span><px-badge :tone="statusTone(active.status)">{{ active.status }}</px-badge></div>
        <div class="pxcfg__defrow"><span>Signature</span><px-badge :tone="active.signature_valid ? 'success' : 'danger'">{{ active.signature_valid ? 'Valid' : 'Invalid' }}</px-badge></div>
        <div class="pxcfg__defrow"><span>IP</span><b>{{ active.ip || '—' }}</b></div>
        <div v-if="active.error_message" class="pxcfg__defrow"><span>Error</span><b>{{ active.error_message }}</b></div>
        <h5 class="pxcfg__subhead">Headers</h5>
        <pre class="pxcfg__pre">{{ formatJson(active.headers) }}</pre>
        <h5 class="pxcfg__subhead">Payload</h5>
        <pre class="pxcfg__pre">{{ formatPayload(active.payload) }}</pre>
      </div>
      <template #footer="{ close }">
        <px-button variant="ghost" @click="close">{{ $t('Close') || 'Close' }}</px-button>
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
import PxModal from "@/components/px-next/PxModal.vue";
import PxInput from "@/components/px-next/PxInput.vue";
import PxBadge from "@/components/px-next/PxBadge.vue";
import PxEmptyState from "@/components/px-next/PxEmptyState.vue";
import VsPx from "@/views/app/products/next/edit/VsPx.vue";

export default {
  metaInfo: { title: "Incoming Webhook Logs" },
  components: { PxPageHeader, PxToolbar, PxTable, PxPagination, PxButton, PxModal, PxInput, PxBadge, PxEmptyState, VsPx },
  data() {
    return {
      _searchTimer: null,
      isLoading: true,
      modalOpen: false,
      serverParams: {
        columnFilters: {},
        sort: { field: "id", type: "desc" },
        page: 1,
        perPage: 15,
      },
      totalRows: 0,
      search: "",
      limit: "15",
      rows: [],
      active: null,
      sourceFilter: "",
      statusFilter: "",
      statusOptions: [
        { value: "", text: "All statuses" },
        { value: "received", text: "Received" },
        { value: "processed", text: "Processed" },
        { value: "failed", text: "Failed" },
        { value: "ignored", text: "Ignored" },
      ],
    };
  },
  computed: {
    columns() {
      return [
        { key: "id", label: "ID", sortable: true },
        { key: "source", label: this.$t("Source") || "Source", sortable: true },
        { key: "event", label: this.$t("Event") || "Event", sortable: true },
        { key: "status", label: this.$t("Status"), align: "center" },
        { key: "signature_valid", label: this.$t("Signature") || "Signature", align: "center" },
        { key: "ip", label: "IP", sortable: true },
        { key: "created_at", label: this.$t("Created_At") || "Created", sortable: true },
      ];
    },
  },
  methods: {
    updateParams(newProps) {
      this.serverParams = Object.assign({}, this.serverParams, newProps);
    },
    onSearchInput(v) {
      this.search = v;
      if (this._searchTimer) clearTimeout(this._searchTimer);
      this._searchTimer = setTimeout(() => this.Get_Logs(this.serverParams.page), 350);
    },
    onPage(p) { if (this.serverParams.page !== p) { this.updateParams({ page: p }); this.Get_Logs(p); } },
    onLimit(v) { if (this.limit !== String(v)) { this.limit = String(v); this.updateParams({ page: 1, perPage: Number(v) }); this.Get_Logs(1); } },
    onSort({ key, dir }) {
      this.updateParams({ sort: { type: dir, field: key } });
      this.Get_Logs(this.serverParams.page);
    },
    statusTone(s) {
      return {
        processed: "success",
        received: "info",
        failed: "danger",
        ignored: "neutral",
      }[s] || "neutral";
    },
    formatJson(value) {
      if (!value) return "—";
      try {
        return JSON.stringify(value, null, 2);
      } catch (_) {
        return String(value);
      }
    },
    formatPayload(value) {
      if (!value) return "—";
      try {
        return JSON.stringify(JSON.parse(value), null, 2);
      } catch (_) {
        return value;
      }
    },
    Show_Log(row) {
      this.active = row;
      this.modalOpen = true;
    },
    Get_Logs(page) {
      NProgress.start();
      NProgress.set(0.1);
      axios
        .get("webhooks/incoming-logs", {
          params: {
            page,
            SortField: this.serverParams.sort.field,
            SortType: this.serverParams.sort.type,
            search: this.search,
            limit: this.limit,
            source: this.sourceFilter,
            status: this.statusFilter,
          },
        })
        .then((response) => {
          this.rows = response.data.logs;
          this.totalRows = response.data.totalRows;
          NProgress.done();
          this.isLoading = false;
        })
        .catch(() => {
          NProgress.done();
          setTimeout(() => (this.isLoading = false), 500);
        });
    },
  },
  created() {
    this.Get_Logs(1);
  },
};
</script>

<style lang="scss" src="@/assets/styles/sass/px-next/production.scss"></style>

<style lang="scss" scoped>
.pxcfg { min-height: 100%; background: var(--pxn-bg); padding: var(--pxn-space-8) var(--pxn-space-9) var(--pxn-space-9); }
@media (max-width: 620px) { .pxcfg { padding: var(--pxn-space-6) var(--pxn-space-5); } }
.pxcfg__pad { padding: var(--pxn-space-6) 0; }
.pxcfg__tablewrap { margin-top: var(--pxn-space-5); }
.pxcfg__deflist { display: grid; gap: var(--pxn-space-2); }
.pxcfg__defrow { display: grid; grid-template-columns: 140px minmax(0, 1fr); gap: var(--pxn-space-4); align-items: baseline; font-size: var(--pxn-fs-sm); }
.pxcfg__defrow > span { color: var(--pxn-text-muted); }
.pxcfg__defrow > b { font-weight: 500; word-break: break-word; }
.pxcfg__subhead { margin: var(--pxn-space-4) 0 var(--pxn-space-2); font-size: var(--pxn-fs-sm); font-weight: 600; color: var(--pxn-text); }
.pxcfg__pre { max-height: 260px; overflow: auto; background: var(--pxn-surface-2); border: 1px solid var(--pxn-border); padding: var(--pxn-space-3); border-radius: var(--pxn-radius-md); font-size: 12px; margin: 0; }
</style>
