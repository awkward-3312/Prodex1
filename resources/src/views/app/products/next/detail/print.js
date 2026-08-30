// =============================================================================
// C2A · Detalle de producto — impresión
// -----------------------------------------------------------------------------
// NO copia el DOM px-next ni depende de CSS en runtime. Construye un documento
// HTML autónomo con layout propio y estable (fuente del sistema, tablas
// simples, colores hex literales, print-color-adjust), con la MISMA información
// relevante de la ficha. Se abre en una ventana nueva, se espera la única
// imagen (la principal) y se lanza la impresión.
// =============================================================================

function esc(v) {
  return String(v == null ? "" : v)
    .replace(/&/g, "&amp;")
    .replace(/</g, "&lt;")
    .replace(/>/g, "&gt;")
    .replace(/"/g, "&quot;");
}

const PRINT_CSS = `
  *{box-sizing:border-box}
  html,body{margin:0;padding:0;background:#fff;color:#101828;
    font-family:-apple-system,BlinkMacSystemFont,"Segoe UI",Roboto,Helvetica,Arial,sans-serif;
    -webkit-print-color-adjust:exact;print-color-adjust:exact}
  body{padding:24px;font-size:12px;line-height:1.5}
  h1{font-size:19px;margin:0 0 2px}
  h2{font-size:13px;margin:22px 0 8px;padding-bottom:4px;border-bottom:1px solid #d0d5dd;
    text-transform:uppercase;letter-spacing:.04em;color:#475467}
  .pp-head{display:flex;gap:18px;align-items:flex-start;border-bottom:2px solid #101828;padding-bottom:14px}
  .pp-thumb{width:96px;height:96px;flex:none;border:1px solid #d0d5dd;border-radius:8px;
    overflow:hidden;display:flex;align-items:center;justify-content:center;background:#f9fafb}
  .pp-thumb img{max-width:100%;max-height:100%;object-fit:contain}
  .pp-meta{margin:6px 0 0;color:#475467;font-size:11px}
  .pp-meta span{margin-right:14px;white-space:nowrap}
  .pp-mono{font-family:ui-monospace,SFMono-Regular,Menlo,Consolas,monospace}
  table{width:100%;border-collapse:collapse;margin:0}
  .pp-kv td{padding:6px 4px;border-bottom:1px dashed #e4e7ec;vertical-align:top}
  .pp-kv td:first-child{color:#667085;width:42%}
  .pp-kv td:last-child{text-align:right;font-weight:600}
  .pp-grid{display:grid;grid-template-columns:1fr 1fr;gap:0 32px}
  .pp-data th,.pp-data td{padding:7px 8px;border-bottom:1px solid #e4e7ec;text-align:left}
  .pp-data th{background:#f2f4f7;font-size:10px;text-transform:uppercase;letter-spacing:.04em;color:#475467}
  .pp-data td.num,.pp-data th.num{text-align:right}
  .pp-note{white-space:pre-wrap;border-left:3px solid #98a2b3;padding:8px 12px;background:#f9fafb;border-radius:0 6px 6px 0}
  .pp-badge{display:inline-block;padding:2px 8px;border-radius:999px;font-size:10px;font-weight:700;border:1px solid #d0d5dd}
  .pp-foot{margin-top:26px;padding-top:8px;border-top:1px solid #d0d5dd;color:#98a2b3;font-size:10px}
  @page{margin:12mm}
  tr{page-break-inside:avoid}
`;

function kvRow(label, value) {
  if (value == null || value === "" || value === "—") return "";
  return `<tr><td>${esc(label)}</td><td>${esc(value)}</td></tr>`;
}

function dataTable(headers, rows) {
  if (!rows.length) return "";
  const th = headers.map(h => `<th class="${h.num ? "num" : ""}">${esc(h.label)}</th>`).join("");
  const trs = rows
    .map(
      cells =>
        `<tr>${cells
          .map((c, i) => `<td class="${headers[i] && headers[i].num ? "num" : ""}">${esc(c)}</td>`)
          .join("")}</tr>`
    )
    .join("");
  return `<table class="pp-data"><thead><tr>${th}</tr></thead><tbody>${trs}</tbody></table>`;
}

/**
 * @param {object} m   modelo adaptado (adaptDetail)
 * @param {object} fmt  { money(n), number(n,dec) }
 * @param {object} batches  { rows, hasAnyVariant } | null
 */
export function buildPrintHtml(m, fmt, batches) {
  const money = n => (n == null ? "—" : fmt.money(n));
  const qty = n => (n == null ? "—" : fmt.number(n, 2));

  const identity = [];
  if (m.code) identity.push(`<span class="pp-mono">${esc(m.code)}</span>`);
  identity.push(`<span>${esc(m.typeLabel)}</span>`);
  if (m.brand) identity.push(`<span>${esc(m.brand)}</span>`);
  if (m.categories.length) identity.push(`<span>${esc(m.categories.join(", "))}</span>`);
  if (m.unit) identity.push(`<span>${esc(m.unit)}</span>`);

  const isVar = m.type === "is_variant";
  const isSingleOrCombo = m.type === "is_single" || m.type === "is_combo";
  const notService = m.type !== "is_service";

  const infoLeft =
    `<table class="pp-kv">` +
    kvRow("Tipo", m.typeLabel) +
    kvRow("Código", m.code) +
    kvRow("Nombre", m.name) +
    kvRow("Categoría", m.categories.join(", ")) +
    kvRow("Subcategoría", m.subcategories.join(", ")) +
    kvRow("Marca", m.brand) +
    (m.gtin ? kvRow("Código de barras (GTIN)", m.gtin) : "") +
    kvRow("Simbología", m.typeBarcode) +
    `</table>`;

  const infoRight =
    `<table class="pp-kv">` +
    (isSingleOrCombo ? kvRow("Costo", money(m.cost)) : "") +
    (!isVar ? kvRow("Precio", money(m.price)) : "") +
    (!isVar ? kvRow("Precio mayoreo", money(m.wholesalePrice)) : "") +
    (!isVar ? kvRow("Precio mínimo", money(m.minPrice)) : "") +
    (notService ? kvRow("Unidad", m.unit) : "") +
    kvRow("Impuesto", m.taxe != null ? `${fmt.number(m.taxe, 2)} %` : null) +
    (m.taxe ? kvRow("Método de impuesto", m.taxMethod === "Exclusive" ? "Exclusivo" : "Inclusivo") : "") +
    kvRow("Descuento", m.discountText || "Sin descuento") +
    (notService ? kvRow("Alerta de stock", m.stockAlert != null ? fmt.number(m.stockAlert, 2) : null) : "") +
    (notService && m.weight ? kvRow("Peso", fmt.number(m.weight, 2)) : "") +
    (m.points ? kvRow("Puntos", m.points) : "") +
    `</table>`;

  const warranty =
    m.warranty.period || m.warranty.terms || m.warranty.hasGuarantee
      ? `<h2>Garantía</h2><table class="pp-kv">` +
        kvRow(
          "Periodo de garantía",
          m.warranty.period ? `${m.warranty.period} ${m.warranty.unit || ""}`.trim() : null
        ) +
        kvRow("Términos de garantía", m.warranty.terms) +
        kvRow(
          "Garantía extendida",
          m.warranty.hasGuarantee
            ? `${m.warranty.guaranteePeriod || ""} ${m.warranty.guaranteeUnit || ""}`.trim()
            : null
        ) +
        `</table>`
      : "";

  const combo =
    m.type === "is_combo" && m.comboRows.length
      ? `<h2>Productos combinados</h2>` +
        dataTable(
          [{ label: "Código" }, { label: "Nombre" }, { label: "Cantidad", num: true }],
          m.comboRows.map(c => [c.code, c.name, qty(c.quantity)])
        )
      : "";

  const variants =
    m.type === "is_variant" && m.variantRows.length
      ? `<h2>Variantes</h2>` +
        dataTable(
          [
            { label: "Código" },
            { label: "Nombre" },
            { label: "Costo", num: true },
            { label: "Precio", num: true },
            { label: "Mayoreo", num: true },
            { label: "Precio mín.", num: true }
          ],
          m.variantRows.map(v => [
            v.code,
            v.name,
            money(v.cost),
            money(v.price),
            money(v.wholesale),
            money(v.min_price)
          ])
        )
      : "";

  const stockSingle =
    m.type === "is_single" && m.warehouseStock.length
      ? `<h2>Existencias por almacén — total ${esc(qty(m.totalStock))} ${esc(m.unit || "")}</h2>` +
        dataTable(
          [{ label: "Almacén" }, { label: "Cantidad", num: true }],
          m.warehouseStock.map(w => [w.warehouse, `${qty(w.qty)} ${m.unit || ""}`.trim()])
        )
      : "";

  const stockVariant =
    m.type === "is_variant" && m.warehouseVariantStock.length
      ? `<h2>Existencias por variante</h2>` +
        dataTable(
          [{ label: "Almacén" }, { label: "Variante" }, { label: "Cantidad", num: true }],
          m.warehouseVariantStock.map(w => [w.warehouse, w.variant, `${qty(w.qty)} ${m.unit || ""}`.trim()])
        )
      : "";

  const batchesBlock =
    m.isBatchTracked && batches && batches.rows.length
      ? `<h2>Lotes</h2>` +
        dataTable(
          [
            { label: "Lote" },
            ...(batches.hasAnyVariant ? [{ label: "Variante" }] : []),
            { label: "Almacén" },
            { label: "Fabricación" },
            { label: "Caducidad" },
            { label: "Cantidad", num: true },
            { label: "Costo unit.", num: true },
            { label: "Estado" }
          ],
          batches.rows.map(b => [
            b.batchNo,
            ...(batches.hasAnyVariant ? [b.variantName || "—"] : []),
            b.warehouseName,
            b.mfgDateLabel || b.mfgDate || "—",
            b.expiryDateLabel || b.expiryDate || "—",
            `${qty(b.qty)} ${m.unit || ""}`.trim(),
            b.unitCost != null ? money(b.unitCost) : "—",
            b.statusLabel || b.status
          ])
        )
      : "";

  const note = m.note ? `<h2>Notas</h2><div class="pp-note">${esc(m.note)}</div>` : "";

  const thumb =
    m.mainImage && m.mainImage !== "no-image.png"
      ? `<div class="pp-thumb"><img src="${esc(m.mainImageUrl)}" alt="" /></div>`
      : "";

  const printedAt = new Date().toLocaleString();

  return `<!DOCTYPE html>
<html lang="es">
<head>
<meta charset="utf-8" />
<title>${esc(m.name)} — ${esc(m.code)}</title>
<style>${PRINT_CSS}</style>
</head>
<body>
  <div class="pp-head">
    ${thumb}
    <div>
      <h1>${esc(m.name)}</h1>
      <div class="pp-meta">${identity.join("")}</div>
    </div>
  </div>

  <h2>Información del producto</h2>
  <div class="pp-grid">${infoLeft}${infoRight}</div>

  ${warranty}
  ${variants}
  ${combo}
  ${stockSingle}
  ${stockVariant}
  ${batchesBlock}
  ${note}

  <div class="pp-foot">Impreso ${esc(printedAt)} · PRODEX</div>
</body>
</html>`;
}

/** Abre la ventana, espera la imagen principal (si la hay) e imprime. */
export function openPrintWindow(html) {
  const win = window.open("", "_blank", "width=980,height=800,scrollbars=yes");
  if (!win) return false;
  win.document.open();
  win.document.write(html);
  win.document.close();

  const done = () => {
    try {
      win.close();
    } catch (e) {
      /* noop */
    }
  };
  const run = () => {
    try {
      win.focus();
      if (typeof win.onafterprint !== "undefined") win.onafterprint = done;
      win.print();
      setTimeout(done, 1500);
    } catch (e) {
      done();
    }
  };
  const waitImages = () => {
    const imgs = Array.from(win.document.images || []);
    if (!imgs.length) return Promise.resolve();
    return Promise.all(
      imgs.map(img =>
        img.complete
          ? Promise.resolve()
          : new Promise(res => {
              img.addEventListener("load", res, { once: true });
              img.addEventListener("error", res, { once: true });
            })
      )
    );
  };

  if (win.document.readyState === "complete") waitImages().then(run);
  else win.addEventListener("load", () => waitImages().then(run));
  return true;
}
