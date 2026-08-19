<template>
  <div class="main-content">
    <breadcumb :page="$t('Invite_Codes')" :folder="$t('Store')" />

    <div v-if="isLoading" class="loading_page spinner spinner-primary mr-3"></div>

    <div v-else class="wrapper">
      <b-card class="shadow-sm mb-3" no-body>
        <div class="card-body d-flex align-items-center justify-content-between flex-wrap" style="gap:.5rem">
          <div class="d-flex align-items-center" style="gap:.5rem">
            <b-form-input v-model="search" :placeholder="$t('Search_by_code')" size="sm" style="max-width:220px" @input="debounceFetch" />
            <b-form-select v-model="filterStatus" size="sm" style="max-width:150px" @change="fetchCodes">
              <option value="">{{ $t('All') }}</option>
              <option value="active">{{ $t('Active') }}</option>
              <option value="inactive">{{ $t('Inactive') }}</option>
            </b-form-select>
          </div>
          <div class="d-flex" style="gap:.5rem">
            <b-button size="sm" variant="outline-primary" @click="showBatchModal = true"><lucide-icon class="mr-1" name="plus" />{{ $t('Generate_Batch') }}</b-button>
            <b-button size="sm" variant="primary" @click="openCreate"><lucide-icon class="mr-1" name="plus" />{{ $t('Create_Code') }}</b-button>
          </div>
        </div>
      </b-card>

      <b-card class="shadow-sm" no-body>
        <div class="table-responsive">
          <table class="table table-hover mb-0 invite-codes-table">
            <thead><tr><th>{{ $t('Code') }}</th><th>{{ $t('Status') }}</th><th>{{ $t('Uses') }}</th><th>{{ $t('Max_Uses') }}</th><th>{{ $t('Expires') }}</th><th>{{ $t('Created') }}</th><th class="text-right">{{ $t('Actions') }}</th></tr></thead>
            <tbody>
              <tr v-if="!codes.length"><td colspan="7" class="text-center text-muted py-4">{{ $t('No_invite_codes_yet') }}</td></tr>
              <tr v-for="code in codes" :key="code.id">
                <td><code class="text-dark font-weight-bold">{{ code.code }}</code><b-button size="sm" variant="link" class="p-0 ml-1" @click="copyCode(code.code)" :title="$t('Copy')"><lucide-icon class="text-muted" name="copy" /></b-button></td>
                <td><b-badge :variant="codeStatusVariant(code)" pill>{{ codeStatusLabel(code) }}</b-badge></td>
                <td>{{ code.times_used }}</td><td>{{ code.max_uses != null ? code.max_uses : '∞' }}</td><td>{{ code.expires_at ? formatDate(code.expires_at) : '—' }}</td><td>{{ formatDate(code.created_at) }}</td>
                <td class="text-right"><b-button size="sm" variant="outline-secondary" class="mr-1" @click="openEdit(code)" title="Editar"><lucide-icon name="pencil" /></b-button><b-button size="sm" variant="outline-danger" @click="deleteCode(code)" title="Eliminar"><lucide-icon name="x" /></b-button></td>
              </tr>
            </tbody>
          </table>
        </div>
        <div class="card-footer d-flex justify-content-between align-items-center" v-if="pagination.last_page > 1">
          <small class="text-muted">{{ $t('Page') }} {{ pagination.current_page }} / {{ pagination.last_page }}</small>
          <div><b-button size="sm" variant="light" :disabled="pagination.current_page <= 1" @click="goPage(pagination.current_page - 1)">‹</b-button><b-button size="sm" variant="light" :disabled="pagination.current_page >= pagination.last_page" @click="goPage(pagination.current_page + 1)">›</b-button></div>
        </div>
      </b-card>

      <b-modal v-model="showModal" :title="editingCode ? $t('Edit_Invite_Code') : $t('Create_Invite_Code')" hide-footer>
        <b-form @submit.prevent="saveCode">
          <b-form-group v-if="!editingCode" :label="$t('Code')" :description="$t('Leave_blank_to_auto_generate')"><b-form-input v-model="codeForm.code" placeholder="Ej. BIENVENIDO2026" maxlength="64"/></b-form-group>
          <b-form-group :label="$t('Max_Uses')" :description="$t('Leave_blank_for_unlimited')"><b-form-input v-model.number="codeForm.max_uses" type="number" min="1" placeholder="∞"/></b-form-group>
          <b-form-group :label="$t('Expires_At')"><b-form-input v-model="codeForm.expires_at" type="datetime-local"/></b-form-group>
          <b-form-group :label="$t('Active')"><b-form-checkbox v-model="codeForm.is_active" switch>{{ codeForm.is_active ? $t('Yes') : $t('No') }}</b-form-checkbox></b-form-group>
          <div class="d-flex justify-content-end" style="gap:.5rem"><b-button variant="light" @click="showModal = false">{{ $t('Cancel') }}</b-button><b-button variant="primary" type="submit" :disabled="saving"><span v-if="saving" class="spinner-border spinner-border-sm mr-1"></span>{{ editingCode ? $t('Update') : $t('Create') }}</b-button></div>
        </b-form>
      </b-modal>

      <b-modal v-model="showBatchModal" :title="$t('Generate_Invite_Codes')" hide-footer>
        <b-form @submit.prevent="generateBatch">
          <b-form-group :label="$t('Number_of_codes')"><b-form-input v-model.number="batchForm.count" type="number" min="1" max="50" required/></b-form-group>
          <b-form-group :label="$t('Max_Uses_Per_Code')" :description="$t('Leave_blank_for_unlimited')"><b-form-input v-model.number="batchForm.max_uses" type="number" min="1" placeholder="∞"/></b-form-group>
          <b-form-group :label="$t('Expires_At')"><b-form-input v-model="batchForm.expires_at" type="datetime-local"/></b-form-group>
          <div class="d-flex justify-content-end" style="gap:.5rem"><b-button variant="light" @click="showBatchModal = false">{{ $t('Cancel') }}</b-button><b-button variant="primary" type="submit" :disabled="saving"><span v-if="saving" class="spinner-border spinner-border-sm mr-1"></span>{{ $t('Generate') }}</b-button></div>
        </b-form>
      </b-modal>
    </div>
  </div>
</template>

<script>
export default {
  metaInfo: { title: 'Códigos de invitación' },
  data () { return { isLoading: true, saving: false, search: '', filterStatus: '', codes: [], pagination: { current_page: 1, last_page: 1 }, showModal: false, showBatchModal: false, editingCode: null, codeForm: { code: '', max_uses: null, expires_at: '', is_active: true }, batchForm: { count: 5, max_uses: null, expires_at: '' }, debounceTimer: null } },
  mounted () { this.fetchCodes() },
  methods: {
    makeToast (variant, msg, title) { this.$root.$bvToast && this.$root.$bvToast.toast(msg, { title, variant, solid: true }) },
    formatDate (d) { if (!d) return ''; var dt = new Date(d); return dt.toLocaleDateString('es-HN') + ' ' + dt.toLocaleTimeString('es-HN', { hour: '2-digit', minute: '2-digit' }) },
    codeStatusVariant (c) { if (!c.is_active) return 'secondary'; if (c.expires_at && new Date(c.expires_at) < new Date()) return 'danger'; if (c.max_uses != null && c.times_used >= c.max_uses) return 'warning'; return 'success' },
    codeStatusLabel (c) { if (!c.is_active) return 'Inactivo'; if (c.expires_at && new Date(c.expires_at) < new Date()) return 'Vencido'; if (c.max_uses != null && c.times_used >= c.max_uses) return 'Agotado'; return 'Activo' },
    copyCode (code) { if (navigator.clipboard) { navigator.clipboard.writeText(code); this.makeToast('success', 'Código copiado', 'Éxito') } },
    debounceFetch () { clearTimeout(this.debounceTimer); this.debounceTimer = setTimeout(this.fetchCodes, 400) },
    async fetchCodes (page) { try { this.isLoading = this.codes.length === 0; var params = { per_page: 15, page: page || 1 }; if (this.search) params.search = this.search; if (this.filterStatus) params.status = this.filterStatus; var resp = await axios.get('/store/invite-codes', { params }); this.codes = resp.data.data || []; this.pagination = { current_page: resp.data.current_page, last_page: resp.data.last_page } } catch (e) { this.makeToast('danger', 'No se pudieron cargar los códigos.', 'Error') } finally { this.isLoading = false } },
    goPage (p) { this.fetchCodes(p) },
    openCreate () { this.editingCode = null; this.codeForm = { code: '', max_uses: null, expires_at: '', is_active: true }; this.showModal = true },
    openEdit (c) { this.editingCode = c; this.codeForm = { code: c.code, max_uses: c.max_uses, expires_at: c.expires_at ? c.expires_at.replace(' ', 'T').substring(0, 16) : '', is_active: c.is_active }; this.showModal = true },
    async saveCode () { this.saving = true; try { var payload = { max_uses: this.codeForm.max_uses || null, expires_at: this.codeForm.expires_at || null, is_active: this.codeForm.is_active }; if (this.editingCode) { await axios.put('/store/invite-codes/' + this.editingCode.id, payload); this.makeToast('success', 'Código actualizado correctamente', 'Éxito') } else { payload.code = this.codeForm.code || null; await axios.post('/store/invite-codes', payload); this.makeToast('success', 'Código creado correctamente', 'Éxito') } this.showModal = false; await this.fetchCodes(this.pagination.current_page) } catch (e) { var msg = (e.response && e.response.data && e.response.data.message) || 'No se pudo guardar el código.'; this.makeToast('danger', msg, 'Error') } finally { this.saving = false } },
    async deleteCode (c) { if (!confirm('¿Deseas eliminar este código?')) return; try { await axios.delete('/store/invite-codes/' + c.id); this.makeToast('success', 'Código eliminado correctamente', 'Éxito'); await this.fetchCodes(this.pagination.current_page) } catch (e) { this.makeToast('danger', 'No se pudo eliminar el código.', 'Error') } },
    async generateBatch () { this.saving = true; try { var payload = { count: this.batchForm.count || 5, max_uses: this.batchForm.max_uses || null, expires_at: this.batchForm.expires_at || null }; var resp = await axios.post('/store/invite-codes/batch', payload); var n = (resp.data && resp.data.generated) || 0; this.makeToast('success', n + ' códigos generados', 'Éxito'); this.showBatchModal = false; await this.fetchCodes() } catch (e) { var msg2 = (e.response && e.response.data && e.response.data.message) || 'No se pudieron generar los códigos.'; this.makeToast('danger', msg2, 'Error') } finally { this.saving = false } }
  }
}
</script>

<style scoped>
.invite-codes-table thead tr th { background: #f8f9fa !important; color: #212529 !important; text-transform: none; font-size: 0.8125rem !important; letter-spacing: 0.01em; font-weight: 600; padding: 0.75rem 1rem !important; border-top: none !important; border-bottom: 2px solid #dee2e6 !important; white-space: nowrap !important; }
</style>