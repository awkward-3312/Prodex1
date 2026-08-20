const STYLE_ID = 'prodex-friendly-navigation-v2-style';

function visible(el) {
  if (!el) return false;
  if (el.classList && el.classList.contains('prodex-nav-absorbed')) return false;
  return el.style.display !== 'none';
}

function setLabel(node, label, selector) {
  if (!node) return;
  const text = node.querySelector(selector);
  if (text) text.textContent = label;
}

function routeClone(anchor, vm, mode) {
  if (!anchor) return null;
  const clone = anchor.cloneNode(true);
  const href = clone.getAttribute('href');
  clone.classList.remove('router-link-active', 'router-link-exact-active', 'open');
  clone.removeAttribute('aria-current');
  if (mode === 'vertical') clone.className = 'submenu-link';
  else clone.classList.add('nav-item-hold');

  if (href && href.startsWith('/app/') && vm.$router) {
    clone.addEventListener('click', event => {
      event.preventDefault();
      vm.$router.push(href).catch(() => {});
    });
  }
  return clone;
}

function addSection(host, title, anchors, vm, mode) {
  if (!host) return;
  const usable = anchors.filter(Boolean);
  if (!usable.length) return;
  const key = 'prodex-v2-' + title.toLowerCase().normalize('NFD').replace(/[\u0300-\u036f]/g, '').replace(/[^a-z0-9]+/g, '-');
  if (host.querySelector('.' + key)) return;

  const heading = document.createElement('li');
  heading.className = `prodex-nav-section ${key}`;
  heading.textContent = title;
  host.appendChild(heading);

  usable.forEach(anchor => {
    const clone = routeClone(anchor, vm, mode);
    if (!clone) return;
    const li = document.createElement('li');
    li.className = mode === 'vertical' ? 'submenu-item prodex-nav-clone' : 'nav-item prodex-nav-clone';
    li.appendChild(clone);
    host.appendChild(li);
  });
}

function topLi(anchor, list) {
  if (!anchor || !list) return null;
  let li = anchor.closest('li');
  while (li && li.parentElement !== list) li = li.parentElement && li.parentElement.closest('li');
  return li && li.parentElement === list ? li : null;
}

function findVerticalTop(list, prefix) {
  const anchor = Array.from(list.querySelectorAll('a[href]')).find(a => String(a.getAttribute('href') || '').startsWith(prefix));
  return topLi(anchor, list);
}

function directAnchor(li) {
  return li && visible(li) ? li.querySelector(':scope > a[href]') : null;
}

function submenuAnchors(li) {
  if (!li || !visible(li)) return [];
  return Array.from(li.querySelectorAll(':scope > .submenu > li > a[href]'));
}

function childAnchors(child, parentLi) {
  if (!child || !visible(parentLi)) return [];
  return Array.from(child.children).map(li => li.querySelector(':scope > a[href]')).filter(Boolean);
}

function organizeClassic(root, vm) {
  const list = root.querySelector('.navigation-left');
  const secondary = root.querySelector('.sidebar-left-secondary');
  if (!list || !secondary) return false;

  const inventory = list.querySelector('li[data-item="products"]');
  const operations = list.querySelector('li[data-item="sales"]');
  if (!inventory || !operations) return false;

  setLabel(list.querySelector('li[data-item="billing"]'), 'Plan y facturación', '.nav-text');
  setLabel(inventory, 'Inventario', '.nav-text');
  setLabel(operations, 'Operaciones', '.nav-text');
  setLabel(list.querySelector('li[data-item="People"]'), 'Clientes y proveedores', '.nav-text');
  setLabel(list.querySelector('li[data-item="User_Management"]'), 'Usuarios y acceso', '.nav-text');

  const inventoryChild = secondary.querySelector('.childNav[data-parent="products"]');
  const operationsChild = secondary.querySelector('.childNav[data-parent="sales"]');
  if (!inventoryChild || !operationsChild) return false;

  const adjustmentTop = list.querySelector('li[data-item="adjustments"]');
  const transferTop = list.querySelector('li[data-item="transfers"]');
  const damageTop = list.querySelector('li[data-item="damages"]');
  addSection(inventoryChild, 'Movimientos de inventario', [
    ...childAnchors(secondary.querySelector('.childNav[data-parent="adjustments"]'), adjustmentTop),
    ...childAnchors(secondary.querySelector('.childNav[data-parent="transfers"]'), transferTop),
    ...childAnchors(secondary.querySelector('.childNav[data-parent="damages"]'), damageTop),
  ], vm, 'classic');

  const purchasesTop = list.querySelector('li[data-item="purchases"]');
  const quotationsTop = list.querySelector('li[data-item="quotations"]');
  addSection(operationsChild, 'Compras', childAnchors(secondary.querySelector('.childNav[data-parent="purchases"]'), purchasesTop), vm, 'classic');
  addSection(operationsChild, 'Cotizaciones', childAnchors(secondary.querySelector('.childNav[data-parent="quotations"]'), quotationsTop), vm, 'classic');

  const saleReturn = list.querySelector('li[data-item="sale_return"]');
  const purchaseReturn = list.querySelector('li[data-item="purchase_return"]');
  const promoAnchor = list.querySelector('a[href="/app/promotions"]');
  const promoTop = promoAnchor && promoAnchor.closest('li.nav-item');
  addSection(operationsChild, 'Devoluciones y promociones', [
    directAnchor(saleReturn), directAnchor(purchaseReturn), visible(promoTop) ? promoAnchor : null,
  ], vm, 'classic');

  [adjustmentTop, transferTop, damageTop, purchasesTop, quotationsTop, saleReturn, purchaseReturn, promoTop]
    .filter(el => el && visible(el))
    .forEach(el => el.classList.add('prodex-nav-absorbed'));

  root.dataset.prodexFriendlyOrganized = '2';
  return true;
}

function organizeVertical(root, vm) {
  const list = root.querySelector('.vertical-nav-menu > .nav-list');
  if (!list) return false;

  const billing = findVerticalTop(list, '/app/billing/');
  const inventory = findVerticalTop(list, '/app/products/');
  const operations = findVerticalTop(list, '/app/sales/');
  if (!inventory || !operations) return false;

  setLabel(billing, 'Plan y facturación', ':scope > .nav-link .nav-text');
  setLabel(inventory, 'Inventario', ':scope > .nav-link .nav-text');
  setLabel(operations, 'Operaciones', ':scope > .nav-link .nav-text');
  setLabel(findVerticalTop(list, '/app/People/'), 'Clientes y proveedores', ':scope > .nav-link .nav-text');
  setLabel(findVerticalTop(list, '/app/User_Management/'), 'Usuarios y acceso', ':scope > .nav-link .nav-text');

  const inventorySub = inventory.querySelector(':scope > .submenu');
  const operationsSub = operations.querySelector(':scope > .submenu');
  if (!inventorySub || !operationsSub) return false;

  const adjustments = findVerticalTop(list, '/app/adjustments/');
  const transfers = findVerticalTop(list, '/app/transfers/');
  const damages = findVerticalTop(list, '/app/damages/');
  addSection(inventorySub, 'Movimientos de inventario', [
    ...submenuAnchors(adjustments), ...submenuAnchors(transfers), ...submenuAnchors(damages),
  ], vm, 'vertical');

  const purchases = findVerticalTop(list, '/app/purchases/');
  const quotations = findVerticalTop(list, '/app/quotations/');
  addSection(operationsSub, 'Compras', submenuAnchors(purchases), vm, 'vertical');
  addSection(operationsSub, 'Cotizaciones', submenuAnchors(quotations), vm, 'vertical');

  const saleReturn = findVerticalTop(list, '/app/sale_return/');
  const purchaseReturn = findVerticalTop(list, '/app/purchase_return/');
  const promotions = findVerticalTop(list, '/app/promotions');
  addSection(operationsSub, 'Devoluciones y promociones', [
    directAnchor(saleReturn), directAnchor(purchaseReturn), directAnchor(promotions),
  ], vm, 'vertical');

  [adjustments, transfers, damages, purchases, quotations, saleReturn, purchaseReturn, promotions]
    .filter(el => el && visible(el))
    .forEach(el => el.classList.add('prodex-nav-absorbed'));

  root.dataset.prodexFriendlyOrganized = '2';
  return true;
}

function installStyles() {
  if (document.getElementById(STYLE_ID)) return;
  const style = document.createElement('style');
  style.id = STYLE_ID;
  style.textContent = `
    .prodex-nav-absorbed { display:none !important; }
    .prodex-nav-section { padding:9px 18px 5px; font-size:10px; line-height:1.2; font-weight:700; letter-spacing:.055em; text-transform:uppercase; color:#98a2b3; cursor:default; }
    .sidebar-left-secondary .prodex-nav-section { padding-left:16px; }
    .vertical-sidebar .prodex-nav-section { padding-left:38px; }
    .prodex-nav-clone a, .prodex-nav-clone .submenu-link { cursor:pointer; }

    /* Sidebar 1: keep the visual rail, but use less space. */
    .layout-sidebar-large .sidebar-left .navigation-left .nav-item .nav-item-hold { padding:18px 0 !important; }
    .layout-sidebar-large .sidebar-left .navigation-left .nav-item .nav-item-hold .nav-icon,
    .layout-sidebar-large .sidebar-left .navigation-left .nav-item .nav-item-hold .feather { width:24px !important; height:24px !important; font-size:24px !important; margin-bottom:4px !important; }
    .layout-sidebar-large .sidebar-left .navigation-left .nav-item .nav-item-hold .nav-text { font-size:12px !important; line-height:1.25; }
    .layout-sidebar-large .sidebar-left-secondary { width:195px !important; }
    .layout-sidebar-large .sidebar-left-secondary .childNav li.nav-item a { padding:10px 16px !important; font-size:12.5px !important; }
    .layout-sidebar-large .sidebar-left-secondary .childNav li.nav-item a .nav-icon { font-size:16px !important; width:16px !important; height:16px !important; }

    /* Sidebar 2: same hierarchy, compact but still readable. */
    .vertical-sidebar .nav-icon { width:20px !important; height:20px !important; min-width:20px !important; }
    .vertical-sidebar .submenu-icon { width:15px !important; height:15px !important; min-width:15px !important; }
    .vertical-sidebar .nav-link { min-height:46px; }
  `;
  document.head.appendChild(style);
}

export default {
  install(Vue) {
    if (typeof window === 'undefined' || window.__prodexFriendlyNavigationV2Installed) return;
    window.__prodexFriendlyNavigationV2Installed = true;
    installStyles();

    const schedule = vm => {
      const attempts = [0, 60, 180, 450, 900, 1600];
      attempts.forEach(delay => window.setTimeout(() => {
        const root = vm && vm.$el;
        if (!root || !root.classList) return;
        if (root.dataset.prodexFriendlyOrganized === '2') return;
        if (root.classList.contains('side-content-wrap')) organizeClassic(root, vm);
        if (root.classList.contains('vertical-sidebar-wrapper')) organizeVertical(root, vm);
      }, delay));
    };

    Vue.mixin({
      mounted() {
        const root = this.$el;
        if (!root || !root.classList) return;
        if (root.classList.contains('side-content-wrap') || root.classList.contains('vertical-sidebar-wrapper')) schedule(this);
      },
      updated() {
        const root = this.$el;
        if (!root || !root.classList || root.dataset.prodexFriendlyOrganized === '2') return;
        if (root.classList.contains('side-content-wrap') || root.classList.contains('vertical-sidebar-wrapper')) schedule(this);
      }
    });
  }
};
