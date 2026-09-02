// C3.6 — Presentación español-first de los estados técnicos de seriales y de
// las acciones del historial. Los VALORES RAW no se tocan: esto es solo la capa
// de presentación (label + tono de badge px-next).

export const SERIAL_STATUS_LABELS = {
  available: "Disponible",
  sold: "Vendido",
  returned_customer: "Devuelto por cliente",
  returned_supplier: "Devuelto a proveedor",
  damaged: "Dañado",
  reserved: "Reservado"
};

export const SERIAL_ACTION_LABELS = {
  purchased: "Comprado",
  sold: "Vendido",
  sale_returned: "Devolución de venta",
  purchase_returned: "Devolución de compra",
  status_changed: "Cambio de estado",
  adjusted: "Ajustado"
};

export function serialStatusTone(status) {
  switch (status) {
    case "available": return "success";
    case "sold": return "info";
    case "returned_customer": return "info";
    case "returned_supplier": return "warning";
    case "damaged": return "danger";
    case "reserved": return "neutral";
    default: return "neutral";
  }
}
