<template>
  <div class="main-content">
    <breadcumb :page="'Faltantes'" :folder="'Inventario'" />
    <b-card class="issues-card">
      <div class="issues-toolbar">
        <div><h4 class="mb-1">Faltantes e incidencias</h4><p class="text-muted mb-0">Mercancía faltante o defectuosa detectada durante la recepción de transferencias.</p></div>
        <div class="d-flex align-items-center"><b-form-select v-model="status" :options="statusOptions" class="mr-2" @change="load" /><b-button variant="outline-primary" :disabled="loading" @click="load">Actualizar</b-button></div>
      </div>
      <div class="mt-3 mb-3"><span class="badge badge-warning">{{ openCount }} abiertas</span></div>
      <div v-if="loading" class="issues-empty">Cargando incidencias…</div>
      <div v-else-if="error" class="alert alert-danger">{{ error }}</div>
      <div v-else-if="!issues.length" class="issues-empty">No hay incidencias para este filtro.</div>
      <div v-else class="table-responsive">
        <table class="table table-hover">
          <thead><tr><th>Transferencia</th><th>Producto</th><th>Ruta</th><th>Tipo</th><th class="text-right">Cantidad</th><th>Estado</th><th>Reportado</th><th v-if="canManage">Acción</th></tr></thead>
          <tbody><tr v-for="issue in issues" :key="issue.id">
            <td><strong>{{ issue.reference }}</strong></td>
            <td>{{ issue.product_name }}<div v-if="issue.variant_name" class="small text-muted">{{ issue.variant_name }}</div></td>
            <td>{{ issue.from_warehouse || '—' }} → {{ issue.to_warehouse || '—' }}</td>
            <td><span class="badge" :class="issue.type === 'missing' ? 'badge-warning' : 'badge-danger'">{{ issue.type === 'missing' ? 'Faltante' : 'Defectuoso' }}</span></td>
            <td class="text-right">{{ fmt(issue.quantity) }}</td>
            <td><span class="badge" :class="issue.resolution_status === 'open' ? 'badge-warning' : 'badge-success'">{{ issue.resolution_status === 'open' ? 'Abierta' : 'Resuelta' }}</span></td>
            <td>{{ formatDate(issue.reported_at) }}</td>
            <td v-if="canManage"><b-button v-if="issue.resolution_status === 'open'" size="sm" variant="outline-primary" @click="openResolve(issue)">Resolver</b-button><span v-else class="text-muted small">{{ resolutionLabel(issue) }}</span></td>
          </tr></tbody>
        </table>
      </div>
    </b-card>

    <b-modal v-model="showResolve" title="Resolver incidencia" hide-footer>
      <div v-if="selected">
        <p class="mb-3"><strong>{{ selected.reference }}</strong> · {{ selected.product_name }} · {{ fmt(selected.quantity) }}</p>
        <b-form-group label="Resolución"><b-form-select v-model="form.resolution_code" :options="resolutionOptions" /></b-form-group>
        <b-form-group v-if="form.resolution_code === 'reconciled_by_adjustment'" label="Referencia del ajuste"><b-form-input v-model.trim="form.resolution_reference" maxlength="120" /></b-form-group>
        <b-form-group label="Notas"><b-form-textarea v-model.trim="form.resolution_notes" rows="4" maxlength="3000" /></b-form-group>
        <div v-if="resolveError" class="alert alert-danger">{{ resolveError }}</div>
        <div class="text-right"><b-button variant="secondary" class="mr-2" @click="showResolve=false">Cancelar</b-button><b-button variant="primary" :disabled="saving || !canSubmit" @click="resolve">{{ saving ? 'Guardando…' : 'Resolver incidencia' }}</b-button></div>
      </div>
    </b-modal>
  </div>
</template>

<script>
export default {
  name: 'InventoryMissing',
  data() { return { issues: [], openCount: 0, canManage: false, resolutions: {}, loading: false, error: '', status: 'open', statusOptions: [{value:'open',text:'Abiertas'},{value:'resolved',text:'Resueltas'},{value:'',text:'Todas'}], showResolve:false, selected:null, saving:false, resolveError:'', form:{resolution_code:'',resolution_reference:'',resolution_notes:''} }; },
  computed: {
    resolutionOptions() { if (!this.selected) return []; return [{value:'',text:'Selecciona una resolución',disabled:true}].concat((this.resolutions[this.selected.type] || []).map(r => ({value:r.value,text:r.label}))); },
    canSubmit() { return !!this.form.resolution_code && !!this.form.resolution_notes && (this.form.resolution_code !== 'reconciled_by_adjustment' || !!this.form.resolution_reference); }
  },
  mounted(){ this.load(); },
  methods: {
    fmt(v){ return Number(v||0).toLocaleString('es-HN',{maximumFractionDigits:3}); },
    formatDate(v){ if(!v) return '—'; const d=new Date(v); return Number.isNaN(d.getTime()) ? v : d.toLocaleString('es-HN'); },
    resolutionLabel(issue){ const list=this.resolutions[issue.type]||[]; const found=list.find(r=>r.value===issue.resolution_code); return found ? found.label : (issue.resolution_code || 'Resuelta'); },
    async load(){ this.loading=true; this.error=''; try{ const {data}=await axios.get('/api/transfer-logistics/issues',{params:this.status?{status:this.status}:{},baseURL:'',meta:{skipErrorRedirect:true}}); this.issues=data.issues||[]; this.openCount=Number(data.open_count||0); this.canManage=!!data.can_manage; this.resolutions=data.resolutions||{}; }catch(e){ this.issues=[]; this.error=(e&&e.message)||'No fue posible cargar las incidencias.'; }finally{this.loading=false;} },
    openResolve(issue){ this.selected=issue; this.resolveError=''; this.form={resolution_code:'',resolution_reference:'',resolution_notes:''}; this.showResolve=true; },
    async resolve(){ if(!this.selected||!this.canSubmit)return; this.saving=true; this.resolveError=''; try{ await axios.post(`/api/transfer-logistics/issues/${this.selected.id}/resolve`,this.form,{baseURL:'',meta:{skipErrorRedirect:true}}); this.showResolve=false; await this.load(); }catch(e){ this.resolveError=(e&&e.message)||'No fue posible resolver la incidencia.'; }finally{this.saving=false;} }
  }
};
</script>

<style scoped>
.issues-card{border:1px solid #e4e7ec;border-radius:10px}.issues-toolbar{display:flex;align-items:center;justify-content:space-between;gap:16px}.issues-empty{padding:40px 12px;text-align:center;color:#667085}.table td,.table th{vertical-align:middle}@media(max-width:700px){.issues-toolbar{align-items:flex-start;flex-direction:column}.issues-toolbar>div:last-child{width:100%}}
</style>
