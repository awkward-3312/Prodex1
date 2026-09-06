<template>
  <div class="px-next pxkb pxkb--article">
    <px-page-header
      :title="article.title || 'Manual PRODEX'"
      :breadcrumbs="[
        { label: 'Manual PRODEX', href: '#/app/knowledge-base/list' },
        { label: article.title || 'Artículo' }
      ]"
    >
      <template #actions v-if="article.id">
        <px-button variant="secondary" size="sm" icon="link" @click="copyArticleLink">
          {{ linkCopied ? 'Enlace copiado' : 'Copiar enlace' }}
        </px-button>
        <px-button variant="ghost" size="sm" icon="chevron-left" @click="$router.push({ name: 'KnowledgeBaseList' })">
          Volver al manual
        </px-button>
      </template>
    </px-page-header>

    <div v-if="isLoading" class="pxkb__pad">
      <px-skeleton variant="lines" :rows="10" />
    </div>

    <div v-else-if="article.id" class="pxkb__layout">
      <main class="pxkb__main">
        <px-card class="pxkb__article">
          <div class="pxkb__article-head">
            <div class="pxkb__label"><lucide-icon name="book-open" /> Manual PRODEX</div>
            <h1 class="pxkb__article-title">{{ article.title }}</h1>
            <div class="pxkb__article-meta">
              <px-badge v-if="article.category" tone="info">{{ article.category.name }}</px-badge>
              <span v-if="article.updated_at" class="pxkb__muted">Actualizado {{ formatDate(article.updated_at) }}</span>
            </div>
          </div>

          <div v-if="toc.length" class="pxkb__mtoc">
            <button type="button" class="pxkb__mtoc-toggle" @click="mobileTocOpen = !mobileTocOpen">
              <span><lucide-icon name="list" /> En este artículo</span>
              <lucide-icon :name="mobileTocOpen ? 'chevron-up' : 'chevron-down'" />
            </button>
            <div v-show="mobileTocOpen" class="pxkb__mtoc-links">
              <button
                v-for="item in toc"
                :key="item.id"
                type="button"
                :class="['pxkb__toc-link', { 'is-child': item.level === 3 }]"
                @click="scrollToSection(item.id)"
              >{{ item.text }}</button>
            </div>
          </div>

          <div ref="articleContent" class="pxkb__content ql-editor" v-html="article.content"></div>

          <div class="pxkb__footer-note">
            <lucide-icon name="info" />
            <span>Este artículo forma parte de la documentación oficial de PRODEX.</span>
          </div>
        </px-card>
      </main>

      <aside v-if="toc.length" class="pxkb__toc-aside">
        <div class="pxkb__toc-card">
          <div class="pxkb__toc-title"><lucide-icon name="list" /> En este artículo</div>
          <button
            v-for="item in toc"
            :key="item.id"
            type="button"
            :class="['pxkb__toc-link', { 'is-child': item.level === 3 }]"
            @click="scrollToSection(item.id)"
          >{{ item.text }}</button>
        </div>
      </aside>
    </div>

    <px-card v-else class="pxkb__article">
      <px-empty-state
        icon="file-question"
        title="Manual no disponible"
        description="El artículo no existe o ya no está publicado."
      >
        <px-button variant="primary" @click="$router.push({ name: 'KnowledgeBaseList' })">Volver al Manual PRODEX</px-button>
      </px-empty-state>
    </px-card>
  </div>
</template>

<script>
import PxPageHeader from "@/components/px-next/PxPageHeader.vue";
import PxCard from "@/components/px-next/PxCard.vue";
import PxButton from "@/components/px-next/PxButton.vue";
import PxBadge from "@/components/px-next/PxBadge.vue";
import PxEmptyState from "@/components/px-next/PxEmptyState.vue";

export default {
  name: 'KnowledgeBaseArticleView',
  metaInfo() {
    return { title: this.article.title ? this.article.title + ' - Manual PRODEX' : 'Manual PRODEX' };
  },
  components: { PxPageHeader, PxCard, PxButton, PxBadge, PxEmptyState },
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

<style lang="scss" src="@/assets/styles/sass/px-next/production.scss"></style>

<style lang="scss" scoped>
.pxkb { min-height: 100%; background: var(--pxn-bg); padding: var(--pxn-space-8) var(--pxn-space-9) var(--pxn-space-9); }
@media (max-width: 620px) { .pxkb { padding: var(--pxn-space-6) var(--pxn-space-5); } }
.pxkb__pad { padding: var(--pxn-space-6) 0; }
.pxkb__muted { color: var(--pxn-text-muted); font-size: var(--pxn-fs-sm); }

.pxkb__layout {
  display: grid;
  grid-template-columns: minmax(0, 960px) 250px;
  gap: var(--pxn-space-5);
  align-items: start;
  max-width: 1240px;
  margin-top: var(--pxn-space-5);
}
.pxkb__main { min-width: 0; }
.pxkb__article-head {
  margin-bottom: var(--pxn-space-5);
  padding-bottom: var(--pxn-space-4);
  border-bottom: 1px solid var(--pxn-border);
}
.pxkb__label {
  display: inline-flex; align-items: center; gap: var(--pxn-space-1);
  color: var(--pxn-primary); font-size: var(--pxn-fs-xs, 0.82rem); font-weight: 600;
  margin-bottom: var(--pxn-space-2); text-transform: uppercase; letter-spacing: 0.04em;
}
.pxkb__article-title { font-size: 1.6rem; font-weight: 700; color: var(--pxn-text); margin: 0 0 var(--pxn-space-2); line-height: 1.3; }
.pxkb__article-meta { display: flex; align-items: center; gap: var(--pxn-space-3); flex-wrap: wrap; }
@media (max-width: 576px) { .pxkb__article-title { font-size: 1.32rem; } }

.pxkb__content { min-height: 100px; line-height: 1.78; color: var(--pxn-text); font-size: 1rem; padding: 0; }
.pxkb__content ::v-deep .kb-anchor-heading { scroll-margin-top: 90px; }
.pxkb__content ::v-deep img { max-width: 100%; height: auto; border-radius: var(--pxn-radius-md, 10px); border: 1px solid var(--pxn-border); }
.pxkb__content ::v-deep h1,
.pxkb__content ::v-deep h2,
.pxkb__content ::v-deep h3 { color: var(--pxn-text); margin-top: 1.55em; margin-bottom: 0.6em; font-weight: 700; }
.pxkb__content ::v-deep h2 { font-size: 1.32rem; padding-bottom: 0.4rem; border-bottom: 1px solid var(--pxn-border); }
.pxkb__content ::v-deep h3 { font-size: 1.06rem; }
.pxkb__content ::v-deep p { margin-bottom: 0.9em; }
.pxkb__content ::v-deep ul,
.pxkb__content ::v-deep ol { padding-left: 1.55em; margin-bottom: 0.9em; }
.pxkb__content ::v-deep li { margin-bottom: 0.35rem; }
.pxkb__content ::v-deep a { color: var(--pxn-primary); }
.pxkb__content ::v-deep blockquote {
  border-left: 3px solid var(--pxn-primary);
  padding: 0.8rem 1rem; margin: 1rem 0;
  color: var(--pxn-text-muted);
  background: var(--pxn-primary-soft, rgba(94,106,210,0.06));
  border-radius: 0 var(--pxn-radius-sm, 8px) var(--pxn-radius-sm, 8px) 0;
}
.pxkb__content ::v-deep .manual-intro {
  background: var(--pxn-primary-soft, rgba(94,106,210,0.06));
  border: 1px solid var(--pxn-border);
  border-radius: var(--pxn-radius-md, 12px);
  padding: 1rem 1.1rem; margin-bottom: 1.25rem; color: var(--pxn-text);
}
.pxkb__content ::v-deep .manual-step {
  position: relative;
  background: var(--pxn-surface);
  border: 1px solid var(--pxn-border);
  border-radius: var(--pxn-radius-md, 12px);
  padding: 1rem 1rem 1rem 3.5rem; margin: 0.8rem 0;
}
.pxkb__content ::v-deep .manual-step-number {
  position: absolute; left: 1rem; top: 1rem;
  width: 1.75rem; height: 1.75rem; border-radius: 50%;
  display: inline-flex; align-items: center; justify-content: center;
  background: var(--pxn-primary); color: #fff; font-size: 0.8rem; font-weight: 700;
}
.pxkb__content ::v-deep .manual-step strong { color: var(--pxn-text); }
.pxkb__content ::v-deep .manual-note,
.pxkb__content ::v-deep .manual-warning,
.pxkb__content ::v-deep .manual-success { border-radius: var(--pxn-radius-md, 10px); padding: 0.9rem 1rem; margin: 1rem 0; border: 1px solid; }
.pxkb__content ::v-deep .manual-note { background: var(--pxn-info-soft, #eff6ff); border-color: var(--pxn-info-border, #bfdbfe); color: var(--pxn-info-text, #1e3a5f); }
.pxkb__content ::v-deep .manual-warning { background: var(--pxn-warning-soft, #fff8e6); border-color: var(--pxn-warning-border, #f6d78b); color: var(--pxn-warning-text, #654b16); }
.pxkb__content ::v-deep .manual-success { background: var(--pxn-success-soft, #ecfdf3); border-color: var(--pxn-success-border, #bbf7d0); color: var(--pxn-success-text, #24563a); }
.pxkb__content ::v-deep .manual-checklist { list-style: none; padding-left: 0; }
.pxkb__content ::v-deep .manual-checklist li { position: relative; padding-left: 1.7rem; margin-bottom: 0.5rem; }
.pxkb__content ::v-deep .manual-checklist li:before { content: '✓'; position: absolute; left: 0; color: var(--pxn-success, #16a34a); font-weight: 700; }
.pxkb__content ::v-deep details.manual-details { border: 1px solid var(--pxn-border); border-radius: var(--pxn-radius-md, 10px); margin: 0.8rem 0; background: var(--pxn-surface); }
.pxkb__content ::v-deep details.manual-details summary { cursor: pointer; padding: 0.9rem 1rem; font-weight: 600; color: var(--pxn-text); }
.pxkb__content ::v-deep details.manual-details > *:not(summary) { margin-left: 1rem; margin-right: 1rem; }
.pxkb__content ::v-deep table { width: 100%; border-collapse: collapse; margin: 1rem 0; font-size: 0.94rem; }
.pxkb__content ::v-deep th,
.pxkb__content ::v-deep td { border: 1px solid var(--pxn-border); padding: 0.65rem 0.75rem; text-align: left; vertical-align: top; }
.pxkb__content ::v-deep th { background: var(--pxn-surface-2, var(--pxn-surface)); color: var(--pxn-text); }

.pxkb__footer-note {
  margin-top: var(--pxn-space-6); padding-top: var(--pxn-space-4);
  border-top: 1px solid var(--pxn-border);
  display: flex; align-items: center; gap: var(--pxn-space-2);
  color: var(--pxn-text-muted); font-size: var(--pxn-fs-sm);
}

.pxkb__toc-aside { position: sticky; top: 90px; }
.pxkb__toc-card {
  background: var(--pxn-surface); border: 1px solid var(--pxn-border);
  border-radius: var(--pxn-radius-md, 12px); padding: var(--pxn-space-4);
}
.pxkb__toc-title {
  display: flex; align-items: center; gap: var(--pxn-space-1);
  color: var(--pxn-text); font-weight: 700; font-size: var(--pxn-fs-sm); margin-bottom: var(--pxn-space-2);
}
.pxkb__toc-link {
  width: 100%; display: block; border: 0; background: transparent; text-align: left;
  color: var(--pxn-text-muted); padding: 6px 4px; font-size: var(--pxn-fs-sm); line-height: 1.35; cursor: pointer;
}
.pxkb__toc-link:hover { color: var(--pxn-primary); }
.pxkb__toc-link.is-child { padding-left: 1rem; font-size: var(--pxn-fs-xs, 0.8rem); }

.pxkb__mtoc { display: none; border: 1px solid var(--pxn-border); border-radius: var(--pxn-radius-md, 10px); margin-bottom: var(--pxn-space-4); overflow: hidden; }
.pxkb__mtoc-toggle {
  width: 100%; border: 0; background: var(--pxn-surface-2, var(--pxn-surface));
  display: flex; align-items: center; justify-content: space-between;
  padding: 0.8rem 0.9rem; font-weight: 600; color: var(--pxn-text);
}
.pxkb__mtoc-toggle span { display: flex; gap: var(--pxn-space-1); align-items: center; }
.pxkb__mtoc-links { padding: var(--pxn-space-2) var(--pxn-space-3); background: var(--pxn-surface); }

@media (max-width: 1100px) {
  .pxkb__layout { grid-template-columns: minmax(0, 1fr); }
  .pxkb__toc-aside { display: none; }
  .pxkb__mtoc { display: block; }
}
@media (max-width: 576px) {
  .pxkb__content ::v-deep .manual-step { padding-left: 3rem; }
}
</style>
