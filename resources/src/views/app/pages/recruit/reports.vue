<template>
  <div class="main-content">
    <breadcumb :page="$t('Reports')" :folder="$t('Recruit')" />

    <b-row class="mb-3">
      <b-col md="4">
        <b-form-group :label="$t('Period')">
          <b-form-select v-model="period" @change="Get_Reports()">
            <b-form-select-option value="1month">{{ $t('Last_Month') }}</b-form-select-option>
            <b-form-select-option value="3months">{{ $t('Last_3_Months') }}</b-form-select-option>
            <b-form-select-option value="6months">{{ $t('Last_6_Months') }}</b-form-select-option>
            <b-form-select-option value="1year">{{ $t('Last_Year') }}</b-form-select-option>
          </b-form-select>
        </b-form-group>
      </b-col>
    </b-row>

    <div v-if="isLoading" class="loading_page spinner spinner-primary mr-3"></div>

    <div v-if="!isLoading">
      <b-row>
        <b-col md="6" class="mb-4">
          <b-card :header="$t('Hiring_Funnel')" class="h-100">
            <div v-for="s in stages" :key="s" class="mb-3">
              <div class="d-flex justify-content-between mb-1">
                <span>{{ format_label(s) }}</span>
                <span class="text-muted">{{ funnel[s] || 0 }}</span>
              </div>
              <b-progress :max="funnelMax" height="8px">
                <b-progress-bar :value="funnel[s] || 0" variant="primary"></b-progress-bar>
              </b-progress>
            </div>
          </b-card>
        </b-col>

        <b-col md="6" class="mb-4">
          <b-card :header="$t('Average_Time_To_Hire')" class="h-100 text-center">
            <div class="py-4">
              <p class="display-4 text-primary mb-0">{{ avg_time_to_hire }}</p>
              <p class="text-muted">{{ $t('Days') }}</p>
            </div>
          </b-card>
        </b-col>
      </b-row>

      <b-row>
        <b-col md="6" class="mb-4">
          <b-card :header="$t('Applications_By_Job')" class="h-100">
            <div class="table-responsive">
              <table class="table">
                <thead><tr><th>{{ $t('Job') }}</th><th class="text-right">{{ $t('Applications') }}</th></tr></thead>
                <tbody>
                  <tr v-if="by_job.length === 0"><td colspan="2" class="text-center text-muted">{{ $t('No_data') }}</td></tr>
                  <tr v-for="r in by_job" :key="r.job_id">
                    <td>{{ r.job ? r.job.title : '-' }}</td>
                    <td class="text-right">{{ r.count }}</td>
                  </tr>
                </tbody>
              </table>
            </div>
          </b-card>
        </b-col>

        <b-col md="6" class="mb-4">
          <b-card :header="$t('Candidates_By_Source')" class="h-100">
            <div v-for="(count, src) in by_source" :key="src" class="mb-3">
              <div class="d-flex justify-content-between mb-1">
                <span>{{ format_label(src) }}</span>
                <span class="text-muted">{{ count }}</span>
              </div>
              <b-progress :max="sourceMax" height="8px">
                <b-progress-bar :value="count" variant="info"></b-progress-bar>
              </b-progress>
            </div>
            <p v-if="Object.keys(by_source).length === 0" class="text-center text-muted">{{ $t('No_data') }}</p>
          </b-card>
        </b-col>
      </b-row>

      <b-row>
        <b-col md="12" class="mb-4">
          <b-card :header="$t('Monthly_Trend')">
            <div class="table-responsive">
              <table class="table">
                <thead>
                  <tr>
                    <th>{{ $t('Month') }}</th>
                    <th class="text-right">{{ $t('Total') }}</th>
                    <th class="text-right">{{ $t('Hired') }}</th>
                    <th class="text-right">{{ $t('Rejected') }}</th>
                  </tr>
                </thead>
                <tbody>
                  <tr v-if="monthly_trend.length === 0"><td colspan="4" class="text-center text-muted">{{ $t('No_data') }}</td></tr>
                  <tr v-for="m in monthly_trend" :key="m.month">
                    <td>{{ m.month }}</td>
                    <td class="text-right">{{ m.total }}</td>
                    <td class="text-right">{{ m.hired }}</td>
                    <td class="text-right">{{ m.rejected }}</td>
                  </tr>
                </tbody>
              </table>
            </div>
          </b-card>
        </b-col>
      </b-row>
    </div>
  </div>
</template>

<script>
export default {
  metaInfo: { title: "Recruit Reports" },
  data() {
    return {
      isLoading: true,
      period: "6months",
      funnel: {},
      by_job: [],
      by_source: {},
      monthly_trend: [],
      avg_time_to_hire: 0,
      by_category: [],
      stages: ["applied", "screening", "shortlisted", "interview", "offered", "hired", "rejected"]
    };
  },
  computed: {
    funnelMax() {
      const vals = Object.values(this.funnel || {});
      const max = vals.length ? Math.max(...vals) : 0;
      return max > 0 ? max : 1;
    },
    sourceMax() {
      const vals = Object.values(this.by_source || {});
      const max = vals.length ? Math.max(...vals) : 0;
      return max > 0 ? max : 1;
    }
  },
  methods: {
    format_label(v) {
      return v ? String(v).replace(/_/g, " ") : "-";
    },
    Get_Reports() {
      this.isLoading = true;
      axios
        .get("recruit/reports?period=" + this.period)
        .then(({ data }) => {
          this.funnel = data.funnel || {};
          this.by_job = data.by_job || [];
          this.by_source = data.by_source || {};
          this.monthly_trend = data.monthly_trend || [];
          this.avg_time_to_hire = data.avg_time_to_hire || 0;
          this.by_category = data.by_category || [];
          this.isLoading = false;
        })
        .catch(() => {
          this.isLoading = false;
        });
    }
  },
  created() {
    this.Get_Reports();
  }
};
</script>
