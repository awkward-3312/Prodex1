<template>
  <div class="px-next pxsubcat">
    <px-page-header :title="$t('SubCategory')" :breadcrumbs="[{ label: $t('Products') }, { label: $t('SubCategory') }]">
      <template #actions>
        <px-button variant="primary" icon="plus" @click="openCreate">{{ $t('Add') }}</px-button>
      </template>
    </px-page-header>

    <px-toolbar
      :search="search"
      :search-placeholder="$t('Search_this_table')"
      @update:search="onSearchInput"
    />

    <transition name="pxsubcat-bulk">
      <div v-if="selectedIds.length" class="pxsubcat__bulk">
        <span><b class="pxn-num">{{ selectedIds.length }}</b> {{ $t('selected') }}</span>
        <div class="pxsubcat__bulk-actions">
          <px-button size="sm" variant="danger" icon="trash-2" @click="deleteBySelected">{{ $t('Del') }}</px-button>
          <px-button size="sm" variant="ghost" @click="selectedIds = []">{{ $t('Cancel') }}</px-button>
        </div>
      </div>
    </transition>

    <div v-if="isLoading" class="pxsubcat__pad">
      <px-skeleton variant="table" :rows="8" :columns="4" />
    </div>

    <template v-else>
      <div class="pxsubcat__tablewrap">
        <px-table
          :columns="columns"
          :rows="rows"
          row-key="id"
          selectable
          :selected.sync="selectedIds"
          :sort-key="serverParams.sort.field"
          :sort-dir="serverParams.sort.type"
          has-row-actions
          @sort="onSort"
        >
          <template #cell-status_label="{ row }">
            <px-badge :tone="row.status ? 'success' : 'neutral'">{{ row.status_label }}</px-badge>
          </template>
          <template #row-actions="{ row }">
            <px-kebab :items="rowActions" @select="onRowAction(row, $event)" />
          </template>
          <template #empty>
            <PxEmptyState icon="folder-tree" :title="$t('No_subcategories_yet')" :description="$t('No_subcategories_desc')">
              <px-button size="sm" variant="primary" icon="plus" @click="openCreate">{{ $t('Add') }}</px-button>
            </PxEmptyState>
          </template>
        </px-table>
      </div>

      <px-pagination
        v-if="rows.length"
        :page="serverParams.page"
        :per-page="Number(limit)"
        :total="Number(totalRows) || 0"
        @update:page="onPage"
        @update:perPage="onLimit"
      />
    </template>

    <!-- Create/Edit Modal -->
    <validation-observer ref="CategoryForm">
      <px-modal v-model="modalOpen" :title="editmode ? $t('Edit') : $t('Add')" size="md">
        <b-form @submit.prevent="submitCategory">
          <validation-provider ref="categoryProvider" name="Category" :rules="{ required: true }" v-slot="v">
            <px-field :label="$t('Categorie')" required :error="v.errors[0]">
              <template #default="{ id }">
                <px-select
                  :id="id"
                  v-model="form.category_id"
                  :options="categories.map(c => ({ label: c.name, value: c.id }))"
                  :placeholder="$t('Choose_Category')"
                  @input="v.validate"
                />
              </template>
            </px-field>
          </validation-provider>

          <validation-provider ref="nameProvider" name="Name subcategory" :rules="{ required: true }" v-slot="v">
            <px-field :label="$t('SubCategoryName')" required :error="v.errors[0]" class="pxsubcat__field-gap">
              <template #default="{ id }">
                <px-input :id="id" v-model="form.name" :placeholder="$t('Enter_name_category')" @input="v.validate" />
              </template>
            </px-field>
          </validation-provider>

          <px-field :label="$t('Description')" class="pxsubcat__field-gap">
            <template #default="{ id }">
              <b-form-textarea :id="id" v-model="form.description" :placeholder="$t('Afewwords')" rows="3" class="pxsubcat__textarea" />
            </template>
          </px-field>

          <label class="pxsubcat__switch">
            <px-check type="switch" v-model="form.status" />
            {{ form.status ? $t('Active') : $t('Inactive') }}
          </label>

          <div class="pxsubcat__actionbar">
            <px-button variant="secondary" type="button" @click="modalOpen = false">{{ $t('Cancel') }}</px-button>
            <px-button variant="primary" type="submit" icon="check" :loading="submitProcessing">{{ $t('submit') }}</px-button>
          </div>
        </b-form>
      </px-modal>
    </validation-observer>
  </div>
</template>

<script>
import NProgress from 'nprogress'
import PxEmptyState from "@/components/px-next/PxEmptyState.vue";
import PxPageHeader from "@/components/px-next/PxPageHeader.vue";
import PxToolbar from "@/components/px-next/PxToolbar.vue";
import PxTable from "@/components/px-next/PxTable.vue";
import PxPagination from "@/components/px-next/PxPagination.vue";
import PxButton from "@/components/px-next/PxButton.vue";
import PxKebab from "@/components/px-next/PxKebab.vue";
import PxBadge from "@/components/px-next/PxBadge.vue";
import PxField from "@/components/px-next/PxField.vue";
import PxInput from "@/components/px-next/PxInput.vue";
import PxSelect from "@/components/px-next/PxSelect.vue";
import PxCheck from "@/components/px-next/PxCheck.vue";
import PxModal from "@/components/px-next/PxModal.vue";

const API = 'subcategories'

export default {
  components: {
    PxEmptyState, PxPageHeader, PxToolbar, PxTable, PxPagination, PxButton,
    PxKebab, PxBadge, PxField, PxInput, PxSelect, PxCheck, PxModal
  },
  metaInfo: { title: 'Subcategorías' },

  data() {
    return {
      isLoading: true,
      submitProcessing: false,
      modalOpen: false,

      serverParams: {
        sort: { field: 'id', type: 'desc' },
        page: 1,
        perPage: 10
      },

      selectedIds: [],
      totalRows: 0,
      search: '',
      limit: '10',
      _searchTimer: null,

      rows: [],
      categories: [],
      editmode: false,

      form: { id: '', category_id: '', name: '', description: '', status: true },
    }
  },

  computed: {
    columns() {
      return [
        { key: 'category_name', label: this.$t('Categorie'), sortable: true },
        { key: 'name', label: this.$t('SubCategoryName'), sortable: true, strong: true },
        { key: 'status_label', label: this.$t('Status'), sortable: false }
      ]
    },
    rowActions() {
      return [
        { key: 'edit', label: this.$t('Edit'), icon: 'pencil' },
        { key: 'delete', label: this.$t('Delete'), icon: 'x', tone: 'danger' }
      ]
    }
  },

  methods: {
    getState({ dirty, validated, valid = null }) { return dirty || validated ? valid : null },
    toast(variant, msg, title) { this.$root.$bvToast.toast(msg, { title, variant, solid: true }) },
    updateParams(patch) { this.serverParams = { ...this.serverParams, ...patch } },

    onSearchInput(v) {
      this.search = v
      if (this._searchTimer) clearTimeout(this._searchTimer)
      this._searchTimer = setTimeout(() => {
        this.updateParams({ page: 1 })
        this.fetchRows()
      }, 350)
    },
    onSort({ key, dir }) {
      this.updateParams({ sort: { field: key, type: dir } })
      this.fetchRows()
    },
    onPage(p) {
      if (this.serverParams.page !== p) {
        this.updateParams({ page: p })
        this.fetchRows()
      }
    },
    onLimit(v) {
      if (this.limit !== String(v)) {
        this.limit = String(v)
        this.updateParams({ page: 1, perPage: Number(v) })
        this.fetchRows()
      }
    },
    onRowAction(row, item) {
      const k = item && item.key
      if (k === 'edit') this.openEdit(row)
      else if (k === 'delete') this.removeOne(row.id)
    },

    // CRUD
    openCreate() {
      this.resetForm()
      this.editmode = false
      this.modalOpen = true
      this.syncValidators()
    },

    // Vee-validate's automatic value detection can't see past a component's own
    // <slot> boundary (px-field wraps px-input/px-select), so a field's tracked
    // value never updates unless the user types into it. Seed each provider's
    // real current value here (silently — no rule is run, no error is shown) so
    // an untouched but valid/prefilled field doesn't block submit with a false
    // "required" error.
    syncValidators() {
      this.$nextTick(() => {
        if (this.$refs.categoryProvider) this.$refs.categoryProvider.syncValue(this.form.category_id)
        if (this.$refs.nameProvider) this.$refs.nameProvider.syncValue(this.form.name)
      })
    },

    async openEdit(row) {
      this.resetForm()
      this.editmode = true
      try {
        NProgress.start(); NProgress.set(0.1)
        const { data } = await axios.get(`${API}/${row.id}`)
        this.form = {
          id: data.id,
          category_id: data.category_id || '',
          name: data.name || '',
          description: data.description || '',
          status: !!data.status,
        }
      } catch (e) {
        this.toast('danger', this.$t('InvalidData'), this.$t('Failed'))
        return
      } finally {
        NProgress.done()
      }
      this.modalOpen = true
      this.syncValidators()
    },

    async fetchRows() {
      NProgress.start(); NProgress.set(0.1)
      this.isLoading = true
      const { page, perPage, sort } = this.serverParams
      try {
        const { data } = await axios.get(
          `${API}?page=${page}&SortField=${sort.field}&SortType=${sort.type}&search=${encodeURIComponent(this.search)}&limit=${this.limit}`
        )
        const subs = data.subcategories || []
        this.rows = subs.map(sc => ({
          id: sc.id,
          name: sc.name,
          category_id: sc.category_id,
          category_name: sc.category ? sc.category.name : '',
          status: !!sc.status,
          status_label: sc.status ? this.$t('Active') : this.$t('Inactive'),
        }))
        this.totalRows = data.totalRows || 0
      } catch (e) {
        this.toast('danger', this.$t('InvalidData'), this.$t('Failed'))
      } finally {
        this.isLoading = false
        NProgress.done()
      }
    },

    async submitCategory() {
      const valid = await this.$refs.CategoryForm.validate()
      if (!valid) return

      this.submitProcessing = true
      const payload = {
        category_id: this.form.category_id,
        name: this.form.name,
        description: this.form.description,
        status: this.form.status ? 1 : 0,
      }

      try {
        NProgress.start(); NProgress.set(0.1)
        if (this.editmode) {
          await axios.put(`${API}/${this.form.id}`, payload)
        } else {
          await axios.post(API, payload)
        }
        this.toast('success', this.$t('Successfully_Updated'), this.$t('Success'))
        this.modalOpen = false
        this.fetchRows()
      } catch (e) {
        this.toast('danger', this.$t('InvalidData'), this.$t('Failed'))
      } finally {
        this.submitProcessing = false
        NProgress.done()
      }
    },

    async removeOne(id) {
      const res = await this.$swal({
        title: this.$t('Delete_Title'),
        text: this.$t('Delete_Text'),
        type: 'warning',
        showCancelButton: true,
        confirmButtonColor: "var(--px-primary)",
        cancelButtonColor: '#d33',
        cancelButtonText: this.$t('Delete_cancelButtonText'),
        confirmButtonText: this.$t('Delete_confirmButtonText'),
      })
      if (!res.value) return

      try {
        NProgress.start(); NProgress.set(0.1)
        await axios.delete(`${API}/${id}`)
        await this.$swal(this.$t('Delete_Deleted'), this.$t('Deleted_in_successfully'), 'success')
        this.fetchRows()
      } catch (e) {
        this.$swal(this.$t('Delete_Failed'), this.$t('Delete_Therewassomethingwronge'), 'warning')
      } finally {
        NProgress.done()
      }
    },

    async deleteBySelected() {
      if (!this.selectedIds.length) return

      const res = await this.$swal({
        title: this.$t('Delete_Title'),
        text: this.$t('Delete_Text'),
        type: 'warning',
        showCancelButton: true,
        confirmButtonColor: "var(--px-primary)",
        cancelButtonColor: '#d33',
        cancelButtonText: this.$t('Delete_cancelButtonText'),
        confirmButtonText: this.$t('Delete_confirmButtonText'),
      })
      if (!res.value) return

      NProgress.start(); NProgress.set(0.1)
      try {
        await axios.post(`${API}/delete/by_selection`, { selectedIds: this.selectedIds })
        await this.$swal(this.$t('Delete_Deleted'), this.$t('Deleted_in_successfully'), 'success')
        this.selectedIds = []
        this.fetchRows()
      } catch (e) {
        this.$swal(this.$t('Delete_Failed'), this.$t('Delete_Therewassomethingwronge'), 'warning')
      } finally {
        NProgress.done()
      }
    },

    resetForm() {
      this.form = { id: '', category_id: '', name: '', description: '', status: true }
      if (this.$refs.CategoryForm) {
        this.$refs.CategoryForm.reset()
      }
    },
  },

  created() {
    // Load all parent categories for the select
    axios.get('categories', { params: { limit: -1 } })
      .then(({ data }) => {
        this.categories = data.categories || []
      })
      .catch(() => {})

    this.fetchRows()
  },
}
</script>

<style lang="scss" src="@/assets/styles/sass/px-next/production.scss"></style>

<style lang="scss" scoped>
.pxsubcat { min-height: 100%; background: var(--pxn-bg); padding: var(--pxn-space-8) var(--pxn-space-9) var(--pxn-space-9); }
@media (max-width: 620px) { .pxsubcat { padding: var(--pxn-space-6) var(--pxn-space-5); } }
.pxsubcat__pad { padding: var(--pxn-space-6) 0; }

.pxsubcat__bulk {
  display: flex; align-items: center; justify-content: space-between; gap: var(--pxn-space-5);
  margin-top: var(--pxn-space-4);
  padding: var(--pxn-space-4) var(--pxn-space-5);
  background: var(--pxn-primary-soft);
  border: 1px solid var(--pxn-primary-border);
  border-radius: var(--pxn-radius-md);
  font-size: var(--pxn-fs-sm); color: var(--pxn-primary-ink);
}
.pxsubcat__bulk-actions { display: flex; gap: var(--pxn-space-3); }
.pxsubcat-bulk-enter-active, .pxsubcat-bulk-leave-active { transition: opacity var(--pxn-dur-2) var(--pxn-ease), transform var(--pxn-dur-2) var(--pxn-ease); }
.pxsubcat-bulk-enter, .pxsubcat-bulk-leave-to { opacity: 0; transform: translateY(-6px); }

.pxsubcat__tablewrap { margin-top: var(--pxn-space-5); }
.pxsubcat__field-gap { margin-top: var(--pxn-space-5); }
.pxsubcat__textarea {
  width: 100%; border: 1px solid var(--pxn-border-control); border-radius: var(--pxn-radius-md);
  background: var(--pxn-surface); color: var(--pxn-ink); font: inherit; font-size: var(--pxn-fs-body);
  padding: var(--pxn-space-4) var(--pxn-space-5);
}
.pxsubcat__switch { display: flex; align-items: center; gap: var(--pxn-space-3); margin-top: var(--pxn-space-6); font-size: var(--pxn-fs-body); color: var(--pxn-ink-2); }
.pxsubcat__actionbar { display: flex; justify-content: flex-end; gap: var(--pxn-space-3); margin-top: var(--pxn-space-7); }
</style>
