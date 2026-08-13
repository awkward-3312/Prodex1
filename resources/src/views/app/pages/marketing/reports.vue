<template>
  <div class="main-content">
    <breadcumb :page="$t('Marketing_Reports')" :folder="$t('Marketing_Management')" />

    <div v-if="isLoading" class="loading_page spinner spinner-primary mr-3"></div>
    <div v-if="!isLoading">
      <!-- Filter / Export toolbar -->
      <b-card class="mb-4 mkt-toolbar">
        <b-row class="align-items-end">
          <b-col md="3" sm="6">
            <b-form-group :label="$t('Date_From')" class="mb-2 mb-md-0">
              <b-form-input type="date" v-model="date_from"></b-form-input>
            </b-form-group>
          </b-col>
          <b-col md="3" sm="6">
            <b-form-group :label="$t('Date_To')" class="mb-2 mb-md-0">
              <b-form-input type="date" v-model="date_to"></b-form-input>
            </b-form-group>
          </b-col>
          <b-col md="6" class="text-md-right mt-2 mt-md-0">
            <b-button variant="primary" class="ripple m-1" @click="Get_Reports()">
              <lucide-icon name="search" size="16" /> {{ $t('Filter') || 'Filter' }}
            </b-button>
            <vue-excel-xlsx
              class="btn btn-outline-danger ripple m-1"
              :data="campaigns"
              :columns="excelColumns"
              :file-name="'marketing_report'"
              :file-type="'xlsx'"
              :sheet-name="'campaigns'"
            >
              <lucide-icon name="file-spreadsheet" size="16" /> EXCEL
            </vue-excel-xlsx>
            <b-button @click="Export_PDF()" variant="outline-success" class="ripple m-1">
              <lucide-icon name="copy" size="16" /> PDF
            </b-button>
          </b-col>
        </b-row>
      </b-card>

      <!-- Summary stat cards -->
      <b-row>
        <b-col lg="2" md="4" sm="6" class="mb-4" v-for="card in summaryCards" :key="card.label">
          <b-card class="mkt-stat" :class="'mkt-stat--' + card.color">
            <div class="mkt-stat__icon">
              <lucide-icon :name="card.icon" size="24" />
            </div>
            <div class="mkt-stat__value">{{ card.value }}</div>
            <div class="mkt-stat__label">{{ $t(card.label) }}</div>
          </b-card>
        </b-col>
      </b-row>

      <b-row>
        <!-- Delivery overview gauge -->
        <b-col md="4" class="mb-4">
          <b-card :title="$t('Delivery_Rate') || 'Delivery Rate'" class="h-100">
            <div class="mkt-gauge">
              <svg viewBox="0 0 120 120" class="mkt-gauge__svg">
                <circle class="mkt-gauge__track" cx="60" cy="60" r="52" />
                <circle
                  class="mkt-gauge__fill"
                  cx="60" cy="60" r="52"
                  :stroke-dasharray="gaugeCircumference"
                  :stroke-dashoffset="gaugeOffset"
                />
              </svg>
              <div class="mkt-gauge__center">
                <div class="mkt-gauge__pct">{{ deliveryRate }}%</div>
                <div class="mkt-gauge__sub">{{ $t('Delivered') }}</div>
              </div>
            </div>
            <ul class="list-unstyled mkt-mini mb-0">
              <li><span><i class="dot bg-success"></i> {{ $t('Sent') }}</span><b>{{ totals.sent }}</b></li>
              <li><span><i class="dot bg-info"></i> {{ $t('Delivered') }}</span><b>{{ totals.delivered }}</b></li>
              <li><span><i class="dot bg-danger"></i> {{ $t('Failed') }}</span><b>{{ totals.failed }}</b></li>
            </ul>
          </b-card>
        </b-col>

        <!-- Campaigns by type -->
        <b-col md="8" class="mb-4">
          <b-card :title="$t('Campaigns_By_Type')" class="h-100">
            <div v-if="!report.by_type || report.by_type.length === 0" class="text-muted text-center p-4">
              {{ $t('No_Campaigns') }}
            </div>
            <div v-else class="mkt-typelist">
              <div class="mkt-type" v-for="r in report.by_type" :key="r.type">
                <div class="mkt-type__head">
                  <span class="mkt-type__icon" :class="'bg-' + type_color(r.type)">
                    <lucide-icon :name="type_icon(r.type)" size="16" />
                  </span>
                  <span class="mkt-type__name">{{ type_label(r.type) }}</span>
                  <span class="mkt-type__count">{{ r.campaigns }} {{ $t('Campaigns') }}</span>
                </div>
                <div class="mkt-type__bar">
                  <div
                    class="mkt-type__bar-sent"
                    :class="'bg-' + type_color(r.type)"
                    :style="{ width: pct(r.sent, r.recipients) + '%' }"
                    :title="$t('Sent') + ': ' + r.sent"
                  ></div>
                  <div
                    class="mkt-type__bar-failed bg-danger"
                    :style="{ width: pct(r.failed, r.recipients) + '%' }"
                    :title="$t('Failed') + ': ' + r.failed"
                  ></div>
                </div>
                <div class="mkt-type__meta">
                  <span>{{ $t('Total_Recipients') }}: <b>{{ r.recipients }}</b></span>
                  <span class="text-success">{{ $t('Sent') }}: <b>{{ r.sent }}</b></span>
                  <span class="text-danger">{{ $t('Failed') }}: <b>{{ r.failed }}</b></span>
                </div>
              </div>
            </div>
          </b-card>
        </b-col>
      </b-row>

      <!-- Campaign performance -->
      <b-card :title="$t('Campaign_Performance')">
        <div class="table-responsive">
          <table class="table table-hover mkt-table">
            <thead>
              <tr>
                <th>{{ $t('Campaign_Title') }}</th>
                <th>{{ $t('Type') }}</th>
                <th>{{ $t('Status') }}</th>
                <th class="text-right">{{ $t('Total_Recipients') }}</th>
                <th class="text-right">{{ $t('Sent') }}</th>
                <th class="text-right">{{ $t('Failed') }}</th>
                <th style="min-width:150px">{{ $t('Success_Rate') || 'Success Rate' }}</th>
              </tr>
            </thead>
            <tbody>
              <tr v-if="campaigns.length === 0">
                <td colspan="7" class="text-center text-muted p-4">{{ $t('No_Campaigns') }}</td>
              </tr>
              <tr v-for="c in campaigns" :key="c.id">
                <td class="font-weight-bold">{{ c.title }}</td>
                <td>
                  <span class="mkt-type__icon mkt-type__icon--sm" :class="'bg-' + type_color(c.type)">
                    <lucide-icon :name="type_icon(c.type)" size="13" />
                  </span>
                  {{ type_label(c.type) }}
                </td>
                <td><span class="badge" :class="status_class(c.status)">{{ format_label(c.status) }}</span></td>
                <td class="text-right">{{ c.total_recipients }}</td>
                <td class="text-right text-success">{{ c.sent_count }}</td>
                <td class="text-right text-danger">{{ c.failed_count }}</td>
                <td>
                  <div class="mkt-rate">
                    <div class="mkt-rate__bar">
                      <div class="mkt-rate__fill" :class="rate_class(rate(c))" :style="{ width: rate(c) + '%' }"></div>
                    </div>
                    <span class="mkt-rate__val">{{ rate(c) }}%</span>
                  </div>
                </td>
              </tr>
            </tbody>
          </table>
        </div>
      </b-card>
    </div>
  </div>
</template>

<script>
import jsPDF from "jspdf";
import autoTable from "jspdf-autotable";

export default {
  metaInfo: { title: "Marketing Reports" },
  data() {
    return {
      isLoading: true,
      date_from: "",
      date_to: "",
      report: { summary: {}, by_type: [] },
      campaigns: []
    };
  },
  computed: {
    summaryCards() {
      const s = this.report.summary || {};
      return [
        { label: "Total_Campaigns", value: s.total_campaigns || 0, icon: "megaphone", color: "primary" },
        { label: "Total_Recipients", value: s.recipients || 0, icon: "users", color: "info" },
        { label: "Sent", value: s.sent || 0, icon: "send", color: "success" },
        { label: "Delivered", value: s.delivered || 0, icon: "check-circle", color: "teal" },
        { label: "Failed", value: s.failed || 0, icon: "alert-triangle", color: "danger" },
        { label: "Pending", value: s.pending || 0, icon: "clock", color: "warning" }
      ];
    },
    totals() {
      const s = this.report.summary || {};
      return {
        sent: s.sent || 0,
        delivered: s.delivered || 0,
        failed: s.failed || 0,
        recipients: s.recipients || 0
      };
    },
    deliveryRate() {
      const t = this.totals;
      const base = t.sent || t.recipients;
      if (!base) return 0;
      const delivered = t.delivered || t.sent;
      return Math.min(100, Math.round((delivered / base) * 100));
    },
    gaugeCircumference() {
      return (2 * Math.PI * 52).toFixed(1);
    },
    gaugeOffset() {
      const c = 2 * Math.PI * 52;
      return (c - (this.deliveryRate / 100) * c).toFixed(1);
    },
    excelColumns() {
      return [
        { label: "Title", field: "title" },
        { label: "Type", field: "type" },
        { label: "Status", field: "status" },
        { label: "Recipients", field: "total_recipients" },
        { label: "Sent", field: "sent_count" },
        { label: "Failed", field: "failed_count" }
      ];
    }
  },
  methods: {
    format_label(v) { return v ? String(v).replace(/_/g, " ") : "-"; },
    type_label(t) { const map = { sms: "SMS", email: "Email", whatsapp: "WhatsApp" }; return map[t] || t; },
    type_icon(t) { const map = { sms: "message-square", email: "mail", whatsapp: "message-circle" }; return map[t] || "send"; },
    type_color(t) { const map = { sms: "warning", email: "info", whatsapp: "teal" }; return map[t] || "primary"; },
    pct(part, total) {
      if (!total) return 0;
      return Math.min(100, Math.round((part / total) * 100));
    },
    rate(c) {
      const total = c.total_recipients || 0;
      if (!total) return 0;
      return Math.min(100, Math.round((c.sent_count / total) * 100));
    },
    rate_class(v) {
      if (v >= 80) return "bg-success";
      if (v >= 50) return "bg-warning";
      return "bg-danger";
    },
    status_class(s) {
      const map = {
        draft: "badge-outline-secondary", scheduled: "badge-outline-primary",
        sending: "badge-outline-warning", sent: "badge-outline-success",
        failed: "badge-outline-danger", cancelled: "badge-outline-dark"
      };
      return map[s] || "badge-outline-secondary";
    },
    Get_Reports() {
      axios.get("marketing/reports?date_from=" + this.date_from + "&date_to=" + this.date_to)
        .then(({ data }) => {
          this.report = data;
          this.campaigns = data.campaigns || [];
          this.isLoading = false;
        })
        .catch(() => { setTimeout(() => { this.isLoading = false; }, 500); });
    },
    Export_PDF() {
      const pdf = new jsPDF("p", "pt");
      const headers = [this.$t("Campaign_Title"), this.$t("Type"), this.$t("Status"), this.$t("Total_Recipients"), this.$t("Sent"), this.$t("Failed")];
      const body = (this.campaigns || []).map(c => [c.title, this.type_label(c.type), c.status, c.total_recipients, c.sent_count, c.failed_count]);
      autoTable(pdf, {
        head: [headers], body, startY: 60, theme: "striped", margin: { left: 40, right: 40 },
        styles: { fontSize: 9 },
        didDrawPage: () => { pdf.setFontSize(14); pdf.text("Marketing Report", 40, 40); }
      });
      pdf.save("marketing_report.pdf");
    }
  },
  created() {
    this.Get_Reports();
  }
};
</script>

<style scoped>
.mkt-toolbar { border-radius: 10px; }

/* Stat cards */
.mkt-stat { text-align: center; border-radius: 10px; }
.mkt-stat__icon { display: inline-flex; align-items: center; justify-content: center; width: 50px; height: 50px; border-radius: 50%; margin-bottom: 8px; color: #fff; }
.mkt-stat__value { font-size: 1.5rem; font-weight: 700; line-height: 1.2; }
.mkt-stat__label { color: #8a94a6; font-size: 0.8rem; }
.mkt-stat--primary .mkt-stat__icon { background: #663cdc; }
.mkt-stat--success .mkt-stat__icon { background: #21b573; }
.mkt-stat--info .mkt-stat__icon { background: #2196f3; }
.mkt-stat--teal .mkt-stat__icon { background: #1eb7a8; }
.mkt-stat--warning .mkt-stat__icon { background: #f7b731; }
.mkt-stat--danger .mkt-stat__icon { background: #f3556d; }

/* Delivery gauge */
.mkt-gauge { position: relative; width: 150px; height: 150px; margin: 6px auto 16px; }
.mkt-gauge__svg { width: 100%; height: 100%; transform: rotate(-90deg); }
.mkt-gauge__track { fill: none; stroke: #eef0f4; stroke-width: 12; }
.mkt-gauge__fill { fill: none; stroke: #21b573; stroke-width: 12; stroke-linecap: round; transition: stroke-dashoffset .6s ease; }
.mkt-gauge__center { position: absolute; top: 0; left: 0; right: 0; bottom: 0; display: flex; flex-direction: column; align-items: center; justify-content: center; }
.mkt-gauge__pct { font-size: 1.8rem; font-weight: 700; color: #2c2c2c; }
.mkt-gauge__sub { font-size: 0.75rem; color: #8a94a6; }
.mkt-mini li { display: flex; justify-content: space-between; padding: 6px 0; border-bottom: 1px solid #f0f0f0; font-size: 0.85rem; }
.mkt-mini li:last-child { border-bottom: 0; }
.dot { display: inline-block; width: 10px; height: 10px; border-radius: 50%; margin-right: 6px; }

/* Campaigns by type */
.mkt-typelist { display: flex; flex-direction: column; gap: 18px; }
.mkt-type__head { display: flex; align-items: center; margin-bottom: 7px; }
.mkt-type__icon { display: inline-flex; align-items: center; justify-content: center; width: 28px; height: 28px; border-radius: 7px; color: #fff; margin-right: 9px; }
.mkt-type__icon--sm { width: 22px; height: 22px; border-radius: 5px; margin-right: 5px; }
.mkt-type__name { font-weight: 600; }
.mkt-type__count { margin-left: auto; font-size: 0.8rem; color: #8a94a6; }
.mkt-type__bar { display: flex; height: 10px; border-radius: 6px; overflow: hidden; background: #eef0f4; }
.mkt-type__bar-sent, .mkt-type__bar-failed { transition: width .5s ease; }
.mkt-type__meta { display: flex; gap: 18px; margin-top: 6px; font-size: 0.78rem; color: #8a94a6; flex-wrap: wrap; }

/* Performance table */
.mkt-table th { font-size: 0.78rem; text-transform: uppercase; letter-spacing: .3px; color: #8a94a6; }
.mkt-table td { vertical-align: middle; }
.mkt-rate { display: flex; align-items: center; gap: 8px; }
.mkt-rate__bar { flex: 1; height: 7px; background: #eef0f4; border-radius: 5px; overflow: hidden; }
.mkt-rate__fill { height: 100%; border-radius: 5px; transition: width .5s ease; }
.mkt-rate__val { font-size: 0.78rem; font-weight: 600; color: #6c757d; min-width: 34px; text-align: right; }

.bg-teal { background: #1eb7a8 !important; }
</style>
