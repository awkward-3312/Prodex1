<template>
  <div class="px-next pxcfg">
    <px-page-header
      title="Gestión de dispositivos de inicio de sesión"
      subtitle="Sesiones de inicio de sesión activas de todos los usuarios, por dispositivo o navegador."
      :breadcrumbs="[{ label: $t('Settings'), href: '#/app/settings/System_settings' }, { label: $t('Login_Device_Management') }]"
    >
      <template #actions>
        <px-button variant="secondary" size="sm" icon="refresh-cw"
          :loading="securitySessionsLoading"
          :disabled="securitySessionsLoading || securitySessionsActionLoading"
          @click="LoadSecuritySessions()">Actualizar</px-button>
        <px-button variant="danger" size="sm" icon="log-out"
          :disabled="securitySessionsLoading || securitySessionsActionLoading || !hasOtherSessions"
          @click="LogoutAllOtherDevices()">Cerrar sesión en los demás dispositivos</px-button>
      </template>
    </px-page-header>

    <div v-if="securitySessionsLoading" class="pxcfg__pad">
      <px-skeleton variant="table" :rows="6" :columns="6" />
    </div>

    <div v-else class="pxcfg__tablewrap">
      <px-table
        v-if="securitySessions.length"
        :columns="columns"
        :rows="securitySessions"
        row-key="token_id"
        has-row-actions
      >
        <template #cell-user_name="{ row }">{{ row.user_name || '-' }}</template>
        <template #cell-device="{ row }">
          <span>{{ row.device }}</span>
          <px-badge v-if="row.is_current" tone="success" class="pxcfg__mlbadge">Actual</px-badge>
        </template>
        <template #cell-ip_address="{ row }">{{ row.ip_address || '-' }}</template>
        <template #cell-login_at="{ row }">{{ formatDateTime(row.login_at) }}</template>
        <template #cell-last_activity_at="{ row }">{{ row.last_activity_at ? formatDateTime(row.last_activity_at) : '-' }}</template>
        <template #row-actions="{ row }">
          <px-button variant="ghost" size="sm" icon="log-out" class="pxcfg__del"
            :disabled="securitySessionsLoading || securitySessionsActionLoading || row.is_current"
            @click="LogoutSession(row.token_id)">Cerrar sesión</px-button>
        </template>
      </px-table>
      <px-empty-state v-else icon="log-in" title="Sin sesiones activas" description="No se encontraron sesiones activas." />
    </div>
  </div>
</template>

<script>
import { mapGetters } from "vuex";
import PxPageHeader from "@/components/px-next/PxPageHeader.vue";
import PxTable from "@/components/px-next/PxTable.vue";
import PxButton from "@/components/px-next/PxButton.vue";
import PxBadge from "@/components/px-next/PxBadge.vue";
import PxEmptyState from "@/components/px-next/PxEmptyState.vue";

export default {
  metaInfo: {
    title: "Gestión de dispositivos de inicio de sesión"
  },
  components: { PxPageHeader, PxTable, PxButton, PxBadge, PxEmptyState },
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
    columns() {
      return [
        { key: "user_name", label: "Usuario" },
        { key: "device", label: "Dispositivo / navegador", strong: true },
        { key: "ip_address", label: "Dirección IP" },
        { key: "login_at", label: "Fecha y hora de inicio" },
        { key: "last_activity_at", label: "Última actividad" }
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

<style lang="scss" src="@/assets/styles/sass/px-next/production.scss"></style>

<style lang="scss" scoped>
.pxcfg { min-height: 100%; background: var(--pxn-bg); padding: var(--pxn-space-8) var(--pxn-space-9) var(--pxn-space-9); }
@media (max-width: 620px) { .pxcfg { padding: var(--pxn-space-6) var(--pxn-space-5); } }
.pxcfg__pad { padding: var(--pxn-space-6) 0; }
.pxcfg__tablewrap { margin-top: var(--pxn-space-5); }
.pxcfg__mlbadge { margin-left: var(--pxn-space-3); }
.pxcfg__del ::v-deep .pxn-btn__icon { color: var(--pxn-danger); }
</style>
