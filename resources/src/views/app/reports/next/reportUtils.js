// C3.9–C3.11 — utilidades compartidas de exportación para los reportes px-next.
// Solo se extrae lo que las tres pantallas repetían casi literal: el documento
// de impresión HTML, la exportación PDF (jsPDF + autoTable) y el CSV. Los
// filtros y el layout de cada reporte siguen siendo propios.

import jsPDF from "jspdf";
import autoTable from "jspdf-autotable";

const BRAND = [26, 86, 219];

/**
 * Abre una ventana de impresión con una tabla simple y limpia.
 * @param {{ title:string, headers:string[], rows:Array<Array<string|number>>, footer?:Array<string|number>, landscape?:boolean }} opts
 */
export function printTableDoc({ title, headers, rows, footer = null, landscape = false }) {
  const esc = v => String(v == null ? "" : v).replace(/&/g, "&amp;").replace(/</g, "&lt;").replace(/>/g, "&gt;");
  const head = "<tr>" + headers.map(h => `<th>${esc(h)}</th>`).join("") + "</tr>";
  const body = (rows || []).map(r => "<tr>" + r.map(c => `<td>${esc(c)}</td>`).join("") + "</tr>").join("");
  const foot = footer ? "<tfoot><tr>" + footer.map(c => `<td>${esc(c)}</td>`).join("") + "</tr></tfoot>" : "";
  const html = `<!doctype html><html lang="es"><head><meta charset="utf-8" />
<title>${esc(title)}</title>
<style>
  @media print { body, body * { visibility: visible !important; } @page { size: A4 ${landscape ? "landscape" : "portrait"}; margin: 0.3cm; } }
  body { margin: 0.4cm; font-family: -apple-system, BlinkMacSystemFont, "Segoe UI", Roboto, Arial, sans-serif; color: #101828; }
  .print-header { font-weight: 600; font-size: 14px; margin-bottom: 10px; }
  table { width: 100%; border-collapse: collapse; font-size: 11px; }
  th, td { border: 1px solid #e4e7ec; padding: 6px 8px; text-align: left; }
  th { background: #f2f4f7; font-weight: 700; text-transform: uppercase; font-size: 10px; letter-spacing: .03em; }
  tfoot td { font-weight: 700; background: #f2f4f7; }
  tr:nth-child(even) td { background: #fafafa; }
</style></head><body>
  <div class="print-header">${esc(title)}</div>
  <table><thead>${head}</thead><tbody>${body || `<tr><td colspan="${headers.length}">Sin datos</td></tr>`}</tbody>${foot}</table>
</body></html>`;
  const w = window.open("", "_blank", "width=980,height=760,scrollbars=yes");
  if (!w) return false;
  w.document.open();
  w.document.write(html);
  w.document.close();
  w.focus();
  setTimeout(() => { try { w.print(); w.close(); } catch (e) { /* noop */ } }, 400);
  return true;
}

/**
 * Genera y descarga un PDF con cabecera de marca y (opcional) fila de total.
 * @param {{ title:string, filename:string, headers:string[], rows:Array<Array<string|number>>, footer?:Array<string|number>, landscape?:boolean, subtitle?:string }} opts
 */
export function exportPdf({ title, filename, headers, rows, footer = null, landscape = false, subtitle = "" }) {
  const pdf = new jsPDF(landscape ? { orientation: "landscape", unit: "pt", format: "A4" } : "p", landscape ? undefined : "pt");
  const fontPath = "/fonts/Vazirmatn-Bold.ttf";
  try {
    pdf.addFont(fontPath, "Vazirmatn", "normal");
    pdf.addFont(fontPath, "Vazirmatn", "bold");
  } catch (e) { /* ya añadida */ }
  pdf.setFont("Vazirmatn", "normal");
  const marginX = 40;
  autoTable(pdf, {
    head: [headers],
    body: (rows || []).map(r => r.map(c => (c == null ? "" : c))),
    foot: footer ? [footer] : undefined,
    startY: subtitle ? 96 : 84,
    theme: "striped",
    margin: { left: marginX, right: marginX },
    styles: { font: "Vazirmatn", fontSize: 9, cellPadding: 5, halign: "left", textColor: 33 },
    headStyles: { font: "Vazirmatn", fontStyle: "bold", fillColor: BRAND, textColor: 255 },
    footStyles: { font: "Vazirmatn", fontStyle: "bold", fillColor: BRAND, textColor: 255 },
    alternateRowStyles: { fillColor: [245, 247, 250] },
    didDrawPage: d => {
      const pageW = pdf.internal.pageSize.getWidth();
      const pageH = pdf.internal.pageSize.getHeight();
      pdf.setFillColor(BRAND[0], BRAND[1], BRAND[2]);
      pdf.rect(0, 0, pageW, subtitle ? 68 : 56, "F");
      pdf.setTextColor(255);
      pdf.setFont("Vazirmatn", "bold");
      pdf.setFontSize(15);
      pdf.text(title, marginX, 34);
      if (subtitle) {
        pdf.setFont("Vazirmatn", "normal");
        pdf.setFontSize(9);
        pdf.text(subtitle, marginX, 52);
      }
      pdf.setTextColor(33);
      pdf.setFontSize(8);
      pdf.text(`${d.pageNumber} / ${pdf.internal.getNumberOfPages()}`, pageW - marginX, pageH - 14, { align: "right" });
    }
  });
  pdf.save(filename.endsWith(".pdf") ? filename : filename + ".pdf");
}

/**
 * Descarga un CSV (UTF-8 BOM) desde filas ya normalizadas.
 * @param {{ filename:string, headers:string[], rows:Array<Array<string|number>> }} opts
 */
export function exportCsv({ filename, headers, rows }) {
  const cell = c => `"${String(c == null ? "" : c).replace(/"/g, '""')}"`;
  const lines = [headers.map(cell).join(",")].concat((rows || []).map(r => r.map(cell).join(",")));
  const blob = new Blob(["﻿" + lines.join("\n")], { type: "text/csv;charset=utf-8;" });
  const link = document.createElement("a");
  link.href = URL.createObjectURL(blob);
  link.setAttribute("download", filename.endsWith(".csv") ? filename : filename + ".csv");
  document.body.appendChild(link);
  link.click();
  document.body.removeChild(link);
}
