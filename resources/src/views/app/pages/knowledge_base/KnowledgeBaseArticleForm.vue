<template>
  <div class="px-next pxkb">
    <px-page-header
      :title="isEdit ? ($t('Edit') + ' ' + $t('Article')) : ($t('New') + ' ' + $t('Article'))"
      :breadcrumbs="[
        { label: 'Manual PRODEX', href: '#/app/knowledge-base/list' },
        { label: $t('Articles'), href: '#/app/knowledge-base/articles' },
        { label: isEdit ? $t('Edit') : $t('New') }
      ]"
    />

    <px-card :title="isEdit ? ($t('Edit') + ' ' + $t('Article')) : ($t('New') + ' ' + $t('Article'))" class="pxkb__card">
      <validation-observer ref="form_article">
        <form @submit.prevent="save">
          <div class="pxkb__formgrid">
            <validation-provider ref="groupProvider" name="Group" :rules="{ required: true }" v-slot="v">
              <px-field :label="$t('Group') + ' *'" :error="v.errors[0]">
                <template #default="{ id }">
                  <vs-px
                    :input-id="id"
                    v-model="form.knowledge_base_article_group_id"
                    :reduce="o => o.value"
                    :options="groupSelectOptions"
                    :placeholder="$t('PleaseSelect')"
                    @input="val => { if ($refs.groupProvider) { $refs.groupProvider.syncValue(val); $refs.groupProvider.validate(); } }"
                  />
                </template>
              </px-field>
            </validation-provider>

            <validation-provider ref="titleProvider" name="Title" :rules="{ required: true }" v-slot="v">
              <px-field :label="$t('Title') + ' *'" :error="v.errors[0]">
                <template #default="{ id, invalid }"><px-input :id="id" v-model="form.title" :invalid="invalid" @input="v.validate" /></template>
              </px-field>
            </validation-provider>

            <validation-provider ref="slugProvider" name="Slug" :rules="{ required: true }" v-slot="v">
              <px-field :label="$t('Slug') + ' *'" :error="v.errors[0]">
                <template #default="{ id, invalid }"><px-input :id="id" v-model="form.slug" :invalid="invalid" @input="v.validate" /></template>
              </px-field>
            </validation-provider>

            <px-field :label="$t('Content')">
              <template #default>
                <div class="pxkb__editor">
                  <RichTextEditor
                    :value="form.content"
                    @input="form.content = $event"
                    editor-id="kb-article-editor"
                  />
                </div>
              </template>
            </px-field>

            <px-field :label="$t('Visibility') || 'Visibilidad'">
              <template #default>
                <px-check type="switch" :modelValue="!!form.is_internal" @change="v => form.is_internal = v">
                  {{ $t('Internal_Article') || 'Artículo interno (visible solo para usuarios con permiso de gestión del Manual)' }}
                </px-check>
              </template>
            </px-field>

            <div class="pxkb__grid2">
              <px-field :label="$t('Publication_date') || 'Fecha de publicación'" :hint="$t('Leave_empty_for_now') || 'Opcional — déjalo vacío para un borrador sin publicar.'">
                <template #default="{ id }"><px-input :id="id" type="date" v-model="form.published_at" /></template>
              </px-field>
              <px-field :label="$t('Sort_order') || 'Orden'" class="pxkb__narrow">
                <template #default="{ id }"><px-input :id="id" type="number" min="0" v-model.number="form.sort_order" /></template>
              </px-field>
            </div>
          </div>
        </form>
      </validation-observer>

      <template #footer>
        <px-button variant="ghost" @click="$router.push({ name: 'KnowledgeBaseArticles' })">{{ $t('Cancel') }}</px-button>
        <px-button v-if="isEdit" variant="secondary" icon="eye" @click="$router.push({ name: 'KnowledgeBaseArticleView', params: { id: id } })">{{ $t('View') }}</px-button>
        <px-button variant="primary" icon="check" :loading="saving" :disabled="saving" @click="save">{{ $t('Save') }}</px-button>
      </template>
    </px-card>
  </div>
</template>

<script>
import RichTextEditor from '@/components/RichTextEditor.vue';
import PxPageHeader from "@/components/px-next/PxPageHeader.vue";
import PxCard from "@/components/px-next/PxCard.vue";
import PxButton from "@/components/px-next/PxButton.vue";
import PxField from "@/components/px-next/PxField.vue";
import PxInput from "@/components/px-next/PxInput.vue";
import PxCheck from "@/components/px-next/PxCheck.vue";
import VsPx from "@/views/app/products/next/edit/VsPx.vue";

export default {
  name: 'KnowledgeBaseArticleForm',
  components: { RichTextEditor, PxPageHeader, PxCard, PxButton, PxField, PxInput, PxCheck, VsPx },
  props: {
    id: { type: [String, Number], default: null }
  },
  data() {
    return {
      form: {
        knowledge_base_article_group_id: null,
        title: '',
        slug: '',
        content: '',
        is_internal: false,
        sort_order: 0,
        published_at: ''
      },
      groups: [],
      saving: false
    };
  },
  computed: {
    isEdit() {
      return this.id != null && this.id !== '';
    },
    groupSelectOptions() {
      return this.groups.map(g => ({ label: g.name, value: g.id }));
    }
  },
  mounted() {
    this.fetchGroups();
    if (this.isEdit) this.fetch();
  },
  methods: {
    syncValidators() {
      this.$nextTick(() => {
        if (this.$refs.groupProvider && this.$refs.groupProvider.syncValue) this.$refs.groupProvider.syncValue(this.form.knowledge_base_article_group_id);
        if (this.$refs.titleProvider && this.$refs.titleProvider.syncValue) this.$refs.titleProvider.syncValue(this.form.title);
        if (this.$refs.slugProvider && this.$refs.slugProvider.syncValue) this.$refs.slugProvider.syncValue(this.form.slug);
      });
    },
    async fetchGroups() {
      try {
        const res = await axios.get('/knowledge-base/groups');
        this.groups = Array.isArray(res.data) ? res.data : (res.data.data || []);
        if (this.groups.length && !this.form.knowledge_base_article_group_id) {
          this.form.knowledge_base_article_group_id = this.groups[0].id;
        }
        this.syncValidators();
      } catch (e) {
        this.groups = [];
      }
    },
    async fetch() {
      try {
        const res = await axios.get('/knowledge-base/articles/' + this.id);
        const a = res.data;
        this.form = {
          knowledge_base_article_group_id: a.knowledge_base_article_group_id,
          title: a.title,
          slug: a.slug,
          content: a.content || '',
          is_internal: !!a.is_internal,
          sort_order: a.sort_order ?? 0,
          published_at: a.published_at ? String(a.published_at).slice(0, 10) : ''
        };
        this.syncValidators();
      } catch (e) {
        if (this.$root && this.$root.$bvToast) {
          this.$root.$bvToast.toast(this.$t('Failed_to_load') || 'Failed to load', { variant: 'danger', solid: true });
        }
      }
    },
    async save() {
      const ok = await this.$refs.form_article.validate();
      if (!ok) return;
      this.saving = true;
      try {
        const payload = { ...this.form };
        if (!payload.published_at) payload.published_at = null;
        if (this.isEdit) {
          await axios.put('/knowledge-base/articles/' + this.id, payload);
          if (this.$root && this.$root.$bvToast) {
            this.$root.$bvToast.toast(this.$t('Updated') || 'Updated', { variant: 'success', solid: true });
          }
        } else {
          await axios.post('/knowledge-base/articles', payload);
          if (this.$root && this.$root.$bvToast) {
            this.$root.$bvToast.toast(this.$t('Saved') || 'Saved', { variant: 'success', solid: true });
          }
        }
        this.$router.push({ name: 'KnowledgeBaseArticles' });
      } catch (e) {
        const msg = (e.response && e.response.data && (e.response.data.message || (e.response.data.errors && Object.values(e.response.data.errors).flat()[0]))) || this.$t('InvalidData') || 'Invalid data';
        if (this.$root && this.$root.$bvToast) {
          this.$root.$bvToast.toast(msg, { variant: 'danger', solid: true });
        }
      } finally {
        this.saving = false;
      }
    }
  }
};
</script>

<style lang="scss" src="@/assets/styles/sass/px-next/production.scss"></style>

<style lang="scss" scoped>
.pxkb { min-height: 100%; background: var(--pxn-bg); padding: var(--pxn-space-8) var(--pxn-space-9) var(--pxn-space-9); }
@media (max-width: 620px) { .pxkb { padding: var(--pxn-space-6) var(--pxn-space-5); } }
.pxkb__card { margin-top: var(--pxn-space-5); max-width: 820px; }
.pxkb__formgrid { display: grid; grid-template-columns: minmax(0, 1fr); gap: var(--pxn-space-4); }
.pxkb__grid2 { display: grid; grid-template-columns: repeat(2, minmax(0, 1fr)); gap: var(--pxn-space-4) var(--pxn-space-5); align-items: start; }
@media (max-width: 560px) { .pxkb__grid2 { grid-template-columns: minmax(0, 1fr); } }
.pxkb__narrow { max-width: 160px; }
.pxkb__editor { border: 1px solid var(--pxn-border); border-radius: var(--pxn-radius-md, 8px); overflow: hidden; }
.pxkb__editor ::v-deep .ql-toolbar { border: 0; border-bottom: 1px solid var(--pxn-border); background: var(--pxn-surface-2, var(--pxn-surface)); }
.pxkb__editor ::v-deep .ql-container { border: 0; min-height: 220px; }
</style>
