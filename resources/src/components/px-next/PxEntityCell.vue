<template>
  <div class="pxn-entity" :class="{ 'pxn-entity--tight': tight }">
    <px-avatar
      :name="name"
      :src="avatar"
      :icon="icon"
      :shape="shape"
      :size="tight ? 'sm' : 'md'"
    />
    <div class="pxn-entity__text">
      <div class="pxn-entity__primary">
        <span class="pxn-entity__name">{{ name }}</span>
        <slot name="badge" />
      </div>
      <div v-if="secondary || $slots.secondary" class="pxn-entity__secondary">
        <slot name="secondary">{{ secondary }}</slot>
      </div>
    </div>
  </div>
</template>

<script>
import PxAvatar from "./PxAvatar.vue";
// Two-line identity cell for tables/lists: avatar + name + a muted secondary
// (SKU, RTN/tax id, city, email...). The building block behind dense entity rows.
export default {
  name: "PxEntityCell",
  components: { PxAvatar },
  props: {
    name: { type: String, required: true },
    secondary: { type: String, default: null },
    avatar: { type: String, default: null },
    icon: { type: String, default: null },
    shape: { type: String, default: "circle" },
    tight: { type: Boolean, default: false }
  }
};
</script>

<style lang="scss" scoped>
.pxn-entity { display: flex; align-items: center; gap: var(--pxn-space-4); min-width: 0; }
.pxn-entity__text { min-width: 0; display: flex; flex-direction: column; gap: 1px; }
.pxn-entity__primary { display: flex; align-items: center; gap: var(--pxn-space-3); min-width: 0; }
.pxn-entity__name {
  font-weight: var(--pxn-fw-medium);
  color: var(--pxn-ink);
  white-space: nowrap;
  overflow: hidden;
  text-overflow: ellipsis;
}
.pxn-entity__secondary {
  font-size: var(--pxn-fs-xs);
  color: var(--pxn-ink-3);
  white-space: nowrap;
  overflow: hidden;
  text-overflow: ellipsis;
}
.pxn-entity--tight .pxn-entity__name { font-size: var(--pxn-fs-sm); }
</style>
