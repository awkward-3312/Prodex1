<template>
  <div class="pxn-toaster" aria-live="polite" aria-atomic="false">
    <transition-group name="pxn-toast" tag="div" class="pxn-toaster__stack">
      <div v-for="t in items" :key="t.id" class="pxn-toast" :class="`is-${t.tone || 'neutral'}`" role="status">
        <lucide-icon :name="iconFor(t.tone)" :size="15" class="pxn-toast__ico" />
        <span class="pxn-toast__msg">{{ t.message }}</span>
        <button type="button" class="pxn-toast__x pxn-ring" aria-label="Cerrar" @click="$emit('dismiss', t.id)">
          <lucide-icon name="x" :size="13" />
        </button>
      </div>
    </transition-group>
  </div>
</template>

<script>
// Transient feedback. Slides in from the edge, self-dismisses. Movement here is
// orientation ("something happened, over here"), not decoration.
const ICONS = { success: "check-circle", danger: "alert-circle", warning: "alert-triangle", info: "info", neutral: "bell" };
export default {
  name: "PxToast",
  props: { items: { type: Array, default: () => [] } },
  methods: { iconFor(tone) { return ICONS[tone] || ICONS.neutral; } }
};
</script>

<style lang="scss" scoped>
.pxn-toaster { position: fixed; right: var(--pxn-space-8); bottom: var(--pxn-space-8); z-index: var(--pxn-z-toast); pointer-events: none; }
.pxn-toaster__stack { display: flex; flex-direction: column; gap: var(--pxn-space-3); align-items: flex-end; }
.pxn-toast {
  pointer-events: auto;
  display: inline-flex;
  align-items: center;
  gap: var(--pxn-space-4);
  max-width: 360px;
  padding: var(--pxn-space-4) var(--pxn-space-4) var(--pxn-space-4) var(--pxn-space-5);
  background: var(--pxn-ink);
  color: #fff;
  border-radius: var(--pxn-radius-md);
  box-shadow: var(--pxn-shadow-menu);
  font-size: var(--pxn-fs-sm);
}
.pxn-toast__ico { flex: none; }
.pxn-toast.is-success .pxn-toast__ico { color: #6ee7a8; }
.pxn-toast.is-danger .pxn-toast__ico { color: #fca5a5; }
.pxn-toast.is-warning .pxn-toast__ico { color: #fcd48a; }
.pxn-toast.is-info .pxn-toast__ico { color: #9dc3fb; }
.pxn-toast__msg { flex: 1; }
.pxn-toast__x {
  flex: none; display: inline-flex; align-items: center; justify-content: center;
  width: 20px; height: 20px; border: 0; border-radius: var(--pxn-radius-xs);
  background: transparent; color: rgba(255, 255, 255, 0.6); cursor: pointer;
}
.pxn-toast__x:hover { background: rgba(255, 255, 255, 0.12); color: #fff; }

.pxn-toast-enter-active { transition: opacity var(--pxn-dur-2) var(--pxn-ease-out), transform var(--pxn-dur-2) var(--pxn-ease-out); }
.pxn-toast-leave-active { transition: opacity var(--pxn-dur-1) var(--pxn-ease-in), transform var(--pxn-dur-1) var(--pxn-ease-in); position: absolute; }
.pxn-toast-enter, .pxn-toast-leave-to { opacity: 0; transform: translateX(16px); }
</style>
