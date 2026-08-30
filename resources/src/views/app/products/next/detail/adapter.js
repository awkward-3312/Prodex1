// =============================================================================
// C2A · Detalle de producto px-next — adapter
// -----------------------------------------------------------------------------
// Normaliza la respuesta REAL de GET /api/get_product_detail_api/{id} y de
// GET /api/product_batches para la ficha. NO inventa datos, NO cambia el
// contrato: castea, renombra y deja los valores listos para presentación.
// El payload original se conserva íntegro en `raw`.
//
// Nota de presentación: el label de tipo se deriva SIEMPRE de `raw.type`
// (is_single→Simple, is_variant→Variable, is_combo→Combo, is_service→Servicio).
// El campo `raw.type_name` del backend NO se usa para el label (arrastra el
// quirk is_combo → "Service"); se conserva intacto en `raw`.
// =============================================================================

const TYPE_LABEL = {
  is_single: "Simple",
  is_variant: "Variable",
  is_combo: "Combo",
  is_service: "Servicio"
};

const NO_IMAGE = "no-image.png";

function num(v) {
  if (v == null || v === "") return null;
  const n = Number(v);
  return Number.isFinite(n) ? n : null;
}
function str(v) {
  return v == null ? "" : String(v).trim();
}
// Datos legacy sucios: algunas columnas de texto guardan el literal "null" /
// "undefined". Se tratan como vacío para presentación (no se pierde info real).
function cleanStr(v) {
  const s = str(v);
  return /^(null|undefined)$/i.test(s) ? "" : s;
}

// ---- Presentación: fecha amigable es-ES ("10 ago 2026") --------------------
// Solo presentación: el valor raw (YYYY-MM-DD) se conserva intacto en la fila.
const ES_DATE_FMT =
  typeof Intl !== "undefined" && Intl.DateTimeFormat
    ? new Intl.DateTimeFormat("es-ES", { day: "numeric", month: "short", year: "numeric" })
    : null;

function friendlyDate(raw) {
  const s = str(raw);
  const m = s.match(/^(\d{4})-(\d{2})-(\d{2})/);
  if (!m || !ES_DATE_FMT) return s;
  try {
    // new Date(y, m-1, d): componentes locales, sin desfase de zona horaria.
    return ES_DATE_FMT.format(new Date(+m[1], +m[2] - 1, +m[3])).replace(/\.$/, "");
  } catch (e) {
    return s;
  }
}

// Presentación: estado de lote localizado (los valores raw se conservan).
const BATCH_STATUS_LABEL = {
  active: "Activo",
  quarantined: "En cuarentena",
  expired: "Vencido",
  written_off: "Dado de baja"
};

export { TYPE_LABEL, NO_IMAGE, friendlyDate, BATCH_STATUS_LABEL };

export function adaptDetail(raw, imgUrl) {
  const r = raw && typeof raw === "object" ? raw : {};
  const type = str(r.type);

  const images = (Array.isArray(r.images) ? r.images : [])
    .map(str)
    .filter(Boolean);
  const realImages = images.filter(f => f && f !== NO_IMAGE);
  const gallery = realImages.length ? realImages : (str(r.image) && str(r.image) !== NO_IMAGE ? [str(r.image)] : []);
  const mainImage = gallery[0] || NO_IMAGE;
  const hasImage = gallery.length > 0;
  const urlFor = f => (typeof imgUrl === "function" ? imgUrl("products", f) : f);

  const categories = (Array.isArray(r.categories) ? r.categories : [])
    .map(c => c && str(c.name))
    .filter(Boolean);
  const subcategories = (Array.isArray(r.subcategories) ? r.subcategories : [])
    .map(c => c && str(c.name))
    .filter(Boolean);

  // discount llega ya formateado por el backend ("15 %", "10 LPS", " LPS", "0 LPS").
  const discountRaw = str(r.discount);
  const discountNum = parseFloat(discountRaw.replace(/[^\d.-]/g, ""));
  const hasDiscount = Number.isFinite(discountNum) && discountNum > 0;

  const warehouseStock = (Array.isArray(r.CountQTY) ? r.CountQTY : []).map(w => ({
    warehouse: str(w.mag) || "—",
    qty: num(w.qte)
  }));
  const warehouseVariantStock = (Array.isArray(r.CountQTY_variants) ? r.CountQTY_variants : []).map(w => ({
    warehouse: str(w.mag) || "—",
    variant: str(w.variant) || "—",
    qty: num(w.qte)
  }));

  const comboRows = (Array.isArray(r.products_combo_data) ? r.products_combo_data : []).map(c => ({
    code: str(c.code) || "—",
    name: str(c.name) || "—",
    quantity: num(c.quantity)
  }));

  const variantRows = (Array.isArray(r.products_variants_data) ? r.products_variants_data : []).map(v => ({
    code: str(v.code) || "—",
    name: str(v.name) || "—",
    cost: num(v.cost),
    price: num(v.price),
    wholesale: num(v.wholesale),
    min_price: num(v.min_price)
  }));

  return {
    raw: r,
    id: r.id,
    type,
    typeLabel: TYPE_LABEL[type] || str(r.type_name) || "—",
    code: str(r.code) || "—",
    gtin: cleanStr(r.gtin),
    name: str(r.name) || "—",
    note: cleanStr(r.note),
    brand: str(r.brand) && str(r.brand) !== "N/D" ? str(r.brand) : null,
    typeBarcode: str(r.Type_barcode),
    unit: str(r.unit) && str(r.unit) !== "----" ? str(r.unit) : null,

    categories,
    subcategories,

    price: num(r.price),
    cost: num(r.cost),
    wholesalePrice: num(r.wholesale_price),
    minPrice: num(r.min_price),
    stockAlert: num(r.stock_alert),
    weight: num(r.weight),
    points: num(r.points),

    taxe: num(r.taxe),
    taxMethod: str(r.tax_method), // "Exclusive" | "Inclusive"
    discountText: hasDiscount ? discountRaw : null,

    warranty: {
      period: num(r.warranty_period),
      unit: cleanStr(r.warranty_unit),
      terms: cleanStr(r.warranty_terms),
      hasGuarantee: r.has_guarantee === true || r.has_guarantee === 1,
      guaranteePeriod: num(r.guarantee_period),
      guaranteeUnit: cleanStr(r.guarantee_unit)
    },

    isBatchTracked: r.is_batch_tracked === true || r.is_batch_tracked === 1,

    gallery,
    galleryUrls: gallery.map(urlFor),
    mainImage,
    mainImageUrl: urlFor(mainImage),
    hasImage,

    warehouseStock,
    warehouseVariantStock,
    comboRows,
    variantRows,
    totalStock: warehouseStock.reduce((s, w) => s + (w.qty || 0), 0)
  };
}

export function adaptBatches(raw) {
  const r = raw && typeof raw === "object" ? raw : {};
  const list = Array.isArray(r.batches) ? r.batches : [];
  return {
    rows: list.map(b => {
      const status = str(b.status) || "active";
      const mfgDate = str(b.mfg_date) || null;
      const expiryDate = str(b.expiry_date) || null;
      return {
        id: b.id,
        batchNo: str(b.batch_no) || "—",
        variantName: str(b.variant_name) || null,
        warehouseName: str(b.warehouse_name) || "—",
        mfgDate, // raw YYYY-MM-DD, intacto
        expiryDate, // raw YYYY-MM-DD, intacto
        mfgDateLabel: mfgDate ? friendlyDate(mfgDate) : null, // solo presentación
        expiryDateLabel: expiryDate ? friendlyDate(expiryDate) : null, // solo presentación
        expiryBucket: str(b.expiry_bucket) || null, // expired | near | valid
        daysToExpiry: Number.isFinite(Number(b.days_to_expiry)) ? Number(b.days_to_expiry) : null,
        qty: Number(b.qty) || 0,
        unitCost: b.unit_cost != null ? Number(b.unit_cost) : null,
        status, // raw, intacto
        statusLabel: BATCH_STATUS_LABEL[status.toLowerCase()] || status // solo presentación
      };
    }),
    expiryWarningDays: Number.isFinite(Number(r.expiry_warning_days)) ? Number(r.expiry_warning_days) : 90,
    totalQty: list.reduce((s, b) => s + (Number(b.qty) || 0), 0),
    expiredCount: list.filter(b => b.expiry_bucket === "expired").length,
    nearCount: list.filter(b => b.expiry_bucket === "near").length,
    hasAnyVariant: list.some(b => !!b.variant_name)
  };
}
