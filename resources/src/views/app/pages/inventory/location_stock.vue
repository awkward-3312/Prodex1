<template>
  <div class="main-content">
    <breadcumb :page="'Existencias por ubicación'" :folder="'Inventario'" />
    <b-card class="inventory-card">
      <div class="inventory-toolbar">
        <div>
          <h4 class="mb-1">Existencias por ubicación</h4>
          <p class="text-muted mb-0">Consulta el inventario físico, reservado, disponible y en tránsito en toda la empresa.</p>
        </div>
        <b-button variant="outline-primary" :disabled="loading || query.trim().length < 2" @click="search">Actualizar</b-button>
      </div>

      <b-input-group class="mt-3 mb-4">
        <b-form-input v-model.trim="query" placeholder="Buscar producto por nombre o código" @keyup.enter="search" />
        <b-input-group-append><b-button variant="primary" :disabled="loading || query.length < 2" @click="search">Buscar</b-button></b-input-group-append>
      </b-input-group>

      <div v-if="query.length < 2 && !searched" class="inventory-empty">Escribe al menos 2 caracteres para consultar existencias.</div>
      <div v-else-if="loading" class="inventory-empty">Consultando existencias…</div>
      <div v-else-if="error" class="alert alert-danger mb-0">{{ error }}</div>
      <div v-else-if="searched && !products.length" class="inventory-empty">No encontramos productos con ese nombre o código.</div>

      <div v-for="product in products" :key="product.id" class="product-block">
        <div class="product-head">
          <div><strong>{{ product.name }}</strong><div class="text-muted small">{{ product.code || 'Sin código' }}</div></div>
          <div class="company-total"><strong>{{ fmt(product.company_available) }}</strong><span> disponible en la empresa</span></div>
        </div>

        <div v-if="!groups(product).length" class="inventory-empty py-3">Este producto todavía no tiene existencias por ubicación.</div>
        <div v-for="group in groups(product)" :key="group.key" class="owner-block" :class="{ current: group.current }">
          <div class="owner-head">
            <div><strong>{{ group.name }}</strong><span v-if="group.current" class="badge badge-info ml-2">MI SUCURSAL</span></div>
            <strong>{{ fmt(group.available) }} disponible</strong>
          </div>
          <div class="table-responsive">
            <table class="table table-sm mb-0">
              <thead><tr><th>Ubicación</th><th>Variante</th><th class="text-right">Disponible</th><th class="text-right">Físico</th><th class="text-right">Reservado</th></tr></thead>
              <tbody>
                <tr v-for="row in group.rows" :key="row.inventory_location_id + ':' + (row.product_variant_id || 0)">
                  <td>{{ row.location_name }} <span v-if="row.is_quarantine" class="badge badge-warning ml-1">Cuarentena</span></td>
                  <td>{{ row.variant_name || '—' }}</td>
                  <td class="text-right">{{ fmt(row.available) }}</td><td class="text-right">{{ fmt(row.physical) }}</td><td class="text-right">{{ fmt(row.reserved) }}</td>
                </tr>
              </tbody>
            </table>
          </div>
          <div v-if="transitFor(product, group) > 0" class="transit-note">En tránsito hacia este destino: <strong>{{ fmt(transitFor(product, group)) }}</strong></div>
        </div>
      </div>
    </b-card>
  </div>
</template>

<script>
export default {
  name: 'InventoryLocationStock',
  data() { return { query: '', products: [], loading: false, searched: false, error: '' }; },
  methods: {
    fmt(value) { return Number(value || 0).toLocaleString('es-HN', { maximumFractionDigits: 3 }); },
    async search() {
      if (this.query.trim().length < 2) return;
      this.loading = true; this.error = '';
      try {
        const { data } = await axios.get('/api/inventory-visibility/search', { params: { q: this.query.trim() }, baseURL: '', meta: { skipErrorRedirect: true } });
        this.products = data.products || []; this.searched = true;
      } catch (e) {
        this.products = []; this.searched = true;
        this.error = (e && e.message) || 'No fue posible consultar las existencias.';
      } finally { this.loading = false; }
    },
    groups(product) {
      const map = {};
      (product.locations || []).forEach(row => {
        const key = row.owner_type === 'branch' ? `b:${row.branch_id}` : `w:${row.warehouse_id}`;
        if (!map[key]) map[key] = { key, owner_type: row.owner_type, owner_id: row.owner_type === 'branch' ? row.branch_id : row.warehouse_id, name: row.owner_type === 'branch' ? (row.branch_name || 'Sucursal') : (row.warehouse_name || 'Centro de distribución'), current: !!row.is_current_branch, available: 0, rows: [] };
        map[key].rows.push(row); if (!row.is_quarantine) map[key].available += Number(row.available || 0);
      });
      return Object.values(map).sort((a,b) => a.current !== b.current ? (a.current ? -1 : 1) : a.name.localeCompare(b.name));
    },
    transitFor(product, group) {
      return (product.in_transit || []).filter(row => group.owner_type === 'branch' ? Number(row.branch_id) === Number(group.owner_id) : Number(row.warehouse_id) === Number(group.owner_id)).reduce((sum,row) => sum + Number(row.quantity || 0), 0);
    }
  }
};
</script>

<style scoped>
.inventory-card{border:1px solid #e4e7ec;border-radius:10px}.inventory-toolbar,.product-head,.owner-head{display:flex;align-items:center;justify-content:space-between;gap:16px}.inventory-empty{padding:36px 12px;text-align:center;color:#667085}.product-block{border:1px solid #e4e7ec;border-radius:10px;overflow:hidden;margin-bottom:18px}.product-head{padding:14px 16px;background:#f8fafc}.company-total{text-align:right}.company-total strong{font-size:20px}.owner-block{padding:14px 16px;border-top:1px solid #eaecf0}.owner-block.current{background:#f2fbfd}.owner-head{margin-bottom:10px}.transit-note{margin-top:10px;padding:8px 10px;border-radius:7px;background:#eff6ff;color:#1d4ed8;font-size:12px}@media(max-width:700px){.inventory-toolbar,.product-head,.owner-head{align-items:flex-start;flex-direction:column}.company-total{text-align:left}}
</style>
