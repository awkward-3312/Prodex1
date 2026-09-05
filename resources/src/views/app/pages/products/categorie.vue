<template>
  <div class="px-next pxcat">
    <px-page-header :title="$t('Categories')" :breadcrumbs="[{ label: $t('Products') }, { label: $t('Categories') }]">
      <template #actions>
        <px-button variant="primary" icon="plus" @click="openCreate">{{ $t('Add') }}</px-button>
      </template>
    </px-page-header>

    <px-toolbar
      :search="search"
      :search-placeholder="$t('Search_this_table')"
      @update:search="onSearchInput"
    />

    <transition name="pxcat-bulk">
      <div v-if="selectedIds.length" class="pxcat__bulk">
        <span><b class="pxn-num">{{ selectedIds.length }}</b> {{ $t('selected') }}</span>
        <div class="pxcat__bulk-actions">
          <px-button size="sm" variant="danger" icon="trash-2" @click="deleteBySelected">{{ $t('Del') }}</px-button>
          <px-button size="sm" variant="ghost" @click="selectedIds = []">{{ $t('Cancel') }}</px-button>
        </div>
      </div>
    </transition>

    <div v-if="isLoading" class="pxcat__pad">
      <px-skeleton variant="table" :rows="8" :columns="5" />
    </div>

    <template v-else>
      <div class="pxcat__tablewrap">
        <px-table
          :columns="columns"
          :rows="categories"
          row-key="id"
          selectable
          :selected.sync="selectedIds"
          :sort-key="serverParams.sort.field"
          :sort-dir="serverParams.sort.type"
          has-row-actions
          @sort="onSort"
        >
          <template #cell-icon="{ row }">
            <i v-if="row.icon" :class="row.icon" class="text-20"></i>
            <span v-else class="pxcat__muted">—</span>
          </template>
          <template #cell-show_in_store="{ row }">
            <px-badge :tone="row.show_in_store ? 'success' : 'neutral'">
              {{ row.show_in_store ? $t('Yes') : $t('No') }}
            </px-badge>
          </template>
          <template #row-actions="{ row }">
            <px-kebab :items="rowActions" @select="onRowAction(row, $event)" />
          </template>
          <template #empty>
            <PxEmptyState
              icon="folder-tree"
              :title="$t('No_categories_yet')"
              :description="$t('No_categories_desc')"
            >
              <px-button size="sm" variant="primary" icon="plus" @click="openCreate">{{ $t('Add') }}</px-button>
            </PxEmptyState>
          </template>
        </px-table>
      </div>

      <px-pagination
        v-if="categories.length"
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
          <validation-provider name="Code category" :rules="{ required: true }" v-slot="v">
            <px-field :label="$t('Codecategorie')" required :error="v.errors[0]">
              <template #default="{ id }">
                <px-input :id="id" v-model="category.code" :placeholder="$t('Enter_Code_category')" />
              </template>
            </px-field>
          </validation-provider>

          <validation-provider name="Name category" :rules="{ required: true }" v-slot="v">
            <px-field :label="$t('Namecategorie')" required :error="v.errors[0]" class="pxcat__field-gap">
              <template #default="{ id }">
                <px-input :id="id" v-model="category.name" :placeholder="$t('Enter_name_category')" />
              </template>
            </px-field>
          </validation-provider>

          <px-field :label="$t('Icon')" class="pxcat__field-gap">
            <template #default="{ id }">
              <div class="pxcat__iconrow">
                <px-select :id="id" v-model="category.icon" :options="iconOptions" placeholder="—" />
                <i v-if="category.icon" :class="category.icon" class="pxcat__iconpreview"></i>
              </div>
            </template>
          </px-field>

          <label class="pxcat__switch">
            <px-check type="switch" v-model="category.show_in_store" />
            {{ $t('Visible_in_online_store') }}
          </label>

          <div class="pxcat__actionbar">
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
import 'bootstrap-icons/font/bootstrap-icons.css'
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

const API = 'categories' // base endpoint

// Curated Bootstrap Icons (add/remove as you like)
const biNames = [
  'bag','basket','cart','shop','shop-window','tag','ticket-perforated',
  'cash-coin','credit-card','qr-code','barcode',
  'box','boxes','box-seam','truck','truck-flatbed','airplane',
  'house','house-door','geo-alt','map','compass','pin-map','pin',
  'alarm','calendar','clock','hourglass','stopwatch',
  'funnel','sliders','filter','sort-alpha-down','sort-alpha-up','search','zoom-in','zoom-out',
  'upload','download','cloud-upload','cloud-download','link-45deg','unlock','lock',
  'shield','shield-check','flag','info-circle','question-circle','exclamation-circle',
  'star','heart','hand-thumbs-up','hand-thumbs-down','check-circle','x-circle','trash',
  'pencil-square','eraser','files','file-earmark','clipboard','copy','save','folder2-open','images',
  'camera','image','play','pause','stop','music-note','mic',
  'printer','display','laptop','tablet','phone','device-hdd','controller','watch'
]

// Build select options with full class names (PxSelect wants {value,label})
const makeBiOptions = (noneLabel) => [
  { value: '', label: noneLabel },
  ...biNames.map(n => ({ value: `bi bi-${n}`, label: n.replace(/-/g, ' ') }))
];

export default {
  components: {
    PxEmptyState, PxPageHeader, PxToolbar, PxTable, PxPagination, PxButton,
    PxKebab, PxBadge, PxField, PxInput, PxSelect, PxCheck, PxModal
  },
  metaInfo: { title: 'Categorías' },

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

      categories: [],
      editmode: false,

      category: { id: '', name: '', code: '', icon: '', show_in_store: true },
    }
  },

  computed: {
    // Bootstrap Icons options
    iconOptions() {
      return makeBiOptions(this.$t('None'))
    },
    columns() {
      return [
        { key: 'code', label: this.$t('Codecategorie'), sortable: true, strong: true },
        { key: 'name', label: this.$t('Namecategorie'), sortable: true },
        { key: 'icon', label: this.$t('Icon'), sortable: false },
        { key: 'show_in_store', label: this.$t('Visible_in_online_store'), sortable: false }
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
    // Helpers
    getState({ dirty, validated, valid = null }) { return dirty || validated ? valid : null },
    toast(variant, msg, title) { this.$root.$bvToast.toast(msg, { title, variant, solid: true }) },
    updateParams(patch) { this.serverParams = { ...this.serverParams, ...patch } },

    // Table events (px-next: local emits, same remote-refetch pattern as before)
    onSearchInput(v) {
      this.search = v
      if (this._searchTimer) clearTimeout(this._searchTimer)
      this._searchTimer = setTimeout(() => {
        this.updateParams({ page: 1 })
        this.fetchCategories()
      }, 350)
    },
    onSort({ key, dir }) {
      this.updateParams({ sort: { field: key, type: dir } })
      this.fetchCategories()
    },
    onPage(p) {
      if (this.serverParams.page !== p) {
        this.updateParams({ page: p })
        this.fetchCategories()
      }
    },
    onLimit(v) {
      if (this.limit !== String(v)) {
        this.limit = String(v)
        this.updateParams({ page: 1, perPage: Number(v) })
        this.fetchCategories()
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
    },

    async openEdit(row) {
      this.resetForm()
      this.editmode = true
      // Fetch full category (we need 'code' which is not in the list view)
      try {
        NProgress.start(); NProgress.set(0.1)
        const { data } = await axios.get(`${API}/${row.id}`)
        this.category = {
          id: data.id,
          name: data.name || '',
          code: data.code || '',
          icon: data.icon || '',
          show_in_store: data.show_in_store === undefined ? true : !!data.show_in_store
        }
      } catch (e) {
        this.toast('danger', this.$t('InvalidData'), this.$t('Failed'))
        return
      } finally {
        NProgress.done()
      }
      this.modalOpen = true
    },

    async fetchCategories() {
      NProgress.start(); NProgress.set(0.1)
      const { page, perPage, sort } = this.serverParams
      try {
        const { data } = await axios.get(
          `${API}?page=${page}&SortField=${sort.field}&SortType=${sort.type}&search=${encodeURIComponent(this.search)}&limit=${this.limit}`
        )
        this.categories = data.categories || []
        this.totalRows = data.totalRows || 0
      } catch (e) {
        // noop
      } finally {
        NProgress.done(); this.isLoading = false
      }
    },

    async submitCategory() {
      const ok = await this.$refs.CategoryForm.validate()
      if (!ok) {
        this.toast('danger', this.$t('Please_fill_the_form_correctly'), this.$t('Failed'))
        return
      }
      this.submitProcessing = true
      try {
        if (this.editmode) {
          await axios.put(`${API}/${this.category.id}`, {
            name: this.category.name,
            code: this.category.code,
            icon: this.category.icon || '',
            show_in_store: this.category.show_in_store ? 1 : 0
          })
          this.toast('success', this.$t('Successfully_Updated'), this.$t('Success'))
        } else {
          await axios.post(API, {
            name: this.category.name,
            code: this.category.code,
            icon: this.category.icon || '',
            show_in_store: this.category.show_in_store ? 1 : 0
          })
          this.toast('success', this.$t('Successfully_Created'), this.$t('Success'))
        }
        this.modalOpen = false
        this.fetchCategories()
      } catch (e) {
        this.toast('danger', this.$t('InvalidData'), this.$t('Failed'))
      } finally {
        this.submitProcessing = false
      }
    },

    resetForm() {
      this.category = { id: '', name: '', code: '', icon: '', show_in_store: true }
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
        confirmButtonText: this.$t('Delete_confirmButtonText')
      })
      if (!res.value) return
      try {
        await axios.delete(`${API}/${id}`)
        await this.$swal(this.$t('Delete_Deleted'), this.$t('Deleted_in_successfully'), 'success')
        this.fetchCategories()
      } catch (e) {
        this.$swal(this.$t('Delete_Failed'), this.$t('Delete_Therewassomethingwronge'), 'warning')
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
        confirmButtonText: this.$t('Delete_confirmButtonText')
      })
      if (!res.value) return

      NProgress.start(); NProgress.set(0.1)
      try {
        await axios.post(`${API}/delete/by_selection`, { selectedIds: this.selectedIds })
        await this.$swal(this.$t('Delete_Deleted'), this.$t('Deleted_in_successfully'), 'success')
        this.selectedIds = []
        this.fetchCategories()
      } catch (e) {
        this.$swal(this.$t('Delete_Failed'), this.$t('Delete_Therewassomethingwronge'), 'warning')
      } finally {
        NProgress.done()
      }
    }
  },

  created() {
    this.fetchCategories()

    // Event bus hooks
    Fire.$on('Event_Category', () => {
      this.modalOpen = false
      this.fetchCategories()
    })
    Fire.$on('Delete_Category', () => {
      this.fetchCategories()
    })
  }
}
</script>

<style lang="scss" src="@/assets/styles/sass/px-next/production.scss"></style>

<style lang="scss" scoped>
.pxcat { min-height: 100%; background: var(--pxn-bg); padding: var(--pxn-space-8) var(--pxn-space-9) var(--pxn-space-9); }
@media (max-width: 620px) { .pxcat { padding: var(--pxn-space-6) var(--pxn-space-5); } }
.pxcat__pad { padding: var(--pxn-space-6) 0; }
.pxcat__muted { color: var(--pxn-ink-3); }

.pxcat__bulk {
  display: flex; align-items: center; justify-content: space-between; gap: var(--pxn-space-5);
  margin-top: var(--pxn-space-4);
  padding: var(--pxn-space-4) var(--pxn-space-5);
  background: var(--pxn-primary-soft);
  border: 1px solid var(--pxn-primary-border);
  border-radius: var(--pxn-radius-md);
  font-size: var(--pxn-fs-sm); color: var(--pxn-primary-ink);
}
.pxcat__bulk-actions { display: flex; gap: var(--pxn-space-3); }
.pxcat-bulk-enter-active, .pxcat-bulk-leave-active { transition: opacity var(--pxn-dur-2) var(--pxn-ease), transform var(--pxn-dur-2) var(--pxn-ease); }
.pxcat-bulk-enter, .pxcat-bulk-leave-to { opacity: 0; transform: translateY(-6px); }

.pxcat__tablewrap { margin-top: var(--pxn-space-5); }

.pxcat__field-gap { margin-top: var(--pxn-space-5); }
.pxcat__iconrow { display: flex; align-items: center; gap: var(--pxn-space-4); }
.pxcat__iconpreview { font-size: 22px; color: var(--pxn-ink-2); }
.pxcat__switch { display: flex; align-items: center; gap: var(--pxn-space-3); margin-top: var(--pxn-space-6); font-size: var(--pxn-fs-body); color: var(--pxn-ink-2); }
.pxcat__actionbar { display: flex; justify-content: flex-end; gap: var(--pxn-space-3); margin-top: var(--pxn-space-7); }
</style>
