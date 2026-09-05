<template>
  <div class="px-next pxunit">
    <px-page-header :title="$t('Units')" :breadcrumbs="[{ label: $t('Products') }, { label: $t('Units') }]">
      <template #actions>
        <px-button variant="primary" icon="plus" @click="New_Unit">{{ $t('Add') }}</px-button>
      </template>
    </px-page-header>

    <px-toolbar
      :search="search"
      :search-placeholder="$t('Search_this_table')"
      @update:search="onSearchInput"
    />

    <div v-if="isLoading" class="pxunit__pad">
      <px-skeleton variant="table" :rows="8" :columns="5" />
    </div>

    <template v-else>
      <div class="pxunit__tablewrap">
        <px-table
          :columns="columns"
          :rows="units"
          row-key="id"
          :sort-key="serverParams.sort.field"
          :sort-dir="serverParams.sort.type"
          has-row-actions
          @sort="onSort"
        >
          <template #cell-base_unit_name="{ row }">
            <span v-if="row.base_unit_name != ''">{{ row.base_unit_name }}</span>
            <span v-else class="pxunit__muted">N/D</span>
          </template>
          <template #row-actions="{ row }">
            <px-kebab :items="rowActions" @select="onRowAction(row, $event)" />
          </template>
          <template #empty>
            <PxEmptyState icon="ruler" :title="$t('No_units_yet')" :description="$t('No_units_desc')">
              <px-button size="sm" variant="primary" icon="plus" @click="New_Unit">{{ $t('Add') }}</px-button>
            </PxEmptyState>
          </template>
        </px-table>
      </div>

      <px-pagination
        v-if="units.length"
        :page="serverParams.page"
        :per-page="Number(limit)"
        :total="Number(totalRows) || 0"
        @update:page="onPage"
        @update:perPage="onLimit"
      />
    </template>

    <validation-observer ref="Create_Unit">
      <px-modal v-model="modalOpen" :title="editmode ? $t('Edit') : $t('Add')" size="md">
        <b-form @submit.prevent="Submit_Unit">
          <validation-provider ref="nameProvider" name="Code Currency" :rules="{ required: true, max: 15 }" v-slot="v">
            <px-field :label="$t('Name')" required :error="v.errors[0]">
              <template #default="{ id }">
                <px-input :id="id" v-model="unit.name" :placeholder="$t('Enter_Name_Unit')" @input="v.validate" />
              </template>
            </px-field>
          </validation-provider>

          <validation-provider ref="shortNameProvider" name="ShortName" :rules="{ required: true, max: 15 }" v-slot="v">
            <px-field :label="$t('ShortName')" required :error="v.errors[0]" class="pxunit__field-gap">
              <template #default="{ id }">
                <px-input :id="id" v-model="unit.ShortName" :placeholder="$t('Enter_ShortName_Unit')" @input="v.validate" />
              </template>
            </px-field>
          </validation-provider>

          <px-field :label="$t('BaseUnit')" class="pxunit__field-gap">
            <template #default="{ id }">
              <px-select
                :id="id"
                v-model="unit.base_unit"
                :options="units_base.map(u => ({ label: u.name, value: u.id }))"
                :placeholder="$t('Choose_Base_Unit')"
                @input="Selected_Base_Unit"
              />
            </template>
          </px-field>

          <px-field v-show="show_operator" :label="$t('Operator')" class="pxunit__field-gap">
            <template #default="{ id }">
              <px-select
                :id="id"
                v-model="unit.operator"
                :options="[{ label: 'Multiplicar (*)', value: '*' }, { label: 'Dividir (/)', value: '/' }]"
                :placeholder="$t('Choose_Operator')"
              />
            </template>
          </px-field>

          <validation-provider ref="operatorValueProvider" v-show="show_operator" name="Operation Value" :rules="{ required: true, regex: /^\d*\.?\d*$/ }" v-slot="v">
            <px-field :label="$t('OperationValue')" required :error="v.errors[0]" class="pxunit__field-gap">
              <template #default="{ id }">
                <px-input :id="id" v-model="unit.operator_value" :placeholder="$t('Enter_Operation_Value')" @input="v.validate" />
              </template>
            </px-field>
          </validation-provider>

          <div class="pxunit__actionbar">
            <px-button variant="secondary" type="button" @click="modalOpen = false">{{ $t('Cancel') }}</px-button>
            <px-button variant="primary" type="submit" icon="check" :loading="SubmitProcessing">{{ $t('submit') }}</px-button>
          </div>
        </b-form>
      </px-modal>
    </validation-observer>
  </div>
</template>


<script>
import NProgress from "nprogress";
import PxEmptyState from "@/components/px-next/PxEmptyState.vue";
import PxPageHeader from "@/components/px-next/PxPageHeader.vue";
import PxToolbar from "@/components/px-next/PxToolbar.vue";
import PxTable from "@/components/px-next/PxTable.vue";
import PxPagination from "@/components/px-next/PxPagination.vue";
import PxButton from "@/components/px-next/PxButton.vue";
import PxKebab from "@/components/px-next/PxKebab.vue";
import PxField from "@/components/px-next/PxField.vue";
import PxInput from "@/components/px-next/PxInput.vue";
import PxSelect from "@/components/px-next/PxSelect.vue";
import PxModal from "@/components/px-next/PxModal.vue";

export default {
  components: { PxEmptyState, PxPageHeader, PxToolbar, PxTable, PxPagination, PxButton, PxKebab, PxField, PxInput, PxSelect, PxModal },
  metaInfo: {
    title: "Unidades"
  },
  data() {
    return {
      isLoading: true,
      SubmitProcessing: false,
      modalOpen: false,
      serverParams: {
        columnFilters: {},
        sort: {
          field: "id",
          type: "desc"
        },
        page: 1,
        perPage: 10
      },
      totalRows: "",
      search: "",
      _searchTimer: null,
      limit: "10",
      units: [],
      units_base: [],
      editmode: false,
      show_operator: false,
      unit: {
        id: "",
        name: "",
        ShortName: "",
        base_unit: "",
        base_unit_name: "",
        operator: "*",
        operator_value: 1
      }
    };
  },

  computed: {
    columns() {
      return [
        { key: "name", label: this.$t("Name"), sortable: true, strong: true },
        { key: "ShortName", label: this.$t("ShortName"), sortable: true },
        { key: "base_unit_name", label: this.$t("BaseUnit"), sortable: false },
        { key: "operator", label: this.$t("Operator"), sortable: false },
        { key: "operator_value", label: this.$t("OperationValue"), sortable: false, align: "right" }
      ];
    },
    rowActions() {
      return [
        { key: "edit", label: this.$t("Edit"), icon: "pencil" },
        { key: "delete", label: this.$t("Delete"), icon: "x", tone: "danger" }
      ];
    }
  },

  methods: {
    updateParams(newProps) {
      this.serverParams = Object.assign({}, this.serverParams, newProps);
    },
    onSearchInput(v) {
      this.search = v;
      if (this._searchTimer) clearTimeout(this._searchTimer);
      this._searchTimer = setTimeout(() => {
        this.updateParams({ page: 1 });
        this.Get_Units(1);
      }, 350);
    },
    onSort({ key, dir }) {
      this.updateParams({ sort: { field: key, type: dir } });
      this.Get_Units(this.serverParams.page);
    },
    onPage(p) {
      if (this.serverParams.page !== p) {
        this.updateParams({ page: p });
        this.Get_Units(p);
      }
    },
    onLimit(v) {
      if (this.limit !== String(v)) {
        this.limit = String(v);
        this.updateParams({ page: 1, perPage: Number(v) });
        this.Get_Units(1);
      }
    },
    onRowAction(row, item) {
      const k = item && item.key;
      if (k === "edit") this.Edit_Unit(row);
      else if (k === "delete") this.Remove_Unit(row.id);
    },

    getValidationState({ dirty, validated, valid = null }) {
      return dirty || validated ? valid : null;
    },

    Submit_Unit() {
      this.$refs.Create_Unit.validate().then(success => {
        if (!success) {
          this.makeToast("danger", this.$t("Please_fill_the_form_correctly"), this.$t("Failed"));
        } else {
          if (!this.editmode) {
            this.Create_Unit();
          } else {
            this.Update_Unit();
          }
        }
      });
    },

    makeToast(variant, msg, title) {
      this.$root.$bvToast.toast(msg, { title: title, variant: variant, solid: true });
    },

    New_Unit() {
      this.reset_Form();
      this.show_operator = false;
      this.editmode = false;
      this.modalOpen = true;
      this.syncValidators();
    },

    Edit_Unit(unit) {
      this.reset_Form();
      this.unit = { ...unit };
      this.show_operator = this.unit.base_unit != "" && this.unit.base_unit != null;
      this.editmode = true;
      this.modalOpen = true;
      this.syncValidators();
    },

    Selected_Base_Unit(value) {
      this.show_operator = value != null && value !== "";
      this.syncValidators();
    },

    // Vee-validate's automatic value detection can't see past a component's own
    // <slot> boundary (px-field wraps px-input), so a field's tracked value never
    // updates unless the user types into it. Seed each provider's real current
    // value here (silently — no rule is run, no error is shown) so an untouched
    // but valid/prefilled field (e.g. the default operator_value) doesn't block
    // submit with a false validation error.
    syncValidators() {
      this.$nextTick(() => {
        if (this.$refs.nameProvider) this.$refs.nameProvider.syncValue(this.unit.name);
        if (this.$refs.shortNameProvider) this.$refs.shortNameProvider.syncValue(this.unit.ShortName);
        if (this.$refs.operatorValueProvider) this.$refs.operatorValueProvider.syncValue(this.unit.operator_value);
      });
    },

    Get_Units(page) {
      NProgress.start();
      NProgress.set(0.1);
      axios
        .get(
          "units?page=" +
            page +
            "&SortField=" +
            this.serverParams.sort.field +
            "&SortType=" +
            this.serverParams.sort.type +
            "&search=" +
            this.search +
            "&limit=" +
            this.limit
        )
        .then(response => {
          this.units = response.data.Units;
          this.totalRows = response.data.totalRows;
          this.units_base = response.data.Units_base;
          NProgress.done();
          this.isLoading = false;
        })
        .catch(response => {
          NProgress.done();
          setTimeout(() => {
            this.isLoading = false;
          }, 500);
        });
    },

    setToStrings() {
      if (this.unit.base_unit === null) {
        this.unit.base_unit = "";
      }
    },
    Create_Unit() {
      this.SubmitProcessing = true;
      this.setToStrings();
      axios
        .post("units", {
          name: this.unit.name,
          ShortName: this.unit.ShortName,
          base_unit: this.unit.base_unit,
          operator: this.unit.operator,
          operator_value: this.unit.operator_value
        })
        .then(response => {
          this.SubmitProcessing = false;
          Fire.$emit("Event_Unit");
          this.makeToast("success", this.$t("Successfully_Created"), this.$t("Success"));
        })
        .catch(error => {
          this.SubmitProcessing = false;
          this.makeToast("danger", this.$t("InvalidData"), this.$t("Failed"));
        });
    },

    Update_Unit() {
      this.SubmitProcessing = true;
      this.setToStrings();
      axios
        .put("units/" + this.unit.id, {
          name: this.unit.name,
          ShortName: this.unit.ShortName,
          base_unit: this.unit.base_unit,
          operator: this.unit.operator,
          operator_value: this.unit.operator_value
        })
        .then(response => {
          this.SubmitProcessing = false;
          Fire.$emit("Event_Unit");
          this.makeToast("success", this.$t("Successfully_Updated"), this.$t("Success"));
        })
        .catch(error => {
          this.SubmitProcessing = false;
          this.makeToast("danger", this.$t("InvalidData"), this.$t("Failed"));
        });
    },

    reset_Form() {
      this.unit = {
        id: "",
        name: "",
        ShortName: "",
        base_unit: "",
        base_unit_name: "",
        operator: "*",
        operator_value: 1
      };
    },

    Remove_Unit(id) {
      this.$swal({
        title: this.$t("Delete_Title"),
        text: this.$t("Delete_Text"),
        type: "warning",
        showCancelButton: true,
        confirmButtonColor: "var(--px-primary)",
        cancelButtonColor: "#d33",
        cancelButtonText: this.$t("Delete_cancelButtonText"),
        confirmButtonText: this.$t("Delete_confirmButtonText")
      }).then(result => {
        if (result.value) {
          axios
            .delete("units/" + id)
            .then(response => {
              if (response.data.success) {
                this.$swal(this.$t("Delete_Deleted"), this.$t("Deleted_in_successfully"), "success");
              } else {
                this.$swal(this.$t("Delete_Failed"), this.$t("Unit_already_linked_with_sub_unit"), "warning");
              }
              Fire.$emit("Delete_Unit");
            })
            .catch(() => {
              this.$swal(this.$t("Delete_Failed"), this.$t("Delete_Therewassomethingwronge"), "warning");
            });
        }
      });
    }
  },

  created: function () {
    this.Get_Units(1);

    Fire.$on("Event_Unit", () => {
      setTimeout(() => {
        this.Get_Units(this.serverParams.page);
        this.modalOpen = false;
      }, 500);
    });

    Fire.$on("Delete_Unit", () => {
      setTimeout(() => {
        this.Get_Units(this.serverParams.page);
      }, 500);
    });
  }
};
</script>

<style lang="scss" src="@/assets/styles/sass/px-next/production.scss"></style>

<style lang="scss" scoped>
.pxunit { min-height: 100%; background: var(--pxn-bg); padding: var(--pxn-space-8) var(--pxn-space-9) var(--pxn-space-9); }
@media (max-width: 620px) { .pxunit { padding: var(--pxn-space-6) var(--pxn-space-5); } }
.pxunit__pad { padding: var(--pxn-space-6) 0; }
.pxunit__muted { color: var(--pxn-ink-3); }
.pxunit__tablewrap { margin-top: var(--pxn-space-5); }
.pxunit__field-gap { margin-top: var(--pxn-space-5); }
.pxunit__actionbar { display: flex; justify-content: flex-end; gap: var(--pxn-space-3); margin-top: var(--pxn-space-7); }
</style>
