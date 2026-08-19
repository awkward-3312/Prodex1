<template>
  <div class="footer_wrap">
    <div class="flex-grow-1"></div>
    <div class="app-footer" v-if="currentUser">
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
            <p class="m-0"><strong>PRODEX</strong> &copy; {{ new Date().getFullYear() }} Desarrollado por PRODEX</p>
          </div>
        </div>
      </div>
    </div>
  </div>
</template>

<script>
import { mapGetters } from "vuex";

export default {
  computed: {
    ...mapGetters(["currentUser"]),
  },
};
</script>

<style lang="scss" scoped>
</style>
