<template>
  <div class="main-content">
    <breadcumb :page="$t('Payroll')" :folder="$t('hrm')"/>

    <div v-if="isLoading" class="loading_page spinner spinner-primary mr-3"></div>
    <b-card class="wrapper" v-if="!isLoading">
      <vue-good-table
        mode="remote"
        :columns="columns"
        :totalRows="totalRows"
        :rows="payrolls"
        @on-page-change="onPageChange"
        @on-per-page-change="onPerPageChange"
        @on-search="onSearch"
        :search-options="{
        enabled: true,
        placeholder: $t('Search_this_table'),  
      }"
       
        :pagination-options="{
        enabled: true,
        mode: 'records',
        nextLabel: 'next',
        prevLabel: 'prev',
      }"
        styleClass="table-hover tableOne vgt-table"
      >
        <div slot="selected-row-actions">
          <button class="btn btn-danger btn-sm" @click="delete_by_selected()">{{$t('Del')}}</button>
        </div>
        <div slot="table-actions" class="mt-2 mb-3">
          <b-button
            @click="Function_New_Payroll()"
            class="btn-rounded"
            variant="btn btn-primary btn-icon m-1"
          >
            <lucide-icon name="plus" />
            {{$t('Add')}}
          </b-button>
        </div>

        <template slot="table-row" slot-scope="props">
          <span v-if="props.column.field == 'actions'">
            <a @click="Payroll_Details(props.row)" class="cursor-pointer" title="View" v-b-tooltip.hover>
              <lucide-icon class="text-25 text-info" name="eye" />
            </a>
            <a @click="Function_Edit_Payroll(props.row)" class="cursor-pointer" title="Edit" v-b-tooltip.hover>
              <lucide-icon class="text-25 text-success" name="pencil" />
            </a>
            <a title="Delete" v-b-tooltip.hover class="cursor-pointer" @click="Remove_Payroll(props.row.id)">
              <lucide-icon class="text-25 text-danger" name="x" />
            </a>
          </span>
          <div v-else-if="props.column.field == 'payment_status'">
            <span
              v-if="props.row.payment_status == 'paid'"
              class="badge badge-outline-success"
            >{{$t('Paid')}}</span>
          </div>
          <div v-else-if="props.column.field == 'documents'">
            <a
              v-if="props.row.documents_count > 0"
              class="badge badge-info cursor-pointer"
              :title="$t('Attached_Documents')"
              v-b-tooltip.hover
              @click="View_Documents(props.row)"
            >
              <lucide-icon name="file" /> {{ props.row.documents_count }}
            </a>
            <span v-else class="text-muted">-</span>
          </div>
        </template>
      </vue-good-table>
    </b-card>

    <validation-observer ref="Create_Payroll">
      <b-modal hide-footer size="md" id="Modal_New_Payroll" :title="editmode?$t('Edit'):$t('Add')">
        <b-form @submit.prevent="Submit_Payroll">
          <b-row>
            
              <!-- date -->
              <b-col md="12">
                <validation-provider
                  name="date"
                  :rules="{ required: true}"
                  v-slot="validationContext"
                >
                    <b-form-group :label="$t('date') + ' ' + '*'">
                        <Datepicker id="date" name="date" :placeholder="$t('date')" v-model="payroll.date" 
                            input-class="form-control back_important" format="yyyy-MM-dd"  @closed="payroll.date=formatDate(payroll.date)">
                        </Datepicker>
                        <b-form-invalid-feedback id="date-feedback">{{ validationContext.errors[0] }}</b-form-invalid-feedback>
                     </b-form-group>
                </validation-provider>
              </b-col>

              <!-- Employee -->
              <b-col md="12">
                <validation-provider name="Employee" :rules="{ required: true}">
                  <b-form-group slot-scope="{ valid, errors }" :label="$t('Employee') + ' ' + '*'">
                    <v-select
                      :class="{'is-invalid': !!errors.length}"
                      :state="errors[0] ? false : (valid ? true : null)"
                      v-model="payroll.employee_id"
                      class="required"
                      required
                      @input="Selected_Employee"
                      :placeholder="$t('Choose_Employee')"
                      :reduce="label => label.value"
                      :options="employees.map(employees => ({label: employees.username, value: employees.id}))"
                    />
                    <b-form-invalid-feedback>{{ errors[0] }}</b-form-invalid-feedback>
                  </b-form-group>
                </validation-provider>
              </b-col>

              <!-- Account -->
              <b-col md="12">
                <validation-provider name="Account">
                  <b-form-group slot-scope="{ valid, errors }" :label="$t('Account')">
                    <v-select
                      :class="{'is-invalid': !!errors.length}"
                      :state="errors[0] ? false : (valid ? true : null)"
                      v-model="payroll.account_id"
                      @input="Selected_Account"
                      :reduce="label => label.value"
                      :placeholder="$t('Choose_Account')"
                      :options="accounts.map(accounts => ({label: accounts.account_name, value: accounts.id}))"
                    />
                    <b-form-invalid-feedback>{{ errors[0] }}</b-form-invalid-feedback>
                  </b-form-group>
                </validation-provider>
              </b-col>

              <!-- Paying Amount  -->
              <b-col md="12">
                <validation-provider
                  name="Amount"
                  :rules="{ required: true , regex: /^\d*\.?\d*$/}"
                  v-slot="validationContext"
                >
                  <b-form-group :label="$t('Amount') + ' ' + '*'">
                    <b-form-input
                      @keyup="Verified_paidAmount(facture.montant)"
                      label="Amount"
                      type="text"
                      :placeholder="$t('Paying_Amount')"
                      v-model.number="payroll.amount"
                      :state="getValidationState(validationContext)"
                      aria-describedby="Amount-feedback"
                    ></b-form-input>
                    <b-form-invalid-feedback id="Amount-feedback">{{ validationContext.errors[0] }}</b-form-invalid-feedback>
                  </b-form-group>
                </validation-provider>
              </b-col>

               <!-- Payment choice -->
              <b-col md="12">
                <validation-provider name="Payment choice" :rules="{ required: true}">
                  <b-form-group slot-scope="{ valid, errors }" :label="$t('Paymentchoice') + ' ' + '*'">
                    <v-select
                      :class="{'is-invalid': !!errors.length}"
                      :state="errors[0] ? false : (valid ? true : null)"
                      v-model="payroll.payment_method_id"
                      :reduce="label => label.value"
                      :placeholder="$t('PleaseSelect')"
                      :options="payment_methods.map(payment_methods => ({label: payment_methods.name, value: payment_methods.id}))"
                    ></v-select>
                    <b-form-invalid-feedback>{{ errors[0] }}</b-form-invalid-feedback>
                  </b-form-group>
                </validation-provider>
              </b-col>

              <!-- Receiver Account Number -->
              <b-col md="12">
                <b-form-group :label="$t('Receiver_Account_Number')">
                  <b-form-input
                    type="text"
                    :placeholder="$t('Receiver_Account_Number')"
                    v-model="payroll.receiver_account_number"
                  ></b-form-input>
                </b-form-group>
              </b-col>

              <!-- Payment Reference Number -->
              <b-col md="12">
                <b-form-group :label="$t('Payment_Reference_Number')">
                  <b-form-input
                    type="text"
                    :placeholder="$t('Payment_Reference_Number')"
                    v-model="payroll.payment_reference_number"
                  ></b-form-input>
                </b-form-group>
              </b-col>

              <!-- Attach Documents -->
              <b-col md="12">
                <hr class="mt-1 mb-3">
                <h6>{{$t('Attach_Documents')}}</h6>
                <b-form-group :label="$t('Upload_Documents')">
                  <b-form-file
                    v-model="selectedFiles"
                    :placeholder="$t('Choose_files_or_drop_them_here')"
                    :drop-placeholder="$t('Drop_files_here')"
                    multiple
                    accept=".pdf,.doc,.docx,.xls,.xlsx,.jpg,.jpeg,.png,.gif"
                    @change="onFileChange"
                  ></b-form-file>
                </b-form-group>
                <b-button
                  variant="primary"
                  size="sm"
                  @click="Upload_Documents"
                  :disabled="!selectedFiles || selectedFiles.length === 0 || uploadProcessing"
                >
                  <lucide-icon name="upload" /> {{$t('Upload')}}
                </b-button>
                <div v-if="uploadProcessing" class="mt-2">
                  <div class="spinner sm spinner-primary"></div>
                </div>
              </b-col>

              <!-- Attached Documents -->
              <b-col md="12" class="mt-3">
                <h6>{{$t('Attached_Documents')}}</h6>
                <div class="table-responsive">
                  <table class="table table-hover table-bordered table-sm">
                    <thead>
                      <tr>
                        <th scope="col">{{$t('File_Name')}}</th>
                        <th scope="col">{{$t('Size')}}</th>
                        <th scope="col">{{$t('Uploaded_Date')}}</th>
                        <th scope="col">{{$t('Action')}}</th>
                      </tr>
                    </thead>
                    <tbody>
                      <tr v-if="documents.length <= 0 && pendingFiles.length <= 0">
                        <td colspan="4" class="text-center">{{$t('NodataAvailable')}}</td>
                      </tr>
                      <tr v-for="(file, index) in pendingFiles" :key="'pending_' + index">
                        <td>
                          <lucide-icon class="mr-1" name="file" />
                          {{file.name}}
                        </td>
                        <td>{{formatFileSize(file.size)}}</td>
                        <td>---</td>
                        <td>
                          <button
                            type="button"
                            title="Delete"
                            class="btn btn-icon btn-danger btn-sm"
                            @click="Remove_Pending_File(index)"
                          >
                            <lucide-icon name="x" />
                          </button>
                        </td>
                      </tr>
                      <tr v-for="document in documents" :key="document.id">
                        <td>
                          <lucide-icon class="mr-1" name="file" />
                          {{document.name}}
                        </td>
                        <td>{{formatFileSize(document.size)}}</td>
                        <td>{{formatDateTime(document.created_at)}}</td>
                        <td>
                          <div role="group" aria-label="Document actions" class="btn-group">
                            <button
                              type="button"
                              title="Download"
                              class="btn btn-icon btn-success btn-sm"
                              @click="Download_Document(document)"
                            >
                              <lucide-icon name="download" />
                            </button>
                            <button
                              type="button"
                              title="Delete"
                              class="btn btn-icon btn-danger btn-sm"
                              @click="Remove_Document(document.id)"
                            >
                              <lucide-icon name="x" />
                            </button>
                          </div>
                        </td>
                      </tr>
                    </tbody>
                  </table>
                </div>
              </b-col>

            <b-col md="12" class="mt-3">
                <b-button variant="primary" type="submit"  :disabled="SubmitProcessing"><lucide-icon class="me-2 font-weight-bold" name="check" /> {{$t('submit')}}</b-button>
                  <div v-once class="typo__p" v-if="SubmitProcessing">
                    <div class="spinner sm spinner-primary mt-3"></div>
                  </div>
            </b-col>

          </b-row>
        </b-form>
      </b-modal>
    </validation-observer>

    <!-- Modal Payroll Details -->
    <b-modal ok-only size="md" id="Payroll_Details" :title="$t('Payroll') + ' ' + $t('Details')">
      <b-row>
        <b-col lg="12" md="12" sm="12">
          <table class="table table-striped table-md">
            <tbody>
              <tr>
                <td>{{$t('Reference')}}</td>
                <th>{{detail.Ref}}</th>
              </tr>
              <tr>
                <td>{{$t('date')}}</td>
                <th>{{detail.date}}</th>
              </tr>
              <tr>
                <td>{{$t('Employee')}}</td>
                <th>{{detail.employee_name}}</th>
              </tr>
              <tr>
                <td>{{$t('Account')}}</td>
                <th>{{detail.account_name}}</th>
              </tr>
              <tr>
                <td>{{$t('Amount')}}</td>
                <th>{{detail.amount}}</th>
              </tr>
              <tr>
                <td>{{$t('ModePaiement')}}</td>
                <th>{{detail.payment_method}}</th>
              </tr>
              <tr>
                <td>{{$t('PaymentStatus')}}</td>
                <th>
                  <span
                    v-if="detail.payment_status == 'paid'"
                    class="badge badge-outline-success"
                  >{{$t('Paid')}}</span>
                  <span v-else>{{detail.payment_status}}</span>
                </th>
              </tr>
              <tr>
                <td>{{$t('Receiver_Account_Number')}}</td>
                <th>{{detail.receiver_account_number || '---'}}</th>
              </tr>
              <tr>
                <td>{{$t('Payment_Reference_Number')}}</td>
                <th>{{detail.payment_reference_number || '---'}}</th>
              </tr>
              <tr>
                <td>{{$t('Attached_Documents')}}</td>
                <th>
                  <span v-if="documents.length <= 0">---</span>
                  <div v-else>
                    <div v-for="document in documents" :key="document.id" class="mb-1">
                      <a class="cursor-pointer text-info" @click="Download_Document(document)">
                        <lucide-icon class="mr-1" name="file" />
                        {{document.name}}
                      </a>
                    </div>
                  </div>
                </th>
              </tr>
            </tbody>
          </table>
        </b-col>
      </b-row>
    </b-modal>

    <!-- Modal View Documents -->
    <b-modal hide-footer size="lg" id="Payroll_Documents" :title="$t('Attached_Documents')">
      <div class="table-responsive">
        <table class="table table-hover table-bordered table-sm">
          <thead>
            <tr>
              <th scope="col">{{$t('File_Name')}}</th>
              <th scope="col">{{$t('Size')}}</th>
              <th scope="col">{{$t('Uploaded_Date')}}</th>
              <th scope="col">{{$t('Action')}}</th>
            </tr>
          </thead>
          <tbody>
            <tr v-if="documents.length <= 0">
              <td colspan="4" class="text-center">{{$t('NodataAvailable')}}</td>
            </tr>
            <tr v-for="document in documents" :key="document.id">
              <td>
                <a class="cursor-pointer" @click="Download_Document(document)">
                  <lucide-icon class="mr-1" name="file" />
                  {{document.name}}
                </a>
              </td>
              <td>{{formatFileSize(document.size)}}</td>
              <td>{{formatDateTime(document.created_at)}}</td>
              <td>
                <div role="group" aria-label="Document actions" class="btn-group">
                  <button
                    type="button"
                    title="Download"
                    class="btn btn-icon btn-success btn-sm"
                    @click="Download_Document(document)"
                  >
                    <lucide-icon name="download" />
                  </button>
                  <button
                    type="button"
                    title="Delete"
                    class="btn btn-icon btn-danger btn-sm"
                    @click="Remove_Document(document.id)"
                  >
                    <lucide-icon name="x" />
                  </button>
                </div>
              </td>
            </tr>
          </tbody>
        </table>
      </div>
    </b-modal>
  </div>
</template>

<script>
import NProgress from "nprogress";
import Datepicker from 'vuejs-datepicker';

export default {
  metaInfo: {
    title: "Payroll"
  },
   components: {
    Datepicker
  },
  data() {
    return {
      isLoading: true,
      SubmitProcessing:false,
      serverParams: {
        columnFilters: {},
        sort: {
          field: "id",
          type: "desc"
        },
        page: 1,
        perPage: 10
      },
      totalRows: "",
      search: "",
      limit: "10",
      editmode: false,
      employees:[],
      accounrs:[],
      payrolls: {},
      payment_methods: [],
      documents: [],
      selectedFiles: [],
      pendingFiles: [],
      uploadProcessing: false,
      currentPayrollId: null,
      detail: {},
      payroll: {
        date: new Date().toISOString().slice(0, 10),
        employee_id:"",
        account_id:"",
        amount:"",
        payment_method_id:"",
        receiver_account_number:"",
        payment_reference_number:"",
      },
    };
  },

  computed: {
    columns() {
      return [
        {
          label: this.$t("date"),
          field: "date",
          tdClass: "text-left",
          thClass: "text-left",
          sortable: false
        },
        {
          label: this.$t("Reference"),
          field: "Ref",
          tdClass: "text-left",
          thClass: "text-left",
          sortable: false
        },
        {
          label: this.$t("Employee"),
          field: "employee_name",
          tdClass: "text-left",
          thClass: "text-left",
          sortable: false
        },
        {
          label: this.$t("Account"),
          field: "account_name",
          tdClass: "text-left",
          thClass: "text-left",
          sortable: false
        },
        {
          label: this.$t("Amount"),
          field: "amount",
          type: "decimal",
          tdClass: "text-left",
          thClass: "text-left",
          sortable: false
        },
        {
          label: this.$t("ModePaiement"),
          field: "payment_method",
          tdClass: "text-left",
          thClass: "text-left",
          sortable: false
        },
        {
          label: this.$t("PaymentStatus"),
          field: "payment_status",
          tdClass: "text-left",
          thClass: "text-left",
          sortable: false
        },
        {
          label: this.$t("Documents"),
          field: "documents",
          tdClass: "text-left",
          thClass: "text-left",
          sortable: false
        },
        {
          label: this.$t("Action"),
          field: "actions",
          tdClass: "text-left",
          thClass: "text-left",
          sortable: false
        }
      ];
    }
  },

  methods: {

    
    //---------- keyup Received Amount

    Verified_paidAmount() {
      if (isNaN(this.payroll.amount)) {
        this.payroll.amount = 0;
      } 
    },

    //---- update Params Table
    updateParams(newProps) {
      this.serverParams = Object.assign({}, this.serverParams, newProps);
    },

    //---- Event Page Change
    onPageChange({ currentPage }) {
      if (this.serverParams.page !== currentPage) {
        this.updateParams({ page: currentPage });
        this.Get_Payrolls(currentPage);
      }
    },

    //---- Event Per Page Change
    onPerPageChange({ currentPerPage }) {
      if (this.limit !== currentPerPage) {
        this.limit = currentPerPage;
        this.updateParams({ page: 1, perPage: currentPerPage });
        this.Get_Payrolls(1);
      }
    },

    //---- Event Select Rows
    selectionChanged({ selectedRows }) {
      this.selectedIds = [];
      selectedRows.forEach((row, index) => {
        this.selectedIds.push(row.id);
      });
    },

    

    //---- Event Search
    onSearch(value) {
      this.search = value.searchTerm;
      this.Get_Payrolls(this.serverParams.page);
    },

    //---- Validation State Form
    getValidationState({ dirty, validated, valid = null }) {
      return dirty || validated ? valid : null;
    },

    formatDate(d){
        var m1 = d.getMonth()+1;
        var m2 = m1 < 10 ? '0' + m1 : m1;
        var d1 = d.getDate();
        var d2 = d1 < 10 ? '0' + d1 : d1;
        return [d.getFullYear(), m2, d2].join('-');
    },
  

    //------------- Submit Validation
    Submit_Payroll() {
      this.$refs.Create_Payroll.validate().then(success => {
        if (!success) {
          this.makeToast(
            "danger",
            this.$t("Please_fill_the_form_correctly"),
            this.$t("Failed")
          );
        } else {
          if (!this.editmode) {
            this.Create_Payroll();
          } else {
            this.Update_Payroll();
          }
        }
      });
    },

    //------ Toast
    makeToast(variant, msg, title) {
      this.$root.$bvToast.toast(msg, {
        title: title,
        variant: variant,
        solid: true
      });
    },

   //------------------------------ Show Modal (Create Payroll) -------------------------------\\
    Function_New_Payroll() {
        this.reset_Form();
        this.editmode = false;
        this.$bvModal.show("Modal_New_Payroll");
    },

    //------------------------------ Show Modal (Update Payroll) -------------------------------\\
    Function_Edit_Payroll(payroll) {
        this.editmode = true;
        this.reset_Form();
        this.payroll = payroll;
        this.currentPayrollId = payroll.id;
        this.Get_Documents(payroll.id);
        this.$bvModal.show("Modal_New_Payroll");
    },

    //------------------------------ Show Modal (Payroll Details) -------------------------------\\
    Payroll_Details(payroll) {
        this.detail = payroll;
        this.currentPayrollId = payroll.id;
        this.documents = [];
        NProgress.start();
        NProgress.set(0.1);
        this.Get_Documents(payroll.id).then(() => {
          setTimeout(() => {
            NProgress.done();
            this.$bvModal.show("Payroll_Details");
          }, 500);
        });
    },

    //------------------------------ Show Modal (View Documents) -------------------------------\\
    View_Documents(payroll) {
        this.currentPayrollId = payroll.id;
        this.documents = [];
        NProgress.start();
        NProgress.set(0.1);
        this.Get_Documents(payroll.id).then(() => {
          setTimeout(() => {
            NProgress.done();
            this.$bvModal.show("Payroll_Documents");
          }, 500);
        });
    },

   
    Selected_Account(value) {
        if (value === null) {
            this.payroll.account_id = "";
        }
    },

    Selected_Employee(value) {
        if (value === null) {
            this.payroll.employee_id = "";
        }
    },

    //----------------------------------------- Get Documents -------------------------------\\
    Get_Documents(payrollId) {
      return axios
        .get("payroll/" + payrollId + "/documents")
        .then(response => {
          this.documents = response.data.documents || [];
        })
        .catch(error => {
          this.makeToast("danger", this.$t("Failed_to_load_documents"), this.$t("Failed"));
        });
    },

    //----------------------------------------- On File Change -------------------------------\\
    onFileChange(event) {
      this.selectedFiles = event.target.files || [];
    },

    //----------------------------------------- Upload Documents -------------------------------\\
    Upload_Documents() {
      if (!this.selectedFiles || this.selectedFiles.length === 0) {
        this.makeToast("warning", this.$t("Please_select_files"), this.$t("Warning"));
        return;
      }

      // Create mode : queue files locally, they are uploaded after the payroll is saved
      if (!this.editmode || !this.payroll.id) {
        for (let i = 0; i < this.selectedFiles.length; i++) {
          this.pendingFiles.push(this.selectedFiles[i]);
        }
        this.selectedFiles = [];
        return;
      }

      // Edit mode : upload immediately
      this.uploadProcessing = true;
      NProgress.start();
      NProgress.set(0.1);

      this.Send_Documents(this.payroll.id, this.selectedFiles)
        .then(response => {
          this.uploadProcessing = false;
          this.selectedFiles = [];
          this.Get_Documents(this.payroll.id);
          this.Get_Payrolls(this.serverParams.page);
          this.makeToast("success", this.$t("Documents_uploaded_successfully"), this.$t("Success"));
          setTimeout(() => NProgress.done(), 500);
        })
        .catch(error => {
          this.uploadProcessing = false;
          setTimeout(() => NProgress.done(), 500);
          this.makeToast("danger", this.$t("Failed_to_upload_documents"), this.$t("Failed"));
        });
    },

    //----------------------------------------- Send Documents -------------------------------\\
    Send_Documents(payrollId, files) {
      const formData = new FormData();
      for (let i = 0; i < files.length; i++) {
        formData.append('documents[]', files[i]);
      }

      return axios.post("payroll/" + payrollId + "/documents", formData, {
        headers: {
          'Content-Type': 'multipart/form-data'
        }
      });
    },

    //----------------------------------------- Remove Pending File -------------------------------\\
    Remove_Pending_File(index) {
      this.pendingFiles.splice(index, 1);
    },

    //----------------------------------------- Download Document -------------------------------\\
    Download_Document(doc) {
      NProgress.start();
      NProgress.set(0.1);

      axios
        .get("payroll/documents/" + doc.id + "/download", {
          responseType: "blob"
        })
        .then(response => {
          const url = window.URL.createObjectURL(new Blob([response.data]));
          const link = window.document.createElement("a");
          link.href = url;
          link.setAttribute("download", doc.name);
          window.document.body.appendChild(link);
          link.click();
          window.document.body.removeChild(link);
          setTimeout(() => NProgress.done(), 500);
        })
        .catch(error => {
          setTimeout(() => NProgress.done(), 500);
          this.makeToast("danger", this.$t("Failed_to_download_document"), this.$t("Failed"));
        });
    },

    //----------------------------------------- Remove Document -------------------------------\\
    Remove_Document(documentId) {
      this.$swal({
        title: this.$t("Delete_Title"),
        text: this.$t("Delete_Text"),
        type: "warning",
        showCancelButton: true,
        confirmButtonColor: "#3085d6",
        cancelButtonColor: "#d33",
        cancelButtonText: this.$t("Delete_cancelButtonText"),
        confirmButtonText: this.$t("Delete_confirmButtonText")
      }).then(result => {
        if (result.value) {
          NProgress.start();
          NProgress.set(0.1);
          axios
            .delete("payroll/documents/" + documentId)
            .then(() => {
              this.$swal(
                this.$t("Delete_Deleted"),
                this.$t("Deleted_in_successfully"),
                "success"
              );
              this.Get_Documents(this.currentPayrollId);
              this.Get_Payrolls(this.serverParams.page);
              setTimeout(() => NProgress.done(), 500);
            })
            .catch(() => {
              setTimeout(() => NProgress.done(), 500);
              this.$swal(
                this.$t("Delete_Failed"),
                this.$t("Delete_Therewassomethingwronge"),
                "warning"
              );
            });
        }
      });
    },

    //----------------------------------------- Format File Size -------------------------------\\
    formatFileSize(bytes) {
      if (bytes === 0) return '0 Bytes';
      const k = 1024;
      const sizes = ['Bytes', 'KB', 'MB', 'GB'];
      const i = Math.floor(Math.log(bytes) / Math.log(k));
      return Math.round(bytes / Math.pow(k, i) * 100) / 100 + ' ' + sizes[i];
    },

    //----------------------------------------- Format Date Time -------------------------------\\
    formatDateTime(value) {
      if (!value) return '';
      const date = new Date(value);
      const year = date.getFullYear();
      const month = String(date.getMonth() + 1).padStart(2, '0');
      const day = String(date.getDate()).padStart(2, '0');
      const hours = String(date.getHours()).padStart(2, '0');
      const minutes = String(date.getMinutes()).padStart(2, '0');
      return `${year}-${month}-${day} ${hours}:${minutes}`;
    },



    //--------------------------Get ALL payrolls ---------------------------\\

    Get_Payrolls(page) {
      // Start the progress bar.
      NProgress.start();
      NProgress.set(0.1);
      axios
        .get(
          "payroll?page=" +
            page +
            "&SortField=" +
            this.serverParams.sort.field +
            "&SortType=" +
            this.serverParams.sort.type +
            "&search=" +
            this.search +
            "&limit=" +
            this.limit
        )
        .then(response => {
          this.totalRows = response.data.totalRows;
          this.payrolls = response.data.payrolls;
          this.accounts = response.data.accounts;
          this.employees = response.data.employees;
          this.payment_methods = response.data.payment_methods;

          // Complete the animation of theprogress bar.
          NProgress.done();
          this.isLoading = false;
        })
        .catch(response => {
          // Complete the animation of theprogress bar.
          NProgress.done();
          setTimeout(() => {
            this.isLoading = false;
          }, 500);
        });
    },

    //------------------------------- Create payroll ------------------------\\
    Create_Payroll() {

        var self = this;
        self.SubmitProcessing = true;
        axios.post("/payroll", {
          date: self.payroll.date,
          employee_id: self.payroll.employee_id,
          account_id: self.payroll.account_id,
          amount: self.payroll.amount,
          payment_method_id: self.payroll.payment_method_id,
          receiver_account_number: self.payroll.receiver_account_number,
          payment_reference_number: self.payroll.payment_reference_number,
        }).then(response => {
            if (self.pendingFiles.length > 0 && response.data.id) {
              self.Send_Documents(response.data.id, self.pendingFiles)
                .catch(() => {
                  self.makeToast("danger", self.$t("Failed_to_upload_documents"), self.$t("Failed"));
                })
                .then(() => {
                  self.pendingFiles = [];
                  self.Finish_Create();
                });
            } else {
              self.Finish_Create();
            }
        })
        .catch(error => {
          this.SubmitProcessing = false;
          this.makeToast("danger", this.$t("InvalidData"), this.$t("Failed"));
        });
    },

    //------------------------------- Finish Create payroll ------------------------\\
    Finish_Create() {
      this.SubmitProcessing = false;
      Fire.$emit("Event_Payroll");
      this.makeToast(
        "success",
        this.$t("Created_in_successfully"),
        this.$t("Success")
      );
    },

    //------------------------------- Update payroll ------------------------\\
    Update_Payroll() {

      var self = this;
      self.SubmitProcessing = true;
      axios.put("/payroll/" + self.payroll.id, {
          date: self.payroll.date,
          employee_id: self.payroll.employee_id,
          account_id: self.payroll.account_id,
          amount: self.payroll.amount,
          payment_method_id: self.payroll.payment_method_id,
          receiver_account_number: self.payroll.receiver_account_number,
          payment_reference_number: self.payroll.payment_reference_number,
      }).then(response => {
            this.SubmitProcessing = false;
            Fire.$emit("Event_Payroll");

            this.makeToast(
              "success",
              this.$t("Updated_in_successfully"),
              this.$t("Success")
            );
        })
        .catch(error => {
          this.SubmitProcessing = false;
          this.makeToast("danger", this.$t("InvalidData"), this.$t("Failed"));
        });
    },

    //------------------------------- reset Form ------------------------\\
    reset_Form() {
     this.payroll = {
        id: "",
        date: new Date().toISOString().slice(0, 10),
        employee_id:"",
        account_id:"",
        amount:"",
        payment_method_id:"",
        receiver_account_number:"",
        payment_reference_number:"",
    };
     this.documents = [];
     this.selectedFiles = [];
     this.pendingFiles = [];
     this.uploadProcessing = false;
    },

    //------------------------------- Delete payroll ------------------------\\
    Remove_Payroll(id) {
      this.$swal({
        title: this.$t("Delete_Title"),
        text: this.$t("Delete_Text"),
        type: "warning",
        showCancelButton: true,
        confirmButtonColor: "#3085d6",
        cancelButtonColor: "#d33",
        cancelButtonText: this.$t("Delete_cancelButtonText"),
        confirmButtonText: this.$t("Delete_confirmButtonText")
      }).then(result => {
        if (result.value) {
          axios
            .delete("payroll/" + id)
            .then(() => {
              this.$swal(
                this.$t("Delete_Deleted"),
                this.$t("Deleted_in_successfully"),
                "success"
              );

              Fire.$emit("Event_delete_Payroll");
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
    },

  
  },

  //----------------------------- Created function-------------------\\

  created: function() {
    this.Get_Payrolls(1);

    Fire.$on("Event_Payroll", () => {
      setTimeout(() => {
        this.Get_Payrolls(this.serverParams.page);
        this.$bvModal.hide("Modal_New_Payroll");
      }, 500);
    });

    Fire.$on("Event_delete_Payroll", () => {
      setTimeout(() => {
        this.Get_Payrolls(this.serverParams.page);
      }, 500);
    });
  }
};
</script>