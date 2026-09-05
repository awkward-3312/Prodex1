<template>
  <div class="px-next pxcfg">
    <px-page-header
      title="Facturación electrónica ZATCA (Fase 2)"
      :breadcrumbs="[{ label: $t('Settings'), href: '#/app/settings/System_settings' }, { label: 'ZATCA' }]"
    />

    <div v-if="isLoading" class="pxcfg__pad">
      <px-skeleton variant="lines" :rows="8" />
    </div>

    <template v-else>
      <div class="pxcfg__tabbar">
        <button v-for="t in tabs" :key="t.key" type="button" class="pxcfg__tab pxn-ring"
          :class="{ 'is-active': activeTab === t.key }" @click="selectTab(t.key)">{{ t.label }}</button>
      </div>

      <!-- Activación -->
      <div v-show="activeTab === 'activation'" class="pxcfg__panel">
        <px-card title="Estado de integración con Fatoora">
          <div class="pxcfg__deflist">
            <div><span>Entorno</span><px-badge :tone="form.environment === 'production' ? 'danger' : 'info'">{{ environmentLabel(form.environment) }}</px-badge></div>
            <div><span>Activación</span><px-badge :tone="statusTone">{{ statusLabel }}</px-badge></div>
            <div><span>Número VAT de la empresa</span><strong>{{ company.vat_number || '— configúralo en Configuración del sistema' }}</strong></div>
            <div><span>Serie del dispositivo (EGS)</span><strong class="pxn-num">{{ form.device_serial }}</strong></div>
            <div><span>Contador de facturas (ICV)</span><strong class="pxn-num">{{ form.icv }}</strong></div>
          </div>

          <template v-if="form.onboarding_status !== 'ready'">
            <px-alert v-if="!company.vat_number" tone="warning" class="pxcfg__alert">
              Configura el número VAT de la empresa (15 dígitos, comienza y termina en 3) en
              <router-link :to="{ name: 'system_settings' }">Configuración del sistema</router-link> antes de realizar la activación.
            </px-alert>
            <form class="pxcfg__inlineform" @submit.prevent="onboard">
              <px-field label="OTP del portal Fatoora">
                <template #default="{ id }"><px-input :id="id" :value="otp" @input="v => otp = tv(v)" placeholder="123456" /></template>
              </px-field>
              <div class="pxcfg__inlinebtns">
                <px-button variant="primary" type="submit" :loading="busy" :disabled="busy || !company.vat_number" @click="onboard">Iniciar activación</px-button>
                <px-button variant="secondary" :disabled="busy" @click="regenerateCsr">Regenerar CSR</px-button>
              </div>
            </form>
            <small class="pxcfg__cardnote">
              Obtén el OTP desde el portal Fatoora (fatoora.zatca.gov.sa → Onboard New Solution Unit/Device).
              En el entorno sandbox se acepta cualquier combinación de 6 dígitos. El proceso ejecuta: CSR → Compliance CSID → verificaciones de cumplimiento de los tipos de documentos declarados → Production CSID.
            </small>
          </template>
          <template v-else>
            <px-alert tone="success" class="pxcfg__alert">
              Activación completada. Las facturas se están reportando {{ form.auto_submit ? 'automáticamente' : 'manualmente' }} en el caso simplificado y procesando en el caso estándar con ZATCA.
            </px-alert>
            <form class="pxcfg__inlineform" @submit.prevent="onboard">
              <px-field label="OTP">
                <template #default="{ id }"><px-input :id="id" :value="otp" @input="v => otp = tv(v)" placeholder="OTP" /></template>
              </px-field>
              <px-button class="pxcfg__del" variant="ghost" type="submit" :disabled="busy || !otp" @click="onboard">Reactivar con un nuevo CSID</px-button>
            </form>
          </template>
        </px-card>

        <px-card v-if="complianceChecks && complianceChecks.length" title="Resultados de las verificaciones de cumplimiento" class="pxcfg__card">
          <div class="pxcfg__scrollbox">
            <table class="pxcfg__table">
              <thead><tr><th>Tipo de documento</th><th>Resultado</th><th>Mensajes</th></tr></thead>
              <tbody>
                <tr v-for="(c, i) in complianceChecks" :key="i">
                  <td>{{ c.type }}</td>
                  <td><px-badge :tone="c.passed ? 'success' : 'danger'">{{ c.passed ? (translateComplianceStatus(c.status) || 'APROBADO') : 'FALLÓ (HTTP ' + c.http_status + ')' }}</px-badge></td>
                  <td>
                    <div v-for="(m, j) in (c.messages || [])" :key="j" class="pxcfg__msg">
                      <px-badge :tone="m.level === 'error' ? 'danger' : (m.level === 'warning' ? 'warning' : 'neutral')">{{ messageLevelLabel(m.level) }}</px-badge>
                      {{ m.code }} — {{ m.message }}
                    </div>
                  </td>
                </tr>
              </tbody>
            </table>
          </div>
        </px-card>
      </div>

      <!-- Configuración -->
      <div v-show="activeTab === 'config'" class="pxcfg__panel">
        <px-card>
          <form @submit.prevent="save">
            <div class="pxcfg__grid pxcfg__grid--3">
              <px-field label="Habilitar ZATCA Fase 2">
                <template #default><px-check type="switch" :modelValue="!!form.enabled" @change="v => form.enabled = v">{{ form.enabled ? 'Habilitado' : 'Deshabilitado' }}</px-check></template>
              </px-field>
              <px-field label="Enviar automáticamente las nuevas facturas">
                <template #default><px-check type="switch" :modelValue="!!form.auto_submit" @change="v => form.auto_submit = v">{{ form.auto_submit ? 'Automático' : 'Manual' }}</px-check></template>
              </px-field>
              <px-field label="Entorno" hint="Cambiar el entorno restablece las credenciales y requiere realizar nuevamente la activación.">
                <template #default="{ id }"><vs-px :input-id="id" v-model="form.environment" :reduce="o => o.value" :options="environmentOptions.map(o => ({ label: o.text, value: o.value }))" :clearable="false" /></template>
              </px-field>
            </div>

            <h4 class="pxcfg__subhead">Datos de la unidad EGS / CSR</h4>
            <div class="pxcfg__grid pxcfg__grid--3">
              <px-field label="Nombre común (CN)"><template #default="{ id }"><px-input :id="id" :value="form.common_name" @input="v => form.common_name = tv(v)" placeholder="Se genera automáticamente si queda vacío" /></template></px-field>
              <px-field label="Nombre de la organización"><template #default="{ id }"><px-input :id="id" :value="form.organization_name" @input="v => form.organization_name = tv(v)" :placeholder="company.name || ''" /></template></px-field>
              <px-field label="Sucursal / Unidad organizativa"><template #default="{ id }"><px-input :id="id" :value="form.organization_unit" @input="v => form.organization_unit = tv(v)" placeholder="Sucursal principal" /></template></px-field>
              <px-field label="Número de registro comercial (CRN)"><template #default="{ id }"><px-input :id="id" :value="form.crn" @input="v => form.crn = tv(v)" /></template></px-field>
              <px-field label="Categoría comercial / Industria"><template #default="{ id }"><px-input :id="id" :value="form.business_category" @input="v => form.business_category = tv(v)" placeholder="Comercio minorista" /></template></px-field>
              <px-field label="Tipos de factura admitidos"><template #default="{ id }"><vs-px :input-id="id" v-model="form.invoice_types" :reduce="o => o.value" :options="invoiceTypeOptions.map(o => ({ label: o.text, value: o.value }))" :clearable="false" /></template></px-field>
            </div>

            <h4 class="pxcfg__subhead">Dirección nacional del vendedor</h4>
            <div class="pxcfg__grid pxcfg__grid--3">
              <px-field label="Nombre de la calle"><template #default="{ id }"><px-input :id="id" :value="form.street_name" @input="v => form.street_name = tv(v)" /></template></px-field>
              <px-field label="N.º de edificio (4 dígitos)"><template #default="{ id }"><px-input :id="id" maxlength="4" :value="form.building_number" @input="v => form.building_number = tv(v)" /></template></px-field>
              <px-field label="ID de parcela"><template #default="{ id }"><px-input :id="id" maxlength="10" :value="form.plot_identification" @input="v => form.plot_identification = tv(v)" /></template></px-field>
              <px-field label="Distrito"><template #default="{ id }"><px-input :id="id" :value="form.sub_division" @input="v => form.sub_division = tv(v)" /></template></px-field>
              <px-field label="Ciudad"><template #default="{ id }"><px-input :id="id" :value="form.city" @input="v => form.city = tv(v)" /></template></px-field>
              <px-field label="Código postal (5 dígitos)"><template #default="{ id }"><px-input :id="id" maxlength="5" :value="form.postal_zone" @input="v => form.postal_zone = tv(v)" /></template></px-field>
            </div>

            <h4 class="pxcfg__subhead">Líneas con tasa cero</h4>
            <div class="pxcfg__grid">
              <px-field label="Código del motivo de exención de VAT"><template #default="{ id }"><px-input :id="id" :value="form.zero_tax_reason_code" @input="v => form.zero_tax_reason_code = tv(v)" placeholder="VATEX-SA-32" /></template></px-field>
              <px-field label="Descripción del motivo de exención de VAT"><template #default="{ id }"><px-input :id="id" :value="form.zero_tax_reason" @input="v => form.zero_tax_reason = tv(v)" placeholder="Exportación de bienes" /></template></px-field>
            </div>

            <div class="pxcfg__actions">
              <px-button variant="primary" type="submit" :loading="busy" :disabled="busy" @click="save">Guardar</px-button>
            </div>
          </form>
        </px-card>
      </div>

      <!-- Documentos -->
      <div v-show="activeTab === 'documents'" class="pxcfg__panel">
        <px-card>
          <div class="pxcfg__cardhead">
            <vs-px class="pxcfg__inlinesearch" v-model="docStatus" :reduce="o => o.value" :clearable="false"
              :options="docStatusOptions.map(o => ({ label: o.text, value: o.value }))" @input="loadDocuments(1)" />
            <px-button variant="secondary" size="sm" icon="refresh-cw" @click="loadDocuments(docPage)">Actualizar</px-button>
          </div>
          <div class="pxcfg__scrollbox">
            <table class="pxcfg__table">
              <thead><tr><th>#</th><th>Factura</th><th>Origen</th><th>Tipo</th><th>ICV</th><th>Estado</th><th>Enviado</th><th>Incidencias</th><th></th></tr></thead>
              <tbody>
                <tr v-for="doc in documents.data" :key="doc.id">
                  <td class="pxn-num">{{ doc.id }}</td><td>{{ doc.invoice_number }}</td><td>{{ sourceLabel(doc.source) }}</td><td>{{ typeLabel(doc) }}</td><td class="pxn-num">{{ doc.icv }}</td>
                  <td><px-badge :tone="docTone(doc.status)">{{ documentStatusLabel(doc.status) }}</px-badge></td>
                  <td class="pxcfg__cardnote">{{ doc.submitted_at }}</td>
                  <td class="pxcfg__cardnote">
                    <div v-if="doc.errors && doc.errors.length" class="pxcfg__err">{{ firstMessage(doc.errors) }}</div>
                    <div v-else-if="doc.warnings && doc.warnings.length" class="pxcfg__warntext">{{ firstMessage(doc.warnings) }}</div>
                  </td>
                  <td class="pxcfg__tr">
                    <px-button size="sm" variant="secondary" @click="downloadXml(doc)">XML</px-button>
                    <px-button v-if="doc.status === 'failed'" class="pxcfg__del" size="sm" variant="ghost" :disabled="busy" @click="retry(doc)">Reintentar</px-button>
                  </td>
                </tr>
                <tr v-if="!documents.data.length"><td colspan="9" class="pxcfg__tc pxcfg__cardnote">Aún no hay documentos.</td></tr>
              </tbody>
            </table>
          </div>
          <div class="pxcfg__pager">
            <span>Total: {{ documents.total }}</span>
            <div class="pxcfg__pagerbtns">
              <px-button size="sm" variant="ghost" :disabled="!documents.prev_page_url" @click="loadDocuments(docPage - 1)">Anterior</px-button>
              <span class="pxn-num">{{ documents.current_page }} / {{ documents.last_page || 1 }}</span>
              <px-button size="sm" variant="ghost" :disabled="!documents.next_page_url" @click="loadDocuments(docPage + 1)">Siguiente</px-button>
            </div>
          </div>
        </px-card>
      </div>
    </template>
  </div>
</template>

<script>
import PxPageHeader from "@/components/px-next/PxPageHeader.vue";
import PxButton from "@/components/px-next/PxButton.vue";
import PxCard from "@/components/px-next/PxCard.vue";
import PxField from "@/components/px-next/PxField.vue";
import PxInput from "@/components/px-next/PxInput.vue";
import PxCheck from "@/components/px-next/PxCheck.vue";
import PxBadge from "@/components/px-next/PxBadge.vue";
import PxAlert from "@/components/px-next/PxAlert.vue";
import VsPx from "@/views/app/products/next/edit/VsPx.vue";

export default {
  metaInfo: { title: 'Facturación electrónica ZATCA' },
  components: { PxPageHeader, PxButton, PxCard, PxField, PxInput, PxCheck, PxBadge, PxAlert, "vs-px": VsPx },
  data() {
    return {
      isLoading: true,
      busy: false,
      activeTab: 'activation',
      tabs: [
        { key: 'activation', label: 'Activación' },
        { key: 'config', label: 'Configuración' },
        { key: 'documents', label: 'Documentos' }
      ],
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
    statusTone() { return { not_started: 'neutral', csid_issued: 'warning', compliance_checked: 'info', ready: 'success' }[this.form.onboarding_status] || 'neutral'; },
  },
  created() { this.load(); this.loadDocuments(1); },
  methods: {
    tv(v) { return typeof v === 'string' ? v.trim() : v; },
    selectTab(key) { this.activeTab = key; if (key === 'documents') this.loadDocuments(1); },
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
    docTone(status) { return { reported: 'success', cleared: 'success', failed: 'danger', pending: 'warning' }[status] || 'neutral'; },
    firstMessage(list) { const m = (list || [])[0] || {}; return m.message || m.code || JSON.stringify(m); },
  },
};
</script>

<style lang="scss" src="@/assets/styles/sass/px-next/production.scss"></style>

<style lang="scss" scoped>
.pxcfg { min-height: 100%; background: var(--pxn-bg); padding: var(--pxn-space-8) var(--pxn-space-9) var(--pxn-space-9); }
@media (max-width: 620px) { .pxcfg { padding: var(--pxn-space-6) var(--pxn-space-5); } }
.pxcfg__pad { padding: var(--pxn-space-6) 0; }
.pxcfg__card { margin-top: var(--pxn-space-5); }
.pxcfg__alert { margin-top: var(--pxn-space-4); }
.pxcfg__cardnote { font-size: var(--pxn-fs-xs); color: var(--pxn-ink-3); }
.pxcfg__tabbar { display: flex; gap: var(--pxn-space-2); margin-top: var(--pxn-space-5); border-bottom: 1px solid var(--pxn-border); }
.pxcfg__tab { appearance: none; background: none; border: 0; border-bottom: 2px solid transparent; padding: var(--pxn-space-3) var(--pxn-space-4); font: inherit; font-size: var(--pxn-fs-sm); font-weight: var(--pxn-fw-medium); color: var(--pxn-ink-3); cursor: pointer; transition: color 120ms, border-color 120ms; }
.pxcfg__tab:hover { color: var(--pxn-ink); }
.pxcfg__tab.is-active { color: var(--pxn-ink); border-bottom-color: var(--pxn-primary); font-weight: var(--pxn-fw-semibold); }
.pxcfg__panel { margin-top: var(--pxn-space-5); }
.pxcfg__deflist { display: grid; gap: var(--pxn-space-2); font-size: var(--pxn-fs-sm); }
.pxcfg__deflist > div { display: flex; gap: var(--pxn-space-3); align-items: center; }
.pxcfg__deflist span { min-width: 220px; color: var(--pxn-ink-3); }
.pxcfg__inlineform { display: flex; flex-wrap: wrap; align-items: flex-end; gap: var(--pxn-space-4); margin-top: var(--pxn-space-4); }
.pxcfg__inlineform .pxn-field { max-width: 220px; }
.pxcfg__inlinebtns { display: flex; gap: var(--pxn-space-3); }
.pxcfg__grid { display: grid; grid-template-columns: repeat(2, minmax(0, 1fr)); gap: var(--pxn-space-4) var(--pxn-space-5); }
.pxcfg__grid--3 { grid-template-columns: repeat(3, minmax(0, 1fr)); }
@media (max-width: 900px) { .pxcfg__grid--3 { grid-template-columns: repeat(2, minmax(0, 1fr)); } }
@media (max-width: 640px) { .pxcfg__grid, .pxcfg__grid--3 { grid-template-columns: minmax(0, 1fr); } }
.pxcfg__subhead { margin: var(--pxn-space-6) 0 var(--pxn-space-3); font-size: var(--pxn-fs-sm); font-weight: var(--pxn-fw-semibold); }
.pxcfg__actions { display: flex; gap: var(--pxn-space-3); margin-top: var(--pxn-space-5); }
.pxcfg__cardhead { display: flex; flex-wrap: wrap; gap: var(--pxn-space-3); align-items: center; justify-content: space-between; margin-bottom: var(--pxn-space-4); }
.pxcfg__inlinesearch { max-width: 220px; }
.pxcfg__scrollbox { max-height: 420px; overflow: auto; border: 1px solid var(--pxn-border); border-radius: var(--pxn-radius-md); }
.pxcfg__table { width: 100%; border-collapse: collapse; font-size: var(--pxn-fs-sm); }
.pxcfg__table th { position: sticky; top: 0; background: var(--pxn-surface-2); z-index: 1; text-align: left; font-size: var(--pxn-fs-xs); text-transform: uppercase; letter-spacing: 0.04em; color: var(--pxn-ink-3); padding: var(--pxn-space-3) var(--pxn-space-4); }
.pxcfg__table td { padding: var(--pxn-space-3) var(--pxn-space-4); border-top: 1px solid var(--pxn-border); vertical-align: middle; }
.pxcfg__tr { text-align: right; white-space: nowrap; display: flex; gap: var(--pxn-space-2); justify-content: flex-end; }
.pxcfg__tc { text-align: center; }
.pxcfg__msg { margin: var(--pxn-space-1) 0; font-size: var(--pxn-fs-xs); }
.pxcfg__err { color: var(--pxn-danger); }
.pxcfg__warntext { color: var(--pxn-warning); }
.pxcfg__pager { display: flex; align-items: center; justify-content: space-between; margin-top: var(--pxn-space-3); font-size: var(--pxn-fs-sm); }
.pxcfg__pagerbtns { display: flex; align-items: center; gap: var(--pxn-space-3); }
.pxcfg__del ::v-deep .pxn-btn__label { color: var(--pxn-danger); }
</style>
