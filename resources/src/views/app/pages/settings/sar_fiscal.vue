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
        Ingresa únicamente datos autorizados por el SAR. Los cambios se aplican a facturas futuras; las facturas ya emitidas conservan una copia congelada de su información fiscal.
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

      <px-card class="pxcfg__card">
        <template #header>
          <div class="pxcfg__cardhead">
            <div><h3 class="pxcfg__cardtitle">Puntos de emisión</h3><small class="pxcfg__cardnote">Cada punto es la identidad fiscal de una sucursal, ubicación y caja física.</small></div>
            <px-button variant="primary" size="sm" icon="plus" @click="openPoint()">Agregar punto de emisión</px-button>
          </div>
        </template>
        <div class="pxcfg__scrollbox">
          <table class="pxcfg__table">
            <thead><tr><th>Sucursal</th><th>Establecimiento</th><th>Punto</th><th>Ubicación</th><th>Caja</th><th>CAI activo</th><th>Estado</th><th></th></tr></thead>
            <tbody>
              <tr v-for="point in points" :key="point.id">
                <td>{{ (point.branch && point.branch.name) || branchName(point.branch_id) }}</td>
                <td class="pxn-num">{{ point.establishment_code }}</td>
                <td class="pxn-num">{{ point.point_code }}</td>
                <td>{{ (point.inventory_location && point.inventory_location.name) || locationName(point.inventory_location_id) }}</td>
                <td>{{ (point.cash_drawer && point.cash_drawer.name) || drawerName(point.cash_drawer_id) }}</td>
                <td>
                  <template v-if="point.has_active_cai && point.active_cai">
                    <px-badge tone="success">CAI …{{ caiTail(point.active_cai.cai) }}</px-badge>
                    <div class="pxcfg__caimeta">vence {{ point.active_cai.deadline }} · quedan {{ point.active_cai.remaining }}</div>
                  </template>
                  <px-badge v-else tone="danger">Sin CAI listo</px-badge>
                </td>
                <td><px-badge :tone="point.active ? 'success' : 'neutral'">{{ point.active ? "Activo" : "Inactivo" }}</px-badge></td>
                <td class="pxcfg__tr"><px-button variant="ghost" size="sm" icon-only icon="pencil" aria-label="Editar" @click="openPoint(point)" /></td>
              </tr>
              <tr v-if="!points.length"><td colspan="8" class="pxcfg__tc pxcfg__cardnote">No hay puntos registrados.</td></tr>
            </tbody>
          </table>
        </div>
      </px-card>

      <px-card class="pxcfg__card">
        <template #header>
          <div class="pxcfg__cardhead">
            <div><h3 class="pxcfg__cardtitle">Autorizaciones y rangos</h3><small class="pxcfg__cardnote">El correlativo solo avanza al emitir una factura fiscal. “CAI listo” significa activo, no vencido y con rango disponible.</small></div>
            <px-button variant="primary" size="sm" icon="plus" :disabled="!points.length" @click="openAuthorization">Agregar autorización</px-button>
          </div>
        </template>
        <div class="pxcfg__scrollbox">
          <table class="pxcfg__table">
            <thead><tr><th>Punto</th><th>CAI</th><th>Rango</th><th>Siguiente</th><th>Fecha límite</th><th>Estado</th><th>Listo</th><th></th></tr></thead>
            <tbody>
              <template v-for="point in points">
                <tr v-for="auth in point.authorizations" :key="auth.id">
                  <td>{{ point.establishment_code }}-{{ point.point_code }}-{{ auth.document_type }}</td>
                  <td class="pxn-num">{{ auth.cai }}</td>
                  <td class="pxn-num">{{ auth.range_start }} – {{ auth.range_end }}</td>
                  <td class="pxn-num">{{ auth.next_number }}</td>
                  <td>{{ dateOnly(auth.deadline) }}</td>
                  <td><px-badge :tone="auth.status === 'active' ? 'success' : 'neutral'">{{ statusLabel(auth.status) }}</px-badge></td>
                  <td>
                    <px-badge v-if="authIsReady(point, auth)" tone="success">Listo para facturar</px-badge>
                    <px-badge v-else tone="danger">{{ authNotReadyReason(auth) }}</px-badge>
                  </td>
                  <td class="pxcfg__tr"><px-button v-if="auth.status === 'draft' || auth.status === 'disabled'" size="sm" variant="primary" @click="activate(auth)">Activar</px-button></td>
                </tr>
              </template>
              <tr v-if="!hasAuthorizations"><td colspan="8" class="pxcfg__tc pxcfg__cardnote">No hay autorizaciones registradas.</td></tr>
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

    <px-modal v-model="pointModalOpen" :title="pointForm.id ? 'Editar punto de emisión' : 'Agregar punto de emisión'" size="md">
      <p class="pxcfg__cardnote pxcfg__mb">El punto de emisión ata la identidad fiscal a la caja física real: Sucursal → Ubicación de inventario → Caja física.</p>
      <div class="pxcfg__formgrid">
        <px-field label="Sucursal *">
          <template #default="{ id }">
            <vs-px :input-id="id" :value="pointForm.branch_id" :reduce="o => o.value" :options="branchOptions" placeholder="Selecciona una sucursal" @input="onPointBranchChange" />
          </template>
        </px-field>
        <px-field label="Ubicación de inventario *" hint="Solo ubicaciones de la sucursal seleccionada.">
          <template #default="{ id }">
            <vs-px :input-id="id" :value="pointForm.inventory_location_id" :reduce="o => o.value" :options="pointLocationOptions" :disabled="!pointForm.branch_id" placeholder="Selecciona la ubicación" @input="onPointLocationChange" />
          </template>
        </px-field>
        <px-field label="Caja física *" hint="Solo cajas activas de esa sucursal y ubicación.">
          <template #default="{ id }">
            <vs-px :input-id="id" :value="pointForm.cash_drawer_id" :reduce="o => o.value" :options="pointDrawerOptions" :disabled="!pointForm.inventory_location_id" placeholder="Selecciona la caja física" @input="v => pointForm.cash_drawer_id = v" />
          </template>
        </px-field>
        <div class="pxcfg__grid">
          <px-field label="Código de establecimiento *"><template #default="{ id }"><px-input :id="id" maxlength="3" :value="pointForm.establishment_code" @input="v => pointForm.establishment_code = tv(v)" placeholder="000" /></template></px-field>
          <px-field label="Código del punto *"><template #default="{ id }"><px-input :id="id" maxlength="3" :value="pointForm.point_code" @input="v => pointForm.point_code = tv(v)" placeholder="001" /></template></px-field>
        </div>
        <px-field label="Nombre *"><template #default="{ id }"><px-input :id="id" :value="pointForm.name" @input="v => pointForm.name = tv(v)" placeholder="Ej. Caja principal Sucursal Centro" /></template></px-field>
        <px-field label="Dirección *"><template #default="{ id }"><px-textarea :id="id" :rows="2" :value="pointForm.address" @input="v => pointForm.address = tv(v)" /></template></px-field>
        <px-check type="switch" :modelValue="!!pointForm.active" @change="v => pointForm.active = v">Activo</px-check>
      </div>
      <template #footer="{ close }">
        <px-button variant="ghost" @click="close">Cancelar</px-button>
        <px-button variant="primary" :disabled="saving || !pointFormComplete" @click="savePoint">Guardar</px-button>
      </template>
    </px-modal>

    <px-modal v-model="authModalOpen" title="Agregar autorización SAR" size="md">
      <div class="pxcfg__formgrid">
        <px-field label="Punto de emisión *"><template #default="{ id }"><vs-px :input-id="id" v-model="authForm.point_of_issue_id" :reduce="o => o.value" :options="pointOptions" /></template></px-field>
        <px-field label="CAI *"><template #default="{ id }"><px-input :id="id" :value="authForm.cai" @input="v => authForm.cai = tv(v)" /></template></px-field>
        <div class="pxcfg__grid pxcfg__grid--3">
          <px-field label="Tipo *"><template #default="{ id }"><px-input :id="id" maxlength="2" :value="authForm.document_type" @input="v => authForm.document_type = tv(v)" /></template></px-field>
          <px-field label="Inicio *"><template #default="{ id }"><px-input :id="id" type="number" :value="authForm.range_start" @input="v => authForm.range_start = vnum(v)" /></template></px-field>
          <px-field label="Final *"><template #default="{ id }"><px-input :id="id" type="number" :value="authForm.range_end" @input="v => authForm.range_end = vnum(v)" /></template></px-field>
        </div>
        <div class="pxcfg__grid">
          <px-field label="Siguiente correlativo *"><template #default="{ id }"><px-input :id="id" type="number" :value="authForm.next_number" @input="v => authForm.next_number = vnum(v)" /></template></px-field>
          <px-field label="Fecha límite *"><template #default="{ id }"><px-input :id="id" type="date" v-model="authForm.deadline" /></template></px-field>
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

const emptyPoint = () => ({
  id: null, branch_id: null, inventory_location_id: null, cash_drawer_id: null,
  establishment_code: "000", point_code: "001", name: "", address: "", active: true
});

export default {
  metaInfo: { title: "Facturación SAR" },
  components: { PxPageHeader, PxButton, PxCard, PxField, PxInput, PxTextarea, PxCheck, PxBadge, PxAlert, PxModal, "vs-px": VsPx },
  data() {
    return {
      loading: true, saving: false, points: [], branches: [], inventoryLocations: [], warehouses: [], cashDrawers: [],
      fiscalGaps: [], products: [], clients: [],
      productSearch: "", clientSearch: "", taxCategories: [], taxRates: [0, 15, 18],
      clientModalOpen: false, pointModalOpen: false, authModalOpen: false,
      profile: { enabled: false, rtn: "", legal_name: "", trade_name: "", head_office_address: "", phone: "", email: "", invoice_settings: invoiceDefaults() },
      pointForm: emptyPoint(), authForm: {}, clientForm: {},
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
    branchOptions() { return this.branches.map(x => ({ label: x.code ? `${x.name} (${x.code})` : x.name, value: x.id })); },
    pointLocationOptions() {
      if (!this.pointForm.branch_id) return [];
      return this.inventoryLocations
        .filter(l => Number(l.branch_id) === Number(this.pointForm.branch_id))
        .map(l => ({ label: l.name, value: l.id }));
    },
    pointDrawerOptions() {
      if (!this.pointForm.branch_id || !this.pointForm.inventory_location_id) return [];
      return this.cashDrawers
        .filter(d => Number(d.branch_id) === Number(this.pointForm.branch_id)
          && (d.inventory_location_id == null || Number(d.inventory_location_id) === Number(this.pointForm.inventory_location_id)))
        .map(d => ({ label: d.code ? `${d.name} (${d.code})` : d.name, value: d.id }));
    },
    pointFormComplete() {
      return !!(this.pointForm.branch_id && this.pointForm.inventory_location_id && this.pointForm.cash_drawer_id
        && this.pointForm.establishment_code && this.pointForm.point_code && this.pointForm.name && this.pointForm.address);
    },
    pointOptions() { return this.points.filter(x => x.active).map(x => ({ label: x.establishment_code + "-" + x.point_code + " · " + x.name, value: x.id })); },
    hasAuthorizations() { return this.points.some(x => (x.authorizations || []).length); },
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
    caiTail(cai) { return String(cai || "").slice(-6); },
    dateOnly(v) { return v ? String(v).slice(0, 10) : "-"; },
    todayStr() { return new Date().toISOString().slice(0, 10); },
    authIsReady(point, auth) {
      if (!auth || auth.status !== "active" || !point.active) return false;
      if (auth.deadline && String(auth.deadline).slice(0, 10) < this.todayStr()) return false;
      const next = Number(auth.next_number);
      return next >= Number(auth.range_start) && next <= Number(auth.range_end);
    },
    authNotReadyReason(auth) {
      if (auth.status !== "active") return "No activo";
      if (auth.deadline && String(auth.deadline).slice(0, 10) < this.todayStr()) return "CAI vencido";
      const next = Number(auth.next_number);
      if (next < Number(auth.range_start) || next > Number(auth.range_end)) return "Rango agotado";
      return "No listo";
    },
    async load() {
      this.loading = true; NProgress.start();
      try {
        const r = await axios.get("sar-fiscal/settings");
        const incoming = r.data.profile || {};
        this.profile = Object.assign({}, this.profile, incoming, { invoice_settings: Object.assign(invoiceDefaults(), incoming.invoice_settings || {}) });
        this.points = r.data.points || [];
        this.branches = r.data.branches || [];
        this.inventoryLocations = r.data.inventory_locations || [];
        this.warehouses = r.data.warehouses || [];
        this.cashDrawers = r.data.cash_drawers || [];
        this.fiscalGaps = r.data.fiscal_gaps || [];
        this.products = (r.data.products || []).map(this.normalizeProduct); this.clients = (r.data.clients || []).map(x => Object.assign({}, x));
        this.taxCategories = r.data.tax_categories || []; this.taxRates = r.data.tax_rates || [0, 15, 18];
      } catch (e) { this.toast("danger", this.errorMessage(e)); } finally { this.loading = false; NProgress.done(); }
    },
    async saveProfile() { this.saving = true; try { await axios.put("sar-fiscal/profile", this.profile); this.toast("success", "Configuración fiscal guardada."); await this.load(); } catch (e) { this.toast("danger", this.errorMessage(e)); } finally { this.saving = false; } },
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
    },
    openPoint(point) {
      this.pointForm = point
        ? Object.assign(emptyPoint(), {
          id: point.id, branch_id: point.branch_id || null, inventory_location_id: point.inventory_location_id || null,
          cash_drawer_id: point.cash_drawer_id || null, establishment_code: point.establishment_code, point_code: point.point_code,
          name: point.name, address: point.address, active: !!point.active
        })
        : emptyPoint();
      this.pointModalOpen = true;
    },
    onPointBranchChange(value) {
      this.pointForm.branch_id = value;
      this.pointForm.inventory_location_id = null;
      this.pointForm.cash_drawer_id = null;
    },
    onPointLocationChange(value) {
      this.pointForm.inventory_location_id = value;
      this.pointForm.cash_drawer_id = null;
    },
    async savePoint() {
      this.saving = true;
      try {
        const payload = {
          branch_id: this.pointForm.branch_id,
          inventory_location_id: this.pointForm.inventory_location_id,
          cash_drawer_id: this.pointForm.cash_drawer_id,
          establishment_code: this.pointForm.establishment_code,
          point_code: this.pointForm.point_code,
          name: this.pointForm.name,
          address: this.pointForm.address,
          active: this.pointForm.active ? 1 : 0
        };
        if (this.pointForm.id) await axios.put("sar-fiscal/points/" + this.pointForm.id, payload);
        else await axios.post("sar-fiscal/points", payload);
        this.pointModalOpen = false; this.toast("success", "Punto de emisión guardado."); await this.load();
      } catch (e) { this.toast("danger", this.errorMessage(e)); } finally { this.saving = false; }
    },
    openAuthorization() { this.authForm = { point_of_issue_id: this.points.length === 1 ? this.points[0].id : null, document_type: "01", cai: "", range_start: 1, range_end: null, next_number: 1, authorization_date: "", deadline: "" }; this.authModalOpen = true; },
    async saveAuthorization() { this.saving = true; try { await axios.post("sar-fiscal/authorizations", this.authForm); this.authModalOpen = false; this.toast("success", "Autorización guardada como borrador."); await this.load(); } catch (e) { this.toast("danger", this.errorMessage(e)); } finally { this.saving = false; } },
    async activate(auth) { const result = await this.$swal({ title: "¿Activar autorización?", text: "Las futuras facturas fiscales usarán este rango.", type: "warning", showCancelButton: true, confirmButtonText: "Activar", cancelButtonText: "Cancelar" }); if (!result.value) return; try { await axios.post("sar-fiscal/authorizations/" + auth.id + "/activate"); this.toast("success", "Autorización activada."); await this.load(); } catch (e) { this.toast("danger", this.errorMessage(e)); } },
    branchName(id) { const item = this.branches.find(x => Number(x.id) === Number(id)); return item ? item.name : "-"; },
    locationName(id) { const item = this.inventoryLocations.find(x => Number(x.id) === Number(id)); return item ? item.name : "-"; },
    warehouseName(id) { const item = this.warehouses.find(x => x.id === id); return item ? item.name : "-"; },
    drawerName(id) { const item = this.cashDrawers.find(x => x.id === id); return item ? item.name : "-"; },
    statusLabel(status) { return ({ draft: "Borrador", active: "Activa", exhausted: "Agotada", expired: "Vencida", disabled: "Deshabilitada" })[status] || status; }
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
.pxcfg__cardhead { display: flex; flex-wrap: wrap; gap: var(--pxn-space-4); align-items: flex-start; justify-content: space-between; }
.pxcfg__cardtitle { margin: 0 0 var(--pxn-space-1); font-size: var(--pxn-fs-md); font-weight: var(--pxn-fw-semibold); }
.pxcfg__inlinesearch { max-width: 280px; }
.pxcfg__grid { display: grid; grid-template-columns: repeat(2, minmax(0, 1fr)); gap: var(--pxn-space-4) var(--pxn-space-5); }
.pxcfg__grid--3 { grid-template-columns: repeat(3, minmax(0, 1fr)); }
@media (max-width: 900px) { .pxcfg__grid--3 { grid-template-columns: repeat(2, minmax(0, 1fr)); } }
@media (max-width: 640px) { .pxcfg__grid, .pxcfg__grid--3 { grid-template-columns: minmax(0, 1fr); } }
.pxcfg__mt { margin-top: var(--pxn-space-4); }
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
.pxcfg__caimeta { margin-top: var(--pxn-space-1); font-size: var(--pxn-fs-xs); color: var(--pxn-ink-3); }
.pxcfg__gaplist { margin: var(--pxn-space-2) 0 0; padding-left: var(--pxn-space-5); display: flex; flex-direction: column; gap: var(--pxn-space-1); }
.pxcfg__gaplist li { display: flex; flex-wrap: wrap; gap: var(--pxn-space-2); align-items: baseline; }
.pxcfg__gapwhere { font-weight: var(--pxn-fw-medium); }
.pxcfg__gapcode { color: var(--pxn-ink-3); font-weight: var(--pxn-fw-regular); }
.pxcfg__gapreason { font-size: var(--pxn-fs-xs); color: var(--pxn-danger-ink, var(--pxn-danger)); text-transform: uppercase; letter-spacing: 0.03em; }
</style>
