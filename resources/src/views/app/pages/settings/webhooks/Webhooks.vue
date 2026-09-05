<template>
  <div class="px-next pxcfg">
    <px-page-header
      :title="$t('Webhooks')"
      :breadcrumbs="[{ label: $t('Settings'), href: '#/app/settings/System_settings' }, { label: $t('Webhooks') }]"
    >
      <template #actions>
        <px-button variant="ghost" size="sm" icon="file-text" @click="$router.push('/app/settings/webhooks/delivery_logs')">{{ $t('Delivery_Logs') || 'Delivery Logs' }}</px-button>
        <px-button variant="ghost" size="sm" icon="file-text" @click="$router.push('/app/settings/webhooks/incoming_logs')">{{ $t('Incoming_Logs') || 'Incoming Logs' }}</px-button>
        <px-button v-if="canAdd" variant="primary" size="sm" icon="plus" @click="New_Webhook()">{{ $t('Add') }}</px-button>
      </template>
    </px-page-header>

    <px-toolbar :search="search" :search-placeholder="$t('Search_this_table')" @update:search="onSearchInput" />

    <div v-if="isLoading" class="pxcfg__pad">
      <px-skeleton variant="table" :rows="8" :columns="4" />
    </div>

    <template v-else>
      <div class="pxcfg__tablewrap">
        <px-table
          v-if="webhooks.length"
          :columns="columns"
          :rows="webhooks"
          row-key="id"
          :sort-key="serverParams.sort.field"
          :sort-dir="serverParams.sort.type"
          has-row-actions
          @sort="onSort"
        >
          <template #cell-events="{ row }">
            <px-badge v-for="e in (row.events || []).slice(0, 3)" :key="e" tone="info" class="pxcfg__mr">{{ e }}</px-badge>
            <span v-if="(row.events || []).length > 3">+{{ row.events.length - 3 }}</span>
          </template>
          <template #cell-is_active="{ row }">
            <px-badge :tone="row.is_active ? 'success' : 'neutral'">{{ row.is_active ? ($t('Active') || 'Active') : ($t('Inactive') || 'Inactive') }}</px-badge>
          </template>
          <template #row-actions="{ row }">
            <div class="pxcfg__rowbtns">
              <px-button v-if="canEdit" variant="ghost" size="sm" icon-only icon="send" aria-label="Send Test" @click="Test_Webhook(row.id)" />
              <px-button v-if="canEdit" variant="ghost" size="sm" icon-only icon="power" :aria-label="row.is_active ? 'Disable' : 'Enable'" @click="Toggle_Webhook(row)" />
              <px-button v-if="canEdit" variant="ghost" size="sm" icon-only icon="pencil" aria-label="Edit" @click="Edit_Webhook(row)" />
              <px-button v-if="canDelete" class="pxcfg__del" variant="ghost" size="sm" icon-only icon="trash-2" aria-label="Delete" @click="Remove_Webhook(row.id)" />
            </div>
          </template>
        </px-table>
        <px-empty-state v-else icon="webhook" title="Sin webhooks" description="Agrega un webhook para verlo en esta lista." />
      </div>

      <px-pagination
        v-if="webhooks.length"
        :page="serverParams.page"
        :per-page="Number(limit)"
        :total="Number(totalRows) || 0"
        @update:page="onPage"
        @update:perPage="onLimit"
      />
    </template>

    <px-modal v-model="modalOpen" :title="editmode ? $t('Edit') : $t('Add')" size="lg">
      <validation-observer ref="ref_create_webhook">
        <form @submit.prevent="Submit_webhook">
          <div class="pxcfg__formgrid">
            <div class="pxcfg__grid">
              <validation-provider ref="nameProvider" name="Name" :rules="{ required: true }" v-slot="v">
                <px-field :label="$t('Name') + ' *'" :error="v.errors[0]">
                  <template #default="{ id, invalid }"><px-input :id="id" v-model="webhook.name" :invalid="invalid" @input="v.validate" /></template>
                </px-field>
              </validation-provider>
              <px-field :label="$t('Status')">
                <template #default>
                  <px-check type="switch" :modelValue="!!webhook.is_active" @change="v => webhook.is_active = v">
                    {{ webhook.is_active ? ($t('Active') || 'Active') : ($t('Inactive') || 'Inactive') }}
                  </px-check>
                </template>
              </px-field>
            </div>

            <validation-provider ref="urlProvider" name="URL" :rules="{ required: true, url: true }" v-slot="v">
              <px-field :label="$t('URL') + ' *'" :error="v.errors[0]">
                <template #default="{ id, invalid }"><px-input :id="id" v-model="webhook.url" placeholder="https://example.com/webhook" :invalid="invalid" @input="v.validate" /></template>
              </px-field>
            </validation-provider>

            <px-field :label="$t('Events') + ' *'">
              <template #default>
                <px-check :modelValue="!!subscribeAll" @change="v => subscribeAll = v">{{ $t('Subscribe_to_all_events') || 'Subscribe to all events (*)' }}</px-check>
                <div v-if="!subscribeAll" class="pxcfg__eventsgrid">
                  <label v-for="e in availableEvents" :key="eventValue(e)" class="pxcfg__eventitem">
                    <input type="checkbox" :value="eventValue(e)" :checked="(webhook.events || []).includes(eventValue(e))" @change="toggleEvent(eventValue(e), $event.target.checked)" />
                    <span>{{ eventLabel(e) }}</span>
                  </label>
                </div>
              </template>
            </px-field>

            <div class="pxcfg__grid">
              <px-field :label="$t('Timeout_seconds') || 'Timeout (seconds)'">
                <template #default="{ id }"><px-input :id="id" type="number" min="1" max="60" v-model.number="webhook.timeout_seconds" /></template>
              </px-field>
              <px-field v-if="editmode" :label="$t('Secret') || 'Secret'"
                :hint="$t('Use_this_secret_to_verify_HMAC_SHA256_signatures_sent_in_X-Webhook-Signature') || 'Use this secret to verify HMAC-SHA256 signatures (X-Webhook-Signature).'">
                <template #default="{ id }">
                  <div class="pxcfg__inline">
                    <px-input :id="id" :value="webhook.secret" readonly />
                    <px-button variant="secondary" size="sm" @click="Regenerate_Secret">{{ $t('Regenerate') || 'Regenerate' }}</px-button>
                  </div>
                </template>
              </px-field>
            </div>
          </div>
        </form>
      </validation-observer>
      <template #footer="{ close }">
        <px-button variant="ghost" @click="close">{{ $t('Cancel') }}</px-button>
        <px-button variant="primary" icon="check" :loading="SubmitProcessing" :disabled="SubmitProcessing" @click="Submit_webhook">{{ $t('submit') }}</px-button>
      </template>
    </px-modal>
  </div>
</template>

<script>
import { mapGetters } from "vuex";
import NProgress from "nprogress";
import PxPageHeader from "@/components/px-next/PxPageHeader.vue";
import PxToolbar from "@/components/px-next/PxToolbar.vue";
import PxTable from "@/components/px-next/PxTable.vue";
import PxPagination from "@/components/px-next/PxPagination.vue";
import PxButton from "@/components/px-next/PxButton.vue";
import PxModal from "@/components/px-next/PxModal.vue";
import PxField from "@/components/px-next/PxField.vue";
import PxInput from "@/components/px-next/PxInput.vue";
import PxCheck from "@/components/px-next/PxCheck.vue";
import PxBadge from "@/components/px-next/PxBadge.vue";
import PxEmptyState from "@/components/px-next/PxEmptyState.vue";

export default {
  metaInfo: { title: "Webhooks" },
  components: { PxPageHeader, PxToolbar, PxTable, PxPagination, PxButton, PxModal, PxField, PxInput, PxCheck, PxBadge, PxEmptyState },
  data() {
    return {
      _searchTimer: null,
      modalOpen: false,
      isLoading: true,
      SubmitProcessing: false,
      serverParams: {
        columnFilters: {},
        sort: { field: "id", type: "desc" },
        page: 1,
        perPage: 10,
      },
      totalRows: 0,
      search: "",
      limit: "10",
      webhooks: [],
      availableEvents: [],
      editmode: false,
      webhook: this.emptyWebhook(),
      subscribeAll: false,
    };
  },
  watch: {
    subscribeAll(val) {
      if (val) {
        this.webhook.events = ["*"];
      } else if (this.webhook.events.length === 1 && this.webhook.events[0] === "*") {
        this.webhook.events = [];
      }
    },
  },
  computed: {
    ...mapGetters(["currentUserPermissions"]),
    canAdd() {
      return this.currentUserPermissions && this.currentUserPermissions.includes("webhooks_add");
    },
    canEdit() {
      return this.currentUserPermissions && this.currentUserPermissions.includes("webhooks_edit");
    },
    canDelete() {
      return this.currentUserPermissions && this.currentUserPermissions.includes("webhooks_delete");
    },
    columns() {
      return [
        { key: "name", label: this.$t("Name"), strong: true },
        { key: "url", label: "URL" },
        { key: "events", label: this.$t("Events") || "Events", sortable: false },
        { key: "is_active", label: this.$t("Status"), align: "center" },
      ];
    },
  },
  methods: {
    emptyWebhook() {
      return {
        id: "",
        name: "",
        url: "",
        events: [],
        is_active: true,
        timeout_seconds: 15,
        secret: "",
      };
    },
    eventValue(e) { return (e && typeof e === "object") ? (e.value != null ? e.value : e.text) : e; },
    eventLabel(e) { return (e && typeof e === "object") ? (e.text != null ? e.text : e.value) : e; },
    toggleEvent(value, checked) {
      const list = Array.isArray(this.webhook.events) ? this.webhook.events.slice() : [];
      const i = list.indexOf(value);
      if (checked && i === -1) list.push(value);
      else if (!checked && i > -1) list.splice(i, 1);
      this.webhook.events = list;
    },
    updateParams(newProps) {
      this.serverParams = Object.assign({}, this.serverParams, newProps);
    },
    onSearchInput(v) {
      this.search = v;
      if (this._searchTimer) clearTimeout(this._searchTimer);
      this._searchTimer = setTimeout(() => { this.updateParams({ page: 1 }); this.Get_Webhooks(1); }, 350);
    },
    onPage(p) { if (this.serverParams.page !== p) { this.updateParams({ page: p }); this.Get_Webhooks(p); } },
    onLimit(v) { if (this.limit !== String(v)) { this.limit = String(v); this.updateParams({ page: 1, perPage: Number(v) }); this.Get_Webhooks(1); } },
    onSort({ key, dir }) {
      this.updateParams({ sort: { type: dir, field: key } });
      this.Get_Webhooks(this.serverParams.page);
    },
    getValidationState({ dirty, validated, valid = null }) {
      return dirty || validated ? valid : null;
    },
    makeToast(variant, msg, title) {
      this.$root.$bvToast.toast(msg, { title, variant, solid: true });
    },
    syncValidators() {
      this.$nextTick(() => {
        if (this.$refs.nameProvider && this.$refs.nameProvider.syncValue) this.$refs.nameProvider.syncValue(this.webhook.name);
        if (this.$refs.urlProvider && this.$refs.urlProvider.syncValue) this.$refs.urlProvider.syncValue(this.webhook.url);
      });
    },
    New_Webhook() {
      this.webhook = this.emptyWebhook();
      this.subscribeAll = false;
      this.editmode = false;
      this.modalOpen = true;
      this.syncValidators();
    },
    Edit_Webhook(row) {
      const events = Array.isArray(row.events) ? [...row.events] : [];
      this.webhook = Object.assign(this.emptyWebhook(), row, { events });
      this.subscribeAll = events.length === 1 && events[0] === "*";
      this.editmode = true;
      this.modalOpen = true;
      this.syncValidators();
    },
    Submit_webhook() {
      this.$refs.ref_create_webhook.validate().then((success) => {
        if (!success) {
          this.makeToast("danger", this.$t("Please_fill_the_form_correctly"), this.$t("Failed"));
          return;
        }
        this.editmode ? this.Update_Webhook() : this.Store_Webhook();
      });
    },
    Store_Webhook() {
      this.SubmitProcessing = true;
      axios
        .post("webhooks", {
          name: this.webhook.name,
          url: this.webhook.url,
          events: this.webhook.events,
          is_active: this.webhook.is_active ? 1 : 0,
          timeout_seconds: this.webhook.timeout_seconds,
        })
        .then(() => {
          this.SubmitProcessing = false;
          this.modalOpen = false;
          this.makeToast("success", this.$t("Created_in_successfully") || "Created", this.$t("Success"));
          this.Get_Webhooks(this.serverParams.page);
        })
        .catch((err) => {
          this.SubmitProcessing = false;
          this.makeToast(
            "danger",
            (err.response && err.response.data && err.response.data.message) || "Error",
            this.$t("Failed")
          );
        });
    },
    Update_Webhook() {
      this.SubmitProcessing = true;
      axios
        .put("webhooks/" + this.webhook.id, {
          name: this.webhook.name,
          url: this.webhook.url,
          events: this.webhook.events,
          is_active: this.webhook.is_active ? 1 : 0,
          timeout_seconds: this.webhook.timeout_seconds,
        })
        .then(() => {
          this.SubmitProcessing = false;
          this.modalOpen = false;
          this.makeToast("success", this.$t("Updated_in_successfully") || "Updated", this.$t("Success"));
          this.Get_Webhooks(this.serverParams.page);
        })
        .catch((err) => {
          this.SubmitProcessing = false;
          this.makeToast(
            "danger",
            (err.response && err.response.data && err.response.data.message) || "Error",
            this.$t("Failed")
          );
        });
    },
    Remove_Webhook(id) {
      this.$swal({
        title: this.$t("Delete_Title"),
        text: this.$t("Delete_Text"),
        type: "warning",
        showCancelButton: true,
        confirmButtonColor: "var(--px-primary)",
        cancelButtonColor: "#d33",
        cancelButtonText: this.$t("Delete_cancelButtonText"),
        confirmButtonText: this.$t("Delete_confirmButtonText"),
      }).then((result) => {
        if (result.value) {
          axios
            .delete("webhooks/" + id)
            .then(() => {
              this.makeToast("success", this.$t("Deleted_in_successfully"), this.$t("Delete_Deleted"));
              this.Get_Webhooks(this.serverParams.page);
            })
            .catch(() =>
              this.makeToast("warning", this.$t("Delete_Therewassomethingwronge"), this.$t("Delete_Failed"))
            );
        }
      });
    },
    Toggle_Webhook(row) {
      axios
        .post("webhooks/" + row.id + "/toggle")
        .then((res) => {
          row.is_active = res.data.is_active;
          this.makeToast("success", "Webhook " + (row.is_active ? "enabled" : "disabled"), this.$t("Success"));
        })
        .catch(() => this.makeToast("danger", "Toggle failed", this.$t("Failed")));
    },
    Test_Webhook(id) {
      axios
        .post("webhooks/" + id + "/test")
        .then(() =>
          this.makeToast("info", "Test webhook queued — check Delivery Logs.", this.$t("Success"))
        )
        .catch(() => this.makeToast("danger", "Test dispatch failed", this.$t("Failed")));
    },
    Regenerate_Secret() {
      axios
        .post("webhooks/" + this.webhook.id + "/regenerate-secret")
        .then((res) => {
          this.webhook.secret = res.data.secret;
          this.makeToast("success", "Secret regenerated", this.$t("Success"));
        })
        .catch(() => this.makeToast("danger", "Regenerate failed", this.$t("Failed")));
    },
    Get_Webhooks(page) {
      NProgress.start();
      NProgress.set(0.1);
      axios
        .get("webhooks", {
          params: {
            page,
            SortField: this.serverParams.sort.field,
            SortType: this.serverParams.sort.type,
            search: this.search,
            limit: this.limit,
          },
        })
        .then((response) => {
          this.webhooks = response.data.webhooks;
          this.totalRows = response.data.totalRows;
          NProgress.done();
          this.isLoading = false;
        })
        .catch(() => {
          NProgress.done();
          setTimeout(() => (this.isLoading = false), 500);
        });
    },
    Get_Available_Events() {
      axios
        .get("webhooks/available-events")
        .then((res) => {
          this.availableEvents = res.data.events || [];
        })
        .catch(() => {});
    },
  },
  created() {
    this.Get_Webhooks(1);
    this.Get_Available_Events();
  },
};
</script>

<style lang="scss" src="@/assets/styles/sass/px-next/production.scss"></style>

<style lang="scss" scoped>
.pxcfg { min-height: 100%; background: var(--pxn-bg); padding: var(--pxn-space-8) var(--pxn-space-9) var(--pxn-space-9); }
@media (max-width: 620px) { .pxcfg { padding: var(--pxn-space-6) var(--pxn-space-5); } }
.pxcfg__pad { padding: var(--pxn-space-6) 0; }
.pxcfg__tablewrap { margin-top: var(--pxn-space-5); }
.pxcfg__mr { margin-right: var(--pxn-space-1); }
.pxcfg__rowbtns { display: flex; gap: var(--pxn-space-1); justify-content: flex-end; }
.pxcfg__del ::v-deep .pxn-btn__icon { color: var(--pxn-danger); }
.pxcfg__formgrid { display: grid; grid-template-columns: minmax(0, 1fr); gap: var(--pxn-space-4); }
.pxcfg__grid { display: grid; grid-template-columns: repeat(2, minmax(0, 1fr)); gap: var(--pxn-space-4) var(--pxn-space-5); }
@media (max-width: 560px) { .pxcfg__grid { grid-template-columns: minmax(0, 1fr); } }
.pxcfg__inline { display: flex; gap: var(--pxn-space-2); align-items: center; }
.pxcfg__eventsgrid { max-height: 240px; overflow-y: auto; padding: var(--pxn-space-3) var(--pxn-space-4); border: 1px solid var(--pxn-border); border-radius: var(--pxn-radius-md); background: var(--pxn-surface-2); display: grid; grid-template-columns: repeat(2, minmax(0, 1fr)); gap: var(--pxn-space-2) var(--pxn-space-5); margin-top: var(--pxn-space-3); }
@media (max-width: 560px) { .pxcfg__eventsgrid { grid-template-columns: minmax(0, 1fr); } }
.pxcfg__eventitem { display: flex; align-items: center; gap: var(--pxn-space-3); font-size: var(--pxn-fs-sm); cursor: pointer; margin: 0; }
.pxcfg__eventitem input { accent-color: var(--pxn-primary); }
</style>
