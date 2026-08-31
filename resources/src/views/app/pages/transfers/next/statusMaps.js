/**
 * C3.24–C3.26 — Traslados px-next: mapas de estado español-first SOLO para
 * presentación. Los valores crudos (statut, approval_status, logistics_status)
 * viajan intactos hacia/desde el backend; aquí únicamente se traducen para la UI.
 *
 * No inventamos estados nuevos: cada clave es un valor real que hoy devuelven
 * FinalTransferController@index, TransferWorkflowController@payload y
 * FinalTransferLogisticsController.
 */

// transfer.statut  (completed | sent | pending)
export const TRANSFER_STATUT_LABELS = {
  completed: "Completado",
  sent: "Enviado",
  pending: "Pendiente"
};

// transfer.approval_status  (null|"" | approved | pending | rejected)
export const TRANSFER_APPROVAL_LABELS = {
  approved: "Aprobado",
  pending: "Pendiente de aprobación",
  rejected: "Rechazado"
};

// transfer.logistics_status  (pending | in_transit | partially_received | received | received_with_issues)
export const TRANSFER_LOGISTICS_LABELS = {
  pending: "Sin despachar",
  in_transit: "En tránsito",
  partially_received: "Recepción parcial",
  received: "Recibida",
  received_with_issues: "Recibida con incidencias"
};

// transfer_events[].event_type  (sólo etiqueta legible; no altera el payload)
export const TRANSFER_EVENT_LABELS = {
  created: "Creada",
  approved: "Aprobada",
  rejected: "Rechazada",
  rejection_note: "Motivo de rechazo",
  dispatched: "Despachada",
  dispatch: "Despachada",
  received: "Recibida",
  partial_receipt: "Recepción parcial",
  receipt: "Recepción registrada",
  discrepancy_reported: "Incidencia reportada",
  discrepancy_resolved: "Incidencia resuelta",
  notification_sent: "Notificación enviada"
};

// transfer_discrepancies[].type
export const DISCREPANCY_TYPE_LABELS = {
  defective: "Defectuoso",
  missing: "Faltante"
};

// transfer_discrepancies[].resolution_status
export const DISCREPANCY_RESOLUTION_STATUS_LABELS = {
  open: "Abierta",
  resolved: "Resuelta"
};

export function approvalTone(value) {
  const v = String(value || "").toLowerCase();
  if (v === "rejected") return "danger";
  if (v === "pending") return "warning";
  return "success"; // approved / null / ""
}

export function statutTone(value) {
  const v = String(value || "").toLowerCase();
  if (v === "completed") return "success";
  if (v === "sent") return "info";
  return "neutral";
}

export function logisticsTone(value) {
  const v = String(value || "").toLowerCase();
  if (v === "received") return "success";
  if (v === "received_with_issues") return "danger";
  if (v === "partially_received") return "warning";
  if (v === "in_transit") return "info";
  return "neutral";
}

export function approvalLabel(value) {
  const v = String(value || "").toLowerCase();
  if (!v || v === "approved") return TRANSFER_APPROVAL_LABELS.approved;
  return TRANSFER_APPROVAL_LABELS[v] || value;
}

export function statutLabel(value) {
  return TRANSFER_STATUT_LABELS[String(value || "").toLowerCase()] || value || "—";
}

export function logisticsLabel(value) {
  const v = String(value || "").toLowerCase();
  if (!v) return TRANSFER_LOGISTICS_LABELS.pending;
  return TRANSFER_LOGISTICS_LABELS[v] || value;
}

export function eventLabel(value) {
  return TRANSFER_EVENT_LABELS[String(value || "").toLowerCase()] || value || "Evento";
}
