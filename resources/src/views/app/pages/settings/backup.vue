<template>
  <div class="px-next pxcfg">
    <px-page-header
      :title="$t('BackupDatabase')"
      :breadcrumbs="[{ label: $t('Settings'), href: '#/app/settings/System_settings' }, { label: $t('BackupDatabase') }]"
    />

    <div v-if="isLoading" class="pxcfg__pad">
      <px-skeleton variant="lines" :rows="6" />
    </div>

    <template v-else>
      <px-card :title="'Destino de la copia de seguridad'" class="pxcfg__card">
        <div class="pxcfg__grid">
          <px-field label="Destino" hint="Ruta de las copias locales: /storage/app/public/backup">
            <template #default>
              <div class="pxcfg__radios">
                <px-check type="radio" name="backup-dest" native-value="local" :modelValue="backupDestination" @change="v => backupDestination = v">
                  Solo almacenamiento local
                </px-check>
                <px-check type="radio" name="backup-dest" native-value="cloud" :modelValue="backupDestination" @change="v => backupDestination = v">
                  Nube (subir después de crear la copia local)
                </px-check>
              </div>
            </template>
          </px-field>

          <px-field v-if="backupDestination === 'cloud'" label="Ruta o carpeta en la nube (opcional)">
            <template #default="{ id }"><px-input :id="id" v-model="setting.backup_cloud_path" placeholder="Ej.: ProdexBackups/" /></template>
          </px-field>
        </div>

        <div v-if="backupDestination === 'cloud'" class="pxcfg__grid pxcfg__grid--mt">
          <px-field label="Proveedor de almacenamiento en la nube" hint="La copia se subirá a la nube después de generarse localmente.">
            <template #default="{ id }">
              <vs-px :input-id="id" v-model="setting.backup_cloud_provider" :reduce="o => o.value" placeholder="Seleccione un proveedor"
                :options="[
                  { label: 'Google Drive', value: 'google_drive' },
                  { label: 'Dropbox', value: 'dropbox' },
                  { label: 'Compatible con S3 (AWS, MinIO, etc.)', value: 's3' }
                ]" />
            </template>
          </px-field>
        </div>

        <div v-if="backupDestination === 'cloud' && setting.backup_cloud_provider === 's3'" class="pxcfg__grid pxcfg__grid--mt">
          <px-field label="Bucket (contenedor)">
            <template #default="{ id }"><px-input :id="id" v-model="setting.backup_s3_bucket" placeholder="Nombre del bucket" /></template>
          </px-field>
          <px-field label="Región">
            <template #default="{ id }"><px-input :id="id" v-model="setting.backup_s3_region" placeholder="Ej.: us-east-1" /></template>
          </px-field>
          <px-field label="Clave de acceso">
            <template #default="{ id }"><px-input :id="id" v-model="setting.backup_s3_access_key" placeholder="Clave de acceso" /></template>
          </px-field>
          <px-field label="Clave secreta (déjela vacía para conservar la actual)">
            <template #default="{ id }"><px-input :id="id" v-model="setting.backup_s3_secret_key" placeholder="Clave secreta" /></template>
          </px-field>
          <px-field label="Endpoint (opcional para MinIO)">
            <template #default="{ id }"><px-input :id="id" v-model="setting.backup_s3_endpoint" placeholder="Ej.: https://minio.ejemplo.com" /></template>
          </px-field>
          <px-field label="URLs con estilo de ruta">
            <template #default>
              <px-check type="switch" :modelValue="setting.backup_s3_path_style" @change="v => setting.backup_s3_path_style = v">
                Activar (MinIO suele requerirlo)
              </px-check>
            </template>
          </px-field>
        </div>

        <div v-if="backupDestination === 'cloud' && setting.backup_cloud_provider === 'google_drive'" class="pxcfg__grid pxcfg__grid--mt">
          <px-field label="ID de carpeta (opcional)">
            <template #default="{ id }"><px-input :id="id" v-model="setting.backup_gdrive_folder_id" placeholder="ID de la carpeta de Google Drive" /></template>
          </px-field>
          <px-field label="Token de acceso (opcional, de corta duración)">
            <template #default="{ id }"><px-input :id="id" v-model="setting.backup_gdrive_access_token" placeholder="Token Bearer" /></template>
          </px-field>
          <px-field label="Token de actualización (recomendado)">
            <template #default="{ id }"><px-input :id="id" v-model="setting.backup_gdrive_refresh_token" placeholder="Token de actualización" /></template>
          </px-field>
          <px-field label="ID de cliente">
            <template #default="{ id }"><px-input :id="id" v-model="setting.backup_gdrive_client_id" placeholder="ID de cliente OAuth" /></template>
          </px-field>
          <px-field label="Secreto del cliente (déjelo vacío para conservar el actual)">
            <template #default="{ id }"><px-input :id="id" v-model="setting.backup_gdrive_client_secret" placeholder="Secreto del cliente OAuth" /></template>
          </px-field>
        </div>

        <div v-if="backupDestination === 'cloud' && setting.backup_cloud_provider === 'dropbox'" class="pxcfg__grid pxcfg__grid--mt">
          <px-field label="Ruta de carpeta en Dropbox (opcional)">
            <template #default="{ id }"><px-input :id="id" v-model="setting.backup_dropbox_path" placeholder="Ej.: /ProdexBackups" /></template>
          </px-field>
          <px-field label="Token de acceso (déjelo vacío para conservar el actual)">
            <template #default="{ id }"><px-input :id="id" v-model="setting.backup_dropbox_access_token" placeholder="Token de Dropbox" /></template>
          </px-field>
        </div>

        <template #footer>
          <px-button variant="primary" @click="Submit_Backup_Settings()">Guardar configuración de copias de seguridad</px-button>
        </template>
      </px-card>

      <px-alert v-if="backupError" tone="danger" dismissible title="Se requiere configurar las copias de seguridad" @dismiss="backupError = null" class="pxcfg__alert">
        <p><strong>No se encontró mysqldump.</strong> Configure <code>DUMP_PATH</code> en el archivo <code>.env</code>.</p>
        <p><strong>Para Laragon en Windows:</strong></p>
        <ol>
          <li>Abra el archivo <code>.env</code> ubicado en la raíz del proyecto.</li>
          <li>Busque la carpeta de su versión de MySQL en <code>C:\laragon\bin\mysql\</code>.</li>
          <li>Agregue esta línea y sustituya la versión por la que tenga instalada:</li>
        </ol>
        <pre><code>DUMP_PATH="C:\\laragon\\bin\\mysql\\mysql-8.0.30\\bin\\mysqldump.exe"</code></pre>
        <p>También puede usar barras normales: <code>DUMP_PATH="C:/laragon/bin/mysql/mysql-8.0.30/bin/mysqldump.exe"</code></p>
        <p><small>Después de actualizar <code>.env</code>, ejecute: <code>php artisan config:clear</code></small></p>
      </px-alert>

      <px-alert tone="info" bare class="pxcfg__alert">
        {{ $t('You_will_find_your_backup_on') }} <strong>/storage/app/public/backup</strong> {{ $t('and_save_it_to_your_pc') }}
      </px-alert>

      <px-toolbar :searchable="false">
        <template #actions>
          <px-button variant="primary" size="sm" icon="plus" @click="GenerateBackup()">{{ $t('GenerateBackup') }}</px-button>
        </template>
      </px-toolbar>

      <div class="pxcfg__tablewrap">
        <px-table
          v-if="backups.length"
          :columns="columns"
          :rows="backups"
          row-key="date"
          has-row-actions
        >
          <template #row-actions="{ row }">
            <px-button class="pxcfg__del" variant="ghost" size="sm" icon-only icon="trash-2" aria-label="Eliminar" @click="DeleteBackup(row.date)" />
          </template>
        </px-table>
        <px-empty-state v-else icon="database-backup" title="Sin copias de seguridad" description="Genera una copia para verla en esta lista." />
      </div>
    </template>
  </div>
</template>

<script>
import NProgress from "nprogress";
import PxPageHeader from "@/components/px-next/PxPageHeader.vue";
import PxToolbar from "@/components/px-next/PxToolbar.vue";
import PxTable from "@/components/px-next/PxTable.vue";
import PxButton from "@/components/px-next/PxButton.vue";
import PxCard from "@/components/px-next/PxCard.vue";
import PxField from "@/components/px-next/PxField.vue";
import PxInput from "@/components/px-next/PxInput.vue";
import PxCheck from "@/components/px-next/PxCheck.vue";
import PxAlert from "@/components/px-next/PxAlert.vue";
import PxEmptyState from "@/components/px-next/PxEmptyState.vue";
import VsPx from "@/views/app/products/next/edit/VsPx.vue";

export default {
  metaInfo: {
    title: "Copias de seguridad"
  },
  components: {
    PxPageHeader, PxToolbar, PxTable, PxButton, PxCard, PxField, PxInput,
    PxCheck, PxAlert, PxEmptyState, "vs-px": VsPx
  },
  data() {
    return {
      backups: [],
      isLoading: true,
      totalRows: "",
      backupError: null,
      setting: {
        id: "",
        backup_cloud_enabled: false,
        backup_cloud_provider: null,
        backup_cloud_path: "",
        backup_s3_bucket: "",
        backup_s3_region: "",
        backup_s3_access_key: "",
        backup_s3_secret_key: "",
        backup_s3_endpoint: "",
        backup_s3_path_style: false,
        backup_gdrive_folder_id: "",
        backup_gdrive_access_token: "",
        backup_gdrive_refresh_token: "",
        backup_gdrive_client_id: "",
        backup_gdrive_client_secret: "",
        backup_dropbox_path: "",
        backup_dropbox_access_token: "",
        backup_s3_has_secret_key: false,
        backup_gdrive_has_access_token: false,
        backup_gdrive_has_refresh_token: false,
        backup_gdrive_has_client_secret: false,
        backup_dropbox_has_access_token: false,
      }
    };
  },

  computed: {
    columns() {
      return [
        { key: "date", label: this.$t("date") },
        { key: "size", label: this.$t("Filesize") }
      ];
    },
    backupDestination: {
      get() {
        const cloudRaw = this.setting ? this.setting.backup_cloud_enabled : false;
        const cloud = (cloudRaw === true || cloudRaw === 1 || cloudRaw === '1' || cloudRaw === 'true');
        return cloud ? 'cloud' : 'local';
      },
      set(v) {
        if (!this.setting) return;
        this.setting.backup_cloud_enabled = (v === 'cloud');
      }
    }
  },

  methods: {
    Get_Settings() {
      axios
        .get("get_Settings_data", { params: { include_secrets: 1 } })
        .then(response => {
          this.setting = { ...this.setting, ...(response.data.settings || {}) };
        })
        .catch(() => {});
    },

    Submit_Backup_Settings() {
      NProgress.start();
      NProgress.set(0.1);
      var self = this;
      self.data = new FormData();
      self.data.append("backup_cloud_enabled", self.setting.backup_cloud_enabled ? 1 : 0);
      self.data.append("backup_cloud_provider", self.setting.backup_cloud_provider || "");
      self.data.append("backup_cloud_path", self.setting.backup_cloud_path || "");
      self.data.append("backup_s3_bucket", self.setting.backup_s3_bucket || "");
      self.data.append("backup_s3_region", self.setting.backup_s3_region || "");
      self.data.append("backup_s3_access_key", self.setting.backup_s3_access_key || "");
      self.data.append("backup_s3_secret_key", self.setting.backup_s3_secret_key || "");
      self.data.append("backup_s3_endpoint", self.setting.backup_s3_endpoint || "");
      self.data.append("backup_s3_path_style", self.setting.backup_s3_path_style ? 1 : 0);
      self.data.append("backup_gdrive_folder_id", self.setting.backup_gdrive_folder_id || "");
      self.data.append("backup_gdrive_access_token", self.setting.backup_gdrive_access_token || "");
      self.data.append("backup_gdrive_refresh_token", self.setting.backup_gdrive_refresh_token || "");
      self.data.append("backup_gdrive_client_id", self.setting.backup_gdrive_client_id || "");
      self.data.append("backup_gdrive_client_secret", self.setting.backup_gdrive_client_secret || "");
      self.data.append("backup_dropbox_path", self.setting.backup_dropbox_path || "");
      self.data.append("backup_dropbox_access_token", self.setting.backup_dropbox_access_token || "");
      self.data.append("_method", "put");

      axios
        .post("settings/" + self.setting.id, self.data)
        .then(() => {
          this.makeToast("success", this.$t("Successfully_Updated"), this.$t("Success"));
          NProgress.done();
        })
        .catch(error => {
          const msg =
            (error && error.response && error.response.data && (error.response.data.message || error.response.data.error)) ||
            this.$t("InvalidData");
          this.makeToast("danger", msg, this.$t("Failed"));
          NProgress.done();
        });
    },

    GenerateBackup() {
      NProgress.start();
      NProgress.set(0.1);
      axios
        .get("generate_new_backup")
        .then(response => {
          Fire.$emit("Generate_Backup");
          if (response.data && response.data.success === false) {
            const errorMsg = response.data.error || response.data.message || "No se pudo generar la copia de seguridad.";
            if (errorMsg.includes('mysqldump') && (errorMsg.includes('not found') || errorMsg.includes('no se encontró'))) {
              this.backupError = true;
            }
            this.makeToast("danger", errorMsg, this.$t("Failed"));
          } else {
            this.backupError = null;
            this.makeToast("success", "Copia de seguridad generada correctamente.", this.$t("Success"));
          }
          setTimeout(() => NProgress.done(), 500);
        })
        .catch(error => {
          let errorMsg = "No se pudo generar la copia de seguridad.";
          if (error.response && error.response.data) {
            if (error.response.data.error) {
              errorMsg = error.response.data.error;
            } else if (error.response.data.message) {
              errorMsg = error.response.data.message;
            }
          }
          if (errorMsg.includes('mysqldump') && (errorMsg.includes('not found') || errorMsg.includes('no se encontró'))) {
            this.backupError = true;
          }
          this.makeToast("danger", errorMsg, this.$t("Failed"));
          setTimeout(() => NProgress.done(), 500);
        });
    },

    Get_Backups() {
      NProgress.start();
      NProgress.set(0.1);
      axios
        .get("get_backup")
        .then(response => {
          this.backups = response.data.backups;
          this.totalRows = response.data.totalRows;
          NProgress.done();
          this.isLoading = false;
        })
        .catch(() => {
          NProgress.done();
          setTimeout(() => {
            this.isLoading = false;
          }, 500);
        });
    },

    makeToast(variant, msg, title) {
      this.$root.$bvToast.toast(msg, {
        title: title,
        variant: variant,
        solid: true
      });
    },

    DeleteBackup(date) {
      this.$swal({
        title: this.$t("Delete_Title"),
        text: this.$t("Delete_Text"),
        type: "warning",
        showCancelButton: true,
        confirmButtonColor: "var(--px-primary)",
        cancelButtonColor: "#d33",
        cancelButtonText: this.$t("Delete_cancelButtonText"),
        confirmButtonText: this.$t("Delete_confirmButtonText")
      }).then(result => {
        if (result.value) {
          axios
            .delete("delete_backup/" + date)
            .then(() => {
              this.$swal(
                this.$t("Delete_Deleted"),
                this.$t("Deleted_in_successfully"),
                "success"
              );
              Fire.$emit("Delete_Backup");
            })
            .catch(() => {
              this.$swal(
                this.$t("Delete_Failed"),
                this.$t("Delete_Therewassomethingwronge"),
                "warning"
              );
            });
        }
      });
    }
  },

  created: function() {
    this.Get_Settings();
    this.Get_Backups();

    Fire.$on("Generate_Backup", () => {
      setTimeout(() => {
        this.Get_Backups();
      }, 500);
    });

    Fire.$on("Delete_Backup", () => {
      setTimeout(() => {
        this.Get_Backups();
        NProgress.done();
      }, 500);
    });
  }
};
</script>

<style lang="scss" src="@/assets/styles/sass/px-next/production.scss"></style>

<style lang="scss" scoped>
.pxcfg { min-height: 100%; background: var(--pxn-bg); padding: var(--pxn-space-8) var(--pxn-space-9) var(--pxn-space-9); }
@media (max-width: 620px) { .pxcfg { padding: var(--pxn-space-6) var(--pxn-space-5); } }
.pxcfg__pad { padding: var(--pxn-space-6) 0; }
.pxcfg__card { margin-top: var(--pxn-space-5); }
.pxcfg__grid { display: grid; grid-template-columns: repeat(2, minmax(0, 1fr)); gap: var(--pxn-space-4) var(--pxn-space-5); }
.pxcfg__grid--mt { margin-top: var(--pxn-space-4); }
@media (max-width: 640px) { .pxcfg__grid { grid-template-columns: minmax(0, 1fr); } }
.pxcfg__radios { display: flex; flex-direction: column; gap: var(--pxn-space-2); }
.pxcfg__alert { margin-top: var(--pxn-space-4); }
.pxcfg__alert pre { background: var(--pxn-surface-2); padding: var(--pxn-space-3); border-radius: var(--pxn-radius-sm); overflow-x: auto; font-size: var(--pxn-fs-xs); }
.pxcfg__alert code { font-family: var(--pxn-font-mono, monospace); font-size: 0.9em; }
.pxcfg__alert ol { margin: var(--pxn-space-2) 0; padding-left: var(--pxn-space-6); }
.pxcfg__tablewrap { margin-top: var(--pxn-space-4); }
.pxcfg__del ::v-deep .pxn-btn__icon { color: var(--pxn-danger); }
</style>
