<template>
  <div class="main-content">
    <breadcumb :page="$t('Calendar')" :folder="$t('Meeting_Management')" />

    <b-card class="mt-cal-card">
      <div class="mt-cal-toolbar">
        <div class="mt-cal-nav">
          <b-button size="sm" variant="outline-secondary" @click="prevMonth()"><lucide-icon name="chevron-left" size="16" /></b-button>
          <b-button size="sm" variant="outline-secondary" class="mx-1" @click="today()">{{ $t('Today') }}</b-button>
          <b-button size="sm" variant="outline-secondary" @click="nextMonth()"><lucide-icon name="chevron-right" size="16" /></b-button>
        </div>
        <h5 class="mt-cal-title mb-0">{{ monthLabel }}</h5>
        <router-link to="/app/meeting/meetings" class="btn btn-primary btn-sm">
          <lucide-icon name="plus" size="14" /> {{ $t('New_Meeting') }}
        </router-link>
      </div>

      <div v-if="isLoading" class="loading_page spinner spinner-primary mr-3"></div>

      <div v-else class="mt-cal-grid">
        <div class="mt-cal-weekday" v-for="d in weekdays" :key="d">{{ d }}</div>
        <div
          v-for="(cell, idx) in cells"
          :key="idx"
          class="mt-cal-cell"
          :class="{ 'mt-cal-cell--out': !cell.inMonth, 'mt-cal-cell--today': cell.isToday }"
        >
          <div class="mt-cal-daynum">{{ cell.day }}</div>
          <div class="mt-cal-events">
            <a
              v-for="m in cell.meetings"
              :key="m.id"
              class="mt-cal-event"
              :class="'mt-ev--' + m.status"
              @click="goTo(m.id)"
              v-b-tooltip.hover
              :title="m.title + ' · ' + short_time(m.start_time)"
            >
              <span class="mt-ev-dot" :class="m.type === 'online' ? 'mt-ev-dot--online' : ''"></span>
              {{ short_time(m.start_time) }} {{ m.title }}
            </a>
          </div>
        </div>
      </div>
    </b-card>
  </div>
</template>

<script>
import moment from "moment";

export default {
  metaInfo: { title: "Meeting Calendar" },
  data() {
    return {
      isLoading: true,
      cursor: moment().startOf("month"),
      meetings: []
    };
  },
  computed: {
    monthLabel() {
      return this.cursor.format("MMMM YYYY");
    },
    weekdays() {
      return moment.weekdaysShort();
    },
    cells() {
      const start = this.cursor.clone().startOf("month").startOf("week");
      const end = this.cursor.clone().endOf("month").endOf("week");
      const todayStr = moment().format("YYYY-MM-DD");
      const cells = [];
      const day = start.clone();
      while (day.isSameOrBefore(end, "day")) {
        const dateStr = day.format("YYYY-MM-DD");
        cells.push({
          day: day.date(),
          inMonth: day.month() === this.cursor.month(),
          isToday: dateStr === todayStr,
          meetings: this.meetings.filter(m => String(m.meeting_date).substring(0, 10) === dateStr)
        });
        day.add(1, "day");
      }
      return cells;
    }
  },
  methods: {
    short_time(t) {
      return t ? String(t).substring(0, 5) : "";
    },
    goTo(id) {
      this.$router.push({ name: "meeting_details", params: { id: id } });
    },
    prevMonth() {
      this.cursor = this.cursor.clone().subtract(1, "month");
      this.Get_Meetings();
    },
    nextMonth() {
      this.cursor = this.cursor.clone().add(1, "month");
      this.Get_Meetings();
    },
    today() {
      this.cursor = moment().startOf("month");
      this.Get_Meetings();
    },
    Get_Meetings() {
      this.isLoading = true;
      const from = this.cursor.clone().startOf("month").startOf("week").format("YYYY-MM-DD");
      const to = this.cursor.clone().endOf("month").endOf("week").format("YYYY-MM-DD");
      axios
        .get("meeting/calendar?from=" + from + "&to=" + to)
        .then(({ data }) => {
          this.meetings = data.meetings || [];
          this.isLoading = false;
        })
        .catch(() => {
          this.isLoading = false;
        });
    }
  },
  created() {
    this.Get_Meetings();
  }
};
</script>

<style scoped>
.mt-cal-toolbar {
  display: flex; align-items: center; justify-content: space-between;
  margin-bottom: 16px; flex-wrap: wrap; gap: 10px;
}
.mt-cal-title { font-weight: 700; }
.mt-cal-grid {
  display: grid;
  grid-template-columns: repeat(7, 1fr);
  gap: 4px;
}
.mt-cal-weekday {
  text-align: center; font-size: 12px; font-weight: 700;
  color: #94a3b8; padding: 6px 0; text-transform: uppercase;
}
.mt-cal-cell {
  min-height: 104px; border: 1px solid #eef1f6; border-radius: 8px;
  padding: 4px 5px; background: #fff; overflow: hidden;
}
.mt-cal-cell--out { background: #f8fafc; }
.mt-cal-cell--out .mt-cal-daynum { color: #cbd5e1; }
.mt-cal-cell--today { border-color: #4361ee; box-shadow: inset 0 0 0 1px #4361ee; }
.mt-cal-daynum { font-size: 12px; font-weight: 600; color: #475569; text-align: right; }
.mt-cal-events { display: flex; flex-direction: column; gap: 2px; margin-top: 2px; }
.mt-cal-event {
  font-size: 11px; padding: 2px 5px; border-radius: 5px; cursor: pointer;
  white-space: nowrap; overflow: hidden; text-overflow: ellipsis;
  background: #eef1ff; color: #3b4cca; display: flex; align-items: center; gap: 4px;
}
.mt-cal-event:hover { filter: brightness(0.96); }
.mt-ev--completed { background: #e9fbef; color: #16a34a; }
.mt-ev--cancelled { background: #fde8e8; color: #dc2626; text-decoration: line-through; }
.mt-ev--ongoing { background: #fff5e5; color: #b45309; }
.mt-ev-dot { width: 7px; height: 7px; border-radius: 50%; background: #4361ee; flex-shrink: 0; }
.mt-ev-dot--online { background: #06b6d4; }
body.dark-theme .mt-cal-cell { background: #1e1e2d; border-color: #2b2b40; }
body.dark-theme .mt-cal-cell--out { background: #181824; }
body.dark-theme .mt-cal-daynum { color: #aeb4c2; }
</style>
