<template>
  <div class="main-content kb-article-page">
    <breadcumb
      :page="article.title || 'Manual PRODEX'"
      folder="Manual PRODEX"
    />

    <div v-if="isLoading" class="loading_page spinner spinner-primary mr-3"></div>

    <div v-else-if="article.id" class="kb-article-wrap">
      <b-card class="kb-article-card shadow-sm">
        <div class="kb-article-header">
          <div class="kb-article-header-content">
            <div class="kb-manual-label">
              <lucide-icon name="book-open" /> Manual PRODEX
            </div>
            <h1 class="kb-article-title">{{ article.title }}</h1>
            <div class="kb-article-meta">
              <span v-if="article.category" class="kb-badge-group">{{ article.category.name }}</span>
              <span v-if="article.updated_at" class="text-muted small">
                Actualizado {{ formatDate(article.updated_at) }}
              </span>
            </div>
          </div>

          <router-link :to="{ name: 'KnowledgeBaseList' }" class="btn btn-outline-secondary btn-sm kb-back-btn">
            <lucide-icon name="chevron-left" /> Volver al manual
          </router-link>
        </div>

        <div class="kb-article-content ql-editor" v-html="article.content"></div>

        <div class="kb-footer-note">
          <lucide-icon name="info" />
          <span>Este artículo forma parte de la documentación oficial de PRODEX.</span>
        </div>
      </b-card>
    </div>

    <b-card v-else class="kb-article-card shadow-sm text-center py-5">
      <lucide-icon name="file-question" class="mb-3" />
      <h4>Manual no disponible</h4>
      <p class="text-muted">El artículo no existe o ya no está publicado.</p>
      <router-link :to="{ name: 'KnowledgeBaseList' }" class="btn btn-primary">
        Volver al Manual PRODEX
      </router-link>
    </b-card>
  </div>
</template>

<script>
export default {
  name: 'KnowledgeBaseArticleView',
  metaInfo() {
    return { title: this.article.title ? this.article.title + ' - Manual PRODEX' : 'Manual PRODEX' };
  },
  props: {
    id: { type: [String, Number], required: true }
  },
  data() {
    return {
      isLoading: true,
      article: {}
    };
  },
  mounted() {
    this.fetchArticle();
  },
  watch: {
    id() {
      this.fetchArticle();
    }
  },
  methods: {
    async fetchArticle() {
      this.isLoading = true;
      try {
        const res = await axios.get('/prodex-manual/articles/' + this.id);
        this.article = res.data || {};
      } catch (e) {
        this.article = {};
        if (this.$root && this.$root.$bvToast) {
          const message = e.response && e.response.status === 404
            ? 'Este manual no está disponible.'
            : 'No se pudo cargar el Manual PRODEX.';
          this.$root.$bvToast.toast(message, { variant: 'danger', solid: true });
        }
      } finally {
        this.isLoading = false;
      }
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
.kb-article-page { padding-bottom: 2rem; }
.kb-article-wrap { max-width: 960px; }
.kb-article-card { border-radius: 12px; border: none; }
.kb-article-header {
  display: flex;
  justify-content: space-between;
  align-items: flex-start;
  flex-wrap: wrap;
  gap: 1rem;
  margin-bottom: 1.5rem;
  padding-bottom: 1.25rem;
  border-bottom: 1px solid #eee;
}
.kb-article-header-content { flex: 1; min-width: 0; }
.kb-manual-label {
  display: inline-flex;
  align-items: center;
  gap: 0.35rem;
  color: #667eea;
  font-size: 0.82rem;
  font-weight: 600;
  margin-bottom: 0.6rem;
  text-transform: uppercase;
  letter-spacing: 0.04em;
}
.kb-article-title {
  font-size: 1.7rem;
  font-weight: 700;
  color: #2d3748;
  margin: 0 0 0.6rem 0;
  line-height: 1.3;
}
.kb-article-meta { display: flex; align-items: center; gap: 0.75rem; flex-wrap: wrap; }
.kb-badge-group {
  font-size: 0.8rem;
  color: #667eea;
  background: rgba(102, 126, 234, 0.12);
  padding: 0.35rem 0.65rem;
  border-radius: 8px;
  font-weight: 500;
}
.kb-back-btn { border-radius: 8px; }
.kb-article-content {
  min-height: 100px;
  line-height: 1.78;
  color: #4a5568;
  font-size: 1rem;
}
.kb-article-content >>> img { max-width: 100%; height: auto; border-radius: 8px; }
.kb-article-content >>> h1,
.kb-article-content >>> h2,
.kb-article-content >>> h3 { color: #2d3748; margin-top: 1.35em; margin-bottom: 0.55em; }
.kb-article-content >>> p { margin-bottom: 0.85em; }
.kb-article-content >>> ul,
.kb-article-content >>> ol { padding-left: 1.5em; margin-bottom: 0.85em; }
.kb-article-content >>> a { color: #667eea; }
.kb-article-content >>> blockquote {
  border-left: 4px solid #667eea;
  padding-left: 1rem;
  margin: 1rem 0;
  color: #718096;
}
.kb-footer-note {
  margin-top: 2rem;
  padding-top: 1rem;
  border-top: 1px solid #eef1f6;
  display: flex;
  align-items: center;
  gap: 0.5rem;
  color: #718096;
  font-size: 0.88rem;
}
</style>
