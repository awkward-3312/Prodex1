<template>
  <div class="px-next pxship">
    <px-page-header :title="$t('Shipments')" :breadcrumbs="[{ label: $t('Sales') }, { label: $t('Shipments') }]">
      <template #actions>
        <px-menu :items="exportMenu" align="end" @select="onExport">
          <template #trigger>
            <px-button variant="secondary" size="sm" icon="file-spreadsheet" trailing-icon="chevron-down">Exportar</px-button>
          </template>
        </px-menu>
      </template>
    </px-page-header>

    <px-toolbar
      :search="search"
      :search-placeholder="$t('Search_this_table')"
      @update:search="onSearchInput"
    />

    <div v-if="isLoading" class="pxship__pad">
      <px-skeleton variant="table" :rows="8" :columns="6" />
    </div>

    <template v-else>
      <div class="pxship__tablewrap">
        <px-table
          v-if="shipments.length"
          :columns="columns"
          :rows="shipments"
          row-key="id"
          :sort-key="serverParams.sort.field"
          :sort-dir="serverParams.sort.type"
          has-row-actions
          @sort="onSort"
        >
          <template #cell-status="{ row }">
            <px-badge :tone="statusTone(row.status)">{{ statusLabel(row.status) }}</px-badge>
          </template>
          <template #row-actions="{ row }">
            <px-kebab :items="rowActions" @select="onRowAction(row, $event)" />
          </template>
        </px-table>

        <px-empty-state
          v-else
          icon="truck"
          :title="$t('No_shipments_yet')"
          :description="$t('No_shipments_desc')"
        />
      </div>

      <px-pagination
        v-if="shipments.length"
        :page="serverParams.page"
        :per-page="Number(limit)"
        :total="Number(totalRows) || 0"
        @update:page="onPage"
        @update:perPage="onLimit"
      />
    </template>

    <!-- Edit shipment -->
    <validation-observer ref="shipment_ref">
      <px-modal v-model="modalOpen" :title="$t('Edit')" size="md">
        <b-form @submit.prevent="Submit_Shipment">
          <validation-provider ref="statusProvider" name="Status" :rules="{ required: true }" v-slot="v">
            <px-field :label="$t('Status')" required :error="v.errors[0]">
              <template #default="{ id }">
                <vs-px
                  :input-id="id"
                  :invalid="!!v.errors.length"
                  v-model="shipment.status"
                  :reduce="label => label.value"
                  :placeholder="$t('Choose_Status')"
                  :options="statusOptions"
                  @input="v.validate"
                />
              </template>
            </px-field>
          </validation-provider>

          <px-field :label="$t('delivered_to')" class="pxship__gap">
            <template #default="{ id }">
              <px-input :id="id" v-model="shipment.delivered_to" :placeholder="$t('delivered_to')" />
            </template>
          </px-field>

          <px-field :label="$t('Adress')" class="pxship__gap">
            <template #default="{ id }">
              <px-textarea :id="id" v-model="shipment.shipping_address" :rows="4" :placeholder="$t('Enter_Address')" />
            </template>
          </px-field>

          <px-field :label="$t('Please_provide_any_details')" class="pxship__gap">
            <template #default="{ id }">
              <px-textarea :id="id" v-model="shipment.shipping_details" :rows="4" :placeholder="$t('Please_provide_any_details')" />
            </template>
          </px-field>

          <div class="pxship__actionbar">
            <px-button variant="secondary" type="button" @click="modalOpen = false">{{ $t('Cancel') }}</px-button>
            <px-button variant="primary" type="submit" icon="check" :loading="SubmitProcessing">{{ $t('submit') }}</px-button>
          </div>
        </b-form>
      </px-modal>
    </validation-observer>
  </div>
</template>

<script>
import { mapActions, mapGetters } from "vuex";
import NProgress from "nprogress";
import jsPDF from "jspdf";
import autoTable from "jspdf-autotable";
import PxPageHeader from "@/components/px-next/PxPageHeader.vue";
import PxToolbar from "@/components/px-next/PxToolbar.vue";
import PxTable from "@/components/px-next/PxTable.vue";
import PxPagination from "@/components/px-next/PxPagination.vue";
import PxButton from "@/components/px-next/PxButton.vue";
import PxMenu from "@/components/px-next/PxMenu.vue";
import PxKebab from "@/components/px-next/PxKebab.vue";
import PxBadge from "@/components/px-next/PxBadge.vue";
import PxField from "@/components/px-next/PxField.vue";
import PxInput from "@/components/px-next/PxInput.vue";
import PxTextarea from "@/components/px-next/PxTextarea.vue";
import PxModal from "@/components/px-next/PxModal.vue";
import PxEmptyState from "@/components/px-next/PxEmptyState.vue";
import VsPx from "@/views/app/products/next/edit/VsPx.vue";

export default {
  components: {
    PxPageHeader, PxToolbar, PxTable, PxPagination, PxButton, PxMenu, PxKebab,
    PxBadge, PxField, PxInput, PxTextarea, PxModal, PxEmptyState, "vs-px": VsPx
  },
  metaInfo: {
    title: "Envíos"
  },
  data() {
    return {
      isLoading: true,
      SubmitProcessing: false,
      ImportProcessing: false,
      modalOpen: false,
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
      _searchTimer: null,
      shipments: [],
      shipment: {}
    };
  },

  computed: {
    ...mapGetters(["currentUserPermissions"]),
    columns() {
      return [
        { key: "date", label: this.$t("date"), sortable: true },
        { key: "shipment_ref", label: this.$t("shipment_ref"), sortable: true, strong: true },
        { key: "sale_ref", label: this.$t("sale_ref"), sortable: true },
        { key: "customer_name", label: this.$t("Customer"), sortable: true },
        { key: "warehouse_name", label: this.$t("warehouse"), sortable: true },
        { key: "status", label: this.$t("Status"), sortable: true }
      ];
    },
    statusOptions() {
      return [
        { label: this.$t("Ordered"), value: "ordered" },
        { label: this.$t("Packed"), value: "packed" },
        { label: this.$t("Shipped"), value: "shipped" },
        { label: this.$t("Delivered"), value: "delivered" },
        { label: this.$t("Cancelled"), value: "cancelled" }
      ];
    },
    rowActions() {
      const items = [];
      if (this.currentUserPermissions && this.currentUserPermissions.includes("shipment")) {
        items.push({ key: "edit", label: this.$t("Edit"), icon: "pencil" });
        items.push({ key: "delete", label: this.$t("Delete"), icon: "x", tone: "danger" });
      }
      return items;
    },
    exportMenu() {
      return [
        { key: "pdf", label: "PDF", icon: "file-text" },
        { key: "xlsx", label: "Excel (CSV)", icon: "file-spreadsheet" }
      ];
    }
  },

  methods: {
    statusLabel(s) {
      const map = {
        ordered: this.$t("Ordered"), packed: this.$t("Packed"), shipped: this.$t("Shipped"),
        delivered: this.$t("Delivered"), cancelled: this.$t("Cancelled")
      };
      return map[s] || this.$t("Cancelled");
    },
    statusTone(s) {
      const map = { ordered: "warning", packed: "info", shipped: "neutral", delivered: "success", cancelled: "danger" };
      return map[s] || "danger";
    },

    //------------- Submit Validation Edit shipment
    Submit_Shipment() {
      this.$refs.shipment_ref.validate().then(success => {
        if (!success) {
          this.makeToast(
            "danger",
            this.$t("Please_fill_the_form_correctly"),
            this.$t("Failed")
          );
        } else {
          this.Update_Shipment();
        }
      });
    },

    //------ update Params Table
    updateParams(newProps) {
      this.serverParams = Object.assign({}, this.serverParams, newProps);
    },

    onSearchInput(v) {
      this.search = v;
      if (this._searchTimer) clearTimeout(this._searchTimer);
      this._searchTimer = setTimeout(() => { this.updateParams({ page: 1 }); this.Get_shipments(1); }, 350);
    },
    onPage(p) {
      if (this.serverParams.page !== p) {
        this.updateParams({ page: p });
        this.Get_shipments(p);
      }
    },
    onLimit(v) {
      if (this.limit !== String(v)) {
        this.limit = String(v);
        this.updateParams({ page: 1, perPage: Number(v) });
        this.Get_shipments(1);
      }
    },
    onSort({ key, dir }) {
      this.updateParams({ sort: { type: dir, field: key } });
      this.Get_shipments(this.serverParams.page);
    },
    onRowAction(row, item) {
      const k = item && item.key;
      if (k === "edit") this.Edit_Shipment(row);
      else if (k === "delete") this.Remove_Shipment(row.id);
    },
    onExport(item) {
      const k = item && item.key;
      if (k === "pdf") this.Shipments_pdf();
      else if (k === "xlsx") this.Shipments_csv();
    },
    Shipments_csv() {
      const head = [this.$t("date"), this.$t("shipment_ref"), this.$t("sale_ref"), this.$t("Customer"), this.$t("warehouse"), this.$t("Status")];
      const lines = [head.join(",")].concat(
        (this.shipments || []).map(s =>
          [s.date, s.shipment_ref, s.sale_ref, s.customer_name, s.warehouse_name, s.status]
            .map(c => `"${String(c == null ? "" : c).replace(/"/g, '""')}"`).join(",")
        )
      );
      const blob = new Blob(["﻿" + lines.join("\n")], { type: "text/csv;charset=utf-8;" });
      const link = document.createElement("a");
      link.href = URL.createObjectURL(blob);
      link.setAttribute("download", "Shipments.csv");
      document.body.appendChild(link);
      link.click();
      document.body.removeChild(link);
    },

    //------ Event Validation State
    getValidationState({ dirty, validated, valid = null }) {
      return dirty || validated ? valid : null;
    },

    //------ Toast
    makeToast(variant, msg, title) {
      this.$root.$bvToast.toast(msg, {
        title: title,
        variant: variant,
        solid: true
      });
    },

    //--------------------------------- Shipments PDF -------------------------------\\
    Shipments_pdf() {
      var self = this;
      let pdf = new jsPDF("p", "pt");

      const fontPath = "/fonts/Vazirmatn-Bold.ttf";
      try {
        pdf.addFont(fontPath, "Vazirmatn", "normal");
        pdf.addFont(fontPath, "Vazirmatn", "bold");
      } catch(e) {}
      pdf.setFont("Vazirmatn", "normal");

      const headers = [
        self.$t("date"),
        self.$t("ShipmentRef") || "Shipment Ref",
        self.$t("Reference") || "Sale Ref",
        self.$t("Customer"),
        self.$t("warehouse"),
        self.$t("Status")
      ];

      const body = (self.shipments || []).map(shipment => ([
        shipment.date,
        shipment.shipment_ref,
        shipment.sale_ref,
        shipment.customer_name,
        shipment.warehouse_name,
        shipment.status
      ]));

      const marginX = 40;
      const rtl =
        (self.$i18n && ['ar','fa','ur','he'].includes(self.$i18n.locale)) ||
        (typeof document !== 'undefined' && document.documentElement.dir === 'rtl');

      autoTable(pdf, {
        head: [headers],
        body: body,
        startY: 110,
        theme: 'striped',
        margin: { left: marginX, right: marginX },
        styles: { font: 'Vazirmatn', fontSize: 9, cellPadding: 4, halign: rtl ? 'right' : 'left', textColor: 33 },
        headStyles: { font: 'Vazirmatn', fontStyle: 'bold', fillColor: [63,81,181], textColor: 255 },
        alternateRowStyles: { fillColor: [245,247,250] },
        didDrawPage: (d) => {
          const pageW = pdf.internal.pageSize.getWidth();
          const pageH = pdf.internal.pageSize.getHeight();

          // Header banner
          pdf.setFillColor(63,81,181);
          pdf.rect(0, 0, pageW, 60, 'F');

          // Title
          pdf.setTextColor(255);
          pdf.setFont('Vazirmatn', 'bold');
          pdf.setFontSize(16);
          const title = self.$t('Shipments') || 'Shipments';
          rtl ? pdf.text(title, pageW - marginX, 38, { align: 'right' })
              : pdf.text(title, marginX, 38);

          // Reset text color
          pdf.setTextColor(33);

          // Footer page numbers
          pdf.setFontSize(8);
          const pn = `${d.pageNumber} / ${pdf.internal.getNumberOfPages()}`;
          rtl ? pdf.text(pn, marginX, pageH - 14, { align: 'left' })
              : pdf.text(pn, pageW - marginX, pageH - 14, { align: 'right' });
        }
      });

      pdf.save("Shipments.pdf");
    },

    //--------------------------------------- Get All Shipments -------------------------------\\
    Get_shipments(page) {
      // Start the progress bar.
      NProgress.start();
      NProgress.set(0.1);
      axios
        .get(
          "shipments?page=" +
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
          this.shipments = response.data.shipments;
          this.totalRows = response.data.totalRows;

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


    //------------------------------ Show Modal (Edit shipment) -------------------------------\\
    Edit_Shipment(shipment) {
      NProgress.start();
      NProgress.set(0.1);
      this.Get_shipments(this.serverParams.page);
      this.reset_Form();
      this.shipment = { ...shipment };

      setTimeout(() => {
        NProgress.done();
        this.modalOpen = true;
        this.$nextTick(() => {
          if (this.$refs.statusProvider) this.$refs.statusProvider.syncValue(this.shipment.status);
        });
      }, 800);

    },

    //----------------------- Update_Shipment ---------------------------\\
    Update_Shipment() {
      var self = this;
      self.SubmitProcessing = true;
      axios
        .put("shipments/" + self.shipment.id, {
          sale_id: self.shipment.sale_id,
          shipping_address: self.shipment.shipping_address,
          delivered_to: self.shipment.delivered_to,
          shipping_details: self.shipment.shipping_details,
          status: self.shipment.status
        })
        .then(response => {
          this.makeToast(
            "success",
            this.$t("Updated_in_successfully"),
            this.$t("Success")
          );
          Fire.$emit("event_update_shipment");
          self.SubmitProcessing = false;
        })
        .catch(error => {
          this.makeToast("danger", this.$t("InvalidData"), this.$t("Failed"));
          self.SubmitProcessing = false;
        });
    },

    //-------------------------------- Reset Form -------------------------------\\
    reset_Form() {
      this.shipment = {
        id: "",
        date: "",
        Ref: "",
        sale_id: "",
        attachment: "",
        delivered_to: "",
        shipping_address: "",
        status: "",
        shipping_details: ""
      };
    },

    //------------------------------- Remove shipment -------------------------------\\
    Remove_Shipment(id) {
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
            .delete("shipments/" + id)
            .then(() => {
              this.$swal(
                this.$t("Delete_Deleted"),
                this.$t("Deleted_in_successfully"),
                "success"
              );
              Fire.$emit("event_delete_shipment");
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
  }, // END METHODS

  //----------------------------- Created function-------------------

  created: function() {
    this.Get_shipments(1);

    Fire.$on("event_update_shipment", () => {
      setTimeout(() => {
        this.Get_shipments(this.serverParams.page);
        this.modalOpen = false;
      }, 500);
    });

    Fire.$on("event_delete_shipment", () => {
      setTimeout(() => {
        this.Get_shipments(this.serverParams.page);
      }, 500);
    });
  }
};
</script>

<style lang="scss" src="@/assets/styles/sass/px-next/production.scss"></style>

<style lang="scss" scoped>
.pxship { min-height: 100%; background: var(--pxn-bg); padding: var(--pxn-space-8) var(--pxn-space-9) var(--pxn-space-9); }
@media (max-width: 620px) { .pxship { padding: var(--pxn-space-6) var(--pxn-space-5); } }
.pxship__pad { padding: var(--pxn-space-6) 0; }
.pxship__tablewrap { margin-top: var(--pxn-space-5); }
.pxship__gap { margin-top: var(--pxn-space-5); }
.pxship__actionbar { display: flex; justify-content: flex-end; gap: var(--pxn-space-3); margin-top: var(--pxn-space-7); }
</style>
