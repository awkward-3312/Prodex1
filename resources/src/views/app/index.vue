<template>
  <div>
    <component :is="activeLayout"></component>
  </div>
</template>

<script>
import { mapGetters } from "vuex";

export default {
  components: {},
  data() {
    return {};
  },
  computed: {
    ...mapGetters("config", ["getThemeMode", "getPxShellLayout"]),
    // Milestone 3 — cutover opt-in: si la bandera local está activa, /app/* se
    // monta bajo el shell px-next; si no, sigue el layout legacy sin cambios.
    activeLayout() {
      return this.getPxShellLayout ? "px-shell-layout" : this.getThemeMode.layout;
    }
  },
  created() {
    // ?pxshell=1 / ?pxshell=0 alterna y persiste la bandera (deep-link directo).
    const q = this.$route && this.$route.query ? this.$route.query.pxshell : undefined;
    if (q === "1" || q === "true" || q === "on") {
      this.$store.dispatch("config/setPxShellLayout", true);
    } else if (q === "0" || q === "false" || q === "off") {
      this.$store.dispatch("config/setPxShellLayout", false);
    }
  }
};
</script>
