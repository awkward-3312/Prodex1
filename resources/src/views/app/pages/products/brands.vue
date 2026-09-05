<template>
  <div class="px-next pxbrand">
    <px-page-header :title="$t('Brand')" :breadcrumbs="[{ label: $t('Products') }, { label: $t('Brand') }]">
      <template #actions>
        <px-button variant="primary" icon="plus" @click="New_Brand">{{ $t('Add') }}</px-button>
      </template>
    </px-page-header>

    <px-toolbar
      :search="search"
      :search-placeholder="$t('Search_this_table')"
      @update:search="onSearchInput"
    />

    <transition name="pxbrand-bulk">
      <div v-if="selectedIds.length" class="pxbrand__bulk">
        <span><b class="pxn-num">{{ selectedIds.length }}</b> {{ $t('selected') }}</span>
        <div class="pxbrand__bulk-actions">
          <px-button size="sm" variant="danger" icon="trash-2" @click="delete_by_selected">{{ $t('Del') }}</px-button>
          <px-button size="sm" variant="ghost" @click="selectedIds = []">{{ $t('Cancel') }}</px-button>
        </div>
      </div>
    </transition>

    <div v-if="isLoading" class="pxbrand__pad">
      <px-skeleton variant="table" :rows="8" :columns="4" />
    </div>

    <template v-else>
      <div class="pxbrand__tablewrap">
        <px-table
          :columns="columns"
          :rows="brands"
          row-key="id"
          selectable
          :selected.sync="selectedIds"
          :sort-key="serverParams.sort.field"
          :sort-dir="serverParams.sort.type"
          has-row-actions
          @sort="onSort"
        >
          <template #cell-image="{ row }">
            <img v-if="row.image" :src="$imgUrl('brands', row.image)" alt="" class="pxbrand__thumb" />
            <span v-else class="pxbrand__muted">—</span>
          </template>
          <template #row-actions="{ row }">
            <px-kebab :items="rowActions" @select="onRowAction(row, $event)" />
          </template>
          <template #empty>
            <PxEmptyState icon="award" :title="$t('No_brands_yet')" :description="$t('No_brands_desc')">
              <px-button size="sm" variant="primary" icon="plus" @click="New_Brand">{{ $t('Add') }}</px-button>
            </PxEmptyState>
          </template>
        </px-table>
      </div>

      <px-pagination
        v-if="brands.length"
        :page="serverParams.page"
        :per-page="Number(limit)"
        :total="Number(totalRows) || 0"
        @update:page="onPage"
        @update:perPage="onLimit"
      />
    </template>

    <validation-observer ref="Create_brand">
      <px-modal v-model="modalOpen" :title="editmode ? $t('Edit') : $t('Add')" size="md">
        <b-form @submit.prevent="Submit_Brand" enctype="multipart/form-data">
          <validation-provider ref="nameProvider" name="Brand Name" :rules="{ required: true, min: 3, max: 20 }" v-slot="v">
            <px-field :label="$t('BrandName')" required :error="v.errors[0]">
              <template #default="{ id }">
                <px-input :id="id" v-model="brand.name" :placeholder="$t('Enter_Name_Brand')" @input="v.validate" />
              </template>
            </px-field>
          </validation-provider>

          <validation-provider ref="descriptionProvider" name="Brand Description" :rules="{ max: 30 }" v-slot="v">
            <px-field :label="$t('BrandDescription')" :error="v.errors[0]" class="pxbrand__field-gap">
              <template #default="{ id }">
                <b-form-textarea :id="id" rows="3" :placeholder="$t('Enter_Description_Brand')" v-model="brand.description" class="pxbrand__textarea" @input="v.validate" />
              </template>
            </px-field>
          </validation-provider>

          <validation-provider name="Image" ref="Image" rules="mimes:image/*|size:200" v-slot="v">
            <px-field :label="$t('BrandImage')" :error="v.errors[0]" class="pxbrand__field-gap">
              <template #default="{ id }">
                <input :id="id" class="pxbrand__file" type="file" @change="onFileSelected" />
              </template>
            </px-field>
          </validation-provider>

          <div class="pxbrand__actionbar">
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
import PxModal from "@/components/px-next/PxModal.vue";

export default {
  components: { PxEmptyState, PxPageHeader, PxToolbar, PxTable, PxPagination, PxButton, PxKebab, PxField, PxInput, PxModal },
  metaInfo: {
    title: "Marcas"
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
      selectedIds: [],
      totalRows: "",
      search: "",
      _searchTimer: null,
      data: new FormData(),
      editmode: false,
      brands: [],
      limit: "10",
      brand: {
        id: "",
        name: "",
        description: "",
        image: ""
      }
    };
  },
  computed: {
    columns() {
      return [
        { key: "image", label: this.$t("BrandImage"), sortable: false, width: "80px" },
        { key: "name", label: this.$t("BrandName"), sortable: true, strong: true },
        { key: "description", label: this.$t("BrandDescription"), sortable: true }
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
        this.Get_Brands(1);
      }, 350);
    },
    onSort({ key, dir }) {
      this.updateParams({ sort: { field: key, type: dir } });
      this.Get_Brands(this.serverParams.page);
    },
    onPage(p) {
      if (this.serverParams.page !== p) {
        this.updateParams({ page: p });
        this.Get_Brands(p);
      }
    },
    onLimit(v) {
      if (this.limit !== String(v)) {
        this.limit = String(v);
        this.updateParams({ page: 1, perPage: Number(v) });
        this.Get_Brands(1);
      }
    },
    onRowAction(row, item) {
      const k = item && item.key;
      if (k === "edit") this.Edit_Brand(row);
      else if (k === "delete") this.Delete_Brand(row.id);
    },

    getValidationState({ dirty, validated, valid = null }) {
      return dirty || validated ? valid : null;
    },

    Submit_Brand() {
      this.$refs.Create_brand.validate().then(success => {
        if (!success) {
          this.makeToast("danger", this.$t("Please_fill_the_form_correctly"), this.$t("Failed"));
        } else {
          if (!this.editmode) {
            this.Create_Brand();
          } else {
            this.Update_Brand();
          }
        }
      });
    },

    makeToast(variant, msg, title) {
      this.$root.$bvToast.toast(msg, { title: title, variant: variant, solid: true });
    },

    async onFileSelected(e) {
      const { valid } = await this.$refs.Image.validate(e);
      if (valid) {
        this.brand.image = e.target.files[0];
      } else {
        this.brand.image = "";
      }
    },

    New_Brand() {
      this.reset_Form();
      this.editmode = false;
      this.modalOpen = true;
      this.syncValidators();
    },

    Edit_Brand(brand) {
      this.reset_Form();
      this.brand = { ...brand };
      this.editmode = true;
      this.modalOpen = true;
      this.syncValidators();
    },

    // Vee-validate's automatic value detection can't see past a component's own
    // <slot> boundary (px-field wraps px-input), so a field's tracked value never
    // updates unless the user types into it. Seed each provider's real current
    // value here (silently — no rule is run, no error is shown) so an untouched
    // but valid/prefilled field doesn't block submit with a false validation error.
    syncValidators() {
      this.$nextTick(() => {
        if (this.$refs.nameProvider) this.$refs.nameProvider.syncValue(this.brand.name);
        if (this.$refs.descriptionProvider) this.$refs.descriptionProvider.syncValue(this.brand.description);
      });
    },

    Get_Brands(page) {
      NProgress.start();
      NProgress.set(0.1);
      axios
        .get(
          "brands?page=" +
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
          this.brands = response.data.brands;
          this.totalRows = response.data.totalRows;
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

    Create_Brand() {
      var self = this;
      self.SubmitProcessing = true;
      self.data.append("name", self.brand.name);
      self.data.append("description", self.brand.description);
      self.data.append("image", self.brand.image);
      axios
        .post("brands", self.data)
        .then(response => {
          self.SubmitProcessing = false;
          Fire.$emit("Event_Brand");
          this.makeToast("success", this.$t("Successfully_Created"), this.$t("Success"));
        })
        .catch(error => {
          self.SubmitProcessing = false;
          this.makeToast("danger", this.$t("InvalidData"), this.$t("Failed"));
        });
    },

    Update_Brand() {
      var self = this;
      self.SubmitProcessing = true;
      self.data.append("name", self.brand.name);
      self.data.append("description", self.brand.description);
      self.data.append("image", self.brand.image);
      self.data.append("_method", "put");

      axios
        .post("brands/" + self.brand.id, self.data)
        .then(response => {
          self.SubmitProcessing = false;
          Fire.$emit("Event_Brand");
          this.makeToast("success", this.$t("Successfully_Updated"), this.$t("Success"));
        })
        .catch(error => {
          self.SubmitProcessing = false;
          this.makeToast("danger", this.$t("InvalidData"), this.$t("Failed"));
        });
    },

    reset_Form() {
      this.brand = {
        id: "",
        name: "",
        description: "",
        image: ""
      };
      this.data = new FormData();
    },

    Delete_Brand(id) {
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
            .delete("brands/" + id)
            .then(() => {
              this.$swal(this.$t("Delete_Deleted"), this.$t("Deleted_in_successfully"), "success");
              Fire.$emit("Delete_Brand");
            })
            .catch(() => {
              this.$swal(this.$t("Delete_Failed"), this.$t("Delete_Therewassomethingwronge"), "warning");
            });
        }
      });
    },

    delete_by_selected() {
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
          NProgress.start();
          NProgress.set(0.1);
          axios
            .post("brands/delete/by_selection", { selectedIds: this.selectedIds })
            .then(() => {
              this.$swal(this.$t("Delete_Deleted"), this.$t("Deleted_in_successfully"), "success");
              this.selectedIds = [];
              Fire.$emit("Delete_Brand");
            })
            .catch(() => {
              setTimeout(() => NProgress.done(), 500);
              this.$swal(this.$t("Delete_Failed"), this.$t("Delete_Therewassomethingwronge"), "warning");
            });
        }
      });
    }
  },
  created: function () {
    this.Get_Brands(1);

    Fire.$on("Event_Brand", () => {
      setTimeout(() => {
        this.Get_Brands(this.serverParams.page);
        this.modalOpen = false;
      }, 500);
    });

    Fire.$on("Delete_Brand", () => {
      setTimeout(() => {
        this.Get_Brands(this.serverParams.page);
      }, 500);
    });
  }
};
</script>

<style lang="scss" src="@/assets/styles/sass/px-next/production.scss"></style>

<style lang="scss" scoped>
.pxbrand { min-height: 100%; background: var(--pxn-bg); padding: var(--pxn-space-8) var(--pxn-space-9) var(--pxn-space-9); }
@media (max-width: 620px) { .pxbrand { padding: var(--pxn-space-6) var(--pxn-space-5); } }
.pxbrand__pad { padding: var(--pxn-space-6) 0; }
.pxbrand__muted { color: var(--pxn-ink-3); }
.pxbrand__thumb { width: 40px; height: 40px; object-fit: cover; border-radius: var(--pxn-radius-sm); border: 1px solid var(--pxn-border); }

.pxbrand__bulk {
  display: flex; align-items: center; justify-content: space-between; gap: var(--pxn-space-5);
  margin-top: var(--pxn-space-4);
  padding: var(--pxn-space-4) var(--pxn-space-5);
  background: var(--pxn-primary-soft);
  border: 1px solid var(--pxn-primary-border);
  border-radius: var(--pxn-radius-md);
  font-size: var(--pxn-fs-sm); color: var(--pxn-primary-ink);
}
.pxbrand__bulk-actions { display: flex; gap: var(--pxn-space-3); }
.pxbrand-bulk-enter-active, .pxbrand-bulk-leave-active { transition: opacity var(--pxn-dur-2) var(--pxn-ease), transform var(--pxn-dur-2) var(--pxn-ease); }
.pxbrand-bulk-enter, .pxbrand-bulk-leave-to { opacity: 0; transform: translateY(-6px); }

.pxbrand__tablewrap { margin-top: var(--pxn-space-5); }
.pxbrand__field-gap { margin-top: var(--pxn-space-5); }
.pxbrand__textarea {
  width: 100%; border: 1px solid var(--pxn-border-control); border-radius: var(--pxn-radius-md);
  background: var(--pxn-surface); color: var(--pxn-ink); font: inherit; font-size: var(--pxn-fs-body);
  padding: var(--pxn-space-4) var(--pxn-space-5);
}
.pxbrand__file { font-size: var(--pxn-fs-sm); color: var(--pxn-ink-2); }
.pxbrand__actionbar { display: flex; justify-content: flex-end; gap: var(--pxn-space-3); margin-top: var(--pxn-space-7); }
</style>
