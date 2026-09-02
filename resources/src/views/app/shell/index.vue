<template>
  <!--
    Contenedor de ruta del shell px-next navegable (/app/shell/*).
    Envuelve la página real (router-view hijo) en el chrome px-next aprobado.
    No sustituye largeSidebar/index.vue: es una ruta propia, phased adoption
    igual que C0 (/app/dashboard) y C1 (/app/products/list).
  -->
  <px-shell>
    <router-view />
  </px-shell>
</template>

<script>
import PxShell from "@/components/px-next/PxShell.vue";

export default {
  name: "AppShell",
  components: { PxShell }
};
</script>

<style lang="scss" src="@/assets/styles/sass/px-next/production.scss"></style>
