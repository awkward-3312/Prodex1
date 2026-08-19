<template>
  <div class="main-content kb-article-page">
    <breadcumb
      :page="article.title || 'Manual PRODEX'"
      folder="Manual PRODEX"
    />

    <div v-if="isLoading" class="loading_page spinner spinner-primary mr-3"></div>

    <div v-else-if="article.id" class="kb-article-layout">
      <main class="kb-article-main">
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

            <div class="kb-header-actions">
              <b-button size="sm" variant="outline-primary" class="kb-copy-btn" @click="copyArticleLink">
                <lucide-icon name="link" /> {{ linkCopied ? 'Enlace copiado' : 'Copiar enlace' }}
              </b-button>
              <router-link :to="{ name: 'KnowledgeBaseList' }" class="btn btn-outline-secondary btn-sm kb-back-btn">
                <lucide-icon name="chevron-left" /> Volver al manual
              </router-link>
            </div>
          </div>

          <div v-if="toc.length" class="kb-mobile-toc">
            <button type="button" class="kb-mobile-toc-toggle" @click="mobileTocOpen = !mobileTocOpen">
              <span><lucide-icon name="list" /> En este artículo</span>
              <lucide-icon :name="mobileTocOpen ? 'chevron-up' : 'chevron-down'" />
            </button>
            <div v-show="mobileTocOpen" class="kb-mobile-toc-links">
              <button
                v-for="item in toc"
                :key="item.id"
                type="button"
                :class="['kb-toc-link', { 'is-child': item.level === 3 }]"
                @click="scrollToSection(item.id)"
              >
                {{ item.text }}
              </button>
            </div>
          </div>

          <div ref="articleContent" class="kb-article-content ql-editor" v-html="article.content"></div>

          <div class="kb-footer-note">
            <lucide-icon name="info" />
            <span>Este artículo forma parte de la documentación oficial de PRODEX.</span>
          </div>
        </b-card>
      </main>

      <aside v-if="toc.length" class="kb-toc-aside">
        <div class="kb-toc-card">
          <div class="kb-toc-title"><lucide-icon name="list" /> En este artículo</div>
          <button
            v-for="item in toc"
            :key="item.id"
            type="button"
            :class="['kb-toc-link', { 'is-child': item.level === 3 }]"
            @click="scrollToSection(item.id)"
          >
            {{ item.text }}
          </button>
        </div>
      </aside>
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
      article: {},
      toc: [],
      mobileTocOpen: false,
      linkCopied: false
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
      this.toc = [];
      try {
        const res = await axios.get('/prodex-manual/articles/' + this.id, {
          meta: { skipErrorRedirect: true }
        });
        this.article = res.data || {};
        this.$nextTick(this.buildTableOfContents);
      } catch (e) {
        this.article = {};
        if (this.$root && this.$root.$bvToast) {
          const status = e && e.response ? e.response.status : null;
          const message = status === 404
            ? 'Este manual no está disponible.'
            : 'No se pudo cargar el Manual PRODEX.';
          this.$root.$bvToast.toast(message, { variant: 'danger', solid: true });
        }
      } finally {
        this.isLoading = false;
      }
    },
    buildTableOfContents() {
      const root = this.$refs.articleContent;
      if (!root) return;

      const headings = Array.from(root.querySelectorAll('h2, h3'));
      const used = {};
      this.toc = headings.map((heading, index) => {
        const text = (heading.textContent || '').trim();
        let slug = text
          .toLowerCase()
          .normalize('NFD')
          .replace(/[\u0300-\u036f]/g, '')
          .replace(/[^a-z0-9]+/g, '-')
          .replace(/^-+|-+$/g, '') || 'seccion-' + (index + 1);

        if (used[slug]) {
          used[slug] += 1;
          slug += '-' + used[slug];
        } else {
          used[slug] = 1;
        }

        heading.id = slug;
        heading.classList.add('kb-anchor-heading');
        return {
          id: slug,
          text,
          level: Number(heading.tagName.substring(1))
        };
      });
    },
    scrollToSection(id) {
      const root = this.$refs.articleContent;
      const element = root ? root.querySelector('#' + id) : null;
      if (!element) return;
      element.scrollIntoView({ behavior: 'smooth', block: 'start' });
      this.mobileTocOpen = false;
      if (window.history && window.history.replaceState) {
        window.history.replaceState(null, '', window.location.pathname + window.location.search + '#' + id);
      }
    },
    async copyArticleLink() {
      const url = window.location.href.split('#')[0];
      try {
        if (navigator.clipboard && window.isSecureContext) {
          await navigator.clipboard.writeText(url);
        } else {
          const input = document.createElement('textarea');
          input.value = url;
          input.setAttribute('readonly', '');
          input.style.position = 'fixed';
          input.style.opacity = '0';
          document.body.appendChild(input);
          input.select();
          document.execCommand('copy');
          input.remove();
        }
        this.linkCopied = true;
        window.setTimeout(() => { this.linkCopied = false; }, 1600);
      } catch (e) {
        if (this.$root && this.$root.$bvToast) {
          this.$root.$bvToast.toast('No se pudo copiar el enlace.', { variant: 'warning', solid: true });
        }
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
.kb-article-layout {
  display: grid;
  grid-template-columns: minmax(0, 960px) 250px;
  gap: 1.25rem;
  align-items: start;
  max-width: 1240px;
}
.kb-article-main { min-width: 0; }
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
.kb-header-actions { display: flex; gap: 0.5rem; flex-wrap: wrap; }
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
.kb-back-btn, .kb-copy-btn { border-radius: 8px; }
.kb-article-content {
  min-height: 100px;
  line-height: 1.78;
  color: #4a5568;
  font-size: 1rem;
}
.kb-article-content >>> .kb-anchor-heading { scroll-margin-top: 90px; }
.kb-article-content >>> img { max-width: 100%; height: auto; border-radius: 10px; border: 1px solid #eef1f6; }
.kb-article-content >>> h1,
.kb-article-content >>> h2,
.kb-article-content >>> h3 { color: #2d3748; margin-top: 1.55em; margin-bottom: 0.6em; font-weight: 700; }
.kb-article-content >>> h2 { font-size: 1.35rem; padding-bottom: 0.4rem; border-bottom: 1px solid #eef1f6; }
.kb-article-content >>> h3 { font-size: 1.08rem; }
.kb-article-content >>> p { margin-bottom: 0.9em; }
.kb-article-content >>> ul,
.kb-article-content >>> ol { padding-left: 1.55em; margin-bottom: 0.9em; }
.kb-article-content >>> li { margin-bottom: 0.35rem; }
.kb-article-content >>> a { color: #667eea; }
.kb-article-content >>> blockquote {
  border-left: 4px solid #667eea;
  padding: 0.8rem 1rem;
  margin: 1rem 0;
  color: #556070;
  background: #f8f9ff;
  border-radius: 0 8px 8px 0;
}
.kb-article-content >>> .manual-intro {
  background: #f7f8ff;
  border: 1px solid #e3e6ff;
  border-radius: 12px;
  padding: 1rem 1.1rem;
  margin-bottom: 1.25rem;
  color: #394150;
}
.kb-article-content >>> .manual-step {
  position: relative;
  background: #fff;
  border: 1px solid #e8ebf1;
  border-radius: 12px;
  padding: 1rem 1rem 1rem 3.5rem;
  margin: 0.8rem 0;
  box-shadow: 0 2px 8px rgba(31, 41, 55, 0.04);
}
.kb-article-content >>> .manual-step-number {
  position: absolute;
  left: 1rem;
  top: 1rem;
  width: 1.75rem;
  height: 1.75rem;
  border-radius: 50%;
  display: inline-flex;
  align-items: center;
  justify-content: center;
  background: #667eea;
  color: #fff;
  font-size: 0.8rem;
  font-weight: 700;
}
.kb-article-content >>> .manual-step strong { color: #2d3748; }
.kb-article-content >>> .manual-note,
.kb-article-content >>> .manual-warning,
.kb-article-content >>> .manual-success {
  border-radius: 10px;
  padding: 0.9rem 1rem;
  margin: 1rem 0;
}
.kb-article-content >>> .manual-note { background: #eff6ff; border: 1px solid #bfdbfe; color: #1e3a5f; }
.kb-article-content >>> .manual-warning { background: #fff8e6; border: 1px solid #f6d78b; color: #654b16; }
.kb-article-content >>> .manual-success { background: #ecfdf3; border: 1px solid #bbf7d0; color: #24563a; }
.kb-article-content >>> .manual-checklist {
  list-style: none;
  padding-left: 0;
}
.kb-article-content >>> .manual-checklist li {
  position: relative;
  padding-left: 1.7rem;
  margin-bottom: 0.5rem;
}
.kb-article-content >>> .manual-checklist li:before {
  content: '✓';
  position: absolute;
  left: 0;
  color: #16a34a;
  font-weight: 700;
}
.kb-article-content >>> details.manual-details {
  border: 1px solid #e5e7eb;
  border-radius: 10px;
  margin: 0.8rem 0;
  background: #fff;
}
.kb-article-content >>> details.manual-details summary {
  cursor: pointer;
  padding: 0.9rem 1rem;
  font-weight: 600;
  color: #374151;
}
.kb-article-content >>> details.manual-details > *:not(summary) { margin-left: 1rem; margin-right: 1rem; }
.kb-article-content >>> table { width: 100%; border-collapse: collapse; margin: 1rem 0; font-size: 0.94rem; }
.kb-article-content >>> th,
.kb-article-content >>> td { border: 1px solid #e5e7eb; padding: 0.65rem 0.75rem; text-align: left; vertical-align: top; }
.kb-article-content >>> th { background: #f8fafc; color: #374151; }
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
.kb-toc-aside { position: sticky; top: 90px; }
.kb-toc-card {
  background: #fff;
  border: 1px solid #e7eaf0;
  border-radius: 12px;
  padding: 1rem;
  box-shadow: 0 2px 10px rgba(31, 41, 55, 0.04);
}
.kb-toc-title {
  display: flex;
  align-items: center;
  gap: 0.4rem;
  color: #2d3748;
  font-weight: 700;
  font-size: 0.92rem;
  margin-bottom: 0.65rem;
}
.kb-toc-link {
  width: 100%;
  display: block;
  border: 0;
  background: transparent;
  text-align: left;
  color: #5f6b7a;
  padding: 0.38rem 0.25rem;
  font-size: 0.84rem;
  line-height: 1.35;
  cursor: pointer;
}
.kb-toc-link:hover { color: #667eea; }
.kb-toc-link.is-child { padding-left: 1rem; font-size: 0.8rem; }
.kb-mobile-toc { display: none; border: 1px solid #e7eaf0; border-radius: 10px; margin-bottom: 1.25rem; overflow: hidden; }
.kb-mobile-toc-toggle {
  width: 100%;
  border: 0;
  background: #f8fafc;
  display: flex;
  align-items: center;
  justify-content: space-between;
  padding: 0.8rem 0.9rem;
  font-weight: 600;
  color: #374151;
}
.kb-mobile-toc-toggle span { display: flex; gap: 0.4rem; align-items: center; }
.kb-mobile-toc-links { padding: 0.55rem 0.75rem; background: #fff; }

@media (max-width: 1100px) {
  .kb-article-layout { grid-template-columns: minmax(0, 1fr); }
  .kb-toc-aside { display: none; }
  .kb-mobile-toc { display: block; }
}
@media (max-width: 576px) {
  .kb-article-title { font-size: 1.4rem; }
  .kb-header-actions { width: 100%; }
  .kb-header-actions .btn { flex: 1 1 auto; }
  .kb-article-content >>> .manual-step { padding-left: 3rem; }
}
</style>
