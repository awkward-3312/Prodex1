<template>
  <div class="px-next pxcfg">
    <px-page-header
      title="Facturación SAR"
      :breadcrumbs="[{ label: $t('Settings'), href: '#/app/settings/System_settings' }, { label: 'Facturación SAR' }]"
    />

    <div v-if="loading" class="pxcfg__pad">
      <px-skeleton variant="lines" :rows="10" />
    </div>

    <template v-else>
      <px-alert tone="info" class="pxcfg__alert">
        Ingresa únicamente datos autorizados por el SAR. PRODEX arma la estructura técnica por sucursal; tú solo escribes el establecimiento, el punto, el CAI, el rango y la fecha límite de tu autorización oficial. Los cambios se aplican a facturas futuras; las facturas ya emitidas conservan una copia congelada de su información fiscal.
      </px-alert>

      <px-card title="Perfil fiscal" class="pxcfg__card">
        <div class="pxcfg__grid pxcfg__grid--3">
          <px-field label="RTN *"><template #default="{ id }"><px-input :id="id" :value="profile.rtn" @input="v => profile.rtn = tv(v)" /></template></px-field>
          <px-field label="Razón social *"><template #default="{ id }"><px-input :id="id" :value="profile.legal_name" @input="v => profile.legal_name = tv(v)" /></template></px-field>
          <px-field label="Nombre comercial"><template #default="{ id }"><px-input :id="id" :value="profile.trade_name" @input="v => profile.trade_name = tv(v)" /></template></px-field>
          <px-field label="Teléfono"><template #default="{ id }"><px-input :id="id" :value="profile.phone" @input="v => profile.phone = tv(v)" /></template></px-field>
          <px-field label="Correo"><template #default="{ id }"><px-input :id="id" type="email" :value="profile.email" @input="v => profile.email = tv(v)" /></template></px-field>
        </div>
        <px-field label="Dirección de casa matriz *" class="pxcfg__mt">
          <template #default="{ id }"><px-textarea :id="id" :rows="2" :value="profile.head_office_address" @input="v => profile.head_office_address = tv(v)" /></template>
        </px-field>
        <px-check type="switch" :modelValue="!!profile.enabled" @change="v => profile.enabled = v" class="pxcfg__mt">
          {{ profile.enabled ? "Facturación fiscal habilitada" : "Facturación fiscal deshabilitada" }}
        </px-check>
        <p class="pxcfg__cardnote pxcfg__mt">Al habilitarla, cada sucursal activa aparece abajo automáticamente (inicialmente sin facturar). No necesitas crear "puntos de emisión" a mano.</p>
      </px-card>

      <px-card title="Contenido y presentación de la factura" class="pxcfg__card">
        <p class="pxcfg__cardnote">Estos datos son administrables por el tenant y se congelan en cada factura al momento de emitirla.</p>
        <div class="pxcfg__grid pxcfg__grid--3">
          <px-field label="Título del documento"><template #default="{ id }"><px-input :id="id" :value="profile.invoice_settings.document_title" @input="v => profile.invoice_settings.document_title = tv(v)" placeholder="FACTURA" /></template></px-field>
          <px-field label="Tipo de venta"><template #default="{ id }"><px-input :id="id" :value="profile.invoice_settings.sale_type_label" @input="v => profile.invoice_settings.sale_type_label = tv(v)" placeholder="CONTADO" /></template></px-field>
          <px-field label="Sitio web"><template #default="{ id }"><px-input :id="id" :value="profile.invoice_settings.website" @input="v => profile.invoice_settings.website = tv(v)" placeholder="https://..." /></template></px-field>
        </div>
        <div class="pxcfg__grid pxcfg__mt">
          <px-field label="Texto de original"><template #default="{ id }"><px-input :id="id" :value="profile.invoice_settings.original_label" @input="v => profile.invoice_settings.original_label = tv(v)" /></template></px-field>
          <px-field label="Texto de copia"><template #default="{ id }"><px-input :id="id" :value="profile.invoice_settings.copy_label" @input="v => profile.invoice_settings.copy_label = tv(v)" /></template></px-field>
        </div>
        <px-field label="Mensaje al pie" class="pxcfg__mt">
          <template #default="{ id }"><px-textarea :id="id" :rows="2" :value="profile.invoice_settings.footer_message" @input="v => profile.invoice_settings.footer_message = tv(v)" /></template>
        </px-field>
        <div class="pxcfg__togglegrid">
          <px-check v-for="toggle in invoiceToggles" :key="toggle.key" type="switch"
            :modelValue="!!profile.invoice_settings[toggle.key]" @change="v => $set(profile.invoice_settings, toggle.key, v)">
            {{ toggle.label }}
          </px-check>
        </div>
        <template #footer>
          <px-button variant="primary" :disabled="saving" @click="saveProfile">Guardar configuración fiscal y factura</px-button>
        </template>
      </px-card>

      <!-- Per-branch fiscal configuration -->
      <template v-if="profile.enabled">
        <div class="pxcfg__summary">
          <div class="pxcfg__summitem">
            <span class="pxcfg__summnum pxcfg__summnum--ok">{{ readyCount }}</span>
            <span class="pxcfg__summlabel">{{ readyCount === 1 ? 'sucursal facturando' : 'sucursales facturando' }}</span>
          </div>
          <div class="pxcfg__summitem">
            <span class="pxcfg__summnum pxcfg__summnum--warn">{{ pendingCount }}</span>
            <span class="pxcfg__summlabel">{{ pendingCount === 1 ? 'sucursal pendiente' : 'sucursales pendientes' }}</span>
          </div>
          <div class="pxcfg__summitem">
            <span class="pxcfg__summnum">{{ disabledCount }}</span>
            <span class="pxcfg__summlabel">{{ disabledCount === 1 ? 'sucursal sin habilitar' : 'sucursales sin habilitar' }}</span>
          </div>
          <div class="pxcfg__summitem" v-if="totalRemaining !== null">
            <span class="pxcfg__summnum">{{ totalRemaining }}</span>
            <span class="pxcfg__summlabel">correlativos disponibles</span>
          </div>
        </div>

        <px-alert v-if="fiscalGaps.length" tone="warning" class="pxcfg__alert">
          <strong>{{ fiscalGaps.length }}</strong>
          {{ fiscalGaps.length === 1 ? 'caja física no está lista para facturar:' : 'cajas físicas no están listas para facturar:' }}
          <ul class="pxcfg__gaplist">
            <li v-for="gap in fiscalGaps" :key="gap.cash_drawer_id">
              <span class="pxcfg__gapwhere">{{ gap.branch_name || '—' }} · {{ gap.inventory_location_name || 'sin ubicación' }} · {{ gap.cash_drawer_name }}<span v-if="gap.cash_drawer_code" class="pxcfg__gapcode"> ({{ gap.cash_drawer_code }})</span></span>
              <span class="pxcfg__gapreason">{{ gap.reason === 'sin_punto_sar' ? 'sin punto SAR' : 'sin CAI activo' }}</span>
            </li>
          </ul>
        </px-alert>

        <px-card v-for="card in branchCards" :key="card.branch_id" class="pxcfg__card pxcfg__branch">
          <template #header>
            <div class="pxcfg__cardhead">
              <div>
                <h3 class="pxcfg__cardtitle">{{ card.branch_name }}<span v-if="card.branch_code" class="pxcfg__branchcode"> · {{ card.branch_code }}</span></h3>
                <small class="pxcfg__cardnote">{{ statusNote(card) }}</small>
              </div>
              <px-badge :tone="statusTone(card.status)">{{ statusLabel(card.status) }}</px-badge>
            </div>
          </template>

          <div class="pxcfg__branchrow">
            <px-check type="switch" :modelValue="card.sar_enabled" :disabled="saving"
              @change="v => toggleBranch(card, v)">
              {{ card.sar_enabled ? 'Facturación SAR habilitada' : 'Facturación SAR deshabilitada' }}
            </px-check>
          </div>

          <template v-if="card.sar_enabled">
            <div class="pxcfg__branchsection">
              <div class="pxcfg__branchsectionhead">
                <span class="pxcfg__branchsectiontitle">Datos autorizados por el SAR</span>
              </div>
              <div class="pxcfg__grid pxcfg__branchcodes">
                <px-field label="Código de establecimiento *" hint="3 dígitos, según tu autorización.">
                  <template #default="{ id }"><px-input :id="id" maxlength="3" :value="draftFor(card).establishment_code" @input="v => setDraft(card, 'establishment_code', tv(v))" placeholder="000" /></template>
                </px-field>
                <px-field label="Código del punto de emisión *" hint="3 dígitos, según tu autorización.">
                  <template #default="{ id }"><px-input :id="id" maxlength="3" :value="draftFor(card).point_code" @input="v => setDraft(card, 'point_code', tv(v))" placeholder="001" /></template>
                </px-field>
              </div>
              <div class="pxcfg__branchactions">
                <px-button size="sm" variant="secondary" :disabled="saving || !codesDirty(card) || !codesComplete(card)" @click="saveBranchCodes(card)">Guardar códigos</px-button>
              </div>
            </div>

            <div class="pxcfg__branchsection">
              <div class="pxcfg__branchsectionhead">
                <span class="pxcfg__branchsectiontitle">Cajas físicas cubiertas</span>
                <small class="pxcfg__cardnote">Varias cajas pueden compartir el mismo punto y CAI. Las cajas nuevas aparecen aquí automáticamente.</small>
              </div>
              <div v-if="card.available_drawers.length" class="pxcfg__drawers">
                <px-check v-for="d in card.available_drawers" :key="d.id"
                  :modelValue="drawerChecked(card, d.id)" :disabled="saving"
                  @change="v => toggleDrawer(card, d.id, v)">
                  {{ d.name }}<span v-if="d.code" class="pxcfg__gapcode"> ({{ d.code }})</span>
                </px-check>
              </div>
              <p v-else class="pxcfg__cardnote">Esta sucursal todavía no tiene cajas físicas activas.</p>
              <div class="pxcfg__branchactions" v-if="card.available_drawers.length">
                <px-button size="sm" variant="secondary" :disabled="saving || !drawersDirty(card)" @click="saveBranchDrawers(card)">Guardar cajas</px-button>
              </div>
            </div>

            <div class="pxcfg__branchsection">
              <div class="pxcfg__branchsectionhead">
                <span class="pxcfg__branchsectiontitle">CAI y rango</span>
              </div>
              <div v-if="card.authorization" class="pxcfg__cai">
                <div class="pxcfg__caigrid">
                  <div><span class="pxcfg__cailbl">CAI</span><span class="pxcfg__caival pxn-num">{{ card.authorization.cai }}</span></div>
                  <div><span class="pxcfg__cailbl">Rango</span><span class="pxcfg__caival pxn-num">{{ card.authorization.range_start }} – {{ card.authorization.range_end }}</span></div>
                  <div><span class="pxcfg__cailbl">Siguiente</span><span class="pxcfg__caival pxn-num">{{ card.authorization.next_number }}</span></div>
                  <div><span class="pxcfg__cailbl">Disponibles</span><span class="pxcfg__caival pxn-num">{{ card.authorization.remaining }}</span></div>
                  <div><span class="pxcfg__cailbl">Fecha límite</span><span class="pxcfg__caival">{{ card.authorization.deadline || '—' }}</span></div>
                  <div>
                    <span class="pxcfg__cailbl">Estado</span>
                    <px-badge :tone="card.authorization.is_ready ? 'success' : 'danger'">
                      {{ card.authorization.is_ready ? 'CAI listo' : caiIssue(card.authorization) }}
                    </px-badge>
                  </div>
                </div>
                <div class="pxcfg__branchactions">
                  <px-button size="sm" variant="ghost" :disabled="saving" @click="openAuthorization(card)">Reemplazar CAI</px-button>
                  <px-button v-if="card.authorization.status === 'draft' || card.authorization.status === 'disabled'" size="sm" variant="primary" :disabled="saving" @click="activate(card.authorization.id)">Activar</px-button>
                </div>
              </div>
              <div v-else class="pxcfg__branchactions">
                <px-button size="sm" variant="primary" :disabled="saving || !card.has_codes" @click="openAuthorization(card)">Registrar CAI</px-button>
                <small v-if="!card.has_codes" class="pxcfg__cardnote">Guarda primero el establecimiento y el punto.</small>
              </div>
            </div>

            <px-alert v-if="card.errors.length" tone="warning" class="pxcfg__branchalert">
              <ul class="pxcfg__errlist">
                <li v-for="(err, i) in card.errors" :key="i">{{ err }}</li>
              </ul>
            </px-alert>
          </template>
        </px-card>

        <px-card v-if="!branchCards.length" class="pxcfg__card">
          <p class="pxcfg__cardnote pxcfg__tc">No hay sucursales activas. Crea una sucursal para configurar su facturación SAR.</p>
        </px-card>
      </template>

      <px-alert v-else tone="neutral" class="pxcfg__alert">
        Habilita la facturación fiscal en el perfil de arriba para configurar cada sucursal.
      </px-alert>

      <px-card class="pxcfg__card">
        <template #header>
          <div class="pxcfg__cardhead">
            <div>
              <h3 class="pxcfg__cardtitle">Clasificación fiscal de productos</h3>
              <small class="pxcfg__cardnote">Define si cada producto es gravado, exento, exonerado o tasa cero y su ISV. Esta clasificación alimenta POS, A4, térmica y reimpresiones.</small>
            </div>
            <px-input class="pxcfg__inlinesearch" :value="productSearch" @input="v => productSearch = tv(v)" placeholder="Buscar producto..." icon-lead="search" />
          </div>
        </template>
        <div class="pxcfg__scrollbox">
          <table class="pxcfg__table">
            <thead><tr><th>Código</th><th>Producto</th><th>Clasificación</th><th>ISV</th><th>Precio</th><th></th></tr></thead>
            <tbody>
              <tr v-for="product in filteredProducts" :key="product.id">
                <td>{{ product.code }}</td>
                <td>{{ product.name }}</td>
                <td class="pxcfg__cellsel"><vs-px v-model="product.fiscal_tax_category" :reduce="o => o.value" :options="taxCategories" :clearable="false" /></td>
                <td class="pxcfg__cellsel"><vs-px v-model="product.TaxNet" :reduce="o => o.value" :options="taxRateOptions(product)" :clearable="false" :disabled="product.fiscal_tax_category !== 'taxed'" /></td>
                <td class="pxcfg__cellsel"><vs-px v-model="product.tax_method" :reduce="o => o.value" :options="taxMethodOptions" :clearable="false" /></td>
                <td class="pxcfg__tr"><px-button size="sm" variant="secondary" :disabled="saving" @click="saveProductFiscal(product)">Guardar</px-button></td>
              </tr>
              <tr v-if="!filteredProducts.length"><td colspan="6" class="pxcfg__tc pxcfg__cardnote">No hay productos que coincidan.</td></tr>
            </tbody>
          </table>
        </div>
      </px-card>

      <px-card class="pxcfg__card">
        <template #header>
          <div class="pxcfg__cardhead">
            <div>
              <h3 class="pxcfg__cardtitle">Datos fiscales de clientes</h3>
              <small class="pxcfg__cardnote">RTN, documento de identificación y registros de exoneración que podrán copiarse a la factura cuando correspondan.</small>
            </div>
            <px-input class="pxcfg__inlinesearch" :value="clientSearch" @input="v => clientSearch = tv(v)" placeholder="Buscar cliente..." icon-lead="search" />
          </div>
        </template>
        <div class="pxcfg__scrollbox">
          <table class="pxcfg__table">
            <thead><tr><th>Cliente</th><th>RTN</th><th>Identificación</th><th>Registro SAR/SAG</th><th>Registro exonerado</th><th></th></tr></thead>
            <tbody>
              <tr v-for="client in filteredClients" :key="client.id">
                <td>{{ client.name }}</td>
                <td>{{ client.tax_number || '-' }}</td>
                <td>{{ client.identification_number || '-' }}</td>
                <td>{{ client.sar_registry_number || '-' }}</td>
                <td>{{ client.exoneration_registry_number || '-' }}</td>
                <td class="pxcfg__tr"><px-button size="sm" variant="secondary" @click="openClient(client)">Editar</px-button></td>
              </tr>
              <tr v-if="!filteredClients.length"><td colspan="6" class="pxcfg__tc pxcfg__cardnote">No hay clientes que coincidan.</td></tr>
            </tbody>
          </table>
        </div>
      </px-card>
    </template>

    <px-modal v-model="clientModalOpen" title="Datos fiscales del cliente" size="md">
      <div class="pxcfg__formgrid">
        <px-field label="Cliente"><template #default="{ id }"><px-input :id="id" :value="clientForm.name" disabled /></template></px-field>
        <px-field label="RTN"><template #default="{ id }"><px-input :id="id" :value="clientForm.tax_number" @input="v => clientForm.tax_number = tv(v)" /></template></px-field>
        <div class="pxcfg__grid">
          <px-field label="Tipo de identificación"><template #default="{ id }"><px-input :id="id" :value="clientForm.identification_type" @input="v => clientForm.identification_type = tv(v)" placeholder="DNI / Pasaporte" /></template></px-field>
          <px-field label="Número de identificación"><template #default="{ id }"><px-input :id="id" :value="clientForm.identification_number" @input="v => clientForm.identification_number = tv(v)" /></template></px-field>
        </div>
        <px-field label="No. Registro SAG/SAR"><template #default="{ id }"><px-input :id="id" :value="clientForm.sar_registry_number" @input="v => clientForm.sar_registry_number = tv(v)" /></template></px-field>
        <px-field label="No. Registro exonerado"><template #default="{ id }"><px-input :id="id" :value="clientForm.exoneration_registry_number" @input="v => clientForm.exoneration_registry_number = tv(v)" /></template></px-field>
      </div>
      <template #footer="{ close }">
        <px-button variant="ghost" @click="close">Cancelar</px-button>
        <px-button variant="primary" :disabled="saving" @click="saveClientFiscal">Guardar</px-button>
      </template>
    </px-modal>

    <px-modal v-model="authModalOpen" :title="'CAI · ' + (authForm.branch_name || '')" size="md">
      <p class="pxcfg__cardnote pxcfg__mb">Registra el CAI exactamente como aparece en tu autorización del SAR para {{ authForm.branch_name }}. Se guarda como borrador; actívalo cuando quieras empezar a facturar con él.</p>
      <div class="pxcfg__formgrid">
        <px-field label="CAI *"><template #default="{ id }"><px-input :id="id" :value="authForm.cai" @input="v => authForm.cai = tv(v)" placeholder="XXXXXX-XXXXXX-XXXXXX-XXXXXX-XXXXXX-XX" /></template></px-field>
        <div class="pxcfg__grid pxcfg__grid--3">
          <px-field label="Tipo de documento *" hint="01 = factura"><template #default="{ id }"><px-input :id="id" maxlength="2" :value="authForm.document_type" @input="v => authForm.document_type = tv(v)" /></template></px-field>
          <px-field label="Rango: desde *"><template #default="{ id }"><px-input :id="id" type="number" :value="authForm.range_start" @input="v => authForm.range_start = vnum(v)" /></template></px-field>
          <px-field label="Rango: hasta *"><template #default="{ id }"><px-input :id="id" type="number" :value="authForm.range_end" @input="v => authForm.range_end = vnum(v)" /></template></px-field>
        </div>
        <div class="pxcfg__grid">
          <px-field label="Siguiente correlativo *"><template #default="{ id }"><px-input :id="id" type="number" :value="authForm.next_number" @input="v => authForm.next_number = vnum(v)" /></template></px-field>
          <px-field label="Fecha límite de emisión *"><template #default="{ id }"><px-input :id="id" type="date" v-model="authForm.deadline" /></template></px-field>
        </div>
        <px-field label="Fecha de autorización"><template #default="{ id }"><px-input :id="id" type="date" v-model="authForm.authorization_date" /></template></px-field>
      </div>
      <template #footer="{ close }">
        <px-button variant="ghost" @click="close">Cancelar</px-button>
        <px-button variant="primary" :disabled="saving" @click="saveAuthorization">Guardar como borrador</px-button>
      </template>
    </px-modal>
  </div>
</template>

<script>
import NProgress from "nprogress";
import PxPageHeader from "@/components/px-next/PxPageHeader.vue";
import PxButton from "@/components/px-next/PxButton.vue";
import PxCard from "@/components/px-next/PxCard.vue";
import PxField from "@/components/px-next/PxField.vue";
import PxInput from "@/components/px-next/PxInput.vue";
import PxTextarea from "@/components/px-next/PxTextarea.vue";
import PxCheck from "@/components/px-next/PxCheck.vue";
import PxBadge from "@/components/px-next/PxBadge.vue";
import PxAlert from "@/components/px-next/PxAlert.vue";
import PxModal from "@/components/px-next/PxModal.vue";
import VsPx from "@/views/app/products/next/edit/VsPx.vue";

const invoiceDefaults = () => ({
  document_title: "FACTURA", sale_type_label: "CONTADO", website: "", footer_message: "Gracias por su compra.",
  original_label: "Original: Cliente", copy_label: "Copia: Obligado Tributario Emisor",
  show_logo: true, show_internal_reference: true, show_cashier: true, show_warehouse: true,
  show_payment_summary: true, show_customer_address: true, show_item_code: true, show_total_in_words: true, show_qr: true
});

export default {
  metaInfo: { title: "Facturación SAR" },
  components: { PxPageHeader, PxButton, PxCard, PxField, PxInput, PxTextarea, PxCheck, PxBadge, PxAlert, PxModal, "vs-px": VsPx },
  data() {
    return {
      loading: true, saving: false,
      branchCards: [], fiscalGaps: [], products: [], clients: [],
      productSearch: "", clientSearch: "", taxCategories: [], taxRates: [0, 15, 18],
      clientModalOpen: false, authModalOpen: false,
      profile: { enabled: false, rtn: "", legal_name: "", trade_name: "", head_office_address: "", phone: "", email: "", invoice_settings: invoiceDefaults() },
      drafts: {},
      authForm: {}, clientForm: {},
      taxMethodOptions: [{ label: "Exclusivo", value: "1" }, { label: "Incluido en precio", value: "2" }],
      invoiceToggles: [
        { key: "show_logo", label: "Mostrar logo" }, { key: "show_internal_reference", label: "Mostrar referencia interna" },
        { key: "show_cashier", label: "Mostrar cajero" }, { key: "show_warehouse", label: "Mostrar almacén" },
        { key: "show_payment_summary", label: "Mostrar resumen de pago" }, { key: "show_customer_address", label: "Mostrar dirección cliente" },
        { key: "show_item_code", label: "Mostrar código de producto" }, { key: "show_total_in_words", label: "Mostrar total en letras" },
        { key: "show_qr", label: "Mostrar QR" }
      ]
    };
  },
  computed: {
    readyCount() { return this.branchCards.filter(c => c.status === "ready").length; },
    pendingCount() { return this.branchCards.filter(c => c.status === "pending").length; },
    disabledCount() { return this.branchCards.filter(c => c.status === "disabled").length; },
    totalRemaining() {
      const ready = this.branchCards.filter(c => c.authorization && c.authorization.is_ready);
      if (!ready.length) return null;
      return ready.reduce((sum, c) => sum + Number(c.authorization.remaining || 0), 0);
    },
    filteredProducts() { const q = this.productSearch.toLowerCase(); return this.products.filter(x => !q || String(x.name || "").toLowerCase().includes(q) || String(x.code || "").toLowerCase().includes(q)); },
    filteredClients() { const q = this.clientSearch.toLowerCase(); return this.clients.filter(x => !q || String(x.name || "").toLowerCase().includes(q) || String(x.tax_number || "").toLowerCase().includes(q)); }
  },
  methods: {
    tv(v) { return typeof v === "string" ? v.trim() : v; },
    vnum(v) { if (v === "" || v === null || typeof v === "undefined") return v; const n = parseFloat(v); return Number.isNaN(n) ? v : n; },
    toast(variant, message) { this.$root.$bvToast.toast(message, { title: variant === "success" ? "Éxito" : "Atención", variant, solid: true }); },
    errorMessage(error) { const data = error.response && error.response.data; if (data && data.errors) { const key = Object.keys(data.errors)[0]; return data.errors[key][0]; } return (data && data.message) || "No se pudo completar la operación."; },
    normalizeProduct(p) { const category = p.fiscal_tax_category || (Number(p.TaxNet) > 0 ? "taxed" : "exempt"); return Object.assign({}, p, { fiscal_tax_category: category, TaxNet: Number(p.TaxNet || 0), tax_method: String(p.tax_method || "1") }); },
    taxRateOptions(product) { return (product.fiscal_tax_category === "taxed" ? this.taxRates.filter(x => Number(x) > 0) : [0]).map(x => ({ label: x + "%", value: Number(x) })); },
    dateOnly(v) { return v ? String(v).slice(0, 10) : "-"; },
    todayStr() { return new Date().toISOString().slice(0, 10); },
    statusLabel(status) { return ({ ready: "Listo para facturar", pending: "Configuración pendiente", disabled: "SAR deshabilitada" })[status] || status; },
    statusTone(status) { return ({ ready: "success", pending: "warning", disabled: "neutral" })[status] || "neutral"; },
    statusNote(card) {
      if (card.status === "disabled") return "Actívala y completa sus datos para facturar desde esta sucursal.";
      if (card.status === "ready") {
        const covered = card.covered_drawer_ids.length;
        return covered === 1 ? "1 caja cubierta · lista para facturar." : covered + " cajas cubiertas · listas para facturar.";
      }
      return "Faltan datos para poder facturar desde esta sucursal.";
    },
    caiIssue(auth) {
      if (auth.status !== "active") return "CAI no activado";
      if (auth.deadline && String(auth.deadline).slice(0, 10) < this.todayStr()) return "CAI vencido";
      if (Number(auth.remaining) <= 0) return "Rango agotado";
      return "No listo";
    },

    // --- per-branch draft state (codes + drawer selection) ------------------
    draftFor(card) { return this.drafts[card.branch_id] || { establishment_code: "", point_code: "", drawer_ids: [] }; },
    setDraft(card, key, value) {
      const d = this.drafts[card.branch_id] || { establishment_code: "", point_code: "", drawer_ids: [] };
      this.$set(this.drafts, card.branch_id, Object.assign({}, d, { [key]: value }));
    },
    seedDrafts() {
      const next = {};
      this.branchCards.forEach(c => {
        next[c.branch_id] = {
          establishment_code: c.establishment_code || "",
          point_code: c.point_code || "",
          drawer_ids: (c.covered_drawer_ids || []).slice()
        };
      });
      this.drafts = next;
    },
    codesComplete(card) {
      const d = this.draftFor(card);
      return /^\d{3}$/.test(d.establishment_code || "") && /^\d{3}$/.test(d.point_code || "");
    },
    codesDirty(card) {
      const d = this.draftFor(card);
      return (d.establishment_code || "") !== (card.establishment_code || "")
        || (d.point_code || "") !== (card.point_code || "");
    },
    drawerChecked(card, drawerId) { return this.draftFor(card).drawer_ids.indexOf(drawerId) !== -1; },
    toggleDrawer(card, drawerId, checked) {
      const d = this.draftFor(card);
      const ids = d.drawer_ids.slice();
      const i = ids.indexOf(drawerId);
      if (checked && i === -1) ids.push(drawerId);
      if (!checked && i !== -1) ids.splice(i, 1);
      this.$set(this.drafts, card.branch_id, Object.assign({}, d, { drawer_ids: ids }));
    },
    drawersDirty(card) {
      const a = this.draftFor(card).drawer_ids.slice().sort();
      const b = (card.covered_drawer_ids || []).slice().sort();
      return a.length !== b.length || a.some((x, i) => x !== b[i]);
    },

    async load() {
      this.loading = true; NProgress.start();
      try {
        const r = await axios.get("sar-fiscal/settings");
        const incoming = r.data.profile || {};
        this.profile = Object.assign({}, this.profile, incoming, { invoice_settings: Object.assign(invoiceDefaults(), incoming.invoice_settings || {}) });
        this.branchCards = r.data.branch_cards || [];
        this.fiscalGaps = r.data.fiscal_gaps || [];
        this.products = (r.data.products || []).map(this.normalizeProduct);
        this.clients = (r.data.clients || []).map(x => Object.assign({}, x));
        this.taxCategories = r.data.tax_categories || [];
        this.taxRates = r.data.tax_rates || [0, 15, 18];
        this.seedDrafts();
      } catch (e) { this.toast("danger", this.errorMessage(e)); } finally { this.loading = false; NProgress.done(); }
    },
    applyCards(data) {
      if (data && data.branch_cards) {
        this.branchCards = data.branch_cards;
        this.seedDrafts();
      }
    },
    async saveProfile() {
      this.saving = true;
      try { const r = await axios.put("sar-fiscal/profile", this.profile); this.toast("success", "Configuración fiscal guardada."); this.applyCards(r.data); await this.load(); }
      catch (e) { this.toast("danger", this.errorMessage(e)); } finally { this.saving = false; }
    },
    async toggleBranch(card, enabled) {
      this.saving = true;
      try {
        const r = await axios.post("sar-fiscal/branches/" + card.branch_id + "/toggle", { enabled: !!enabled });
        this.toast("success", enabled ? "Facturación SAR habilitada para " + card.branch_name + "." : "Facturación SAR deshabilitada para " + card.branch_name + ".");
        this.applyCards(r.data);
      } catch (e) { this.toast("danger", this.errorMessage(e)); await this.load(); } finally { this.saving = false; }
    },
    async saveBranchCodes(card) {
      const d = this.draftFor(card);
      this.saving = true;
      try {
        const r = await axios.put("sar-fiscal/branches/" + card.branch_id + "/point", { establishment_code: d.establishment_code, point_code: d.point_code });
        this.toast("success", "Códigos SAR guardados para " + card.branch_name + ".");
        this.applyCards(r.data);
      } catch (e) { this.toast("danger", this.errorMessage(e)); } finally { this.saving = false; }
    },
    async saveBranchDrawers(card) {
      this.saving = true;
      try {
        const r = await axios.put("sar-fiscal/branches/" + card.branch_id + "/drawers", { cash_drawer_ids: this.draftFor(card).drawer_ids });
        this.toast("success", "Cajas cubiertas actualizadas para " + card.branch_name + ".");
        this.applyCards(r.data);
      } catch (e) { this.toast("danger", this.errorMessage(e)); } finally { this.saving = false; }
    },
    openAuthorization(card) {
      this.authForm = {
        branch_id: card.branch_id, branch_name: card.branch_name,
        document_type: "01", cai: "", range_start: 1, range_end: null, next_number: 1,
        authorization_date: "", deadline: ""
      };
      this.authModalOpen = true;
    },
    async saveAuthorization() {
      this.saving = true;
      try {
        const r = await axios.post("sar-fiscal/authorizations", {
          branch_id: this.authForm.branch_id,
          document_type: this.authForm.document_type,
          cai: this.authForm.cai,
          range_start: this.authForm.range_start,
          range_end: this.authForm.range_end,
          next_number: this.authForm.next_number,
          authorization_date: this.authForm.authorization_date || null,
          deadline: this.authForm.deadline
        });
        this.authModalOpen = false;
        this.toast("success", "CAI guardado como borrador. Actívalo para empezar a facturar.");
        void r; await this.load();
      } catch (e) { this.toast("danger", this.errorMessage(e)); } finally { this.saving = false; }
    },
    async activate(authId) {
      const result = await this.$swal({ title: "¿Activar CAI?", text: "Las futuras facturas fiscales de esta sucursal usarán este rango.", type: "warning", showCancelButton: true, confirmButtonText: "Activar", cancelButtonText: "Cancelar" });
      if (!result.value) return;
      try { await axios.post("sar-fiscal/authorizations/" + authId + "/activate"); this.toast("success", "CAI activado."); await this.load(); }
      catch (e) { this.toast("danger", this.errorMessage(e)); }
    },
    async saveProductFiscal(product) {
      this.saving = true;
      try {
        await axios.put("sar-fiscal/profile", { action: "product_fiscal", product_id: product.id, fiscal_tax_category: product.fiscal_tax_category, TaxNet: product.fiscal_tax_category === "taxed" ? Number(product.TaxNet) : 0, tax_method: String(product.tax_method || "1") });
        this.toast("success", "Clasificación fiscal del producto guardada."); await this.load();
      } catch (e) { this.toast("danger", this.errorMessage(e)); } finally { this.saving = false; }
    },
    openClient(client) { this.clientForm = Object.assign({}, client); this.clientModalOpen = true; },
    async saveClientFiscal() {
      this.saving = true;
      try { await axios.put("sar-fiscal/profile", Object.assign({ action: "client_fiscal", client_id: this.clientForm.id }, this.clientForm)); this.clientModalOpen = false; this.toast("success", "Datos fiscales del cliente guardados."); await this.load(); }
      catch (e) { this.toast("danger", this.errorMessage(e)); } finally { this.saving = false; }
    }
  },
  created() { this.load(); }
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
.pxcfg__mb { margin: 0 0 var(--pxn-space-4); }
.pxcfg__mt { margin-top: var(--pxn-space-4); }
.pxcfg__cardhead { display: flex; flex-wrap: wrap; gap: var(--pxn-space-4); align-items: flex-start; justify-content: space-between; }
.pxcfg__cardtitle { margin: 0 0 var(--pxn-space-1); font-size: var(--pxn-fs-md); font-weight: var(--pxn-fw-semibold); }
.pxcfg__inlinesearch { max-width: 280px; }
.pxcfg__grid { display: grid; grid-template-columns: repeat(2, minmax(0, 1fr)); gap: var(--pxn-space-4) var(--pxn-space-5); }
.pxcfg__grid--3 { grid-template-columns: repeat(3, minmax(0, 1fr)); }
@media (max-width: 900px) { .pxcfg__grid--3 { grid-template-columns: repeat(2, minmax(0, 1fr)); } }
@media (max-width: 640px) { .pxcfg__grid, .pxcfg__grid--3 { grid-template-columns: minmax(0, 1fr); } }
.pxcfg__togglegrid { display: grid; grid-template-columns: repeat(4, minmax(0, 1fr)); gap: var(--pxn-space-3); margin-top: var(--pxn-space-4); }
@media (max-width: 900px) { .pxcfg__togglegrid { grid-template-columns: repeat(2, minmax(0, 1fr)); } }
@media (max-width: 560px) { .pxcfg__togglegrid { grid-template-columns: minmax(0, 1fr); } }
.pxcfg__formgrid { display: grid; grid-template-columns: minmax(0, 1fr); gap: var(--pxn-space-4); }
.pxcfg__scrollbox { max-height: 420px; overflow: auto; border: 1px solid var(--pxn-border); border-radius: var(--pxn-radius-md); }
.pxcfg__table { width: 100%; border-collapse: collapse; font-size: var(--pxn-fs-sm); }
.pxcfg__table th { position: sticky; top: 0; background: var(--pxn-surface-2); z-index: 1; text-align: left; font-size: var(--pxn-fs-xs); text-transform: uppercase; letter-spacing: 0.04em; color: var(--pxn-ink-3); padding: var(--pxn-space-3) var(--pxn-space-4); }
.pxcfg__table td { padding: var(--pxn-space-3) var(--pxn-space-4); border-top: 1px solid var(--pxn-border); vertical-align: middle; }
.pxcfg__cellsel { min-width: 150px; }
.pxcfg__tr { text-align: right; white-space: nowrap; }
.pxcfg__tc { text-align: center; }

/* summary strip */
.pxcfg__summary { display: flex; flex-wrap: wrap; gap: var(--pxn-space-6); margin-top: var(--pxn-space-5); padding: var(--pxn-space-4) var(--pxn-space-5); background: var(--pxn-surface-2); border: 1px solid var(--pxn-border); border-radius: var(--pxn-radius-md); }
.pxcfg__summitem { display: flex; flex-direction: column; gap: var(--pxn-space-1); }
.pxcfg__summnum { font-size: var(--pxn-fs-lg); font-weight: var(--pxn-fw-semibold); font-variant-numeric: tabular-nums; }
.pxcfg__summnum--ok { color: var(--pxn-success); }
.pxcfg__summnum--warn { color: var(--pxn-warning-ink, var(--pxn-warning)); }
.pxcfg__summlabel { font-size: var(--pxn-fs-xs); color: var(--pxn-ink-3); }

/* per-branch card */
.pxcfg__branch .pxcfg__cardhead { align-items: center; }
.pxcfg__branchcode { color: var(--pxn-ink-3); font-weight: var(--pxn-fw-regular); }
.pxcfg__branchrow { padding-bottom: var(--pxn-space-3); }
.pxcfg__branchsection { padding: var(--pxn-space-4) 0; border-top: 1px solid var(--pxn-border); }
.pxcfg__branchsectionhead { display: flex; flex-direction: column; gap: var(--pxn-space-1); margin-bottom: var(--pxn-space-3); }
.pxcfg__branchsectiontitle { font-size: var(--pxn-fs-sm); font-weight: var(--pxn-fw-semibold); }
.pxcfg__branchcodes { max-width: 460px; }
.pxcfg__branchactions { display: flex; flex-wrap: wrap; gap: var(--pxn-space-3); align-items: center; margin-top: var(--pxn-space-3); }
.pxcfg__drawers { display: grid; grid-template-columns: repeat(3, minmax(0, 1fr)); gap: var(--pxn-space-2) var(--pxn-space-4); }
@media (max-width: 900px) { .pxcfg__drawers { grid-template-columns: repeat(2, minmax(0, 1fr)); } }
@media (max-width: 560px) { .pxcfg__drawers { grid-template-columns: minmax(0, 1fr); } }
.pxcfg__cai { display: flex; flex-direction: column; gap: var(--pxn-space-3); }
.pxcfg__caigrid { display: grid; grid-template-columns: repeat(3, minmax(0, 1fr)); gap: var(--pxn-space-3) var(--pxn-space-5); }
@media (max-width: 780px) { .pxcfg__caigrid { grid-template-columns: repeat(2, minmax(0, 1fr)); } }
@media (max-width: 480px) { .pxcfg__caigrid { grid-template-columns: minmax(0, 1fr); } }
.pxcfg__caigrid > div { display: flex; flex-direction: column; align-items: flex-start; gap: 2px; }
.pxcfg__cailbl { font-size: var(--pxn-fs-xs); text-transform: uppercase; letter-spacing: 0.04em; color: var(--pxn-ink-3); }
.pxcfg__caival { font-size: var(--pxn-fs-sm); }
.pxcfg__branchalert { margin-top: var(--pxn-space-4); }
.pxcfg__errlist { margin: 0; padding-left: var(--pxn-space-5); display: flex; flex-direction: column; gap: var(--pxn-space-1); }
.pxcfg__gaplist { margin: var(--pxn-space-2) 0 0; padding-left: var(--pxn-space-5); display: flex; flex-direction: column; gap: var(--pxn-space-1); }
.pxcfg__gaplist li { display: flex; flex-wrap: wrap; gap: var(--pxn-space-2); align-items: baseline; }
.pxcfg__gapwhere { font-weight: var(--pxn-fw-medium); }
.pxcfg__gapcode { color: var(--pxn-ink-3); font-weight: var(--pxn-fw-regular); }
.pxcfg__gapreason { font-size: var(--pxn-fs-xs); color: var(--pxn-danger-ink, var(--pxn-danger)); text-transform: uppercase; letter-spacing: 0.03em; }
</style>
