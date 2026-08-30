<template>
  <span
    class="pxn-avatar"
    :class="[`pxn-avatar--${size}`, `pxn-avatar--${shape}`]"
    :style="!src ? bgStyle : null"
    role="img"
    :aria-label="name"
  >
    <img v-if="src" :src="src" :alt="name" class="pxn-avatar__img" />
    <lucide-icon v-else-if="icon" :name="icon" :size="iconPx" />
    <span v-else class="pxn-avatar__initials">{{ initials }}</span>
    <span v-if="status" class="pxn-avatar__status" :class="`is-${status}`" aria-hidden="true"></span>
  </span>
</template>

<script>
// Entity avatar — person or organisation. Initials fallback gets a deterministic
// low-saturation tint from the auxiliary palette (never the tenant accent).
const HUES = ["slate", "indigo", "teal", "plum", "clay", "moss"];
export default {
  name: "PxAvatar",
  props: {
    name: { type: String, default: "" },
    src: { type: String, default: null },
    icon: { type: String, default: null },
    size: { type: String, default: "md" }, // xs | sm | md | lg
    shape: { type: String, default: "circle" }, // circle | square
    status: { type: String, default: null } // online | busy | offline
  },
  computed: {
    initials() {
      return (this.name || "")
        .split(/\s+/).filter(Boolean).slice(0, 2)
        .map(w => w[0]).join("").toUpperCase() || "?";
    },
    iconPx() { return { xs: 12, sm: 14, md: 16, lg: 20 }[this.size] || 16; },
    bgStyle() {
      let h = 0;
      const s = this.name || "?";
      for (let i = 0; i < s.length; i += 1) h = (h * 31 + s.charCodeAt(i)) >>> 0;
      const hue = HUES[h % HUES.length];
      return { background: `var(--pxn-tag-${hue}-soft)`, color: `var(--pxn-tag-${hue}-ink)` };
    }
  }
};
</script>

<style lang="scss" scoped>
.pxn-avatar {
  position: relative;
  display: inline-flex;
  align-items: center;
  justify-content: center;
  flex: none;
  overflow: visible;
  font-weight: var(--pxn-fw-semibold);
  border: 1px solid var(--pxn-border);
  background: var(--pxn-surface-2);
  color: var(--pxn-ink-2);
}
.pxn-avatar--circle { border-radius: 999px; }
.pxn-avatar--square { border-radius: var(--pxn-radius-sm); }
.pxn-avatar--xs { width: 20px; height: 20px; font-size: 9px; }
.pxn-avatar--sm { width: 26px; height: 26px; font-size: 10px; }
.pxn-avatar--md { width: 32px; height: 32px; font-size: 12px; }
.pxn-avatar--lg { width: 40px; height: 40px; font-size: 14px; }
.pxn-avatar__img { width: 100%; height: 100%; object-fit: cover; border-radius: inherit; }
.pxn-avatar__initials { line-height: 1; }
.pxn-avatar__status {
  position: absolute;
  right: -1px; bottom: -1px;
  width: 9px; height: 9px;
  border-radius: 999px;
  border: 2px solid var(--pxn-surface);
}
.pxn-avatar__status.is-online { background: var(--pxn-success); }
.pxn-avatar__status.is-busy { background: var(--pxn-warning); }
.pxn-avatar__status.is-offline { background: var(--pxn-ink-3); }
</style>
