<template>
  <div class="px-next pxkb">
    <px-page-header
      :title="$t('Article_Groups') || 'Grupos de artículos'"
      :breadcrumbs="[
        { label: 'Manual PRODEX', href: '#/app/knowledge-base/list' },
        { label: $t('Article_Groups') || 'Grupos de artículos' }
      ]"
    >
      <template #actions>
        <px-button variant="ghost" size="sm" icon="chevron-left" @click="$router.push({ name: 'KnowledgeBaseList' })">{{ $t('Back') }}</px-button>
        <px-button variant="primary" size="sm" icon="plus" @click="$router.push({ name: 'KnowledgeBaseGroupCreate' })">{{ $t('New') }}</px-button>
      </template>
    </px-page-header>

    <px-toolbar :search="q" :search-placeholder="$t('Search') + '…'" @update:search="v => q = tv(v)" />

    <div v-if="isLoading" class="pxkb__pad">
      <px-skeleton variant="table" :rows="6" :columns="3" />
    </div>

    <template v-else>
      <div class="pxkb__tablewrap">
        <px-table
          v-if="filtered.length"
          :columns="columns"
          :rows="filtered"
          row-key="id"
          has-row-actions
        >
          <template #cell-name="{ row }">
            <div class="pxkb__strong">{{ row.name }}</div>
            <div v-if="row.description" class="pxkb__muted pxkb__clamp">{{ row.description }}</div>
            <code class="pxkb__slug">{{ row.slug }}</code>
          </template>
          <template #cell-articles_count="{ row }">
            <px-badge tone="neutral">{{ row.articles_count != null ? row.articles_count : 0 }}</px-badge>
          </template>
          <template #row-actions="{ row }">
            <div class="pxkb__rowbtns">
              <px-button variant="ghost" size="sm" icon-only icon="pencil" aria-label="Editar" @click="$router.push({ name: 'KnowledgeBaseGroupEdit', params: { id: row.id } })" />
              <px-button class="pxkb__del" variant="ghost" size="sm" icon-only icon="trash-2" aria-label="Eliminar" :disabled="busyId === row.id" @click="destroy(row)" />
            </div>
          </template>
        </px-table>

        <px-empty-state v-else icon="folder" :title="$t('No_items')">
          <px-button variant="primary" icon="plus" @click="$router.push({ name: 'KnowledgeBaseGroupCreate' })">{{ $t('New') }}</px-button>
        </px-empty-state>
      </div>
    </template>
  </div>
</template>

<script>
import PxPageHeader from "@/components/px-next/PxPageHeader.vue";
import PxToolbar from "@/components/px-next/PxToolbar.vue";
import PxTable from "@/components/px-next/PxTable.vue";
import PxButton from "@/components/px-next/PxButton.vue";
import PxBadge from "@/components/px-next/PxBadge.vue";
import PxEmptyState from "@/components/px-next/PxEmptyState.vue";

export default {
  name: 'KnowledgeBaseGroups',
  metaInfo: { title: 'Knowledge Base - Groups' },
  components: { PxPageHeader, PxToolbar, PxTable, PxButton, PxBadge, PxEmptyState },
  data() {
    return { isLoading: true, busyId: null, q: '', groups: [] };
  },
  computed: {
    columns() {
      return [
        { key: 'name', label: this.$t('Name'), strong: true },
        { key: 'articles_count', label: this.$t('Articles'), align: 'center' },
      ];
    },
    filtered() {
      const term = (this.q || '').toLowerCase();
      if (!term) return this.groups;
      return this.groups.filter(g =>
        String(g.name || '').toLowerCase().includes(term) ||
        String(g.slug || '').toLowerCase().includes(term)
      );
    }
  },
  mounted() {
    this.fetch();
  },
  methods: {
    tv(v) { return typeof v === 'string' ? v.trim() : v; },
    makeToast(variant, msg) {
      if (this.$root && this.$root.$bvToast) this.$root.$bvToast.toast(msg, { variant, solid: true });
    },
    async fetch() {
      this.isLoading = true;
      try {
        const res = await axios.get('/knowledge-base/groups');
        this.groups = Array.isArray(res.data) ? res.data : (res.data.data || []);
      } catch (e) {
        this.makeToast('danger', this.$t('Failed_to_load'));
        this.groups = [];
      } finally {
        this.isLoading = false;
      }
    },
    async destroy(g) {
      if (!confirm(this.$t('Confirm_Delete_This_Item'))) return;
      try {
        this.busyId = g.id;
        await axios.delete('/knowledge-base/groups/' + g.id);
        this.makeToast('success', this.$t('Deleted_successfully'));
        this.groups = this.groups.filter(x => x.id !== g.id);
      } catch (e) {
        this.makeToast('danger', this.$t('Delete_failed'));
      } finally {
        this.busyId = null;
      }
    }
  }
};
</script>

<style lang="scss" src="@/assets/styles/sass/px-next/production.scss"></style>

<style lang="scss" scoped>
.pxkb { min-height: 100%; background: var(--pxn-bg); padding: var(--pxn-space-8) var(--pxn-space-9) var(--pxn-space-9); }
@media (max-width: 620px) { .pxkb { padding: var(--pxn-space-6) var(--pxn-space-5); } }
.pxkb__pad { padding: var(--pxn-space-6) 0; }
.pxkb__tablewrap { margin-top: var(--pxn-space-5); }
.pxkb__muted { color: var(--pxn-text-muted); font-size: var(--pxn-fs-sm); }
.pxkb__strong { font-weight: 600; color: var(--pxn-text); }
.pxkb__clamp { max-width: 460px; margin: 2px 0; }
.pxkb__slug { font-size: var(--pxn-fs-xs, 0.75rem); color: var(--pxn-text-muted); background: var(--pxn-surface-2, var(--pxn-surface)); padding: 1px 6px; border-radius: var(--pxn-radius-sm, 4px); }
.pxkb__rowbtns { display: flex; gap: var(--pxn-space-1); justify-content: flex-end; }
.pxkb__del ::v-deep .pxn-btn__icon { color: var(--pxn-danger); }
</style>
