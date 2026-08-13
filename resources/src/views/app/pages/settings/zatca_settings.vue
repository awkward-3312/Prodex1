<template>
  <div class="main-content">
    <breadcumb page="ZATCA E-Invoicing (Phase 2)" :folder="$t('Settings')" />
    <div v-if="isLoading" class="loading_page spinner spinner-primary mr-3"></div>

    <b-col md="12" v-if="!isLoading">
      <b-tabs pills content-class="mt-3">

        <!-- ======================= Status & Onboarding ======================= -->
        <b-tab title="Onboarding" active>
          <div class="row border rounded p-3 mt-3">
            <b-col md="12" class="mb-3">
              <h5>Fatoora Integration Status</h5>
              <p class="mb-1">
                <strong>Environment:</strong>
                <b-badge :variant="form.environment === 'production' ? 'danger' : 'info'">{{ form.environment }}</b-badge>
              </p>
              <p class="mb-1">
                <strong>Onboarding:</strong>
                <b-badge :variant="statusVariant">{{ statusLabel }}</b-badge>
              </p>
              <p class="mb-1"><strong>Company VAT Number:</strong> {{ company.vat_number || '— set it in System Settings' }}</p>
              <p class="mb-1"><strong>Device Serial (EGS):</strong> {{ form.device_serial }}</p>
              <p class="mb-1"><strong>Invoice Counter (ICV):</strong> {{ form.icv }}</p>
            </b-col>

            <b-col md="12" v-if="form.onboarding_status !== 'ready'">
              <b-alert show variant="warning" v-if="!company.vat_number">
                Set the company VAT number (15 digits, starts and ends with 3) in
                <router-link :to="{ name: 'system_settings' }">System Settings</router-link> before onboarding.
              </b-alert>
              <b-form inline @submit.prevent="onboard">
                <label class="mr-2">OTP from the Fatoora portal:</label>
                <b-form-input v-model.trim="otp" placeholder="123456" class="mr-2" style="max-width: 160px" required />
                <b-button variant="primary" type="submit" :disabled="busy || !company.vat_number">
                  <span v-if="busy" class="spinner-border spinner-border-sm mr-1"></span>
                  Start Onboarding
                </b-button>
                <b-button variant="outline-secondary" class="ml-2" :disabled="busy" @click="regenerateCsr">
                  Regenerate CSR
                </b-button>
              </b-form>
              <small class="text-muted d-block mt-2">
                Get the OTP from the Fatoora portal (fatoora.zatca.gov.sa &rarr; Onboard New Solution Unit/Device).
                On the sandbox environment any 6 digits are accepted.
                Onboarding runs: CSR &rarr; Compliance CSID &rarr; compliance checks of all declared
                document types &rarr; Production CSID.
              </small>
            </b-col>

            <b-col md="12" v-else>
              <b-alert show variant="success">
                Onboarding complete — invoices are being {{ form.auto_submit ? 'automatically' : 'manually' }} reported (simplified)
                and cleared (standard) with ZATCA.
              </b-alert>
              <b-form inline @submit.prevent="onboard">
                <b-form-input v-model.trim="otp" placeholder="OTP" class="mr-2" style="max-width: 140px" />
                <b-button variant="outline-danger" type="submit" :disabled="busy || !otp">
                  Re-onboard (new CSID)
                </b-button>
              </b-form>
            </b-col>

            <!-- Compliance check results -->
            <b-col md="12" class="mt-3" v-if="complianceChecks && complianceChecks.length">
              <h6>Compliance Check Results</h6>
              <div class="table-responsive">
                <table class="table table-sm table-bordered">
                  <thead>
                    <tr><th>Document Type</th><th>Result</th><th>Messages</th></tr>
                  </thead>
                  <tbody>
                    <tr v-for="(c, i) in complianceChecks" :key="i">
                      <td>{{ c.type }}</td>
                      <td>
                        <b-badge :variant="c.passed ? 'success' : 'danger'">
                          {{ c.passed ? (c.status || 'PASSED') : 'FAILED (HTTP ' + c.http_status + ')' }}
                        </b-badge>
                      </td>
                      <td>
                        <div v-for="(m, j) in (c.messages || [])" :key="j" class="small">
                          <b-badge :variant="m.level === 'error' ? 'danger' : (m.level === 'warning' ? 'warning' : 'light')">{{ m.level }}</b-badge>
                          {{ m.code }} — {{ m.message }}
                        </div>
                      </td>
                    </tr>
                  </tbody>
                </table>
              </div>
            </b-col>
          </div>
        </b-tab>

        <!-- ======================= Configuration ======================= -->
        <b-tab title="Configuration">
          <div class="row border rounded p-3 mt-3">
            <b-form @submit.prevent="save" style="width:100%">
              <b-row>
                <b-col md="4">
                  <b-form-group label="Enable ZATCA Phase 2">
                    <b-form-checkbox v-model="form.enabled" switch>
                      {{ form.enabled ? 'Enabled' : 'Disabled' }}
                    </b-form-checkbox>
                  </b-form-group>
                </b-col>
                <b-col md="4">
                  <b-form-group label="Auto-submit new invoices">
                    <b-form-checkbox v-model="form.auto_submit" switch>
                      {{ form.auto_submit ? 'Automatic' : 'Manual' }}
                    </b-form-checkbox>
                  </b-form-group>
                </b-col>
                <b-col md="4">
                  <b-form-group label="Environment">
                    <b-form-select v-model="form.environment" :options="environmentOptions" />
                    <b-form-text class="text-muted">
                      Changing the environment resets credentials and requires re-onboarding.
                    </b-form-text>
                  </b-form-group>
                </b-col>

                <b-col md="12"><hr><h6>EGS Unit / CSR Details</h6></b-col>
                <b-col md="4">
                  <b-form-group label="Common Name (CN)">
                    <b-form-input v-model.trim="form.common_name" placeholder="auto-generated if empty" />
                  </b-form-group>
                </b-col>
                <b-col md="4">
                  <b-form-group label="Organization Name">
                    <b-form-input v-model.trim="form.organization_name" :placeholder="company.name || ''" />
                  </b-form-group>
                </b-col>
                <b-col md="4">
                  <b-form-group label="Branch / Organization Unit">
                    <b-form-input v-model.trim="form.organization_unit" placeholder="Main Branch" />
                  </b-form-group>
                </b-col>
                <b-col md="4">
                  <b-form-group label="Commercial Registration Number (CRN)">
                    <b-form-input v-model.trim="form.crn" />
                  </b-form-group>
                </b-col>
                <b-col md="4">
                  <b-form-group label="Business Category / Industry">
                    <b-form-input v-model.trim="form.business_category" placeholder="Retail" />
                  </b-form-group>
                </b-col>
                <b-col md="4">
                  <b-form-group label="Supported Invoice Types">
                    <b-form-select v-model="form.invoice_types" :options="invoiceTypeOptions" />
                  </b-form-group>
                </b-col>

                <b-col md="12"><hr><h6>National Address (seller)</h6></b-col>
                <b-col md="4">
                  <b-form-group label="Street Name">
                    <b-form-input v-model.trim="form.street_name" />
                  </b-form-group>
                </b-col>
                <b-col md="2">
                  <b-form-group label="Building No. (4 digits)">
                    <b-form-input v-model.trim="form.building_number" maxlength="4" />
                  </b-form-group>
                </b-col>
                <b-col md="2">
                  <b-form-group label="Plot ID">
                    <b-form-input v-model.trim="form.plot_identification" maxlength="10" />
                  </b-form-group>
                </b-col>
                <b-col md="4">
                  <b-form-group label="District">
                    <b-form-input v-model.trim="form.sub_division" />
                  </b-form-group>
                </b-col>
                <b-col md="4">
                  <b-form-group label="City">
                    <b-form-input v-model.trim="form.city" />
                  </b-form-group>
                </b-col>
                <b-col md="2">
                  <b-form-group label="Postal Code (5 digits)">
                    <b-form-input v-model.trim="form.postal_zone" maxlength="5" />
                  </b-form-group>
                </b-col>

                <b-col md="12"><hr><h6>Zero-rated Lines</h6></b-col>
                <b-col md="4">
                  <b-form-group label="VAT Exemption Reason Code">
                    <b-form-input v-model.trim="form.zero_tax_reason_code" placeholder="VATEX-SA-32" />
                  </b-form-group>
                </b-col>
                <b-col md="8">
                  <b-form-group label="VAT Exemption Reason Text">
                    <b-form-input v-model.trim="form.zero_tax_reason" placeholder="Export of goods" />
                  </b-form-group>
                </b-col>

                <b-col md="12" class="mt-2">
                  <b-button variant="primary" type="submit" :disabled="busy">
                    <span v-if="busy" class="spinner-border spinner-border-sm mr-1"></span>
                    {{ $t('Submit') }}
                  </b-button>
                </b-col>
              </b-row>
            </b-form>
          </div>
        </b-tab>

        <!-- ======================= Documents ======================= -->
        <b-tab title="Documents" @click="loadDocuments(1)">
          <div class="row border rounded p-3 mt-3">
            <b-col md="12" class="mb-2 d-flex justify-content-between">
              <b-form-select v-model="docStatus" :options="docStatusOptions" style="max-width: 200px" @change="loadDocuments(1)" />
              <b-button size="sm" variant="outline-secondary" @click="loadDocuments(docPage)">Refresh</b-button>
            </b-col>
            <b-col md="12">
              <div class="table-responsive">
                <table class="table table-sm table-hover">
                  <thead>
                    <tr>
                      <th>#</th><th>Invoice</th><th>Source</th><th>Type</th><th>ICV</th>
                      <th>Status</th><th>Submitted</th><th>Issues</th><th></th>
                    </tr>
                  </thead>
                  <tbody>
                    <tr v-for="doc in documents.data" :key="doc.id">
                      <td>{{ doc.id }}</td>
                      <td>{{ doc.invoice_number }}</td>
                      <td>{{ doc.source }}</td>
                      <td>{{ typeLabel(doc) }}</td>
                      <td>{{ doc.icv }}</td>
                      <td>
                        <b-badge :variant="docVariant(doc.status)">{{ doc.status }}</b-badge>
                      </td>
                      <td class="small">{{ doc.submitted_at }}</td>
                      <td class="small">
                        <div v-if="doc.errors && doc.errors.length" class="text-danger">
                          {{ firstMessage(doc.errors) }}
                        </div>
                        <div v-else-if="doc.warnings && doc.warnings.length" class="text-warning">
                          {{ firstMessage(doc.warnings) }}
                        </div>
                      </td>
                      <td class="text-nowrap">
                        <b-button size="sm" variant="outline-primary" @click="downloadXml(doc)" title="Download XML">XML</b-button>
                        <b-button v-if="doc.status === 'failed'" size="sm" variant="outline-danger" :disabled="busy" @click="retry(doc)">
                          Retry
                        </b-button>
                      </td>
                    </tr>
                    <tr v-if="!documents.data.length">
                      <td colspan="9" class="text-center text-muted">No documents yet.</td>
                    </tr>
                  </tbody>
                </table>
              </div>
              <div class="d-flex justify-content-between align-items-center">
                <span>{{ $t('Total') }}: {{ documents.total }}</span>
                <div>
                  <b-button size="sm" :disabled="!documents.prev_page_url" @click="loadDocuments(docPage - 1)">{{ $t('Prev') }}</b-button>
                  <span class="mx-2">{{ documents.current_page }} / {{ documents.last_page || 1 }}</span>
                  <b-button size="sm" :disabled="!documents.next_page_url" @click="loadDocuments(docPage + 1)">{{ $t('Next') }}</b-button>
                </div>
              </div>
            </b-col>
          </div>
        </b-tab>

      </b-tabs>
    </b-col>
  </div>
</template>

<script>
export default {
  metaInfo: { title: 'ZATCA E-Invoicing' },
  data() {
    return {
      isLoading: true,
      busy: false,
      otp: '',
      company: { name: '', name_ar: '', vat_number: '' },
      form: {
        enabled: false,
        auto_submit: true,
        environment: 'sandbox',
        common_name: '',
        organization_name: '',
        organization_unit: '',
        solution_name: 'Rasheed',
        solution_version: '1.0',
        device_serial: '',
        business_category: '',
        invoice_types: '1100',
        crn: '',
        street_name: '',
        building_number: '',
        plot_identification: '',
        sub_division: '',
        city: '',
        postal_zone: '',
        zero_tax_reason_code: 'VATEX-SA-32',
        zero_tax_reason: 'Export of goods',
        onboarding_status: 'not_started',
        compliance_results: null,
        icv: 0,
      },
      environmentOptions: [],
      invoiceTypeOptions: [
        { value: '1100', text: 'Standard + Simplified (B2B & B2C)' },
        { value: '1000', text: 'Standard only (B2B)' },
        { value: '0100', text: 'Simplified only (B2C)' },
      ],
      complianceChecks: null,
      documents: { data: [], total: 0, last_page: 1, current_page: 1, next_page_url: null, prev_page_url: null },
      docPage: 1,
      docStatus: '',
      docStatusOptions: [
        { value: '', text: 'All statuses' },
        { value: 'reported', text: 'Reported' },
        { value: 'cleared', text: 'Cleared' },
        { value: 'failed', text: 'Failed' },
        { value: 'pending', text: 'Pending' },
      ],
    };
  },

  computed: {
    statusLabel() {
      return {
        not_started: 'Not started',
        csid_issued: 'Compliance CSID issued',
        compliance_checked: 'Compliance checks passed',
        ready: 'Ready (Production CSID active)',
      }[this.form.onboarding_status] || this.form.onboarding_status;
    },
    statusVariant() {
      return {
        not_started: 'secondary',
        csid_issued: 'warning',
        compliance_checked: 'info',
        ready: 'success',
      }[this.form.onboarding_status] || 'secondary';
    },
  },

  created() {
    this.load();
    this.loadDocuments(1);
  },

  methods: {
    toast(variant, msg, title) {
      this.$root.$bvToast.toast(msg, { title: title || 'ZATCA', variant, solid: true });
    },

    async load() {
      try {
        const { data } = await axios.get('/zatca/settings');
        Object.assign(this.form, data.settings || {});
        this.company = data.company || this.company;
        this.environmentOptions = (data.environments || []).map(e => ({ value: e.value, text: e.label }));
        this.complianceChecks = this.form.compliance_results;
      } catch (e) {
        this.toast('danger', (e.response && e.response.data && e.response.data.message) || 'Failed loading ZATCA settings');
      } finally {
        this.isLoading = false;
      }
    },

    async save() {
      this.busy = true;
      try {
        const { data } = await axios.post('/zatca/settings', this.form);
        Object.assign(this.form, data.settings || {});
        this.toast('success', 'Settings saved');
      } catch (e) {
        this.toast('danger', (e.response && e.response.data && e.response.data.message) || 'Save failed');
      } finally {
        this.busy = false;
      }
    },

    async onboard() {
      if (!this.otp) return;
      this.busy = true;
      try {
        const { data } = await axios.post('/zatca/onboard', { otp: this.otp });
        Object.assign(this.form, data.settings || {});
        this.complianceChecks = data.compliance_checks || [];
        if (data.success) {
          this.toast('success', 'Onboarding complete — production CSID issued.');
        } else {
          this.toast('warning', 'Onboarding did not complete: ' + (data.status || data.message || 'see compliance results'));
        }
      } catch (e) {
        this.toast('danger', (e.response && e.response.data && e.response.data.message) || 'Onboarding failed');
      } finally {
        this.busy = false;
        this.otp = '';
      }
    },

    async regenerateCsr() {
      this.busy = true;
      try {
        const { data } = await axios.post('/zatca/csr/regenerate');
        Object.assign(this.form, data.settings || {});
        this.toast('success', 'New key pair and CSR generated.');
      } catch (e) {
        this.toast('danger', (e.response && e.response.data && e.response.data.message) || 'CSR generation failed');
      } finally {
        this.busy = false;
      }
    },

    async loadDocuments(page) {
      this.docPage = Math.max(1, page || 1);
      try {
        const { data } = await axios.get('/zatca/documents', {
          params: { page: this.docPage, status: this.docStatus || undefined },
        });
        this.documents = data;
      } catch (e) {
        this.toast('danger', 'Failed loading documents');
      }
    },

    async retry(doc) {
      this.busy = true;
      try {
        const { data } = await axios.post(`/zatca/${doc.source_kind}/${doc.source_id}/submit`);
        this.toast(data.success ? 'success' : 'warning', 'Status: ' + data.status);
        this.loadDocuments(this.docPage);
      } catch (e) {
        this.toast('danger', (e.response && e.response.data && e.response.data.message) || 'Retry failed');
      } finally {
        this.busy = false;
      }
    },

    downloadXml(doc) {
      axios.get(`/zatca/documents/${doc.id}/xml`, { responseType: 'blob' }).then(res => {
        const url = window.URL.createObjectURL(new Blob([res.data]));
        const link = document.createElement('a');
        link.href = url;
        link.setAttribute('download', `${doc.invoice_number || 'invoice'}-${doc.uuid}.xml`);
        document.body.appendChild(link);
        link.click();
        link.remove();
      });
    },

    typeLabel(doc) {
      const t = { '388': 'Invoice', '381': 'Credit note', '383': 'Debit note' }[doc.type] || doc.type;
      return `${t} (${doc.subtype})`;
    },

    docVariant(status) {
      return { reported: 'success', cleared: 'success', failed: 'danger', pending: 'warning' }[status] || 'secondary';
    },

    firstMessage(list) {
      const m = (list || [])[0] || {};
      return m.message || m.code || JSON.stringify(m);
    },
  },
};
</script>
