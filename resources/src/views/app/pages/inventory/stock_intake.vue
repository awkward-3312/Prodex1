<template>
  <div class="main-content">
    <breadcumb page="Ingreso de stock" folder="Operaciones"/>

    <b-card class="mb-3">
      <div class="d-flex flex-wrap justify-content-between align-items-start">
        <div>
          <h4 class="mb-1">Ingreso de stock</h4>
          <p class="text-muted mb-0">Revisa mercancía en camino hacia tus bodegas. Una transferencia solo aumenta tu inventario cuando un usuario autorizado confirma la recepción física.</p>
        </div>
        <div class="mt-2 mt-md-0">
          <b-button variant="outline-primary" class="mr-2" @click="load"><lucide-icon name="refresh-cw" class="mr-1"/> Actualizar</b-button>
          <b-button variant="primary" @click="openScanner"><lucide-icon name="scan-line" class="mr-1"/> Escanear QR</b-button>
        </div>
      </div>
    </b-card>

    <div v-if="loading" class="loading_page spinner spinner-primary mr-3"></div>

    <template v-else>
      <b-row class="mb-3">
        <b-col md="4" class="mb-2">
          <b-card class="h-100">
            <small class="text-muted text-uppercase">Pendientes de recibir</small>
            <div class="display-4 mt-1">{{ incoming.length }}</div>
          </b-card>
        </b-col>
        <b-col md="4" class="mb-2">
          <b-card class="h-100">
            <small class="text-muted text-uppercase">Notificaciones sin leer</small>
            <div class="display-4 mt-1">{{ unread }}</div>
          </b-card>
        </b-col>
        <b-col md="4" class="mb-2">
          <b-card class="h-100">
            <small class="text-muted text-uppercase">Regla de inventario</small>
            <strong class="d-block mt-2">En tránsito ≠ stock disponible</strong>
            <small class="text-muted">Solo lo recibido correctamente entra a existencias vendibles.</small>
          </b-card>
        </b-col>
      </b-row>

      <b-card>
        <div class="d-flex flex-wrap justify-content-between align-items-center mb-3">
          <div>
            <h5 class="mb-1">Transferencias entrantes</h5>
            <small class="text-muted">Solo aparecen transferencias destinadas a tus bodegas asignadas.</small>
          </div>
          <b-form-input v-model="search" placeholder="Buscar referencia, origen o destino" style="max-width:340px" class="mt-2 mt-md-0"/>
        </div>

        <div v-if="!filteredIncoming.length" class="text-center py-5 text-muted">
          No tienes transferencias en tránsito pendientes de recepción.
        </div>

        <div v-else class="table-responsive">
          <table class="table table-hover align-middle mb-0">
            <thead><tr><th>Referencia</th><th>Origen</th><th>Destino</th><th>Estado</th><th>Despachada</th><th class="text-right">Acción</th></tr></thead>
            <tbody>
              <tr v-for="transfer in filteredIncoming" :key="transfer.id">
                <td><strong>{{ transfer.reference }}</strong><div class="text-muted text-11">{{ transfer.items }} artículo(s)</div></td>
                <td>{{ transfer.from_warehouse || '—' }}</td>
                <td>{{ transfer.to_warehouse || '—' }}</td>
                <td><span class="badge" :class="transfer.logistics_status === 'partially_received' ? 'badge-warning' : 'badge-info'">{{ statusLabel(transfer.logistics_status) }}</span></td>
                <td>{{ formatDate(transfer.dispatched_at) }}</td>
                <td class="text-right">
                  <b-button size="sm" variant="primary" @click="receive(transfer)">Revisar y recibir</b-button>
                </td>
              </tr>
            </tbody>
          </table>
        </div>
      </b-card>
    </template>

    <b-modal id="manual-scan-modal" hide-footer size="md" title="Escanear transferencia">
      <p class="text-muted">Usa la cámara desde el botón de transferencias de la barra superior o pega aquí el código seguro TRF-… impreso en el envío.</p>
      <b-form @submit.prevent="openToken">
        <b-form-group label="Código de recepción">
          <b-form-input v-model.trim="manualToken" placeholder="TRF-..." autofocus/>
        </b-form-group>
        <div v-if="scanError" class="alert alert-danger">{{ scanError }}</div>
        <div class="d-flex justify-content-end">
          <b-button variant="outline-secondary" class="mr-2" @click="$bvModal.hide('manual-scan-modal')">Cancelar</b-button>
          <b-button type="submit" variant="primary">Abrir transferencia</b-button>
        </div>
      </b-form>
    </b-modal>
  </div>
</template>

<script>
export default {
  metaInfo: { title: 'Ingreso de stock' },
  data() {
    return { loading:true, incoming:[], notifications:[], unread:0, search:'', manualToken:'', scanError:'' };
  },
  computed: {
    filteredIncoming() {
      const q = (this.search || '').trim().toLowerCase();
      if (!q) return this.incoming;
      return this.incoming.filter(t => [t.reference,t.from_warehouse,t.to_warehouse,t.logistics_status].filter(Boolean).join(' ').toLowerCase().includes(q));
    },
  },
  created() { this.load(); },
  methods: {
    config() { return { meta:{skipErrorRedirect:true} }; },
    async load() {
      this.loading = true;
      try {
        const [incoming, notifications] = await Promise.all([
          axios.get('/transfer-logistics/incoming', this.config()),
          axios.get('/transfer-logistics/notifications', this.config()),
        ]);
        this.incoming = incoming.data.transfers || [];
        this.notifications = notifications.data.notifications || [];
        this.unread = Number(notifications.data.unread || 0);
      } catch (e) {
        this.incoming = [];
        if (e && e.response && e.response.status === 403) this.$router.replace({name:'not_authorize'});
      } finally { this.loading = false; }
    },
    receive(transfer) {
      if (!transfer.receiving_token) return;
      window.location.href = `/transfer-receive/${encodeURIComponent(transfer.receiving_token)}`;
    },
    openScanner() {
      this.manualToken = '';
      this.scanError = '';
      const headerButton = document.getElementById('px-transfer-logistics-btn');
      if (headerButton) {
        headerButton.click();
        window.setTimeout(() => {
          const scan = document.querySelector('#px-transfer-logistics-panel [data-action="scan"]');
          if (scan) scan.click(); else this.$bvModal.show('manual-scan-modal');
        }, 20);
      } else this.$bvModal.show('manual-scan-modal');
    },
    openToken() {
      let token = (this.manualToken || '').trim();
      const match = token.match(/(TRF-[A-Z0-9-]+)/i);
      if (match) token = match[1].toUpperCase();
      if (!/^TRF-[A-Z0-9-]+$/i.test(token)) {
        this.scanError = 'El código de recepción no tiene un formato válido.';
        return;
      }
      window.location.href = `/transfer-receive/${encodeURIComponent(token)}`;
    },
    statusLabel(status) { return status === 'partially_received' ? 'Recepción parcial' : 'En tránsito'; },
    formatDate(value) {
      if (!value) return '—';
      try { return new Intl.DateTimeFormat('es-HN',{dateStyle:'medium',timeStyle:'short'}).format(new Date(value)); }
      catch (e) { return value; }
    },
  },
};
</script>
