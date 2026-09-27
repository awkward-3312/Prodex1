<template>
  <ui-probe v-if="$route.query.probe === 'ui'" />
  <playground v-else />
</template>

<script>
// SOLO DESARROLLO (la ruta /app/_ui no existe en producción, ver router.js).
import Playground from "./index.vue";
import UiProbe from "./UiProbe.vue";

export default { name: "UiEntry", components: { Playground, UiProbe } };
</script>
