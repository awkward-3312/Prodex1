<template>
  <px-menu :items="items" :align="align" @select="$emit('select', $event)">
    <template #trigger="{ open }">
      <button
        type="button"
        class="pxn-kebab pxn-ring"
        :class="{ 'is-open': open }"
        :aria-label="label"
        aria-haspopup="menu"
        :aria-expanded="open ? 'true' : 'false'"
      >
        <lucide-icon :name="horizontal ? 'more-vertical' : 'more-vertical'" :size="16" />
      </button>
    </template>
  </px-menu>
</template>

<script>
import PxMenu from "./PxMenu.vue";
// Row / card "more actions" affordance. Thin wrapper over PxMenu so every
// kebab in the system behaves and looks identical.
export default {
  name: "PxKebab",
  components: { PxMenu },
  props: {
    items: { type: Array, required: true },
    align: { type: String, default: "end" },
    horizontal: { type: Boolean, default: false },
    label: { type: String, default: "Más acciones" }
  }
};
</script>

<style lang="scss" scoped>
.pxn-kebab {
  display: inline-flex;
  align-items: center;
  justify-content: center;
  width: 32px; height: 32px;
  border: 0;
  border-radius: var(--pxn-radius-sm);
  background: transparent;
  color: var(--pxn-ink-3);
  cursor: pointer;
  transition: background-color var(--pxn-dur-1) var(--pxn-ease), color var(--pxn-dur-1) var(--pxn-ease);
}
.pxn-kebab:hover, .pxn-kebab.is-open { background: var(--pxn-surface-3); color: var(--pxn-ink); }
</style>
