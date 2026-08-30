<template>
  <div class="pxp-cell">
    <span class="pxp-cell__thumb" :class="{ 'is-fallback': !showImage }" aria-hidden="true">
      <img
        v-if="showImage"
        :src="row.imageUrl"
        :alt="''"
        loading="lazy"
        decoding="async"
        @error="broken = true"
      />
      <lucide-icon v-else name="package" :size="18" />
    </span>
    <span class="pxp-cell__text">
      <span class="pxp-cell__name" :title="row.name">{{ row.name }}</span>
      <span class="pxp-cell__sku pxn-mono" :title="row.code">{{ row.code }}</span>
    </span>
  </div>
</template>

<script>
// Celda de identidad de producto: miniatura + nombre + SKU.
// Imagen real cuando el endpoint da una válida; si falta o falla la carga se
// muestra un marcador px-next consistente. El contenedor tiene tamaño fijo →
// cero layout shift entre "con imagen" y "sin imagen".
export default {
  name: "PxProductCell",
  props: {
    row: { type: Object, required: true }
  },
  data() {
    return { broken: false };
  },
  computed: {
    showImage() {
      return !!this.row.hasImage && !!this.row.imageUrl && !this.broken;
    }
  },
  watch: {
    "row.imageUrl"() { this.broken = false; }
  }
};
</script>

<style lang="scss" scoped>
/* ancho acotado: la tabla usa table-layout:auto, así que sin un límite duro el
   nombre sin cortes ensancharía la columna y empujaría al resto fuera de vista. */
.pxp-cell { display: flex; align-items: center; gap: var(--pxn-space-4); width: 248px; max-width: 248px; }
.pxn-doc--compact .pxp-cell { width: 208px; max-width: 208px; }

.pxp-cell__thumb {
  flex: none;
  width: 40px; height: 40px;
  display: inline-flex; align-items: center; justify-content: center;
  border: 1px solid var(--pxn-border);
  border-radius: var(--pxn-radius-md);
  background: var(--pxn-surface-2);
  overflow: hidden;
  color: var(--pxn-ink-disabled);
}
.pxp-cell__thumb img { width: 100%; height: 100%; object-fit: cover; display: block; }
.pxp-cell__thumb.is-fallback { background: var(--pxn-surface-2); }

.pxp-cell__text { min-width: 0; display: flex; flex-direction: column; gap: 1px; }
.pxp-cell__name {
  font-weight: var(--pxn-fw-medium); color: var(--pxn-ink);
  white-space: nowrap; overflow: hidden; text-overflow: ellipsis;
}
.pxp-cell__sku {
  font-size: var(--pxn-fs-xs); color: var(--pxn-ink-3);
  white-space: nowrap; overflow: hidden; text-overflow: ellipsis;
}

.pxn-doc--compact .pxp-cell__thumb { width: 32px; height: 32px; }
</style>
