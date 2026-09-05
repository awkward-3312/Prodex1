<template>
  <div class="px-next pxcfg">
    <px-page-header
      :title="$t('Appearance_Settings')"
      :breadcrumbs="[{ label: $t('Settings'), href: '#/app/settings/System_settings' }, { label: $t('Appearance_Settings') }]"
    />

    <div v-if="isLoading" class="pxcfg__pad">
      <px-skeleton variant="lines" :rows="8" />
    </div>

    <validation-observer v-else ref="form_setting">
      <form @submit.prevent="Submit_Setting">
        <px-card :title="$t('Appearance_Settings')" class="pxcfg__card">
          <div class="pxcfg__grid pxcfg__grid--3">
            <validation-provider ref="appNameProvider" name="App Name" :rules="{ required: true }" v-slot="v">
              <px-field :label="$t('app_name') + ' *'" :error="v.errors[0]">
                <template #default="{ id, invalid }">
                  <px-input :id="id" v-model="setting.app_name" :invalid="invalid" @input="v.validate" />
                </template>
              </px-field>
            </validation-provider>

            <validation-provider ref="titleSuffixProvider" name="Page Title Suffix" :rules="{ required: true }" v-slot="v">
              <px-field :label="$t('page_title_suffix') + ' *'" :error="v.errors[0]">
                <template #default="{ id, invalid }">
                  <px-input :id="id" v-model="setting.page_title_suffix" :invalid="invalid" @input="v.validate" />
                </template>
              </px-field>
            </validation-provider>

            <validation-provider ref="developedByProvider" name="developed by" :rules="{ required: true }" v-slot="v">
              <px-field :label="$t('developed_by') + ' *'" :error="v.errors[0]">
                <template #default="{ id, invalid }">
                  <px-input :id="id" v-model="setting.developed_by" :invalid="invalid" @input="v.validate" />
                </template>
              </px-field>
            </validation-provider>

            <validation-provider ref="footerProvider" name="footer" :rules="{ required: true }" v-slot="v">
              <px-field :label="$t('footer') + ' *'" :error="v.errors[0]">
                <template #default="{ id, invalid }">
                  <px-input :id="id" v-model="setting.footer" :invalid="invalid" @input="v.validate" />
                </template>
              </px-field>
            </validation-provider>

            <validation-provider name="Logo" ref="Logo" rules="mimes:image/*|size:200" v-slot="{ errors }">
              <px-field :label="$t('ChangeLogo')" hint="Tamaño máximo del archivo: 200 KB" :error="errors[0]">
                <template #default="{ id }">
                  <input :id="id" class="pxcfg__file" :class="{ 'is-invalid': !!errors.length }" @change="onFileSelected" type="file" accept="image/*" />
                </template>
              </px-field>
            </validation-provider>

            <validation-provider name="Favicon" ref="Favicon" rules="mimes:image/*|size:100" v-slot="{ errors }">
              <px-field :label="$t('ChangeFavicon')" hint="Tamaño máximo del archivo: 100 KB" :error="errors[0]">
                <template #default="{ id }">
                  <input :id="id" class="pxcfg__file" :class="{ 'is-invalid': !!errors.length }" @change="onFaviconSelected" type="file" accept="image/*" />
                </template>
              </px-field>
            </validation-provider>
          </div>

          <div class="pxcfg__toggles">
            <div class="pxcfg__toggle">
              <div class="pxcfg__toggle-copy">
                <div class="pxcfg__toggle-title">Show the floating Customize button</div>
                <div class="pxcfg__toggle-hint">When enabled, a Customize button appears at the bottom-right of every page so users can quickly change theme, layout, primary color and language.</div>
              </div>
              <px-check type="switch" :modelValue="!!setting.customize_button_visible" @change="v => setting.customize_button_visible = v" />
            </div>
            <div class="pxcfg__toggle">
              <div class="pxcfg__toggle-copy">
                <div class="pxcfg__toggle-title">{{ $t('hide_site_name') }}</div>
                <div class="pxcfg__toggle-hint">{{ $t('hide_site_name_hint') }}</div>
              </div>
              <px-check type="switch" :modelValue="!!setting.hide_site_name" @change="v => setting.hide_site_name = v" />
            </div>
          </div>

          <template #footer>
            <px-button variant="primary" icon="check" type="submit" @click="Submit_Setting">{{ $t('submit') }}</px-button>
          </template>
        </px-card>

        <px-card :title="$t('Appearance_Settings') + ' — Login Page'" class="pxcfg__card">
          <div class="pxcfg__grid">
            <px-field label="Login hero title">
              <template #default="{ id }"><px-input :id="id" v-model="setting.login_hero_title" /></template>
            </px-field>
            <px-field label="Login hero subtitle">
              <template #default="{ id }"><px-input :id="id" v-model="setting.login_hero_subtitle" /></template>
            </px-field>
            <px-field label="Login panel title">
              <template #default="{ id }"><px-input :id="id" v-model="setting.login_panel_title" /></template>
            </px-field>
            <px-field label="Login panel subtitle">
              <template #default="{ id }"><px-input :id="id" v-model="setting.login_panel_subtitle" /></template>
            </px-field>
            <px-field label="Hero badge text">
              <template #default="{ id }"><px-input :id="id" v-model="setting.login_hero_badge" placeholder="Secure & Reliable" /></template>
            </px-field>
            <px-field label="Hero feature 1">
              <template #default="{ id }"><px-input :id="id" v-model="setting.login_hero_feature_1" placeholder="Real-time inventory tracking" /></template>
            </px-field>
            <px-field label="Hero feature 2">
              <template #default="{ id }"><px-input :id="id" v-model="setting.login_hero_feature_2" placeholder="Multi-location POS support" /></template>
            </px-field>
            <px-field label="Hero feature 3">
              <template #default="{ id }"><px-input :id="id" v-model="setting.login_hero_feature_3" placeholder="Advanced reporting & analytics" /></template>
            </px-field>
            <px-field label="Sign in button text">
              <template #default="{ id }"><px-input :id="id" v-model="setting.login_btn_text" placeholder="Sign in" /></template>
            </px-field>
            <px-field label="Login footer text">
              <template #default="{ id }"><px-input :id="id" v-model="setting.login_footer_text" placeholder="Leave empty to use app name" /></template>
            </px-field>
          </div>

          <template #footer>
            <px-button variant="primary" icon="check" type="submit" @click="Submit_Setting">{{ $t('submit') }}</px-button>
          </template>
        </px-card>
      </form>
    </validation-observer>
  </div>
</template>

<script>
import { mapActions } from "vuex";
import NProgress from "nprogress";
import PxPageHeader from "@/components/px-next/PxPageHeader.vue";
import PxButton from "@/components/px-next/PxButton.vue";
import PxCard from "@/components/px-next/PxCard.vue";
import PxField from "@/components/px-next/PxField.vue";
import PxInput from "@/components/px-next/PxInput.vue";
import PxCheck from "@/components/px-next/PxCheck.vue";

export default {
  metaInfo: {
    title: "Appearance Settings"
  },
  components: { PxPageHeader, PxButton, PxCard, PxField, PxInput, PxCheck },
  data() {
    return {
      isLoading: true,
      data: new FormData(),
      setting: {
        logo: "",
        favicon: "",
        footer: "",
        app_name: "",
        page_title_suffix: "",
        developed_by: "",
        customize_button_visible: true,
        hide_site_name: false,
        login_hero_title: "",
        login_hero_subtitle: "",
        login_panel_title: "",
        login_panel_subtitle: "",
        login_hero_badge: "",
        login_hero_feature_1: "",
        login_hero_feature_2: "",
        login_hero_feature_3: "",
        login_btn_text: "",
        login_footer_text: "",
      },
    };
  },

  methods: {
    ...mapActions(["refreshUserPermissions"]),

    syncValidators() {
      this.$nextTick(() => {
        const map = {
          appNameProvider: "app_name",
          titleSuffixProvider: "page_title_suffix",
          developedByProvider: "developed_by",
          footerProvider: "footer"
        };
        Object.keys(map).forEach(ref => {
          const p = this.$refs[ref];
          if (p && p.syncValue) p.syncValue(this.setting[map[ref]]);
        });
      });
    },

    Submit_Setting() {
      this.$refs.form_setting.validate().then(success => {
        if (!success) {
          this.makeToast(
            "danger",
            this.$t("Please_fill_the_form_correctly"),
            this.$t("Failed")
          );
        } else {
          this.Update_Settings();
        }
      });
    },

    makeToast(variant, msg, title) {
      this.$root.$bvToast.toast(msg, {
        title: title,
        variant: variant,
        solid: true
      });
    },

    getValidationState({ dirty, validated, valid = null }) {
      return dirty || validated ? valid : null;
    },

    async onFileSelected(e) {
      const { valid } = await this.$refs.Logo.validate(e);
      if (valid) {
        this.setting.logo = e.target.files[0];
      } else {
        this.setting.logo = "";
      }
    },

    async onFaviconSelected(e) {
      const { valid } = await this.$refs.Favicon.validate(e);
      if (valid) {
        this.setting.favicon = e.target.files[0];
      } else {
        this.setting.favicon = "";
      }
    },

    Update_Settings() {
      NProgress.start();
      NProgress.set(0.1);
      var self = this;
      self.data.append("favicon", self.setting.favicon);
      self.data.append("logo", self.setting.logo);
      self.data.append("app_name", self.setting.app_name);
      self.data.append("page_title_suffix", self.setting.page_title_suffix);
      self.data.append("developed_by", self.setting.developed_by);
      self.data.append("footer", self.setting.footer);
      self.data.append("customize_button_visible", self.setting.customize_button_visible ? "1" : "0");
      self.data.append("hide_site_name", self.setting.hide_site_name ? "1" : "0");
      self.data.append("login_hero_title", self.setting.login_hero_title || "");
      self.data.append("login_hero_subtitle", self.setting.login_hero_subtitle || "");
      self.data.append("login_panel_title", self.setting.login_panel_title || "");
      self.data.append("login_panel_subtitle", self.setting.login_panel_subtitle || "");
      self.data.append("login_hero_badge", self.setting.login_hero_badge || "");
      self.data.append("login_hero_feature_1", self.setting.login_hero_feature_1 || "");
      self.data.append("login_hero_feature_2", self.setting.login_hero_feature_2 || "");
      self.data.append("login_hero_feature_3", self.setting.login_hero_feature_3 || "");
      self.data.append("login_btn_text", self.setting.login_btn_text || "");
      self.data.append("login_footer_text", self.setting.login_footer_text || "");
      self.data.append("_method", "put");

      axios
        .post("update_appearance_settings/" + self.setting.id, self.data)
        .then(response => {
          Fire.$emit("Event_Setting");
          this.makeToast(
            "success",
            this.$t("Successfully_Updated"),
            this.$t("Success")
          );
          NProgress.done();
          setTimeout(() => {
            window.location.reload();
          }, 500);
        })
        .catch(error => {
          this.makeToast("danger", this.$t("InvalidData"), this.$t("Failed"));
          NProgress.done();
        });
    },

    Get_Settings() {
      axios
        .get("get_appearance_settings")
        .then(response => {
          this.setting = response.data.settings;
          this.isLoading = false;
          this.syncValidators();
        })
        .catch(error => {
          setTimeout(() => {
            this.isLoading = false;
          }, 500);
        });
    },
  }, //end Methods

  created: function() {
    this.Get_Settings();

    Fire.$on("Event_Setting", () => {
      this.Get_Settings();
    });
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
.pxcfg__file {
  width: 100%; font: inherit; font-size: var(--pxn-fs-sm); color: var(--pxn-ink-2);
  padding: var(--pxn-space-2) 0;
}
.pxcfg__file.is-invalid { color: var(--pxn-danger); }
.pxcfg__toggles { margin-top: var(--pxn-space-5); display: flex; flex-direction: column; gap: var(--pxn-space-3); }
.pxcfg__toggle { display: flex; align-items: flex-start; justify-content: space-between; gap: var(--pxn-space-5); padding: var(--pxn-space-4) var(--pxn-space-5); border: 1px solid var(--pxn-border); border-radius: var(--pxn-radius-md); background: var(--pxn-surface); }
.pxcfg__toggle-title { font-size: var(--pxn-fs-sm); font-weight: var(--pxn-fw-semibold); color: var(--pxn-ink); }
.pxcfg__toggle-hint { margin-top: var(--pxn-space-1); font-size: var(--pxn-fs-xs); color: var(--pxn-ink-3); max-width: 60ch; }
</style>
