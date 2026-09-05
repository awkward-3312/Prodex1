<template>
  <div class="px-next pxcfg">
    <px-page-header
      :title="`${$t('Translations for')} “${language}”`"
      :breadcrumbs="[{ label: $t('Settings'), href: '#/app/settings/System_settings' }, { label: $t('Languages'), href: '#/app/settings/translations_settings' }, { label: $t('Translations') }]"
    >
      <template #actions>
        <px-button variant="ghost" size="sm" icon="arrow-left" @click="$router.push('/app/settings/translations_settings')">{{ $t('Back') }}</px-button>
        <px-button variant="secondary" size="sm" icon="plus" @click="showAddModal = true">{{ $t('Add New') }}</px-button>
        <px-button variant="primary" size="sm" icon="save" @click="bulkSave">{{ $t('Save All Changes') }}</px-button>
      </template>
    </px-page-header>

    <px-alert tone="info" icon="refresh-cw" class="pxcfg__alert">
      {{ $t('Please reload the page after saving translations to apply the changes.') }}
    </px-alert>

    <px-toolbar
      :search="searchInput"
      search-placeholder="Search by key or value..."
      @update:search="v => searchInput = v"
    >
      <template #actions>
        <px-button variant="secondary" size="sm" icon="search" @click="applySearch">{{ $t('Search') || 'Buscar' }}</px-button>
        <px-button variant="ghost" size="sm" icon="x" @click="resetSearch">{{ $t('Reset') }}</px-button>
      </template>
    </px-toolbar>

    <div v-if="isLoading" class="pxcfg__pad">
      <px-skeleton variant="table" :rows="12" :columns="3" />
    </div>

    <template v-else>
      <div class="pxcfg__tablewrap">
        <px-table
          v-if="filteredTranslations.length"
          :columns="columns"
          :rows="filteredTranslations"
          row-key="key"
          has-row-actions
        >
          <template #cell-key="{ row }"><code class="pxcfg__key">{{ row.key }}</code></template>
          <template #cell-value="{ row }">
            <px-input :value="row.value" @input="v => row.value = v" />
          </template>
          <template #row-actions="{ row }">
            <div class="pxcfg__rowbtns">
              <px-button variant="ghost" size="sm" icon="save" @click="saveTranslation(row)">{{ $t('Save') }}</px-button>
              <px-button v-if="row.locale !== 'en'" class="pxcfg__del" variant="ghost" size="sm" icon="trash-2" @click="deleteTranslation(row.key)">{{ $t('Delete') }}</px-button>
            </div>
          </template>
        </px-table>
        <px-empty-state v-else icon="globe" title="Sin traducciones" description="Ajusta la búsqueda o agrega una traducción." />
      </div>

      <px-pagination
        v-if="totalRows"
        :page="currentPage"
        :per-page="perPage"
        :total="totalRows"
        :per-page-options="['100']"
        @update:page="p => currentPage = p"
      />
      <div class="pxcfg__count">{{ $t('Showing') }} {{ translations.length }} of {{ totalRows }} {{ $t('Translations') }}</div>
    </template>

    <px-modal v-model="showAddModal" :title="$t('Add New Translation')" size="md">
      <div class="pxcfg__formgrid">
        <px-field label="Key" required>
          <template #default="{ id }"><px-input :id="id" v-model="newTranslation.key" /></template>
        </px-field>
        <px-field label="Value">
          <template #default="{ id }"><px-input :id="id" v-model="newTranslation.value" /></template>
        </px-field>
      </div>
      <template #footer="{ close }">
        <px-button variant="ghost" @click="close">{{ $t('Cancel') }}</px-button>
        <px-button variant="primary" @click="submitNewTranslation">{{ $t('Add') || 'Agregar' }}</px-button>
      </template>
    </px-modal>
  </div>
</template>

<script>
import PxPageHeader from "@/components/px-next/PxPageHeader.vue";
import PxToolbar from "@/components/px-next/PxToolbar.vue";
import PxTable from "@/components/px-next/PxTable.vue";
import PxPagination from "@/components/px-next/PxPagination.vue";
import PxButton from "@/components/px-next/PxButton.vue";
import PxModal from "@/components/px-next/PxModal.vue";
import PxField from "@/components/px-next/PxField.vue";
import PxInput from "@/components/px-next/PxInput.vue";
import PxAlert from "@/components/px-next/PxAlert.vue";
import PxEmptyState from "@/components/px-next/PxEmptyState.vue";

export default {
  components: { PxPageHeader, PxToolbar, PxTable, PxPagination, PxButton, PxModal, PxField, PxInput, PxAlert, PxEmptyState },
  data() {
    return {
      showAddModal: false,
      newTranslation: {
        key: '',
        value: '',
      },
      locale: this.$route.params.locale,
      language: '',
      translations: [],
      originalTranslations: [],
      totalRows: 0,
      currentPage: 1,
      perPage: 100,
      isLoading: false,
      searchQuery: '',
      searchInput: '',
    };
  },
  computed: {
    columns() {
      return [
        { key: "key", label: "Key" },
        { key: "value", label: "Value" }
      ];
    },
    filteredTranslations() {
      if (!this.searchQuery) return this.translations;
      return this.translations.filter(item =>
        item.key.toLowerCase().includes(this.searchQuery.toLowerCase())
      );
    }
  },
  methods: {
    async submitNewTranslation() {
      if (!this.newTranslation.key) return;
      try {
        await axios.put(`/translations_setting/${this.locale}`, {
          key: this.newTranslation.key,
          value: this.newTranslation.value,
        });
        this.$bvToast.toast(this.$t("Translation added"), { title: this.$t("Success"), variant: 'success', solid: true });
        this.showAddModal = false;
        this.newTranslation.key = '';
        this.newTranslation.value = '';
        this.fetchTranslations(this.currentPage);
      } catch (err) {
        this.$bvToast.toast(this.$t("Failed to add translation"), { title: this.$t("Failed"), variant: 'danger', solid: true });
      }
    },

    applySearch() {
      this.searchQuery = this.searchInput;
      this.currentPage = 1;
      this.fetchTranslations(1);
    },

    resetSearch() {
      this.searchInput = '';
      this.searchQuery = '';
      this.currentPage = 1;
      this.fetchTranslations(1);
    },

    async fetchTranslations(page = 1) {
      this.isLoading = true;
      try {
        const res = await axios.get(`/translations_setting/${this.locale}`, {
          params: {
            page: page,
            per_page: this.perPage,
            search: this.searchQuery,
          }
        });
        this.translations = res.data.data;
        this.originalTranslations = JSON.parse(JSON.stringify(res.data.data));
        this.totalRows = res.data.total;
        this.currentPage = res.data.current_page;
        this.language = res.data.language;
      } catch (err) {
      } finally {
        this.isLoading = false;
      }
    },

    async saveTranslation(entry) {
      try {
        await axios.put(`/translations_setting/${this.locale}`, {
          key: entry.key,
          value: entry.value,
        });
        this.$bvToast.toast(this.$t("Translation updated"), { title: 'Success', variant: 'success', solid: true });
      } catch (err) {
        this.$bvToast.toast(this.$t("Failed to update"), { title: this.$t("Failed"), variant: 'danger', solid: true });
      }
    },

    async bulkSave() {
      const changed = this.translations.filter((t, i) =>
        t.value !== (this.originalTranslations[i] && this.originalTranslations[i].value)
      );

      if (!changed.length) {
        this.$bvToast.toast(this.$t("No changes to save"), { title: this.$t("Notice"), variant: 'info', solid: true });
        return;
      }

      this.isLoading = true;
      try {
        await Promise.all(changed.map(entry =>
          axios.put(`/translations_setting/${this.locale}`, {
            key: entry.key,
            value: entry.value,
          })
        ));
        this.$bvToast.toast(this.$t("All changes saved successfully"), { title: this.$t("Success"), variant: 'success', solid: true });
        this.fetchTranslations(this.currentPage);
      } catch (err) {
        this.$bvToast.toast(this.$t("Bulk save failed"), { title: this.$t("Failed"), variant: 'danger', solid: true });
      } finally {
        this.isLoading = false;
      }
    },

    async deleteTranslation(key) {
      const translation = this.translations.find(t => t.key === key);
      if (!translation) return;

      this.$swal({
        title: this.$t("Delete_Title"),
        text: this.$t("Delete_Text"),
        icon: "warning",
        showCancelButton: true,
        confirmButtonColor: "var(--px-primary)",
        cancelButtonColor: "#d33",
        cancelButtonText: this.$t("Delete_cancelButtonText"),
        confirmButtonText: this.$t("Delete_confirmButtonText"),
      }).then(async (result) => {
        if (result.value) {
          try {
            await axios.delete(`/translations_setting/${this.locale}/${key}`);
            this.$swal(this.$t("Delete_Deleted"), this.$t("Deleted_in_successfully"), "success");
            this.fetchTranslations(this.currentPage);
          } catch (error) {
            this.$swal(this.$t("Delete_Failed"), this.$t("Delete_Therewassomethingwronge"), "warning");
          }
        }
      });
    }
  },
  watch: {
    currentPage(newPage) {
      this.fetchTranslations(newPage);
    },
    searchQuery() {
      this.currentPage = 1;
      this.fetchTranslations(1);
    }
  },
  created() {
    this.fetchTranslations();
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
.pxcfg__key { font-family: var(--pxn-font-mono, monospace); font-size: var(--pxn-fs-xs); color: var(--pxn-ink-2); }
.pxcfg__count { text-align: right; margin-top: var(--pxn-space-2); font-size: var(--pxn-fs-xs); color: var(--pxn-ink-3); }
.pxcfg__rowbtns { display: flex; gap: var(--pxn-space-2); justify-content: flex-end; }
.pxcfg__del ::v-deep .pxn-btn__icon { color: var(--pxn-danger); }
.pxcfg__formgrid { display: grid; grid-template-columns: minmax(0, 1fr); gap: var(--pxn-space-4); }
</style>
