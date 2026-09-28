/**
 * Seguimiento visual de un traslado. Traduce los estados REALES del backend
 * (approval_status, statut, logistics_status + transfer_events) a los pasos del
 * TransferProgress. No inventa estados ni fechas: si el backend no entrega un
 * timestamp, el paso no muestra fecha.
 *
 *   Creada → Aprobada → Enviada → Recibida
 *
 * Estados de paso: done | current | pending | rejected | skipped
 * Un valor de backend desconocido nunca marca un paso como completado.
 */
import { TRANSFER_APPROVAL_LABELS, TRANSFER_LOGISTICS_LABELS } from "./statusMaps.js";

const LOGISTICS_KNOWN = ["pending", "in_transit", "partially_received", "received", "received_with_issues"];
const APPROVAL_KNOWN = ["pending", "approved", "rejected"];

function lastEvent(events, types) {
  const list = (Array.isArray(events) ? events : []).filter(e => types.includes(String(e.event_type || "").toLowerCase()));
  return list.length ? list[list.length - 1] : null;
}

function step(key, title, state, extra) {
  return Object.assign({ key, title, state, badge: "", tone: "neutral", at: null, by: null, note: null }, extra || {});
}

export function buildTransferSteps({ transfer = {}, workflowTransfer = {}, events = [] } = {}) {
  const wf = workflowTransfer || {};
  const approvalRaw = String(wf.approval_status != null ? wf.approval_status : (transfer.approval_status != null ? transfer.approval_status : "")).toLowerCase();
  // El backend trata approval_status vacío como aprobado (traslados anteriores al flujo de aprobación).
  const approval = approvalRaw === "" ? "approved" : approvalRaw;
  const logistics = String(wf.logistics_status || transfer.logistics_status || "pending").toLowerCase();
  const statut = String(wf.status || transfer.statut || "").toLowerCase();

  const evCreated = lastEvent(events, ["created"]);
  const evApproved = lastEvent(events, ["approved"]);
  const evRejected = lastEvent(events, ["rejected"]);
  const evDispatched = lastEvent(events, ["dispatched", "dispatch"]);
  const evReceived = lastEvent(events, ["received"]);
  const evPartial = lastEvent(events, ["partial_receipt"]);

  const known = APPROVAL_KNOWN.includes(approval) && LOGISTICS_KNOWN.includes(logistics);
  // Traslados anteriores al flujo logístico: statut=completed con logística sin registrar.
  const legacyCompleted = statut === "completed" && logistics === "pending" && approval === "approved";

  const created = step("created", "Creada", "done", {
    badge: "Completado", tone: "success",
    at: (evCreated && evCreated.created_at) || transfer.date || null,
    by: evCreated ? evCreated.actor_name : null
  });
  const approved = step("approved", "Aprobada", "pending", { badge: "Pendiente" });
  const sent = step("sent", "Enviada", "pending", { badge: "Pendiente" });
  const received = step("received", "Recibida", "pending", { badge: "Pendiente" });

  const done = (s, at, by, badge) => Object.assign(s, { state: "done", badge: badge || "Completado", tone: "success", at: at || null, by: by || null });
  const current = (s, badge, tone, at, by) => Object.assign(s, { state: "current", badge, tone, at: at || null, by: by || null });

  if (!known) {
    // Estado desconocido: solo lo que sabemos con certeza (la creación) y aviso explícito.
    return { steps: [created, approved, sent, received], unknown: true, unknownValue: !APPROVAL_KNOWN.includes(approval) ? approval : logistics, terminal: null };
  }

  if (approval === "rejected") {
    Object.assign(approved, {
      title: "Rechazada", state: "rejected", badge: TRANSFER_APPROVAL_LABELS.rejected, tone: "danger",
      at: evRejected ? evRejected.created_at : null, by: evRejected ? evRejected.actor_name : null,
      note: evRejected && evRejected.payload && evRejected.payload.reason ? evRejected.payload.reason : null
    });
    Object.assign(sent, { state: "skipped", badge: "No aplica" });
    Object.assign(received, { state: "skipped", badge: "No aplica" });
    return { steps: [created, approved, sent, received], unknown: false, terminal: "rejected" };
  }

  if (approval === "pending") {
    current(approved, TRANSFER_APPROVAL_LABELS.pending, "warning");
    return { steps: [created, approved, sent, received], unknown: false, terminal: null };
  }

  done(approved, evApproved && evApproved.created_at, evApproved && evApproved.actor_name);

  if (legacyCompleted) {
    done(sent); done(received);
    return { steps: [created, approved, sent, received], unknown: false, terminal: null };
  }

  // Fuente primaria: transfer_events (misma zona/formato que la línea de tiempo existente); respaldo: columnas del traslado.
  const dispatchedAt = (evDispatched && evDispatched.created_at) || wf.dispatched_at || null;
  const dispatchedBy = evDispatched ? evDispatched.actor_name : null;

  if (logistics === "pending") {
    current(sent, TRANSFER_LOGISTICS_LABELS.pending, "neutral");
    return { steps: [created, approved, sent, received], unknown: false, terminal: null };
  }
  if (logistics === "in_transit") {
    current(sent, TRANSFER_LOGISTICS_LABELS.in_transit, "info", dispatchedAt, dispatchedBy);
    return { steps: [created, approved, sent, received], unknown: false, terminal: null };
  }

  done(sent, dispatchedAt, dispatchedBy);

  if (logistics === "partially_received") {
    current(received, TRANSFER_LOGISTICS_LABELS.partially_received, "warning", evPartial && evPartial.created_at, evPartial && evPartial.actor_name);
    return { steps: [created, approved, sent, received], unknown: false, terminal: null };
  }

  const receivedAt = (evReceived && evReceived.created_at) || wf.received_at || null;
  const receivedBy = evReceived ? evReceived.actor_name : null;
  if (logistics === "received_with_issues") {
    done(received, receivedAt, receivedBy, TRANSFER_LOGISTICS_LABELS.received_with_issues);
    received.tone = "danger";
    received.flag = true;
    return { steps: [created, approved, sent, received], unknown: false, terminal: null };
  }
  // received
  done(received, receivedAt, receivedBy);
  return { steps: [created, approved, sent, received], unknown: false, terminal: null };
}
