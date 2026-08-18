<template>
  <div class="main-content">
    <breadcumb page="Cajas físicas" :folder="$t('Settings')" />

    <div v-if="isLoading" class="loading_page spinner spinner-primary mr-3"></div>

    <b-card v-else>
      <div class="d-flex flex-wrap justify-content-between align-items-center mb-3">
        <div>
          <h5 class="mb-1">Cajas físicas</h5>
          <small class="text-muted">Administra las cajas disponibles en cada almacén.</small>
        </div>
        <b-button variant="primary" class="btn-rounded" @click="openCreate">
          <lucide-icon name="plus" class="mr-1" />
          Agregar
        </b-button>
      </div>

      <b-alert v-if="!warehouses.length" show variant="warning">
        Primero debes crear un almacén antes de agregar una caja física.
      </b-alert>

      <div class="table-responsive">
        <table class="table table-hover">
          <thead>
            <tr>
              <th>Nombre</th>
              <th>Código</th>
              <th>Almacén</th>
              <th>Estado</th>
              <th>Descripción</th>
              <th class="text-right">Acciones</th>
            </tr>
          </thead>
          <tbody>
            <tr v-for="drawer in cashDrawers" :key="drawer.id">
              <td>{{ drawer.name }}</td>
              <td>{{ drawer.code }}</td>
              <td>{{ drawer.warehouse ? drawer.warehouse.name : '-' }}</td>
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
              <td colspan="6" class="text-center text-muted py-4">
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
                    placeholder="Ej. CAJA-01"
                  />
                  <b-form-invalid-feedback>{{ validationContext.errors[0] }}</b-form-invalid-feedback>
                </b-form-group>
              </validation-provider>
            </b-col>

            <b-col md="6">
              <validation-provider name="Almacén" rules="required" v-slot="validationContext">
                <b-form-group label="Almacén *">
                  <v-select
                    v-model="form.warehouse_id"
                    :reduce="option => option.value"
                    :options="warehouseOptions"
                    :class="{ 'is-invalid': validationContext.errors.length }"
                    placeholder="Selecciona un almacén"
                  />
                  <b-form-invalid-feedback class="d-block" v-if="validationContext.errors.length">
                    {{ validationContext.errors[0] }}
                  </b-form-invalid-feedback>
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
              <b-button type="submit" variant="primary" :disabled="submitting">
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
      warehouses: [],
      form: {
        id: null,
        warehouse_id: null,
        name: "",
        code: "",
        description: "",
        is_active: 1
      }
    };
  },

  computed: {
    warehouseOptions() {
      return this.warehouses.map(warehouse => ({
        label: warehouse.name,
        value: warehouse.id
      }));
    }
  },

  methods: {
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
      this.form = {
        id: null,
        warehouse_id: this.warehouses.length === 1 ? this.warehouses[0].id : null,
        name: "",
        code: "",
        description: "",
        is_active: 1
      };
      if (this.$refs.CashDrawerForm) {
        this.$refs.CashDrawerForm.reset();
      }
    },

    openCreate() {
      if (!this.warehouses.length) {
        this.toast("warning", "Primero debes crear un almacén.", "Atención");
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
        warehouse_id: drawer.warehouse_id,
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
        const [drawersResponse, warehousesResponse] = await Promise.all([
          axios.get("cash-drawers"),
          axios.get("warehouses", {
            params: {
              page: 1,
              SortField: "name",
              SortType: "asc",
              search: "",
              limit: 1000
            }
          })
        ]);
        this.cashDrawers = drawersResponse.data.cash_drawers || [];
        this.warehouses = warehousesResponse.data.warehouses || [];
      } catch (error) {
        this.toast("danger", this.errorMessage(error), "Error");
      } finally {
        this.isLoading = false;
        NProgress.done();
      }
    },

    submitDrawer() {
      this.$refs.CashDrawerForm.validate().then(valid => {
        if (!valid) {
          this.toast("warning", "Completa los campos obligatorios.", "Atención");
          return;
        }
        this.saveDrawer();
      });
    },

    async saveDrawer() {
      this.submitting = true;
      const payload = {
        warehouse_id: this.form.warehouse_id,
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
        text: "La caja dejará de estar disponible para nuevas sesiones.",
        type: "warning",
        showCancelButton: true,
        confirmButtonColor: "#d33",
        cancelButtonText: "Cancelar",
        confirmButtonText: "Eliminar"
      }).then(async result => {
        if (!result.value) return;
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
