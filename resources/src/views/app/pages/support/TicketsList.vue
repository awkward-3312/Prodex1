<template>
  <div class="main-content tickets-page">
    <breadcumb :page="$t('my_tickets')" :folder="$t('support_center')" />

    <!-- Hero header -->
    <div class="tk-hero mb-4">
      <div class="tk-hero__text">
        <div class="tk-hero__icon">
          <lucide-icon name="life-buoy" />
        </div>
        <div>
          <h1 class="tk-hero__title">{{ $t('my_tickets') }}</h1>
          <p class="tk-hero__subtitle">{{ $t('support_subtitle') }}</p>
        </div>
      </div>
      <router-link class="tk-hero__btn" to="/app/support/tickets/create">
        <lucide-icon name="plus" />
        <span>{{ $t('new_ticket') }}</span>
      </router-link>
    </div>

    <!-- Stat cards (also act as status filters) -->
    <b-row class="mb-4">
      <b-col xl="3" md="6" class="mb-3" v-for="card in statCards" :key="card.key">
        <div
          class="tk-stat"
          :class="['tk-stat--' + card.color, { 'tk-stat--active': Filter_status === card.status }]"
          @click="setFilter(card.status)"
        >
          <div class="tk-stat__icon">
            <lucide-icon :name="card.icon" />
          </div>
          <div class="tk-stat__body">
            <span class="tk-stat__value">{{ stats[card.key] }}</span>
            <span class="tk-stat__label">{{ $t(card.labelKey) }}</span>
          </div>
        </div>
      </b-col>
    </b-row>

    <!-- Tickets card -->
    <div class="tk-card">
      <div class="tk-card__head">
        <div class="tk-filters">
          <button
            type="button"
            class="tk-chip"
            :class="{ 'tk-chip--active': Filter_status === '' }"
            @click="setFilter('')"
          >{{ $t('all') }}</button>
          <button
            v-for="f in statusFilters"
            :key="f.value"
            type="button"
            class="tk-chip"
            :class="['tk-chip--' + f.color, { 'tk-chip--active': Filter_status === f.value }]"
            @click="setFilter(f.value)"
          >{{ $t(f.labelKey) }}</button>
        </div>
      </div>

      <div v-if="isLoading" class="tk-loading">
        <div class="loading_page spinner spinner-primary mr-3"></div>
      </div>

      <div v-else class="tk-table">
        <vue-good-table
          mode="remote"
          :columns="columns"
          :totalRows="totalRows"
          :rows="tickets"
          @on-page-change="onPageChange"
          @on-per-page-change="onPerPageChange"
          @on-search="onSearch"
          :search-options="{ enabled: true, placeholder: $t('search_tickets') }"
          :pagination-options="{ enabled: true, mode: 'records', nextLabel: 'next', prevLabel: 'prev' }"
          styleClass="tableOne table-hover vgt-table"
        >
          <template slot="emptystate">
            <div class="tk-empty">
              <div class="tk-empty__icon">
                <lucide-icon name="inbox" />
              </div>
              <h5 class="tk-empty__title">{{ $t('no_tickets_yet') }}</h5>
              <p class="tk-empty__desc">{{ $t('no_tickets_desc') }}</p>
              <router-link class="tk-hero__btn tk-hero__btn--sm mt-2" to="/app/support/tickets/create">
                <lucide-icon name="plus" />
                <span>{{ $t('new_ticket') }}</span>
              </router-link>
            </div>
          </template>

          <template slot="table-row" slot-scope="props">
            <span v-if="props.column.field == 'ticket_number'">
              <router-link :to="'/app/support/tickets/' + props.row.id" class="tk-ref">
                {{ props.row.ticket_number }}
              </router-link>
              <b-badge v-if="props.row.tenant_unread" variant="danger" class="ml-1 tk-pill">{{ $t('new_reply') }}</b-badge>
            </span>
            <span v-else-if="props.column.field == 'subject'">
              <router-link :to="'/app/support/tickets/' + props.row.id" class="tk-subject">
                {{ props.row.subject }}
              </router-link>
            </span>
            <span v-else-if="props.column.field == 'category'">
              <span class="tk-cat">{{ $t('cat_' + props.row.category) }}</span>
            </span>
            <span v-else-if="props.column.field == 'priority'">
              <span class="tk-tag" :class="'tk-tag--pri-' + props.row.priority">
                <i class="tk-dot"></i>{{ $t('pri_' + props.row.priority) }}
              </span>
            </span>
            <span v-else-if="props.column.field == 'status'">
              <span class="tk-tag" :class="'tk-tag--status-' + props.row.status">
                <i class="tk-dot"></i>{{ $t('status_' + props.row.status) }}
              </span>
            </span>
            <span v-else-if="props.column.field == 'last_reply_at'">
              <span class="tk-date">{{ formatDateTime(props.row.last_reply_at || props.row.created_at) }}</span>
            </span>
            <span v-else-if="props.column.field == 'actions'">
              <router-link :title="$t('view')" v-b-tooltip.hover :to="'/app/support/tickets/' + props.row.id" class="tk-action">
                <lucide-icon name="eye" />
              </router-link>
            </span>
          </template>
        </vue-good-table>
      </div>
    </div>
  </div>
</template>

<script>
import { mapGetters } from "vuex";
import NProgress from "nprogress";

export default {
  metaInfo: { title: "Support Tickets" },
  data() {
    return {
      isLoading: true,
      serverParams: { page: 1, perPage: 10 },
      limit: "10",
      search: "",
      Filter_status: "",
      tickets: [],
      totalRows: 0,
      stats: { open: 0, pending: 0, resolved: 0, closed: 0 }
    };
  },
  computed: {
    ...mapGetters(["currentUserPermissions", "currentUser"]),
    statCards() {
      return [
        { key: "open", status: "open", labelKey: "stat_open", icon: "inbox", color: "primary" },
        { key: "pending", status: "pending", labelKey: "stat_pending", icon: "clock", color: "warning" },
        { key: "resolved", status: "resolved", labelKey: "stat_resolved", icon: "check-circle", color: "success" },
        { key: "closed", status: "closed", labelKey: "stat_closed", icon: "archive", color: "muted" }
      ];
    },
    statusFilters() {
      return [
        { value: "open", labelKey: "status_open", color: "primary" },
        { value: "pending", labelKey: "status_pending", color: "warning" },
        { value: "resolved", labelKey: "status_resolved", color: "success" },
        { value: "closed", labelKey: "status_closed", color: "muted" }
      ];
    },
    columns() {
      return [
        { label: this.$t("ticket_number"), field: "ticket_number", tdClass: "text-left", thClass: "text-left", sortable: false },
        { label: this.$t("subject"), field: "subject", tdClass: "text-left", thClass: "text-left", sortable: false },
        { label: this.$t("category"), field: "category", tdClass: "text-left", thClass: "text-left", sortable: false },
        { label: this.$t("priority"), field: "priority", tdClass: "text-left", thClass: "text-left", sortable: false },
        { label: this.$t("status"), field: "status", tdClass: "text-left", thClass: "text-left", sortable: false },
        { label: this.$t("last_activity"), field: "last_reply_at", tdClass: "text-left", thClass: "text-left", sortable: false },
        { label: this.$t("Action"), field: "actions", tdClass: "text-left", thClass: "text-left", sortable: false }
      ];
    }
  },
  methods: {
    setFilter(status) {
      if (this.Filter_status === status) return;
      this.Filter_status = status;
      this.Get_Tickets(1);
    },
    formatDateTime(value) {
      if (!value) return "";
      const d = new Date(value);
      if (isNaN(d.getTime())) return value;
      const p = n => String(n).padStart(2, "0");
      return `${d.getFullYear()}-${p(d.getMonth() + 1)}-${p(d.getDate())} ${p(d.getHours())}:${p(d.getMinutes())}`;
    },
    onPageChange({ currentPage }) {
      if (this.serverParams.page !== currentPage) {
        this.serverParams.page = currentPage;
        this.Get_Tickets(currentPage);
      }
    },
    onPerPageChange({ currentPerPage }) {
      if (this.limit !== currentPerPage) {
        this.limit = currentPerPage;
        this.serverParams.page = 1;
        this.Get_Tickets(1);
      }
    },
    onSearch(value) {
      this.search = value.searchTerm;
      this.Get_Tickets(1);
    },
    Get_Tickets(page) {
      NProgress.start();
      NProgress.set(0.1);
      this.serverParams.page = page;
      axios
        .get(
          "support/tickets?page=" + page +
          "&status=" + encodeURIComponent(this.Filter_status || "") +
          "&search=" + encodeURIComponent(this.search || "") +
          "&limit=" + this.limit
        )
        .then(response => {
          this.tickets = response.data.tickets;
          this.totalRows = response.data.totalRows;
          this.stats = response.data.stats;
          this.isLoading = false;
          NProgress.done();
        })
        .catch(() => {
          this.isLoading = false;
          NProgress.done();
        });
    }
  },
  created() {
    this.Get_Tickets(1);
  }
};
</script>

<style scoped>
.tickets-page {
  --tk-primary: #5e72e4;
  --tk-warning: #fb6340;
  --tk-success: #2dce89;
  --tk-muted: #8898aa;
}

/* Hero header */
.tk-hero {
  display: flex;
  align-items: center;
  justify-content: space-between;
  gap: 16px;
  flex-wrap: wrap;
  padding: 26px 30px;
  border-radius: 18px;
  background: linear-gradient(120deg, #4254e8 0%, #6a3df0 60%, #8a4ff0 100%);
  box-shadow: 0 12px 30px rgba(80, 70, 220, 0.28);
  color: #fff;
}
.tk-hero__text {
  display: flex;
  align-items: center;
  gap: 18px;
}
.tk-hero__icon {
  width: 56px;
  height: 56px;
  border-radius: 16px;
  background: rgba(255, 255, 255, 0.18);
  display: flex;
  align-items: center;
  justify-content: center;
  flex-shrink: 0;
}
.tk-hero__icon >>> svg { width: 28px; height: 28px; }
.tk-hero__title {
  margin: 0;
  font-size: 22px;
  font-weight: 700;
  line-height: 1.2;
  color: #fff;
}
.tk-hero__subtitle {
  margin: 4px 0 0;
  font-size: 13.5px;
  color: rgba(255, 255, 255, 0.82);
}
.tk-hero__btn {
  display: inline-flex;
  align-items: center;
  gap: 8px;
  padding: 11px 20px;
  border-radius: 11px;
  background: #fff;
  color: #4a37d6;
  font-weight: 600;
  font-size: 14px;
  text-decoration: none;
  transition: transform 0.18s ease, box-shadow 0.18s ease;
  box-shadow: 0 6px 16px rgba(0, 0, 0, 0.12);
}
.tk-hero__btn:hover {
  transform: translateY(-2px);
  color: #4a37d6;
  box-shadow: 0 10px 22px rgba(0, 0, 0, 0.18);
}
.tk-hero__btn >>> svg { width: 18px; height: 18px; }
.tk-hero__btn--sm { padding: 9px 16px; font-size: 13px; }

/* Stat cards */
.tk-stat {
  display: flex;
  align-items: center;
  gap: 16px;
  padding: 20px;
  border-radius: 16px;
  background: #fff;
  border: 1px solid #edf0f5;
  box-shadow: 0 4px 14px rgba(20, 30, 70, 0.05);
  cursor: pointer;
  transition: transform 0.18s ease, box-shadow 0.18s ease, border-color 0.18s ease;
  position: relative;
  overflow: hidden;
}
.tk-stat:hover {
  transform: translateY(-3px);
  box-shadow: 0 12px 26px rgba(20, 30, 70, 0.12);
}
.tk-stat--active {
  border-color: currentColor;
  box-shadow: 0 12px 26px rgba(20, 30, 70, 0.14);
}
.tk-stat__icon {
  width: 52px;
  height: 52px;
  border-radius: 14px;
  display: flex;
  align-items: center;
  justify-content: center;
  flex-shrink: 0;
}
.tk-stat__icon >>> svg { width: 24px; height: 24px; }
.tk-stat__body { display: flex; flex-direction: column; line-height: 1.1; }
.tk-stat__value { font-size: 26px; font-weight: 700; color: #2b3553; }
.tk-stat__label { font-size: 13px; color: #8a94a6; margin-top: 2px; }

.tk-stat--primary { color: var(--tk-primary); }
.tk-stat--primary .tk-stat__icon { background: rgba(94, 114, 228, 0.12); color: var(--tk-primary); }
.tk-stat--warning { color: var(--tk-warning); }
.tk-stat--warning .tk-stat__icon { background: rgba(251, 99, 64, 0.12); color: var(--tk-warning); }
.tk-stat--success { color: var(--tk-success); }
.tk-stat--success .tk-stat__icon { background: rgba(45, 206, 137, 0.12); color: var(--tk-success); }
.tk-stat--muted { color: var(--tk-muted); }
.tk-stat--muted .tk-stat__icon { background: rgba(136, 152, 170, 0.14); color: var(--tk-muted); }

/* Table card */
.tk-card {
  background: #fff;
  border-radius: 16px;
  border: 1px solid #edf0f5;
  box-shadow: 0 4px 14px rgba(20, 30, 70, 0.05);
  overflow: hidden;
}
.tk-card__head {
  padding: 16px 20px;
  border-bottom: 1px solid #f0f2f7;
}
.tk-filters { display: flex; flex-wrap: wrap; gap: 8px; }
.tk-chip {
  border: 1px solid #e2e6ef;
  background: #f7f8fc;
  color: #5b6478;
  padding: 6px 14px;
  border-radius: 20px;
  font-size: 13px;
  font-weight: 600;
  cursor: pointer;
  transition: all 0.15s ease;
}
.tk-chip:hover { background: #eef0f7; }
.tk-chip--active { color: #fff; border-color: transparent; background: var(--tk-primary); }
.tk-chip--active.tk-chip--warning { background: var(--tk-warning); }
.tk-chip--active.tk-chip--success { background: var(--tk-success); }
.tk-chip--active.tk-chip--muted { background: var(--tk-muted); }

.tk-loading { padding: 60px 0; display: flex; justify-content: center; }
.tk-table { padding: 6px 12px 12px; }

/* Cell styling */
.tk-ref { font-weight: 700; color: var(--tk-primary); text-decoration: none; }
.tk-ref:hover { text-decoration: underline; }
.tk-subject { color: #2b3553; font-weight: 500; text-decoration: none; }
.tk-subject:hover { color: var(--tk-primary); }
.tk-cat { color: #5b6478; font-size: 13px; }
.tk-date { color: #8a94a6; font-size: 13px; }
.tk-pill { font-weight: 600; border-radius: 12px; }

.tk-tag {
  display: inline-flex;
  align-items: center;
  gap: 6px;
  padding: 4px 11px;
  border-radius: 20px;
  font-size: 12.5px;
  font-weight: 600;
}
.tk-dot { width: 7px; height: 7px; border-radius: 50%; background: currentColor; display: inline-block; }
.tk-tag--status-open { background: rgba(245, 54, 92, 0.12); color: #f5365c; }
.tk-tag--status-pending { background: rgba(251, 99, 64, 0.12); color: #fb6340; }
.tk-tag--status-resolved { background: rgba(45, 206, 137, 0.12); color: #2dce89; }
.tk-tag--status-closed { background: rgba(136, 152, 170, 0.14); color: #8898aa; }
.tk-tag--pri-urgent { background: rgba(245, 54, 92, 0.12); color: #f5365c; }
.tk-tag--pri-high { background: rgba(251, 99, 64, 0.12); color: #fb6340; }
.tk-tag--pri-medium { background: rgba(17, 205, 239, 0.12); color: #11cdef; }
.tk-tag--pri-low { background: rgba(45, 206, 137, 0.12); color: #2dce89; }

.tk-action {
  display: inline-flex;
  align-items: center;
  justify-content: center;
  width: 34px;
  height: 34px;
  border-radius: 9px;
  background: rgba(94, 114, 228, 0.1);
  color: var(--tk-primary);
  transition: all 0.15s ease;
}
.tk-action:hover { background: var(--tk-primary); color: #fff; }
.tk-action >>> svg { width: 18px; height: 18px; }

/* Empty state */
.tk-empty { text-align: center; padding: 50px 20px; }
.tk-empty__icon {
  width: 72px;
  height: 72px;
  margin: 0 auto 16px;
  border-radius: 50%;
  background: rgba(94, 114, 228, 0.1);
  color: var(--tk-primary);
  display: flex;
  align-items: center;
  justify-content: center;
}
.tk-empty__icon >>> svg { width: 34px; height: 34px; }
.tk-empty__title { color: #2b3553; font-weight: 600; margin-bottom: 6px; }
.tk-empty__desc { color: #8a94a6; font-size: 14px; margin-bottom: 0; }
</style>
