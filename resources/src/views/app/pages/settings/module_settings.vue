<template>
  <div class="px-next pxcfg pxmod">
    <px-page-header
      :title="$t('module_settings') || 'Module Settings'"
      subtitle="Install, manage and configure modules to extend your Stocky application."
      :breadcrumbs="[{ label: $t('Settings'), href: '#/app/settings/System_settings' }, { label: $t('module_settings') || 'Module Settings' }]"
    />

    <div v-if="isLoading" class="pxcfg__pad">
      <px-skeleton variant="lines" :rows="6" />
    </div>

    <template v-else>
      <div class="pxcfg__stats">
        <px-stat icon="puzzle" label="Installed" :value="String(modules_info.length)" bordered />
        <px-stat icon="check-circle" label="Active" :value="String(activeCount)" bordered />
        <px-stat icon="circle-slash" label="Inactive" :value="String(inactiveCount)" bordered />
      </div>

      <px-card title="Install New Module" class="pxcfg__card">
        <validation-observer ref="ref_Upload_Module">
          <form @submit.prevent="Submit_Upload_Module" enctype="multipart/form-data">
            <validation-provider name="Upload Module" ref="Upload_Module" v-slot="{ errors }">
              <div
                class="pxmod__drop"
                :class="{ 'is-drag': isDragging, 'has-file': module_zip, 'is-invalid': !!errors.length }"
                @dragover.prevent="isDragging = true"
                @dragleave.prevent="isDragging = false"
                @drop.prevent="onFileDrop"
                @click="$refs.fileInput.click()"
              >
                <input ref="fileInput" @change="onFileSelected" type="file" accept=".zip" class="pxmod__file" />
                <template v-if="!module_zip">
                  <div class="pxmod__drop-icon"><lucide-icon name="upload" :size="24" /></div>
                  <p class="pxmod__drop-text">Drag &amp; drop your module <strong>.zip</strong> file here</p>
                  <p class="pxcfg__cardnote">or click to browse files</p>
                </template>
                <template v-else>
                  <div class="pxmod__drop-icon"><lucide-icon name="file-archive" :size="22" /></div>
                  <div class="pxmod__file-details">
                    <span class="pxmod__file-name">{{ module_zip.name }}</span>
                    <span class="pxcfg__cardnote">{{ formatFileSize(module_zip.size) }}</span>
                  </div>
                  <px-button class="pxcfg__del" variant="ghost" size="sm" icon-only icon="x" aria-label="Quitar" @click.stop.native="removeFile" />
                </template>
              </div>
              <div v-if="errors[0]" class="pxmod__err">{{ errors[0] }}</div>
            </validation-provider>
          </form>
        </validation-observer>
        <template #footer>
          <px-button variant="primary" icon="upload" :loading="SubmitProcessing" :disabled="SubmitProcessing || !module_zip" @click="Submit_Upload_Module">
            {{ SubmitProcessing ? 'Installing...' : 'Install Module' }}
          </px-button>
        </template>
      </px-card>

      <template v-if="modules_info.length > 0">
        <div class="pxmod__sectionhead">
          <h4 class="pxcfg__subhead">Installed Modules</h4>
          <div class="pxcfg__seg">
            <px-button size="sm" :variant="filter === 'all' ? 'primary' : 'subtle'" @click="filter = 'all'">All</px-button>
            <px-button size="sm" :variant="filter === 'active' ? 'primary' : 'subtle'" @click="filter = 'active'">Active</px-button>
            <px-button size="sm" :variant="filter === 'inactive' ? 'primary' : 'subtle'" @click="filter = 'inactive'">Inactive</px-button>
          </div>
        </div>

        <div class="pxmod__grid">
          <div v-for="module_item in filteredModules" :key="module_item.module_name" class="pxmod__card" :class="{ 'is-active': module_item.status }">
            <div class="pxmod__card-top">
              <div class="pxmod__card-icon"><lucide-icon :name="getModuleIcon(module_item.module_name)" :size="22" /></div>
              <px-badge :tone="module_item.status ? 'success' : 'neutral'">{{ module_item.status ? 'Active' : 'Inactive' }}</px-badge>
            </div>
            <h5 class="pxmod__card-name">{{ formatModuleName(module_item.module_name) }}</h5>
            <p class="pxmod__card-desc">{{ getModuleDescription(module_item.module_name) }}</p>
            <div class="pxmod__card-meta"><lucide-icon name="tag" :size="12" /> v{{ module_item.current_version }}</div>
            <div class="pxmod__card-foot">
              <px-check type="switch" :modelValue="!!module_item.status" :disabled="togglingModule === module_item.module_name"
                @change="v => { module_item.status = v; update_status_module(module_item); }">
                {{ module_item.status ? 'Enabled' : 'Disabled' }}
              </px-check>
            </div>
          </div>
        </div>
      </template>

      <px-empty-state v-else icon="puzzle" title="No Modules Installed"
        description="Upload a module zip file above to get started. Modules add new features and functionality to your Stocky application." />
    </template>
  </div>
</template>

<script>
import { mapActions, mapGetters } from "vuex";
import NProgress from "nprogress";
import PxPageHeader from "@/components/px-next/PxPageHeader.vue";
import PxButton from "@/components/px-next/PxButton.vue";
import PxCard from "@/components/px-next/PxCard.vue";
import PxStat from "@/components/px-next/PxStat.vue";
import PxCheck from "@/components/px-next/PxCheck.vue";
import PxBadge from "@/components/px-next/PxBadge.vue";
import PxEmptyState from "@/components/px-next/PxEmptyState.vue";

export default {
  metaInfo: {
    title: "Module Settings"
  },
  components: { PxPageHeader, PxButton, PxCard, PxStat, PxCheck, PxBadge, PxEmptyState },
  data() {
    return {
      isLoading: true,
      SubmitProcessing: false,
      modules_info: [],
      module_zip: '',
      data: new FormData(),
      isDragging: false,
      filter: 'all',
      togglingModule: null,
    };
  },

  computed: {
    activeCount() {
      return this.modules_info.filter(m => m.status).length;
    },
    inactiveCount() {
      return this.modules_info.filter(m => !m.status).length;
    },
    filteredModules() {
      if (this.filter === 'active') return this.modules_info.filter(m => m.status);
      if (this.filter === 'inactive') return this.modules_info.filter(m => !m.status);
      return this.modules_info;
    }
  },

  methods: {
    ...mapActions(["refreshUserPermissions"]),

    makeToast(variant, msg, title) {
      this.$root.$bvToast.toast(msg, {
        title: title,
        variant: variant,
        solid: true
      });
    },

    formatFileSize(bytes) {
      if (!bytes) return '0 B';
      const k = 1024;
      const sizes = ['B', 'KB', 'MB', 'GB'];
      const i = Math.floor(Math.log(bytes) / Math.log(k));
      return parseFloat((bytes / Math.pow(k, i)).toFixed(1)) + ' ' + sizes[i];
    },

    formatModuleName(name) {
      return name.replace(/([A-Z])/g, ' $1').replace(/^[\s]/, '').trim();
    },

    getModuleIcon(name) {
      const icons = {
        'ApiDocs': 'clipboard-list',
        'WooCommerceSync': 'shopping-cart',
        'Ecommerce': 'shopping-bag',
        'HRM': 'users',
        'Commission': 'banknote',
        'Contracts': 'file-pen',
        'Bookings': 'calendar',
        'Reports': 'bar-chart',
        'Recruit': 'users',
      };
      return icons[name] || 'puzzle';
    },

    getModuleDescription(name) {
      const descriptions = {
        'ApiDocs': 'Interactive API documentation with code examples and endpoint reference.',
        'WooCommerceSync': 'Sync products and stock with your WooCommerce store.',
        'Ecommerce': 'Full-featured online store with product catalog and checkout.',
        'HRM': 'Human resource management with employees, attendance, and payroll.',
        'Commission': 'Sales agent commission programs, rules, and tracking.',
        'Contracts': 'Contract management with templates, tasks, and attachments.',
        'Bookings': 'Appointment booking and service job management.',
        'Reports': 'Advanced reporting and business analytics.',
        'Recruit': 'Recruitment management with jobs, candidates, applications, interviews and reports.',
      };
      return descriptions[name] || 'Extends your Stocky application with additional functionality.';
    },

    async onFileSelected(e) {
      const { valid } = await this.$refs.Upload_Module.validate(e);
      if (valid) {
        this.module_zip = e.target.files[0];
      } else {
        this.module_zip = "";
      }
    },

    onFileDrop(e) {
      this.isDragging = false;
      const files = e.dataTransfer.files;
      if (files.length && files[0].name.endsWith('.zip')) {
        this.module_zip = files[0];
      } else {
        this.makeToast("danger", "Please upload a .zip file", this.$t("Failed"));
      }
    },

    removeFile() {
      this.module_zip = '';
      this.data = new FormData();
      if (this.$refs.fileInput) {
        this.$refs.fileInput.value = '';
      }
    },

    update_status_module(module_info) {
      this.togglingModule = module_info.module_name;
      axios
        .post("update_status_module", {
          status: module_info.status,
          name: module_info.module_name,
        })
        .then(response => {
          if (module_info.status) {
            this.makeToast(
              "success",
              this.$t("Module_enabled_success") || "Module enabled successfully",
              this.$t("Success")
            );
          } else {
            this.makeToast(
              "warning",
              this.$t("Module_Disabled_success") || "Module disabled successfully",
              this.$t("Warning")
            );
          }
          this.togglingModule = null;
          setTimeout(() => { window.location.reload(); }, 1000);
        })
        .catch(error => {
          module_info.status = !module_info.status;
          this.togglingModule = null;
          this.makeToast(
            "danger",
            this.$t("Delete_Therewassomethingwronge") || "Something went wrong",
            this.$t("Warning")
          );
        });
    },

    get_modules_info() {
      axios
        .get("get_modules_info")
        .then(response => {
          this.modules_info = response.data;
          this.isLoading = false;
        })
        .catch(error => {
          setTimeout(() => {
            this.isLoading = false;
          }, 500);
        });
    },

    Submit_Upload_Module() {
      this.$refs.ref_Upload_Module.validate().then(success => {
        if (!success) {
          this.makeToast(
            "danger",
            this.$t("Please_Upload_the_Correct_Module") || "Please upload a valid module",
            this.$t("Failed")
          );
        } else {
          this.Upload_Module();
        }
      });
    },

    Upload_Module() {
      var self = this;
      self.SubmitProcessing = true;
      self.data.append("module_zip", self.module_zip);
      axios
        .post("upload_module", self.data)
        .then(response => {
          self.SubmitProcessing = false;
          self.module_zip = '';
          self.data = new FormData();
          self.makeToast(
            "success",
            self.$t("Uploaded_Success") || "Module installed successfully",
            self.$t("Success")
          );
          setTimeout(() => { window.location.reload(); }, 1000);
        })
        .catch(error => {
          self.SubmitProcessing = false;
          self.makeToast("danger", self.$t("InvalidData") || "Invalid module file", self.$t("Failed"));
        });
    },
  },

  created: function() {
    this.get_modules_info();
  }
};
</script>

<style lang="scss" src="@/assets/styles/sass/px-next/production.scss"></style>

<style lang="scss" scoped>
.pxcfg { min-height: 100%; background: var(--pxn-bg); padding: var(--pxn-space-8) var(--pxn-space-9) var(--pxn-space-9); }
@media (max-width: 620px) { .pxcfg { padding: var(--pxn-space-6) var(--pxn-space-5); } }
.pxcfg__pad { padding: var(--pxn-space-6) 0; }
.pxcfg__card { margin-top: var(--pxn-space-5); }
.pxcfg__cardnote { font-size: var(--pxn-fs-xs); color: var(--pxn-ink-3); margin: var(--pxn-space-1) 0 0; }
.pxcfg__stats { display: grid; grid-template-columns: repeat(3, minmax(0, 1fr)); gap: var(--pxn-space-4); margin-top: var(--pxn-space-5); }
@media (max-width: 560px) { .pxcfg__stats { grid-template-columns: minmax(0, 1fr); } }
.pxcfg__subhead { margin: 0; font-size: var(--pxn-fs-md); font-weight: var(--pxn-fw-semibold); }
.pxcfg__seg { display: flex; gap: var(--pxn-space-2); }
.pxcfg__del ::v-deep .pxn-btn__icon { color: var(--pxn-danger); }

.pxmod__drop {
  border: 1px dashed var(--pxn-border-strong, var(--pxn-border)); border-radius: var(--pxn-radius-lg);
  padding: var(--pxn-space-8) var(--pxn-space-6); text-align: center; cursor: pointer;
  display: flex; flex-direction: column; align-items: center; gap: var(--pxn-space-2);
  transition: border-color 120ms, background 120ms;
}
.pxmod__drop:hover, .pxmod__drop.is-drag { border-color: var(--pxn-primary); background: var(--pxn-surface-2); }
.pxmod__drop.has-file { flex-direction: row; justify-content: center; text-align: left; }
.pxmod__drop.is-invalid { border-color: var(--pxn-danger); }
.pxmod__file { display: none; }
.pxmod__drop-icon { width: 44px; height: 44px; border-radius: 50%; background: var(--pxn-surface-2); display: flex; align-items: center; justify-content: center; color: var(--pxn-ink-2); }
.pxmod__drop-text { margin: 0; font-size: var(--pxn-fs-sm); color: var(--pxn-ink); }
.pxmod__file-details { display: flex; flex-direction: column; }
.pxmod__file-name { font-size: var(--pxn-fs-sm); font-weight: var(--pxn-fw-semibold); }
.pxmod__err { text-align: center; margin-top: var(--pxn-space-2); font-size: var(--pxn-fs-xs); color: var(--pxn-danger); }

.pxmod__sectionhead { display: flex; align-items: center; justify-content: space-between; gap: var(--pxn-space-4); margin: var(--pxn-space-6) 0 var(--pxn-space-3); flex-wrap: wrap; }
.pxmod__grid { display: grid; grid-template-columns: repeat(3, minmax(0, 1fr)); gap: var(--pxn-space-4); }
@media (max-width: 1000px) { .pxmod__grid { grid-template-columns: repeat(2, minmax(0, 1fr)); } }
@media (max-width: 640px) { .pxmod__grid { grid-template-columns: minmax(0, 1fr); } }
.pxmod__card { border: 1px solid var(--pxn-border); border-radius: var(--pxn-radius-lg); background: var(--pxn-surface); padding: var(--pxn-space-5); display: flex; flex-direction: column; gap: var(--pxn-space-3); }
.pxmod__card.is-active { border-color: color-mix(in srgb, var(--pxn-success) 45%, var(--pxn-border)); }
.pxmod__card-top { display: flex; align-items: center; justify-content: space-between; }
.pxmod__card-icon { width: 40px; height: 40px; border-radius: var(--pxn-radius-md); background: var(--pxn-surface-2); display: flex; align-items: center; justify-content: center; color: var(--pxn-ink-2); }
.pxmod__card-name { margin: 0; font-size: var(--pxn-fs-md); font-weight: var(--pxn-fw-semibold); }
.pxmod__card-desc { margin: 0; font-size: var(--pxn-fs-xs); color: var(--pxn-ink-3); line-height: 1.5; flex: 1; }
.pxmod__card-meta { display: flex; align-items: center; gap: var(--pxn-space-2); font-size: var(--pxn-fs-xs); color: var(--pxn-ink-3); }
.pxmod__card-foot { border-top: 1px solid var(--pxn-border); padding-top: var(--pxn-space-3); }
</style>
