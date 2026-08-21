<template>
  <div class="main-content">
    <breadcumb page="Almacenes / CD" :folder="$t('Settings')"/>

    <div v-if="isLoading" class="loading_page spinner spinner-primary mr-3"></div>

    <template v-else>
      <b-card class="mb-3">
        <div class="d-flex flex-wrap justify-content-between align-items-start">
          <div>
            <h4 class="mb-1">Almacenes y centros de distribución</h4>
            <p class="text-muted mb-0">Un almacén/CD es una instalación logística independiente. Las bodegas internas de una sucursal se crean como ubicaciones de inventario dentro de esa sucursal y no consumen otro almacén del plan.</p>
          </div>
          <b-button @click="New_Warehouse" class="btn-rounded mt-2 mt-md-0" variant="primary">
            <lucide-icon name="plus" class="mr-1" /> Nuevo almacén / CD
          </b-button>
        </div>
      </b-card>

      <b-card class="wrapper">
        <vue-good-table
          mode="remote"
          :columns="columns"
          :totalRows="totalRows"
          :rows="warehouses"
          @on-page-change="onPageChange"
          @on-per-page-change="onPerPageChange"
          @on-sort-change="onSortChange"
          @on-search="onSearch"
          :search-options="{ enabled: true, placeholder: $t('Search_this_table') }"
          :pagination-options="{ enabled: true, mode: 'records', nextLabel: 'next', prevLabel: 'prev' }"
          styleClass="table-hover tableOne vgt-table"
        >
          <template slot="table-row" slot-scope="props">
            <span v-if="props.column.field === 'default_inventory_location'">
              {{ props.row.default_inventory_location ? props.row.default_inventory_location.name : 'Pendiente de inicializar' }}
            </span>
            <span v-else-if="props.column.field === 'actions'">
              <a @click="Edit_Warehouse(props.row)" title="Editar" v-b-tooltip.hover class="mr-2 cursor-pointer">
                <lucide-icon class="text-25 text-success" name="pencil" />
              </a>
              <a title="Desactivar" v-b-tooltip.hover @click="Remove_Warehouse(props.row.id)" class="cursor-pointer">
                <lucide-icon class="text-25 text-danger" name="archive" />
              </a>
            </span>
          </template>
        </vue-good-table>
      </b-card>
    </template>

    <validation-observer ref="Create_Warehouse">
      <b-modal hide-footer size="lg" id="New_Warehouse" :title="editmode ? 'Editar almacén / CD' : 'Nuevo almacén / CD'">
        <b-alert show variant="light" class="border">
          PRODEX creará una ubicación logística “Inventario principal”. Este almacén/CD no pertenece a una sucursal.
        </b-alert>
        <b-form @submit.prevent="Submit_Warehouse">
          <b-row>
            <b-col md="6">
              <validation-provider name="Nombre" :rules="{ required: true }" v-slot="validationContext">
                <b-form-group label="Nombre *">
                  <b-form-input placeholder="Ej. Centro de distribución principal" :state="getValidationState(validationContext)" v-model.trim="warehouse.name" />
                  <b-form-invalid-feedback>{{ validationContext.errors[0] }}</b-form-invalid-feedback>
                </b-form-group>
              </validation-provider>
            </b-col>
            <b-col md="6"><b-form-group label="Teléfono"><b-form-input v-model.trim="warehouse.mobile" /></b-form-group></b-col>
            <b-col md="6"><b-form-group label="País"><b-form-input v-model.trim="warehouse.country" /></b-form-group></b-col>
            <b-col md="6"><b-form-group label="Ciudad"><b-form-input v-model.trim="warehouse.city" /></b-form-group></b-col>
            <b-col md="6"><b-form-group label="Correo"><b-form-input type="email" v-model.trim="warehouse.email" /></b-form-group></b-col>
            <b-col md="6"><b-form-group label="Código postal"><b-form-input v-model.trim="warehouse.zip" /></b-form-group></b-col>
            <b-col md="12" class="mt-3 text-right">
              <b-button variant="outline-secondary" class="mr-2" @click="$bvModal.hide('New_Warehouse')">Cancelar</b-button>
              <b-button variant="primary" type="submit" :disabled="SubmitProcessing"><lucide-icon class="mr-1" name="check" /> Guardar</b-button>
              <div v-if="SubmitProcessing" class="spinner sm spinner-primary mt-3"></div>
            </b-col>
          </b-row>
        </b-form>
      </b-modal>
    </validation-observer>
  </div>
</template>

<script>
import NProgress from "nprogress";

export default {
  metaInfo: { title: "Almacenes / CD" },
  data() {
    return {
      isLoading: true,
      SubmitProcessing: false,
      serverParams: { sort: { field: "id", type: "desc" }, page: 1, perPage: 10 },
      totalRows: 0,
      search: "",
      limit: 10,
      warehouses: [],
      editmode: false,
      warehouse: this.emptyWarehouse()
    };
  },
  computed: {
    columns() {
      return [
        { label: "Nombre", field: "name", tdClass: "text-left", thClass: "text-left" },
        { label: "Ciudad", field: "city", tdClass: "text-left", thClass: "text-left" },
        { label: "País", field: "country", tdClass: "text-left", thClass: "text-left" },
        { label: "Inventario principal", field: "default_inventory_location", sortable: false },
        { label: "Correo", field: "email", tdClass: "text-left", thClass: "text-left" },
        { label: "Acciones", field: "actions", sortable: false, tdClass: "text-left", thClass: "text-left" }
      ];
    }
  },
  methods: {
    emptyWarehouse() { return { id: null, name: "", mobile: "", email: "", zip: "", country: "Honduras", city: "" }; },
    updateParams(newProps) { this.serverParams = Object.assign({}, this.serverParams, newProps); },
    onPageChange({ currentPage }) { if (this.serverParams.page !== currentPage) { this.updateParams({ page: currentPage }); this.Get_Warehouses(currentPage); } },
    onPerPageChange({ currentPerPage }) { if (this.limit !== currentPerPage) { this.limit = currentPerPage; this.updateParams({ page: 1, perPage: currentPerPage }); this.Get_Warehouses(1); } },
    onSortChange(params) { this.updateParams({ sort: { type: params[0].type, field: params[0].field } }); this.Get_Warehouses(this.serverParams.page); },
    onSearch(value) { this.search = value.searchTerm; this.Get_Warehouses(1); },
    getValidationState({ dirty, validated, valid = null }) { return dirty || validated ? valid : null; },
    toast(variant, msg, title) { this.$root.$bvToast.toast(msg, { title, variant, solid: true }); },
    Submit_Warehouse() { this.$refs.Create_Warehouse.validate().then(success => { if (!success) return this.toast("danger", "Completa los campos obligatorios.", "Error"); this.editmode ? this.Update_Warehouse() : this.Create_Warehouse(); }); },
    New_Warehouse() { this.warehouse = this.emptyWarehouse(); this.editmode = false; this.$bvModal.show("New_Warehouse"); },
    Edit_Warehouse(row) {
      this.warehouse = { id: row.id, name: row.name || "", mobile: row.mobile || "", email: row.email || "", zip: row.zip || "", country: row.country || "", city: row.city || "" };
      this.editmode = true;
      this.$bvModal.show("New_Warehouse");
    },
    async Get_Warehouses(page) {
      NProgress.start();
      try {
        const { data } = await axios.get("warehouses", { params: { page, SortField: this.serverParams.sort.field, SortType: this.serverParams.sort.type, search: this.search, limit: this.limit } });
        this.warehouses = data.warehouses || [];
        this.totalRows = data.totalRows || 0;
      } finally { this.isLoading = false; NProgress.done(); }
    },
    payload() { return { name: this.warehouse.name, mobile: this.warehouse.mobile || null, email: this.warehouse.email || null, zip: this.warehouse.zip || null, country: this.warehouse.country || null, city: this.warehouse.city || null }; },
    async Create_Warehouse() {
      this.SubmitProcessing = true;
      try { await axios.post("warehouses", this.payload()); this.$bvModal.hide("New_Warehouse"); this.toast("success", "Almacén/CD creado correctamente.", "Éxito"); await this.Get_Warehouses(1); }
      catch (error) { this.toast("danger", (error.response && error.response.data && error.response.data.message) || "No se pudo crear el almacén/CD.", "Error"); }
      finally { this.SubmitProcessing = false; }
    },
    async Update_Warehouse() {
      this.SubmitProcessing = true;
      try { await axios.put("warehouses/" + this.warehouse.id, this.payload()); this.$bvModal.hide("New_Warehouse"); this.toast("success", "Almacén/CD actualizado correctamente.", "Éxito"); await this.Get_Warehouses(this.serverParams.page); }
      catch (error) { this.toast("danger", (error.response && error.response.data && error.response.data.message) || "No se pudo actualizar el almacén/CD.", "Error"); }
      finally { this.SubmitProcessing = false; }
    },
    Remove_Warehouse(id) {
      this.$swal({ title: "Desactivar almacén / CD", text: "Se conservará el historial. No desactives un almacén con operaciones pendientes.", type: "warning", showCancelButton: true, confirmButtonText: "Desactivar", cancelButtonText: "Cancelar" }).then(async result => {
        if (!(result.value || result.isConfirmed)) return;
        try { await axios.delete("warehouses/" + id); this.toast("success", "Almacén/CD desactivado.", "Éxito"); await this.Get_Warehouses(this.serverParams.page); }
        catch (error) { this.toast("danger", "No se pudo desactivar el almacén/CD.", "Error"); }
      });
    }
  },
  created() { this.Get_Warehouses(1); }
};
</script>
