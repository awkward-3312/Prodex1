<template>
  <div class="px-next pxkb">
    <px-page-header
      title="Manual PRODEX"
      subtitle="Guías oficiales paso a paso para aprender a utilizar PRODEX y resolver las dudas más frecuentes."
      :breadcrumbs="[{ label: $t('App') || 'Inicio' }, { label: 'Manual PRODEX' }]"
    />

    <section class="pxkb__hero">
      <div class="pxkb__hero-icon"><lucide-icon name="book-open" /></div>
      <div class="pxkb__hero-body">
        <h2 class="pxkb__hero-title">¿Qué necesitas hacer?</h2>
        <p class="pxkb__hero-sub">Busca por tarea — crear producto, cerrar caja, configurar el CAI…</p>
        <div class="pxkb__hero-search">
          <px-input
            :value="searchQ"
            icon-lead="search"
            placeholder="Ej.: crear producto, cerrar caja, CAI..."
            @input="v => searchQ = tv(v)"
            @keyup.native.enter="search"
          />
          <px-button variant="primary" :loading="loading" :disabled="loading" @click="search">Buscar</px-button>
        </div>
      </div>
    </section>

    <div v-if="isLoading" class="pxkb__pad">
      <px-skeleton variant="lines" :rows="6" />
    </div>

    <template v-else>
      <section v-if="categories.length" class="pxkb__section">
        <div class="pxkb__section-head">
          <div>
            <h3 class="pxkb__section-title">Explora por tema</h3>
            <p class="pxkb__section-sub">Selecciona un área para ver únicamente sus guías.</p>
          </div>
          <px-button v-if="filterCategoryId" variant="ghost" size="sm" @click="clearCategory">Ver todas</px-button>
        </div>

        <div class="pxkb__cat-grid">
          <button
            v-for="category in categories"
            :key="category.id"
            type="button"
            :class="['pxkb__cat', { 'is-active': Number(filterCategoryId) === Number(category.id) }]"
            @click="selectCategory(category.id)"
          >
            <span class="pxkb__cat-icon"><lucide-icon name="folder-open" /></span>
            <span class="pxkb__cat-copy">
              <strong>{{ category.name }}</strong>
              <small v-if="category.description">{{ category.description }}</small>
              <span class="pxkb__cat-count">{{ category.published_articles_count || 0 }} {{ Number(category.published_articles_count) === 1 ? 'guía' : 'guías' }}</span>
            </span>
            <lucide-icon class="pxkb__cat-arrow" name="chevron-right" />
          </button>
        </div>
      </section>

      <px-card flush class="pxkb__results">
        <div class="pxkb__toolbar">
          <div class="pxkb__toolbar-top">
            <div>
              <h3 class="pxkb__results-title">{{ resultsTitle }}</h3>
              <p class="pxkb__muted">{{ total }} {{ total === 1 ? 'manual encontrado' : 'manuales encontrados' }}</p>
            </div>
            <vs-px
              class="pxkb__cat-select"
              :options="categorySelectOptions"
              :reduce="o => o.value"
              :value="filterCategoryId"
              placeholder="Todas las categorías"
              @input="v => filterCategoryId = v || null"
            />
          </div>

          <div class="pxkb__quick">
            <span>Temas frecuentes:</span>
            <button v-for="term in quickSearches" :key="term" type="button" @click="quickSearch(term)">{{ term }}</button>
          </div>
        </div>

        <div v-if="articles.length" class="pxkb__list">
          <div v-for="article in articles" :key="article.id" class="pxkb__row">
            <div class="pxkb__row-icon"><lucide-icon name="file-text" /></div>
            <div class="pxkb__row-body">
              <router-link
                :to="{ name: 'KnowledgeBaseArticleView', params: { id: article.id } }"
                class="pxkb__row-title"
              >{{ article.title }}</router-link>
              <div class="pxkb__row-meta">
                <span class="pxkb__row-group">{{ article.category ? article.category.name : 'General' }}</span>
                <span v-if="article.updated_at" class="pxkb__muted">Actualizado {{ formatDate(article.updated_at) }}</span>
              </div>
            </div>
            <px-button
              variant="secondary"
              size="sm"
              trailing-icon="chevron-right"
              @click="$router.push({ name: 'KnowledgeBaseArticleView', params: { id: article.id } })"
            >Abrir</px-button>
          </div>
        </div>

        <px-empty-state
          v-else-if="!loading"
          icon="search-x"
          title="No encontramos una guía con esos filtros"
          description="Prueba con otra palabra o vuelve a ver todas las categorías."
        >
          <px-button variant="secondary" @click="resetFilters">Limpiar búsqueda</px-button>
        </px-empty-state>

        <div v-if="totalPages > 1" class="pxkb__pager">
          <span class="pxkb__muted">Página {{ currentPage }} de {{ totalPages }}</span>
          <div class="pxkb__pager-btns">
            <px-button size="sm" variant="ghost" icon="chevron-left" :disabled="currentPage <= 1" @click="goPage(currentPage - 1)">Anterior</px-button>
            <px-button size="sm" variant="ghost" trailing-icon="chevron-right" :disabled="currentPage >= totalPages" @click="goPage(currentPage + 1)">Siguiente</px-button>
          </div>
        </div>
      </px-card>
    </template>
  </div>
</template>

<script>
import PxPageHeader from "@/components/px-next/PxPageHeader.vue";
import PxCard from "@/components/px-next/PxCard.vue";
import PxButton from "@/components/px-next/PxButton.vue";
import PxInput from "@/components/px-next/PxInput.vue";
import PxEmptyState from "@/components/px-next/PxEmptyState.vue";
import VsPx from "@/views/app/products/next/edit/VsPx.vue";

export default {
  name: 'KnowledgeBaseList',
  metaInfo: { title: 'Manual PRODEX' },
  components: { PxPageHeader, PxCard, PxButton, PxInput, PxEmptyState, VsPx },
  data() {
    return {
      isLoading: true,
      loading: false,
      articles: [],
      categories: [],
      searchQ: '',
      filterCategoryId: null,
      currentPage: 1,
      perPage: 15,
      total: 0,
      quickSearches: ['Primeros pasos', 'Crear producto', 'POS', 'Cerrar caja', 'CAI', 'Compra', 'Usuarios']
    };
  },
  computed: {
    categorySelectOptions() {
      return this.categories.map(c => ({ label: c.name, value: c.id }));
    },
    selectedCategory() {
      if (!this.filterCategoryId) return null;
      return this.categories.find(category => Number(category.id) === Number(this.filterCategoryId)) || null;
    },
    resultsTitle() {
      if (this.searchQ) return 'Resultados para “' + this.searchQ + '”';
      if (this.selectedCategory) return this.selectedCategory.name;
      return 'Todas las guías';
    },
    totalPages() {
      return Math.max(1, Math.ceil(this.total / this.perPage));
    }
  },
  watch: {
    filterCategoryId() {
      this.currentPage = 1;
      this.fetchArticles();
    }
  },
  mounted() {
    this.fetchCategories();
    this.fetchArticles();
  },
  methods: {
    tv(v) { return typeof v === 'string' ? v.trim() : v; },
    async fetchCategories() {
      try {
        const res = await axios.get('/prodex-manual/categories', {
          meta: { skipErrorRedirect: true }
        });
        this.categories = Array.isArray(res.data) ? res.data : [];
      } catch (e) {
        this.categories = [];
      }
    },
    async fetchArticles() {
      this.loading = true;
      if (this.articles.length === 0) this.isLoading = true;
      try {
        const params = { per_page: this.perPage, page: this.currentPage };
        if (this.searchQ) params.q = this.searchQ;
        if (this.filterCategoryId) params.category_id = this.filterCategoryId;

        const res = await axios.get('/prodex-manual/articles', {
          params,
          meta: { skipErrorRedirect: true }
        });
        const data = res.data || {};
        this.articles = data.data || [];
        this.total = data.total || 0;
      } catch (e) {
        this.articles = [];
        this.total = 0;
        if (this.$root && this.$root.$bvToast) {
          this.$root.$bvToast.toast('No se pudo cargar el Manual PRODEX.', { variant: 'danger', solid: true });
        }
      } finally {
        this.loading = false;
        this.isLoading = false;
      }
    },
    search() {
      this.currentPage = 1;
      this.fetchArticles();
    },
    quickSearch(term) {
      this.searchQ = term;
      this.filterCategoryId = null;
      this.currentPage = 1;
      this.fetchArticles();
    },
    selectCategory(id) {
      if (Number(this.filterCategoryId) === Number(id)) {
        this.filterCategoryId = null;
      } else {
        this.searchQ = '';
        this.filterCategoryId = id;
      }
    },
    clearCategory() {
      this.filterCategoryId = null;
    },
    resetFilters() {
      this.searchQ = '';
      this.filterCategoryId = null;
      this.currentPage = 1;
      this.fetchArticles();
    },
    goPage(page) {
      this.currentPage = page;
      this.fetchArticles();
    },
    formatDate(value) {
      if (!value) return '';
      try {
        return new Intl.DateTimeFormat('es-HN', {
          day: '2-digit',
          month: '2-digit',
          year: 'numeric'
        }).format(new Date(value));
      } catch (e) {
        return '';
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
.pxkb__muted { color: var(--pxn-text-muted); font-size: var(--pxn-fs-sm); margin: 0; }

.pxkb__hero {
  display: flex; gap: var(--pxn-space-4); align-items: flex-start;
  background: var(--pxn-surface);
  border: 1px solid var(--pxn-border);
  border-radius: var(--pxn-radius-lg, 12px);
  padding: var(--pxn-space-6);
  margin-top: var(--pxn-space-5);
}
.pxkb__hero-icon {
  flex: 0 0 auto; width: 44px; height: 44px; border-radius: var(--pxn-radius-md, 10px);
  display: flex; align-items: center; justify-content: center;
  background: var(--pxn-primary-soft, rgba(94,106,210,0.12)); color: var(--pxn-primary);
}
.pxkb__hero-body { flex: 1; min-width: 0; }
.pxkb__hero-title { margin: 0 0 var(--pxn-space-1); font-size: var(--pxn-fs-lg, 1.05rem); font-weight: 600; color: var(--pxn-text); }
.pxkb__hero-sub { margin: 0 0 var(--pxn-space-4); color: var(--pxn-text-muted); font-size: var(--pxn-fs-sm); }
.pxkb__hero-search { display: flex; gap: var(--pxn-space-2); align-items: center; max-width: 640px; }
.pxkb__hero-search > *:first-child { flex: 1; }
@media (max-width: 560px) { .pxkb__hero { flex-direction: column; } .pxkb__hero-search { flex-direction: column; align-items: stretch; } }

.pxkb__section { margin-top: var(--pxn-space-6); }
.pxkb__section-head { display: flex; align-items: flex-end; justify-content: space-between; gap: var(--pxn-space-4); margin-bottom: var(--pxn-space-3); }
.pxkb__section-title { margin: 0 0 var(--pxn-space-1); font-size: var(--pxn-fs-md, 0.98rem); font-weight: 600; color: var(--pxn-text); }
.pxkb__section-sub { margin: 0; color: var(--pxn-text-muted); font-size: var(--pxn-fs-sm); }

.pxkb__cat-grid { display: grid; grid-template-columns: repeat(2, minmax(0, 1fr)); gap: var(--pxn-space-3); }
@media (max-width: 768px) { .pxkb__cat-grid { grid-template-columns: 1fr; } }
.pxkb__cat {
  appearance: none; width: 100%; text-align: left; cursor: pointer;
  border: 1px solid var(--pxn-border); background: var(--pxn-surface);
  border-radius: var(--pxn-radius-md, 12px);
  padding: var(--pxn-space-4);
  display: flex; align-items: center; gap: var(--pxn-space-3);
  transition: border-color .12s ease, background .12s ease;
}
.pxkb__cat:hover { border-color: var(--pxn-border-strong, var(--pxn-primary)); }
.pxkb__cat.is-active { border-color: var(--pxn-primary); background: var(--pxn-primary-soft, rgba(94,106,210,0.06)); }
.pxkb__cat-icon {
  flex: 0 0 auto; width: 40px; height: 40px; border-radius: var(--pxn-radius-md, 10px);
  display: flex; align-items: center; justify-content: center;
  background: var(--pxn-primary-soft, rgba(94,106,210,0.12)); color: var(--pxn-primary);
}
.pxkb__cat-copy { display: flex; flex-direction: column; min-width: 0; flex: 1; }
.pxkb__cat-copy strong { color: var(--pxn-text); font-size: var(--pxn-fs-sm); margin-bottom: 2px; }
.pxkb__cat-copy small { color: var(--pxn-text-muted); font-size: var(--pxn-fs-xs, 0.78rem); line-height: 1.35; }
.pxkb__cat-count { color: var(--pxn-primary); font-size: var(--pxn-fs-xs, 0.74rem); font-weight: 600; margin-top: var(--pxn-space-1); }
.pxkb__cat-arrow { color: var(--pxn-text-muted); flex: 0 0 auto; }

.pxkb__results { margin-top: var(--pxn-space-6); }
.pxkb__toolbar { padding: var(--pxn-space-5) var(--pxn-space-5) var(--pxn-space-3); }
.pxkb__toolbar-top { display: flex; align-items: center; justify-content: space-between; gap: var(--pxn-space-4); margin-bottom: var(--pxn-space-3); }
.pxkb__toolbar-top > div:first-child { flex: 1 1 auto; min-width: 0; }
.pxkb__results-title { margin: 0 0 2px; font-size: var(--pxn-fs-md, 1rem); font-weight: 600; color: var(--pxn-text); }
.pxkb__cat-select { flex: 0 0 auto; width: 280px; }
@media (max-width: 640px) { .pxkb__toolbar-top { flex-direction: column; align-items: stretch; } .pxkb__cat-select { width: 100%; } }
.pxkb__quick { display: flex; align-items: center; flex-wrap: wrap; gap: var(--pxn-space-2); }
.pxkb__quick > span { color: var(--pxn-text-muted); font-size: var(--pxn-fs-xs, 0.8rem); }
.pxkb__quick button {
  border: 1px solid var(--pxn-border); background: var(--pxn-surface-2, var(--pxn-surface)); color: var(--pxn-text-muted);
  border-radius: 9999px; padding: 3px 10px; font-size: var(--pxn-fs-xs, 0.77rem); cursor: pointer;
  transition: color .12s ease, border-color .12s ease;
}
.pxkb__quick button:hover { border-color: var(--pxn-primary); color: var(--pxn-primary); }

.pxkb__list { border-top: 1px solid var(--pxn-border); }
.pxkb__row { display: flex; align-items: center; gap: var(--pxn-space-3); padding: var(--pxn-space-4) var(--pxn-space-5); border-bottom: 1px solid var(--pxn-border); }
.pxkb__row:last-child { border-bottom: none; }
.pxkb__row-icon {
  flex: 0 0 auto; width: 36px; height: 36px; border-radius: var(--pxn-radius-md, 9px);
  display: flex; align-items: center; justify-content: center;
  background: var(--pxn-primary-soft, rgba(94,106,210,0.1)); color: var(--pxn-primary);
}
.pxkb__row-body { flex: 1; min-width: 0; }
.pxkb__row-title { font-weight: 600; color: var(--pxn-text); display: block; margin-bottom: 4px; text-decoration: none; }
.pxkb__row-title:hover { color: var(--pxn-primary); }
.pxkb__row-meta { display: flex; align-items: center; gap: var(--pxn-space-3); flex-wrap: wrap; }
.pxkb__row-group { font-size: var(--pxn-fs-xs, 0.8rem); color: var(--pxn-primary); background: var(--pxn-primary-soft, rgba(94,106,210,0.1)); padding: 2px 8px; border-radius: var(--pxn-radius-sm, 6px); }
@media (max-width: 560px) { .pxkb__row-icon { display: none; } }

.pxkb__pager { display: flex; justify-content: space-between; align-items: center; padding: var(--pxn-space-4) var(--pxn-space-5); border-top: 1px solid var(--pxn-border); }
.pxkb__pager-btns { display: flex; gap: var(--pxn-space-2); }
</style>
