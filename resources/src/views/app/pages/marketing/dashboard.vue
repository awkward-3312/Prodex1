<template>
  <div class="main-content">
    <breadcumb :page="$t('Marketing_Dashboard')" :folder="$t('Marketing_Management')" />

    <div v-if="isLoading" class="loading_page spinner spinner-primary mr-3"></div>

    <div v-if="!isLoading">
      <!-- Stat cards -->
      <b-row>
        <b-col md="3" sm="6" class="mb-4" v-for="card in statCards" :key="card.label">
          <b-card class="mkt-stat" :class="'mkt-stat--' + card.color">
            <div class="mkt-stat__icon">
              <lucide-icon :name="card.icon" size="26" />
            </div>
            <div class="mkt-stat__value">{{ card.value }}</div>
            <div class="mkt-stat__label">{{ $t(card.label) }}</div>
          </b-card>
        </b-col>
      </b-row>

      <b-row>
        <!-- Monthly volume bars -->
        <b-col md="8" class="mb-4">
          <b-card :title="$t('Marketing_Statistics')">
            <div class="mkt-chart">
              <div class="mkt-chart__col" v-for="m in stats.monthly" :key="m.label">
                <div class="mkt-chart__bars">
                  <div class="mkt-chart__bar bg-primary" :style="barStyle(m.sms)" :title="'SMS: ' + m.sms"></div>
                  <div class="mkt-chart__bar bg-success" :style="barStyle(m.email)" :title="'Email: ' + m.email"></div>
                  <div class="mkt-chart__bar bg-info" :style="barStyle(m.whatsapp)" :title="'WhatsApp: ' + m.whatsapp"></div>
                </div>
                <div class="mkt-chart__label">{{ m.label }}</div>
              </div>
            </div>
            <div class="mkt-legend">
              <span><i class="dot bg-primary"></i> {{ $t('SMS') }}</span>
              <span><i class="dot bg-success"></i> {{ $t('Email') }}</span>
              <span><i class="dot bg-info"></i> {{ $t('WhatsApp') }}</span>
            </div>
          </b-card>
        </b-col>

        <!-- Campaigns by status -->
        <b-col md="4" class="mb-4">
          <b-card :title="$t('Campaigns_By_Status')">
            <div v-if="statusList.length === 0" class="text-muted text-center p-3">{{ $t('No_Campaigns') }}</div>
            <ul class="list-unstyled mkt-statuslist">
              <li v-for="s in statusList" :key="s.key">
                <span class="badge" :class="status_class(s.key)">{{ format_label(s.key) }}</span>
                <span class="float-right font-weight-bold">{{ s.value }}</span>
              </li>
            </ul>
          </b-card>
        </b-col>
      </b-row>

      <!-- Recent campaigns -->
      <b-card :title="$t('Recent_Campaign_Activity')">
        <div class="table-responsive">
          <table class="table table-hover">
            <thead>
              <tr>
                <th>{{ $t('Campaign_Title') }}</th>
                <th>{{ $t('Type') }}</th>
                <th>{{ $t('Status') }}</th>
                <th>{{ $t('Total_Recipients') }}</th>
                <th>{{ $t('Sent') }}</th>
                <th>{{ $t('Failed') }}</th>
              </tr>
            </thead>
            <tbody>
              <tr v-if="stats.recent && stats.recent.length === 0">
                <td colspan="6" class="text-center text-muted">{{ $t('No_Campaigns') }}</td>
              </tr>
              <tr v-for="c in stats.recent" :key="c.id" class="cursor-pointer" @click="View_Details(c.id)">
                <td class="text-primary font-weight-bold">{{ c.title }}</td>
                <td><span class="badge badge-outline-info">{{ format_label(c.type) }}</span></td>
                <td><span class="badge" :class="status_class(c.status)">{{ format_label(c.status) }}</span></td>
                <td>{{ c.total_recipients }}</td>
                <td>{{ c.sent_count }}</td>
                <td>{{ c.failed_count }}</td>
              </tr>
            </tbody>
          </table>
        </div>
      </b-card>
    </div>
  </div>
</template>

<script>
export default {
  metaInfo: { title: "Marketing Dashboard" },
  data() {
    return {
      isLoading: true,
      stats: {
        total_campaigns: 0, total_messages: 0, total_emails: 0, total_whatsapp: 0,
        total_sms: 0, failed_messages: 0, by_status: {}, by_type: {}, monthly: [], recent: []
      }
    };
  },
  computed: {
    statCards() {
      return [
        { label: "Total_Campaigns", value: this.stats.total_campaigns, icon: "megaphone", color: "primary" },
        { label: "Total_Messages_Sent", value: this.stats.total_messages, icon: "send", color: "success" },
        { label: "Total_Emails_Sent", value: this.stats.total_emails, icon: "mail", color: "info" },
        { label: "Total_WhatsApp_Sent", value: this.stats.total_whatsapp, icon: "message-circle", color: "teal" },
        { label: "Total_SMS_Sent", value: this.stats.total_sms, icon: "message-square", color: "warning" },
        { label: "Failed_Messages", value: this.stats.failed_messages, icon: "alert-triangle", color: "danger" }
      ];
    },
    statusList() {
      const o = this.stats.by_status || {};
      return Object.keys(o).map(k => ({ key: k, value: o[k] }));
    },
    maxMonthly() {
      let max = 1;
      (this.stats.monthly || []).forEach(m => {
        max = Math.max(max, m.sms, m.email, m.whatsapp);
      });
      return max;
    }
  },
  methods: {
    format_label(v) {
      return v ? String(v).replace(/_/g, " ") : "-";
    },
    status_class(s) {
      const map = {
        draft: "badge-outline-secondary", scheduled: "badge-outline-primary",
        sending: "badge-outline-warning", sent: "badge-outline-success",
        failed: "badge-outline-danger", cancelled: "badge-outline-dark"
      };
      return map[s] || "badge-outline-secondary";
    },
    barStyle(v) {
      const h = Math.round((v / this.maxMonthly) * 100);
      return { height: Math.max(2, h) + "%" };
    },
    View_Details(id) {
      this.$router.push({ name: "marketing_campaign_details", params: { id: id } });
    },
    Get_Dashboard() {
      axios.get("marketing/dashboard")
        .then(({ data }) => {
          this.stats = data;
          this.isLoading = false;
        })
        .catch(() => {
          setTimeout(() => { this.isLoading = false; }, 500);
        });
    }
  },
  created() {
    this.Get_Dashboard();
  }
};
</script>

<style scoped>
.mkt-stat { text-align: center; border-radius: 10px; }
.mkt-stat__icon { display: inline-flex; align-items: center; justify-content: center; width: 52px; height: 52px; border-radius: 50%; margin-bottom: 8px; color: #fff; }
.mkt-stat__value { font-size: 1.6rem; font-weight: 700; }
.mkt-stat__label { color: #8a94a6; font-size: 0.85rem; }
.mkt-stat--primary .mkt-stat__icon { background: #663cdc; }
.mkt-stat--success .mkt-stat__icon { background: #21b573; }
.mkt-stat--info .mkt-stat__icon { background: #2196f3; }
.mkt-stat--teal .mkt-stat__icon { background: #1eb7a8; }
.mkt-stat--warning .mkt-stat__icon { background: #f7b731; }
.mkt-stat--danger .mkt-stat__icon { background: #f3556d; }
.mkt-chart { display: flex; align-items: flex-end; height: 220px; gap: 14px; padding: 10px 0; }
.mkt-chart__col { flex: 1; display: flex; flex-direction: column; align-items: center; height: 100%; }
.mkt-chart__bars { flex: 1; display: flex; align-items: flex-end; gap: 3px; width: 100%; justify-content: center; }
.mkt-chart__bar { width: 14px; border-radius: 3px 3px 0 0; transition: height .3s; }
.mkt-chart__label { font-size: 0.75rem; color: #8a94a6; margin-top: 6px; }
.mkt-legend { display: flex; gap: 18px; justify-content: center; margin-top: 10px; font-size: 0.8rem; color: #8a94a6; }
.mkt-legend .dot { display: inline-block; width: 10px; height: 10px; border-radius: 50%; margin-right: 4px; }
.mkt-statuslist li { padding: 7px 0; border-bottom: 1px solid #f0f0f0; }
</style>
