<template>
  <div class="px-next pxsnd">
    <!--
      C3.6 — Detalle de un número de serie / IMEI px-next. Ruta real
      /app/serial_numbers/detail/:id (name detail_serial_number). Conserva
      GET serial_numbers/{id}, el cambio de estado manual
      (POST serial_numbers/{id}/status) — solo para estados en stock
      (available/damaged/reserved) y con permiso `serial_numbers` — y el
      log de movimientos. Español-first solo en la presentación de estados.
    -->
    <div v-if="!can('serial_numbers')" class="pxsnd__denied">
      <px-empty-state icon="lock" title="No tienes permiso para ver números de serie"
        description="Pide a un administrador el permiso «serial_numbers»." />
    </div>

    <template v-else>
      <div v-if="isLoading" class="pxsnd__pad"><px-skeleton variant="card" :rows="6" /></div>

      <px-alert v-else-if="loadError" tone="danger" title="No se pudo cargar el serial" class="pxsnd__alert">
        {{ loadError }}
        <template #actions><px-button size="sm" variant="secondary" @click="loadData()">Reintentar</px-button></template>
      </px-alert>

      <template v-else>
        <px-page-header
          :title="serial.serial_number || '—'"
          :breadcrumbs="[{ label: 'Inventario' }, { label: 'Números de serie' }, { label: serial.serial_number || $route.params.id }]"
        >
          <template #meta>
            <span class="pxn-mono">{{ serial.serial_number }}</span>
          </template>
          <template #actions>
            <px-button variant="ghost" icon="arrow-left" type="button" @click="goBack">Volver</px-button>
          </template>
        </px-page-header>

        <px-card class="pxsnd__hero">
          <div class="pxsnd__hero-top">
            <div class="pxsnd__hero-title">
              <lucide-icon name="scan-barcode" :size="18" />
              <span class="pxn-mono">{{ serial.serial_number }}</span>
              <px-badge :tone="statusTone(serial.status)">{{ statusLabel(serial.status) }}</px-badge>
            </div>
            <div v-if="canChangeStatus" class="pxsnd__hero-actions">
              <px-button v-if="serial.status !== 'available'" size="sm" variant="secondary" icon="check" :loading="saving === 'available'" @click="setStatus('available')">Marcar disponible</px-button>
              <px-button v-if="serial.status !== 'damaged'" size="sm" variant="danger" icon="x" :loading="saving === 'damaged'" @click="setStatus('damaged')">Marcar dañado</px-button>
              <px-button v-if="serial.status !== 'reserved'" size="sm" variant="secondary" icon="clock" :loading="saving === 'reserved'" @click="setStatus('reserved')">Reservar</px-button>
            </div>
          </div>

          <dl class="pxsnd__dl">
            <div><dt>Producto</dt><dd>{{ serial.product_name || '—' }}<span v-if="serial.product_code" class="pxsnd__muted"> ({{ serial.product_code }})</span></dd></div>
            <div v-if="serial.variant_name"><dt>Variante</dt><dd>{{ serial.variant_name }}</dd></div>
            <div><dt>Almacén</dt><dd>{{ serial.warehouse_name || '—' }}</dd></div>
            <div v-if="serial.provider_name"><dt>Proveedor</dt><dd>{{ serial.provider_name }}</dd></div>
            <div v-if="serial.client_name"><dt>Cliente</dt><dd>{{ serial.client_name }}</dd></div>
            <div v-if="serial.created_at"><dt>Registrado</dt><dd>{{ serial.created_at }}</dd></div>
          </dl>
        </px-card>

        <px-card title="Historial de movimientos" class="pxsnd__log">
          <div class="pxsnd-tbl__wrap pxn-scroll">
            <table class="pxsnd-tbl">
              <thead>
                <tr>
                  <th>Fecha</th>
                  <th>Acción</th>
                  <th>Estado</th>
                  <th>Referencia</th>
                  <th>Usuario</th>
                </tr>
              </thead>
              <tbody>
                <tr v-if="!movements.length"><td colspan="5" class="pxsnd-tbl__empty">Sin movimientos registrados.</td></tr>
                <tr v-for="m in movements" :key="m.id">
                  <td>{{ m.created_at }}</td>
                  <td>{{ actionLabel(m.action) }}</td>
                  <td>
                    <span v-if="m.from_status" class="pxsnd__muted">{{ statusLabel(m.from_status) }} → </span>
                    <span>{{ statusLabel(m.to_status) }}</span>
                  </td>
                  <td>
                    <span v-if="m.reference_type">{{ m.reference_type }}<span v-if="m.reference_ref"> · {{ m.reference_ref }}</span></span>
                    <span v-else class="pxn-muted">—</span>
                  </td>
                  <td>{{ m.user_name || '—' }}</td>
                </tr>
              </tbody>
            </table>
          </div>
        </px-card>
      </template>
    </template>
  </div>
</template>

<script>
import { mapGetters } from "vuex";
import NProgress from "nprogress";
import PxPageHeader from "@/components/px-next/PxPageHeader.vue";
import PxCard from "@/components/px-next/PxCard.vue";
import PxButton from "@/components/px-next/PxButton.vue";
import PxBadge from "@/components/px-next/PxBadge.vue";
import PxAlert from "@/components/px-next/PxAlert.vue";
import PxEmptyState from "@/components/px-next/PxEmptyState.vue";
import { SERIAL_STATUS_LABELS, SERIAL_ACTION_LABELS, serialStatusTone } from "./status.js";

export default {
  name: "SerialNumberDetailNext",
  metaInfo: { title: "Detalle de serial" },
  components: { PxPageHeader, PxCard, PxButton, PxBadge, PxAlert, PxEmptyState },
  data() {
    return { isLoading: true, loadError: null, serial: {}, movements: [], saving: null };
  },
  computed: {
    ...mapGetters(["currentUserPermissions"]),
    canChangeStatus() {
      // Igual que el legacy: solo estados en stock son editables a mano, y con permiso.
      return ["available", "damaged", "reserved"].includes(this.serial.status) && this.can("serial_numbers");
    }
  },
  created() {
    this.loadData();
  },
  watch: {
    "$route.params.id"() {
      this.isLoading = true;
      this.loadData();
    }
  },
  methods: {
    can(p) {
      const list = Array.isArray(this.currentUserPermissions) ? this.currentUserPermissions : [];
      return list.includes(p);
    },
    statusLabel(s) {
      return SERIAL_STATUS_LABELS[s] || s;
    },
    statusTone(s) {
      return serialStatusTone(s);
    },
    actionLabel(a) {
      return SERIAL_ACTION_LABELS[a] || a;
    },
    goBack() {
      this.$router.push({ name: "index_serial_numbers" });
    },
    makeToast(variant, msg) {
      this.$root.$bvToast.toast(msg, { variant, solid: true });
    },
    setStatus(status) {
      this.saving = status;
      NProgress.start(); NProgress.set(0.1);
      window.axios
        .post("serial_numbers/" + this.serial.id + "/status", { status })
        .then(() => {
          NProgress.done();
          this.saving = null;
          this.makeToast("success", "Estado actualizado.");
          this.loadData();
        })
        .catch(error => {
          NProgress.done();
          this.saving = null;
          const msg =
            (error.response && error.response.data && error.response.data.errors && error.response.data.errors.status &&
              error.response.data.errors.status[0]) ||
            "No se pudo actualizar el estado.";
          this.makeToast("danger", msg);
        });
    },
    loadData() {
      this.loadError = null;
      NProgress.start(); NProgress.set(0.1);
      const id = this.$route.params.id;
      window.axios
        .get("serial_numbers/" + id)
        .then(response => {
          this.serial = response.data.serial || {};
          this.movements = response.data.movements || [];
          NProgress.done();
          this.isLoading = false;
        })
        .catch(err => {
          NProgress.done();
          this.loadError =
            (err && err.response && err.response.data && (err.response.data.message || err.response.data.error)) ||
            (err && err.message) || "Error de red.";
          setTimeout(() => { this.isLoading = false; }, 300);
        });
    }
  }
};
</script>

<style lang="scss" src="@/assets/styles/sass/px-next/production.scss"></style>

<style lang="scss" scoped>
.pxsnd { min-height: 100%; background: var(--pxn-bg); padding: var(--pxn-space-8) var(--pxn-space-9) var(--pxn-space-9); }
@media (max-width: 620px) { .pxsnd { padding: var(--pxn-space-6) var(--pxn-space-5); } }
.pxsnd__denied { padding: var(--pxn-space-12) 0; }
.pxsnd__pad { padding: var(--pxn-space-6) 0; }
.pxsnd__alert { margin-top: var(--pxn-space-5); }
.pxsnd__muted { color: var(--pxn-ink-3); }

.pxsnd__hero { margin-top: var(--pxn-space-5); }
.pxsnd__hero-top { display: flex; align-items: center; justify-content: space-between; gap: var(--pxn-space-4); flex-wrap: wrap; }
.pxsnd__hero-title { display: inline-flex; align-items: center; gap: var(--pxn-space-3); font-size: var(--pxn-fs-h3); font-weight: var(--pxn-fw-semibold); color: var(--pxn-ink); }
.pxsnd__hero-actions { display: flex; gap: var(--pxn-space-2); flex-wrap: wrap; }
.pxsnd__dl { display: grid; grid-template-columns: repeat(3, minmax(0, 1fr)); gap: var(--pxn-space-4); margin: var(--pxn-space-5) 0 0; }
@media (max-width: 720px) { .pxsnd__dl { grid-template-columns: minmax(0, 1fr); } }
.pxsnd__dl dt { font-size: var(--pxn-fs-xs); color: var(--pxn-ink-3); text-transform: uppercase; letter-spacing: 0.04em; }
.pxsnd__dl dd { margin: var(--pxn-space-1) 0 0; font-size: var(--pxn-fs-sm); color: var(--pxn-ink); }

.pxsnd__log { margin-top: var(--pxn-space-5); }
.pxsnd-tbl__wrap { overflow-x: auto; }
.pxsnd-tbl { width: 100%; border-collapse: collapse; font-size: var(--pxn-fs-sm); }
.pxsnd-tbl th {
  text-align: left; padding: var(--pxn-space-3) var(--pxn-space-4);
  font-size: var(--pxn-fs-xs); font-weight: var(--pxn-fw-semibold);
  text-transform: uppercase; letter-spacing: 0.04em; color: var(--pxn-ink-3);
  background: var(--pxn-surface-2); border-bottom: 1px solid var(--pxn-border); white-space: nowrap;
}
.pxsnd-tbl td { padding: var(--pxn-space-3) var(--pxn-space-4); border-bottom: 1px solid var(--pxn-border); }
.pxsnd-tbl tr:last-child td { border-bottom: 0; }
.pxsnd-tbl__empty { text-align: center; color: var(--pxn-ink-3); }
</style>
