<template>
  <div class="main-content">
    <breadcumb page="Cajas físicas" :folder="$t('Settings')" />

    <div v-if="isLoading" class="loading_page spinner spinner-primary mr-3"></div>

    <b-card v-else>
      <div class="d-flex flex-wrap justify-content-between align-items-center mb-3">
        <div>
          <h5 class="mb-1">Cajas físicas</h5>
          <small class="text-muted">Cada caja pertenece a una sucursal y descuenta ventas desde una ubicación de inventario vendible, normalmente el Piso de venta.</small>
        </div>
        <b-button variant="primary" class="btn-rounded" @click="openCreate">
          <lucide-icon name="plus" class="mr-1" />
          Agregar
        </b-button>
      </div>

      <b-alert v-if="!branches.length" show variant="warning">
        Primero crea una sucursal con inventario y un Piso de venta antes de agregar una caja física.
      </b-alert>

      <div class="table-responsive">
        <table class="table table-hover">
          <thead>
            <tr>
              <th>Nombre</th>
              <th>Código</th>
              <th>Sucursal</th>
              <th>Ubicación de venta</th>
              <th>Estado</th>
              <th>Descripción</th>
              <th class="text-right">Acciones</th>
            </tr>
          </thead>
          <tbody>
            <tr v-for="drawer in cashDrawers" :key="drawer.id">
              <td>{{ drawer.name }}</td>
              <td>{{ drawer.code }}</td>
              <td>
                <span v-if="drawer.branch">{{ drawer.branch.name }}</span>
                <span v-else-if="drawer.warehouse" class="text-warning">Legado · {{ drawer.warehouse.name }}</span>
                <span v-else>—</span>
              </td>
              <td>
                <span v-if="drawer.inventory_location">{{ drawer.inventory_location.name }}</span>
                <span v-else class="text-muted">Pendiente de migrar</span>
              </td>
              <td>
                <b-badge :variant="drawer.is_active ? 'success' : 'secondary'">
                  {{ drawer.is_active ? 'Activa' : 'Inactiva' }}
                </b-badge>
              </td>
              <td>{{ drawer.description || '-' }}</td>
              <td class="text-right">
                <a href="#" class="mr-3" title="Editar" @click.prevent="openEdit(drawer)">
                  <lucide-icon class="text-success" name="pencil" />
                </a>
                <a href="#" title="Eliminar" @click.prevent="removeDrawer(drawer)">
                  <lucide-icon class="text-danger" name="trash-2" />
                </a>
              </td>
            </tr>
            <tr v-if="!cashDrawers.length">
              <td colspan="7" class="text-center text-muted py-4">
                No hay cajas físicas registradas.
              </td>
            </tr>
          </tbody>
        </table>
      </div>
    </b-card>

    <validation-observer ref="CashDrawerForm">
      <b-modal
        id="CashDrawerModal"
        hide-footer
        size="lg"
        :title="editMode ? 'Editar caja física' : 'Agregar caja física'"
      >
        <b-alert show variant="light" class="border">
          La caja no crea inventario propio. Las ventas realizadas desde ella descuentan de la ubicación seleccionada.
        </b-alert>

        <b-form @submit.prevent="submitDrawer">
          <b-row>
            <b-col md="6">
              <validation-provider name="Nombre" rules="required" v-slot="validationContext">
                <b-form-group label="Nombre *">
                  <b-form-input
                    v-model.trim="form.name"
                    :state="getValidationState(validationContext)"
                    placeholder="Ej. Caja principal"
                  />
                  <b-form-invalid-feedback>{{ validationContext.errors[0] }}</b-form-invalid-feedback>
                </b-form-group>
              </validation-provider>
            </b-col>

            <b-col md="6">
              <validation-provider name="Código" rules="required" v-slot="validationContext">
                <b-form-group label="Código *">
                  <b-form-input
                    v-model.trim="form.code"
                    :state="getValidationState(validationContext)"
                    placeholder="Ej. CAJA-MALL-01"
                  />
                  <b-form-invalid-feedback>{{ validationContext.errors[0] }}</b-form-invalid-feedback>
                </b-form-group>
              </validation-provider>
            </b-col>

            <b-col md="6">
              <validation-provider name="Sucursal" rules="required" v-slot="validationContext">
                <b-form-group label="Sucursal *">
                  <v-select
                    v-model="form.branch_id"
                    :reduce="option => option.value"
                    :options="branchOptions"
                    :class="{ 'is-invalid': validationContext.errors.length }"
                    placeholder="Selecciona una sucursal"
                    @input="onBranchChange"
                  />
                  <b-form-invalid-feedback class="d-block" v-if="validationContext.errors.length">
                    {{ validationContext.errors[0] }}
                  </b-form-invalid-feedback>
                </b-form-group>
              </validation-provider>
            </b-col>

            <b-col md="6">
              <validation-provider name="Ubicación de venta" rules="required" v-slot="validationContext">
                <b-form-group label="Ubicación de venta *">
                  <v-select
                    v-model="form.inventory_location_id"
                    :reduce="option => option.value"
                    :options="locationOptions"
                    :class="{ 'is-invalid': validationContext.errors.length }"
                    placeholder="Ej. Piso de venta"
                  />
                  <b-form-invalid-feedback class="d-block" v-if="validationContext.errors.length">
                    {{ validationContext.errors[0] }}
                  </b-form-invalid-feedback>
                  <small class="text-muted">Solo aparecen ubicaciones activas y habilitadas para venta de la sucursal seleccionada.</small>
                </b-form-group>
              </validation-provider>
            </b-col>

            <b-col md="6">
              <b-form-group label="Estado">
                <b-form-checkbox v-model="form.is_active" :value="1" :unchecked-value="0" switch>
                  {{ form.is_active ? 'Activa' : 'Inactiva' }}
                </b-form-checkbox>
              </b-form-group>
            </b-col>

            <b-col md="12">
              <b-form-group label="Descripción">
                <b-form-textarea
                  v-model.trim="form.description"
                  rows="3"
                  placeholder="Descripción opcional"
                />
              </b-form-group>
            </b-col>

            <b-col md="12" class="mt-3">
              <b-button type="submit" variant="primary" :disabled="submitting || !locationOptions.length">
                <lucide-icon name="check" class="mr-1" />
                Guardar
              </b-button>
              <b-button variant="secondary" class="ml-2" @click="$bvModal.hide('CashDrawerModal')">
                Cancelar
              </b-button>
              <div v-if="submitting" class="spinner sm spinner-primary mt-3"></div>
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
  metaInfo: {
    title: "Cajas físicas"
  },

  data() {
    return {
      isLoading: true,
      submitting: false,
      editMode: false,
      cashDrawers: [],
      branches: [],
      inventoryLocations: [],
      form: this.emptyForm()
    };
  },

  computed: {
    branchOptions() {
      return this.branches.map(branch => ({
        label: branch.code ? `${branch.name} (${branch.code})` : branch.name,
        value: branch.id
      }));
    },
    locationOptions() {
      if (!this.form.branch_id) return [];
      return this.inventoryLocations
        .filter(location => Number(location.branch_id) === Number(this.form.branch_id) && !!location.is_sellable)
        .map(location => ({
          label: location.is_default_sales ? `${location.name} · predeterminada` : location.name,
          value: location.id
        }));
    }
  },

  methods: {
    emptyForm() {
      return {
        id: null,
        branch_id: null,
        inventory_location_id: null,
        warehouse_id: null,
        name: "",
        code: "",
        description: "",
        is_active: 1
      };
    },

    getValidationState({ dirty, validated, valid = null }) {
      return dirty || validated ? valid : null;
    },

    toast(variant, message, title) {
      this.$root.$bvToast.toast(message, {
        title,
        variant,
        solid: true
      });
    },

    errorMessage(error) {
      const data = error.response && error.response.data;
      if (data && data.errors) {
        const first = Object.keys(data.errors)[0];
        if (first && data.errors[first] && data.errors[first][0]) {
          return data.errors[first][0];
        }
      }
      return (data && data.message) || "No se pudo completar la operación.";
    },

    resetForm() {
      this.form = this.emptyForm();
      if (this.branches.length === 1) {
        this.form.branch_id = this.branches[0].id;
        this.selectDefaultLocation();
      }
      if (this.$refs.CashDrawerForm) {
        this.$refs.CashDrawerForm.reset();
      }
    },

    selectDefaultLocation() {
      const branch = this.branches.find(item => Number(item.id) === Number(this.form.branch_id));
      const options = this.locationOptions;
      const preferred = branch && branch.default_inventory_location_id
        ? options.find(option => Number(option.value) === Number(branch.default_inventory_location_id))
        : null;
      this.form.inventory_location_id = preferred ? preferred.value : (options.length === 1 ? options[0].value : null);
    },

    onBranchChange() {
      this.form.inventory_location_id = null;
      this.selectDefaultLocation();
    },

    openCreate() {
      if (!this.branches.length) {
        this.toast("warning", "Primero crea una sucursal con Piso de venta.", "Atención");
        return;
      }
      this.editMode = false;
      this.resetForm();
      this.$bvModal.show("CashDrawerModal");
    },

    openEdit(drawer) {
      this.editMode = true;
      this.form = {
        id: drawer.id,
        branch_id: drawer.branch_id || null,
        inventory_location_id: drawer.inventory_location_id || null,
        warehouse_id: drawer.warehouse_id || null,
        name: drawer.name,
        code: drawer.code,
        description: drawer.description || "",
        is_active: drawer.is_active ? 1 : 0
      };
      this.$bvModal.show("CashDrawerModal");
    },

    async loadData() {
      this.isLoading = true;
      NProgress.start();
      try {
        const response = await axios.get("cash-drawers");
        this.cashDrawers = response.data.cash_drawers || [];
        this.branches = response.data.branches || [];
        this.inventoryLocations = response.data.inventory_locations || [];
      } catch (error) {
        this.toast("danger", this.errorMessage(error), "Error");
      } finally {
        this.isLoading = false;
        NProgress.done();
      }
    },

    submitDrawer() {
      this.$refs.CashDrawerForm.validate().then(valid => {
        if (!valid || !this.form.branch_id || !this.form.inventory_location_id) {
          this.toast("warning", "Selecciona la sucursal y una ubicación de venta válida.", "Atención");
          return;
        }
        this.saveDrawer();
      });
    },

    async saveDrawer() {
      this.submitting = true;
      const payload = {
        branch_id: this.form.branch_id,
        inventory_location_id: this.form.inventory_location_id,
        // Preserve a legacy compatibility pointer for already-existing drawers.
        warehouse_id: this.form.warehouse_id || null,
        name: this.form.name,
        code: this.form.code,
        description: this.form.description || null,
        is_active: this.form.is_active ? 1 : 0
      };

      try {
        if (this.editMode) {
          await axios.put("cash-drawers/" + this.form.id, payload);
        } else {
          await axios.post("cash-drawers", payload);
        }
        this.$bvModal.hide("CashDrawerModal");
        this.toast(
          "success",
          this.editMode ? "Caja física actualizada correctamente." : "Caja física creada correctamente.",
          "Éxito"
        );
        await this.loadData();
      } catch (error) {
        this.toast("danger", this.errorMessage(error), "Error");
      } finally {
        this.submitting = false;
      }
    },

    removeDrawer(drawer) {
      this.$swal({
        title: "¿Eliminar caja física?",
        text: "La caja dejará de estar disponible para nuevas sesiones. El historial se conserva.",
        type: "warning",
        showCancelButton: true,
        confirmButtonColor: "#d33",
        cancelButtonText: "Cancelar",
        confirmButtonText: "Eliminar"
      }).then(async result => {
        if (!(result.value || result.isConfirmed)) return;
        try {
          await axios.delete("cash-drawers/" + drawer.id);
          this.toast("success", "Caja física eliminada correctamente.", "Éxito");
          await this.loadData();
        } catch (error) {
          this.toast("danger", this.errorMessage(error), "Error");
        }
      });
    }
  },

  created() {
    this.loadData();
  }
};
</script>
