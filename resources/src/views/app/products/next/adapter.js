// =============================================================================
// Fase B2 · Productos / listado preview — adapter
// -----------------------------------------------------------------------------
// Normaliza la respuesta REAL de GET /api/products para la tabla del preview.
// NO inventa datos, NO filtra en cliente, NO duplica lógica de negocio: sólo
// castea, renombra y deja los valores listos para presentación.
//
// Forma real de products[]:
//   id, code, category, sub_category, categories_display ("\n"-separado),
//   brand (nombre), status (localizado), is_active (bool), image (filename;
//   "no-image.png" = sin imagen), type ("Single"|"Variable"),
//   name, cost (string; "" en Variable; multilínea en Variable con valores),
//   price (string; idem), unit, quantity (string "N.NN unidad" | "----")
// =============================================================================

const NO_IMAGE = "no-image.png";

function firstLine(v) {
  if (v == null) return "";
  return String(v).split("\n")[0].trim();
}

/** "0.20" -> 0.2 ; "" -> null ; "1,234.5" -> 1234.5 ; multilínea -> primera */
function toMoney(v) {
  const s = firstLine(v).replace(/,/g, "");
  if (s === "") return null;
  const n = parseFloat(s);
  return Number.isFinite(n) ? n : null;
}

/** "255.00 pc" -> { label:"255.00 pc", value:255 } ; "----" -> { label:"----", value:null } */
function parseQuantity(raw) {
  const label = raw == null ? "----" : String(raw).trim() || "----";
  const m = label.match(/-?\d[\d,]*\.?\d*/);
  const value = m ? parseFloat(m[0].replace(/,/g, "")) : null;
  return { label, value: Number.isFinite(value) ? value : null };
}

function splitCategories(display, fallback) {
  const src = display != null && String(display).trim() !== "" ? String(display) : fallback || "";
  return src
    .split("\n")
    .map(s => s.trim())
    .filter(Boolean);
}

export function adaptProductRow(p, imgUrl) {
  const p2 = p || {};
  const isVariable = String(p2.type).toLowerCase() === "variable";
  const cats = splitCategories(p2.categories_display, p2.category);
  const image = p2.image && String(p2.image).trim() !== "" ? String(p2.image).trim() : NO_IMAGE;
  const hasImage = image !== NO_IMAGE;
  const qty = parseQuantity(p2.quantity);

  return {
    id: p2.id,
    code: p2.code || "—",
    name: p2.name || "—",
    type: isVariable ? "variable" : "single",
    typeLabel: isVariable ? "Variable" : "Simple",
    brand: p2.brand && p2.brand !== "N/D" ? p2.brand : null,
    categories: cats,
    categoryPrimary: cats[0] || null,
    categoryExtra: Math.max(0, cats.length - 1),
    cost: toMoney(p2.cost),
    price: toMoney(p2.price),
    costMissing: firstLine(p2.cost) === "",
    priceMissing: firstLine(p2.price) === "",
    unit: p2.unit || "",
    qtyLabel: qty.label,
    qtyValue: qty.value,
    qtyUnavailable: qty.label === "----",
    image,
    hasImage,
    imageUrl: hasImage && typeof imgUrl === "function" ? imgUrl("products", image) : null,
    active: p2.is_active === true || p2.is_active === 1,
    statusLabel: p2.status || (p2.is_active ? "Activo" : "Inactivo")
  };
}

export function adaptProducts(raw, imgUrl) {
  const r = raw && typeof raw === "object" ? raw : {};
  const list = Array.isArray(r.products) ? r.products : [];
  const asOptions = arr =>
    (Array.isArray(arr) ? arr : [])
      .filter(x => x && x.id != null)
      .map(x => ({ value: String(x.id), label: x.name || `#${x.id}` }));

  return {
    rows: list.map(p => adaptProductRow(p, imgUrl)),
    total: Number.isFinite(+r.totalRows) ? +r.totalRows : list.length,
    warehouses: asOptions(r.warehouses),
    categories: asOptions(r.categories),
    brands: asOptions(r.brands)
  };
}
