<template>
  <b-card class="attendance-identity-card">
    <div class="attendance-identity-heading">
      <div class="attendance-identity-icon"><lucide-icon name="fingerprint" /></div>
      <div>
        <h6>Control de asistencia</h6>
        <p>Vincula los códigos con los que este empleado aparece en relojes biométricos, tarjetas u otros sistemas.</p>
      </div>
    </div>

    <div v-if="loading" class="attendance-identity-loading">Cargando identificadores...</div>

    <template v-else>
      <div v-if="identifiers.length" class="attendance-identity-list">
        <div v-for="identifier in identifiers" :key="identifier.id" class="attendance-identity-row">
          <div>
            <strong>{{ identifier.external_user_id }}</strong>
            <span>{{ identifier.device ? identifier.device.name : providerName(identifier.provider) + ' · General' }}</span>
          </div>
          <span :class="['attendance-identity-status', identifier.is_active ? 'active' : 'inactive']">{{ identifier.is_active ? 'Activo' : 'Inactivo' }}</span>
          <button type="button" class="attendance-identity-remove" title="Eliminar vínculo" @click="removeIdentifier(identifier)">
            <lucide-icon name="x" />
          </button>
        </div>
      </div>
      <div v-else class="attendance-identity-empty">Este empleado todavía no tiene códigos de marcaje vinculados.</div>

      <div class="attendance-identity-form">
        <div class="attendance-identity-form-title">Vincular código</div>
        <b-form-group label="Dispositivo">
          <v-select
            v-model="form.attendance_device_id"
            :reduce="option => option.value"
            :options="deviceOptions"
            placeholder="General o selecciona un dispositivo"
            @input="onDeviceChanged"
          />
          <small class="text-muted">Déjalo vacío si el mismo código aplica a cualquier dispositivo del proveedor.</small>
        </b-form-group>
        <b-form-group label="Proveedor">
          <v-select v-model="form.provider" :disabled="!!form.attendance_device_id" :reduce="option => option.value" :options="providerOptions" />
        </b-form-group>
        <b-form-group label="Código de marcaje / ID en dispositivo">
          <b-form-input v-model.trim="form.external_user_id" placeholder="Ej. 0042" />
        </b-form-group>
        <b-button block variant="outline-primary" :disabled="saving || !form.external_user_id" @click="saveIdentifier">
          <lucide-icon name="link" class="mr-1" /> {{ saving ? 'Vinculando...' : 'Vincular código' }}
        </b-button>
      </div>

      <div class="attendance-identity-note">
        PRODEX no almacena la huella. Solo guarda el código con el que el dispositivo identifica a este empleado.
      </div>
    </template>
  </b-card>
</template>

<script>
export default {
  props: {
    employeeId: { type: [Number, String], required: true }
  },
  data() {
    return {
      loading: true,
      saving: false,
      identifiers: [],
      devices: [],
      form: { attendance_device_id: null, provider: 'generic', external_user_id: '' },
      providerOptions: [
        { label: 'Genérico', value: 'generic' },
        { label: 'ZKTeco', value: 'zkteco' },
        { label: 'Hikvision', value: 'hikvision' },
        { label: 'Otro', value: 'other' }
      ]
    };
  },
  computed: {
    deviceOptions() {
      return this.devices.map(device => ({
        label: device.name + (device.model ? ' · ' + device.model : ''),
        value: device.id,
        provider: device.provider
      }));
    }
  },
  mounted() {
    this.load();
  },
  methods: {
    load() {
      this.loading = true;
      axios.get(`/employees/${this.employeeId}/attendance-identifiers`)
        .then(response => {
          this.identifiers = response.data.identifiers || [];
          this.devices = response.data.devices || [];
        })
        .catch(() => this.toast('danger', 'No se pudieron cargar los identificadores de asistencia.', 'Error'))
        .finally(() => { this.loading = false; });
    },
    onDeviceChanged(id) {
      const device = this.devices.find(item => Number(item.id) === Number(id));
      if (device) this.form.provider = device.provider;
    },
    saveIdentifier() {
      this.saving = true;
      axios.post(`/employees/${this.employeeId}/attendance-identifiers`, this.form)
        .then(() => {
          this.form = { attendance_device_id: null, provider: 'generic', external_user_id: '' };
          this.toast('success', 'Código de marcaje vinculado al empleado.', 'Listo');
          this.load();
        })
        .catch(error => this.toast('danger', error.response && error.response.data && error.response.data.message ? error.response.data.message : 'No se pudo vincular el código.', 'Error'))
        .finally(() => { this.saving = false; });
    },
    removeIdentifier(identifier) {
      this.$swal({
        title: 'Eliminar vínculo',
        text: 'Los marcajes ya almacenados se conservarán, pero este código dejará de identificar automáticamente al empleado.',
        type: 'warning',
        showCancelButton: true,
        confirmButtonText: 'Eliminar',
        cancelButtonText: 'Cancelar'
      }).then(result => {
        if (!result.value) return;
        axios.delete(`/employees/${this.employeeId}/attendance-identifiers/${identifier.id}`)
          .then(() => this.load())
          .catch(() => this.toast('danger', 'No se pudo eliminar el vínculo.', 'Error'));
      });
    },
    providerName(provider) {
      const option = this.providerOptions.find(item => item.value === provider);
      return option ? option.label : provider;
    },
    toast(variant, msg, title) {
      this.$root.$bvToast.toast(msg, { title, variant, solid: true });
    }
  }
};
</script>

<style scoped>
.attendance-identity-card { border: 1px solid #e4eaf1; border-radius: 12px; }
.attendance-identity-heading { display: flex; gap: 12px; align-items: flex-start; margin-bottom: 18px; }
.attendance-identity-icon { width: 38px; height: 38px; display: flex; align-items: center; justify-content: center; flex: 0 0 auto; border-radius: 10px; background: #eef9fb; color: var(--primary-color,#38bfd3); }
.attendance-identity-heading h6 { margin: 1px 0 4px; font-size: 14px; font-weight: 700; color: #18212f; }
.attendance-identity-heading p { margin: 0; color: #667085; font-size: 11px; line-height: 1.5; }
.attendance-identity-list { margin-bottom: 16px; }
.attendance-identity-row { display: grid; grid-template-columns: 1fr auto 26px; gap: 8px; align-items: center; padding: 10px 0; border-bottom: 1px solid #edf1f5; }
.attendance-identity-row strong, .attendance-identity-row span { display: block; }
.attendance-identity-row strong { color: #253044; font-size: 13px; }
.attendance-identity-row > div span { color: #667085; font-size: 10px; margin-top: 2px; }
.attendance-identity-status { padding: 3px 7px; border-radius: 999px; font-size: 9px; font-weight: 700; }
.attendance-identity-status.active { background: #e7f8ef; color: #18794e; }
.attendance-identity-status.inactive { background: #eef2f6; color: #667085; }
.attendance-identity-remove { border: 0; background: transparent; color: #98a2b3; padding: 3px; cursor: pointer; }
.attendance-identity-remove:hover { color: #dc2626; }
.attendance-identity-remove svg { width: 16px; height: 16px; }
.attendance-identity-empty, .attendance-identity-loading { padding: 14px; margin-bottom: 16px; border-radius: 9px; background: #f8fafc; color: #667085; font-size: 11px; text-align: center; }
.attendance-identity-form { padding-top: 14px; border-top: 1px solid #edf1f5; }
.attendance-identity-form-title { margin-bottom: 12px; color: #344054; font-size: 12px; font-weight: 700; }
.attendance-identity-note { margin-top: 14px; padding: 10px 11px; border-radius: 8px; background: #f8fafc; color: #667085; font-size: 10px; line-height: 1.5; }
</style>
