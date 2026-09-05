<template>
  <div class="px-next pxqtl">
    <px-page-header :title="$t('ListQuotations')" :breadcrumbs="[{ label: $t('Sales') }, { label: $t('Quotations') }]">
      <template #actions>
        <px-menu :items="exportMenu" align="end" @select="onExport">
          <template #trigger>
            <px-button variant="secondary" size="sm" icon="file-spreadsheet" trailing-icon="chevron-down">Exportar</px-button>
          </template>
        </px-menu>
        <px-button
          v-if="currentUserPermissions && currentUserPermissions.includes('Quotations_add')"
          variant="primary" icon="plus" @click="$router.push('/app/quotations/store')"
        >{{ $t('Add') }}</px-button>
      </template>
    </px-page-header>

    <px-toolbar
      :search="search"
      :search-placeholder="$t('Search_this_table')"
      :filter-count="activeFilterCount"
      @update:search="onSearchInput"
      @open-filters="filtersOpen = !filtersOpen"
    />

    <div v-if="filtersOpen" class="pxqtl__filters">
      <div class="pxqtl__filters-grid">
        <px-field :label="$t('date')"><template #default="{ id }"><px-input :id="id" type="date" v-model="Filter_date" /></template></px-field>
        <px-field :label="$t('Reference')"><template #default="{ id }"><px-input :id="id" v-model="Filter_Ref" :placeholder="$t('Reference')" /></template></px-field>
        <px-field :label="$t('Customer')">
          <template #default="{ id }">
            <vs-px :input-id="id" v-model="Filter_client" :reduce="o => o.value" :placeholder="$t('Choose_Customer')"
              :options="customers.map(c => ({ label: c.name, value: c.id }))" />
          </template>
        </px-field>
        <px-field :label="$t('warehouse')">
          <template #default="{ id }">
            <vs-px :input-id="id" v-model="Filter_warehouse" :reduce="o => o.value" :placeholder="$t('Choose_Warehouse')"
              :options="warehouses.map(w => ({ label: w.name, value: w.id }))" />
          </template>
        </px-field>
        <px-field :label="$t('Status')">
          <template #default="{ id }">
            <vs-px :input-id="id" v-model="Filter_status" :reduce="o => o.value" :placeholder="$t('Choose_Status')"
              :options="[{ label: $t('Sent'), value: 'sent' }, { label: $t('Pending'), value: 'pending' }]" />
          </template>
        </px-field>
      </div>
      <div class="pxqtl__filters-act">
        <px-button size="sm" variant="primary" icon="filter" @click="applyFilters">{{ $t('Filter') }}</px-button>
        <px-button size="sm" variant="ghost" icon="x" @click="Reset_Filter">{{ $t('Reset') }}</px-button>
      </div>
    </div>

    <div v-if="isLoading" class="pxqtl__pad">
      <px-skeleton variant="table" :rows="10" :columns="5" />
    </div>

    <template v-else>
      <transition name="pxqtl-bulk">
        <div v-if="selectedIds.length" class="pxqtl__bulk">
          <span><b class="pxn-num">{{ selectedIds.length }}</b> {{ $t('selected') }}</span>
          <div class="pxqtl__bulk-act">
            <px-button v-if="currentUserPermissions.includes('Quotations_delete')" size="sm" variant="danger" icon="trash-2" @click="delete_by_selected">{{ $t('Del') }}</px-button>
            <px-button size="sm" variant="ghost" @click="selectedIds = []">{{ $t('Cancel') }}</px-button>
          </div>
        </div>
      </transition>

      <div class="pxqtl__tablewrap">
        <px-table
          v-if="quotations.length"
          :columns="columns"
          :rows="quotations"
          row-key="id"
          selectable
          :selected.sync="selectedIds"
          :sort-key="serverParams.sort.field"
          :sort-dir="serverParams.sort.type"
          has-row-actions
          @sort="onSort"
        >
          <template #cell-date="{ row }">{{ formatDisplayDate(row.date) }}</template>
          <template #cell-Ref="{ row }">
            <router-link class="pxqtl__link" :to="'/app/quotations/detail/' + row.id">{{ row.Ref }}</router-link>
          </template>
          <template #cell-statut="{ row }">
            <px-badge :tone="row.statut === 'sent' ? 'success' : 'info'">{{ row.statut === 'sent' ? $t('Sent') : $t('Pending') }}</px-badge>
          </template>
          <template #cell-GrandTotal="{ row }"><span class="pxn-num">{{ formatPriceWithSymbol(currentUser.currency, row.GrandTotal, 2) }}</span></template>
          <template #row-actions="{ row }">
            <px-kebab :items="rowActions(row)" @select="onRowAction(row, $event)" />
          </template>
        </px-table>

        <px-empty-state
          v-else
          icon="file-text"
          :title="$t('No_quotations_yet')"
          :description="$t('No_quotations_desc')"
        >
          <px-button
            v-if="currentUserPermissions && currentUserPermissions.includes('Quotations_add')"
            size="sm" variant="primary" icon="plus" @click="$router.push('/app/quotations/store')"
          >{{ $t('Add') }}</px-button>
        </px-empty-state>
      </div>

      <px-pagination
        v-if="quotations.length"
        :page="serverParams.page"
        :per-page="Number(limit)"
        :total="Number(totalRows) || 0"
        @update:page="onPage"
        @update:perPage="onLimit"
      />
    </template>
  </div>
</template>


<script>
import { mapActions, mapGetters } from "vuex";
import NProgress from "nprogress";
import jsPDF from "jspdf";
import autoTable from "jspdf-autotable";
import Util from '../../../../utils';
import {
  formatPriceDisplay as formatPriceDisplayHelper,
  getPriceFormatSetting,
  getPriceDecimals
} from "../../../../utils/priceFormat";
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
import PxEmptyState from "@/components/px-next/PxEmptyState.vue";
import VsPx from "@/views/app/products/next/edit/VsPx.vue";

export default {
  components: {
    PxPageHeader, PxToolbar, PxTable, PxPagination, PxButton, PxMenu, PxKebab, PxBadge,
    PxField, PxInput, PxEmptyState, "vs-px": VsPx
  },
  metaInfo: {
    title: "Cotizaciones"
  },
  data() {
    return {
      isLoading: true,
      serverParams: {
        sort: {
          field: "id",
          type: "desc"
        },
        page: 1,
        perPage: 10
      },
      selectedIds: [],
      totalRows: "",
      search: "",
      _searchTimer: null,
      filtersOpen: false,
      Filter_date: "",
      Filter_client: "",
      Filter_status: "",
      Filter_Ref: "",
      Filter_warehouse: "",
      customers: [],
      warehouses: [],
      details: [],
      quotations: [],
      quote: {},
      limit: "10",
      email: {
        to: "",
        subject: "",
        message: "",
        client_name: "",
        quote_Ref: ""
      },
      // Optional price format key for frontend display (loaded from system settings/localStorage)
      price_format_key: null
    };
  },

  computed: {
    ...mapGetters(["currentUserPermissions", "currentUser"]),
    // Monetary precision (2 or 3) driven by the "Enable 3 Decimal Pricing" setting.
    priceDecimals() {
      return getPriceDecimals({ store: this.$store });
    },
    columns() {
      return [
        { key: "date", label: this.$t("date"), sortable: true },
        { key: "Ref", label: this.$t("Reference"), sortable: true, strong: true },
        { key: "client_name", label: this.$t("Customer"), sortable: true },
        { key: "warehouse_name", label: this.$t("warehouse"), sortable: true },
        { key: "statut", label: this.$t("Status"), sortable: true },
        { key: "GrandTotal", label: this.$t("Total"), sortable: true, align: "right" }
      ];
    },
    activeFilterCount() {
      return [this.Filter_date, this.Filter_Ref, this.Filter_client, this.Filter_warehouse, this.Filter_status]
        .filter(v => v !== "" && v != null).length;
    },
    exportMenu() {
      return [
        { key: "pdf", label: "PDF de la lista", icon: "file-text" },
        { key: "xlsx", label: "Excel (CSV)", icon: "file-spreadsheet" }
      ];
    }
  },

  methods: {
    rowActions(row) {
      const p = this.currentUserPermissions || [];
      const items = [{ key: "view", label: this.$t("DetailQuote"), icon: "eye" }];
      if (p.includes("Quotations_edit")) {
        items.push({ key: "edit", label: this.$t("EditQuote"), icon: "pencil" });
        items.push({ key: "convert", label: this.$t("Convert_to_Invoice"), icon: "plus" });
      }
      items.push({ key: "pdf", label: this.$t("DownloadPdf"), icon: "file-text" });
      items.push({ key: "whatsapp", label: "WhatsApp", icon: "message-circle" });
      items.push({ key: "email", label: this.$t("email_notification"), icon: "mail" });
      items.push({ key: "sms", label: this.$t("sms_notification"), icon: "message-square" });
      if (p.includes("Quotations_delete")) items.push({ key: "delete", label: this.$t("DeleteQuote"), icon: "x", tone: "danger" });
      return items;
    },
    onRowAction(row, item) {
      const k = item && item.key;
      if (k === "view") this.$router.push("/app/quotations/detail/" + row.id);
      else if (k === "edit") this.$router.push("/app/quotations/edit/" + row.id);
      else if (k === "convert") this.$router.push("/app/quotations/Create_sale/" + row.id);
      else if (k === "pdf") this.Quote_pdf(row, row.id);
      else if (k === "whatsapp") this.Send_WhatsApp(row.id);
      else if (k === "email") this.SendEmail(row.id);
      else if (k === "sms") this.Quote_SMS(row.id);
      else if (k === "delete") this.Remove_Quotation(row.id);
    },
    onExport(item) {
      const k = item && item.key;
      if (k === "pdf") this.Quotation_PDF();
      else if (k === "xlsx") this.exportCsv();
    },
    exportCsv() {
      const head = [this.$t("date"), this.$t("Reference"), this.$t("Customer"), this.$t("warehouse"), this.$t("Status"), this.$t("Total")];
      const lines = [head.join(",")].concat(
        (this.quotations || []).map(r =>
          [r.date, r.Ref, r.client_name, r.warehouse_name, r.statut, r.GrandTotal]
            .map(c => `"${String(c == null ? "" : c).replace(/"/g, '""')}"`).join(",")
        )
      );
      const blob = new Blob(["﻿" + lines.join("\n")], { type: "text/csv;charset=utf-8;" });
      const link = document.createElement("a");
      link.href = URL.createObjectURL(blob);
      link.setAttribute("download", "Quotations.csv");
      document.body.appendChild(link);
      link.click();
      document.body.removeChild(link);
    },

    updateParams(newProps) {
      this.serverParams = Object.assign({}, this.serverParams, newProps);
    },

    onSearchInput(v) {
      this.search = v;
      if (this._searchTimer) clearTimeout(this._searchTimer);
      this._searchTimer = setTimeout(() => { this.updateParams({ page: 1 }); this.Get_Quotations(1); }, 350);
    },
    onPage(p) {
      if (this.serverParams.page !== p) {
        this.updateParams({ page: p });
        this.Get_Quotations(p);
      }
    },
    onLimit(v) {
      if (this.limit !== String(v)) {
        this.limit = String(v);
        this.updateParams({ page: 1, perPage: Number(v) });
        this.Get_Quotations(1);
      }
    },
    onSort({ key, dir }) {
      let field = key;
      if (key === "client_name") field = "client_id";
      else if (key === "warehouse_name") field = "warehouse_id";
      this.updateParams({ sort: { type: dir, field: field } });
      this.Get_Quotations(this.serverParams.page);
    },
    applyFilters() {
      this.updateParams({ page: 1 });
      this.Get_Quotations(this.serverParams.page);
    },

    //------ Toast
    makeToast(variant, msg, title) {
      this.$root.$bvToast.toast(msg, {
        title: title,
        variant: variant,
        solid: true
      });
    },

    //------ Reset Filter
    Reset_Filter() {
      this.search = "";
      this.Filter_date = "";
      this.Filter_client = "";
      this.Filter_status = "";
      this.Filter_Ref = "";
      this.Filter_warehouse = "";
      this.Get_Quotations(this.serverParams.page);
    },

    //------------------------------------- Quotations PDF -------------------------\\
    Quotation_PDF() {
      const pdf = new jsPDF("p", "pt");
      const fontPath = "/fonts/Vazirmatn-Bold.ttf";
      try {
        pdf.addFont(fontPath, "Vazirmatn", "normal");
        pdf.addFont(fontPath, "Vazirmatn", "bold");
      } catch(e) { /* ignore if already added */ }
      pdf.setFont("Vazirmatn", "normal");

      const headers = [
        this.$t("date"),
        this.$t("Reference"),
        this.$t("Customer"),
        this.$t("warehouse"),
        this.$t("Status"),
        this.$t("Total")
      ];

      const body = (this.quotations || []).map(r => ([
        r.date,
        r.Ref,
        r.client_name,
        r.warehouse_name,
        r.statut,
        r.GrandTotal
      ]));

      const totalGrandTotal = (this.quotations || []).reduce((s, q) => s + parseFloat(q.GrandTotal || 0), 0);
      const footer = [[ this.$t('Total'), '', '', '', '', totalGrandTotal.toFixed(this.priceDecimals) ]];

      const marginX = 40;
      const rtl =
        (this.$i18n && ['ar','fa','ur','he'].includes(this.$i18n.locale)) ||
        (typeof document !== 'undefined' && document.documentElement.dir === 'rtl');

      autoTable(pdf, {
        head: [headers],
        body,
        foot: footer,
        startY: 110,
        theme: 'striped',
        margin: { left: marginX, right: marginX },
        styles: { font: 'Vazirmatn', fontSize: 9, cellPadding: 4, halign: rtl ? 'right' : 'left', textColor: 33 },
        headStyles: { font: 'Vazirmatn', fontStyle: 'bold', fillColor: [63,81,181], textColor: 255 },
        alternateRowStyles: { fillColor: [245,247,250] },
        footStyles: { font: 'Vazirmatn', fontStyle: 'bold', fillColor: [63,81,181], textColor: 255 },
        columnStyles: {
          0: { halign: rtl ? 'right' : 'left' },  // Date
          1: { halign: rtl ? 'right' : 'left' },  // Reference
          2: { halign: rtl ? 'right' : 'left' },  // Customer
          3: { halign: rtl ? 'right' : 'left' },  // Warehouse
          4: { halign: rtl ? 'right' : 'left' },  // Status
          5: { halign: 'left' }                   // Total (always right-aligned for numbers)
        },
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
          const title = this.$t('ListQuotations') || 'Quotation List';
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

      pdf.save("Quotation_List.pdf");
    },

    Send_WhatsApp(id) {
      NProgress.start();
      NProgress.set(0.1);
      axios
        .post("quotation_send_whatsapp", {
          id: id,
        })
        .then(response => {
          setTimeout(() => NProgress.done(), 500);

          var phone = response.data.phone;
          var message = response.data.message;

          var encodedPhone = encodeURIComponent(phone);
          var encodedMessage = encodeURIComponent(message);

          var whatsappUrl = `https://web.whatsapp.com/send/?phone=${encodedPhone}&text=${encodedMessage}`;

          window.open(whatsappUrl, '_blank');

        })
        .catch(error => {
          setTimeout(() => NProgress.done(), 500);
          this.makeToast("danger", "Failed to send the Message", this.$t("Failed"));
        });
    },


     //----------------------------------- Quotation PDF by id -------------------------\\
    Quote_pdf(quote, id) {
      NProgress.start();
      NProgress.set(0.1);
      axios
        .get("quote_pdf/" + id, {
          responseType: "blob", // important
          headers: {
            "Content-Type": "application/json"
          }
        })
        .then(response => {
          const url = window.URL.createObjectURL(new Blob([response.data]));
          const link = document.createElement("a");
          link.href = url;
          link.setAttribute("download", "Quotation_" + quote.Ref + ".pdf");
          document.body.appendChild(link);
          link.click();
          setTimeout(() => NProgress.done(), 500);
        })
        .catch(() => {
          setTimeout(() => NProgress.done(), 500);
        });
    },


    SendEmail(id) {
      NProgress.start();
      NProgress.set(0.1);
      axios
        .post("quotations_send_email", {
          id: id,
        })
        .then(response => {
          setTimeout(() => NProgress.done(), 500);
          this.makeToast(
            "success",
            this.$t("SendEmail"),
            this.$t("Success")
          );
        })
        .catch(error => {
          setTimeout(() => NProgress.done(), 500);
          this.makeToast("danger", this.$t("SMTPIncorrect"), this.$t("Failed"));
        });
    },

    //---------SMS notification

     Quote_SMS(id) {
      NProgress.start();
      NProgress.set(0.1);
      axios
        .post("quotations_send_sms", {
          id: id,
        })
        .then(response => {
          setTimeout(() => NProgress.done(), 500);
          this.makeToast(
            "success",
            this.$t("Send_SMS"),
            this.$t("Success")
          );
        })
        .catch(error => {
          setTimeout(() => NProgress.done(), 500);
          this.makeToast("danger", this.$t("sms_config_invalid"), this.$t("Failed"));
        });
    },

    //---------------------------------------- Set To Strings-------------------------\\
    setToStrings() {
      // Simply replaces null values with strings=''s
      if (this.Filter_client === null) {
        this.Filter_client = "";
      } else if (this.Filter_warehouse === null) {
        this.Filter_warehouse = "";
      } else if (this.Filter_status === null) {
        this.Filter_status = "";
      }
    },

    //---------------------------------------- Get All Quotations -------------------------\\
    Get_Quotations(page) {
      NProgress.start();
      NProgress.set(0.1);
      this.setToStrings();
      axios
        .get(
          "quotations?page=" +
            this.serverParams.page +
            "&Ref=" +
            this.Filter_Ref +
            "&client_id=" +
            this.Filter_client +
            "&statut=" +
            this.Filter_status +
            "&warehouse_id=" +
            this.Filter_warehouse +
            "&date=" +
            this.Filter_date +
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
          this.quotations = response.data.quotations;
          this.customers = response.data.customers;
          this.warehouses = response.data.warehouses;
          this.totalRows = response.data.totalRows;

          NProgress.done();
          this.isLoading = false;
        })
        .catch(response => {
          NProgress.done();
          setTimeout(() => {
            this.isLoading = false;
          }, 500);
        });
    },



    //-------------------------------------------- Delete Quotation -------------------------\\
    Remove_Quotation(id) {
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
          NProgress.start();
          NProgress.set(0.1);
          axios
            .delete("quotations/" + id)
            .then(() => {
              this.$swal(
                this.$t("Delete_Deleted"),
                this.$t("Deleted_in_successfully"),
                "success"
              );
              Fire.$emit("Delete_Quote");
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

    //---- Delete quotations by selection

    delete_by_selected() {
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
          NProgress.start();
          NProgress.set(0.1);
          axios
            .post("quotations_delete_by_selection", {
              selectedIds: this.selectedIds
            })
            .then(() => {
              this.$swal(
                this.$t("Delete_Deleted"),
                this.$t("Deleted_in_successfully"),
                "success"
              );

              Fire.$emit("Delete_Quote");
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
    //----------------------------------------- Format Display Date (for tables) -------------------------------\\
    formatDisplayDate(value) {
      if (!value) return '';
      // Get date format from Vuex store (loaded from database) or fallback
      const dateFormat = this.$store.getters.getDateFormat || Util.getDateFormat(this.$store);
      return Util.formatDisplayDate(value, dateFormat);
    },

    //------------------------------Formetted Numbers -------------------------\\
    formatNumber(number, dec) {
      const value = (typeof number === "string"
        ? number
        : number.toString()
      ).split(".");
      if (dec <= 0) return value[0];
      let formated = value[1] || "";
      if (formated.length > dec)
        return `${value[0]}.${formated.substr(0, dec)}`;
      while (formated.length < dec) formated += "0";
      return `${value[0]}.${formated}`;
    },

    // Price formatting for display only (does NOT affect calculations or stored values)
    formatPriceDisplay(number, dec) {
      try {
        const decimals = this.priceDecimals;
        const key = this.price_format_key || getPriceFormatSetting({ store: this.$store });
        if (key) {
          this.price_format_key = key;
        }
        const effectiveKey = key || null;
        return formatPriceDisplayHelper(number, decimals, effectiveKey);
      } catch (e) {
        return this.formatNumber(number, dec);
      }
    },

    formatPriceWithSymbol(symbol, number, dec) {
      const safeSymbol = symbol || "";
      const value = this.formatPriceDisplay(number, dec);
      return safeSymbol ? `${safeSymbol} ${value}` : value;
    }
  },

  //-----------------------------Autoload function-------------------
  created: function() {
    this.Get_Quotations(1);

    Fire.$on("Delete_Quote", () => {
      setTimeout(() => {
        this.Get_Quotations(this.serverParams.page);
        setTimeout(() => NProgress.done(), 500);
      }, 500);
    });
  }
};
</script>

<style lang="scss" src="@/assets/styles/sass/px-next/production.scss"></style>

<style lang="scss" scoped>
.pxqtl { min-height: 100%; background: var(--pxn-bg); padding: var(--pxn-space-8) var(--pxn-space-9) var(--pxn-space-9); }
@media (max-width: 620px) { .pxqtl { padding: var(--pxn-space-6) var(--pxn-space-5); } }
.pxqtl__pad { padding: var(--pxn-space-6) 0; }

.pxqtl__filters { margin-top: var(--pxn-space-4); padding: var(--pxn-space-5); border: 1px solid var(--pxn-border); border-radius: var(--pxn-radius-lg); background: var(--pxn-surface); }
.pxqtl__filters-grid { display: grid; grid-template-columns: repeat(3, minmax(0, 1fr)); gap: var(--pxn-space-5); }
@media (max-width: 900px) { .pxqtl__filters-grid { grid-template-columns: repeat(2, minmax(0, 1fr)); } }
@media (max-width: 560px) { .pxqtl__filters-grid { grid-template-columns: minmax(0, 1fr); } }
.pxqtl__filters-act { display: flex; gap: var(--pxn-space-3); margin-top: var(--pxn-space-4); }

.pxqtl__bulk { display: flex; align-items: center; justify-content: space-between; gap: var(--pxn-space-5); margin-top: var(--pxn-space-4); padding: var(--pxn-space-4) var(--pxn-space-5); background: var(--pxn-primary-soft); border: 1px solid var(--pxn-primary-border); border-radius: var(--pxn-radius-md); font-size: var(--pxn-fs-sm); color: var(--pxn-primary-ink); }
.pxqtl__bulk-act { display: flex; gap: var(--pxn-space-3); }
.pxqtl-bulk-enter-active, .pxqtl-bulk-leave-active { transition: opacity var(--pxn-dur-2) var(--pxn-ease), transform var(--pxn-dur-2) var(--pxn-ease); }
.pxqtl-bulk-enter, .pxqtl-bulk-leave-to { opacity: 0; transform: translateY(-6px); }

.pxqtl__tablewrap { margin-top: var(--pxn-space-5); }
.pxqtl__link { color: var(--pxn-primary); text-decoration: none; }
.pxqtl__link:hover { text-decoration: underline; }
</style>
