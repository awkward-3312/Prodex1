<template>
  <div class="main-content recruit-dashboard">
    <breadcumb :page="$t('Dashboard')" :folder="$t('Recruit')" />

    <div v-if="isLoading" class="loading_page spinner spinner-primary mr-3"></div>

    <div v-if="!isLoading">
      <!-- Hero header -->
      <div class="rd-hero mb-4">
        <div class="rd-hero__text">
          <h2 class="rd-hero__title">{{ $t('Recruit') }}</h2>
          <p class="rd-hero__subtitle">{{ $t('Dashboard') }}</p>
        </div>
        <div class="rd-hero__icon">
          <lucide-icon name="users" size="40" />
        </div>
      </div>

      <!-- Stat cards -->
      <div class="rd-stats">
        <div
          v-for="card in statCards"
          :key="card.label"
          class="rd-stat"
          :class="'rd-stat--' + card.color"
        >
          <div class="rd-stat__icon">
            <lucide-icon :name="card.icon" size="24" />
          </div>
          <div class="rd-stat__body">
            <div class="rd-stat__value">{{ card.value }}</div>
            <div class="rd-stat__label">{{ $t(card.label) }}</div>
          </div>
        </div>
      </div>

      <!-- Pipeline -->
      <div class="rd-card mb-4">
        <div class="rd-card__header">
          <span class="rd-card__title">
            <lucide-icon name="activity" size="18" /> {{ $t('Pipeline') }}
          </span>
        </div>
        <div class="rd-card__body">
          <div class="rd-pipeline-bar">
            <div
              v-for="(s, idx) in stages"
              :key="'seg-' + s"
              class="rd-pipeline-seg"
              :class="'rd-seg--' + idx"
              :style="{ flex: (pipeline[s] || 0) + 0.02 }"
              v-b-tooltip.hover
              :title="format_label(s) + ': ' + (pipeline[s] || 0)"
            ></div>
          </div>
          <div class="rd-pipeline-legend">
            <div v-for="(s, idx) in stages" :key="'leg-' + s" class="rd-legend-item">
              <span class="rd-legend-dot" :class="'rd-seg--' + idx"></span>
              <span class="rd-legend-name">{{ format_label(s) }}</span>
              <span class="rd-legend-count">{{ pipeline[s] || 0 }}</span>
            </div>
          </div>
        </div>
      </div>

      <div class="rd-grid">
        <!-- Recent applications -->
        <div class="rd-card">
          <div class="rd-card__header">
            <span class="rd-card__title">
              <lucide-icon name="file-text" size="18" /> {{ $t('Recent_Applications') }}
            </span>
          </div>
          <div class="rd-card__body rd-card__body--flush">
            <div v-if="recent_applications.length === 0" class="rd-empty">
              <lucide-icon name="inbox" size="28" />
              <span>{{ $t('No_data') }}</span>
            </div>
            <ul v-else class="rd-list">
              <li v-for="a in recent_applications" :key="a.id" class="rd-list__item">
                <span class="rd-avatar">{{ initials(candidate_name_app(a)) }}</span>
                <div class="rd-list__main">
                  <div class="rd-list__name">{{ candidate_name_app(a) }}</div>
                  <div class="rd-list__sub">{{ a.job ? a.job.title : '-' }}</div>
                </div>
                <div class="rd-list__meta">
                  <span class="rd-badge" :class="'rd-stage--' + a.stage">{{ format_label(a.stage) }}</span>
                  <span class="rd-date">{{ a.applied_date }}</span>
                </div>
              </li>
            </ul>
          </div>
        </div>

        <!-- Upcoming interviews -->
        <div class="rd-card">
          <div class="rd-card__header">
            <span class="rd-card__title">
              <lucide-icon name="calendar" size="18" /> {{ $t('Upcoming_Interviews') }}
            </span>
          </div>
          <div class="rd-card__body rd-card__body--flush">
            <div v-if="upcoming_interviews.length === 0" class="rd-empty">
              <lucide-icon name="calendar" size="28" />
              <span>{{ $t('No_data') }}</span>
            </div>
            <ul v-else class="rd-list">
              <li v-for="i in upcoming_interviews" :key="i.id" class="rd-list__item">
                <span class="rd-avatar rd-avatar--soft">
                  <lucide-icon name="calendar" size="18" />
                </span>
                <div class="rd-list__main">
                  <div class="rd-list__name">{{ interview_candidate(i) }}</div>
                  <div class="rd-list__sub">{{ format_label(i.type) }}</div>
                </div>
                <div class="rd-list__meta">
                  <span class="rd-badge rd-badge--info">{{ format_label(i.status) }}</span>
                  <span class="rd-date">{{ format_datetime(i.scheduled_at) }}</span>
                </div>
              </li>
            </ul>
          </div>
        </div>
      </div>
    </div>
  </div>
</template>

<script>
export default {
  metaInfo: { title: "Recruit Dashboard" },
  data() {
    return {
      isLoading: true,
      stats: {},
      recent_applications: [],
      upcoming_interviews: [],
      pipeline: {},
      stages: ["applied", "screening", "shortlisted", "interview", "offered", "hired", "rejected"]
    };
  },
  computed: {
    statCards() {
      return [
        { label: "Open_Jobs", value: this.stats.open_jobs || 0, icon: "briefcase-business", color: "primary" },
        { label: "Candidates", value: this.stats.total_candidates || 0, icon: "users", color: "info" },
        { label: "Applications", value: this.stats.total_applications || 0, icon: "file-text", color: "purple" },
        { label: "Pending_Interviews", value: this.stats.pending_interviews || 0, icon: "calendar", color: "warning" },
        { label: "Hired", value: this.stats.hired_count || 0, icon: "check-circle", color: "success" },
        { label: "Total_Jobs", value: this.stats.total_jobs || 0, icon: "clipboard-list", color: "dark" }
      ];
    }
  },
  methods: {
    format_label(v) {
      return v ? String(v).replace(/_/g, " ") : "-";
    },
    format_datetime(v) {
      if (!v) return "-";
      return String(v).replace("T", " ").substring(0, 16);
    },
    candidate_name_app(a) {
      return a.candidate ? a.candidate.first_name + " " + a.candidate.last_name : "-";
    },
    interview_candidate(i) {
      const c = i.application && i.application.candidate;
      return c ? c.first_name + " " + c.last_name : "-";
    },
    initials(name) {
      if (!name || name === "-") return "?";
      const parts = name.trim().split(/\s+/);
      return ((parts[0] ? parts[0][0] : "") + (parts[1] ? parts[1][0] : "")).toUpperCase();
    },
    Get_Dashboard() {
      axios
        .get("recruit/dashboard")
        .then(({ data }) => {
          this.stats = data.stats;
          this.recent_applications = data.recent_applications;
          this.upcoming_interviews = data.upcoming_interviews;
          this.pipeline = data.pipeline;
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
.recruit-dashboard {
  --rd-primary: #4361ee;
  --rd-info: #06b6d4;
  --rd-purple: #8b5cf6;
  --rd-warning: #f59e0b;
  --rd-success: #22c55e;
  --rd-dark: #334155;
}

/* Hero */
.rd-hero {
  display: flex;
  align-items: center;
  justify-content: space-between;
  padding: 26px 28px;
  border-radius: 18px;
  background: linear-gradient(135deg, #4361ee 0%, #7c3aed 100%);
  color: #fff;
  box-shadow: 0 12px 30px rgba(67, 97, 238, 0.28);
}
.rd-hero__title {
  font-size: 26px;
  font-weight: 800;
  margin: 0;
  color: #fff;
}
.rd-hero__subtitle {
  margin: 4px 0 0;
  opacity: 0.85;
  font-size: 14px;
}
.rd-hero__icon {
  width: 64px;
  height: 64px;
  border-radius: 16px;
  background: rgba(255, 255, 255, 0.18);
  display: flex;
  align-items: center;
  justify-content: center;
  flex-shrink: 0;
}

/* Stat grid */
.rd-stats {
  display: grid;
  grid-template-columns: repeat(auto-fit, minmax(190px, 1fr));
  gap: 18px;
  margin-bottom: 24px;
}
.rd-stat {
  position: relative;
  display: flex;
  align-items: center;
  gap: 16px;
  background: #fff;
  border-radius: 16px;
  padding: 20px;
  border: 1px solid #eef1f6;
  box-shadow: 0 4px 18px rgba(17, 24, 39, 0.05);
  transition: transform 0.2s ease, box-shadow 0.2s ease;
  overflow: hidden;
}
.rd-stat::before {
  content: "";
  position: absolute;
  left: 0;
  top: 0;
  bottom: 0;
  width: 5px;
  background: var(--accent);
}
.rd-stat:hover {
  transform: translateY(-3px);
  box-shadow: 0 12px 26px rgba(17, 24, 39, 0.1);
}
.rd-stat__icon {
  width: 52px;
  height: 52px;
  border-radius: 14px;
  display: flex;
  align-items: center;
  justify-content: center;
  color: var(--accent);
  background: var(--accent-soft);
  flex-shrink: 0;
}
.rd-stat__value {
  font-size: 26px;
  font-weight: 800;
  line-height: 1.1;
  color: #1e293b;
}
.rd-stat__label {
  font-size: 12px;
  font-weight: 600;
  text-transform: uppercase;
  letter-spacing: 0.4px;
  color: #94a3b8;
  margin-top: 2px;
}
.rd-stat--primary { --accent: var(--rd-primary); --accent-soft: #eef1ff; }
.rd-stat--info    { --accent: var(--rd-info);    --accent-soft: #e7fbff; }
.rd-stat--purple  { --accent: var(--rd-purple);  --accent-soft: #f3eeff; }
.rd-stat--warning { --accent: var(--rd-warning); --accent-soft: #fff5e5; }
.rd-stat--success { --accent: var(--rd-success); --accent-soft: #e9fbef; }
.rd-stat--dark    { --accent: var(--rd-dark);    --accent-soft: #eef2f7; }

/* Cards */
.rd-card {
  background: #fff;
  border-radius: 16px;
  border: 1px solid #eef1f6;
  box-shadow: 0 4px 18px rgba(17, 24, 39, 0.05);
  overflow: hidden;
}
.rd-card__header {
  padding: 16px 20px;
  border-bottom: 1px solid #f1f4f9;
}
.rd-card__title {
  display: inline-flex;
  align-items: center;
  gap: 8px;
  font-size: 15px;
  font-weight: 700;
  color: #1e293b;
}
.rd-card__body { padding: 20px; }
.rd-card__body--flush { padding: 6px 0; }

.rd-grid {
  display: grid;
  grid-template-columns: 1fr 1fr;
  gap: 22px;
}
@media (max-width: 991px) {
  .rd-grid { grid-template-columns: 1fr; }
}

/* Pipeline */
.rd-pipeline-bar {
  display: flex;
  gap: 4px;
  height: 14px;
  margin-bottom: 18px;
}
.rd-pipeline-seg {
  border-radius: 6px;
  min-width: 6px;
  transition: flex 0.4s ease;
}
.rd-pipeline-legend {
  display: flex;
  flex-wrap: wrap;
  gap: 14px 22px;
}
.rd-legend-item {
  display: inline-flex;
  align-items: center;
  gap: 7px;
  font-size: 13px;
}
.rd-legend-dot {
  width: 10px;
  height: 10px;
  border-radius: 50%;
}
.rd-legend-name { color: #475569; text-transform: capitalize; }
.rd-legend-count { font-weight: 700; color: #1e293b; }

.rd-seg--0 { background: #4361ee; }
.rd-seg--1 { background: #06b6d4; }
.rd-seg--2 { background: #8b5cf6; }
.rd-seg--3 { background: #f59e0b; }
.rd-seg--4 { background: #ec4899; }
.rd-seg--5 { background: #22c55e; }
.rd-seg--6 { background: #ef4444; }

/* Lists */
.rd-list { list-style: none; margin: 0; padding: 0; }
.rd-list__item {
  display: flex;
  align-items: center;
  gap: 14px;
  padding: 12px 20px;
  border-bottom: 1px solid #f4f6fa;
}
.rd-list__item:last-child { border-bottom: none; }
.rd-avatar {
  width: 40px;
  height: 40px;
  border-radius: 12px;
  background: linear-gradient(135deg, #4361ee, #7c3aed);
  color: #fff;
  font-weight: 700;
  font-size: 14px;
  display: flex;
  align-items: center;
  justify-content: center;
  flex-shrink: 0;
}
.rd-avatar--soft {
  background: #eef1ff;
  color: #4361ee;
}
.rd-list__main { flex: 1; min-width: 0; }
.rd-list__name {
  font-weight: 600;
  color: #1e293b;
  white-space: nowrap;
  overflow: hidden;
  text-overflow: ellipsis;
}
.rd-list__sub {
  font-size: 12px;
  color: #94a3b8;
  text-transform: capitalize;
}
.rd-list__meta {
  text-align: right;
  flex-shrink: 0;
}
.rd-date {
  display: block;
  font-size: 11px;
  color: #b0b8c5;
  margin-top: 4px;
}

/* Badges */
.rd-badge {
  display: inline-block;
  padding: 3px 10px;
  border-radius: 20px;
  font-size: 11px;
  font-weight: 700;
  text-transform: capitalize;
  background: #eef2f7;
  color: #475569;
}
.rd-badge--info { background: #e7fbff; color: #0891b2; }
.rd-stage--applied     { background: #eef1ff; color: #4361ee; }
.rd-stage--screening   { background: #e7fbff; color: #0891b2; }
.rd-stage--shortlisted { background: #f3eeff; color: #8b5cf6; }
.rd-stage--interview   { background: #fff5e5; color: #b45309; }
.rd-stage--offered     { background: #fef3c7; color: #92400e; }
.rd-stage--hired       { background: #e9fbef; color: #16a34a; }
.rd-stage--rejected    { background: #fde8e8; color: #dc2626; }

/* Empty */
.rd-empty {
  display: flex;
  flex-direction: column;
  align-items: center;
  gap: 8px;
  padding: 40px 20px;
  color: #b0b8c5;
  font-size: 13px;
}

/* Dark theme */
body.dark-theme .rd-stat,
body.dark-theme .rd-card {
  background: #1e1e2d;
  border-color: #2b2b40;
}
body.dark-theme .rd-stat__value,
body.dark-theme .rd-list__name,
body.dark-theme .rd-card__title,
body.dark-theme .rd-legend-count { color: #e7e9f3; }
body.dark-theme .rd-card__header { border-color: #2b2b40; }
body.dark-theme .rd-list__item { border-color: #2b2b40; }
</style>
