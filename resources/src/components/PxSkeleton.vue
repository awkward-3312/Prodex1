<template>
  <div class="px-skeleton-group" role="status" aria-live="polite">
    <span class="px-skeleton-sr">{{ label }}</span>

    <!-- Table placeholder: a header bar + N rows of M cells -->
    <div v-if="variant === 'table'" class="px-skeleton-table" aria-hidden="true">
      <div class="px-skeleton-table__row px-skeleton-table__row--head">
        <span
          v-for="c in columns"
          :key="'h' + c"
          class="px-skeleton px-skeleton--sm"
          :style="{ width: headWidth(c) }"
        ></span>
      </div>
      <div
        v-for="r in rows"
        :key="'r' + r"
        class="px-skeleton-table__row"
      >
        <span
          v-for="c in columns"
          :key="r + '-' + c"
          class="px-skeleton"
          :style="{ width: cellWidth(r, c) }"
        ></span>
      </div>
    </div>

    <!-- Stacked text lines -->
    <template v-else-if="variant === 'lines'">
      <span
        v-for="r in rows"
        :key="r"
        class="px-skeleton px-skeleton--line"
        :style="{ width: lineWidth(r) }"
        aria-hidden="true"
      ></span>
    </template>

    <!-- Single block sized by props (card / control / custom) -->
    <span
      v-else
      class="px-skeleton"
      :class="'px-skeleton--' + variant"
      :style="blockStyle"
      aria-hidden="true"
    ></span>
  </div>
</template>

<script>
// Lightweight, self-contained loading placeholder for migrated admin screens.
// Replaces the legacy full-page spinner (`.loading_page`) so a list/form keeps
// its shape while data loads (less layout shift). Honours prefers-reduced-motion.
export default {
  name: "PxSkeleton",
  props: {
    // 'table' | 'lines' | 'card' | 'control' | 'block'
    variant: { type: String, default: "block" },
    rows: { type: [Number, String], default: 6 },
    columns: { type: [Number, String], default: 5 },
    width: { type: String, default: null },
    height: { type: String, default: null },
    label: { type: String, default: "Cargando…" },
  },
  computed: {
    blockStyle() {
      const s = {};
      if (this.width) s.width = this.width;
      if (this.height) s.height = this.height;
      return s;
    },
  },
  methods: {
    // Deterministic pseudo-random widths so the placeholder looks organic
    // without re-shuffling on every re-render.
    pseudo(seed) {
      const x = Math.sin(seed * 12.9898) * 43758.5453;
      return x - Math.floor(x);
    },
    headWidth(c) {
      return 45 + Math.round(this.pseudo(c) * 35) + "%";
    },
    cellWidth(r, c) {
      if (Number(c) === 1) return "60%";
      return 40 + Math.round(this.pseudo(r * 7 + c) * 45) + "%";
    },
    lineWidth(r) {
      if (Number(r) === Number(this.rows)) return "55%";
      return 80 + Math.round(this.pseudo(r) * 18) + "%";
    },
  },
};
</script>

<style scoped>
.px-skeleton-group {
  display: block;
  width: 100%;
}

.px-skeleton-sr {
  position: absolute;
  width: 1px;
  height: 1px;
  padding: 0;
  margin: -1px;
  overflow: hidden;
  clip: rect(0 0 0 0);
  white-space: nowrap;
  border: 0;
}

.px-skeleton {
  position: relative;
  display: block;
  width: 100%;
  height: 16px;
  overflow: hidden;
  background: var(--px-surface-muted, #f1f5f9);
  border-radius: var(--px-radius-sm, 6px);
}

.px-skeleton::after {
  content: "";
  position: absolute;
  inset: 0;
  transform: translateX(-100%);
  background: linear-gradient(
    90deg,
    transparent,
    rgba(255, 255, 255, 0.6),
    transparent
  );
  animation: px-skeleton-shimmer 1.4s ease-in-out infinite;
}

.px-skeleton--sm {
  height: 10px;
}

.px-skeleton--line {
  height: 12px;
  margin-bottom: 10px;
}

.px-skeleton--line:last-child {
  margin-bottom: 0;
}

.px-skeleton--card {
  min-height: 120px;
  height: 100%;
}

.px-skeleton--control {
  height: var(--px-control-height, 40px);
}

.px-skeleton-table {
  width: 100%;
  border: 1px solid var(--px-border, #e2e8f0);
  border-radius: var(--px-radius-lg, 12px);
  overflow: hidden;
  background: var(--px-surface, #fff);
}

.px-skeleton-table__row {
  display: flex;
  align-items: center;
  gap: 24px;
  padding: 16px 20px;
  border-bottom: 1px solid var(--px-border, #e2e8f0);
}

.px-skeleton-table__row:last-child {
  border-bottom: 0;
}

.px-skeleton-table__row--head {
  background: var(--px-surface-muted, #f1f5f9);
}

.px-skeleton-table__row .px-skeleton {
  flex: 1 1 0;
}

@keyframes px-skeleton-shimmer {
  100% {
    transform: translateX(100%);
  }
}

@media (prefers-reduced-motion: reduce) {
  .px-skeleton::after {
    animation: none;
  }
}
</style>
