<template>
  <div class="main-content">
    <breadcumb page="Facturación electrónica ZATCA (Fase 2)" :folder="$t('Settings')" />
    <div v-if="isLoading" class="loading_page spinner spinner-primary mr-3"></div>

    <b-col md="12" v-if="!isLoading">
      <b-tabs pills content-class="mt-3">
        <b-tab title="Activación" active>
          <div class="row border rounded p-3 mt-3">
            <b-col md="12" class="mb-3">
              <h5>Estado de integración con Fatoora</h5>
              <p class="mb-1"><strong>Entorno:</strong> <b-badge :variant="form.environment === 'production' ? 'danger' : 'info'">{{ environmentLabel(form.environment) }}</b-badge></p>
              <p class="mb-1"><strong>Activación:</strong> <b-badge :variant="statusVariant">{{ statusLabel }}</b-badge></p>
              <p class="mb-1"><strong>Número VAT de la empresa:</strong> {{ company.vat_number || '— configúralo en Configuración del sistema' }}</p>
              <p class="mb-1"><strong>Serie del dispositivo (EGS):</strong> {{ form.device_serial }}</p>
              <p class="mb-1"><strong>Contador de facturas (ICV):</strong> {{ form.icv }}</p>
            </b-col>

            <b-col md="12" v-if="form.onboarding_status !== 'ready'">
              <b-alert show variant="warning" v-if="!company.vat_number">
                Configura el número VAT de la empresa (15 dígitos, comienza y termina en 3) en
                <router-link :to="{ name: 'system_settings' }">Configuración del sistema</router-link> antes de realizar la activación.
              </b-alert>
              <b-form inline @submit.prevent="onboard">
                <label class="mr-2">OTP del portal Fatoora:</label>
                <b-form-input v-model.trim="otp" placeholder="123456" class="mr-2" style="max-width: 160px" required />
                <b-button variant="primary" type="submit" :disabled="busy || !company.vat_number"><span v-if="busy" class="spinner-border spinner-border-sm mr-1"></span>Iniciar activación</b-button>
                <b-button variant="outline-secondary" class="ml-2" :disabled="busy" @click="regenerateCsr">Regenerar CSR</b-button>
              </b-form>
              <small class="text-muted d-block mt-2">
                Obtén el OTP desde el portal Fatoora (fatoora.zatca.gov.sa → Onboard New Solution Unit/Device).
                En el entorno sandbox se acepta cualquier combinación de 6 dígitos. El proceso ejecuta: CSR → Compliance CSID → verificaciones de cumplimiento de los tipos de documentos declarados → Production CSID.
              </small>
            </b-col>

            <b-col md="12" v-else>
              <b-alert show variant="success">
                Activación completada. Las facturas se están reportando {{ form.auto_submit ? 'automáticamente' : 'manualmente' }} en el caso simplificado y procesando en el caso estándar con ZATCA.
              </b-alert>
              <b-form inline @submit.prevent="onboard">
                <b-form-input v-model.trim="otp" placeholder="OTP" class="mr-2" style="max-width: 140px" />
                <b-button variant="outline-danger" type="submit" :disabled="busy || !otp">Reactivar con un nuevo CSID</b-button>
              </b-form>
            </b-col>

            <b-col md="12" class="mt-3" v-if="complianceChecks && complianceChecks.length">
              <h6>Resultados de las verificaciones de cumplimiento</h6>
              <div class="table-responsive">
                <table class="table table-sm table-bordered">
                  <thead><tr><th>Tipo de documento</th><th>Resultado</th><th>Mensajes</th></tr></thead>
                  <tbody>
                    <tr v-for="(c, i) in complianceChecks" :key="i">
                      <td>{{ c.type }}</td>
                      <td><b-badge :variant="c.passed ? 'success' : 'danger'">{{ c.passed ? (translateComplianceStatus(c.status) || 'APROBADO') : 'FALLÓ (HTTP ' + c.http_status + ')' }}</b-badge></td>
                      <td><div v-for="(m, j) in (c.messages || [])" :key="j" class="small"><b-badge :variant="m.level === 'error' ? 'danger' : (m.level === 'warning' ? 'warning' : 'light')">{{ messageLevelLabel(m.level) }}</b-badge> {{ m.code }} — {{ m.message }}</div></td>
                    </tr>
                  </tbody>
                </table>
              </div>
            </b-col>
          </div>
        </b-tab>

        <b-tab title="Configuración">
          <div class="row border rounded p-3 mt-3">
            <b-form @submit.prevent="save" style="width:100%">
              <b-row>
                <b-col md="4"><b-form-group label="Habilitar ZATCA Fase 2"><b-form-checkbox v-model="form.enabled" switch>{{ form.enabled ? 'Habilitado' : 'Deshabilitado' }}</b-form-checkbox></b-form-group></b-col>
                <b-col md="4"><b-form-group label="Enviar automáticamente las nuevas facturas"><b-form-checkbox v-model="form.auto_submit" switch>{{ form.auto_submit ? 'Automático' : 'Manual' }}</b-form-checkbox></b-form-group></b-col>
                <b-col md="4"><b-form-group label="Entorno"><b-form-select v-model="form.environment" :options="environmentOptions" /><b-form-text class="text-muted">Cambiar el entorno restablece las credenciales y requiere realizar nuevamente la activación.</b-form-text></b-form-group></b-col>

                <b-col md="12"><hr><h6>Datos de la unidad EGS / CSR</h6></b-col>
                <b-col md="4"><b-form-group label="Nombre común (CN)"><b-form-input v-model.trim="form.common_name" placeholder="Se genera automáticamente si queda vacío" /></b-form-group></b-col>
                <b-col md="4"><b-form-group label="Nombre de la organización"><b-form-input v-model.trim="form.organization_name" :placeholder="company.name || ''" /></b-form-group></b-col>
                <b-col md="4"><b-form-group label="Sucursal / Unidad organizativa"><b-form-input v-model.trim="form.organization_unit" placeholder="Sucursal principal" /></b-form-group></b-col>
                <b-col md="4"><b-form-group label="Número de registro comercial (CRN)"><b-form-input v-model.trim="form.crn" /></b-form-group></b-col>
                <b-col md="4"><b-form-group label="Categoría comercial / Industria"><b-form-input v-model.trim="form.business_category" placeholder="Comercio minorista" /></b-form-group></b-col>
                <b-col md="4"><b-form-group label="Tipos de factura admitidos"><b-form-select v-model="form.invoice_types" :options="invoiceTypeOptions" /></b-form-group></b-col>

                <b-col md="12"><hr><h6>Dirección nacional del vendedor</h6></b-col>
                <b-col md="4"><b-form-group label="Nombre de la calle"><b-form-input v-model.trim="form.street_name" /></b-form-group></b-col>
                <b-col md="2"><b-form-group label="N.º de edificio (4 dígitos)"><b-form-input v-model.trim="form.building_number" maxlength="4" /></b-form-group></b-col>
                <b-col md="2"><b-form-group label="ID de parcela"><b-form-input v-model.trim="form.plot_identification" maxlength="10" /></b-form-group></b-col>
                <b-col md="4"><b-form-group label="Distrito"><b-form-input v-model.trim="form.sub_division" /></b-form-group></b-col>
                <b-col md="4"><b-form-group label="Ciudad"><b-form-input v-model.trim="form.city" /></b-form-group></b-col>
                <b-col md="2"><b-form-group label="Código postal (5 dígitos)"><b-form-input v-model.trim="form.postal_zone" maxlength="5" /></b-form-group></b-col>

                <b-col md="12"><hr><h6>Líneas con tasa cero</h6></b-col>
                <b-col md="4"><b-form-group label="Código del motivo de exención de VAT"><b-form-input v-model.trim="form.zero_tax_reason_code" placeholder="VATEX-SA-32" /></b-form-group></b-col>
                <b-col md="8"><b-form-group label="Descripción del motivo de exención de VAT"><b-form-input v-model.trim="form.zero_tax_reason" placeholder="Exportación de bienes" /></b-form-group></b-col>

                <b-col md="12" class="mt-2"><b-button variant="primary" type="submit" :disabled="busy"><span v-if="busy" class="spinner-border spinner-border-sm mr-1"></span>Guardar</b-button></b-col>
              </b-row>
            </b-form>
          </div>
        </b-tab>

        <b-tab title="Documentos" @click="loadDocuments(1)">
          <div class="row border rounded p-3 mt-3">
            <b-col md="12" class="mb-2 d-flex justify-content-between"><b-form-select v-model="docStatus" :options="docStatusOptions" style="max-width: 200px" @change="loadDocuments(1)" /><b-button size="sm" variant="outline-secondary" @click="loadDocuments(docPage)">Actualizar</b-button></b-col>
            <b-col md="12">
              <div class="table-responsive">
                <table class="table table-sm table-hover">
                  <thead><tr><th>#</th><th>Factura</th><th>Origen</th><th>Tipo</th><th>ICV</th><th>Estado</th><th>Enviado</th><th>Incidencias</th><th></th></tr></thead>
                  <tbody>
                    <tr v-for="doc in documents.data" :key="doc.id">
                      <td>{{ doc.id }}</td><td>{{ doc.invoice_number }}</td><td>{{ sourceLabel(doc.source) }}</td><td>{{ typeLabel(doc) }}</td><td>{{ doc.icv }}</td>
                      <td><b-badge :variant="docVariant(doc.status)">{{ documentStatusLabel(doc.status) }}</b-badge></td>
                      <td class="small">{{ doc.submitted_at }}</td>
                      <td class="small"><div v-if="doc.errors && doc.errors.length" class="text-danger">{{ firstMessage(doc.errors) }}</div><div v-else-if="doc.warnings && doc.warnings.length" class="text-warning">{{ firstMessage(doc.warnings) }}</div></td>
                      <td class="text-nowrap"><b-button size="sm" variant="outline-primary" @click="downloadXml(doc)" title="Descargar XML">XML</b-button><b-button v-if="doc.status === 'failed'" size="sm" variant="outline-danger" :disabled="busy" @click="retry(doc)">Reintentar</b-button></td>
                    </tr>
                    <tr v-if="!documents.data.length"><td colspan="9" class="text-center text-muted">Aún no hay documentos.</td></tr>
                  </tbody>
                </table>
              </div>
              <div class="d-flex justify-content-between align-items-center"><span>Total: {{ documents.total }}</span><div><b-button size="sm" :disabled="!documents.prev_page_url" @click="loadDocuments(docPage - 1)">Anterior</b-button><span class="mx-2">{{ documents.current_page }} / {{ documents.last_page || 1 }}</span><b-button size="sm" :disabled="!documents.next_page_url" @click="loadDocuments(docPage + 1)">Siguiente</b-button></div></div>
            </b-col>
          </div>
        </b-tab>
      </b-tabs>
    </b-col>
  </div>
</template>

<script>
export default {
  metaInfo: { title: 'Facturación electrónica ZATCA' },
  data() {
    return {
      isLoading: true,
      busy: false,
      otp: '',
      company: { name: '', name_ar: '', vat_number: '' },
      form: {
        enabled: false, auto_submit: true, environment: 'sandbox', common_name: '', organization_name: '', organization_unit: '', solution_name: 'Rasheed', solution_version: '1.0', device_serial: '', business_category: '', invoice_types: '1100', crn: '', street_name: '', building_number: '', plot_identification: '', sub_division: '', city: '', postal_zone: '', zero_tax_reason_code: 'VATEX-SA-32', zero_tax_reason: 'Exportación de bienes', onboarding_status: 'not_started', compliance_results: null, icv: 0,
      },
      environmentOptions: [],
      invoiceTypeOptions: [
        { value: '1100', text: 'Estándar + Simplificada (B2B y B2C)' },
        { value: '1000', text: 'Solo estándar (B2B)' },
        { value: '0100', text: 'Solo simplificada (B2C)' },
      ],
      complianceChecks: null,
      documents: { data: [], total: 0, last_page: 1, current_page: 1, next_page_url: null, prev_page_url: null },
      docPage: 1,
      docStatus: '',
      docStatusOptions: [
        { value: '', text: 'Todos los estados' },
        { value: 'reported', text: 'Reportado' },
        { value: 'cleared', text: 'Procesado' },
        { value: 'failed', text: 'Fallido' },
        { value: 'pending', text: 'Pendiente' },
      ],
    };
  },
  computed: {
    statusLabel() {
      return { not_started: 'No iniciada', csid_issued: 'Compliance CSID emitido', compliance_checked: 'Verificaciones de cumplimiento aprobadas', ready: 'Listo (Production CSID activo)' }[this.form.onboarding_status] || this.form.onboarding_status;
    },
    statusVariant() { return { not_started: 'secondary', csid_issued: 'warning', compliance_checked: 'info', ready: 'success' }[this.form.onboarding_status] || 'secondary'; },
  },
  created() { this.load(); this.loadDocuments(1); },
  methods: {
    toast(variant, msg, title) { this.$root.$bvToast.toast(msg, { title: title || 'ZATCA', variant, solid: true }); },
    environmentLabel(value) { return { production: 'Producción', sandbox: 'Sandbox', simulation: 'Simulación' }[value] || value; },
    messageLevelLabel(level) { return { error: 'Error', warning: 'Advertencia', info: 'Información' }[level] || level; },
    translateComplianceStatus(status) { return { PASSED: 'APROBADO', FAILED: 'FALLÓ', passed: 'Aprobado', failed: 'Falló' }[status] || status; },
    documentStatusLabel(status) { return { reported: 'Reportado', cleared: 'Procesado', failed: 'Fallido', pending: 'Pendiente' }[status] || status; },
    sourceLabel(source) { return { sale: 'Venta', sales: 'Ventas', return: 'Devolución', sale_return: 'Devolución de venta' }[source] || source; },
    async load() {
      try {
        const { data } = await axios.get('/zatca/settings');
        Object.assign(this.form, data.settings || {});
        this.company = data.company || this.company;
        this.environmentOptions = (data.environments || []).map(e => ({ value: e.value, text: this.environmentLabel(e.value || e.label) }));
        this.complianceChecks = this.form.compliance_results;
      } catch (e) { this.toast('danger', (e.response && e.response.data && e.response.data.message) || 'No se pudo cargar la configuración de ZATCA.'); }
      finally { this.isLoading = false; }
    },
    async save() {
      this.busy = true;
      try { const { data } = await axios.post('/zatca/settings', this.form); Object.assign(this.form, data.settings || {}); this.toast('success', 'Configuración guardada correctamente.'); }
      catch (e) { this.toast('danger', (e.response && e.response.data && e.response.data.message) || 'No se pudo guardar la configuración.'); }
      finally { this.busy = false; }
    },
    async onboard() {
      if (!this.otp) return;
      this.busy = true;
      try {
        const { data } = await axios.post('/zatca/onboard', { otp: this.otp });
        Object.assign(this.form, data.settings || {});
        this.complianceChecks = data.compliance_checks || [];
        if (data.success) this.toast('success', 'Activación completada; se emitió el Production CSID.');
        else this.toast('warning', 'La activación no se completó: ' + (data.status || data.message || 'revisa los resultados de cumplimiento'));
      } catch (e) { this.toast('danger', (e.response && e.response.data && e.response.data.message) || 'La activación falló.'); }
      finally { this.busy = false; this.otp = ''; }
    },
    async regenerateCsr() {
      this.busy = true;
      try { const { data } = await axios.post('/zatca/csr/regenerate'); Object.assign(this.form, data.settings || {}); this.toast('success', 'Se generaron un nuevo par de claves y un nuevo CSR.'); }
      catch (e) { this.toast('danger', (e.response && e.response.data && e.response.data.message) || 'No se pudo generar el CSR.'); }
      finally { this.busy = false; }
    },
    async loadDocuments(page) {
      this.docPage = Math.max(1, page || 1);
      try { const { data } = await axios.get('/zatca/documents', { params: { page: this.docPage, status: this.docStatus || undefined } }); this.documents = data; }
      catch (e) { this.toast('danger', 'No se pudieron cargar los documentos.'); }
    },
    async retry(doc) {
      this.busy = true;
      try { const { data } = await axios.post(`/zatca/${doc.source_kind}/${doc.source_id}/submit`); this.toast(data.success ? 'success' : 'warning', 'Estado: ' + this.documentStatusLabel(data.status)); this.loadDocuments(this.docPage); }
      catch (e) { this.toast('danger', (e.response && e.response.data && e.response.data.message) || 'No se pudo reintentar el envío.'); }
      finally { this.busy = false; }
    },
    downloadXml(doc) {
      axios.get(`/zatca/documents/${doc.id}/xml`, { responseType: 'blob' }).then(res => {
        const url = window.URL.createObjectURL(new Blob([res.data]));
        const link = document.createElement('a'); link.href = url; link.setAttribute('download', `${doc.invoice_number || 'factura'}-${doc.uuid}.xml`); document.body.appendChild(link); link.click(); link.remove();
      });
    },
    typeLabel(doc) { const t = { '388': 'Factura', '381': 'Nota de crédito', '383': 'Nota de débito' }[doc.type] || doc.type; return `${t} (${doc.subtype})`; },
    docVariant(status) { return { reported: 'success', cleared: 'success', failed: 'danger', pending: 'warning' }[status] || 'secondary'; },
    firstMessage(list) { const m = (list || [])[0] || {}; return m.message || m.code || JSON.stringify(m); },
  },
};
</script>