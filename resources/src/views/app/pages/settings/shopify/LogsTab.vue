<template>
  <div>
    <b-card class="sync-card shadow-sm">
      <template #header>
        <div class="d-flex align-items-center justify-content-between">
          <div class="d-flex align-items-center">
            <lucide-icon class="mr-2 text-primary" name="clipboard-list" />
            <h5 class="mb-0 font-weight-bold">{{ $t('View_Logs') }}</h5>
          </div>
          <div>
            <b-form-select v-model="level" :options="levelOptions" size="sm" class="d-inline-block mr-2" style="width:auto" @change="load(1)" />
            <b-button size="sm" variant="outline-secondary" class="mr-2" @click="load(page)">
              <lucide-icon name="refresh-cw" /> {{ $t('Refresh') }}
            </b-button>
            <b-button size="sm" variant="outline-danger" @click="clearLogs">
              <lucide-icon name="trash-2" /> {{ $t('Clear_Logs') }}
            </b-button>
          </div>
        </div>
      </template>

      <b-table :items="logs" :fields="fields" responsive striped hover show-empty :empty-text="$t('There_are_no_records_to_show')">
        <template #cell(created_at)="{ item }">
          {{ formatDate(item.created_at) }}
        </template>
        <template #cell(level)="{ item }">
          <b-badge :variant="levelToVariant(item.level)">{{ item.level }}</b-badge>
        </template>
        <template #cell(context)="{ item }">
          <b-button size="sm" variant="link" class="p-0" @click="selectedLog = item">{{ $t('Details') }}</b-button>
        </template>
      </b-table>

      <b-pagination
        v-if="total > perPage"
        v-model="page"
        :total-rows="total"
        :per-page="perPage"
        @change="load"
        align="center"
      />
    </b-card>

    <b-modal :visible="!!selectedLog" :title="$t('Log_Details')" hide-footer @hidden="selectedLog = null">
      <div v-if="selectedLog">
        <p class="mb-1"><strong>{{ $t('Action') }}:</strong> {{ selectedLog.action }}</p>
        <p class="mb-1"><strong>{{ $t('Message') }}:</strong> {{ selectedLog.message }}</p>
        <pre class="log-context">{{ JSON.stringify(selectedLog.context, null, 2) }}</pre>
      </div>
    </b-modal>
  </div>
</template>

<script>
import moment from 'moment';

export default {
  props: { store: { type: Object, default: null } },
  data() {
    return {
      logs: [],
      page: 1,
      perPage: 25,
      total: 0,
      level: null,
      selectedLog: null,
    };
  },
  computed: {
    fields() {
      return [
        { key: 'created_at', label: this.$t('date') },
        { key: 'action', label: this.$t('Action') },
        { key: 'level', label: this.$t('Level') },
        { key: 'message', label: this.$t('Message') },
        { key: 'context', label: this.$t('Details') },
      ];
    },
    levelOptions() {
      return [
        { value: null, text: this.$t('All') },
        { value: 'info', text: 'info' },
        { value: 'warning', text: 'warning' },
        { value: 'error', text: 'error' },
      ];
    },
  },
  watch: {
    'store.id'() { this.load(1); },
  },
  methods: {
    load(page) {
      const params = { page: page || this.page, per_page: this.perPage };
      if (this.store) params.store_id = this.store.id;
      if (this.level) params.level = this.level;
      axios.get('shopify/logs', { params }).then(({ data }) => {
        this.logs = data.data || [];
        this.total = data.total || 0;
        this.page = data.current_page || 1;
      }).catch(() => { this.logs = []; });
    },
    clearLogs() {
      this.$swal({
        title: this.$t('Delete_Title'),
        type: 'warning',
        icon: 'warning',
        showCancelButton: true,
      }).then(result => {
        if (!result.value) return;
        const params = this.store ? { store_id: this.store.id } : {};
        axios.delete('shopify/logs', { params }).then(() => this.load(1));
      });
    },
    levelToVariant(level) {
      if (level === 'error') return 'danger';
      if (level === 'warning') return 'warning';
      return 'success';
    },
    formatDate(date) {
      return date ? moment(date).format('YYYY-MM-DD HH:mm') : '';
    },
  },
  created() {
    this.load(1);
    this.$emit('ready');
  }
};
</script>

<style scoped>
.sync-card {
  border-radius: 12px;
  border: none;
}

.sync-card ::v-deep .card-header {
  background: linear-gradient(135deg, #f5f7fa 0%, #c3cfe2 100%);
  border-bottom: 2px solid #e9ecef;
  padding: 1rem 1.5rem;
  border-radius: 12px 12px 0 0;
}

.log-context {
  background: #f8f9fa;
  border: 1px solid #e9ecef;
  border-radius: 8px;
  padding: 1rem;
  white-space: pre-wrap;
  word-break: break-word;
  max-height: 400px;
  overflow: auto;
  font-size: 13px;
}
</style>
