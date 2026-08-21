const STYLE_ID = 'prodex-friendly-navigation-v2-style';

function installStyles() {
  if (document.getElementById(STYLE_ID)) return;
  const style = document.createElement('style');
  style.id = STYLE_ID;
  style.textContent = `
    .prodex-nav-absorbed { display:none !important; }
    .prodex-nav-section { padding:9px 18px 5px; font-size:10px; line-height:1.2; font-weight:700; letter-spacing:.055em; text-transform:uppercase; color:#98a2b3; cursor:default; }
    .vertical-sidebar .nav-icon { width:20px !important; height:20px !important; min-width:20px !important; }
    .vertical-sidebar .submenu-icon { width:15px !important; height:15px !important; min-width:15px !important; }
    .vertical-sidebar .nav-link { min-height:46px; }
  `;
  document.head.appendChild(style);
}

export default {
  install() {
    if (typeof window === 'undefined' || window.__prodexFriendlyNavigationV2Installed) return;
    window.__prodexFriendlyNavigationV2Installed = true;
    installStyles();

    // Top-level navigation is owned exclusively by prodex-navigation-v3.js.
    // This plugin previously reorganized the same DOM again from Vue mounted/
    // updated hooks, producing race conditions whenever a submenu was opened.
    // Keep only the shared visual styles; do not mutate menu structure here.
  }
};
