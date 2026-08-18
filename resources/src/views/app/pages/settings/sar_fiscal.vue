<template>
  <div class="main-content">
    <breadcumb page="Facturación SAR" :folder="$t('Settings')" />
    <div v-if="loading" class="loading_page spinner spinner-primary mr-3"></div>

    <div v-else>
      <b-alert show variant="info">
        Esta configuración no genera una autorización. Debes ingresar exactamente los datos aprobados por el SAR.
        La emisión fiscal permanecerá apagada hasta activar una autorización válida.
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
            <b-button variant="primary" class="mt-3" :disabled="saving" @click="saveProfile">Guardar perfil</b-button>
          </b-col>
        </b-row>
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
                  <td class="text-right">
                    <b-button v-if="auth.status === 'draft' || auth.status === 'disabled'" size="sm" variant="success" @click="activate(auth)">Activar</b-button>
                  </td>
                </tr>
              </template>
              <tr v-if="!hasAuthorizations"><td colspan="7" class="text-center text-muted">No hay autorizaciones registradas.</td></tr>
            </tbody>
          </table>
        </div>
      </b-card>
    </div>

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

export default {
  metaInfo: { title: "Facturación SAR" },
  data() {
    return {
      loading: true, saving: false, points: [], warehouses: [], cashDrawers: [],
      profile: { enabled: false, rtn: "", legal_name: "", trade_name: "", head_office_address: "", phone: "", email: "" },
      pointForm: {},
      authForm: {}
    };
  },
  computed: {
    warehouseOptions() { return this.warehouses.map(x => ({ label: x.name, value: x.id })); },
    drawerOptions() {
      return this.cashDrawers
        .filter(x => !this.pointForm.warehouse_id || x.warehouse_id === this.pointForm.warehouse_id)
        .map(x => ({ label: x.name + " (" + x.code + ")", value: x.id }));
    },
    pointOptions() { return this.points.filter(x => x.active).map(x => ({ label: x.establishment_code + "-" + x.point_code + " · " + x.name, value: x.id })); },
    hasAuthorizations() { return this.points.some(x => (x.authorizations || []).length); }
  },
  methods: {
    toast(variant, message) { this.$root.$bvToast.toast(message, { title: variant === "success" ? "Éxito" : "Atención", variant, solid: true }); },
    errorMessage(error) {
      const data = error.response && error.response.data;
      if (data && data.errors) { const key = Object.keys(data.errors)[0]; return data.errors[key][0]; }
      return (data && data.message) || "No se pudo completar la operación.";
    },
    async load() {
      this.loading = true; NProgress.start();
      try {
        const response = await axios.get("sar-fiscal/settings");
        this.profile = Object.assign({}, this.profile, response.data.profile || {});
        this.points = response.data.points || [];
        this.warehouses = response.data.warehouses || [];
        this.cashDrawers = response.data.cash_drawers || [];
      } catch (e) { this.toast("danger", this.errorMessage(e)); }
      finally { this.loading = false; NProgress.done(); }
    },
    async saveProfile() {
      this.saving = true;
      try { await axios.put("sar-fiscal/profile", this.profile); this.toast("success", "Perfil fiscal guardado."); await this.load(); }
      catch (e) { this.toast("danger", this.errorMessage(e)); }
      finally { this.saving = false; }
    },
    openPoint(point) {
      this.pointForm = point ? Object.assign({}, point) : { id: null, establishment_code: "000", point_code: "001", name: "", address: "", warehouse_id: null, cash_drawer_id: null, active: true };
      this.$bvModal.show("SarPointModal");
    },
    async savePoint() {
      this.saving = true;
      try {
        const url = this.pointForm.id ? "sar-fiscal/points/" + this.pointForm.id : "sar-fiscal/points";
        if (this.pointForm.id) await axios.put(url, this.pointForm); else await axios.post(url, this.pointForm);
        this.$bvModal.hide("SarPointModal"); this.toast("success", "Punto de emisión guardado."); await this.load();
      } catch (e) { this.toast("danger", this.errorMessage(e)); }
      finally { this.saving = false; }
    },
    openAuthorization() {
      this.authForm = { point_of_issue_id: this.points.length === 1 ? this.points[0].id : null, document_type: "01", cai: "", range_start: 1, range_end: null, next_number: 1, authorization_date: "", deadline: "" };
      this.$bvModal.show("SarAuthorizationModal");
    },
    async saveAuthorization() {
      this.saving = true;
      try {
        await axios.post("sar-fiscal/authorizations", this.authForm);
        this.$bvModal.hide("SarAuthorizationModal"); this.toast("success", "Autorización guardada como borrador."); await this.load();
      } catch (e) { this.toast("danger", this.errorMessage(e)); }
      finally { this.saving = false; }
    },
    async activate(auth) {
      const result = await this.$swal({ title: "¿Activar autorización?", text: "Las futuras facturas fiscales usarán este rango cuando se habilite la emisión.", type: "warning", showCancelButton: true, confirmButtonText: "Activar", cancelButtonText: "Cancelar" });
      if (!result.value) return;
      try { await axios.post("sar-fiscal/authorizations/" + auth.id + "/activate"); this.toast("success", "Autorización activada."); await this.load(); }
      catch (e) { this.toast("danger", this.errorMessage(e)); }
    },
    warehouseName(id) { const item = this.warehouses.find(x => x.id === id); return item ? item.name : "-"; },
    drawerName(id) { const item = this.cashDrawers.find(x => x.id === id); return item ? item.name : "-"; },
    statusLabel(status) { return ({ draft: "Borrador", active: "Activa", exhausted: "Agotada", expired: "Vencida", disabled: "Deshabilitada" })[status] || status; }
  },
  created() { this.load(); }
};
</script>
