<template>
  <div class="footer_wrap">
    <div class="flex-grow-1"></div>
    <div class="app-footer" v-if="currentUser">
      <div class="row">
        <div class="col-12 col-md-9">
          <p><strong>PRODEX</strong></p>
        </div>
      </div>
      <div class="footer-bottom border-top pt-3 d-flex flex-column flex-sm-row align-items-center w-100">
        <div class="d-flex align-items-center">
          <img
            v-if="currentUser.logo"
            class="logo"
            :src="$imgUrl('settings', currentUser.logo)"
            alt=""
            width="60"
            height="60"
          >
          <img
            v-else
            class="logo"
            :src="$imgUrl('settings', 'logo-default.png')"
            alt=""
            width="60"
            height="60"
          >
          <div>
            <p class="m-0">&copy; {{ new Date().getFullYear() }} Desarrollado por PRODEX</p>
            <p class="m-0">All rights reserved<span v-if="installedVersion"> - v{{ installedVersion }}</span></p>
          </div>
        </div>
      </div>
    </div>
  </div>
</template>

<script>
import { mapGetters } from "vuex";

export default {
  data() {
    return {
      installedVersion: "",
    };
  },
  computed: {
    ...mapGetters(["currentUser"]),
  },
  mounted() {
    this.loadInstalledVersion();
  },
  methods: {
    async loadInstalledVersion() {
      try {
        const response = await axios.get("/system/version", {
          meta: { skipErrorRedirect: true },
        });
        this.installedVersion = response.data && response.data.version
          ? String(response.data.version).trim()
          : "";
      } catch (error) {
        this.installedVersion = "";
      }
    },
  },
};
</script>

<style lang="scss" scoped>
</style>
