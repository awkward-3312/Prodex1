<template>
  <div class="px-next pxcfg">
    <px-page-header
      title="Cajas físicas"
      :subtitle="contextBranch ? `Administrando cajas de ${contextBranch.name}. Cada caja pertenece a una sucursal y opera desde una ubicación vendible, normalmente Piso de venta.` : 'Cada caja pertenece a una sucursal y opera desde una ubicación vendible, normalmente Piso de venta.'"
      :breadcrumbs="[{ label: $t('Settings'), href: '#/app/settings/System_settings' }, { label: contextBranch ? contextBranch.name : 'Cajas físicas' }]"
    >
      <template #actions>
        <px-button v-if="contextBranchId" variant="ghost" size="sm" icon="arrow-left" @click="backToBranches">Sucursales</px-button>
        <px-button variant="primary" size="sm" icon="plus" @click="openCreate">Agregar caja</px-button>
      </template>
    </px-page-header>

    <div v-if="isLoading" class="pxcfg__pad">
      <px-skeleton variant="table" :rows="8" :columns="7" />
    </div>

    <template v-else>
      <px-alert v-if="!branches.length" tone="warning" class="pxcfg__alert">
        Primero crea una sucursal con inventario y un Piso de venta antes de agregar una caja física.
      </px-alert>
      <px-alert v-if="contextBranch && !sellableLocationsForContext.length" tone="warning" class="pxcfg__alert">
        {{ contextBranch.name }} no tiene una ubicación activa habilitada para venta. Crea o habilita un Piso de venta antes de agregar cajas físicas.
      </px-alert>

      <div class="pxcfg__tablewrap">
        <px-table
          v-if="cashDrawers.length"
          :columns="columns"
          :rows="cashDrawers"
          row-key="id"
          has-row-actions
        >
          <template #cell-branch="{ row }">
            <span v-if="row.branch">{{ row.branch.name }}</span>
            <span v-else-if="row.warehouse" class="pxcfg__warn">Legado · {{ row.warehouse.name }}</span>
            <span v-else>—</span>
          </template>
          <template #cell-inventory_location="{ row }">
            <span v-if="row.inventory_location">{{ row.inventory_location.name }}</span>
            <span v-else class="pxcfg__muted">Pendiente de migrar</span>
          </template>
          <template #cell-is_active="{ row }">
            <px-badge :tone="row.is_active ? 'success' : 'neutral'">{{ row.is_active ? 'Activa' : 'Inactiva' }}</px-badge>
          </template>
          <template #cell-description="{ row }">{{ row.description || '-' }}</template>
          <template #row-actions="{ row }">
            <div class="pxcfg__rowbtns">
              <px-button variant="ghost" size="sm" icon-only icon="pencil" aria-label="Editar" @click="openEdit(row)" />
              <px-button class="pxcfg__del" variant="ghost" size="sm" icon-only icon="archive" aria-label="Desactivar" @click="removeDrawer(row)" />
            </div>
          </template>
        </px-table>
        <px-empty-state v-else icon="wallet" title="Sin cajas físicas"
          :description="contextBranch ? 'Esta sucursal todavía no tiene cajas físicas registradas.' : 'No hay cajas físicas registradas.'" />
      </div>
    </template>

    <px-modal v-model="modalOpen" size="lg" :title="editMode ? 'Editar caja física' : 'Agregar caja física'">
      <px-alert tone="info" bare class="pxcfg__alert">
        La caja física identifica el punto donde trabaja el cajero. No crea inventario propio: las ventas descuentan de la ubicación seleccionada.
      </px-alert>

      <validation-observer ref="CashDrawerForm">
        <form @submit.prevent="submitDrawer">
          <div class="pxcfg__grid">
            <validation-provider ref="nameProvider" name="Nombre" rules="required" v-slot="v">
              <px-field label="Nombre *" :error="v.errors[0]">
                <template #default="{ id, invalid }">
                  <px-input :id="id" :value="form.name" @input="val => { form.name = val.trim ? val.trim() : val; v.validate(); }" placeholder="Ej. Caja 1" :invalid="invalid" />
                </template>
              </px-field>
            </validation-provider>

            <validation-provider ref="codeProvider" name="Código" rules="required" v-slot="v">
              <px-field label="Código *" :error="v.errors[0]">
                <template #default="{ id, invalid }">
                  <px-input :id="id" :value="form.code" @input="val => { form.code = val.trim ? val.trim() : val; v.validate(); }" placeholder="Ej. SPS-PISO-CAJA-01" :invalid="invalid" />
                </template>
              </px-field>
            </validation-provider>

            <validation-provider ref="branchProvider" name="Sucursal" rules="required" v-slot="v">
              <px-field label="Sucursal *" :error="v.errors[0]">
                <template #default="{ id }">
                  <vs-px :input-id="id" v-model="form.branch_id" :reduce="option => option.value" :options="branchOptions"
                    :disabled="!!contextBranchId" placeholder="Selecciona una sucursal" @input="onBranchChangeAndValidate(v)" />
                </template>
              </px-field>
            </validation-provider>

            <validation-provider ref="locationProvider" name="Ubicación de venta" rules="required" v-slot="v">
              <px-field label="Ubicación de venta *" :error="v.errors[0]"
                hint="Solo aparecen ubicaciones activas y habilitadas para venta de la sucursal seleccionada.">
                <template #default="{ id }">
                  <vs-px :input-id="id" v-model="form.inventory_location_id" :reduce="option => option.value" :options="locationOptions"
                    placeholder="Ej. Piso de venta" @input="v.validate" />
                </template>
              </px-field>
            </validation-provider>

            <px-field label="Estado">
              <template #default>
                <px-check type="switch" :modelValue="!!form.is_active" @change="val => form.is_active = val ? 1 : 0">
                  {{ form.is_active ? 'Activa' : 'Inactiva' }}
                </px-check>
              </template>
            </px-field>
          </div>

          <px-field label="Descripción" class="pxcfg__mt">
            <template #default="{ id }">
              <px-textarea :id="id" :value="form.description" @input="val => form.description = val.trim ? val.trim() : val" :rows="3" placeholder="Ej. Caja principal del mostrador derecho" />
            </template>
          </px-field>
        </form>
      </validation-observer>

      <template #footer="{ close }">
        <px-button variant="ghost" @click="close">Cancelar</px-button>
        <px-button variant="primary" icon="check" :loading="submitting" :disabled="submitting || !locationOptions.length" @click="submitDrawer">Guardar caja</px-button>
      </template>
    </px-modal>
  </div>
</template>

<script>
import NProgress from "nprogress";
import PxPageHeader from "@/components/px-next/PxPageHeader.vue";
import PxTable from "@/components/px-next/PxTable.vue";
import PxButton from "@/components/px-next/PxButton.vue";
import PxModal from "@/components/px-next/PxModal.vue";
import PxField from "@/components/px-next/PxField.vue";
import PxInput from "@/components/px-next/PxInput.vue";
import PxTextarea from "@/components/px-next/PxTextarea.vue";
import PxCheck from "@/components/px-next/PxCheck.vue";
import PxBadge from "@/components/px-next/PxBadge.vue";
import PxAlert from "@/components/px-next/PxAlert.vue";
import PxEmptyState from "@/components/px-next/PxEmptyState.vue";
import VsPx from "@/views/app/products/next/edit/VsPx.vue";

export default {
  metaInfo: { title: "Cajas físicas" },
  components: {
    PxPageHeader, PxTable, PxButton, PxModal, PxField, PxInput, PxTextarea,
    PxCheck, PxBadge, PxAlert, PxEmptyState, "vs-px": VsPx
  },

  data() {
    return {
      modalOpen: false,
      isLoading: true,
      submitting: false,
      editMode: false,
      contextBranchId: null,
      cashDrawers: [],
      branches: [],
      inventoryLocations: [],
      form: this.emptyForm()
    };
  },

  computed: {
    columns() {
      return [
        { key: "name", label: "Nombre", strong: true },
        { key: "code", label: "Código" },
        { key: "branch", label: "Sucursal" },
        { key: "inventory_location", label: "Ubicación de venta" },
        { key: "is_active", label: "Estado" },
        { key: "description", label: "Descripción" }
      ];
    },
    contextBranch() {
      if (!this.contextBranchId) return null;
      return this.branches.find(branch => Number(branch.id) === Number(this.contextBranchId)) || null;
    },
    sellableLocationsForContext() {
      if (!this.contextBranchId) return [];
      return this.inventoryLocations.filter(location => Number(location.branch_id) === Number(this.contextBranchId) && !!location.is_sellable);
    },
    branchOptions() {
      const branches = this.contextBranchId
        ? this.branches.filter(branch => Number(branch.id) === Number(this.contextBranchId))
        : this.branches;
      return branches.map(branch => ({
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
      this.$root.$bvToast.toast(message, { title, variant, solid: true });
    },
    errorMessage(error) {
      const data = (error && error.response && error.response.data) || (error && typeof error === 'object' ? error : null);
      if (data && data.errors) {
        const first = Object.keys(data.errors)[0];
        if (first && data.errors[first] && data.errors[first][0]) return data.errors[first][0];
      }
      return (data && (data.message || data.error)) || "No se pudo completar la operación.";
    },
    syncValidators() {
      this.$nextTick(() => {
        const map = { nameProvider: "name", codeProvider: "code", branchProvider: "branch_id", locationProvider: "inventory_location_id" };
        Object.keys(map).forEach(ref => {
          const p = this.$refs[ref];
          if (p && p.syncValue) p.syncValue(this.form[map[ref]]);
        });
      });
    },
    onBranchChangeAndValidate(v) {
      this.onBranchChange();
      if (v && v.validate) v.validate();
      const lp = this.$refs.locationProvider;
      if (lp && lp.syncValue) lp.syncValue(this.form.inventory_location_id);
    },
    resetForm() {
      this.form = this.emptyForm();
      if (this.contextBranchId) {
        this.form.branch_id = Number(this.contextBranchId);
        this.selectDefaultLocation();
      } else if (this.branches.length === 1) {
        this.form.branch_id = this.branches[0].id;
        this.selectDefaultLocation();
      }
      if (this.$refs.CashDrawerForm) this.$refs.CashDrawerForm.reset();
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
      if (!this.locationOptions.length) {
        this.toast("warning", "La sucursal seleccionada no tiene una ubicación habilitada para venta.", "Atención");
        return;
      }
      this.modalOpen = true;
      this.syncValidators();
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
      this.modalOpen = true;
      this.syncValidators();
    },
    async loadData() {
      this.isLoading = true;
      NProgress.start();
      try {
        const params = this.contextBranchId ? { branch_id: this.contextBranchId } : {};
        const response = await axios.get("cash-drawers", { params, meta: { skipErrorRedirect: true } });
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
        warehouse_id: this.form.warehouse_id || null,
        name: this.form.name,
        code: this.form.code,
        description: this.form.description || null,
        is_active: this.form.is_active ? 1 : 0
      };

      try {
        if (this.editMode) await axios.put("cash-drawers/" + this.form.id, payload, { meta: { skipErrorRedirect: true } });
        else await axios.post("cash-drawers", payload, { meta: { skipErrorRedirect: true } });
        this.modalOpen = false;
        this.toast("success", this.editMode ? "Caja física actualizada correctamente." : "Caja física creada correctamente.", "Éxito");
        await this.loadData();
      } catch (error) {
        this.toast("danger", this.errorMessage(error), "Error");
      } finally {
        this.submitting = false;
      }
    },
    removeDrawer(drawer) {
      this.$swal({
        title: "¿Desactivar caja física?",
        text: "La caja dejará de estar disponible para nuevas sesiones. El historial de ventas y arqueos se conserva.",
        type: "warning",
        showCancelButton: true,
        confirmButtonColor: "#d33",
        cancelButtonText: "Cancelar",
        confirmButtonText: "Desactivar"
      }).then(async result => {
        if (!(result.value || result.isConfirmed)) return;
        try {
          await axios.delete("cash-drawers/" + drawer.id, { meta: { skipErrorRedirect: true } });
          this.toast("success", "Caja física desactivada correctamente.", "Éxito");
          await this.loadData();
        } catch (error) {
          this.toast("danger", this.errorMessage(error), "Error");
        }
      });
    },
    backToBranches() {
      this.$router.push('/app/organization/branches').catch(() => {});
    }
  },

  created() {
    const queryBranchId = Number(this.$route && this.$route.query && this.$route.query.branch_id || 0);
    this.contextBranchId = queryBranchId > 0 ? queryBranchId : null;
    this.loadData();
  }
};
</script>

<style lang="scss" src="@/assets/styles/sass/px-next/production.scss"></style>

<style lang="scss" scoped>
.pxcfg { min-height: 100%; background: var(--pxn-bg); padding: var(--pxn-space-8) var(--pxn-space-9) var(--pxn-space-9); }
@media (max-width: 620px) { .pxcfg { padding: var(--pxn-space-6) var(--pxn-space-5); } }
.pxcfg__pad { padding: var(--pxn-space-6) 0; }
.pxcfg__alert { margin-top: var(--pxn-space-4); }
.pxcfg__tablewrap { margin-top: var(--pxn-space-4); }
.pxcfg__grid { display: grid; grid-template-columns: repeat(2, minmax(0, 1fr)); gap: var(--pxn-space-4) var(--pxn-space-5); }
@media (max-width: 640px) { .pxcfg__grid { grid-template-columns: minmax(0, 1fr); } }
.pxcfg__mt { margin-top: var(--pxn-space-4); }
.pxcfg__muted { color: var(--pxn-ink-3); }
.pxcfg__warn { color: var(--pxn-warning); }
.pxcfg__rowbtns { display: flex; gap: var(--pxn-space-2); justify-content: flex-end; }
.pxcfg__del ::v-deep .pxn-btn__icon { color: var(--pxn-danger); }
</style>
