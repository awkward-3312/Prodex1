<template>
  <div class="px-next pxcfg">
    <px-page-header
      :title="$t('Languages')"
      :breadcrumbs="[{ label: $t('Settings'), href: '#/app/settings/System_settings' }, { label: $t('Languages') }]"
    />

    <div v-if="isLoading" class="pxcfg__pad">
      <px-skeleton variant="lines" :rows="6" />
    </div>

    <template v-else>
      <px-card :title="$t(lang.id ? 'Edit' : 'Add') + ' ' + $t('Language')" class="pxcfg__card">
        <form @submit.prevent="submitLang">
          <div class="pxcfg__grid pxcfg__grid--3">
            <px-field :label="$t('Name')" required>
              <template #default="{ id }"><px-input :id="id" v-model="lang.name" /></template>
            </px-field>
            <px-field :label="$t('Locale')" required>
              <template #default="{ id }"><px-input :id="id" v-model="lang.locale" /></template>
            </px-field>
            <px-field :label="$t('Flag')">
              <template #default="{ id }">
                <input :id="id" class="pxcfg__file" type="file" accept="image/*" @change="onFlagChange" />
              </template>
            </px-field>
          </div>
        </form>
        <template #footer>
          <px-button variant="ghost" @click="resetForm">{{ $t('Reset') }}</px-button>
          <px-button variant="primary" :loading="SubmitProcessing" :disabled="SubmitProcessing" @click="submitLang">
            {{ $t(lang.id ? 'Update Language' : 'Add Language') }} {{ $t('Language') }}
          </px-button>
        </template>
      </px-card>

      <div class="pxcfg__tablewrap">
        <px-table
          v-if="languages.length"
          :columns="columns"
          :rows="languages"
          row-key="id"
          has-row-actions
        >
          <template #cell-flag="{ row }">
            <img v-if="row.flag" :src="`/flags/${row.flag}`" width="28" alt="" class="pxcfg__flag" />
            <span v-else>—</span>
          </template>
          <template #cell-is_active="{ row }">
            <px-check :modelValue="truthy(row.is_active)" @change="() => onToggleActive(row)" />
          </template>
          <template #cell-is_default="{ row }">
            <px-check :modelValue="truthy(row.is_default)" @change="() => onToggleDefault(row)" />
          </template>
          <template #row-actions="{ row }">
            <div class="pxcfg__rowbtns">
              <px-button v-if="row.locale !== 'en'" variant="ghost" size="sm" icon="pencil" @click="editLang(row)">{{ $t('Edit') }}</px-button>
              <px-button v-if="row.locale !== 'en'" class="pxcfg__del" variant="ghost" size="sm" icon="trash-2" @click="deleteLang(row.id)">{{ $t('Delete') }}</px-button>
              <px-button variant="subtle" size="sm" icon="globe" @click="$router.push({ name: 'translations_view', params: { locale: row.locale } })">{{ $t('Translations') }}</px-button>
            </div>
          </template>
        </px-table>
        <px-empty-state v-else icon="globe" title="Sin idiomas" description="Agrega un idioma para verlo en esta lista." />
      </div>
    </template>
  </div>
</template>

<script>
import NProgress from "nprogress";
import PxPageHeader from "@/components/px-next/PxPageHeader.vue";
import PxTable from "@/components/px-next/PxTable.vue";
import PxButton from "@/components/px-next/PxButton.vue";
import PxCard from "@/components/px-next/PxCard.vue";
import PxField from "@/components/px-next/PxField.vue";
import PxInput from "@/components/px-next/PxInput.vue";
import PxCheck from "@/components/px-next/PxCheck.vue";
import PxEmptyState from "@/components/px-next/PxEmptyState.vue";

export default {
  metaInfo: {
    title: "Languages"
  },
  components: { PxPageHeader, PxTable, PxButton, PxCard, PxField, PxInput, PxCheck, PxEmptyState },

  data() {
    return {
      isLoading: true,
      SubmitProcessing: false,
      languages: [],
      lang: {
        id: null,
        name: '',
        locale: '',
        flag: null,
      },
    };
  },
  computed: {
    columns() {
      return [
        { key: "flag", label: this.$t("Flag") },
        { key: "name", label: this.$t("Name"), strong: true },
        { key: "locale", label: this.$t("Locale") },
        { key: "is_active", label: this.$t("Active") || "Activo" },
        { key: "is_default", label: this.$t("Default") || "Predeterminado" }
      ];
    }
  },
  methods: {
    truthy(v) { return v === true || v == 1 || v === '1'; },

    onFlagChange(e) {
      this.lang.flag = (e.target.files && e.target.files[0]) || null;
    },

    async onToggleDefault(item) {
      try {
        await axios.post(`/languages_setting/${item.id}/set-default`);
        this.languages = this.languages.map(lang => ({
          ...lang,
          is_default: lang.id === item.id
        }));
        this.SetLocal(item.locale);
        this.makeToast("success", this.$t("Successfully_Updated"), this.$t("Success"));
      } catch (error) {
        this.makeToast("danger", this.$t("InvalidData"), this.$t("Failed"));
      }
    },

    async onToggleActive(item) {
      if (item.is_default) {
        this.makeToast("danger", 'Cannot change default language', this.$t("Failed"));
        return;
      }
      try {
        await axios.post(`/languages_setting/${item.id}/set-active`);
        this.makeToast("success", this.$t("Successfully_Updated"), this.$t("Success"));
        window.location.reload();
      } catch (error) {
        this.makeToast("danger", this.$t("InvalidData"), this.$t("Failed"));
      }
    },

    async fetchLanguages() {
      this.isLoading = true;
      const { data } = await axios.get('/languages_setting');
      this.languages = data;
      this.isLoading = false;
    },

    async submitLang() {
      this.SubmitProcessing = true;
      const formData = new FormData();
      formData.append('name', this.lang.name);
      formData.append('locale', this.lang.locale);
      if (this.lang.flag) formData.append('flag', this.lang.flag);

      try {
        if (this.lang.id) {
          await axios.post(`/languages_setting/${this.lang.id}?_method=PUT`, formData);
        } else {
          await axios.post('/languages_setting', formData);
        }
        this.makeToast('success', this.$t('Successfully_Created'), this.$t('Success'));
        this.resetForm();
        this.fetchLanguages();
        this.SubmitProcessing = false;
      } catch (e) {
        this.makeToast('danger', this.$t('InvalidData'), this.$t('Failed'));
        this.SubmitProcessing = false;
      }
    },

    async deleteLang(id) {
      const lang = this.languages.find(l => l.id === id);
      if (!lang) return;
      if (lang.is_default) {
        this.$swal(this.$t("Action_Blocked"), this.$t("You_cannot_delete_the_default_language"), "error");
        return;
      }
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
          NProgress.start();
          NProgress.set(0.1);
          try {
            await axios.delete("languages_setting/" + id);
            this.$swal(this.$t("Delete_Deleted"), this.$t("Deleted_in_successfully"), "success");
            this.fetchLanguages();
            setTimeout(() => NProgress.done(), 500);
          } catch (error) {
            setTimeout(() => NProgress.done(), 500);
            this.$swal(this.$t("Delete_Failed"), this.$t("Delete_Therewassomethingwronge"), "warning");
          }
        }
      });
    },

    SetLocal(locale) {
      this.$i18n.locale = locale;
      this.$store.dispatch("setLanguage", locale);
      Fire.$emit("ChangeLanguage");
      window.location.reload();
    },

    editLang(lang) {
      this.lang = { ...lang, flag: null };
    },
    resetForm() {
      this.lang = { id: null, name: '', locale: '', flag: null };
    },

    makeToast(variant, msg, title) {
      this.$root.$bvToast.toast(msg, {
        title: title,
        variant: variant,
        solid: true
      });
    },
  },
  created() {
    this.fetchLanguages();
  }
};
</script>

<style lang="scss" src="@/assets/styles/sass/px-next/production.scss"></style>

<style lang="scss" scoped>
.pxcfg { min-height: 100%; background: var(--pxn-bg); padding: var(--pxn-space-8) var(--pxn-space-9) var(--pxn-space-9); }
@media (max-width: 620px) { .pxcfg { padding: var(--pxn-space-6) var(--pxn-space-5); } }
.pxcfg__pad { padding: var(--pxn-space-6) 0; }
.pxcfg__card { margin-top: var(--pxn-space-5); }
.pxcfg__grid { display: grid; grid-template-columns: repeat(2, minmax(0, 1fr)); gap: var(--pxn-space-4) var(--pxn-space-5); }
.pxcfg__grid--3 { grid-template-columns: repeat(3, minmax(0, 1fr)); }
@media (max-width: 900px) { .pxcfg__grid--3 { grid-template-columns: repeat(2, minmax(0, 1fr)); } }
@media (max-width: 640px) { .pxcfg__grid, .pxcfg__grid--3 { grid-template-columns: minmax(0, 1fr); } }
.pxcfg__file { width: 100%; font: inherit; font-size: var(--pxn-fs-sm); color: var(--pxn-ink-2); padding: var(--pxn-space-2) 0; }
.pxcfg__tablewrap { margin-top: var(--pxn-space-4); }
.pxcfg__flag { border-radius: 3px; vertical-align: middle; }
.pxcfg__rowbtns { display: flex; gap: var(--pxn-space-2); flex-wrap: wrap; justify-content: flex-end; }
.pxcfg__del ::v-deep .pxn-btn__icon { color: var(--pxn-danger); }
</style>
