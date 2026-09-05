<template>
  <div class="px-next pxcfg">
    <px-page-header
      :title="$t('Quickbooks_Sync')"
      :breadcrumbs="[{ label: $t('Settings'), href: '#/app/settings/System_settings' }, { label: $t('Quickbooks_Sync') }]"
    />

    <div v-if="isLoading" class="pxcfg__pad">
      <px-skeleton variant="lines" :rows="6" />
    </div>

    <template v-else>
      <div class="pxcfg__tabbar" role="tablist">
        <button
          v-for="tab in tabs"
          :key="tab.key"
          type="button"
          class="pxcfg__tab"
          :class="{ 'is-active': qbTab === tab.key }"
          @click="qbTab = tab.key"
        >{{ tab.label }}</button>
      </div>

      <!-- ================= Connection ================= -->
      <div v-show="qbTab === 'connection'" class="pxcfg__panel">
        <px-card :title="$t('Connection')" class="pxcfg__card">
          <div class="pxcfg__deflist">
            <div class="pxcfg__defrow"><span>{{ $t('Environment') }}</span><b>{{ status.env }}</b></div>
            <div class="pxcfg__defrow"><span>{{ $t('Redirect_URI_active') }}</span><b>{{ status.redirect }}</b></div>
            <div class="pxcfg__defrow"><span>{{ $t('Callback') }}</span><b>{{ status.callback }}</b></div>
            <div class="pxcfg__defrow">
              <span>{{ $t('Status') }}</span>
              <px-badge :tone="status.has_token ? 'success' : 'danger'">
                {{ status.has_token ? $t('Connected') : $t('Disconnected') }}
              </px-badge>
            </div>
            <div v-if="status.token_realm" class="pxcfg__defrow"><span>{{ $t('Realm_ID') }}</span><b>{{ status.token_realm }}</b></div>
          </div>

          <template #footer>
            <px-button v-if="!status.has_token" variant="primary" icon="link" @click="connectBlank">
              {{ $t('Connect_to_QuickBooks') }}
            </px-button>
            <px-button v-else variant="danger" :disabled="busy" @click="disconnect">
              {{ $t('Disconnect') }}
            </px-button>
            <px-button variant="ghost" size="sm" icon="trash-2" @click="Clear_Cache()">
              {{ $t('Clear_Cache') }}
            </px-button>
          </template>
        </px-card>
      </div>

      <!-- ================= Settings ================= -->
      <div v-show="qbTab === 'settings'" class="pxcfg__panel">
        <px-card :title="$t('Settings')" class="pxcfg__card">
          <validation-observer ref="qb_settings">
            <form @submit.prevent="save">
              <div class="pxcfg__formgrid">
                <px-field :label="$t('Client_ID')" :hint="$t('From_Intuit_Keys_Identifies_App') + ' ' + $t('Paste_value_exactly_as_in_app_keys')">
                  <template #default="{ id }"><px-input :id="id" :value="form.client_id" @input="v => form.client_id = tv(v)" /></template>
                </px-field>
                <px-field :label="$t('Client_Secret')" :hint="$t('From_Intuit_Keys_Keep_private_rotate')">
                  <template #default="{ id }"><px-input :id="id" type="password" :value="form.client_secret" @input="v => form.client_secret = tv(v)" /></template>
                </px-field>
                <px-field :label="$t('Redirect_URI')">
                  <template #default="{ id }"><px-input :id="id" :value="form.redirect" @input="v => form.redirect = tv(v)" /></template>
                  <template #hint>
                    {{ $t('Must_match_Intuit_redirect_exactly') }}
                    {{ $t('Typical') }}: <code>{{ callbackExample }}</code>. {{ $t('Add_this_exact_URL_on_Keys_page_too') }}
                  </template>
                </px-field>
                <px-field :label="$t('Environment')">
                  <template #default>
                    <vs-px
                      :options="envOptions.map(o => ({ label: o.text, value: o.value }))"
                      :reduce="o => o.value"
                      :value="form.env"
                      :clearable="false"
                      @input="v => form.env = v"
                    />
                  </template>
                </px-field>
                <px-field :label="$t('Realm_ID')" :hint="$t('Realm_ID_Hint')">
                  <template #default="{ id }"><px-input :id="id" :value="form.realm_id" @input="v => form.realm_id = tv(v)" /></template>
                </px-field>
                <px-field :label="$t('Income_Account_Name')" :hint="$t('Income_Account_Name_Hint')">
                  <template #default="{ id }"><px-input :id="id" :placeholder="$t('Income_Account_Name')" :value="form.income_account_name" @input="v => form.income_account_name = tv(v)" /></template>
                </px-field>
              </div>
            </form>
          </validation-observer>
          <template #footer>
            <px-button variant="primary" icon="check" :disabled="busy" @click="save">{{ $t('Save') }}</px-button>
          </template>
        </px-card>
      </div>

      <!-- ================= Clients Sync ================= -->
      <div v-show="qbTab === 'clients'" class="pxcfg__panel">
        <px-alert v-if="!status.has_token" tone="warning" class="pxcfg__alert" :title="$t('Not_connected_to_QuickBooks')">
          {{ $t('Please_connect_before_syncing_clients') }}
          <template #actions>
            <px-button size="sm" variant="primary" @click="connectBlank">{{ $t('Connect') }}</px-button>
          </template>
        </px-alert>

        <div class="pxcfg__statgrid">
          <px-stat :label="$t('Total_Clients')" :value="String(clientStats.total)" icon="users" bordered />
          <px-stat :label="$t('Synced')" :value="String(clientStats.synced)" icon="check-circle" bordered />
          <px-stat :label="$t('Not_Synced')" :value="String(clientStats.not_synced)" icon="alert-triangle" bordered />
          <px-card class="pxcfg__synccard">
            <px-button
              variant="primary"
              :loading="syncingClients"
              :disabled="syncingClients || !status.has_token || clientStats.not_synced === 0"
              @click="syncAllClients"
            >{{ $t('Sync_All_Clients') }}</px-button>
            <div class="pxcfg__muted">{{ $t('Bulk_sync_unsynced_clients') }}</div>
          </px-card>
        </div>

        <div class="pxcfg__progress">
          <div class="pxcfg__progress-head">
            <small>{{ $t('Overall_progress') }}</small>
            <small>{{ syncPercent }}%</small>
          </div>
          <div class="pxcfg__bar"><div class="pxcfg__bar-fill" :style="{ width: syncPercent + '%' }" /></div>
        </div>

        <px-card flush class="pxcfg__card">
          <div class="pxcfg__cardhead">
            <div class="pxcfg__search">
              <px-input
                :value="search"
                :placeholder="$t('Search_unsynced_by_name_email')"
                icon-lead="search"
                @input="v => search = tv(v)"
                @keyup.native.enter="loadUnsynced(1)"
              />
              <px-button variant="secondary" size="sm" @click="loadUnsynced(1)">{{ $t('Search') }}</px-button>
            </div>
            <div class="pxcfg__rowbtns">
              <px-button variant="ghost" size="sm" icon="repeat" @click="loadClientStats">{{ $t('Refresh_Stats') }}</px-button>
              <px-button variant="ghost" size="sm" icon="repeat" @click="loadUnsynced(page)">{{ $t('Refresh_List') }}</px-button>
            </div>
          </div>

          <px-table
            v-if="unsynced.items && unsynced.items.length"
            :columns="unsyncedColumns"
            :rows="unsynced.items"
            row-key="id"
            selectable
            :selected="selectedIds"
            has-row-actions
            @update:selected="v => selectedIds = v"
          >
            <template #cell-name="{ row }">
              <div class="pxcfg__strong">{{ row.name || '-' }}</div>
              <div class="pxcfg__muted">{{ row.email || '-' }}</div>
            </template>
            <template #cell-created_at="{ row }">
              <div>{{ fmtDate(row.created_at) }}</div>
              <div class="pxcfg__muted">{{ fmtTime(row.created_at) }}</div>
            </template>
            <template #row-actions="{ row }">
              <px-button
                variant="ghost" size="sm"
                :loading="syncingRowId === row.id"
                :disabled="syncingRowId === row.id || !status.has_token"
                @click="syncOne(row)"
              >{{ $t('Sync') }}</px-button>
            </template>
          </px-table>
          <px-empty-state v-else icon="check-circle" :title="$t('All_clients_are_synced') + ' 🎉'" />

          <div class="pxcfg__cardfoot">
            <div class="pxcfg__rowbtns">
              <px-button
                variant="primary" size="sm"
                :disabled="selectedIds.length === 0 || syncingClients || !status.has_token"
                @click="syncSelected"
              >{{ $t('Sync_Selected') }} ({{ selectedIds.length }})</px-button>
              <px-button variant="ghost" size="sm" @click="selectedIds = []">{{ $t('Clear_Selection') }}</px-button>
            </div>
            <div class="pxcfg__pager">
              <px-button variant="ghost" size="sm" icon-only icon="chevron-left" :disabled="page <= 1" @click="loadUnsynced(page - 1)" />
              <span>{{ $t('Page') }} {{ page }} / {{ unsynced.last_page || 1 }}</span>
              <px-button variant="ghost" size="sm" icon-only icon="chevron-right" :disabled="page >= unsynced.last_page" @click="loadUnsynced(page + 1)" />
            </div>
          </div>
        </px-card>

        <px-alert
          v-if="syncReport"
          class="pxcfg__alert"
          :tone="(syncReport.failed_count || 0) > 0 ? 'warning' : 'success'"
        >
          {{ $t('Synced') }}: <strong>{{ syncReport.synced_count }}</strong>,
          {{ $t('Failed') }}: <strong>{{ syncReport.failed_count || 0 }}</strong>
          <details v-if="(syncReport.failures || []).length" class="pxcfg__details">
            <summary>{{ $t('Show_failures') }}</summary>
            <ul>
              <li v-for="f in syncReport.failures" :key="f.id">
                #{{ f.id }} — {{ f.name || '(' + $t('no_name') + ')' }} — <code>{{ f.error }}</code>
              </li>
            </ul>
          </details>
        </px-alert>

        <div class="pxcfg__cardnote">
          <strong>{{ $t('notes') }}:</strong>
          <ul>
            <li>{{ $t('Only_clients_without_quickbooks_customer_id_are_candidates') }}</li>
            <li>{{ $t('If_matching_email_exists_reuse_instead_of_duplicate') }}</li>
          </ul>
        </div>
      </div>

      <!-- ================= Audit ================= -->
      <div v-show="qbTab === 'audit'" class="pxcfg__panel">
        <px-card flush class="pxcfg__card">
          <div class="pxcfg__cardhead">
            <vs-px
              style="min-width: 180px"
              :options="levelOptions.map(o => ({ label: o.text, value: o.value }))"
              :reduce="o => o.value"
              :value="level"
              :clearable="false"
              @input="v => { level = v || ''; loadAudits(1); }"
            />
            <px-button variant="ghost" size="sm" icon="repeat" @click="loadAudits(page)">{{ $t('Refresh') }}</px-button>
          </div>

          <div class="pxcfg__table">
            <table>
              <thead>
                <tr>
                  <th>#</th>
                  <th>{{ $t('When') }}</th>
                  <th>{{ $t('Operation') }}</th>
                  <th>{{ $t('Level') }}</th>
                  <th>{{ $t('Sale') }}</th>
                  <th>{{ $t('Realm_ID') }}</th>
                  <th>{{ $t('Env') }}</th>
                  <th>{{ $t('Message') }}</th>
                  <th>HTTP</th>
                </tr>
              </thead>
              <tbody>
                <tr v-for="a in audits.data" :key="a.id">
                  <td>{{ a.id }}</td>
                  <td>{{ fmtWhen(a.created_at) }}</td>
                  <td>{{ a.operation }}</td>
                  <td><px-badge :tone="levelTone(a.level)">{{ a.level }}</px-badge></td>
                  <td>{{ a.sale_id || '-' }}</td>
                  <td>{{ a.realm_id || '-' }}</td>
                  <td>{{ a.environment }}</td>
                  <td class="pxcfg__truncate">{{ a.message }}</td>
                  <td>{{ a.http_code || '-' }}</td>
                </tr>
              </tbody>
            </table>
          </div>

          <div class="pxcfg__cardfoot">
            <span class="pxcfg__muted">{{ $t('Total') }}: {{ audits.total }}</span>
            <div class="pxcfg__pager">
              <px-button variant="ghost" size="sm" icon-only icon="chevron-left" :disabled="!audits.prev_page_url" @click="loadAudits(page - 1)" />
              <span>{{ $t('Page') }} {{ page }} / {{ audits.last_page || 1 }}</span>
              <px-button variant="ghost" size="sm" icon-only icon="chevron-right" :disabled="!audits.next_page_url" @click="loadAudits(page + 1)" />
            </div>
          </div>
        </px-card>
      </div>
    </template>
  </div>
</template>

<script>
import NProgress from 'nprogress';
import PxPageHeader from "@/components/px-next/PxPageHeader.vue";
import PxCard from "@/components/px-next/PxCard.vue";
import PxButton from "@/components/px-next/PxButton.vue";
import PxField from "@/components/px-next/PxField.vue";
import PxInput from "@/components/px-next/PxInput.vue";
import PxBadge from "@/components/px-next/PxBadge.vue";
import PxAlert from "@/components/px-next/PxAlert.vue";
import PxStat from "@/components/px-next/PxStat.vue";
import PxTable from "@/components/px-next/PxTable.vue";
import PxEmptyState from "@/components/px-next/PxEmptyState.vue";
import VsPx from "@/views/app/products/next/edit/VsPx.vue";

export default {
  metaInfo: { title: 'QuickBooks Sync' },
  components: { PxPageHeader, PxCard, PxButton, PxField, PxInput, PxBadge, PxAlert, PxStat, PxTable, PxEmptyState, VsPx },
  data() {
    return {
      // page state
      isLoading: true,
      busy: false,
      qbTab: 'connection',

      // connection / settings / audits
      status: { env:'', redirect:'', callback:'', has_token:false, token_realm:null, updated_at:'', connect_url:'' },
      form: { client_id:'', client_secret:'', redirect:'', env:'Development', realm_id:'', income_account_name:'' },
      envOptions: [
        { value: 'Development', text: this.$t('Development') },
        { value: 'Production', text: this.$t('Production') },
      ],
      level: '',
      levelOptions: [
        { value: '', text: this.$t('All') },
        { value: 'error', text: this.$t('Errors') },
        { value: 'warning', text: this.$t('Warnings') },
        { value: 'info', text: this.$t('Info') },
      ],
      audits: { data: [], total: 0, last_page: 1, next_page_url: null, prev_page_url: null },
      page: 1,

      // clients sync
      loadingClients: false,
      syncingClients: false,
      syncingRowId: null,
      clientStats: { total: 0, synced: 0, not_synced: 0 },
      unsyncedBusy: false,
      unsynced: { items: [], last_page: 1, total: 0, current_page: 1 },
      search: '',
      selectedIds: [],
      syncReport: null,
    };
  },

  computed: {
    unsyncedColumns() {
      return [
        { key: 'id', label: this.$t('ID'), sortable: true },
        { key: 'name', label: this.$t('Client'), sortable: true },
        { key: 'created_at', label: this.$t('Added'), sortable: true },
      ];
    },
    callbackExample() {
      // For the Settings tab hint — what to put in Intuit App Redirect URIs
      const origin = window.location.origin;
      return `${origin}/quickbooks/callback`;
    },
    syncPercent() {
      const t = this.clientStats.total || 0;
      const s = this.clientStats.synced || 0;
      if (t === 0) return 0;
      return Math.round((s / t) * 100);
    },
  },

  methods: {
    // --------------------- helpers ---------------------
    tv(v) { return typeof v === 'string' ? v.trim() : v; },
    toast(variant, msg, title) {
      this.$root.$bvToast.toast(msg, { title, variant, solid: true });
    },
    levelTone(level) {
      if (level === 'error') return 'danger';
      if (level === 'warning') return 'warning';
      return 'success';
    },

    fmtWhen(iso) {
      if (!iso) return '-';
      try {
        const d = new Date(iso);
        return new Intl.DateTimeFormat(undefined, {
          timeZone: 'Africa/Casablanca',
          dateStyle: 'medium',
          timeStyle: 'short'
        }).format(d);
      } catch { return iso; }
    },
    fmtDate(iso) {
      if (!iso) return '-';
      return new Intl.DateTimeFormat(undefined, {
        timeZone: 'Africa/Casablanca',
        year: 'numeric', month: '2-digit', day: '2-digit'
      }).format(new Date(iso));
    },
    fmtTime(iso) {
      if (!iso) return '-';
      return new Intl.DateTimeFormat(undefined, {
        timeZone: 'Africa/Casablanca',
        hour: '2-digit', minute: '2-digit', second: '2-digit'
      }).format(new Date(iso));
    },

    // --------------------- cache clear ---------------------
    Clear_Cache() {
      NProgress.start(); NProgress.set(0.1);
      axios.get('/clear_cache')
        .then(() => this.toast('success', this.$t('Cache_cleared_successfully'), this.$t('Success')))
        .catch(() => this.toast('danger', this.$t('Failed_to_clear_cache'), this.$t('Failed')))
        .finally(() => NProgress.done());
    },

    // --------------------- connection / status ---------------------
    async loadStatus() {
      try {
        const { data } = await axios.get('/quickbooks/status');
        this.status = data;
      } catch {
        this.toast('danger', this.$t('Failed_to_load_status'), this.$t('QuickBooks'));
      }
    },
    connectBlank() {
      const url = this.status.connect_url || '/quickbooks/connect';
      window.location.href = url;
    },

    async disconnect() {
      this.busy = true; NProgress.start();
      try {
        await axios.post('/quickbooks/disconnect');
        this.toast('success', this.$t('Disconnected'), this.$t('QuickBooks'));
        await this.loadStatus();
      } catch {
        this.toast('danger', this.$t('Failed_to_disconnect'), this.$t('QuickBooks'));
      } finally { this.busy = false; NProgress.done(); }
    },

    // --------------------- settings (.env) ---------------------
    async loadSettings() {
      try {
        const { data } = await axios.get(`/quickbooks/settings?time=${Date.now()}`);
        this.form = { ...this.form, ...data };
      } catch {
        this.toast('danger', this.$t('Failed_to_load_settings'), this.$t('QuickBooks'));
      }
    },
    async save() {
      this.busy = true; NProgress.start();
      try {
        await axios.post('/quickbooks/settings', this.form);
        this.toast('success', this.$t('Settings_saved_to_env'), this.$t('QuickBooks'));
        await this.loadStatus();
      } catch {
        this.toast('danger', this.$t('Failed_to_save_settings'), this.$t('QuickBooks'));
      } finally { this.busy = false; NProgress.done(); }
    },

    // --------------------- audits ---------------------
    async loadAudits(p = 1) {
      this.page = p;
      try {
        const { data } = await axios.get('/quickbooks/audits', { params: { page: p, level: this.level }});
        this.audits = data;
      } catch {
        this.toast('danger', this.$t('Failed_to_load_audit_logs'), this.$t('QuickBooks'));
      }
    },

    // --------------------- clients sync: stats & list ---------------------
    async loadClientStats() {
      this.loadingClients = true;
      try {
        const { data } = await axios.get('/quickbooks/clients-stats');
        // backend should count NOT NULL and != ''
        this.clientStats = data;
      } catch {
        this.toast('danger', this.$t('Failed_to_load_client_stats'), this.$t('QuickBooks'));
      } finally {
        this.loadingClients = false;
      }
    },

    async loadUnsynced(p = 1) {
      this.unsyncedBusy = true;
      try {
        const { data } = await axios.get('/quickbooks/clients-unsynced', { params: { page: p, q: this.search } });
        this.unsynced = data;
        this.page = data.current_page || p;
      } catch {
        this.toast('danger', this.$t('Failed_to_load_unsynced_clients'), this.$t('QuickBooks'));
      } finally {
        this.unsyncedBusy = false;
      }
    },

    // --------------------- clients sync: actions ---------------------
    async syncAllClients() {
      if (!this.status.has_token) {
        this.toast('warning', this.$t('Connect_to_QuickBooks_first'), this.$t('QuickBooks'));
        return;
      }
      this.syncingClients = true; this.syncReport = null; NProgress.start();
      try {
        const { data } = await axios.post('/quickbooks/sync-clients');
        this.syncReport = data;
        await Promise.all([this.loadClientStats(), this.loadUnsynced(this.page)]);
        if ((data.synced_count || 0) > 0) {
          this.toast('success', `${this.$t('Synced')} ${data.synced_count} ${this.$t('Clients')}`, this.$t('QuickBooks'));
        } else {
          // Common reasons: no unsynced clients, email missing, or not connected
          this.toast('warning', data.note || this.$t('No_clients_were_synced'), this.$t('QuickBooks'));
        }
      } catch {
        this.toast('danger', this.$t('Failed_to_sync_clients'), this.$t('QuickBooks'));
      } finally {
        this.syncingClients = false; NProgress.done();
      }
    },

    async syncSelected() {
      if (!this.status.has_token) {
        this.toast('warning', this.$t('Connect_to_QuickBooks_first'), this.$t('QuickBooks'));
        return;
      }
      if (this.selectedIds.length === 0) return;

      this.syncingClients = true; this.syncReport = null; NProgress.start();
      try {
        const { data } = await axios.post('/quickbooks/sync-clients', { ids: this.selectedIds });
        this.syncReport = data;
        this.selectedIds = [];
        await Promise.all([this.loadClientStats(), this.loadUnsynced(this.page)]);
        this.toast((data.synced_count||0)>0 ? 'success' : 'warning',
                   `${this.$t('Synced')} ${data.synced_count} ${this.$t('Selected')}`,
                   this.$t('QuickBooks'));
      } catch {
        this.toast('danger', this.$t('Sync_selected_failed'), this.$t('QuickBooks'));
      } finally {
        this.syncingClients = false; NProgress.done();
      }
    },

    async syncOne(row) {
      if (!this.status.has_token) {
        this.toast('warning', this.$t('Connect_to_QuickBooks_first'), this.$t('QuickBooks'));
        return;
      }
      this.syncingRowId = row.id; this.syncReport = null; NProgress.start();
      try {
        const { data } = await axios.post('/quickbooks/sync-clients', { ids: [row.id] });
        this.syncReport = data;
        await Promise.all([this.loadClientStats(), this.loadUnsynced(this.page)]);
        this.toast((data.synced_count||0)>0 ? 'success' : 'warning', this.$t('Client_sync_attempted'), this.$t('QuickBooks'));
      } catch {
        this.toast('danger', this.$t('Client_sync_failed'), this.$t('QuickBooks'));
      } finally {
        this.syncingRowId = null; NProgress.done();
      }
    },
  },

  async created() {
    try {
      await Promise.all([
        this.loadStatus(),
        this.loadSettings(),
        this.loadAudits(1),
        this.loadClientStats(),
        this.loadUnsynced(1),
      ]);
    } finally {
      this.isLoading = false;
    }
  }
};
</script>

<style lang="scss" src="@/assets/styles/sass/px-next/production.scss"></style>

<style lang="scss" scoped>
.pxcfg { min-height: 100%; background: var(--pxn-bg); padding: var(--pxn-space-8) var(--pxn-space-9) var(--pxn-space-9); }
@media (max-width: 620px) { .pxcfg { padding: var(--pxn-space-6) var(--pxn-space-5); } }
.pxcfg__pad { padding: var(--pxn-space-6) 0; }
.pxcfg__card { margin-top: var(--pxn-space-4); }
.pxcfg__panel { padding-top: var(--pxn-space-2); }

.pxcfg__tabbar {
  display: flex; flex-wrap: wrap; gap: var(--pxn-space-1);
  border-bottom: 1px solid var(--pxn-border);
  margin-top: var(--pxn-space-5);
}
.pxcfg__tab {
  appearance: none; background: transparent; border: 0;
  border-bottom: 2px solid transparent;
  padding: var(--pxn-space-3) var(--pxn-space-4);
  font-size: var(--pxn-fs-sm); font-weight: 500; color: var(--pxn-text-muted);
  cursor: pointer; transition: color .12s ease, border-color .12s ease;
}
.pxcfg__tab:hover { color: var(--pxn-text); }
.pxcfg__tab.is-active { color: var(--pxn-primary); border-bottom-color: var(--pxn-primary); font-weight: 600; }

.pxcfg__deflist { display: grid; gap: var(--pxn-space-2); }
.pxcfg__defrow { display: grid; grid-template-columns: 200px minmax(0, 1fr); gap: var(--pxn-space-4); align-items: baseline; font-size: var(--pxn-fs-sm); }
.pxcfg__defrow > span { color: var(--pxn-text-muted); }
.pxcfg__defrow > b { font-weight: 500; word-break: break-word; }
@media (max-width: 560px) { .pxcfg__defrow { grid-template-columns: minmax(0, 1fr); gap: var(--pxn-space-1); } }

.pxcfg__formgrid { display: grid; grid-template-columns: repeat(2, minmax(0, 1fr)); gap: var(--pxn-space-4) var(--pxn-space-5); }
@media (max-width: 640px) { .pxcfg__formgrid { grid-template-columns: minmax(0, 1fr); } }

.pxcfg__statgrid { display: grid; grid-template-columns: repeat(4, minmax(0, 1fr)); gap: var(--pxn-space-4); margin-top: var(--pxn-space-4); }
@media (max-width: 900px) { .pxcfg__statgrid { grid-template-columns: repeat(2, minmax(0, 1fr)); } }
@media (max-width: 520px) { .pxcfg__statgrid { grid-template-columns: minmax(0, 1fr); } }
.pxcfg__synccard { display: flex; flex-direction: column; align-items: center; justify-content: center; gap: var(--pxn-space-2); text-align: center; }

.pxcfg__progress { margin-top: var(--pxn-space-5); }
.pxcfg__progress-head { display: flex; justify-content: space-between; color: var(--pxn-text-muted); margin-bottom: var(--pxn-space-1); }
.pxcfg__bar { height: 8px; border-radius: 9999px; background: var(--pxn-surface-2); overflow: hidden; }
.pxcfg__bar-fill { height: 100%; background: var(--pxn-primary); transition: width .2s ease; }

.pxcfg__cardhead { display: flex; flex-wrap: wrap; gap: var(--pxn-space-3); align-items: center; justify-content: space-between; padding: var(--pxn-space-4) var(--pxn-space-4) var(--pxn-space-3); }
.pxcfg__search { display: flex; gap: var(--pxn-space-2); align-items: center; flex: 1 1 280px; }
.pxcfg__cardfoot { display: flex; flex-wrap: wrap; gap: var(--pxn-space-3); align-items: center; justify-content: space-between; padding: var(--pxn-space-3) var(--pxn-space-4) var(--pxn-space-4); }
.pxcfg__rowbtns { display: flex; gap: var(--pxn-space-2); flex-wrap: wrap; }
.pxcfg__pager { display: flex; align-items: center; gap: var(--pxn-space-2); font-size: var(--pxn-fs-sm); color: var(--pxn-text-muted); }

.pxcfg__strong { font-weight: 600; }
.pxcfg__muted { color: var(--pxn-text-muted); font-size: var(--pxn-fs-sm); }

.pxcfg__alert { margin-top: var(--pxn-space-4); }
.pxcfg__details { margin-top: var(--pxn-space-2); font-size: var(--pxn-fs-sm); }
.pxcfg__details ul { margin: var(--pxn-space-2) 0 0; padding-left: var(--pxn-space-5); }
.pxcfg__cardnote { margin-top: var(--pxn-space-4); color: var(--pxn-text-muted); font-size: var(--pxn-fs-sm); }
.pxcfg__cardnote ul { margin: var(--pxn-space-1) 0 0; padding-left: var(--pxn-space-5); }

.pxcfg__table { overflow-x: auto; }
.pxcfg__table table { width: 100%; border-collapse: collapse; font-size: var(--pxn-fs-sm); }
.pxcfg__table th, .pxcfg__table td { text-align: left; padding: var(--pxn-space-2) var(--pxn-space-3); border-bottom: 1px solid var(--pxn-border); white-space: nowrap; }
.pxcfg__table th { position: sticky; top: 0; background: var(--pxn-surface); color: var(--pxn-text-muted); font-weight: 600; }
.pxcfg__truncate { max-width: 300px; overflow: hidden; text-overflow: ellipsis; white-space: nowrap; }
</style>
