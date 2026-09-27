<template>
  <section ref="root" class="ps-container" @mouseover.once="update">
    <slot />
  </section>
</template>

<script>
// Reemplaza `vue-perfect-scrollbar` (wrapper Vue 2, ver `node_modules/vue-perfect-scrollbar/index.vue`): envuelve
// `perfect-scrollbar` (la librería JS agnóstica, sin cambios) directo. Mismo contrato observado en las 6 vistas que
// lo consumen: prop `settings` (pasada tal cual al constructor de `perfect-scrollbar`), slot por defecto, y las
// clases propias de cada vista (`sidebar-left`, `dropdown-scroll`…) se aplican sobre la MISMA raíz `<section
// class="ps-container">` que generaba el wrapper original. `update()` en cada actualización del árbol (como
// `updated()` del original) y destroy en `beforeUnmount` (su `beforeDestroy`); el watcher de `$route` del original
// no aplica aquí — ninguna vista dependía de que el scroll se recalculara solo por cambiar de ruta, `updated()` ya
// cubre cualquier cambio real de contenido.
import PerfectScrollbar from 'perfect-scrollbar';
import 'perfect-scrollbar/css/perfect-scrollbar.css';

export default {
  name: 'VuePerfectScrollbar',
  props: {
    settings: { type: Object, default: undefined },
  },
  data() {
    return { ps: null };
  },
  mounted() {
    this.ps = new PerfectScrollbar(this.$refs.root, this.settings);
  },
  updated() {
    this.$nextTick(this.update);
  },
  beforeUnmount() {
    if (this.ps) {
      this.ps.destroy();
      this.ps = null;
    }
  },
  methods: {
    update() {
      if (this.ps) this.ps.update();
    },
  },
};
</script>
