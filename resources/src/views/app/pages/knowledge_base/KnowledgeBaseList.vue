<template>
  <div class="main-content kb-page">
    <breadcumb page="Manual PRODEX" :folder="$t('App')" />

    <div class="kb-hero mb-4">
      <div class="kb-hero-inner">
        <div class="kb-hero-icon">
          <lucide-icon name="book-open" />
        </div>
        <h1 class="kb-hero-title">Manual PRODEX</h1>
        <p class="kb-hero-subtitle">Guías oficiales paso a paso para aprender a utilizar PRODEX y resolver las dudas más frecuentes.</p>

        <div class="kb-hero-search">
          <b-input-group>
            <b-input-group-prepend is-text>
              <lucide-icon name="search" />
            </b-input-group-prepend>
            <b-form-input
              v-model.trim="searchQ"
              placeholder="¿Qué necesitas hacer? Ej.: crear producto, cerrar caja, CAI..."
              @keyup.enter="search"
            />
            <b-input-group-append>
              <b-button variant="light" @click="search" :disabled="loading">Buscar</b-button>
            </b-input-group-append>
          </b-input-group>
        </div>
      </div>
    </div>

    <div v-if="isLoading" class="loading_page spinner spinner-primary mr-3"></div>

    <template v-else>
      <section v-if="categories.length" class="kb-category-section mb-4">
        <div class="kb-section-heading">
          <div>
            <h2>Explora por tema</h2>
            <p>Selecciona un área para ver únicamente sus guías.</p>
          </div>
          <button v-if="filterCategoryId" type="button" class="btn btn-sm btn-outline-secondary" @click="clearCategory">
            Ver todas
          </button>
        </div>

        <div class="kb-category-grid">
          <button
            v-for="category in categories"
            :key="category.id"
            type="button"
            :class="['kb-category-card', { active: Number(filterCategoryId) === Number(category.id) }]"
            @click="selectCategory(category.id)"
          >
            <span class="kb-category-icon"><lucide-icon name="folder-open" /></span>
            <span class="kb-category-copy">
              <strong>{{ category.name }}</strong>
              <small v-if="category.description">{{ category.description }}</small>
              <span class="kb-category-count">{{ category.published_articles_count || 0 }} {{ Number(category.published_articles_count) === 1 ? 'guía' : 'guías' }}</span>
            </span>
            <lucide-icon class="kb-category-arrow" name="chevron-right" />
          </button>
        </div>
      </section>

      <b-card class="kb-card shadow-sm">
        <div class="kb-toolbar">
          <div class="kb-toolbar-top">
            <div>
              <h2 class="kb-results-title">{{ resultsTitle }}</h2>
              <p class="text-muted small mb-0">{{ total }} {{ total === 1 ? 'manual encontrado' : 'manuales encontrados' }}</p>
            </div>

            <b-form-select
              v-model="filterCategoryId"
              :options="categoryOptions"
              value-field="id"
              text-field="name"
              class="kb-group-select"
            >
              <template #first>
                <b-form-select-option :value="null">Todas las categorías</b-form-select-option>
              </template>
            </b-form-select>
          </div>

          <div class="kb-quick-searches">
            <span>Temas frecuentes:</span>
            <button v-for="term in quickSearches" :key="term" type="button" @click="quickSearch(term)">{{ term }}</button>
          </div>
        </div>

        <div class="kb-articles-list" v-if="articles.length">
          <div
            v-for="article in articles"
            :key="article.id"
            class="kb-article-item"
          >
            <div class="kb-article-item-icon"><lucide-icon name="file-text" /></div>
            <div class="kb-article-item-body">
              <router-link
                :to="{ name: 'KnowledgeBaseArticleView', params: { id: article.id } }"
                class="kb-article-title"
              >
                {{ article.title }}
              </router-link>
              <div class="kb-article-meta">
                <span class="kb-article-group">{{ article.category ? article.category.name : 'General' }}</span>
                <span v-if="article.updated_at" class="text-muted small">
                  Actualizado {{ formatDate(article.updated_at) }}
                </span>
              </div>
            </div>

            <router-link
              :to="{ name: 'KnowledgeBaseArticleView', params: { id: article.id } }"
              class="btn btn-sm btn-outline-primary kb-open-btn"
              title="Abrir manual"
            >
              Abrir <lucide-icon name="chevron-right" />
            </router-link>
          </div>
        </div>

        <div v-else-if="!loading" class="kb-empty">
          <div class="kb-empty-icon"><lucide-icon name="search-x" /></div>
          <p class="kb-empty-title">No encontramos una guía con esos filtros</p>
          <p class="kb-empty-text text-muted">Prueba con otra palabra o vuelve a ver todas las categorías.</p>
          <b-button variant="outline-primary" @click="resetFilters">Limpiar búsqueda</b-button>
        </div>

        <div v-if="totalPages > 1" class="kb-pagination">
          <span class="text-muted small">Página {{ currentPage }} de {{ totalPages }}</span>
          <div>
            <b-button size="sm" variant="outline-secondary" :disabled="currentPage <= 1" @click="goPage(currentPage - 1)">
              Anterior
            </b-button>
            <b-button size="sm" variant="outline-secondary" class="ml-2" :disabled="currentPage >= totalPages" @click="goPage(currentPage + 1)">
              Siguiente
            </b-button>
          </div>
        </div>
      </b-card>
    </template>
  </div>
</template>

<script>
export default {
  name: 'KnowledgeBaseList',
  metaInfo: { title: 'Manual PRODEX' },
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
    categoryOptions() {
      return this.categories;
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

<style scoped>
.kb-page { padding-bottom: 2rem; }
.kb-hero {
  background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
  border-radius: 14px;
  padding: 2rem 1.75rem;
  color: #fff;
}
.kb-hero-inner { max-width: 780px; }
.kb-hero-icon {
  width: 56px;
  height: 56px;
  border-radius: 12px;
  background: rgba(255,255,255,0.2);
  display: flex;
  align-items: center;
  justify-content: center;
  margin-bottom: 1rem;
}
.kb-hero-title { font-size: 1.65rem; font-weight: 700; margin: 0 0 0.25rem 0; }
.kb-hero-subtitle { margin: 0; opacity: 0.94; font-size: 0.98rem; }
.kb-hero-search { margin-top: 1.25rem; max-width: 680px; }
.kb-hero-search .input-group { background: #fff; border-radius: 10px; overflow: hidden; }
.kb-hero-search .form-control, .kb-hero-search .input-group-text { border: 0; background: #fff; }
.kb-hero-search .btn { border-radius: 0; font-weight: 600; color: #5b5fc7; }
.kb-section-heading { display: flex; align-items: flex-end; justify-content: space-between; gap: 1rem; margin-bottom: 0.9rem; }
.kb-section-heading h2 { font-size: 1.15rem; margin: 0 0 0.2rem; color: #2d3748; font-weight: 700; }
.kb-section-heading p { margin: 0; color: #718096; font-size: 0.9rem; }
.kb-category-grid { display: grid; grid-template-columns: repeat(2, minmax(0, 1fr)); gap: 0.75rem; }
.kb-category-card {
  appearance: none;
  width: 100%;
  border: 1px solid #e6e9ef;
  background: #fff;
  border-radius: 12px;
  padding: 0.95rem 1rem;
  display: flex;
  align-items: center;
  gap: 0.85rem;
  text-align: left;
  cursor: pointer;
  transition: border-color .2s, box-shadow .2s, transform .2s;
}
.kb-category-card:hover { border-color: #aeb8f4; box-shadow: 0 4px 14px rgba(45,55,72,.06); transform: translateY(-1px); }
.kb-category-card.active { border-color: #667eea; background: #f8f8ff; box-shadow: 0 0 0 2px rgba(102,126,234,.08); }
.kb-category-icon { width: 42px; height: 42px; flex: 0 0 42px; border-radius: 10px; display: flex; align-items: center; justify-content: center; background: #eef0ff; color: #667eea; }
.kb-category-copy { display: flex; flex-direction: column; min-width: 0; flex: 1; }
.kb-category-copy strong { color: #303746; font-size: 0.94rem; margin-bottom: 0.15rem; }
.kb-category-copy small { color: #7a8492; font-size: 0.78rem; line-height: 1.35; }
.kb-category-count { color: #667eea; font-size: 0.74rem; font-weight: 600; margin-top: 0.3rem; }
.kb-category-arrow { color: #a0a8b3; flex: 0 0 auto; }
.kb-card { border-radius: 12px; border: none; }
.kb-toolbar { margin-bottom: 1.25rem; }
.kb-toolbar-top { display: flex; align-items: center; justify-content: space-between; gap: 1rem; margin-bottom: 0.85rem; }
.kb-results-title { margin: 0 0 0.15rem; color: #2d3748; font-size: 1.1rem; font-weight: 700; }
.kb-group-select { max-width: 270px; border-radius: 8px; }
.kb-quick-searches { display: flex; align-items: center; flex-wrap: wrap; gap: 0.4rem; }
.kb-quick-searches > span { color: #718096; font-size: 0.8rem; margin-right: 0.15rem; }
.kb-quick-searches button { border: 1px solid #e1e5ec; background: #f8fafc; color: #596579; border-radius: 999px; padding: 0.27rem 0.58rem; font-size: 0.77rem; cursor: pointer; }
.kb-quick-searches button:hover { border-color: #aeb8f4; color: #5b5fc7; background: #f5f5ff; }
.kb-articles-list { border-top: 1px solid #eee; }
.kb-article-item { display: flex; align-items: center; justify-content: space-between; padding: 1rem 0; border-bottom: 1px solid #f0f0f0; gap: 0.9rem; }
.kb-article-item:last-child { border-bottom: none; }
.kb-article-item-icon { width: 38px; height: 38px; flex: 0 0 38px; border-radius: 9px; display: flex; align-items: center; justify-content: center; background: #f4f5ff; color: #667eea; }
.kb-article-item-body { flex: 1; min-width: 0; }
.kb-article-title { font-weight: 600; color: #333; display: block; margin-bottom: 0.35rem; text-decoration: none; transition: color 0.2s; }
.kb-article-title:hover { color: #667eea; }
.kb-article-meta { display: flex; align-items: center; gap: 0.65rem; flex-wrap: wrap; }
.kb-article-group { font-size: 0.8rem; color: #667eea; background: rgba(102,126,234,0.1); padding: 0.2rem 0.5rem; border-radius: 6px; }
.kb-open-btn { display: inline-flex; align-items: center; gap: 0.15rem; border-radius: 8px; }
.kb-empty { text-align: center; padding: 3rem 1.5rem; }
.kb-empty-icon { font-size: 3rem; color: #dee2e6; margin-bottom: 1rem; }
.kb-empty-title { font-weight: 600; margin-bottom: 0.25rem; }
.kb-empty-text { font-size: 0.9rem; margin-bottom: 1rem; }
.kb-pagination { display: flex; justify-content: space-between; align-items: center; margin-top: 1.5rem; padding-top: 1rem; border-top: 1px solid #eee; }
@media (max-width: 768px) {
  .kb-category-grid { grid-template-columns: 1fr; }
  .kb-toolbar-top { align-items: stretch; flex-direction: column; }
  .kb-group-select { max-width: none; }
  .kb-open-btn { padding-left: 0.55rem; padding-right: 0.55rem; }
}
@media (max-width: 576px) {
  .kb-hero { padding: 1.5rem 1.1rem; }
  .kb-article-item-icon { display: none; }
  .kb-open-btn { font-size: 0; }
  .kb-open-btn svg { font-size: initial; }
}
</style>
