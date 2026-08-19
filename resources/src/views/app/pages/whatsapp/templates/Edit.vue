<template>
  <div class="main-content">
    <breadcumb page="Editar plantilla" folder="WhatsApp" />
    <div v-if="isLoading" class="loading_page spinner spinner-primary mr-3"></div>

    <b-row v-if="!isLoading">
      <b-col md="7">
        <b-card title="Detalles de la plantilla">
          <b-form-group label="Nombre">
            <b-form-input v-model="form.name" placeholder="Ej.: Factura creada" />
          </b-form-group>

          <b-form-group label="Clave" description="Se utiliza para relacionar la plantilla con automatizaciones.">
            <b-form-input v-model="form.key" placeholder="factura_creada" />
          </b-form-group>

          <b-row>
            <b-col md="6">
              <b-form-group label="Idioma">
                <b-form-input v-model="form.language" placeholder="es" />
              </b-form-group>
            </b-col>
            <b-col md="6">
              <b-form-group label="Categoría">
                <b-form-input v-model="form.category" placeholder="Opcional" />
              </b-form-group>
            </b-col>
          </b-row>

          <b-form-group label="Cuerpo del mensaje">
            <b-form-textarea v-model="form.body" rows="6" :placeholder="bodyPlaceholder" />
          </b-form-group>

          <b-form-checkbox v-model="form.is_active" switch>Activa</b-form-checkbox>

          <div class="mt-3">
            <b-button variant="primary" @click="save" :disabled="saving">
              <lucide-icon name="check" /> Actualizar
            </b-button>
            <router-link to="/app/whatsapp/templates" class="btn btn-outline-secondary ml-2">Cancelar</router-link>
          </div>
        </b-card>
      </b-col>

      <b-col md="5">
        <b-card title="Variables">
          <p class="text-muted"><small>Haz clic para insertar una variable en el cuerpo del mensaje.</small></p>
          <b-button
            v-for="(desc, v) in variables"
            :key="v"
            size="sm"
            variant="outline-info"
            class="m-1"
            :title="desc"
            @click="insertVar(v)"
          >
            {{ v }}
          </b-button>
        </b-card>

        <b-card title="Vista previa" class="mt-3">
          <div class="wa-preview">{{ preview }}</div>
        </b-card>
      </b-col>
    </b-row>
  </div>
</template>

<script>
export default {
  data() {
    return {
      isLoading: true,
      saving: false,
      variables: {},
      bodyPlaceholder: 'Hola {{customer_name}}, ...',
      sample: {
        '{{customer_name}}': 'Juan Pérez',
        '{{invoice_number}}': 'FAC-1024',
        '{{total_amount}}': '149.00',
        '{{company_name}}': 'PRODEX',
        '{{payment_status}}': 'pagado',
        '{{order_status}}': 'confirmado',
        '{{date}}': '2026-06-03',
      },
      form: {
        name: '',
        key: '',
        body: '',
        language: 'es',
        category: '',
        is_active: true,
      },
    };
  },
  computed: {
    preview() {
      let out = this.form.body || '';
      Object.keys(this.sample).forEach(k => {
        out = out.split(k).join(this.sample[k]);
      });
      return out || 'La vista previa del mensaje aparecerá aquí.';
    },
  },
  mounted() {
    this.load();
  },
  methods: {
    makeToast(variant, msg, title) {
      this.$root.$bvToast.toast(msg, { title, variant, solid: true });
    },
    load() {
      const id = this.$route.params.id;
      Promise.all([
        axios.get('/whatsapp/templates/' + id),
        axios.get('/whatsapp/templates', { params: { per_page: 1 } }),
      ])
        .then(([tplRes, listRes]) => {
          const t = tplRes.data;
          this.form = {
            name: t.name,
            key: t.key,
            body: t.body,
            language: t.language || 'es',
            category: t.category || '',
            is_active: !!t.is_active,
          };
          this.variables = listRes.data.variables || this.sample;
        })
        .catch(() => this.makeToast('danger', 'No se pudo cargar la plantilla.', 'Error'))
        .finally(() => {
          this.isLoading = false;
        });
    },
    insertVar(v) {
      this.form.body = (this.form.body || '') + v;
    },
    save() {
      this.saving = true;
      axios
        .put('/whatsapp/templates/' + this.$route.params.id, this.form)
        .then(() => {
          this.makeToast('success', 'Plantilla actualizada.', 'Éxito');
          this.$router.push('/app/whatsapp/templates');
        })
        .catch(err => {
          const msg = (err.response && err.response.data && err.response.data.message)
            ? err.response.data.message
            : 'No se pudo actualizar.';
          this.makeToast('danger', msg, 'Error');
        })
        .finally(() => {
          this.saving = false;
        });
    },
  },
};
</script>

<style scoped>
.wa-preview {
  white-space: pre-wrap;
  background: #e5ffd9;
  border-radius: 8px;
  padding: 12px 14px;
  min-height: 80px;
  font-size: 0.9rem;
}
</style>
