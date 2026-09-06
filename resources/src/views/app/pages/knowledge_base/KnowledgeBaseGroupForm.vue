<template>
  <div class="px-next pxkb">
    <px-page-header
      :title="isEdit ? ($t('Edit') + ' ' + $t('Group')) : ($t('New') + ' ' + $t('Group'))"
      :breadcrumbs="[
        { label: 'Manual PRODEX', href: '#/app/knowledge-base/list' },
        { label: $t('Article_Groups') || 'Grupos de artículos', href: '#/app/knowledge-base/groups' },
        { label: isEdit ? $t('Edit') : $t('New') }
      ]"
    />

    <px-card :title="isEdit ? ($t('Edit') + ' ' + $t('Group')) : ($t('New') + ' ' + $t('Group'))" class="pxkb__card">
      <validation-observer ref="form_group">
        <form @submit.prevent="save">
          <div class="pxkb__formgrid">
            <validation-provider ref="nameProvider" name="Name" :rules="{ required: true }" v-slot="v">
              <px-field :label="$t('Name') + ' *'" :error="v.errors[0]">
                <template #default="{ id, invalid }"><px-input :id="id" v-model="form.name" :invalid="invalid" @input="v.validate" /></template>
              </px-field>
            </validation-provider>

            <validation-provider ref="slugProvider" name="Slug" :rules="{ required: true }" v-slot="v">
              <px-field :label="$t('Slug') + ' *'" :error="v.errors[0]">
                <template #default="{ id, invalid }"><px-input :id="id" v-model="form.slug" :invalid="invalid" @input="v.validate" /></template>
              </px-field>
            </validation-provider>

            <px-field :label="$t('Description')">
              <template #default="{ id }"><px-textarea :id="id" v-model="form.description" :rows="3" /></template>
            </px-field>

            <px-field :label="$t('Sort_order') || 'Orden'" class="pxkb__narrow">
              <template #default="{ id }"><px-input :id="id" type="number" min="0" v-model.number="form.sort_order" /></template>
            </px-field>
          </div>
        </form>
      </validation-observer>

      <template #footer>
        <px-button variant="ghost" @click="$router.push({ name: 'KnowledgeBaseGroups' })">{{ $t('Cancel') }}</px-button>
        <px-button variant="primary" icon="check" :loading="saving" :disabled="saving" @click="save">{{ $t('Save') }}</px-button>
      </template>
    </px-card>
  </div>
</template>

<script>
import PxPageHeader from "@/components/px-next/PxPageHeader.vue";
import PxCard from "@/components/px-next/PxCard.vue";
import PxButton from "@/components/px-next/PxButton.vue";
import PxField from "@/components/px-next/PxField.vue";
import PxInput from "@/components/px-next/PxInput.vue";
import PxTextarea from "@/components/px-next/PxTextarea.vue";

export default {
  name: 'KnowledgeBaseGroupForm',
  components: { PxPageHeader, PxCard, PxButton, PxField, PxInput, PxTextarea },
  props: {
    id: { type: [String, Number], default: null }
  },
  data() {
    return {
      form: { name: '', slug: '', description: '', sort_order: 0 },
      saving: false
    };
  },
  computed: {
    isEdit() {
      return this.id != null && this.id !== '';
    }
  },
  mounted() {
    if (this.isEdit) this.fetch();
  },
  methods: {
    syncValidators() {
      this.$nextTick(() => {
        if (this.$refs.nameProvider && this.$refs.nameProvider.syncValue) this.$refs.nameProvider.syncValue(this.form.name);
        if (this.$refs.slugProvider && this.$refs.slugProvider.syncValue) this.$refs.slugProvider.syncValue(this.form.slug);
      });
    },
    async fetch() {
      try {
        const res = await axios.get('/knowledge-base/groups/' + this.id);
        const g = res.data;
        this.form = { name: g.name, slug: g.slug, description: g.description || '', sort_order: g.sort_order ?? 0 };
        this.syncValidators();
      } catch (e) {
        if (this.$root && this.$root.$bvToast) {
          this.$root.$bvToast.toast(this.$t('Failed_to_load') || 'Failed to load', { variant: 'danger', solid: true });
        }
      }
    },
    async save() {
      const ok = await this.$refs.form_group.validate();
      if (!ok) return;
      this.saving = true;
      try {
        if (this.isEdit) {
          await axios.put('/knowledge-base/groups/' + this.id, this.form);
          if (this.$root && this.$root.$bvToast) this.$root.$bvToast.toast(this.$t('Updated') || 'Updated', { variant: 'success', solid: true });
        } else {
          await axios.post('/knowledge-base/groups', this.form);
          if (this.$root && this.$root.$bvToast) this.$root.$bvToast.toast(this.$t('Saved') || 'Saved', { variant: 'success', solid: true });
        }
        this.$router.push({ name: 'KnowledgeBaseGroups' });
      } catch (e) {
        const msg = (e.response && e.response.data && e.response.data.message) || this.$t('InvalidData') || 'Invalid data';
        if (this.$root && this.$root.$bvToast) this.$root.$bvToast.toast(msg, { variant: 'danger', solid: true });
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
.pxkb__card { margin-top: var(--pxn-space-5); max-width: 720px; }
.pxkb__formgrid { display: grid; grid-template-columns: minmax(0, 1fr); gap: var(--pxn-space-4); }
.pxkb__narrow { max-width: 160px; }
</style>
