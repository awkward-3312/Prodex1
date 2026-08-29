<template>
  <span class="pxn-tag" :class="{ 'pxn-tag--removable': removable }" :style="tagStyle">
    <span class="pxn-tag__dot" aria-hidden="true"></span>
    <span class="pxn-tag__label"><slot>{{ label }}</slot></span>
    <button
      v-if="removable"
      type="button"
      class="pxn-tag__x pxn-ring"
      :aria-label="`Quitar ${label}`"
      @click="$emit('remove')"
    ><lucide-icon name="x" :size="11" /></button>
  </span>
</template>

<script>
// Entity tag — categorías, tipos de documento, centros de costo, etc.
// Uses the AUXILIARY low-saturation palette (--pxn-tag-*), deliberately separate
// from the tenant primary and from semantic status colours. The label always
// carries the meaning; the coloured dot is a secondary cue, not the message.
const HUES = ["slate", "indigo", "teal", "plum", "clay", "moss"];
export default {
  name: "PxTag",
  props: {
    label: { type: String, default: "" },
    hue: { type: String, default: "slate" }, // slate | indigo | teal | plum | clay | moss  (or a stable string to hash)
    removable: { type: Boolean, default: false }
  },
  computed: {
    resolvedHue() {
      if (HUES.includes(this.hue)) return this.hue;
      let h = 0;
      const s = String(this.hue || this.label);
      for (let i = 0; i < s.length; i += 1) h = (h * 31 + s.charCodeAt(i)) >>> 0;
      return HUES[h % HUES.length];
    },
    tagStyle() {
      const h = this.resolvedHue;
      return {
        "--_dot": `var(--pxn-tag-${h})`,
        "--_bg": `var(--pxn-tag-${h}-soft)`,
        "--_ink": `var(--pxn-tag-${h}-ink)`
      };
    }
  }
};
</script>

<style lang="scss" scoped>
.pxn-tag {
  display: inline-flex;
  align-items: center;
  gap: var(--pxn-space-3);
  height: 20px;
  padding: 0 var(--pxn-space-4);
  border-radius: var(--pxn-radius-sm);
  background: var(--_bg);
  color: var(--_ink);
  font-size: var(--pxn-fs-xs);
  font-weight: var(--pxn-fw-medium);
  line-height: 1;
  white-space: nowrap;
}
.pxn-tag__dot { width: 6px; height: 6px; border-radius: 2px; background: var(--_dot); flex: none; }
.pxn-tag--removable { padding-right: var(--pxn-space-2); }
.pxn-tag__x {
  display: inline-flex;
  align-items: center;
  justify-content: center;
  width: 16px; height: 16px;
  margin-left: 2px;
  border: 0;
  border-radius: var(--pxn-radius-xs);
  background: transparent;
  color: inherit;
  cursor: pointer;
  opacity: 0.7;
}
.pxn-tag__x:hover { opacity: 1; background: rgba(0, 0, 0, 0.06); }
</style>
