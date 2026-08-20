<template>
  <div class="main-content">
    <breadcumb page="Facturación SAR" :folder="$t('Accounting') || 'Contabilidad'" />
    <div v-if="loading" class="loading_page spinner spinner-primary mr-3"></div>

    <div v-else>
      <b-alert show variant="info">
        Ingresa únicamente datos autorizados por el SAR. Los cambios se aplican a facturas futuras; las facturas ya emitidas conservan una copia congelada de su información fiscal.
      </b-alert>

      <b-card class="mb-4">
        <h5>Perfil fiscal</h5>
        <b-row>
          <b-col md="4"><b-form-group label="RTN *"><b-form-input v-model.trim="profile.rtn" /></b-form-group></b-col>
          <b-col md="4"><b-form-group label="Razón social *"><b-form-input v-model.trim="profile.legal_name" /></b-form-group></b-col>
          <b-col md="4"><b-form-group label="Nombre comercial"><b-form-input v-model.trim="profile.trade_name" /></b-form-group></b-col>
          <b-col md="4"><b-form-group label="Teléfono"><b-form-input v-model.trim="profile.phone" /></b-form-group></b-col>
          <b-col md="4"><b-form-group label="Correo"><b-form-input type="email" v-model.trim="profile.email" /></b-form-group></b-col>
          <b-col md="12"><b-form-group label="Dirección de casa matriz *"><b-form-textarea rows="2" v-model.trim="profile.head_office_address" /></b-form-group></b-col>
          <b-col md="12">
            <b-form-checkbox v-model="profile.enabled" switch>
              {{ profile.enabled ? "Facturación fiscal habilitada" : "Facturación fiscal deshabilitada" }}
            </b-form-checkbox>
          </b-col>
        </b-row>
      </b-card>

      <b-card class="mb-4">
        <div class="mb-3">
          <h5 class="mb-1">Contenido y presentación de la factura</h5>
          <small class="text-muted">Estos datos son administrables por el tenant y se congelan en cada factura al momento de emitirla.</small>
        </div>
        <b-row>
          <b-col md="4"><b-form-group label="Título del documento"><b-form-input v-model.trim="profile.invoice_settings.document_title" placeholder="FACTURA" /></b-form-group></b-col>
          <b-col md="4"><b-form-group label="Tipo de venta"><b-form-input v-model.trim="profile.invoice_settings.sale_type_label" placeholder="CONTADO" /></b-form-group></b-col>
          <b-col md="4"><b-form-group label="Sitio web"><b-form-input v-model.trim="profile.invoice_settings.website" placeholder="https://..." /></b-form-group></b-col>
          <b-col md="6"><b-form-group label="Texto de original"><b-form-input v-model.trim="profile.invoice_settings.original_label" /></b-form-group></b-col>
          <b-col md="6"><b-form-group label="Texto de copia"><b-form-input v-model.trim="profile.invoice_settings.copy_label" /></b-form-group></b-col>
          <b-col md="12"><b-form-group label="Mensaje al pie"><b-form-textarea rows="2" v-model.trim="profile.invoice_settings.footer_message" /></b-form-group></b-col>
        </b-row>
        <b-row>
          <b-col md="3" v-for="toggle in invoiceToggles" :key="toggle.key" class="mb-2">
            <b-form-checkbox v-model="profile.invoice_settings[toggle.key]" switch>{{ toggle.label }}</b-form-checkbox>
          </b-col>
        </b-row>
        <b-button variant="primary" class="mt-3" :disabled="saving" @click="saveProfile">Guardar configuración fiscal y factura</b-button>
      </b-card>

      <b-card class="mb-4">
        <div class="d-flex justify-content-between align-items-center mb-3 flex-wrap">
          <div><h5 class="mb-1">Clasificación fiscal de productos</h5><small class="text-muted">Define si cada producto es gravado, exento, exonerado o tasa cero y su ISV. Esta clasificación alimenta POS, A4, térmica y reimpresiones.</small></div>
          <b-form-input v-model.trim="productSearch" size="sm" style="max-width:280px" placeholder="Buscar producto..." />
        </div>
        <div class="table-responsive" style="max-height:420px; overflow:auto;">
          <table class="table table-sm table-hover">
            <thead><tr><th>Código</th><th>Producto</th><th style="min-width:160px">Clasificación</th><th style="min-width:120px">ISV</th><th style="min-width:150px">Precio</th><th></th></tr></thead>
            <tbody>
              <tr v-for="product in filteredProducts" :key="product.id">
                <td>{{ product.code }}</td>
                <td>{{ product.name }}</td>
                <td><v-select v-model="product.fiscal_tax_category" :reduce="o => o.value" :options="taxCategories" :clearable="false" /></td>
                <td>
                  <v-select v-model="product.TaxNet" :reduce="o => o.value" :options="taxRateOptions(product)" :clearable="false" :disabled="product.fiscal_tax_category !== 'taxed'" />
                </td>
                <td><v-select v-model="product.tax_method" :reduce="o => o.value" :options="taxMethodOptions" :clearable="false" /></td>
                <td class="text-right"><b-button size="sm" variant="outline-primary" :disabled="saving" @click="saveProductFiscal(product)">Guardar</b-button></td>
              </tr>
              <tr v-if="!filteredProducts.length"><td colspan="6" class="text-center text-muted">No hay productos que coincidan.</td></tr>
            </tbody>
          </table>
        </div>
      </b-card>

      <b-card class="mb-4">
        <div class="d-flex justify-content-between align-items-center mb-3 flex-wrap">
          <div><h5 class="mb-1">Datos fiscales de clientes</h5><small class="text-muted">RTN, documento de identificación y registros de exoneración que podrán copiarse a la factura cuando correspondan.</small></div>
          <b-form-input v-model.trim="clientSearch" size="sm" style="max-width:280px" placeholder="Buscar cliente..." />
        </div>
        <div class="table-responsive" style="max-height:360px; overflow:auto;">
          <table class="table table-sm table-hover">
            <thead><tr><th>Cliente</th><th>RTN</th><th>Identificación</th><th>Registro SAR/SAG</th><th>Registro exonerado</th><th></th></tr></thead>
            <tbody>
              <tr v-for="client in filteredClients" :key="client.id">
                <td>{{ client.name }}</td>
                <td>{{ client.tax_number || '-' }}</td>
                <td>{{ client.identification_number || '-' }}</td>
                <td>{{ client.sar_registry_number || '-' }}</td>
                <td>{{ client.exoneration_registry_number || '-' }}</td>
                <td class="text-right"><b-button size="sm" variant="outline-primary" @click="openClient(client)">Editar</b-button></td>
              </tr>
              <tr v-if="!filteredClients.length"><td colspan="6" class="text-center text-muted">No hay clientes que coincidan.</td></tr>
            </tbody>
          </table>
        </div>
      </b-card>

      <b-card class="mb-4">
        <div class="d-flex justify-content-between align-items-center mb-3">
          <div><h5 class="mb-1">Puntos de emisión</h5><small class="text-muted">Códigos de establecimiento y punto autorizados.</small></div>
          <b-button variant="primary" @click="openPoint()">Agregar punto</b-button>
        </div>
        <div class="table-responsive">
          <table class="table table-hover">
            <thead><tr><th>Código</th><th>Nombre</th><th>Almacén</th><th>Caja</th><th>Estado</th><th></th></tr></thead>
            <tbody>
              <tr v-for="point in points" :key="point.id">
                <td>{{ point.establishment_code }}-{{ point.point_code }}</td>
                <td>{{ point.name }}</td>
                <td>{{ warehouseName(point.warehouse_id) }}</td>
                <td>{{ drawerName(point.cash_drawer_id) }}</td>
                <td><b-badge :variant="point.active ? 'success' : 'secondary'">{{ point.active ? "Activo" : "Inactivo" }}</b-badge></td>
                <td class="text-right"><a href="#" @click.prevent="openPoint(point)"><lucide-icon name="pencil" /></a></td>
              </tr>
              <tr v-if="!points.length"><td colspan="6" class="text-center text-muted">No hay puntos registrados.</td></tr>
            </tbody>
          </table>
        </div>
      </b-card>

      <b-card>
        <div class="d-flex justify-content-between align-items-center mb-3">
          <div><h5 class="mb-1">Autorizaciones y rangos</h5><small class="text-muted">El correlativo solo avanza al emitir una factura fiscal.</small></div>
          <b-button variant="primary" :disabled="!points.length" @click="openAuthorization">Agregar autorización</b-button>
        </div>
        <div class="table-responsive">
          <table class="table table-hover">
            <thead><tr><th>Punto</th><th>CAI</th><th>Rango</th><th>Siguiente</th><th>Fecha límite</th><th>Estado</th><th></th></tr></thead>
            <tbody>
              <template v-for="point in points">
                <tr v-for="auth in point.authorizations" :key="auth.id">
                  <td>{{ point.establishment_code }}-{{ point.point_code }}-{{ auth.document_type }}</td>
                  <td>{{ auth.cai }}</td>
                  <td>{{ auth.range_start }} – {{ auth.range_end }}</td>
                  <td>{{ auth.next_number }}</td>
                  <td>{{ auth.deadline }}</td>
                  <td><b-badge :variant="auth.status === 'active' ? 'success' : 'secondary'">{{ statusLabel(auth.status) }}</b-badge></td>
                  <td class="text-right"><b-button v-if="auth.status === 'draft' || auth.status === 'disabled'" size="sm" variant="success" @click="activate(auth)">Activar</b-button></td>
                </tr>
              </template>
              <tr v-if="!hasAuthorizations"><td colspan="7" class="text-center text-muted">No hay autorizaciones registradas.</td></tr>
            </tbody>
          </table>
        </div>
      </b-card>
    </div>

    <b-modal id="SarClientModal" hide-footer title="Datos fiscales del cliente">
      <b-form @submit.prevent="saveClientFiscal">
        <b-form-group label="Cliente"><b-form-input :value="clientForm.name" disabled /></b-form-group>
        <b-form-group label="RTN"><b-form-input v-model.trim="clientForm.tax_number" /></b-form-group>
        <b-row>
          <b-col md="6"><b-form-group label="Tipo de identificación"><b-form-input v-model.trim="clientForm.identification_type" placeholder="DNI / Pasaporte" /></b-form-group></b-col>
          <b-col md="6"><b-form-group label="Número de identificación"><b-form-input v-model.trim="clientForm.identification_number" /></b-form-group></b-col>
        </b-row>
        <b-form-group label="No. Registro SAG/SAR"><b-form-input v-model.trim="clientForm.sar_registry_number" /></b-form-group>
        <b-form-group label="No. Registro exonerado"><b-form-input v-model.trim="clientForm.exoneration_registry_number" /></b-form-group>
        <b-button type="submit" variant="primary" :disabled="saving">Guardar</b-button>
      </b-form>
    </b-modal>

    <b-modal id="SarPointModal" hide-footer :title="pointForm.id ? 'Editar punto de emisión' : 'Agregar punto de emisión'">
      <b-form @submit.prevent="savePoint">
        <b-row>
          <b-col md="6"><b-form-group label="Código de establecimiento *"><b-form-input maxlength="3" v-model.trim="pointForm.establishment_code" placeholder="000" /></b-form-group></b-col>
          <b-col md="6"><b-form-group label="Código del punto *"><b-form-input maxlength="3" v-model.trim="pointForm.point_code" placeholder="001" /></b-form-group></b-col>
          <b-col md="12"><b-form-group label="Nombre *"><b-form-input v-model.trim="pointForm.name" /></b-form-group></b-col>
          <b-col md="6"><b-form-group label="Almacén"><v-select v-model="pointForm.warehouse_id" :reduce="o => o.value" :options="warehouseOptions" /></b-form-group></b-col>
          <b-col md="6"><b-form-group label="Caja física"><v-select v-model="pointForm.cash_drawer_id" :reduce="o => o.value" :options="drawerOptions" /></b-form-group></b-col>
          <b-col md="12"><b-form-group label="Dirección del punto *"><b-form-textarea rows="2" v-model.trim="pointForm.address" /></b-form-group></b-col>
          <b-col md="12"><b-form-checkbox v-model="pointForm.active" switch>Activo</b-form-checkbox></b-col>
        </b-row>
        <b-button type="submit" variant="primary" class="mt-3" :disabled="saving">Guardar</b-button>
      </b-form>
    </b-modal>

    <b-modal id="SarAuthorizationModal" hide-footer title="Agregar autorización SAR">
      <b-form @submit.prevent="saveAuthorization">
        <b-form-group label="Punto de emisión *"><v-select v-model="authForm.point_of_issue_id" :reduce="o => o.value" :options="pointOptions" /></b-form-group>
        <b-form-group label="CAI *"><b-form-input v-model.trim="authForm.cai" /></b-form-group>
        <b-row>
          <b-col md="4"><b-form-group label="Tipo *"><b-form-input maxlength="2" v-model.trim="authForm.document_type" /></b-form-group></b-col>
          <b-col md="4"><b-form-group label="Inicio *"><b-form-input type="number" v-model.number="authForm.range_start" /></b-form-group></b-col>
          <b-col md="4"><b-form-group label="Final *"><b-form-input type="number" v-model.number="authForm.range_end" /></b-form-group></b-col>
          <b-col md="6"><b-form-group label="Siguiente correlativo *"><b-form-input type="number" v-model.number="authForm.next_number" /></b-form-group></b-col>
          <b-col md="6"><b-form-group label="Fecha límite *"><b-form-input type="date" v-model="authForm.deadline" /></b-form-group></b-col>
          <b-col md="6"><b-form-group label="Fecha de autorización"><b-form-input type="date" v-model="authForm.authorization_date" /></b-form-group></b-col>
        </b-row>
        <b-button type="submit" variant="primary" :disabled="saving">Guardar como borrador</b-button>
      </b-form>
    </b-modal>
  </div>
</template>

<script>
import NProgress from "nprogress";

const invoiceDefaults = () => ({
  document_title: "FACTURA", sale_type_label: "CONTADO", website: "", footer_message: "Gracias por su compra.",
  original_label: "Original: Cliente", copy_label: "Copia: Obligado Tributario Emisor",
  show_logo: true, show_internal_reference: true, show_cashier: true, show_warehouse: true,
  show_payment_summary: true, show_customer_address: true, show_item_code: true, show_total_in_words: true, show_qr: true
});

export default {
  metaInfo: { title: "Facturación SAR" },
  data() {
    return {
      loading: true, saving: false, points: [], warehouses: [], cashDrawers: [], products: [], clients: [],
      productSearch: "", clientSearch: "", taxCategories: [], taxRates: [0,15,18],
      profile: { enabled: false, rtn: "", legal_name: "", trade_name: "", head_office_address: "", phone: "", email: "", invoice_settings: invoiceDefaults() },
      pointForm: {}, authForm: {}, clientForm: {},
      taxMethodOptions: [{label:"Exclusivo",value:"1"},{label:"Incluido en precio",value:"2"}],
      invoiceToggles: [
        {key:"show_logo",label:"Mostrar logo"}, {key:"show_internal_reference",label:"Mostrar referencia interna"},
        {key:"show_cashier",label:"Mostrar cajero"}, {key:"show_warehouse",label:"Mostrar almacén"},
        {key:"show_payment_summary",label:"Mostrar resumen de pago"}, {key:"show_customer_address",label:"Mostrar dirección cliente"},
        {key:"show_item_code",label:"Mostrar código de producto"}, {key:"show_total_in_words",label:"Mostrar total en letras"},
        {key:"show_qr",label:"Mostrar QR"}
      ]
    };
  },
  computed: {
    warehouseOptions() { return this.warehouses.map(x => ({ label: x.name, value: x.id })); },
    drawerOptions() { return this.cashDrawers.filter(x => !this.pointForm.warehouse_id || x.warehouse_id === this.pointForm.warehouse_id).map(x => ({ label: x.name + " (" + x.code + ")", value: x.id })); },
    pointOptions() { return this.points.filter(x => x.active).map(x => ({ label: x.establishment_code + "-" + x.point_code + " · " + x.name, value: x.id })); },
    hasAuthorizations() { return this.points.some(x => (x.authorizations || []).length); },
    filteredProducts() { const q=this.productSearch.toLowerCase(); return this.products.filter(x=>!q || String(x.name||"").toLowerCase().includes(q) || String(x.code||"").toLowerCase().includes(q)); },
    filteredClients() { const q=this.clientSearch.toLowerCase(); return this.clients.filter(x=>!q || String(x.name||"").toLowerCase().includes(q) || String(x.tax_number||"").toLowerCase().includes(q)); }
  },
  methods: {
    toast(variant, message) { this.$root.$bvToast.toast(message, { title: variant === "success" ? "Éxito" : "Atención", variant, solid: true }); },
    errorMessage(error) { const data=error.response&&error.response.data; if(data&&data.errors){const key=Object.keys(data.errors)[0];return data.errors[key][0];} return (data&&data.message)||"No se pudo completar la operación."; },
    normalizeProduct(p) { const category=p.fiscal_tax_category || (Number(p.TaxNet)>0?"taxed":"exempt"); return Object.assign({},p,{fiscal_tax_category:category,TaxNet:Number(p.TaxNet||0),tax_method:String(p.tax_method||"1")}); },
    taxRateOptions(product) { return (product.fiscal_tax_category === "taxed" ? this.taxRates.filter(x=>Number(x)>0) : [0]).map(x=>({label:x+"%",value:Number(x)})); },
    async load() {
      this.loading=true; NProgress.start();
      try {
        const r=await axios.get("sar-fiscal/settings");
        const incoming=r.data.profile||{};
        this.profile=Object.assign({},this.profile,incoming,{invoice_settings:Object.assign(invoiceDefaults(),incoming.invoice_settings||{})});
        this.points=r.data.points||[]; this.warehouses=r.data.warehouses||[]; this.cashDrawers=r.data.cash_drawers||[];
        this.products=(r.data.products||[]).map(this.normalizeProduct); this.clients=(r.data.clients||[]).map(x=>Object.assign({},x));
        this.taxCategories=r.data.tax_categories||[]; this.taxRates=r.data.tax_rates||[0,15,18];
      } catch(e){this.toast("danger",this.errorMessage(e));} finally {this.loading=false;NProgress.done();}
    },
    async saveProfile() { this.saving=true; try{await axios.put("sar-fiscal/profile",this.profile);this.toast("success","Configuración fiscal guardada.");await this.load();}catch(e){this.toast("danger",this.errorMessage(e));}finally{this.saving=false;} },
    async saveProductFiscal(product) {
      this.saving=true;
      try {
        await axios.put("sar-fiscal/profile", { action:"product_fiscal", product_id:product.id, fiscal_tax_category:product.fiscal_tax_category, TaxNet:product.fiscal_tax_category==="taxed"?Number(product.TaxNet):0, tax_method:String(product.tax_method||"1") });
        this.toast("success","Clasificación fiscal del producto guardada."); await this.load();
      } catch(e){this.toast("danger",this.errorMessage(e));} finally{this.saving=false;}
    },
    openClient(client){this.clientForm=Object.assign({},client);this.$bvModal.show("SarClientModal");},
    async saveClientFiscal(){
      this.saving=true;
      try{await axios.put("sar-fiscal/profile",Object.assign({action:"client_fiscal",client_id:this.clientForm.id},this.clientForm));this.$bvModal.hide("SarClientModal");this.toast("success","Datos fiscales del cliente guardados.");await this.load();}
      catch(e){this.toast("danger",this.errorMessage(e));} finally{this.saving=false;}
    },
    openPoint(point) { this.pointForm=point?Object.assign({},point):{id:null,establishment_code:"000",point_code:"001",name:"",address:"",warehouse_id:null,cash_drawer_id:null,active:true}; this.$bvModal.show("SarPointModal"); },
    async savePoint(){this.saving=true;try{const url=this.pointForm.id?"sar-fiscal/points/"+this.pointForm.id:"sar-fiscal/points";if(this.pointForm.id)await axios.put(url,this.pointForm);else await axios.post(url,this.pointForm);this.$bvModal.hide("SarPointModal");this.toast("success","Punto de emisión guardado.");await this.load();}catch(e){this.toast("danger",this.errorMessage(e));}finally{this.saving=false;}},
    openAuthorization(){this.authForm={point_of_issue_id:this.points.length===1?this.points[0].id:null,document_type:"01",cai:"",range_start:1,range_end:null,next_number:1,authorization_date:"",deadline:""};this.$bvModal.show("SarAuthorizationModal");},
    async saveAuthorization(){this.saving=true;try{await axios.post("sar-fiscal/authorizations",this.authForm);this.$bvModal.hide("SarAuthorizationModal");this.toast("success","Autorización guardada como borrador.");await this.load();}catch(e){this.toast("danger",this.errorMessage(e));}finally{this.saving=false;}},
    async activate(auth){const result=await this.$swal({title:"¿Activar autorización?",text:"Las futuras facturas fiscales usarán este rango.",type:"warning",showCancelButton:true,confirmButtonText:"Activar",cancelButtonText:"Cancelar"});if(!result.value)return;try{await axios.post("sar-fiscal/authorizations/"+auth.id+"/activate");this.toast("success","Autorización activada.");await this.load();}catch(e){this.toast("danger",this.errorMessage(e));}},
    warehouseName(id){const item=this.warehouses.find(x=>x.id===id);return item?item.name:"-";},
    drawerName(id){const item=this.cashDrawers.find(x=>x.id===id);return item?item.name:"-";},
    statusLabel(status){return({draft:"Borrador",active:"Activa",exhausted:"Agotada",expired:"Vencida",disabled:"Deshabilitada"})[status]||status;}
  },
  created(){this.load();}
};
</script>
