<template>
  <!--
    Milestone 3 — px-next como layout PERSISTENTE de /app/* (opt-in local).
    Sustituye a largeSidebar/index.vue SÓLO cuando la bandera
    `config/getPxShellLayout` está activa (localStorage `pxnShellLayout` o
    ?pxshell=1). El layout legacy queda intacto como fallback por defecto.

    · Ruta normal   → <px-shell> envuelve la página real (router-view hijo)
                      en el chrome px-next aprobado (rail + panel + topbar).
    · Ruta excluida → contexto fullscreen/operativo (POS, customer display,
                      kitchen, wallboard, dashboard 3D): la página se monta
                      SIN chrome, igual que hoy.

    No se remonta TopNav/Sidebar legacy: PxShell ya embebe su propio <top-nav/>.
  -->
  <router-view v-if="excluded" />
  <px-shell v-else>
    <router-view />
  </px-shell>
</template>

<script>
import PxShell from "@/components/px-next/PxShell.vue";
import { isShellExcluded } from "@/views/app/_ui/data/shell-nav";

export default {
  name: "PxShellLayout",
  components: { PxShell },
  computed: {
    excluded() {
      return isShellExcluded(this.$route.path);
    }
  }
};
</script>

<style lang="scss" src="@/assets/styles/sass/px-next/production.scss"></style>
