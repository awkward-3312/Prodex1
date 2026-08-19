<template>
  <div class="main-content kb-page">
    <breadcumb page="Manual PRODEX" :folder="$t('App')" />

    <div class="kb-hero mb-4">
      <div class="kb-hero-inner">
        <div class="kb-hero-icon">
          <lucide-icon name="book-open" />
        </div>
        <h1 class="kb-hero-title">Manual PRODEX</h1>
        <p class="kb-hero-subtitle">Encuentra guías oficiales para aprender a utilizar las funciones de PRODEX.</p>
      </div>
    </div>

    <div v-if="isLoading" class="loading_page spinner spinner-primary mr-3"></div>

    <b-card v-else class="kb-card shadow-sm">
      <div class="kb-toolbar">
        <div class="kb-search-row">
          <b-input-group class="kb-search-input">
            <b-input-group-prepend is-text>
              <lucide-icon name="search" />
            </b-input-group-prepend>
            <b-form-input
              v-model.trim="searchQ"
              placeholder="Buscar por título o contenido..."
              @keyup.enter="search"
            />
          </b-input-group>

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

          <b-button variant="primary" class="kb-search-btn" @click="search" :disabled="loading">
            <lucide-icon name="search" /> Buscar
          </b-button>
        </div>

        <p class="text-muted small mb-0">
          Los manuales publicados aquí son documentación oficial administrada por PRODEX.
        </p>
      </div>

      <div class="kb-articles-list" v-if="articles.length">
        <div
          v-for="article in articles"
          :key="article.id"
          class="kb-article-item"
        >
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
            class="btn btn-sm btn-outline-primary"
            title="Abrir manual"
          >
            <lucide-icon name="chevron-right" />
          </router-link>
        </div>
      </div>

      <div v-else-if="!loading" class="kb-empty">
        <div class="kb-empty-icon"><lucide-icon name="book-open" /></div>
        <p class="kb-empty-title">No encontramos manuales</p>
        <p class="kb-empty-text text-muted">
          Prueba con otra búsqueda o categoría. Si todavía no hay artículos publicados, aparecerán aquí cuando PRODEX los publique.
        </p>
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
      total: 0
    };
  },
  computed: {
    categoryOptions() {
      return this.categories;
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
        const res = await axios.get('/prodex-manual/categories');
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

        const res = await axios.get('/prodex-manual/articles', { params });
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
  border-radius: 12px;
  padding: 1.75rem 1.5rem;
  color: #fff;
}
.kb-hero-inner { max-width: 720px; }
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
.kb-hero-title { font-size: 1.5rem; font-weight: 700; margin: 0 0 0.25rem 0; }
.kb-hero-subtitle { margin: 0; opacity: 0.92; font-size: 0.95rem; }
.kb-card { border-radius: 12px; border: none; }
.kb-toolbar { margin-bottom: 1.5rem; }
.kb-search-row {
  display: flex;
  flex-wrap: wrap;
  align-items: center;
  gap: 0.75rem;
  margin-bottom: 0.75rem;
}
.kb-search-input { max-width: 420px; flex: 1 1 240px; }
.kb-search-input .input-group-text { border-radius: 8px 0 0 8px; background: #f8f9fa; }
.kb-group-select { max-width: 260px; border-radius: 8px; }
.kb-search-btn { border-radius: 8px; }
.kb-articles-list { border-top: 1px solid #eee; }
.kb-article-item {
  display: flex;
  align-items: center;
  justify-content: space-between;
  padding: 1rem 0;
  border-bottom: 1px solid #f0f0f0;
  gap: 1rem;
}
.kb-article-item:last-child { border-bottom: none; }
.kb-article-item-body { flex: 1; min-width: 0; }
.kb-article-title {
  font-weight: 600;
  color: #333;
  display: block;
  margin-bottom: 0.35rem;
  text-decoration: none;
  transition: color 0.2s;
}
.kb-article-title:hover { color: #667eea; }
.kb-article-meta { display: flex; align-items: center; gap: 0.65rem; flex-wrap: wrap; }
.kb-article-group {
  font-size: 0.8rem;
  color: #667eea;
  background: rgba(102, 126, 234, 0.1);
  padding: 0.2rem 0.5rem;
  border-radius: 6px;
}
.kb-empty { text-align: center; padding: 3rem 1.5rem; }
.kb-empty-icon { font-size: 3rem; color: #dee2e6; margin-bottom: 1rem; }
.kb-empty-title { font-weight: 600; margin-bottom: 0.25rem; }
.kb-empty-text { font-size: 0.9rem; margin-bottom: 0; }
.kb-pagination {
  display: flex;
  justify-content: space-between;
  align-items: center;
  margin-top: 1.5rem;
  padding-top: 1rem;
  border-top: 1px solid #eee;
}
</style>
