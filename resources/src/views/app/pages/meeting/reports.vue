<template>
  <div class="main-content meeting-reports">
    <breadcumb :page="$t('Reports')" :folder="$t('Meeting_Management')" />

    <!-- Hero header with period filter -->
    <div class="mr-hero mb-4">
      <div class="mr-hero__text">
        <span class="mr-hero__eyebrow"><lucide-icon name="bar-chart-3" size="14" /> {{ $t('Meeting_Management') }}</span>
        <h2 class="mr-hero__title">{{ $t('Reports') }}</h2>
      </div>
      <div class="mr-period">
        <button
          v-for="p in periods"
          :key="p.value"
          class="mr-period__btn"
          :class="{ 'mr-period__btn--active': period === p.value }"
          @click="setPeriod(p.value)"
        >{{ $t(p.label) }}</button>
      </div>
    </div>

    <div v-if="isLoading" class="loading_page spinner spinner-primary mr-3"></div>

    <div v-else>
      <!-- Summary cards -->
      <div class="mr-stats">
        <div v-for="c in summaryCards" :key="c.label" class="mr-stat" :class="'mr-stat--' + c.color">
          <div class="mr-stat__icon"><lucide-icon :name="c.icon" size="22" /></div>
          <div class="mr-stat__body">
            <div class="mr-stat__value">{{ c.value }}</div>
            <div class="mr-stat__label">{{ $t(c.label) }}</div>
          </div>
        </div>
      </div>

      <div class="mr-grid">
        <!-- Status donut -->
        <div class="mr-card">
          <div class="mr-card__header">
            <span class="mr-card__title"><lucide-icon name="pie-chart" size="18" /> {{ $t('Meetings_By_Status') }}</span>
          </div>
          <div class="mr-card__body">
            <div v-if="statusTotal === 0" class="mr-empty"><lucide-icon name="pie-chart" size="28" /><span>{{ $t('No_Data') }}</span></div>
            <div v-else class="mr-donut-wrap">
              <svg class="mr-donut" viewBox="0 0 180 180">
                <g transform="rotate(-90 90 90)">
                  <circle class="mr-donut__track" cx="90" cy="90" :r="radius" />
                  <circle
                    v-for="seg in donutSegments"
                    :key="seg.key"
                    cx="90" cy="90" :r="radius"
                    :stroke="seg.color"
                    :stroke-dasharray="seg.dash"
                    :stroke-dashoffset="seg.offset"
                  />
                </g>
                <text x="90" y="84" class="mr-donut__num">{{ statusTotal }}</text>
                <text x="90" y="104" class="mr-donut__cap">{{ $t('Meetings') }}</text>
              </svg>
              <ul class="mr-donut-legend">
                <li v-for="s in statusOrder" :key="s">
                  <span class="mr-dot" :style="{ background: statusColor[s] }"></span>
                  <span class="mr-legend-name">{{ format_label(s) }}</span>
                  <span class="mr-legend-count">{{ by_status[s] || 0 }}</span>
                </li>
              </ul>
            </div>
          </div>
        </div>

        <!-- Type + attendance rate gauge -->
        <div class="mr-card">
          <div class="mr-card__header">
            <span class="mr-card__title"><lucide-icon name="layers" size="18" /> {{ $t('Meetings_By_Type') }}</span>
          </div>
          <div class="mr-card__body">
            <div class="mr-type-grid">
              <div class="mr-type mr-type--physical">
                <div class="mr-type__icon"><lucide-icon name="calendar-days" size="20" /></div>
                <div class="mr-type__value">{{ by_type.physical || 0 }}</div>
                <div class="mr-type__label">{{ $t('Physical') }}</div>
              </div>
              <div class="mr-type mr-type--online">
                <div class="mr-type__icon"><lucide-icon name="video" size="20" /></div>
                <div class="mr-type__value">{{ by_type.online || 0 }}</div>
                <div class="mr-type__label">{{ $t('Online') }}</div>
              </div>
            </div>
            <div class="mr-split-bar">
              <div class="mr-split--physical" :style="{ width: physicalPct + '%' }"></div>
              <div class="mr-split--online" :style="{ width: onlinePct + '%' }"></div>
            </div>
            <div class="mr-split-legend">
              <span><span class="mr-dot mr-dot--physical"></span> {{ $t('Physical') }} {{ physicalPct }}%</span>
              <span><span class="mr-dot mr-dot--online"></span> {{ $t('Online') }} {{ onlinePct }}%</span>
            </div>
          </div>
        </div>
      </div>

      <div class="mr-grid">
        <!-- Attendance gauge + breakdown -->
        <div class="mr-card">
          <div class="mr-card__header">
            <span class="mr-card__title"><lucide-icon name="users" size="18" /> {{ $t('Attendance_Statistics') }}</span>
          </div>
          <div class="mr-card__body">
            <div class="mr-att-wrap">
              <svg class="mr-gauge" viewBox="0 0 130 130">
                <circle class="mr-gauge__track" cx="65" cy="65" :r="gaugeR" />
                <circle
                  class="mr-gauge__fill" cx="65" cy="65" :r="gaugeR"
                  :stroke-dasharray="gaugeC"
                  :stroke-dashoffset="gaugeOffset"
                  transform="rotate(-90 65 65)"
                />
                <text x="65" y="62" class="mr-gauge__num">{{ attendance_rate }}%</text>
                <text x="65" y="80" class="mr-gauge__cap">{{ $t('Attendance_Rate') }}</text>
              </svg>
              <div class="mr-att-bars">
                <div v-if="attendanceTotal === 0" class="mr-empty mr-empty--sm"><span>{{ $t('No_Data') }}</span></div>
                <div v-else v-for="a in attendanceOrder" :key="a" class="mr-bar-row">
                  <span class="mr-bar-label"><span class="mr-dot" :style="{ background: attendColor[a] }"></span> {{ format_label(a) }}</span>
                  <div class="mr-bar-track">
                    <div class="mr-bar-fill" :style="{ width: attPct(a) + '%', background: attendColor[a] }"></div>
                  </div>
                  <span class="mr-bar-count">{{ attendance[a] || 0 }}</span>
                </div>
              </div>
            </div>
          </div>
        </div>

        <!-- Top organizers -->
        <div class="mr-card">
          <div class="mr-card__header">
            <span class="mr-card__title"><lucide-icon name="trophy" size="18" /> {{ $t('Top_Organizers') }}</span>
          </div>
          <div class="mr-card__body mr-card__body--flush">
            <div v-if="!top_organizers || top_organizers.length === 0" class="mr-empty"><lucide-icon name="users" size="28" /><span>{{ $t('No_Data') }}</span></div>
            <ul v-else class="mr-rank">
              <li v-for="(o, i) in top_organizers" :key="o.organizer_id" class="mr-rank__item">
                <span class="mr-rank__pos" :class="'mr-rank__pos--' + (i + 1)">{{ i + 1 }}</span>
                <span class="mr-rank__avatar">{{ initials(user_name(o.organizer)) }}</span>
                <span class="mr-rank__name">{{ user_name(o.organizer) }}</span>
                <span class="mr-rank__count">{{ o.count }} <small>{{ $t('Meetings') }}</small></span>
              </li>
            </ul>
          </div>
        </div>
      </div>

      <!-- Monthly trend column chart -->
      <div class="mr-card">
        <div class="mr-card__header">
          <span class="mr-card__title"><lucide-icon name="trending-up" size="18" /> {{ $t('Monthly_Trend') }}</span>
          <div class="mr-chart-legend">
            <span><span class="mr-dot" style="background:#4361ee"></span> {{ $t('Total_Meetings') }}</span>
            <span><span class="mr-dot" style="background:#22c55e"></span> {{ $t('Completed') }}</span>
            <span><span class="mr-dot" style="background:#ef4444"></span> {{ $t('Cancelled') }}</span>
          </div>
        </div>
        <div class="mr-card__body">
          <div v-if="!monthly_trend || monthly_trend.length === 0" class="mr-empty"><lucide-icon name="bar-chart-3" size="28" /><span>{{ $t('No_Data') }}</span></div>
          <div v-else class="mr-chart">
            <div v-for="row in monthly_trend" :key="row.month" class="mr-chart__col">
              <div class="mr-chart__bars">
                <div class="mr-chart__bar mr-chart__bar--total" :style="{ height: barH(row.total) + '%' }" v-b-tooltip.hover :title="$t('Total_Meetings') + ': ' + row.total"></div>
                <div class="mr-chart__bar mr-chart__bar--completed" :style="{ height: barH(row.completed) + '%' }" v-b-tooltip.hover :title="$t('Completed') + ': ' + row.completed"></div>
                <div class="mr-chart__bar mr-chart__bar--cancelled" :style="{ height: barH(row.cancelled) + '%' }" v-b-tooltip.hover :title="$t('Cancelled') + ': ' + row.cancelled"></div>
              </div>
              <span class="mr-chart__label">{{ monthLabel(row.month) }}</span>
            </div>
          </div>
        </div>
      </div>
    </div>
  </div>
</template>

<script>
import moment from "moment";

export default {
  metaInfo: { title: "Meeting Reports" },
  data() {
    return {
      isLoading: true,
      radius: 70,
      gaugeR: 54,
      period: "6months",
      periods: [
        { value: "1month", label: "Last_Month" },
        { value: "3months", label: "Last_3_Months" },
        { value: "6months", label: "Last_6_Months" },
        { value: "1year", label: "Last_Year" }
      ],
      summary: {},
      by_status: {},
      by_type: {},
      attendance: {},
      attendance_rate: 0,
      monthly_trend: [],
      top_organizers: [],
      statusOrder: ["scheduled", "ongoing", "completed", "cancelled"],
      statusColor: { scheduled: "#4361ee", ongoing: "#f59e0b", completed: "#22c55e", cancelled: "#ef4444" },
      attendanceOrder: ["present", "late", "absent", "pending"],
      attendColor: { present: "#22c55e", late: "#f59e0b", absent: "#ef4444", pending: "#94a3b8" }
    };
  },
  computed: {
    summaryCards() {
      return [
        { label: "Total_Meetings", value: this.summary.total || 0, icon: "calendar-days", color: "primary" },
        { label: "Upcoming_Meetings", value: this.summary.upcoming || 0, icon: "calendar-clock", color: "info" },
        { label: "Completed_Meetings", value: this.summary.completed || 0, icon: "check-circle", color: "success" },
        { label: "Cancelled_Meetings", value: this.summary.cancelled || 0, icon: "x-circle", color: "danger" }
      ];
    },
    circumference() { return 2 * Math.PI * this.radius; },
    statusTotal() {
      return this.statusOrder.reduce((s, k) => s + (Number(this.by_status[k]) || 0), 0);
    },
    donutSegments() {
      const total = this.statusTotal;
      if (!total) return [];
      const C = this.circumference;
      let cumulative = 0;
      const segs = [];
      this.statusOrder.forEach(s => {
        const count = Number(this.by_status[s]) || 0;
        if (count <= 0) return;
        const len = (count / total) * C;
        segs.push({ key: s, color: this.statusColor[s], dash: len + " " + (C - len), offset: -cumulative });
        cumulative += len;
      });
      return segs;
    },
    typeTotal() { return (Number(this.by_type.physical) || 0) + (Number(this.by_type.online) || 0); },
    physicalPct() { return this.typeTotal ? Math.round(((Number(this.by_type.physical) || 0) / this.typeTotal) * 100) : 0; },
    onlinePct() { return this.typeTotal ? 100 - this.physicalPct : 0; },
    gaugeC() { return 2 * Math.PI * this.gaugeR; },
    gaugeOffset() { return this.gaugeC * (1 - (Number(this.attendance_rate) || 0) / 100); },
    attendanceTotal() {
      return this.attendanceOrder.reduce((s, k) => s + (Number(this.attendance[k]) || 0), 0);
    },
    maxMonthly() {
      if (!this.monthly_trend || !this.monthly_trend.length) return 1;
      return Math.max(...this.monthly_trend.map(r => Number(r.total) || 0), 1);
    }
  },
  methods: {
    format_label(v) { return v ? String(v).replace(/_/g, " ") : "-"; },
    setPeriod(p) { this.period = p; this.Get_Reports(); },
    attPct(a) {
      return this.attendanceTotal ? Math.round(((Number(this.attendance[a]) || 0) / this.attendanceTotal) * 100) : 0;
    },
    barH(v) {
      return Math.round(((Number(v) || 0) / this.maxMonthly) * 100);
    },
    monthLabel(m) {
      return m ? moment(m + "-01").format("MMM YY") : m;
    },
    user_name(u) {
      if (!u) return "-";
      const n = ((u.firstname || "") + " " + (u.lastname || "")).trim();
      return n || u.username || "-";
    },
    initials(name) {
      if (!name || name === "-") return "?";
      const parts = name.trim().split(/\s+/);
      return ((parts[0] ? parts[0][0] : "") + (parts[1] ? parts[1][0] : "")).toUpperCase();
    },
    Get_Reports() {
      this.isLoading = true;
      axios
        .get("meeting/reports?period=" + this.period)
        .then(({ data }) => {
          this.summary = data.summary || {};
          this.by_status = data.by_status || {};
          this.by_type = data.by_type || {};
          this.attendance = data.attendance || {};
          this.attendance_rate = data.attendance_rate || 0;
          this.monthly_trend = data.monthly_trend || [];
          this.top_organizers = data.top_organizers || [];
          this.isLoading = false;
        })
        .catch(() => { this.isLoading = false; });
    }
  },
  created() { this.Get_Reports(); }
};
</script>

<style scoped>
.meeting-reports {
  --mr-primary: #4361ee;
  --mr-info: #06b6d4;
  --mr-success: #22c55e;
  --mr-danger: #ef4444;
}

/* Hero */
.mr-hero {
  display: flex; align-items: center; justify-content: space-between; flex-wrap: wrap; gap: 16px;
  padding: 26px 30px; border-radius: 20px;
  background: linear-gradient(120deg, #0f172a 0%, #1e293b 45%, #334155 100%);
  color: #fff; box-shadow: 0 16px 38px rgba(15, 23, 42, 0.30);
  position: relative; overflow: hidden;
}
.mr-hero::after {
  content: ""; position: absolute; right: -30px; top: -50px;
  width: 200px; height: 200px; background: rgba(67, 97, 238, 0.18); border-radius: 50%;
}
.mr-hero__eyebrow {
  display: inline-flex; align-items: center; gap: 6px;
  font-size: 12px; font-weight: 600; letter-spacing: 0.5px; text-transform: uppercase; opacity: 0.8;
}
.mr-hero__title { font-size: 28px; font-weight: 800; margin: 4px 0 0; color: #fff; }
.mr-period { z-index: 1; display: inline-flex; background: rgba(255, 255, 255, 0.12); border-radius: 12px; padding: 4px; flex-wrap: wrap; }
.mr-period__btn {
  border: none; background: transparent; color: #cbd5e1;
  font-size: 13px; font-weight: 600; padding: 8px 14px; border-radius: 9px; cursor: pointer;
  transition: all 0.15s ease;
}
.mr-period__btn:hover { color: #fff; }
.mr-period__btn--active { background: #fff; color: #1e293b; box-shadow: 0 3px 8px rgba(0, 0, 0, 0.15); }

/* Stat cards */
.mr-stats { display: grid; grid-template-columns: repeat(auto-fit, minmax(190px, 1fr)); gap: 16px; margin-bottom: 20px; }
.mr-stat {
  position: relative; display: flex; align-items: center; gap: 14px;
  background: #fff; border-radius: 16px; padding: 18px; border: 1px solid #eef1f6;
  box-shadow: 0 4px 18px rgba(17, 24, 39, 0.05); transition: transform 0.2s ease, box-shadow 0.2s ease; overflow: hidden;
}
.mr-stat::before { content: ""; position: absolute; left: 0; top: 0; bottom: 0; width: 5px; background: var(--accent); }
.mr-stat:hover { transform: translateY(-3px); box-shadow: 0 12px 26px rgba(17, 24, 39, 0.1); }
.mr-stat__icon {
  width: 48px; height: 48px; border-radius: 13px; display: flex; align-items: center; justify-content: center;
  color: var(--accent); background: var(--accent-soft); flex-shrink: 0;
}
.mr-stat__value { font-size: 24px; font-weight: 800; line-height: 1.1; color: #1e293b; }
.mr-stat__label { font-size: 11px; font-weight: 600; text-transform: uppercase; letter-spacing: 0.4px; color: #94a3b8; margin-top: 2px; }
.mr-stat--primary { --accent: var(--mr-primary); --accent-soft: #eef1ff; }
.mr-stat--info    { --accent: var(--mr-info);    --accent-soft: #e7fbff; }
.mr-stat--success { --accent: var(--mr-success); --accent-soft: #e9fbef; }
.mr-stat--danger  { --accent: var(--mr-danger);  --accent-soft: #fde8e8; }

/* Grid */
.mr-grid { display: grid; grid-template-columns: 1fr 1fr; gap: 20px; margin-bottom: 20px; }
@media (max-width: 991px) { .mr-grid { grid-template-columns: 1fr; } }

/* Cards */
.mr-card { background: #fff; border-radius: 16px; border: 1px solid #eef1f6; box-shadow: 0 4px 18px rgba(17, 24, 39, 0.05); overflow: hidden; margin-bottom: 20px; }
.mr-grid .mr-card { margin-bottom: 0; }
.mr-card__header { padding: 16px 20px; border-bottom: 1px solid #f1f4f9; display: flex; align-items: center; justify-content: space-between; flex-wrap: wrap; gap: 8px; }
.mr-card__title { display: inline-flex; align-items: center; gap: 8px; font-size: 15px; font-weight: 700; color: #1e293b; }
.mr-card__body { padding: 20px; }
.mr-card__body--flush { padding: 6px 0; }

/* Donut */
.mr-donut-wrap { display: flex; align-items: center; gap: 22px; flex-wrap: wrap; justify-content: center; }
.mr-donut { width: 170px; height: 170px; flex-shrink: 0; }
.mr-donut circle { fill: none; stroke-width: 20; }
.mr-donut__track { stroke: #eef1f6; }
.mr-donut circle[stroke-dasharray] { transition: stroke-dasharray 0.5s ease; }
.mr-donut__num { font-size: 30px; font-weight: 800; fill: #1e293b; text-anchor: middle; }
.mr-donut__cap { font-size: 10px; font-weight: 600; fill: #94a3b8; text-anchor: middle; text-transform: uppercase; letter-spacing: 0.5px; }
.mr-donut-legend { list-style: none; margin: 0; padding: 0; min-width: 150px; flex: 1; }
.mr-donut-legend li { display: flex; align-items: center; gap: 8px; padding: 7px 0; border-bottom: 1px dashed #f1f4f9; font-size: 13px; }
.mr-donut-legend li:last-child { border-bottom: none; }
.mr-dot { width: 11px; height: 11px; border-radius: 50%; flex-shrink: 0; display: inline-block; }
.mr-dot--physical { background: #4361ee; }
.mr-dot--online { background: #06b6d4; }
.mr-legend-name { color: #475569; text-transform: capitalize; flex: 1; }
.mr-legend-count { font-weight: 700; color: #1e293b; }

/* Type */
.mr-type-grid { display: grid; grid-template-columns: 1fr 1fr; gap: 14px; margin-bottom: 18px; }
.mr-type { text-align: center; padding: 18px 10px; border-radius: 14px; border: 1px solid #eef1f6; }
.mr-type--physical { background: #eef1ff; }
.mr-type--online { background: #e7fbff; }
.mr-type__icon { width: 42px; height: 42px; border-radius: 12px; margin: 0 auto 8px; display: flex; align-items: center; justify-content: center; background: #fff; }
.mr-type--physical .mr-type__icon { color: #4361ee; }
.mr-type--online .mr-type__icon { color: #06b6d4; }
.mr-type__value { font-size: 24px; font-weight: 800; color: #1e293b; }
.mr-type__label { font-size: 12px; color: #64748b; font-weight: 600; }
.mr-split-bar { display: flex; height: 12px; border-radius: 8px; overflow: hidden; background: #eef1f6; margin-bottom: 10px; }
.mr-split--physical { background: #4361ee; transition: width 0.5s ease; }
.mr-split--online { background: #06b6d4; transition: width 0.5s ease; }
.mr-split-legend { display: flex; justify-content: space-between; font-size: 12px; color: #64748b; }
.mr-split-legend .mr-dot { margin-right: 4px; }

/* Attendance gauge */
.mr-att-wrap { display: flex; align-items: center; gap: 22px; flex-wrap: wrap; }
.mr-gauge { width: 130px; height: 130px; flex-shrink: 0; }
.mr-gauge circle { fill: none; stroke-width: 12; }
.mr-gauge__track { stroke: #eef1f6; }
.mr-gauge__fill { stroke: #22c55e; stroke-linecap: round; transition: stroke-dashoffset 0.6s ease; }
.mr-gauge__num { font-size: 24px; font-weight: 800; fill: #16a34a; text-anchor: middle; }
.mr-gauge__cap { font-size: 8px; font-weight: 600; fill: #94a3b8; text-anchor: middle; text-transform: uppercase; letter-spacing: 0.4px; }
.mr-att-bars { flex: 1; min-width: 180px; }
.mr-bar-row { display: flex; align-items: center; gap: 10px; margin-bottom: 12px; }
.mr-bar-row:last-child { margin-bottom: 0; }
.mr-bar-label { width: 92px; font-size: 13px; color: #475569; text-transform: capitalize; display: flex; align-items: center; gap: 6px; }
.mr-bar-track { flex: 1; height: 9px; background: #eef1f6; border-radius: 6px; overflow: hidden; }
.mr-bar-fill { height: 100%; border-radius: 6px; transition: width 0.5s ease; }
.mr-bar-count { width: 30px; text-align: right; font-weight: 700; color: #1e293b; }

/* Rank list */
.mr-rank { list-style: none; margin: 0; padding: 0; }
.mr-rank__item { display: flex; align-items: center; gap: 12px; padding: 11px 20px; border-bottom: 1px solid #f4f6fa; }
.mr-rank__item:last-child { border-bottom: none; }
.mr-rank__pos {
  width: 24px; height: 24px; border-radius: 7px; flex-shrink: 0;
  display: flex; align-items: center; justify-content: center;
  font-size: 12px; font-weight: 800; background: #eef2f7; color: #64748b;
}
.mr-rank__pos--1 { background: #fef3c7; color: #b45309; }
.mr-rank__pos--2 { background: #e5e7eb; color: #475569; }
.mr-rank__pos--3 { background: #fde4cf; color: #c2410c; }
.mr-rank__avatar {
  width: 36px; height: 36px; border-radius: 10px; flex-shrink: 0;
  background: linear-gradient(135deg, #4361ee, #7c3aed); color: #fff;
  display: flex; align-items: center; justify-content: center; font-weight: 700; font-size: 13px;
}
.mr-rank__name { flex: 1; font-weight: 600; color: #1e293b; white-space: nowrap; overflow: hidden; text-overflow: ellipsis; }
.mr-rank__count { font-weight: 700; color: #4361ee; font-size: 14px; }
.mr-rank__count small { color: #94a3b8; font-weight: 600; font-size: 11px; }

/* Monthly column chart */
.mr-chart-legend { display: flex; gap: 14px; font-size: 12px; color: #64748b; }
.mr-chart-legend .mr-dot { margin-right: 4px; }
.mr-chart { display: flex; align-items: flex-end; gap: 10px; height: 220px; padding-top: 10px; }
.mr-chart__col { flex: 1; display: flex; flex-direction: column; align-items: center; height: 100%; }
.mr-chart__bars { flex: 1; display: flex; align-items: flex-end; gap: 3px; width: 100%; justify-content: center; }
.mr-chart__bar { width: 16px; border-radius: 5px 5px 0 0; min-height: 2px; transition: height 0.5s ease; }
.mr-chart__bar--total { background: #4361ee; }
.mr-chart__bar--completed { background: #22c55e; }
.mr-chart__bar--cancelled { background: #ef4444; }
.mr-chart__label { font-size: 11px; color: #94a3b8; margin-top: 8px; font-weight: 600; }

/* Empty */
.mr-empty { display: flex; flex-direction: column; align-items: center; gap: 10px; padding: 40px 20px; color: #b0b8c5; font-size: 13px; }
.mr-empty--sm { padding: 16px; }

/* Dark theme */
body.dark-theme .mr-stat,
body.dark-theme .mr-card { background: #1e1e2d; border-color: #2b2b40; }
body.dark-theme .mr-stat__value,
body.dark-theme .mr-card__title,
body.dark-theme .mr-type__value,
body.dark-theme .mr-donut__num,
body.dark-theme .mr-legend-count,
body.dark-theme .mr-bar-count,
body.dark-theme .mr-rank__name { color: #e7e9f3; }
body.dark-theme .mr-card__header { border-color: #2b2b40; }
body.dark-theme .mr-donut__track,
body.dark-theme .mr-gauge__track { stroke: #2b2b40; }
body.dark-theme .mr-type { background: #252537; border-color: #2b2b40; }
body.dark-theme .mr-type__icon { background: #1e1e2d; }
body.dark-theme .mr-bar-track,
body.dark-theme .mr-split-bar { background: #2b2b40; }
body.dark-theme .mr-rank__item { border-color: #2b2b40; }
body.dark-theme .mr-donut-legend li { border-color: #2b2b40; }
</style>
