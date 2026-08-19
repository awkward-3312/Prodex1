<template>
  <div class="main-content">
    <breadcumb :page="$t('Customer_Maintenance_History')" :folder="$t('Service_Maintenance')" />

    <div v-if="isLoading" class="loading_page spinner spinner-primary mr-3"></div>

    <div v-else class="page-wrapper">
      <b-row class="mb-3">
        <b-col md="4"><b-form-group :label="$t('Customer')"><v-select :reduce="c => c.id" v-model="filters.client_id" :options="clients" label="name" :placeholder="$t('Choose_Customer')" @input="fetchHistory" /></b-form-group></b-col>
        <b-col md="3"><b-form-group :label="$t('From')"><b-form-input v-model="filters.from" type="date" @change="fetchHistory" /></b-form-group></b-col>
        <b-col md="3"><b-form-group :label="$t('To')"><b-form-input v-model="filters.to" type="date" @change="fetchHistory" /></b-form-group></b-col>
      </b-row>

      <vue-good-table mode="remote" :columns="columns" :totalRows="totalRows" :rows="rows" @on-page-change="onPageChange" @on-per-page-change="onPerPageChange" :pagination-options="{ enabled: true, mode: 'records', nextLabel: 'Siguiente', prevLabel: 'Anterior' }" styleClass="tableOne vgt-table">
        <template slot="table-row" slot-scope="props">
          <span v-if="props.column.field === 'job_type'">{{ jobTypeLabel(props.row.job_type) }}</span>
          <span v-else-if="props.column.field === 'status'">{{ statusLabel(props.row.status) }}</span>
          <span v-else>{{ props.formattedRow[props.column.field] }}</span>
        </template>
      </vue-good-table>
    </div>
  </div>
</template>

<script>
export default {
  name: 'CustomerMaintenanceHistory',
  data() {
    return {
      isLoading: true, rows: [], totalRows: 0, clients: [],
      serverParams: { page: 1, perPage: 10 },
      filters: { client_id: null, from: '', to: '' },
      columns: [
        { label: 'Fecha', field: 'scheduled_date' },
        { label: 'Cliente', field: 'client_name' },
        { label: 'Técnico', field: 'technician_name' },
        { label: 'Servicio / artículo', field: 'service_item' },
        { label: 'Tipo de trabajo', field: 'job_type' },
        { label: 'Estado', field: 'status' }
      ]
    };
  },
  async mounted() { await this.fetchHistory(); this.isLoading = false; },
  methods: {
    jobTypeLabel(value) {
      return { service: 'Servicio', repair: 'Reparación', installation: 'Instalación', consultation: 'Consulta', maintenance: 'Mantenimiento', inspection: 'Inspección' }[String(value || '').toLowerCase()] || value || '—';
    },
    statusLabel(value) {
      return { pending: 'Pendiente', intake: 'Recepción', diagnostic: 'Diagnóstico', quoted: 'Cotizado', approved: 'Aprobado', in_progress: 'En progreso', ready: 'Listo', completed: 'Completado', delivered: 'Entregado', cancelled: 'Cancelado', declined: 'Rechazado' }[String(value || '').toLowerCase()] || value || '—';
    },
    async fetchHistory() {
      const params = { page: this.serverParams.page, limit: this.serverParams.perPage, client_id: this.filters.client_id, from: this.filters.from, to: this.filters.to };
      const { data } = await axios.get('report/customer_maintenance_history', { params });
      this.rows = data.jobs || []; this.totalRows = data.totalRows || 0; this.clients = (data.clients || []).map(c => ({ id: c.id, name: c.name }));
    },
    onPageChange({ currentPage }) { this.serverParams.page = currentPage; this.fetchHistory(); },
    onPerPageChange({ currentPerPage }) { this.serverParams.perPage = currentPerPage; this.fetchHistory(); }
  }
};
</script>