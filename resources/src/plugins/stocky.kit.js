import BootstrapVue from 'bootstrap-vue/dist/bootstrap-vue.esm';
import VueGoodTablePlugin from "vue-good-table";
import Meta from "vue-meta";
import "./../assets/styles/sass/themes/lite-purple.scss";
import "./sweetalert2.js";
import VueHtmlToPaper from 'vue-html-to-paper';

const options = {
  name: '_blank',
  specs: ['fullscreen=yes', 'titlebar=yes', 'scrollbars=yes'],
  styles: [
    'https://maxcdn.bootstrapcdn.com/bootstrap/4.0.0/css/bootstrap.min.css',
    'https://unpkg.com/kidlat-css/css/kidlat.css',
  ],
  timeout: 1000,
  autoClose: true,
  windowTitle: window.document.title,
};

function installReceiptPresentationEnhancer(Vue) {
  if (typeof window === 'undefined' || window.__prodexReceiptPresentationEnhancerInstalled) return;
  window.__prodexReceiptPresentationEnhancerInstalled = true;

  const fields = [
    ['receipt_paper_size', 'Ancho del papel', [[58, '58 mm'], [80, '80 mm'], [88, '88 mm']]],
    ['logo_size', 'Tamaño del logo', [[40, '40 px'], [50, '50 px'], [60, '60 px'], [70, '70 px'], [80, '80 px'], [100, '100 px'], [120, '120 px']]],
    ['receipt_header_alignment', 'Cabecera', [['left', 'Izquierda'], ['center', 'Centro'], ['right', 'Derecha']]],
    ['receipt_fiscal_alignment', 'Información fiscal', [['left', 'Izquierda'], ['center', 'Centro'], ['right', 'Derecha']]],
    ['receipt_customer_alignment', 'Datos del cliente', [['left', 'Izquierda'], ['center', 'Centro'], ['right', 'Derecha']]],
    ['receipt_items_alignment', 'Productos', [['left', 'Izquierda'], ['center', 'Centro'], ['right', 'Derecha']]],
    ['receipt_totals_alignment', 'Totales', [['left', 'Izquierda'], ['center', 'Centro'], ['right', 'Derecha']]],
    ['receipt_footer_alignment', 'Pie de factura', [['left', 'Izquierda'], ['center', 'Centro'], ['right', 'Derecha']]],
    ['receipt_qr_alignment', 'Código QR', [['left', 'Izquierda'], ['center', 'Centro'], ['right', 'Derecha']]],
    ['receipt_font_size', 'Tamaño de letra', [[8, '8 px'], [9, '9 px'], [10, '10 px'], [11, '11 px'], [12, '12 px'], [13, '13 px'], [14, '14 px']]],
    ['receipt_density', 'Espaciado', [['compact', 'Compacto'], ['normal', 'Normal'], ['wide', 'Amplio']]],
    ['receipt_separator', 'Separadores', [['none', 'Sin línea'], ['solid', 'Línea sólida'], ['dotted', 'Punteado'], ['dashed', 'Guiones']]],
  ];

  const defaults = {
    receipt_paper_size: 80,
    logo_size: 60,
    receipt_header_alignment: 'center',
    receipt_fiscal_alignment: 'center',
    receipt_customer_alignment: 'left',
    receipt_items_alignment: 'left',
    receipt_totals_alignment: 'right',
    receipt_footer_alignment: 'center',
    receipt_qr_alignment: 'center',
    receipt_font_size: 10,
    receipt_density: 'normal',
    receipt_separator: 'dotted',
  };

  const alignment = (value, fallback) => ['left', 'center', 'right'].includes(String(value || '')) ? value : fallback;
  const boolSetting = (value, fallback = true) => value === undefined || value === null ? fallback : (value === true || value === 1 || value === '1');

  const receiptStyle = settings => {
    const paper = [58, 80, 88].includes(Number(settings.receipt_paper_size)) ? Number(settings.receipt_paper_size) : 80;
    const density = ['compact', 'normal', 'wide'].includes(String(settings.receipt_density || '')) ? settings.receipt_density : 'normal';
    const separatorName = ['none', 'solid', 'dotted', 'dashed'].includes(String(settings.receipt_separator || '')) ? settings.receipt_separator : 'dotted';
    return {
      paper,
      font: Math.max(8, Math.min(14, Number(settings.receipt_font_size || 10))),
      logo: Math.max(20, Math.min(200, Number(settings.logo_size || 60))),
      gap: density === 'compact' ? 3 : density === 'wide' ? 8 : 5,
      separator: separatorName === 'none' ? '0' : `1px ${separatorName} #333`,
      header: alignment(settings.receipt_header_alignment, 'center'),
      fiscal: alignment(settings.receipt_fiscal_alignment, 'center'),
      customer: alignment(settings.receipt_customer_alignment, 'left'),
      items: alignment(settings.receipt_items_alignment, 'left'),
      totals: alignment(settings.receipt_totals_alignment, 'right'),
      footer: alignment(settings.receipt_footer_alignment, 'center'),
      qr: alignment(settings.receipt_qr_alignment, 'center'),
    };
  };

  const renderFiscalPreview = vm => {
    const target = vm.__receiptFiscalPreview;
    if (!target || !vm.pos_settings) return;
    const s = receiptStyle(vm.pos_settings);
    const logo = boolSetting(vm.pos_settings.show_logo, true)
      ? `<div style="text-align:${s.header};margin-bottom:${s.gap}px"><div style="display:inline-flex;width:${s.logo}px;height:${Math.max(32, Math.round(s.logo * 0.55))}px;border:1px dashed #bbb;align-items:center;justify-content:center;font-size:9px">LOGO</div></div>`
      : '';
    const line = `<div style="border-top:${s.separator};margin:${s.gap}px 0"></div>`;

    target.style.maxWidth = s.paper === 58 ? '280px' : s.paper === 88 ? '390px' : '350px';
    target.style.fontSize = `${s.font}px`;
    target.innerHTML = `
      <div style="line-height:1.3;color:#111">
        ${logo}
        <div style="text-align:${s.header}">
          <strong style="font-size:125%">EMPRESA DE DEMOSTRACIÓN</strong><br>
          RTN: 0801-1999-123456<br>
          San Pedro Sula, Cortés<br>
          Tel. +504 2216-1950
        </div>
        ${line}
        <div style="text-align:${s.fiscal}">
          <strong style="font-size:120%">FACTURA CONTADO</strong><br>
          <strong>000-001-01-00000001</strong><br>
          CAI: 434578-9E863C-C754EB-83BE03-0909CE-5B<br>
          Rango: 000-001-01-00000001 al 000-001-01-00000500<br>
          Fecha límite: 31/12/2026
        </div>
        ${line}
        <div style="text-align:${s.customer}">
          <strong>Cliente:</strong> CONSUMIDOR FINAL<br>
          <strong>RTN:</strong> CF<br>
          Fecha: 20/08/2026 12:00 &nbsp; Cajero: Usuario POS
        </div>
        ${line}
        <div style="text-align:${s.items}">
          <div style="display:flex;justify-content:space-between;gap:8px"><strong>Producto de demostración A</strong><strong>L 230.00</strong></div>
          <div>2 x L 100.00 &nbsp; ISV 15%</div>
          <div style="display:flex;justify-content:space-between;gap:8px;margin-top:${s.gap}px"><strong>Producto exento</strong><strong>L 50.00</strong></div>
          <div>1 x L 50.00 &nbsp; EXENTO</div>
        </div>
        ${line}
        <div style="text-align:${s.totals}">
          <div style="display:flex;justify-content:space-between"><span>Subtotal</span><span>L 250.00</span></div>
          <div style="display:flex;justify-content:space-between"><span>Importe exento</span><span>L 50.00</span></div>
          <div style="display:flex;justify-content:space-between"><span>Importe gravado 15%</span><span>L 200.00</span></div>
          <div style="display:flex;justify-content:space-between"><span>ISV 15%</span><span>L 30.00</span></div>
          <div style="display:flex;justify-content:space-between;font-size:115%;font-weight:900"><span>TOTAL</span><span>L 280.00</span></div>
          <div style="display:flex;justify-content:space-between"><span>Efectivo</span><span>L 300.00</span></div>
          <div style="display:flex;justify-content:space-between"><span>Cambio</span><span>L 20.00</span></div>
        </div>
        ${line}
        <div style="text-align:${s.footer}">DOSCIENTOS OCHENTA LEMPIRAS CON 00/100<br>Original: Cliente</div>
        <div style="text-align:${s.qr};margin-top:${s.gap}px"><span style="display:inline-flex;width:72px;height:72px;border:1px solid #333;align-items:center;justify-content:center;font-size:9px">QR SAR</span></div>
      </div>`;
  };

  Vue.mixin({
    mounted() {
      const title = this.$options && this.$options.metaInfo && this.$options.metaInfo.title;
      if (title !== 'POS Receipt' || !this.pos_settings || this.__receiptPresentationMounted) return;
      this.__receiptPresentationMounted = true;

      Object.keys(defaults).forEach(key => {
        if (this.pos_settings[key] === undefined || this.pos_settings[key] === null || this.pos_settings[key] === '') {
          this.$set(this.pos_settings, key, defaults[key]);
        }
      });

      this.$nextTick(() => {
        const submitButton = this.$el && this.$el.querySelector('button[type="submit"]');
        const row = submitButton && submitButton.closest('.row');
        if (!row) return;

        const host = document.createElement('div');
        host.className = 'col-md-12 mt-4 mb-2 prodex-receipt-presentation';
        host.setAttribute('data-prodex-no-legacy-translate', '1');
        host.innerHTML = `
          <hr class="my-4">
          <h6 class="mb-2">Diseño de la factura / recibo</h6>
          <p class="text-muted mb-2">Estas opciones cambian únicamente la presentación. Los datos fiscales SAR obligatorios permanecen en la factura.</p>
          <div class="alert alert-light border mb-3" role="alert">
            <strong>Logo de la factura:</strong> se utiliza el logo configurado en <strong>Ajustes del sistema → Configuración de apariencia → Cambiar logo</strong>. No necesitas cargar otro logo aquí.
          </div>
          <div class="row prodex-receipt-controls"></div>
          <div class="card mt-3 mb-3">
            <div class="card-body">
              <div class="d-flex justify-content-between align-items-center mb-2">
                <h6 class="mb-0">Vista previa fiscal SAR (Honduras)</h6>
                <small class="text-muted">Ejemplo visual</small>
              </div>
              <p class="text-muted small mb-3">La factura real toma CAI, RTN, rango, fecha límite, cliente, productos e impuestos del documento fiscal SAR emitido. Esta vista previa sirve únicamente para ajustar la presentación.</p>
              <div class="prodex-fiscal-preview mx-auto border p-3 bg-white"></div>
            </div>
          </div>`;

        const controlsRow = host.querySelector('.prodex-receipt-controls');
        fields.forEach(([key, label, choices]) => {
          const col = document.createElement('div');
          col.className = 'col-md-6 mb-3';
          const formGroup = document.createElement('div');
          formGroup.className = 'form-group mb-0';
          const lab = document.createElement('label');
          lab.textContent = label;
          const select = document.createElement('select');
          select.className = 'form-control';
          choices.forEach(([value, text]) => {
            const option = document.createElement('option');
            option.value = String(value);
            option.textContent = text;
            select.appendChild(option);
          });
          select.value = String(this.pos_settings[key] == null ? defaults[key] : this.pos_settings[key]);
          select.addEventListener('change', () => {
            const numeric = key === 'receipt_font_size' || key === 'receipt_paper_size' || key === 'logo_size';
            this.pos_settings[key] = numeric ? Number(select.value) : select.value;
            renderFiscalPreview(this);
          });
          formGroup.appendChild(lab);
          formGroup.appendChild(select);
          col.appendChild(formGroup);
          controlsRow.appendChild(col);
        });

        row.insertBefore(host, submitButton.closest('[class*="col-"]'));
        this.__receiptPresentationHost = host;
        this.__receiptFiscalPreview = host.querySelector('.prodex-fiscal-preview');
        renderFiscalPreview(this);

        const watchKeys = [
          'receipt_layout', 'receipt_paper_size', 'logo_size', 'show_logo',
          'receipt_header_alignment', 'receipt_fiscal_alignment', 'receipt_customer_alignment',
          'receipt_items_alignment', 'receipt_totals_alignment', 'receipt_footer_alignment',
          'receipt_qr_alignment', 'receipt_font_size', 'receipt_density', 'receipt_separator'
        ];
        this.__receiptPresentationUnwatch = watchKeys.map(key => this.$watch(`pos_settings.${key}`, () => renderFiscalPreview(this)));
      });

      const originalUpdate = this.Update_Pos_Settings && this.Update_Pos_Settings.bind(this);
      if (originalUpdate) {
        this.Update_Pos_Settings = () => {
          const payload = { receipt_layout: this.pos_settings.receipt_layout };
          Object.keys(defaults).forEach(key => { payload[key] = this.pos_settings[key]; });
          axios.put('pos_settings/' + this.pos_settings.id, payload)
            .then(() => originalUpdate())
            .catch(() => originalUpdate());
        };
      }
    },

    beforeDestroy() {
      if (Array.isArray(this.__receiptPresentationUnwatch)) {
        this.__receiptPresentationUnwatch.forEach(unwatch => { if (typeof unwatch === 'function') unwatch(); });
      }
      if (this.__receiptPresentationHost && this.__receiptPresentationHost.parentNode) {
        this.__receiptPresentationHost.parentNode.removeChild(this.__receiptPresentationHost);
      }
    }
  });
}

function installFriendlyNavigation(Vue) {
  if (typeof window === 'undefined' || window.__prodexFriendlyNavigationInstalled) return;
  window.__prodexFriendlyNavigationInstalled = true;

  if (!document.getElementById('prodex-friendly-navigation-style')) {
    const style = document.createElement('style');
    style.id = 'prodex-friendly-navigation-style';
    style.textContent = `
      .prodex-nav-absorbed { display: none !important; }
      .prodex-nav-section { padding: 9px 18px 5px; font-size: 10px; line-height: 1.2; font-weight: 700; letter-spacing: .055em; text-transform: uppercase; color: #98a2b3; cursor: default; }
      .sidebar-left-secondary .prodex-nav-section { padding-left: 20px; }
      .vertical-sidebar .prodex-nav-section { padding-left: 42px; }
      .prodex-nav-clone a, .prodex-nav-clone .submenu-link { cursor: pointer; }
      .vertical-sidebar .nav-icon { width: 20px !important; height: 20px !important; min-width: 20px !important; }
      .vertical-sidebar .submenu-icon { width: 15px !important; height: 15px !important; min-width: 15px !important; }
      .vertical-sidebar .nav-link { min-height: 46px; }
    `;
    document.head.appendChild(style);
  }

  const setLabel = (node, label, selector) => {
    if (!node) return;
    const text = node.querySelector(selector);
    if (text) text.textContent = label;
  };

  const routerizeClone = (anchor, vm) => {
    if (!anchor) return null;
    const clone = anchor.cloneNode(true);
    const href = clone.getAttribute('href');
    if (href && href.startsWith('/app/') && vm.$router) {
      clone.addEventListener('click', event => {
        event.preventDefault();
        vm.$router.push(href).catch(() => {});
      });
    }
    clone.classList.remove('router-link-active', 'router-link-exact-active', 'open');
    return clone;
  };

  const addSection = (host, title, anchors, vm, mode) => {
    if (!host || !anchors || !anchors.length) return;
    const key = 'prodex-section-' + title.toLowerCase().replace(/[^a-z0-9]+/g, '-');
    if (host.querySelector('.' + key)) return;

    const heading = document.createElement('li');
    heading.className = `prodex-nav-section ${key}`;
    heading.textContent = title;
    host.appendChild(heading);

    anchors.forEach(anchor => {
      const clone = routerizeClone(anchor, vm);
      if (!clone) return;
      const li = document.createElement('li');
      li.className = mode === 'vertical' ? 'submenu-item prodex-nav-clone' : 'nav-item prodex-nav-clone';
      if (mode === 'vertical') clone.className = 'submenu-link';
      else clone.classList.add('nav-item-hold');
      li.appendChild(clone);
      host.appendChild(li);
    });
  };

  const anchorsFromChildNav = child => child
    ? Array.from(child.children).map(li => li.querySelector(':scope > a')).filter(Boolean)
    : [];

  const topLiForAnchor = (anchor, list) => {
    if (!anchor || !list) return null;
    let li = anchor.closest('li');
    while (li && li.parentElement !== list) li = li.parentElement && li.parentElement.closest('li');
    return li && li.parentElement === list ? li : null;
  };

  const findVerticalTop = (list, prefix) => {
    const anchor = Array.from(list.querySelectorAll('a[href]')).find(a => String(a.getAttribute('href') || '').startsWith(prefix));
    return topLiForAnchor(anchor, list);
  };

  const directAnchor = li => li && li.querySelector(':scope > a[href]');
  const submenuAnchors = li => li ? Array.from(li.querySelectorAll(':scope > .submenu > li > a[href]')) : [];

  const organizeClassic = (root, vm) => {
    const list = root.querySelector('.navigation-left');
    const secondary = root.querySelector('.sidebar-left-secondary');
    if (!list || !secondary || root.dataset.prodexFriendlyOrganized === '1') return;

    const inventory = list.querySelector('li[data-item="products"]');
    const operations = list.querySelector('li[data-item="sales"]');
    if (!inventory || !operations) return;

    setLabel(inventory, 'Inventario', '.nav-text');
    setLabel(operations, 'Operaciones', '.nav-text');
    setLabel(list.querySelector('li[data-item="People"]'), 'Clientes y proveedores', '.nav-text');
    setLabel(list.querySelector('li[data-item="User_Management"]'), 'Usuarios y acceso', '.nav-text');

    const inventoryChild = secondary.querySelector('.childNav[data-parent="products"]');
    const operationsChild = secondary.querySelector('.childNav[data-parent="sales"]');

    const adjustmentChild = secondary.querySelector('.childNav[data-parent="adjustments"]');
    const transferChild = secondary.querySelector('.childNav[data-parent="transfers"]');
    const damageChild = secondary.querySelector('.childNav[data-parent="damages"]');
    addSection(inventoryChild, 'Movimientos de inventario', [
      ...anchorsFromChildNav(adjustmentChild),
      ...anchorsFromChildNav(transferChild),
      ...anchorsFromChildNav(damageChild),
    ], vm, 'classic');

    const purchasesChild = secondary.querySelector('.childNav[data-parent="purchases"]');
    const quotationsChild = secondary.querySelector('.childNav[data-parent="quotations"]');
    addSection(operationsChild, 'Compras', anchorsFromChildNav(purchasesChild), vm, 'classic');
    addSection(operationsChild, 'Cotizaciones', anchorsFromChildNav(quotationsChild), vm, 'classic');

    const directOperations = ['sale_return', 'purchase_return'].map(key => directAnchor(list.querySelector(`li[data-item="${key}"]`))).filter(Boolean);
    const promo = list.querySelector('a[href="/app/promotions"]');
    if (promo) directOperations.push(promo);
    addSection(operationsChild, 'Devoluciones y promociones', directOperations, vm, 'classic');

    ['adjustments', 'purchases', 'sale_return', 'quotations', 'purchase_return', 'transfers', 'damages'].forEach(key => {
      const li = list.querySelector(`li[data-item="${key}"]`);
      if (li) li.classList.add('prodex-nav-absorbed');
    });
    const promoLi = promo && promo.closest('li.nav-item');
    if (promoLi) promoLi.classList.add('prodex-nav-absorbed');

    root.dataset.prodexFriendlyOrganized = '1';
  };

  const organizeVertical = (root, vm) => {
    const list = root.querySelector('.vertical-nav-menu > .nav-list');
    if (!list || root.dataset.prodexFriendlyOrganized === '1') return;

    const inventory = findVerticalTop(list, '/app/products/');
    const operations = findVerticalTop(list, '/app/sales/');
    if (!inventory || !operations) return;

    setLabel(inventory, 'Inventario', ':scope > .nav-link .nav-text');
    setLabel(operations, 'Operaciones', ':scope > .nav-link .nav-text');

    const people = findVerticalTop(list, '/app/People/');
    const users = findVerticalTop(list, '/app/User_Management/');
    setLabel(people, 'Clientes y proveedores', ':scope > .nav-link .nav-text');
    setLabel(users, 'Usuarios y acceso', ':scope > .nav-link .nav-text');

    const inventorySub = inventory.querySelector(':scope > .submenu');
    const operationsSub = operations.querySelector(':scope > .submenu');

    const adjustments = findVerticalTop(list, '/app/adjustments/');
    const transfers = findVerticalTop(list, '/app/transfers/');
    const damages = findVerticalTop(list, '/app/damages/');
    addSection(inventorySub, 'Movimientos de inventario', [
      ...submenuAnchors(adjustments),
      ...submenuAnchors(transfers),
      ...submenuAnchors(damages),
    ], vm, 'vertical');

    const purchases = findVerticalTop(list, '/app/purchases/');
    const quotations = findVerticalTop(list, '/app/quotations/');
    addSection(operationsSub, 'Compras', submenuAnchors(purchases), vm, 'vertical');
    addSection(operationsSub, 'Cotizaciones', submenuAnchors(quotations), vm, 'vertical');

    const saleReturn = findVerticalTop(list, '/app/sale_return/');
    const purchaseReturn = findVerticalTop(list, '/app/purchase_return/');
    const promotions = findVerticalTop(list, '/app/promotions');
    addSection(operationsSub, 'Devoluciones y promociones', [
      directAnchor(saleReturn), directAnchor(purchaseReturn), directAnchor(promotions)
    ].filter(Boolean), vm, 'vertical');

    [adjustments, transfers, damages, purchases, quotations, saleReturn, purchaseReturn, promotions].filter(Boolean).forEach(li => li.classList.add('prodex-nav-absorbed'));
    root.dataset.prodexFriendlyOrganized = '1';
  };

  const organize = vm => {
    const root = vm && vm.$el;
    if (!root || !root.querySelector) return;
    if (root.classList && root.classList.contains('side-content-wrap')) organizeClassic(root, vm);
    if (root.classList && root.classList.contains('vertical-sidebar-wrapper')) organizeVertical(root, vm);
  };

  Vue.mixin({
    mounted() {
      const root = this.$el;
      if (!root || !root.classList) return;
      if (!root.classList.contains('side-content-wrap') && !root.classList.contains('vertical-sidebar-wrapper')) return;
      this.$nextTick(() => organize(this));
    },
    updated() {
      const root = this.$el;
      if (!root || !root.classList) return;
      if (!root.classList.contains('side-content-wrap') && !root.classList.contains('vertical-sidebar-wrapper')) return;
      if (root.dataset.prodexFriendlyOrganized !== '1') this.$nextTick(() => organize(this));
    }
  });
}

export default {
  install(Vue) {
    Vue.use(BootstrapVue);
    Vue.component("large-sidebar", () => import(/* webpackChunkName: "largeSidebar" */ "../containers/layouts/largeSidebar"));
    Vue.component("customizer", () => import(/* webpackChunkName: "customizer" */ "../components/common/customizer.vue"));
    Vue.component("vue-perfect-scrollbar", () => import(/* webpackChunkName: "vue-perfect-scrollbar" */ "vue-perfect-scrollbar"));
    Vue.use(Meta, {
      keyName: "metaInfo",
      attribute: "data-vue-meta",
      ssrAttribute: "data-vue-meta-server-rendered",
      tagIDKeyName: "vmid",
      refreshOnceOnNavigation: true
    });
    Vue.use(VueGoodTablePlugin);
    Vue.use(VueHtmlToPaper, options);
    installReceiptPresentationEnhancer(Vue);
    installFriendlyNavigation(Vue);
  }
};