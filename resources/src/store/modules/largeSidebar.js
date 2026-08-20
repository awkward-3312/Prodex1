const SIDEBAR_LAYOUT_VERSION = 'prodex-sidebar-layout-v2';

function resolveSidebarLayout() {
  try {
    const storedVersion = localStorage.getItem('sidebarLayoutVersion');

    // One-time migration: standardize every existing tenant/browser on the
    // current PRODEX vertical navigation. Future tenants also start here.
    if (storedVersion !== SIDEBAR_LAYOUT_VERSION) {
      localStorage.setItem('sidebarLayout', 'vertical');
      localStorage.setItem('sidebarLayoutVersion', SIDEBAR_LAYOUT_VERSION);
      return 'vertical';
    }

    const savedLayout = localStorage.getItem('sidebarLayout');
    return savedLayout === 'horizontal' || savedLayout === 'vertical'
      ? savedLayout
      : 'vertical';
  } catch (e) {
    return 'vertical';
  }
}

const state = {
  sidebarToggleProperties: {
    isSideNavOpen: true,
    isSecondarySideNavOpen: false,
    isActiveSecondarySideNav: false
  },
  sidebarLayout: resolveSidebarLayout(), // 'horizontal' or 'vertical'
  verticalSidebarCollapsed: localStorage.getItem('verticalSidebarCollapsed') === 'true' || false
};

const getters = {
  getSideBarToggleProperties: state => state.sidebarToggleProperties,
  getSidebarLayout: state => state.sidebarLayout,
  getVerticalSidebarCollapsed: state => state.verticalSidebarCollapsed
};

const actions = {
  changeSidebarProperties({commit}) {
    commit("toggleSidebarProperties");
  },
  changeSecondarySidebarProperties({commit}) {
    commit("toggleSecondarySidebarProperties");
  },
  changeSecondarySidebarPropertiesViaMenuItem({commit}, data) {
    commit("toggleSecondarySidebarPropertiesViaMenuItem", data);
  },
  changeSecondarySidebarPropertiesViaOverlay({commit}) {
    commit("toggleSecondarySidebarPropertiesViaOverlay");
  },
  setSidebarLayout({commit}, layout) {
    commit("setSidebarLayout", layout);
  },
  setVerticalSidebarCollapsed({commit}, collapsed) {
    commit("setVerticalSidebarCollapsed", collapsed);
  }
};

const mutations = {
  toggleSidebarProperties: state =>
    (state.sidebarToggleProperties.isSideNavOpen = !state
      .sidebarToggleProperties.isSideNavOpen),

  toggleSecondarySidebarProperties: state =>
    (state.sidebarToggleProperties.isSecondarySideNavOpen = !state
      .sidebarToggleProperties.isSecondarySideNavOpen),
  toggleSecondarySidebarPropertiesViaMenuItem(state, data) {
    state.sidebarToggleProperties.isSecondarySideNavOpen = data;
    state.sidebarToggleProperties.isActiveSecondarySideNav = data;
  },
  toggleSecondarySidebarPropertiesViaOverlay(state) {
    state.sidebarToggleProperties.isSecondarySideNavOpen = !state
      .sidebarToggleProperties.isSecondarySideNavOpen;
  },
  setSidebarLayout(state, layout) {
    state.sidebarLayout = layout;
    localStorage.setItem('sidebarLayout', layout);
    localStorage.setItem('sidebarLayoutVersion', SIDEBAR_LAYOUT_VERSION);
  },
  setVerticalSidebarCollapsed(state, collapsed) {
    state.verticalSidebarCollapsed = collapsed;
    localStorage.setItem('verticalSidebarCollapsed', collapsed);
  }
};

export default {
  state,
  getters,
  actions,
  mutations
};
