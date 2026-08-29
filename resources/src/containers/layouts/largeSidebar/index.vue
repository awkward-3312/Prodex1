<template>
  <div class="app-admin-wrap layout-sidebar-large clearfix" :class="{ 
    'vertical-layout': getSidebarLayout === 'vertical',
    'vertical-collapsed': getSidebarLayout === 'vertical' && getVerticalSidebarCollapsed
  }">
    <!-- Conditional Top Navigation -->
    <vertical-top-nav v-if="getSidebarLayout === 'vertical'" />
    <top-nav v-else />

    <!-- Conditional Sidebar Rendering -->
    <vertical-sidebar v-if="getSidebarLayout === 'vertical'" />
    <sidebar v-else />

    <main :class="{ 'with-vertical-sidebar': getSidebarLayout === 'vertical' }">
      <div
        :class="{ 
          'sidenav-open': getSideBarToggleProperties.isSideNavOpen && getSidebarLayout !== 'vertical',
          'with-vertical-topnav': getSidebarLayout === 'vertical'
        }"
        class="main-content-wrap d-flex flex-column flex-grow-1"
      >
        <transition name="page" mode="out-in">
          <router-view />
        </transition>

        <div class="flex-grow-1"></div>
        <appFooter />
      </div>
    </main>
  </div>
</template>

<script>
import Sidebar from "./Sidebar";
import VerticalSidebar from "./VerticalSidebar";
import TopNav from "./TopNav";
import VerticalTopNav from "./VerticalTopNav";
import appFooter from "../common/footer";
import { mapGetters, mapActions } from "vuex";

export default {
  components: {
    Sidebar,
    VerticalSidebar,
    TopNav,
    VerticalTopNav,
    appFooter,
  },
  data() {
    return {};
  },
  computed: {
    ...mapGetters(["getSideBarToggleProperties", "getSidebarLayout", "getVerticalSidebarCollapsed"]),
  },
  methods: {},
};
</script>
<style scoped>
/* Layout adjustments for vertical sidebar.
   1024px is the shell breakpoint (see prodex/_breakpoints.scss $px-bp-lg and the
   matching `window.innerWidth <= 1024` check in VerticalSidebar.vue): at or
   below it the sidebar becomes an off-canvas overlay, so the content margin
   drops to 0. Keep the LTR and RTL rules mirrored. */
.vertical-layout main.with-vertical-sidebar {
  margin-left: 260px;
  transition: margin 0.3s ease;
}

.vertical-layout.vertical-collapsed main.with-vertical-sidebar {
  margin-left: 0;
}

/* Adjust content for vertical topnav */
.with-vertical-topnav {
  /* padding-top removed for flush layout */
}

/* Tablet & below: sidebar is an overlay, content spans full width */
@media (max-width: 1024px) {
  .vertical-layout main.with-vertical-sidebar,
  html[dir="rtl"] .vertical-layout main.with-vertical-sidebar {
    margin-left: 0;
    margin-right: 0;
  }
}

/* RTL: mirror of the LTR rules above */
html[dir="rtl"] .vertical-layout main.with-vertical-sidebar {
  margin-left: 0;
  margin-right: 260px;
}

html[dir="rtl"] .vertical-layout.vertical-collapsed main.with-vertical-sidebar {
  margin-right: 0;
}
</style>