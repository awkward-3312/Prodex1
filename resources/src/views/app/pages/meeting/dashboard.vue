<template>
  <div class="main-content meeting-dashboard">
    <breadcumb :page="$t('Dashboard')" :folder="$t('Meeting_Management')" />

    <div v-if="isLoading" class="loading_page spinner spinner-primary mr-3"></div>

    <div v-if="!isLoading">
      <!-- Hero header -->
      <div class="md-hero mb-4">
        <div class="md-hero__text">
          <span class="md-hero__eyebrow">{{ todayLabel }}</span>
          <h2 class="md-hero__title">{{ $t('Meeting_Management') }}</h2>
          <p class="md-hero__subtitle">
            {{ stats.today_meetings || 0 }} {{ $t('Today_Meetings') }} · {{ stats.upcoming_meetings || 0 }} {{ $t('Upcoming_Meetings') }}
          </p>
        </div>
        <router-link to="/app/meeting/meetings" class="md-hero__btn">
          <lucide-icon name="plus" size="18" /> {{ $t('New_Meeting') }}
        </router-link>
      </div>

      <!-- Stat cards -->
      <div class="md-stats">
        <div v-for="card in statCards" :key="card.label" class="md-stat" :class="'md-stat--' + card.color">
          <div class="md-stat__icon">
            <lucide-icon :name="card.icon" size="22" />
          </div>
          <div class="md-stat__body">
            <div class="md-stat__value">{{ card.value }}</div>
            <div class="md-stat__label">{{ $t(card.label) }}</div>
          </div>
        </div>
      </div>

      <div class="md-grid">
        <!-- Status donut -->
        <div class="md-card md-card--chart">
          <div class="md-card__header">
            <span class="md-card__title">
              <lucide-icon name="pie-chart" size="18" /> {{ $t('Meetings_By_Status') }}
            </span>
          </div>
          <div class="md-card__body">
            <div class="md-donut-wrap">
              <svg class="md-donut" viewBox="0 0 180 180">
                <g transform="rotate(-90 90 90)">
                  <circle class="md-donut__track" cx="90" cy="90" :r="radius" />
                  <circle
                    v-for="seg in donutSegments"
                    :key="seg.key"
                    cx="90" cy="90" :r="radius"
                    :stroke="seg.color"
                    :stroke-dasharray="seg.dash"
                    :stroke-dashoffset="seg.offset"
                  />
                </g>
                <text x="90" y="84" class="md-donut__num">{{ stats.total_meetings || 0 }}</text>
                <text x="90" y="104" class="md-donut__cap">{{ $t('Total_Meetings') }}</text>
              </svg>
              <ul class="md-donut-legend">
                <li v-for="s in statusOrder" :key="s">
                  <span class="md-dot" :style="{ background: statusColor[s] }"></span>
                  <span class="md-legend-name">{{ format_label(s) }}</span>
                  <span class="md-legend-count">{{ by_status[s] || 0 }}</span>
                </li>
              </ul>
            </div>
          </div>
        </div>

        <!-- Type split -->
        <div class="md-card">
          <div class="md-card__header">
            <span class="md-card__title">
              <lucide-icon name="layers" size="18" /> {{ $t('Meetings_By_Type') }}
            </span>
          </div>
          <div class="md-card__body">
            <div class="md-type-grid">
              <div class="md-type md-type--physical">
                <div class="md-type__icon"><lucide-icon name="map-pin" size="22" /></div>
                <div class="md-type__value">{{ by_type.physical || 0 }}</div>
                <div class="md-type__label">{{ $t('Physical') }}</div>
              </div>
              <div class="md-type md-type--online">
                <div class="md-type__icon"><lucide-icon name="video" size="22" /></div>
                <div class="md-type__value">{{ by_type.online || 0 }}</div>
                <div class="md-type__label">{{ $t('Online') }}</div>
              </div>
            </div>
            <div class="md-split-bar">
              <div class="md-split-bar__fill md-split--physical" :style="{ width: physicalPct + '%' }" v-b-tooltip.hover :title="$t('Physical') + ': ' + physicalPct + '%'"></div>
              <div class="md-split-bar__fill md-split--online" :style="{ width: onlinePct + '%' }" v-b-tooltip.hover :title="$t('Online') + ': ' + onlinePct + '%'"></div>
            </div>
            <div class="md-split-legend">
              <span><span class="md-dot md-dot--physical"></span> {{ $t('Physical') }} {{ physicalPct }}%</span>
              <span><span class="md-dot md-dot--online"></span> {{ $t('Online') }} {{ onlinePct }}%</span>
            </div>

            <div class="md-mini-stats">
              <div class="md-mini">
                <lucide-icon name="check-circle" size="16" class="text-success" />
                <span>{{ $t('Completed') }}</span>
                <strong>{{ stats.completed_meetings || 0 }}</strong>
              </div>
              <div class="md-mini">
                <lucide-icon name="x-circle" size="16" class="text-danger" />
                <span>{{ $t('Cancelled') }}</span>
                <strong>{{ stats.cancelled_meetings || 0 }}</strong>
              </div>
            </div>
          </div>
        </div>

        <!-- Upcoming meetings -->
        <div class="md-card md-card--wide">
          <div class="md-card__header">
            <span class="md-card__title">
              <lucide-icon name="calendar-clock" size="18" /> {{ $t('Upcoming_Meetings') }}
            </span>
            <router-link to="/app/meeting/calendar" class="md-link">{{ $t('Calendar') }}</router-link>
          </div>
          <div class="md-card__body md-card__body--flush">
            <div v-if="upcoming_meetings.length === 0" class="md-empty">
              <lucide-icon name="calendar" size="32" />
              <span>{{ $t('No_Meetings_Scheduled') }}</span>
            </div>
            <ul v-else class="md-list">
              <li v-for="m in upcoming_meetings" :key="m.id" class="md-list__item" @click="goTo(m.id)">
                <div class="md-daybox">
                  <span class="md-daybox__day">{{ dayOf(m.meeting_date) }}</span>
                  <span class="md-daybox__mon">{{ monOf(m.meeting_date) }}</span>
                </div>
                <div class="md-list__main">
                  <div class="md-list__name">{{ m.title }}</div>
                  <div class="md-list__sub">
                    <lucide-icon name="clock" size="13" /> {{ short_time(m.start_time) }}
                    <span class="md-sep">·</span>
                    <lucide-icon :name="m.type === 'online' ? 'video' : 'map-pin'" size="13" /> {{ format_label(m.type) }}
                    <span class="md-sep">·</span>
                    <lucide-icon name="users" size="13" /> {{ m.participants_count }}
                  </div>
                </div>
                <span class="md-badge" :class="'md-st--' + m.status">{{ format_label(m.status) }}</span>
              </li>
            </ul>
          </div>
        </div>
      </div>
    </div>
  </div>
</template>

<script>
import moment from "moment";

export default {
  metaInfo: { title: "Meeting Dashboard" },
  data() {
    return {
      isLoading: true,
      radius: 70,
      stats: {},
      upcoming_meetings: [],
      by_status: {},
      by_type: {},
      statusOrder: ["scheduled", "ongoing", "completed", "cancelled"],
      statusColor: {
        scheduled: "#4361ee",
        ongoing: "#f59e0b",
        completed: "#22c55e",
        cancelled: "#ef4444"
      }
    };
  },
  computed: {
    todayLabel() {
      return moment().format("dddd, MMMM D, YYYY");
    },
    statCards() {
      return [
        { label: "Total_Meetings", value: this.stats.total_meetings || 0, icon: "calendar-days", color: "primary" },
        { label: "Upcoming_Meetings", value: this.stats.upcoming_meetings || 0, icon: "calendar-clock", color: "info" },
        { label: "Today_Meetings", value: this.stats.today_meetings || 0, icon: "sun", color: "purple" },
        { label: "Ongoing_Meetings", value: this.stats.ongoing_meetings || 0, icon: "activity", color: "warning" },
        { label: "Completed_Meetings", value: this.stats.completed_meetings || 0, icon: "check-circle", color: "success" },
        { label: "Cancelled_Meetings", value: this.stats.cancelled_meetings || 0, icon: "x-circle", color: "dark" }
      ];
    },
    circumference() {
      return 2 * Math.PI * this.radius;
    },
    statusTotal() {
      return this.statusOrder.reduce((sum, s) => sum + (Number(this.by_status[s]) || 0), 0);
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
        segs.push({
          key: s,
          color: this.statusColor[s],
          dash: len + " " + (C - len),
          offset: -cumulative
        });
        cumulative += len;
      });
      return segs;
    },
    typeTotal() {
      return (Number(this.by_type.physical) || 0) + (Number(this.by_type.online) || 0);
    },
    physicalPct() {
      return this.typeTotal ? Math.round(((Number(this.by_type.physical) || 0) / this.typeTotal) * 100) : 0;
    },
    onlinePct() {
      return this.typeTotal ? 100 - this.physicalPct : 0;
    }
  },
  methods: {
    format_label(v) {
      return v ? String(v).replace(/_/g, " ") : "-";
    },
    short_time(t) {
      return t ? String(t).substring(0, 5) : "";
    },
    dayOf(d) {
      return d ? moment(String(d).substring(0, 10)).format("DD") : "--";
    },
    monOf(d) {
      return d ? moment(String(d).substring(0, 10)).format("MMM") : "";
    },
    goTo(id) {
      this.$router.push({ name: "meeting_details", params: { id: id } });
    },
    Get_Dashboard() {
      axios
        .get("meeting/dashboard")
        .then(({ data }) => {
          this.stats = data.stats;
          this.upcoming_meetings = data.upcoming_meetings;
          this.by_status = data.by_status;
          this.by_type = data.by_type;
          this.isLoading = false;
        })
        .catch(() => {
          this.isLoading = false;
        });
    }
  },
  created() {
    this.Get_Dashboard();
  }
};
</script>

<style scoped>
.meeting-dashboard {
  --md-primary: #4361ee;
  --md-info: #06b6d4;
  --md-purple: #8b5cf6;
  --md-warning: #f59e0b;
  --md-success: #22c55e;
  --md-dark: #334155;
}

/* Hero */
.md-hero {
  display: flex;
  align-items: center;
  justify-content: space-between;
  flex-wrap: wrap;
  gap: 16px;
  padding: 28px 30px;
  border-radius: 20px;
  background: linear-gradient(120deg, #4361ee 0%, #7c3aed 55%, #9333ea 100%);
  color: #fff;
  box-shadow: 0 16px 38px rgba(67, 97, 238, 0.32);
  position: relative;
  overflow: hidden;
}
.md-hero::after {
  content: "";
  position: absolute;
  right: -40px; top: -60px;
  width: 220px; height: 220px;
  background: rgba(255, 255, 255, 0.10);
  border-radius: 50%;
}
.md-hero__eyebrow {
  font-size: 12px; font-weight: 600; letter-spacing: 0.5px;
  text-transform: uppercase; opacity: 0.85;
}
.md-hero__title { font-size: 28px; font-weight: 800; margin: 4px 0 0; color: #fff; }
.md-hero__subtitle { margin: 6px 0 0; opacity: 0.9; font-size: 14px; }
.md-hero__btn {
  z-index: 1;
  display: inline-flex; align-items: center; gap: 8px;
  background: #fff; color: #4361ee;
  font-weight: 700; font-size: 14px;
  padding: 11px 20px; border-radius: 12px;
  box-shadow: 0 6px 16px rgba(0, 0, 0, 0.12);
  transition: transform 0.15s ease;
}
.md-hero__btn:hover { transform: translateY(-2px); color: #3b4cca; }

/* Stat grid */
.md-stats {
  display: grid;
  grid-template-columns: repeat(auto-fit, minmax(180px, 1fr));
  gap: 16px;
  margin-bottom: 22px;
}
.md-stat {
  position: relative; display: flex; align-items: center; gap: 14px;
  background: #fff; border-radius: 16px; padding: 18px;
  border: 1px solid #eef1f6; box-shadow: 0 4px 18px rgba(17, 24, 39, 0.05);
  transition: transform 0.2s ease, box-shadow 0.2s ease; overflow: hidden;
}
.md-stat::before {
  content: ""; position: absolute; left: 0; top: 0; bottom: 0; width: 5px; background: var(--accent);
}
.md-stat:hover { transform: translateY(-3px); box-shadow: 0 12px 26px rgba(17, 24, 39, 0.1); }
.md-stat__icon {
  width: 48px; height: 48px; border-radius: 13px;
  display: flex; align-items: center; justify-content: center;
  color: var(--accent); background: var(--accent-soft); flex-shrink: 0;
}
.md-stat__value { font-size: 24px; font-weight: 800; line-height: 1.1; color: #1e293b; }
.md-stat__label {
  font-size: 11px; font-weight: 600; text-transform: uppercase;
  letter-spacing: 0.4px; color: #94a3b8; margin-top: 2px;
}
.md-stat--primary { --accent: var(--md-primary); --accent-soft: #eef1ff; }
.md-stat--info    { --accent: var(--md-info);    --accent-soft: #e7fbff; }
.md-stat--purple  { --accent: var(--md-purple);  --accent-soft: #f3eeff; }
.md-stat--warning { --accent: var(--md-warning); --accent-soft: #fff5e5; }
.md-stat--success { --accent: var(--md-success); --accent-soft: #e9fbef; }
.md-stat--dark    { --accent: var(--md-dark);    --accent-soft: #eef2f7; }

/* Grid layout */
.md-grid {
  display: grid;
  grid-template-columns: 1fr 1fr;
  gap: 20px;
}
.md-card--wide { grid-column: 1 / -1; }
@media (max-width: 991px) {
  .md-grid { grid-template-columns: 1fr; }
}

/* Cards */
.md-card {
  background: #fff; border-radius: 16px; border: 1px solid #eef1f6;
  box-shadow: 0 4px 18px rgba(17, 24, 39, 0.05); overflow: hidden;
}
.md-card__header {
  padding: 16px 20px; border-bottom: 1px solid #f1f4f9;
  display: flex; align-items: center; justify-content: space-between;
}
.md-card__title {
  display: inline-flex; align-items: center; gap: 8px;
  font-size: 15px; font-weight: 700; color: #1e293b;
}
.md-link { font-size: 13px; color: #4361ee; font-weight: 600; }
.md-card__body { padding: 20px; }
.md-card__body--flush { padding: 6px 0; }

/* Donut */
.md-donut-wrap { display: flex; align-items: center; gap: 22px; flex-wrap: wrap; justify-content: center; }
.md-donut { width: 180px; height: 180px; flex-shrink: 0; }
.md-donut circle { fill: none; stroke-width: 20; }
.md-donut__track { stroke: #eef1f6; }
.md-donut circle[stroke-dasharray] { transition: stroke-dasharray 0.5s ease; stroke-linecap: butt; }
.md-donut__num { font-size: 30px; font-weight: 800; fill: #1e293b; text-anchor: middle; }
.md-donut__cap { font-size: 10px; font-weight: 600; fill: #94a3b8; text-anchor: middle; text-transform: uppercase; letter-spacing: 0.5px; }
.md-donut-legend { list-style: none; margin: 0; padding: 0; min-width: 160px; flex: 1; }
.md-donut-legend li {
  display: flex; align-items: center; gap: 8px;
  padding: 7px 0; border-bottom: 1px dashed #f1f4f9; font-size: 13px;
}
.md-donut-legend li:last-child { border-bottom: none; }
.md-dot { width: 11px; height: 11px; border-radius: 50%; flex-shrink: 0; }
.md-dot--physical { background: #4361ee; }
.md-dot--online { background: #06b6d4; }
.md-legend-name { color: #475569; text-transform: capitalize; flex: 1; }
.md-legend-count { font-weight: 700; color: #1e293b; }

/* Type split */
.md-type-grid { display: grid; grid-template-columns: 1fr 1fr; gap: 14px; margin-bottom: 18px; }
.md-type {
  text-align: center; padding: 18px 10px; border-radius: 14px;
  border: 1px solid #eef1f6;
}
.md-type--physical { background: #eef1ff; }
.md-type--online { background: #e7fbff; }
.md-type__icon {
  width: 44px; height: 44px; border-radius: 12px; margin: 0 auto 8px;
  display: flex; align-items: center; justify-content: center; background: #fff;
}
.md-type--physical .md-type__icon { color: #4361ee; }
.md-type--online .md-type__icon { color: #06b6d4; }
.md-type__value { font-size: 26px; font-weight: 800; color: #1e293b; }
.md-type__label { font-size: 12px; color: #64748b; font-weight: 600; }
.md-split-bar {
  display: flex; height: 12px; border-radius: 8px; overflow: hidden;
  background: #eef1f6; margin-bottom: 10px;
}
.md-split-bar__fill { height: 100%; transition: width 0.5s ease; }
.md-split--physical { background: #4361ee; }
.md-split--online { background: #06b6d4; }
.md-split-legend { display: flex; justify-content: space-between; font-size: 12px; color: #64748b; }
.md-split-legend .md-dot { display: inline-block; margin-right: 4px; }
.md-mini-stats { display: flex; gap: 12px; margin-top: 18px; }
.md-mini {
  flex: 1; display: flex; align-items: center; gap: 6px;
  padding: 10px 12px; border-radius: 12px; background: #f8fafc;
  font-size: 13px; color: #475569;
}
.md-mini strong { margin-left: auto; color: #1e293b; font-size: 15px; }

/* Upcoming list */
.md-list { list-style: none; margin: 0; padding: 0; }
.md-list__item {
  display: flex; align-items: center; gap: 14px;
  padding: 12px 20px; border-bottom: 1px solid #f4f6fa; cursor: pointer;
}
.md-list__item:last-child { border-bottom: none; }
.md-list__item:hover { background: #f8fafe; }
.md-daybox {
  width: 50px; height: 50px; border-radius: 12px; flex-shrink: 0;
  background: linear-gradient(135deg, #4361ee, #7c3aed); color: #fff;
  display: flex; flex-direction: column; align-items: center; justify-content: center;
}
.md-daybox__day { font-size: 18px; font-weight: 800; line-height: 1; }
.md-daybox__mon { font-size: 10px; text-transform: uppercase; opacity: 0.9; }
.md-list__main { flex: 1; min-width: 0; }
.md-list__name { font-weight: 600; color: #1e293b; white-space: nowrap; overflow: hidden; text-overflow: ellipsis; }
.md-list__sub {
  font-size: 12px; color: #94a3b8; margin-top: 3px;
  display: flex; align-items: center; gap: 4px; flex-wrap: wrap;
}
.md-sep { opacity: 0.5; margin: 0 2px; }
.md-badge {
  display: inline-block; padding: 4px 11px; border-radius: 20px;
  font-size: 11px; font-weight: 700; text-transform: capitalize;
  background: #eef2f7; color: #475569; flex-shrink: 0;
}
.md-st--scheduled { background: #eef1ff; color: #4361ee; }
.md-st--ongoing   { background: #fff5e5; color: #b45309; }
.md-st--completed { background: #e9fbef; color: #16a34a; }
.md-st--cancelled { background: #fde8e8; color: #dc2626; }
.md-empty {
  display: flex; flex-direction: column; align-items: center; gap: 10px;
  padding: 44px 20px; color: #b0b8c5; font-size: 14px;
}

/* Dark theme */
body.dark-theme .md-stat,
body.dark-theme .md-card { background: #1e1e2d; border-color: #2b2b40; }
body.dark-theme .md-stat__value,
body.dark-theme .md-list__name,
body.dark-theme .md-card__title,
body.dark-theme .md-type__value,
body.dark-theme .md-donut__num,
body.dark-theme .md-legend-count,
body.dark-theme .md-mini strong { color: #e7e9f3; }
body.dark-theme .md-card__header { border-color: #2b2b40; }
body.dark-theme .md-list__item { border-color: #2b2b40; }
body.dark-theme .md-list__item:hover { background: #252537; }
body.dark-theme .md-donut__track { stroke: #2b2b40; }
body.dark-theme .md-type { background: #252537; border-color: #2b2b40; }
body.dark-theme .md-type__icon { background: #1e1e2d; }
body.dark-theme .md-mini { background: #252537; }
body.dark-theme .md-donut-legend li { border-color: #2b2b40; }
</style>
