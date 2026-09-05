<template>
  <div class="px-next pxcfg">
    <px-page-header
      :title="$t('CustomFields')"
      :breadcrumbs="[{ label: $t('Settings'), href: '#/app/settings/System_settings' }, { label: $t('CustomFields') }]"
    >
      <template #actions>
        <px-button variant="primary" size="sm" icon="plus" @click="New_CustomField(activeEntity)">{{ $t('Add') }}</px-button>
      </template>
    </px-page-header>

    <div v-if="isLoading" class="pxcfg__pad">
      <px-skeleton variant="table" :rows="8" :columns="5" />
    </div>

    <template v-else>
      <div class="pxcfg__tabbar">
        <button type="button" class="pxcfg__tab pxn-ring" :class="{ 'is-active': activeEntity === 'client' }" @click="activeEntity = 'client'">{{ $t('Customers') }}</button>
        <button type="button" class="pxcfg__tab pxn-ring" :class="{ 'is-active': activeEntity === 'provider' }" @click="activeEntity = 'provider'">{{ $t('Suppliers') }}</button>
      </div>

      <div v-show="activeEntity === 'client'" class="pxcfg__panel">
        <h4 class="pxcfg__subhead">{{ $t('CustomerCustomFields') }}</h4>
        <px-table v-if="customerFields.length" :columns="columns" :rows="customerFields" row-key="id" has-row-actions>
          <template #cell-field_type="{ row }">{{ getFieldTypeLabel(row.field_type) }}</template>
          <template #cell-is_required="{ row }"><px-badge :tone="row.is_required ? 'success' : 'neutral'">{{ row.is_required ? $t('Required') : $t('Optional') }}</px-badge></template>
          <template #row-actions="{ row }">
            <div class="pxcfg__rowbtns">
              <px-button variant="ghost" size="sm" icon-only icon="pencil" aria-label="Editar" @click="Edit_CustomField(row)" />
              <px-button class="pxcfg__del" variant="ghost" size="sm" icon-only icon="trash-2" aria-label="Eliminar" @click="Delete_CustomField(row.id)" />
            </div>
          </template>
        </px-table>
        <px-empty-state v-else icon="list-plus" title="Sin campos personalizados" description="Agrega un campo para verlo en esta lista." />
      </div>

      <div v-show="activeEntity === 'provider'" class="pxcfg__panel">
        <h4 class="pxcfg__subhead">{{ $t('SupplierCustomFields') }}</h4>
        <px-table v-if="supplierFields.length" :columns="columns" :rows="supplierFields" row-key="id" has-row-actions>
          <template #cell-field_type="{ row }">{{ getFieldTypeLabel(row.field_type) }}</template>
          <template #cell-is_required="{ row }"><px-badge :tone="row.is_required ? 'success' : 'neutral'">{{ row.is_required ? $t('Required') : $t('Optional') }}</px-badge></template>
          <template #row-actions="{ row }">
            <div class="pxcfg__rowbtns">
              <px-button variant="ghost" size="sm" icon-only icon="pencil" aria-label="Editar" @click="Edit_CustomField(row)" />
              <px-button class="pxcfg__del" variant="ghost" size="sm" icon-only icon="trash-2" aria-label="Eliminar" @click="Delete_CustomField(row.id)" />
            </div>
          </template>
        </px-table>
        <px-empty-state v-else icon="list-plus" title="Sin campos personalizados" description="Agrega un campo para verlo en esta lista." />
      </div>
    </template>

    <px-modal v-model="modalOpen" :title="editmode ? $t('Edit') : $t('Add')" size="lg">
      <validation-observer ref="Create_CustomField">
        <form @submit.prevent="Submit_CustomField">
          <div class="pxcfg__formgrid">
            <validation-provider ref="nameProvider" name="Field Name" :rules="{ required: true }" v-slot="v">
              <px-field :label="$t('FieldName') + ' *'" :error="v.errors[0]">
                <template #default="{ id, invalid }"><px-input :id="id" v-model="customField.name" :placeholder="$t('FieldName')" :invalid="invalid" @input="v.validate" /></template>
              </px-field>
            </validation-provider>

            <div class="pxcfg__grid">
              <validation-provider ref="typeProvider" name="Field Type" :rules="{ required: true }" v-slot="v">
                <px-field :label="$t('FieldType') + ' *'" :error="v.errors[0]">
                  <template #default="{ id }">
                    <vs-px :input-id="id" v-model="customField.field_type" :reduce="label => label.value" :options="fieldTypes"
                      :placeholder="$t('PleaseSelect')" @input="val => { onFieldTypeChange(); if ($refs.typeProvider) { $refs.typeProvider.syncValue(val); $refs.typeProvider.validate(); } }" />
                  </template>
                </px-field>
              </validation-provider>
              <px-field :label="$t('Required')">
                <template #default>
                  <px-check type="switch" :modelValue="!!customField.is_required" @change="v => customField.is_required = v">
                    {{ customField.is_required ? $t('Required') : $t('Optional') }}
                  </px-check>
                </template>
              </px-field>
            </div>

            <px-field v-if="customField.field_type === 'select'" :label="$t('SelectOptions')" :hint="$t('EnterOptionsOnePerLine')">
              <template #default="{ id }">
                <px-textarea :id="id" v-model="selectOptionsText" :placeholder="$t('EnterOptionsOnePerLine')" :rows="4" @blur="updateSelectOptions" />
              </template>
            </px-field>

            <px-field v-else-if="customField.field_type && customField.field_type !== 'select' && customField.field_type !== 'checkbox'" :label="$t('DefaultValue')">
              <template #default="{ id }">
                <px-textarea v-if="customField.field_type === 'textarea'" :id="id" v-model="customField.default_value" :placeholder="$t('DefaultValue')" :rows="3" />
                <px-input v-else :id="id" v-model="customField.default_value"
                  :type="customField.field_type === 'number' ? 'number' : (customField.field_type === 'date' ? 'date' : 'text')"
                  :placeholder="$t('DefaultValue')" />
              </template>
            </px-field>

            <div class="pxcfg__grid">
              <px-field :label="$t('SortOrder')">
                <template #default="{ id }"><px-input :id="id" type="number" v-model.number="customField.sort_order" :placeholder="$t('SortOrder')" min="0" /></template>
              </px-field>
            </div>
          </div>
        </form>
      </validation-observer>
      <template #footer="{ close }">
        <px-button variant="ghost" @click="() => { reset_Form(); close(); }">{{ $t('Cancel') }}</px-button>
        <px-button variant="primary" icon="check" :loading="SubmitProcessing" :disabled="SubmitProcessing" @click="Submit_CustomField">{{ $t('submit') }}</px-button>
      </template>
    </px-modal>
  </div>
</template>

<script>
import { mapGetters } from "vuex";
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
import PxEmptyState from "@/components/px-next/PxEmptyState.vue";
import VsPx from "@/views/app/products/next/edit/VsPx.vue";

export default {
  metaInfo: {
    title: "Custom Fields"
  },
  components: {
    PxPageHeader, PxTable, PxButton, PxModal, PxField, PxInput, PxTextarea, PxCheck, PxBadge, PxEmptyState, "vs-px": VsPx
  },
  data() {
    return {
      isLoading: true,
      SubmitProcessing: false,
      editmode: false,
      modalOpen: false,
      activeEntity: 'client',
      customerFields: [],
      supplierFields: [],
      customField: {
        id: "",
        name: "",
        field_type: "",
        entity_type: "",
        is_required: false,
        default_value: "",
        sort_order: 0,
      },
      selectOptionsText: "",
      fieldTypes: [
        { label: this.$t('Text'), value: 'text' },
        { label: this.$t('Number'), value: 'number' },
        { label: this.$t('Textarea'), value: 'textarea' },
        { label: this.$t('Date'), value: 'date' },
        { label: this.$t('Select'), value: 'select' },
        { label: this.$t('Checkbox'), value: 'checkbox' },
      ],
    };
  },
  computed: {
    ...mapGetters(["currentUser"]),
    columns() {
      return [
        { key: "name", label: this.$t("FieldName"), strong: true },
        { key: "field_type", label: this.$t("FieldType") },
        { key: "is_required", label: this.$t("Required"), align: "center" },
        { key: "sort_order", label: this.$t("SortOrder"), align: "center" }
      ];
    }
  },
  mounted() {
    this.Get_CustomFields();
  },
  methods: {
    Get_CustomFields() {
      NProgress.start();
      NProgress.set(0.1);

      axios
        .get("custom-fields?entity_type=client")
        .then(response => {
          this.customerFields = response.data.custom_fields;
        })
        .catch(error => {
          this.makeToast("danger", this.$t("InvalidData"), this.$t("Failed"));
        });

      axios
        .get("custom-fields?entity_type=provider")
        .then(response => {
          this.supplierFields = response.data.custom_fields;
          NProgress.done();
          this.isLoading = false;
        })
        .catch(error => {
          NProgress.done();
          this.isLoading = false;
          this.makeToast("danger", this.$t("InvalidData"), this.$t("Failed"));
        });
    },

    syncValidators() {
      this.$nextTick(() => {
        if (this.$refs.nameProvider && this.$refs.nameProvider.syncValue) this.$refs.nameProvider.syncValue(this.customField.name);
        if (this.$refs.typeProvider && this.$refs.typeProvider.syncValue) this.$refs.typeProvider.syncValue(this.customField.field_type);
      });
    },

    New_CustomField(entityType) {
      this.reset_Form();
      this.customField.entity_type = entityType;
      this.editmode = false;
      this.modalOpen = true;
      this.syncValidators();
    },

    Edit_CustomField(customField) {
      this.reset_Form();
      this.customField = {
        id: customField.id,
        name: customField.name,
        field_type: customField.field_type,
        entity_type: customField.entity_type,
        is_required: customField.is_required,
        default_value: customField.default_value || "",
        sort_order: customField.sort_order || 0,
      };

      if (customField.field_type === 'select' && customField.default_value) {
        const options = Array.isArray(customField.default_value)
          ? customField.default_value
          : JSON.parse(customField.default_value || '[]');
        this.selectOptionsText = options.join('\n');
      } else {
        this.selectOptionsText = "";
      }

      this.editmode = true;
      this.modalOpen = true;
      this.syncValidators();
    },

    Submit_CustomField() {
      this.$refs.Create_CustomField.validate().then(success => {
        if (!success) {
          this.makeToast(
            "danger",
            this.$t("Please_fill_the_form_correctly"),
            this.$t("Failed")
          );
          return;
        }

        this.SubmitProcessing = true;

        const payload = {
          name: this.customField.name,
          field_type: this.customField.field_type,
          entity_type: this.customField.entity_type,
          is_required: this.customField.is_required,
          default_value: this.customField.default_value || null,
          sort_order: this.customField.sort_order || 0,
        };

        if (this.customField.field_type === 'select') {
          const options = this.selectOptionsText
            .split('\n')
            .map(opt => opt.trim())
            .filter(opt => opt.length > 0);
          payload.default_value = JSON.stringify(options);
        }

        const url = this.editmode
          ? `custom-fields/${this.customField.id}`
          : "custom-fields";
        const method = this.editmode ? "put" : "post";

        axios[method](url, payload)
          .then(response => {
            this.makeToast(
              "success",
              this.editmode ? this.$t("Successfully_Updated") : this.$t("Successfully_Created"),
              this.$t("Success")
            );
            this.SubmitProcessing = false;
            this.modalOpen = false;
            this.Get_CustomFields();
          })
          .catch(error => {
            this.SubmitProcessing = false;
            this.makeToast("danger", this.$t("InvalidData"), this.$t("Failed"));
          });
      });
    },

    Delete_CustomField(id) {
      this.$swal({
        title: this.$t("DeleteTitle"),
        text: this.$t("DeleteMessage"),
        type: "warning",
        showCancelButton: true,
        confirmButtonColor: "var(--px-primary)",
        cancelButtonColor: "#d33",
        cancelButtonText: this.$t("Cancel"),
        confirmButtonText: this.$t("Delete")
      }).then(result => {
        if (result.value) {
          axios
            .delete("custom-fields/" + id)
            .then(response => {
              this.makeToast(
                "success",
                this.$t("Successfully_Deleted"),
                this.$t("Success")
              );
              this.Get_CustomFields();
            })
            .catch(error => {
              this.makeToast("danger", this.$t("InvalidData"), this.$t("Failed"));
            });
        }
      });
    },

    reset_Form() {
      this.customField = {
        id: "",
        name: "",
        field_type: "",
        entity_type: "",
        is_required: false,
        default_value: "",
        sort_order: 0,
      };
      this.selectOptionsText = "";
      this.editmode = false;
    },

    onFieldTypeChange() {
      if (this.customField.field_type !== 'select') {
        this.selectOptionsText = "";
      }
      if (this.customField.field_type === 'checkbox') {
        this.customField.default_value = "";
      }
    },

    updateSelectOptions() {
      // This is handled in Submit_CustomField
    },

    getFieldTypeLabel(type) {
      const field = this.fieldTypes.find(f => f.value === type);
      return field ? field.label : type;
    },

    getValidationState({ dirty, validated, valid = null }) {
      return dirty || validated ? valid : null;
    },

    makeToast(variant, msg, title) {
      this.$root.$bvToast.toast(msg, {
        title: title,
        variant: variant,
        solid: true
      });
    },
  }
};
</script>

<style lang="scss" src="@/assets/styles/sass/px-next/production.scss"></style>

<style lang="scss" scoped>
.pxcfg { min-height: 100%; background: var(--pxn-bg); padding: var(--pxn-space-8) var(--pxn-space-9) var(--pxn-space-9); }
@media (max-width: 620px) { .pxcfg { padding: var(--pxn-space-6) var(--pxn-space-5); } }
.pxcfg__pad { padding: var(--pxn-space-6) 0; }
.pxcfg__tabbar { display: flex; gap: var(--pxn-space-2); margin-top: var(--pxn-space-5); border-bottom: 1px solid var(--pxn-border); }
.pxcfg__tab { appearance: none; background: none; border: 0; border-bottom: 2px solid transparent; padding: var(--pxn-space-3) var(--pxn-space-4); font: inherit; font-size: var(--pxn-fs-sm); font-weight: var(--pxn-fw-medium); color: var(--pxn-ink-3); cursor: pointer; transition: color 120ms, border-color 120ms; }
.pxcfg__tab:hover { color: var(--pxn-ink); }
.pxcfg__tab.is-active { color: var(--pxn-ink); border-bottom-color: var(--pxn-primary); font-weight: var(--pxn-fw-semibold); }
.pxcfg__panel { margin-top: var(--pxn-space-4); }
.pxcfg__subhead { margin: 0 0 var(--pxn-space-3); font-size: var(--pxn-fs-sm); font-weight: var(--pxn-fw-semibold); }
.pxcfg__formgrid { display: grid; grid-template-columns: minmax(0, 1fr); gap: var(--pxn-space-4); }
.pxcfg__grid { display: grid; grid-template-columns: repeat(2, minmax(0, 1fr)); gap: var(--pxn-space-4) var(--pxn-space-5); }
@media (max-width: 560px) { .pxcfg__grid { grid-template-columns: minmax(0, 1fr); } }
.pxcfg__rowbtns { display: flex; gap: var(--pxn-space-2); justify-content: flex-end; }
.pxcfg__del ::v-deep .pxn-btn__icon { color: var(--pxn-danger); }
</style>
