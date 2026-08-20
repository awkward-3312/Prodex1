function esc(value) {
  return String(value == null ? '' : value)
    .replace(/&/g, '&amp;').replace(/</g, '&lt;').replace(/>/g, '&gt;')
    .replace(/"/g, '&quot;').replace(/'/g, '&#039;');
}

function money(value) {
  const n = Number(value || 0);
  return (Number.isFinite(n) ? n : 0).toFixed(2);
}

function setting(settings, key, fallback = true) {
  if (!settings || settings[key] === undefined || settings[key] === null) return fallback;
  return settings[key] === true || settings[key] === 1 || settings[key] === '1';
}

function rangeNumber(value, fiscalNumber) {
  if (value === null || value === undefined || value === '') return '';
  const raw = String(value);
  if (raw.includes('-')) return raw;
  const parts = String(fiscalNumber || '').split('-');
  const prefix = parts.length >= 4 ? parts.slice(0, 3).join('-') + '-' : '';
  return prefix + raw.replace(/\D+/g, '').padStart(8, '0');
}

function taxLabel(line) {
  const category = String((line && line.tax_category) || '').toLowerCase();
  if (category === 'taxed') return money(line.tax_rate || 0).replace('.00', '') + '%';
  if (category === 'exempt') return 'EXENTO';
  if (category === 'exonerated') return 'EXONERADO';
  if (category === 'zero_rate') return '0%';
  return '';
}

function logoUrl(responseData, settings) {
  if (!setting(settings, 'show_logo', true)) return '';
  const logo = responseData && responseData.setting ? responseData.setting.logo : '';
  if (!logo) return '';
  const base = String(window.__uploadPath || 'images').replace(/^\/+|\/+$/g, '');
  return '/' + base + '/settings/' + encodeURIComponent(logo);
}

function layoutStyle(layout) {
  const l = Number(layout || 1);
  if (l === 2) return { font: '9px', gap: '3px', separator: '1px dotted #555', itemPad: '2px 0' };
  if (l === 3) return { font: '10px', gap: '5px', separator: '1px dashed #222', itemPad: '5px 0' };
  if (l === 4) return { font: '9.5px', gap: '4px', separator: '1px dashed #444', itemPad: '4px 0' };
  if (l === 5) return { font: '9px', gap: '3px', separator: '1px solid #111', itemPad: '3px 0' };
  return { font: '10px', gap: '4px', separator: '1px dotted #333', itemPad: '4px 0' };
}

function createQr(container, text) {
  if (!container || !text || typeof window.QRCode === 'undefined') return;
  try {
    container.innerHTML = '';
    new window.QRCode(container, { text, width: 100, height: 100, correctLevel: window.QRCode.CorrectLevel ? window.QRCode.CorrectLevel.M : undefined });
    setTimeout(() => {
      try {
        const canvas = container.querySelector('canvas');
        if (!canvas) return;
        let img = container.querySelector('img.prodex-sar-qr-img');
        if (!img) {
          img = document.createElement('img');
          img.className = 'prodex-sar-qr-img';
          img.style.cssText = 'width:100px;height:100px;display:block;margin:0 auto;';
          container.appendChild(img);
        }
        img.src = canvas.toDataURL('image/png');
        canvas.style.display = 'none';
        Array.from(container.querySelectorAll('img:not(.prodex-sar-qr-img)')).forEach(el => el.style.display = 'none');
      } catch (e) {}
    }, 40);
  } catch (e) {}
}

function render(responseData) {
  try {
    const fiscal = responseData && responseData.sar_fiscal;
    if (!fiscal || !fiscal.fiscal_number) return;
    const root = document.getElementById('invoice-POS');
    if (!root) return;

    const issuer = fiscal.issuer || {};
    const customer = fiscal.customer || {};
    const sale = fiscal.sale || {};
    const totals = sale.fiscal_totals || {};
    const settings = issuer.invoice_settings || {};
    const pos = responseData.pos_settings || {};
    const layout = Number(pos.receipt_layout || 1);
    const style = layoutStyle(layout);
    const details = Array.isArray(responseData.details) ? responseData.details : [];
    const fiscalLines = Array.isArray(sale.lines) ? sale.lines : [];
    const rangeStart = rangeNumber(fiscal.range_start, fiscal.fiscal_number);
    const rangeEnd = rangeNumber(fiscal.range_end, fiscal.fiscal_number);
    const title = settings.document_title || 'FACTURA';
    const type = settings.sale_type_label || '';
    const businessName = issuer.trade_name || issuer.legal_name || '';
    const logo = logoUrl(responseData, settings);
    const qrUrl = setting(settings, 'show_qr', true) ? String(responseData.public_invoice_url || '') : '';

    const itemsHtml = details.map((detail, index) => {
      const line = fiscalLines[index] || {};
      const code = detail.code || line.code || '';
      const name = detail.name || line.description || 'Producto';
      const qty = Number(detail.quantity != null ? detail.quantity : line.quantity || 0);
      const unit = detail.pack_name || detail.unitSale || '';
      const unitPrice = line.unit_price != null ? line.unit_price : detail.price || 0;
      const lineTotal = line.line_total != null ? line.line_total : detail.total || 0;
      const discount = Number(line.product_discount || 0) + Number(line.allocated_sale_discount || 0);
      return '<div class="sar-fiscal-item" style="padding:' + style.itemPad + ';border-bottom:' + style.separator + ';">' +
        '<div style="display:flex;justify-content:space-between;gap:8px;"><strong style="text-align:left;">' + esc(name) + '</strong><strong style="white-space:nowrap;">L ' + money(lineTotal) + '</strong></div>' +
        (setting(settings, 'show_item_code', true) && code ? '<div style="font-size:90%;">Código: ' + esc(code) + '</div>' : '') +
        '<div style="display:flex;justify-content:space-between;gap:8px;font-size:92%;"><span>' + esc(qty) + (unit ? ' ' + esc(unit) : '') + ' x L ' + money(unitPrice) + '</span><span>' + esc(taxLabel(line)) + '</span></div>' +
        (discount > 0 ? '<div style="font-size:90%;">Descuento línea: L ' + money(discount) + '</div>' : '') +
        (detail.imei_number ? '<div style="font-size:90%;">SN/IMEI: ' + esc(detail.imei_number) + '</div>' : '') +
        '</div>';
    }).join('');

    const totalRows = [];
    const totalRow = (label, value, strong) => totalRows.push(
      '<div style="display:flex;justify-content:space-between;gap:8px;' + (strong ? 'font-weight:900;font-size:115%;' : '') + '">' +
      '<span>' + esc(label) + '</span><span style="white-space:nowrap;">L ' + money(value) + '</span></div>'
    );
    totalRow('Descuentos y rebajas', totals.discount_total || 0);
    totalRow('Subtotal', totals.subtotal || 0);
    totalRow('Importe exonerado', totals.exonerated_amount || 0);
    totalRow('Importe exento', totals.exempt_amount || 0);
    if (Number(totals.zero_rate_amount || 0) > 0) totalRow('Importe tasa cero', totals.zero_rate_amount);
    totalRow('Importe gravado 15%', totals.taxable_15_amount || 0);
    totalRow('Importe gravado 18%', totals.taxable_18_amount || 0);
    totalRow('ISV 15%', totals.tax_15_amount || 0);
    totalRow('ISV 18%', totals.tax_18_amount || 0);
    if (Number(totals.other_taxable_amount || 0) > 0) totalRow('Otros importes gravados', totals.other_taxable_amount);
    if (Number(totals.other_tax_amount || 0) > 0) totalRow('Otros impuestos', totals.other_tax_amount);
    if (Math.abs(Number(totals.rounding_adjustment || 0)) >= 0.005) totalRow('Ajuste de redondeo', totals.rounding_adjustment);
    if (Number(totals.shipping || 0) !== 0) totalRow('Envío', totals.shipping);
    totalRow('TOTAL', totals.grand_total !== undefined ? totals.grand_total : sale.grand_total, true);

    const paymentHtml = setting(settings, 'show_payment_summary', true) && Array.isArray(sale.payments) && sale.payments.length
      ? '<div style="border-top:' + style.separator + ';margin-top:' + style.gap + ';padding-top:' + style.gap + ';"><strong>Pago</strong>' + sale.payments.map(p =>
          '<div style="display:flex;justify-content:space-between;gap:8px;"><span>' + esc(p.method || p.reference || 'Pago') + '</span><span>L ' + money(p.amount) + '</span></div>' +
          (Number(p.change || 0) !== 0 ? '<div style="display:flex;justify-content:space-between;gap:8px;"><span>Cambio</span><span>L ' + money(p.change) + '</span></div>' : '')
        ).join('') + '</div>'
      : '';

    const customerId = customer.rtn
      ? '<div><strong>RTN:</strong> ' + esc(customer.rtn) + '</div>'
      : (customer.identification_number ? '<div><strong>' + esc(customer.identification_type || 'Identificación') + ':</strong> ' + esc(customer.identification_number) + '</div>' : '');

    const receipt = document.createElement('div');
    receipt.className = 'sar-complete-receipt sar-layout-' + layout;
    receipt.setAttribute('data-fiscal-number', String(fiscal.fiscal_number));
    receipt.style.cssText = 'font-size:' + style.font + ';line-height:1.3;color:#111;text-transform:none;word-break:break-word;width:100%;';
    receipt.innerHTML =
      '<div style="text-align:center;">' +
        (logo ? '<div style="margin-bottom:4px;"><img src="' + esc(logo) + '" alt="Logo" style="max-width:80px;max-height:80px;object-fit:contain;"></div>' : '') +
        (businessName ? '<div style="font-size:125%;font-weight:900;">' + esc(businessName) + '</div>' : '') +
        (issuer.legal_name && issuer.trade_name ? '<div>' + esc(issuer.legal_name) + '</div>' : '') +
        (issuer.rtn ? '<div><strong>RTN:</strong> ' + esc(issuer.rtn) + '</div>' : '') +
        (issuer.point_of_issue_address || issuer.head_office_address ? '<div>' + esc(issuer.point_of_issue_address || issuer.head_office_address) + '</div>' : '') +
        (issuer.phone ? '<div>Tel: ' + esc(issuer.phone) + '</div>' : '') +
        (issuer.email ? '<div>' + esc(issuer.email) + '</div>' : '') +
        (settings.website ? '<div>' + esc(settings.website) + '</div>' : '') +
      '</div>' +
      '<div style="border-top:' + style.separator + ';border-bottom:' + style.separator + ';margin:' + style.gap + ' 0;padding:' + style.gap + ' 0;text-align:center;">' +
        '<div style="font-size:125%;font-weight:900;">' + esc(title) + (type ? ' ' + esc(type) : '') + '</div>' +
        (String(fiscal.status || '').toLowerCase() === 'voided' ? '<div style="font-weight:900;border:2px solid #111;display:inline-block;padding:2px 8px;margin:2px 0;">ANULADA</div>' : '') +
        '<div style="font-size:115%;font-weight:800;">' + esc(fiscal.fiscal_number) + '</div>' +
        (fiscal.cai ? '<div><strong>CAI:</strong> ' + esc(fiscal.cai) + '</div>' : '') +
        ((rangeStart || rangeEnd) ? '<div><strong>Rango autorizado:</strong><br>' + esc(rangeStart) + '<br>' + esc(rangeEnd) + '</div>' : '') +
        (fiscal.deadline ? '<div><strong>Fecha límite:</strong> ' + esc(fiscal.deadline) + '</div>' : '') +
      '</div>' +
      '<div style="margin-bottom:' + style.gap + ';">' +
        '<div><strong>Cliente:</strong> ' + esc(customer.name || 'Consumidor final') + '</div>' + customerId +
        (setting(settings, 'show_customer_address', true) && customer.address ? '<div>' + esc(customer.address) + '</div>' : '') +
        (customer.sar_registry_number ? '<div><strong>Registro SAG/SAR:</strong> ' + esc(customer.sar_registry_number) + '</div>' : '') +
        (customer.exempt_purchase_order_number ? '<div><strong>Orden compra exenta:</strong> ' + esc(customer.exempt_purchase_order_number) + '</div>' : '') +
        (customer.exoneration_registry_number ? '<div><strong>Registro exonerado:</strong> ' + esc(customer.exoneration_registry_number) + '</div>' : '') +
        (customer.exonerated_card_number ? '<div><strong>Carnet exonerado:</strong> ' + esc(customer.exonerated_card_number) + '</div>' : '') +
        (setting(settings, 'show_internal_reference', true) && sale.internal_reference ? '<div><strong>Referencia:</strong> ' + esc(sale.internal_reference) + '</div>' : '') +
        (setting(settings, 'show_warehouse', true) && sale.warehouse_name ? '<div><strong>Almacén:</strong> ' + esc(sale.warehouse_name) + '</div>' : '') +
        (setting(settings, 'show_cashier', true) && sale.seller_name ? '<div><strong>Cajero:</strong> ' + esc(sale.seller_name) + '</div>' : '') +
        (fiscal.issued_at ? '<div><strong>Fecha:</strong> ' + esc(fiscal.issued_at) + '</div>' : '') +
      '</div>' +
      '<div style="border-top:' + style.separator + ';">' + itemsHtml + '</div>' +
      '<div style="margin-top:' + style.gap + ';padding-top:' + style.gap + ';">' + totalRows.join('') + '</div>' +
      paymentHtml +
      (setting(settings, 'show_total_in_words', true) && fiscal.total_in_words ? '<div style="text-align:center;font-weight:800;margin-top:' + style.gap + ';">' + esc(fiscal.total_in_words) + '</div>' : '') +
      '<div style="text-align:center;border-top:' + style.separator + ';margin-top:' + style.gap + ';padding-top:' + style.gap + ';">' +
        esc(settings.original_label || 'Original: Cliente') + '<br>' + esc(settings.copy_label || 'Copia: Obligado Tributario Emisor') +
        (settings.footer_message ? '<div style="margin-top:3px;">' + esc(settings.footer_message) + '</div>' : '') +
        (String(fiscal.status || '').toLowerCase() === 'voided' && fiscal.void_reason ? '<div style="margin-top:3px;"><strong>Motivo:</strong> ' + esc(fiscal.void_reason) + '</div>' : '') +
      '</div>' +
      (qrUrl ? '<div style="text-align:center;margin-top:6px;"><div style="font-weight:700;margin-bottom:3px;">FACTURA QR</div><div class="prodex-sar-public-qr" style="display:inline-block;width:100px;height:100px;"></div></div>' : '');

    const container = root.firstElementChild || root;
    container.innerHTML = '';
    container.appendChild(receipt);

    if (qrUrl) createQr(receipt.querySelector('.prodex-sar-public-qr'), qrUrl);
  } catch (e) {}
}

export function installSarInvoiceBridge(axiosInstance) {
  if (!axiosInstance || !axiosInstance.interceptors || window.__prodexSarInvoiceBridgeInstalled) return;
  window.__prodexSarInvoiceBridgeInstalled = true;

  axiosInstance.interceptors.response.use(response => {
    try {
      const url = response && response.config ? String(response.config.url || '') : '';
      if (url.indexOf('sales_print_invoice/') !== -1 && response.data && response.data.sar_fiscal) {
        [0, 50, 120, 300, 750].forEach(delay => setTimeout(() => render(response.data), delay));
      }
    } catch (e) {}
    return response;
  }, error => Promise.reject(error));
}
