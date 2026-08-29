<template>
  <div class="main-content">
    <breadcumb :page="$t('BackupDatabase')" :folder="$t('Settings')"/>

    <div v-if="isLoading" class="loading_page spinner spinner-primary mr-3"></div>
    <b-card class="wrapper" v-if="!isLoading">
      <b-row class="mb-4">
        <b-col lg="12" md="12" sm="12">
          <b-card no-body class="mb-0">
            <b-card-body>
              <div class="d-flex justify-content-between align-items-center mb-3">
                <h5 class="mb-0">Destino de la copia de seguridad</h5>
              </div>

              <b-row>
                <b-col lg="6" md="6" sm="12" class="mb-3">
                  <b-form-group label="Destino">
                    <b-form-radio-group
                      v-model="backupDestination"
                      :options="[
                        { value: 'local', text: 'Solo almacenamiento local' },
                        { value: 'cloud', text: 'Nube (subir después de crear la copia local)' },
                      ]"
                      stacked
                    />
                    <small class="text-muted d-block mt-1">
                      Ruta de las copias locales: <code>/storage/app/public/backup</code>.
                    </small>
                  </b-form-group>
                </b-col>

                <b-col lg="6" md="6" sm="12" class="mb-3">
                  <b-form-group label="Ruta o carpeta en la nube (opcional)" v-if="backupDestination === 'cloud'">
                    <b-form-input
                      v-model="setting.backup_cloud_path"
                      placeholder="Ej.: ProdexBackups/"
                    />
                  </b-form-group>
                </b-col>
              </b-row>

              <b-row v-if="backupDestination === 'cloud'">
                <b-col lg="6" md="6" sm="12" class="mb-3">
                  <b-form-group label="Proveedor de almacenamiento en la nube">
                    <b-form-select
                      v-model="setting.backup_cloud_provider"
                      :options="[
                        { value: null, text: 'Seleccione un proveedor' },
                        { value: 'google_drive', text: 'Google Drive' },
                        { value: 'dropbox', text: 'Dropbox' },
                        { value: 's3', text: 'Compatible con S3 (AWS, MinIO, etc.)' },
                      ]"
                    />
                    <small class="text-muted d-block mt-1">
                      La copia se subirá a la nube después de generarse localmente.
                    </small>
                  </b-form-group>
                </b-col>
              </b-row>

              <b-row v-if="backupDestination === 'cloud' && setting.backup_cloud_provider === 's3'">
                <b-col lg="6" md="6" sm="12" class="mb-3">
                  <b-form-group label="Bucket (contenedor)">
                    <b-form-input v-model="setting.backup_s3_bucket" placeholder="Nombre del bucket" />
                  </b-form-group>
                </b-col>
                <b-col lg="6" md="6" sm="12" class="mb-3">
                  <b-form-group label="Región">
                    <b-form-input v-model="setting.backup_s3_region" placeholder="Ej.: us-east-1" />
                  </b-form-group>
                </b-col>
                <b-col lg="6" md="6" sm="12" class="mb-3">
                  <b-form-group label="Clave de acceso">
                    <b-form-input v-model="setting.backup_s3_access_key" placeholder="Clave de acceso" />
                  </b-form-group>
                </b-col>
                <b-col lg="6" md="6" sm="12" class="mb-3">
                  <b-form-group label="Clave secreta (déjela vacía para conservar la actual)">
                    <b-form-input type="text" v-model="setting.backup_s3_secret_key" placeholder="Clave secreta" />
                  </b-form-group>
                </b-col>
                <b-col lg="6" md="6" sm="12" class="mb-3">
                  <b-form-group label="Endpoint (opcional para MinIO)">
                    <b-form-input v-model="setting.backup_s3_endpoint" placeholder="Ej.: https://minio.ejemplo.com" />
                  </b-form-group>
                </b-col>
                <b-col lg="6" md="6" sm="12" class="mb-3">
                  <b-form-group label="Usar URLs con estilo de ruta (MinIO suele requerirlo)">
                    <b-form-checkbox switch v-model="setting.backup_s3_path_style">Activar</b-form-checkbox>
                  </b-form-group>
                </b-col>
              </b-row>

              <b-row v-if="backupDestination === 'cloud' && setting.backup_cloud_provider === 'google_drive'">
                <b-col lg="6" md="6" sm="12" class="mb-3">
                  <b-form-group label="ID de carpeta (opcional)">
                    <b-form-input v-model="setting.backup_gdrive_folder_id" placeholder="ID de la carpeta de Google Drive" />
                  </b-form-group>
                </b-col>
                <b-col lg="6" md="6" sm="12" class="mb-3">
                  <b-form-group label="Token de acceso (opcional, de corta duración)">
                    <b-form-input type="text" v-model="setting.backup_gdrive_access_token" placeholder="Token Bearer" />
                  </b-form-group>
                </b-col>
                <b-col lg="6" md="6" sm="12" class="mb-3">
                  <b-form-group label="Token de actualización (recomendado)">
                    <b-form-input type="text" v-model="setting.backup_gdrive_refresh_token" placeholder="Token de actualización" />
                  </b-form-group>
                </b-col>
                <b-col lg="6" md="6" sm="12" class="mb-3">
                  <b-form-group label="ID de cliente">
                    <b-form-input v-model="setting.backup_gdrive_client_id" placeholder="ID de cliente OAuth" />
                  </b-form-group>
                </b-col>
                <b-col lg="6" md="6" sm="12" class="mb-3">
                  <b-form-group label="Secreto del cliente (déjelo vacío para conservar el actual)">
                    <b-form-input type="text" v-model="setting.backup_gdrive_client_secret" placeholder="Secreto del cliente OAuth" />
                  </b-form-group>
                </b-col>
              </b-row>

              <b-row v-if="backupDestination === 'cloud' && setting.backup_cloud_provider === 'dropbox'">
                <b-col lg="6" md="6" sm="12" class="mb-3">
                  <b-form-group label="Ruta de carpeta en Dropbox (opcional)">
                    <b-form-input v-model="setting.backup_dropbox_path" placeholder="Ej.: /ProdexBackups" />
                  </b-form-group>
                </b-col>
                <b-col lg="6" md="6" sm="12" class="mb-3">
                  <b-form-group label="Token de acceso (déjelo vacío para conservar el actual)">
                    <b-form-input type="text" v-model="setting.backup_dropbox_access_token" placeholder="Token de Dropbox" />
                  </b-form-group>
                </b-col>
              </b-row>

              <div class="d-flex justify-content-end">
                <b-button variant="primary" @click="Submit_Backup_Settings()">
                  Guardar configuración de copias de seguridad
                </b-button>
              </div>
            </b-card-body>
          </b-card>
        </b-col>
      </b-row>

      <b-alert v-if="backupError" show variant="danger" dismissible @dismissed="backupError = null" class="mb-3">
        <h6 class="alert-heading">Se requiere configurar las copias de seguridad</h6>
        <p class="mb-2"><strong>No se encontró mysqldump.</strong> Configure <code>DUMP_PATH</code> en el archivo <code>.env</code>.</p>
        <p class="mb-2"><strong>Para Laragon en Windows:</strong></p>
        <ol class="mb-2 pl-3">
          <li>Abra el archivo <code>.env</code> ubicado en la raíz del proyecto.</li>
          <li>Busque la carpeta de su versión de MySQL en <code>C:\laragon\bin\mysql\</code>.</li>
          <li>Agregue esta línea y sustituya la versión por la que tenga instalada:</li>
        </ol>
        <pre class="bg-light p-2 mb-2"><code>DUMP_PATH="C:\\laragon\\bin\\mysql\\mysql-8.0.30\\bin\\mysqldump.exe"</code></pre>
        <p class="mb-0">También puede usar barras normales: <code>DUMP_PATH="C:/laragon/bin/mysql/mysql-8.0.30/bin/mysqldump.exe"</code></p>
        <p class="mb-0 mt-2"><small>Después de actualizar <code>.env</code>, ejecute: <code>php artisan config:clear</code></small></p>
      </b-alert>
      
      <span class="alert alert-danger">{{$t('You_will_find_your_backup_on')}} <strong>/storage/app/public/backup</strong> {{$t('and_save_it_to_your_pc')}}</span>
      <vue-good-table
        mode="remote"
        :columns="columns"
        :totalRows="totalRows"
        :rows="backups"
        styleClass="table-hover tableOne vgt-table"
      >
        <div slot="table-actions" class="mt-2 mb-3">
          <b-button
            @click="GenerateBackup()"
            size="sm"
            class="btn-rounded"
            variant="btn btn-primary btn-icon m-1"
          >
            <lucide-icon name="plus" />
            {{$t('GenerateBackup')}}
          </b-button>
        </div>

        <template slot="table-row" slot-scope="props">
          <span v-if="props.column.field == 'actions'">
            <a title="Eliminar" v-b-tooltip.hover @click="DeleteBackup(props.row.date)">
              <lucide-icon class="text-25 text-danger" name="x" />
            </a>
          </span>
        </template>
      </vue-good-table>
    </b-card>
  </div>
</template>

<script>
import NProgress from "nprogress";

export default {
  metaInfo: {
    title: "Copias de seguridad"
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
        {
          label: this.$t("date"),
          field: "date",
          tdClass: "text-left",
          thClass: "text-left"
        },
        {
          label: this.$t("Filesize"),
          field: "size",
          tdClass: "text-left",
          thClass: "text-left"
        },
        {
          label: this.$t("Action"),
          field: "actions",
          tdClass: "text-left",
          thClass: "text-left",
          sortable: false
        }
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