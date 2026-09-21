<template>
  <bv-probe v-if="$route.query.probe === 'bv'" />
  <playground v-else />
</template>

<script>
// SOLO DESARROLLO (la ruta /app/_ui no existe en producción, ver router.js).
import Playground from "./index.vue";
import BvProbe from "./BvProbe.vue";

export default { name: "UiEntry", components: { Playground, BvProbe } };
</script>
