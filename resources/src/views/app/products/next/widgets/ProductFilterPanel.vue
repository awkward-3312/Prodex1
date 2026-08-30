<template>
  <px-modal :value="value" title="Filtros" subtitle="Acotan el listado del servidor" size="sm" @close="$emit('input', false)">
    <form class="pxpf" @submit.prevent="apply">
      <px-field label="Código">
        <template #default="{ id }">
          <px-input :id="id" v-model="draft.code" placeholder="Ej. PR-DEMO-001" />
        </template>
      </px-field>

      <px-field label="Nombre">
        <template #default="{ id }">
          <px-input :id="id" v-model="draft.name" placeholder="Parte del nombre" />
        </template>
      </px-field>

      <px-field label="Categoría">
        <template #default="{ id }">
          <px-select :id="id" v-model="draft.category" :options="categoryOptions" />
        </template>
      </px-field>

      <px-field label="Marca">
        <template #default="{ id }">
          <px-select :id="id" v-model="draft.brand" :options="brandOptions" />
        </template>
      </px-field>

      <px-field label="Almacén">
        <template #default="{ id }">
          <px-select :id="id" v-model="draft.warehouse" :options="warehouseOptions" />
        </template>
      </px-field>

      <px-field label="Estado">
        <template #default="{ id }">
          <px-select :id="id" v-model="draft.status" :options="statusOptions" />
        </template>
      </px-field>
    </form>

    <template #footer="{ close }">
      <px-button variant="ghost" icon="power" :disabled="!activeCount" @click="clear">Limpiar filtros</px-button>
      <span class="pxpf__spacer" />
      <px-button variant="secondary" @click="close">Cancelar</px-button>
      <px-button variant="primary" icon="filter" @click="apply">Aplicar</px-button>
    </template>
  </px-modal>
</template>

<script>
import PxModal from "@/components/px-next/PxModal.vue";
import PxField from "@/components/px-next/PxField.vue";
import PxInput from "@/components/px-next/PxInput.vue";
import PxSelect from "@/components/px-next/PxSelect.vue";
import PxButton from "@/components/px-next/PxButton.vue";

const EMPTY = { code: "", name: "", category: "", brand: "", warehouse: "", status: "" };

export default {
  name: "ProductFilterPanel",
  components: { PxModal, PxField, PxInput, PxSelect, PxButton },
  props: {
    value: { type: Boolean, default: false },
    filters: { type: Object, default: () => ({ ...EMPTY }) },
    categories: { type: Array, default: () => [] },
    brands: { type: Array, default: () => [] },
    warehouses: { type: Array, default: () => [] }
  },
  data() {
    return { draft: { ...EMPTY, ...this.filters } };
  },
  computed: {
    categoryOptions() {
      return [{ value: "", label: "Todas las categorías" }, ...this.categories];
    },
    brandOptions() {
      return [{ value: "", label: "Todas las marcas" }, ...this.brands];
    },
    warehouseOptions() {
      return [{ value: "", label: "Todos los almacenes" }, ...this.warehouses];
    },
    statusOptions() {
      return [
        { value: "", label: "Todos los estados" },
        { value: "1", label: "Activos" },
        { value: "0", label: "Inactivos" }
      ];
    },
    activeCount() {
      return Object.keys(EMPTY).filter(k => String(this.draft[k] || "").trim() !== "").length;
    }
  },
  watch: {
    value(open) {
      if (open) this.draft = { ...EMPTY, ...this.filters };
    }
  },
  methods: {
    apply() {
      this.$emit("apply", { ...this.draft });
      this.$emit("input", false);
    },
    clear() {
      this.draft = { ...EMPTY };
      this.$emit("apply", { ...EMPTY });
      this.$emit("input", false);
    }
  }
};
</script>

<style lang="scss" scoped>
.pxpf { display: flex; flex-direction: column; gap: var(--pxn-space-5); }
.pxpf__spacer { flex: 1; }
</style>
