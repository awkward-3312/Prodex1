<template>
  <div class="main-content">
    <breadcumb :page="$t('Login_Device_Management')" :folder="$t('Settings')" />

    <b-card>
      <div class="d-flex justify-content-between align-items-center mb-2">
        <div>
          <h5 class="mb-1">Gestión de dispositivos de inicio de sesión</h5>
          <p class="text-muted mb-0">
            Sesiones de inicio de sesión activas de todos los usuarios, por dispositivo o navegador.
          </p>
        </div>
        <div class="d-flex">
          <b-button
            variant="outline-primary"
            class="mr-2"
            @click="LoadSecuritySessions()"
            :disabled="securitySessionsLoading || securitySessionsActionLoading"
          >
            Actualizar
          </b-button>
          <b-button
            variant="danger"
            @click="LogoutAllOtherDevices()"
            :disabled="securitySessionsLoading || securitySessionsActionLoading || !hasOtherSessions"
          >
            Cerrar sesión en los demás dispositivos
          </b-button>
        </div>
      </div>

      <div v-if="securitySessionsLoading" class="py-4 text-center text-muted">
        <div class="spinner spinner-primary mr-3"></div>
      </div>

      <b-table
        v-else
        :items="securitySessions"
        :fields="securitySessionFields"
        responsive="sm"
        small
        class="mt-3"
        show-empty
        empty-text="No se encontraron sesiones activas."
      >
        <template #cell(user_name)="row">
          <span>{{ row.item.user_name || '-' }}</span>
        </template>

        <template #cell(device)="row">
          <div class="d-flex align-items-center">
            <span>{{ row.item.device }}</span>
            <b-badge v-if="row.item.is_current" variant="success" class="ms-2">Actual</b-badge>
          </div>
        </template>

        <template #cell(ip_address)="row">
          <span>{{ row.item.ip_address || '-' }}</span>
        </template>

        <template #cell(login_at)="row">
          <span>{{ formatDateTime(row.item.login_at) }}</span>
        </template>

        <template #cell(last_activity_at)="row">
          <span>{{ row.item.last_activity_at ? formatDateTime(row.item.last_activity_at) : '-' }}</span>
        </template>

        <template #cell(actions)="row">
          <b-button
            size="sm"
            variant="danger"
            @click="LogoutSession(row.item.token_id)"
            :disabled="securitySessionsLoading || securitySessionsActionLoading || row.item.is_current"
          >
            Cerrar sesión
          </b-button>
        </template>
      </b-table>
    </b-card>
  </div>
</template>

<script>
import { mapGetters } from "vuex";

export default {
  metaInfo: {
    title: "Gestión de dispositivos de inicio de sesión"
  },
  data() {
    return {
      securitySessions: [],
      securitySessionsLoading: false,
      securitySessionsActionLoading: false,
    };
  },
  computed: {
    ...mapGetters(["currentUserPermissions"]),
    hasOtherSessions() {
      return (this.securitySessions || []).some(s => !s.is_current);
    },
    securitySessionFields() {
      return [
        { key: "user_name", label: "Usuario", tdClass: "text-left", thClass: "text-left" },
        { key: "device", label: "Dispositivo / navegador", tdClass: "text-left", thClass: "text-left" },
        { key: "ip_address", label: "Dirección IP", tdClass: "text-left", thClass: "text-left" },
        { key: "login_at", label: "Fecha y hora de inicio", tdClass: "text-left", thClass: "text-left" },
        { key: "last_activity_at", label: "Última actividad", tdClass: "text-left", thClass: "text-left" },
        { key: "actions", label: "Acción", tdClass: "text-right", thClass: "text-right" }
      ];
    }
  },
  created() {
    const perms = this.currentUserPermissions || [];
    const allowed = perms.includes("login_device_management") || perms.includes("setting_system");
    if (!allowed) {
      this.$router.push({ name: "not_authorize" });
      return;
    }
    this.LoadSecuritySessions();
  },
  methods: {
    formatDateTime(v) {
      try {
        if (!v) return "";
        const d = new Date(v);
        if (isNaN(d.getTime())) return String(v);
        return d.toLocaleString();
      } catch (e) {
        return String(v || "");
      }
    },
    makeToast(variant, msg, title) {
      this.$root.$bvToast.toast(msg, {
        title: title,
        variant: variant,
        solid: true
      });
    },
    LoadSecuritySessions() {
      if (this.securitySessionsLoading) return;
      this.securitySessionsLoading = true;
      axios
        .get("security/sessions")
        .then(response => {
          this.securitySessions = (response && response.data && response.data.sessions) ? response.data.sessions : [];
        })
        .catch(error => {
          const msg =
            (error && error.response && error.response.data && (error.response.data.message || error.response.data.error)) ||
            this.$t("Failed");
          this.makeToast("danger", msg, this.$t("Failed"));
        })
        .finally(() => {
          this.securitySessionsLoading = false;
        });
    },
    LogoutSession(tokenId) {
      if (!tokenId || this.securitySessionsActionLoading) return;
      this.securitySessionsActionLoading = true;
      axios
        .delete(`security/sessions/${encodeURIComponent(tokenId)}`)
        .then(() => {
          this.makeToast("success", "Sesión cerrada correctamente.", this.$t("Success"));
          this.LoadSecuritySessions();
        })
        .catch(error => {
          const msg =
            (error && error.response && error.response.data && (error.response.data.message || error.response.data.error)) ||
            this.$t("Failed");
          this.makeToast("danger", msg, this.$t("Failed"));
        })
        .finally(() => {
          this.securitySessionsActionLoading = false;
        });
    },
    LogoutAllOtherDevices() {
      if (this.securitySessionsActionLoading) return;
      this.securitySessionsActionLoading = true;
      axios
        .post("security/sessions/logout-other")
        .then(response => {
          const revoked = response && response.data && typeof response.data.revoked !== "undefined" ? response.data.revoked : null;
          const msg = revoked === null ? "Se cerraron las sesiones de los demás dispositivos." : `Se cerró la sesión en ${revoked} dispositivo(s) adicional(es).`;
          this.makeToast("success", msg, this.$t("Success"));
          this.LoadSecuritySessions();
        })
        .catch(error => {
          const msg =
            (error && error.response && error.response.data && (error.response.data.message || error.response.data.error)) ||
            this.$t("Failed");
          this.makeToast("danger", msg, this.$t("Failed"));
        })
        .finally(() => {
          this.securitySessionsActionLoading = false;
        });
    }
  }
};
</script>
