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
    const current = root.querySelector('.sar-unified-reprint-block');
    if (current) current.remove();

    const rows = [];
    const row = (label, value, strong) => rows.push(
      '<div style="display:flex;justify-content:space-between;gap:8px;' + (strong ? 'font-weight:800;' : '') + '">' +
      '<span>' + esc(label) + '</span><span style="white-space:nowrap;">L ' + money(value) + '</span></div>'
    );
    row('Descuentos y rebajas', totals.discount_total || 0);
    row('Subtotal', totals.subtotal || 0);
    row('Importe exonerado', totals.exonerated_amount || 0);
    row('Importe exento', totals.exempt_amount || 0);
    if (Number(totals.zero_rate_amount || 0) > 0) row('Importe tasa cero', totals.zero_rate_amount);
    row('Importe gravado 15%', totals.taxable_15_amount || 0);
    row('Importe gravado 18%', totals.taxable_18_amount || 0);
    row('ISV 15%', totals.tax_15_amount || 0);
    row('ISV 18%', totals.tax_18_amount || 0);
    if (Math.abs(Number(totals.rounding_adjustment || 0)) >= 0.005) row('Ajuste de redondeo', totals.rounding_adjustment);
    row('TOTAL', totals.grand_total !== undefined ? totals.grand_total : sale.grand_total, true);

    const paymentHtml = setting(settings, 'show_payment_summary', true) && Array.isArray(sale.payments) && sale.payments.length
      ? '<div style="border-top:1px dashed #333;margin-top:5px;padding-top:4px;"><strong>Pago:</strong>' + sale.payments.map(p =>
          '<div style="display:flex;justify-content:space-between;gap:8px;"><span>' + esc(p.method || p.reference || 'Pago') + '</span><span>L ' + money(p.amount) + '</span></div>' +
          (Number(p.change || 0) !== 0 ? '<div style="display:flex;justify-content:space-between;gap:8px;"><span>Cambio</span><span>L ' + money(p.change) + '</span></div>' : '')
        ).join('') + '</div>'
      : '';

    const rangeStart = rangeNumber(fiscal.range_start, fiscal.fiscal_number);
    const rangeEnd = rangeNumber(fiscal.range_end, fiscal.fiscal_number);
    const title = settings.document_title || 'FACTURA';
    const type = settings.sale_type_label || '';
    const name = issuer.trade_name || issuer.legal_name || '';

    const block = document.createElement('div');
    block.className = 'sar-unified-reprint-block';
    block.style.cssText = 'font-size:9.5px;line-height:1.3;margin:0 0 8px;padding:0 3px 8px;border-bottom:1px dashed #333;color:#111;text-transform:none;';
    block.innerHTML =
      '<div style="text-align:center;">' +
      (name ? '<div style="font-size:12px;font-weight:800;">' + esc(name) + '</div>' : '') +
      (issuer.rtn ? '<div><strong>RTN:</strong> ' + esc(issuer.rtn) + '</div>' : '') +
      (issuer.point_of_issue_address ? '<div>' + esc(issuer.point_of_issue_address) + '</div>' : '') +
      (issuer.phone ? '<div>Tel: ' + esc(issuer.phone) + '</div>' : '') +
      (issuer.email ? '<div>' + esc(issuer.email) + '</div>' : '') +
      (settings.website ? '<div>' + esc(settings.website) + '</div>' : '') +
      '<div style="font-size:12px;font-weight:900;margin-top:5px;">' + esc(title) + (type ? ' ' + esc(type) : '') + '</div>' +
      (String(fiscal.status || '').toLowerCase() === 'voided' ? '<div style="font-weight:900;border:2px solid #000;display:inline-block;padding:2px 6px;">ANULADA</div>' : '') +
      '<div style="font-size:11px;font-weight:800;">' + esc(fiscal.fiscal_number) + '</div>' +
      (fiscal.cai ? '<div><strong>CAI:</strong> ' + esc(fiscal.cai) + '</div>' : '') +
      ((rangeStart || rangeEnd) ? '<div><strong>Rango autorizado:</strong><br>' + esc(rangeStart) + ' al ' + esc(rangeEnd) + '</div>' : '') +
      (fiscal.deadline ? '<div><strong>Fecha límite de emisión:</strong> ' + esc(fiscal.deadline) + '</div>' : '') +
      '</div>' +
      '<div style="border-top:1px dashed #333;margin-top:5px;padding-top:4px;"><strong>Cliente:</strong> ' + esc(customer.name || 'Consumidor final') +
      (customer.rtn ? '<div><strong>RTN:</strong> ' + esc(customer.rtn) + '</div>' : '') +
      (!customer.rtn && customer.identification_number ? '<div><strong>' + esc(customer.identification_type || 'Identificación') + ':</strong> ' + esc(customer.identification_number) + '</div>' : '') +
      (setting(settings, 'show_customer_address', true) && customer.address ? '<div>' + esc(customer.address) + '</div>' : '') +
      (customer.sar_registry_number ? '<div><strong>Registro SAG/SAR:</strong> ' + esc(customer.sar_registry_number) + '</div>' : '') +
      (customer.exempt_purchase_order_number ? '<div><strong>Orden compra exenta:</strong> ' + esc(customer.exempt_purchase_order_number) + '</div>' : '') +
      (customer.exoneration_registry_number ? '<div><strong>Registro exonerado:</strong> ' + esc(customer.exoneration_registry_number) + '</div>' : '') +
      (customer.exonerated_card_number ? '<div><strong>Carnet exonerado:</strong> ' + esc(customer.exonerated_card_number) + '</div>' : '') +
      '</div>' +
      (setting(settings, 'show_internal_reference', true) && sale.internal_reference ? '<div><strong>Referencia:</strong> ' + esc(sale.internal_reference) + '</div>' : '') +
      (setting(settings, 'show_warehouse', true) && sale.warehouse_name ? '<div><strong>Almacén:</strong> ' + esc(sale.warehouse_name) + '</div>' : '') +
      (setting(settings, 'show_cashier', true) && sale.seller_name ? '<div><strong>Cajero:</strong> ' + esc(sale.seller_name) + '</div>' : '') +
      '<div style="border-top:1px dashed #333;margin-top:5px;padding-top:4px;">' + rows.join('') + '</div>' +
      paymentHtml +
      (setting(settings, 'show_total_in_words', true) && fiscal.total_in_words ? '<div style="text-align:center;margin-top:5px;font-weight:700;">' + esc(fiscal.total_in_words) + '</div>' : '') +
      '<div style="text-align:center;margin-top:5px;">' + esc(settings.original_label || 'Original: Cliente') + '<br>' + esc(settings.copy_label || 'Copia: Obligado Tributario Emisor') + '</div>' +
      (settings.footer_message ? '<div style="text-align:center;margin-top:5px;">' + esc(settings.footer_message) + '</div>' : '');

    const container = root.firstElementChild || root;
    const posBlock = container.querySelector('.sar-fiscal-pos-block');
    if (posBlock) posBlock.remove();
    container.insertBefore(block, container.firstChild);
  } catch (e) {}
}

export function installSarInvoiceBridge(axiosInstance) {
  if (!axiosInstance || !axiosInstance.interceptors || window.__prodexSarInvoiceBridgeInstalled) return;
  window.__prodexSarInvoiceBridgeInstalled = true;

  axiosInstance.interceptors.response.use(response => {
    try {
      const url = response && response.config ? String(response.config.url || '') : '';
      if (url.indexOf('sales_print_invoice/') !== -1 && response.data && response.data.sar_fiscal) {
        [0, 80, 250, 700].forEach(delay => setTimeout(() => render(response.data), delay));
      }
    } catch (e) {}
    return response;
  }, error => Promise.reject(error));
}
