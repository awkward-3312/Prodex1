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
    // Cutover local: px-next es el layout por DEFECTO de /app/*. `getPxShellLayout`
    // es true salvo override explícito de rollback ('legacy'); en ese caso se
    // monta el layout legacy (`getThemeMode.layout`), que sigue intacto.
    activeLayout() {
      return this.getPxShellLayout ? "px-shell-layout" : this.getThemeMode.layout;
    }
  },
  created() {
    // ?pxshell=0 → rollback a legacy · ?pxshell=1 → px-next. Persiste (deep-link).
    const q = this.$route && this.$route.query ? this.$route.query.pxshell : undefined;
    if (q === "1" || q === "true" || q === "on") {
      this.$store.dispatch("config/setPxShellLayout", true);
    } else if (q === "0" || q === "false" || q === "off") {
      this.$store.dispatch("config/setPxShellLayout", false);
    }
  }
};
</script>
