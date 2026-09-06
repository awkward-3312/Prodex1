<template>
  <div class="px-next pxkb">
    <px-page-header
      :title="$t('Articles')"
      :breadcrumbs="[
        { label: 'Manual PRODEX', href: '#/app/knowledge-base/list' },
        { label: $t('Articles') }
      ]"
    >
      <template #actions>
        <px-button variant="ghost" size="sm" icon="chevron-left" @click="$router.push({ name: 'KnowledgeBaseList' })">{{ $t('Back') }}</px-button>
        <px-button variant="primary" size="sm" icon="plus" @click="$router.push({ name: 'KnowledgeBaseArticleCreate' })">{{ $t('New_Article') || 'Nuevo artículo' }}</px-button>
      </template>
    </px-page-header>

    <px-toolbar
      :search="searchQ"
      :search-placeholder="$t('Search') + '…'"
      @update:search="v => searchQ = tv(v)"
    >
      <template #filters>
        <vs-px
          style="min-width: 200px"
          :options="groupSelectOptions"
          :reduce="o => o.value"
          :value="filterGroupId"
          :placeholder="$t('All_Groups') || 'Todos los grupos'"
          @input="v => filterGroupId = v || null"
        />
      </template>
      <template #actions>
        <px-button variant="secondary" size="sm" @click="fetch">{{ $t('Search') }}</px-button>
      </template>
    </px-toolbar>

    <div v-if="isLoading" class="pxkb__pad">
      <px-skeleton variant="table" :rows="8" :columns="3" />
    </div>

    <template v-else>
      <div class="pxkb__tablewrap">
        <px-table
          v-if="articles.length"
          :columns="columns"
          :rows="articles"
          row-key="id"
          has-row-actions
        >
          <template #cell-title="{ row }">
            <div class="pxkb__strong">{{ row.title }}</div>
            <div class="pxkb__row-meta">
              <span class="pxkb__row-group">{{ row.group ? row.group.name : '—' }}</span>
              <px-badge v-if="row.is_internal" tone="warning">{{ $t('Internal') }}</px-badge>
            </div>
          </template>
          <template #row-actions="{ row }">
            <div class="pxkb__rowbtns">
              <px-button variant="ghost" size="sm" icon-only icon="eye" aria-label="Ver" @click="$router.push({ name: 'KnowledgeBaseArticleView', params: { id: row.id } })" />
              <px-button variant="ghost" size="sm" icon-only icon="pencil" aria-label="Editar" @click="$router.push({ name: 'KnowledgeBaseArticleEdit', params: { id: row.id } })" />
              <px-button class="pxkb__del" variant="ghost" size="sm" icon-only icon="trash-2" aria-label="Eliminar" :disabled="busyId === row.id" @click="destroy(row)" />
            </div>
          </template>
        </px-table>

        <px-empty-state v-else icon="files" :title="$t('No_items')">
          <px-button variant="primary" icon="plus" @click="$router.push({ name: 'KnowledgeBaseArticleCreate' })">{{ $t('New_Article') || 'Nuevo artículo' }}</px-button>
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
import VsPx from "@/views/app/products/next/edit/VsPx.vue";

export default {
  name: 'KnowledgeBaseArticles',
  metaInfo: { title: 'Knowledge Base - Articles' },
  components: { PxPageHeader, PxToolbar, PxTable, PxButton, PxBadge, PxEmptyState, VsPx },
  data() {
    return {
      isLoading: true,
      articles: [],
      groups: [],
      searchQ: '',
      filterGroupId: null,
      busyId: null
    };
  },
  computed: {
    columns() {
      return [
        { key: 'title', label: this.$t('Title'), strong: true },
      ];
    },
    groupSelectOptions() {
      return this.groups.map(g => ({ label: g.name, value: g.id }));
    }
  },
  watch: {
    filterGroupId() {
      this.fetch();
    }
  },
  mounted() {
    this.fetchGroups();
    this.fetch();
  },
  methods: {
    tv(v) { return typeof v === 'string' ? v.trim() : v; },
    async fetchGroups() {
      try {
        const res = await axios.get('/knowledge-base/groups');
        this.groups = Array.isArray(res.data) ? res.data : (res.data.data || []);
      } catch (e) {
        this.groups = [];
      }
    },
    async fetch() {
      this.isLoading = true;
      try {
        const params = { per_page: 100 };
        if (this.searchQ) params.q = this.searchQ;
        if (this.filterGroupId) params.group_id = this.filterGroupId;
        const res = await axios.get('/knowledge-base/articles', { params });
        const data = res.data;
        this.articles = data.data || data;
        if (!Array.isArray(this.articles)) this.articles = [];
      } catch (e) {
        this.articles = [];
      } finally {
        this.isLoading = false;
      }
    },
    async destroy(a) {
      if (!confirm(this.$t('Confirm_Delete_This_Item'))) return;
      try {
        this.busyId = a.id;
        await axios.delete('/knowledge-base/articles/' + a.id);
        if (this.$root && this.$root.$bvToast) {
          this.$root.$bvToast.toast(this.$t('Deleted_successfully'), { variant: 'success', solid: true });
        }
        this.articles = this.articles.filter(x => x.id !== a.id);
      } catch (e) {
        if (this.$root && this.$root.$bvToast) {
          this.$root.$bvToast.toast(this.$t('Delete_failed'), { variant: 'danger', solid: true });
        }
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
.pxkb__strong { font-weight: 600; color: var(--pxn-text); }
.pxkb__row-meta { display: flex; align-items: center; gap: var(--pxn-space-2); flex-wrap: wrap; margin-top: 3px; }
.pxkb__row-group { font-size: var(--pxn-fs-xs, 0.8rem); color: var(--pxn-primary); background: var(--pxn-primary-soft, rgba(94,106,210,0.1)); padding: 2px 8px; border-radius: var(--pxn-radius-sm, 6px); }
.pxkb__rowbtns { display: flex; gap: var(--pxn-space-1); justify-content: flex-end; }
.pxkb__del ::v-deep .pxn-btn__icon { color: var(--pxn-danger); }
</style>
