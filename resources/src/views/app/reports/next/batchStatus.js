// C3.17 — Presentación español-first del estado de lote en los reportes.
// Mismo mapa que la pantalla de Lotes (C3.5). Solo presentación; el valor raw
// no se toca.

export const BATCH_STATUS_LABELS = {
  active: "Activo",
  expired: "Vencido",
  quarantined: "En cuarentena",
  written_off: "Dado de baja"
};

export function batchStatusLabel(raw) {
  return BATCH_STATUS_LABELS[raw] || raw;
}

export function batchStatusTone(raw) {
  switch (raw) {
    case "active": return "success";
    case "quarantined": return "warning";
    case "expired": return "danger";
    case "written_off": return "neutral";
    default: return "neutral";
  }
}

// Cubeta de caducidad (bucket) que entrega el backend → tono px-next.
export function expiryBucketTone(bucket) {
  switch (bucket) {
    case "expired": return "danger";
    case "near": return "warning";
    case "valid": return "success";
    default: return "neutral";
  }
}
