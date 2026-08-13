<template>
  <div class="main-content kitchen-page">
    <!-- Page header -->
    <div class="kitchen-header">
      <div class="kh-title">
        <div class="kh-icon">
          <lucide-icon name="chef-hat" :size="26" />
        </div>
        <div>
          <h1>{{ $t('KitchenDisplay') || 'Kitchen Display' }}</h1>
          <p class="kh-sub">{{ $t('Kitchen') || 'Kitchen' }} · {{ today }}</p>
        </div>
      </div>

      <div class="kh-tools">
        <div class="live-pill" :class="{ on: autoRefresh }">
          <span class="dot"></span>
          {{ autoRefresh ? ($t('Live') || 'Live') : ($t('Paused') || 'Paused') }}
        </div>

        <b-button class="kh-btn" size="sm" variant="light" @click="autoRefresh = !autoRefresh; toggleAutoRefresh()">
          <lucide-icon :name="autoRefresh ? 'pause' : 'play'" :size="15" />
        </b-button>

        <b-button class="kh-btn" size="sm" variant="light" :disabled="refreshing" @click="fetch(true)">
          <lucide-icon name="rotate-cw" :size="15" :class="{ spin: refreshing }" />
          <span class="d-none d-md-inline ml-1">{{ $t('Refresh') || 'Refresh' }}</span>
        </b-button>
      </div>
    </div>

    <!-- Stat chips -->
    <div class="kitchen-stats">
      <div
        v-for="col in columns"
        :key="'stat-' + col.key"
        class="stat-chip"
        :class="'sc-' + col.key"
      >
        <div class="sc-ic"><lucide-icon :name="col.icon" :size="18" /></div>
        <div class="sc-meta">
          <span class="sc-count">{{ (grouped[col.key] || []).length }}</span>
          <span class="sc-label">{{ col.label }}</span>
        </div>
      </div>
    </div>

    <!-- Toolbar / filters -->
    <div class="kitchen-toolbar">
      <div class="kt-search">
        <lucide-icon name="search" :size="16" class="kt-search-ic" />
        <input
          v-model="search"
          :placeholder="$t('Search') || 'Search'"
          @keyup.enter="reload"
        />
      </div>
      <b-form-datepicker v-model="dateFrom" :placeholder="$t('From') || 'From'" reset-button size="sm" class="kt-date" @input="reload" />
      <b-form-datepicker v-model="dateTo" :placeholder="$t('To') || 'To'" reset-button size="sm" class="kt-date" @input="reload" />
      <b-button size="sm" variant="outline-secondary" class="kt-clear" @click="clearFilters">
        <lucide-icon name="x" :size="15" /> {{ $t('Clear') || 'Clear' }}
      </b-button>
    </div>

    <!-- Board -->
    <div class="kitchen-board">
      <div
        v-for="col in columns"
        :key="col.key"
        class="kitchen-column"
        :class="'kc-' + col.key"
      >
        <div class="kitchen-column-header" :class="'kch-' + col.key">
          <span class="kch-left">
            <lucide-icon :name="col.icon" :size="16" />
            {{ col.label }}
          </span>
          <span class="kch-count">{{ (grouped[col.key] || []).length }}</span>
        </div>

        <div class="kitchen-column-body">
          <!-- Skeleton on first load -->
          <template v-if="isLoading && !firstLoadDone">
            <div v-for="n in 2" :key="'sk-' + col.key + '-' + n" class="skeleton-card">
              <div class="sk-line w60"></div>
              <div class="sk-line w40"></div>
              <div class="sk-line w80"></div>
              <div class="sk-line w70"></div>
            </div>
          </template>

          <!-- Empty -->
          <div
            v-else-if="!(grouped[col.key] || []).length"
            class="empty-state"
          >
            <lucide-icon name="inbox" :size="26" />
            <span>{{ $t('NoOrders') || 'No orders' }}</span>
          </div>

          <!-- Tickets -->
          <transition-group name="ticket" tag="div" v-else>
            <div
              v-for="order in (grouped[col.key] || [])"
              :key="order.id"
              class="kitchen-ticket"
            >
              <!-- busy overlay -->
              <div v-if="busyId === order.id" class="ticket-busy">
                <span class="spinner-border spinner-border-sm"></span>
              </div>

              <div class="ticket-top">
                <strong class="ticket-ref">{{ order.ref || ('#' + order.sale_id) }}</strong>
                <span class="ticket-time" :title="order.created_at">
                  <lucide-icon name="clock" :size="12" /> {{ elapsed(order) }}
                </span>
              </div>

              <div class="ticket-customer">
                <lucide-icon name="user" :size="13" class="ic" />
                {{ order.customer_name || ($t('Walkin_Customer') || 'Walk-in Customer') }}
              </div>

              <ul class="ticket-items">
                <li v-for="item in order.items" :key="item.id">
                  <span class="qty">{{ formatQty(item.quantity) }}<small v-if="item.unit"> {{ item.unit }}</small></span>
                  <span class="nm">{{ item.name }}</span>
                </li>
              </ul>

              <div v-if="order.instructions" class="ticket-note">
                <lucide-icon name="sticky-note" :size="13" class="ic" />
                <span>{{ order.instructions }}</span>
              </div>

              <div v-if="order.assigned_name" class="ticket-staff">
                <lucide-icon name="chef-hat" :size="13" class="ic" />
                {{ order.assigned_name }}
              </div>

              <!-- Dispatch to warehouse (completed only, display-only) -->
              <div v-if="col.key === 'completed'" class="ticket-dispatch">
                <div v-if="order.dispatched_warehouse_id" class="dispatch-badge">
                  <lucide-icon name="warehouse" :size="13" />
                  {{ $t('SentToWarehouse') || 'Sent to' }} {{ order.dispatched_warehouse_name }}
                </div>
                <select
                  v-if="canManage"
                  class="dispatch-select"
                  :value="order.dispatched_warehouse_id || ''"
                  :disabled="busyId === order.id"
                  @change="dispatchToWarehouse(order, $event.target.value)"
                >
                  <option value="" disabled>
                    {{ order.dispatched_warehouse_id ? ($t('ChangeWarehouse') || 'Change warehouse…') : ($t('SendToWarehouse') || 'Send to warehouse…') }}
                  </option>
                  <option v-for="w in warehouses" :key="w.id" :value="w.id">{{ w.name }}</option>
                </select>
              </div>

              <!-- Actions -->
              <div class="ticket-actions">
                <template v-if="canManage">
                  <button
                    v-if="col.key === 'pending' || col.key === 'on_hold'"
                    class="t-btn t-primary"
                    :disabled="busyId === order.id"
                    @click="setStatus(order, 'preparing')"
                  >
                    <lucide-icon name="play" :size="13" /> {{ $t('StartPreparation') || 'Start' }}
                  </button>

                  <button
                    v-if="col.key === 'preparing'"
                    class="t-btn t-success"
                    :disabled="busyId === order.id"
                    @click="setStatus(order, 'completed')"
                  >
                    <lucide-icon name="check-circle" :size="13" /> {{ $t('MarkCompleted') || 'Complete' }}
                  </button>

                  <button
                    v-if="col.key === 'pending' || col.key === 'preparing'"
                    class="t-btn t-warning"
                    :disabled="busyId === order.id"
                    @click="setStatus(order, 'on_hold')"
                  >
                    <lucide-icon name="pause" :size="13" /> {{ $t('PutOnHold') || 'Hold' }}
                  </button>

                  <button
                    v-if="col.key === 'completed'"
                    class="t-btn t-ghost"
                    :disabled="busyId === order.id"
                    @click="setStatus(order, 'preparing')"
                  >
                    <lucide-icon name="rotate-cw" :size="13" /> {{ $t('Reopen') || 'Reopen' }}
                  </button>
                </template>

                <button class="t-btn t-ghost t-icon" @click="openDetails(order)" :title="$t('Details') || 'Details'">
                  <lucide-icon name="eye" :size="14" />
                </button>
              </div>
            </div>
          </transition-group>
        </div>
      </div>
    </div>

    <!-- Details modal -->
    <b-modal v-model="detailsOpen" :title="$t('OrderDetails') || 'Order Details'" hide-footer size="lg" centered>
      <div v-if="selected" class="km-details">
        <div class="km-grid">
          <div class="km-cell">
            <span class="km-k">{{ $t('Order') || 'Order' }}</span>
            <span class="km-v">{{ selected.ref || ('#' + selected.sale_id) }}</span>
          </div>
          <div class="km-cell">
            <span class="km-k">{{ $t('Status') || 'Status' }}</span>
            <b-badge :variant="statusVariant(selected.status)" class="km-badge">{{ statusLabel(selected.status) }}</b-badge>
          </div>
          <div class="km-cell">
            <span class="km-k">{{ $t('Customer') || 'Customer' }}</span>
            <span class="km-v">{{ selected.customer_name || ($t('Walkin_Customer') || 'Walk-in Customer') }}</span>
          </div>
          <div class="km-cell">
            <span class="km-k">{{ $t('Date') || 'Date' }}</span>
            <span class="km-v">{{ selected.created_at }}</span>
          </div>
          <div v-if="selected.dispatched_warehouse_id" class="km-cell">
            <span class="km-k">{{ $t('SentToWarehouse') || 'Sent to' }}</span>
            <span class="km-v">
              <lucide-icon name="warehouse" :size="14" /> {{ selected.dispatched_warehouse_name }}
            </span>
          </div>
        </div>

        <b-table-simple small responsive class="km-table mt-3">
          <b-thead>
            <b-tr>
              <b-th>{{ $t('Product') || 'Product' }}</b-th>
              <b-th class="text-right">{{ $t('Quantity') || 'Quantity' }}</b-th>
            </b-tr>
          </b-thead>
          <b-tbody>
            <b-tr v-for="item in selected.items" :key="item.id">
              <b-td>{{ item.name }}</b-td>
              <b-td class="text-right">{{ formatQty(item.quantity) }}<span v-if="item.unit"> {{ item.unit }}</span></b-td>
            </b-tr>
          </b-tbody>
        </b-table-simple>

        <div v-if="selected.instructions" class="km-note">
          <strong>{{ $t('SpecialInstructions') || 'Special Instructions' }}</strong>
          <p>{{ selected.instructions }}</p>
        </div>

        <div v-if="canManage" class="km-assign">
          <label>{{ $t('AssignedStaff') || 'Assigned Staff' }}</label>
          <div class="d-flex">
            <b-form-select v-model="assignChoice" :options="staffOptions" class="mr-2" />
            <b-button variant="primary" :disabled="assignBusy" @click="saveAssignment">
              <span v-if="assignBusy" class="spinner-border spinner-border-sm"></span>
              <span v-else>{{ $t('Save') || 'Save' }}</span>
            </b-button>
          </div>
        </div>
      </div>
    </b-modal>
  </div>
</template>

<script>
import { mapGetters } from "vuex";

export default {
  metaInfo: { title: "Kitchen Display" },
  data() {
    return {
      isLoading: true,
      refreshing: false,
      grouped: { pending: [], preparing: [], completed: [], on_hold: [] },
      search: "",
      dateFrom: null,
      dateTo: null,
      busyId: null,

      autoRefresh: true,
      pollTimer: null,
      clockTimer: null,
      nowTs: Date.now(),
      lastLatestId: 0,
      firstLoadDone: false,

      detailsOpen: false,
      selected: null,
      assignChoice: null,
      assignBusy: false,
      staff: [],
      warehouses: [],
    };
  },

  computed: {
    ...mapGetters(["currentUser", "currentUserPermissions"]),
    canManage() {
      return this.currentUserPermissions && this.currentUserPermissions.includes("kitchen_display_manage");
    },
    today() {
      try {
        return new Date(this.nowTs).toLocaleDateString(undefined, { weekday: "long", year: "numeric", month: "short", day: "numeric" });
      } catch (e) {
        return "";
      }
    },
    columns() {
      return [
        { key: "pending", label: this.$t("PendingOrders") || "Pending", icon: "clock" },
        { key: "preparing", label: this.$t("PreparingOrders") || "Preparing", icon: "chef-hat" },
        { key: "completed", label: this.$t("CompletedOrders") || "Completed", icon: "check-circle" },
        { key: "on_hold", label: this.$t("OnHoldOrders") || "On Hold", icon: "pause" },
      ];
    },
    staffOptions() {
      const opts = [{ value: null, text: this.$t("Unassigned") || "—" }];
      this.staff.forEach((u) => {
        opts.push({ value: u.id, text: this.staffName(u) });
      });
      return opts;
    },
  },

  mounted() {
    this.fetch();
    this.loadStaff();
    this.loadWarehouses();
    this.toggleAutoRefresh();
    this.clockTimer = setInterval(() => { this.nowTs = Date.now(); }, 30000);
  },

  beforeDestroy() {
    this.stopPolling();
    if (this.clockTimer) clearInterval(this.clockTimer);
  },

  methods: {
    _params() {
      return {
        q: this.search || "",
        from: this.toDateStr(this.dateFrom),
        to: this.toDateStr(this.dateTo),
      };
    },
    toDateStr(d) {
      if (!d) return "";
      const dt = d instanceof Date ? d : new Date(d);
      if (isNaN(dt.getTime())) return "";
      const y = dt.getFullYear();
      const m = ("0" + (dt.getMonth() + 1)).slice(-2);
      const day = ("0" + dt.getDate()).slice(-2);
      return y + "-" + m + "-" + day;
    },
    shortTime(s) {
      if (!s) return "";
      const dt = new Date(s.replace(" ", "T"));
      if (isNaN(dt.getTime())) return s;
      return dt.toLocaleString();
    },
    elapsed(order) {
      const raw = order.sent_at || order.created_at;
      if (!raw) return "";
      const dt = new Date(String(raw).replace(" ", "T"));
      if (isNaN(dt.getTime())) return "";
      let mins = Math.max(0, Math.floor((this.nowTs - dt.getTime()) / 60000));
      if (mins < 1) return this.$t("Now") || "now";
      if (mins < 60) return mins + "m";
      const h = Math.floor(mins / 60);
      const m = mins % 60;
      return h + "h" + (m ? " " + m + "m" : "");
    },
    formatQty(n) {
      const num = Number(n || 0);
      return Number.isInteger(num) ? String(num) : num.toFixed(3).replace(/0+$/, "").replace(/\.$/, "");
    },
    statusLabel(s) {
      return {
        pending: this.$t("PendingOrders") || "Pending",
        preparing: this.$t("PreparingOrders") || "Preparing",
        completed: this.$t("CompletedOrders") || "Completed",
        on_hold: this.$t("OnHoldOrders") || "On Hold",
      }[s] || s;
    },
    statusVariant(s) {
      return { pending: "secondary", preparing: "primary", completed: "success", on_hold: "warning" }[s] || "secondary";
    },
    staffName(u) {
      const name = ((u.firstname || "") + " " + (u.lastname || "")).trim();
      return name || u.username || u.email;
    },

    async fetch(showSpinner = false) {
      if (showSpinner) this.refreshing = true;
      if (!this.firstLoadDone) this.isLoading = true;
      try {
        const resp = await axios.get("/kitchen/orders", {
          params: this._params(),
          validateStatus: () => true,
        });
        if (resp && resp.status < 400 && resp.data && resp.data.grouped) {
          this.grouped = resp.data.grouped;
          if (Array.isArray(resp.data.warehouses) && resp.data.warehouses.length) {
            this.warehouses = resp.data.warehouses;
          }
        }
      } catch (e) {
        // network errors keep the last good board state
      } finally {
        this.isLoading = false;
        this.refreshing = false;
        this.firstLoadDone = true;
      }
    },

    async loadStaff() {
      try {
        const resp = await axios.get("/users_list_for_select", { validateStatus: () => true });
        if (resp && resp.data && resp.data.users) this.staff = resp.data.users;
      } catch (e) {
        /* optional, ignore */
      }
    },

    async loadWarehouses() {
      try {
        const resp = await axios.get("/kitchen/warehouses", { validateStatus: () => true });
        if (resp && resp.data && resp.data.warehouses) this.warehouses = resp.data.warehouses;
      } catch (e) {
        /* optional, ignore */
      }
    },

    async dispatchToWarehouse(order, warehouseId) {
      if (!warehouseId) return;
      this.busyId = order.id;
      try {
        const resp = await axios.patch(
          "/kitchen/orders/" + order.id + "/dispatch",
          { dispatched_warehouse_id: warehouseId },
          { validateStatus: () => true }
        );
        if (resp && resp.status < 400) {
          await this.fetch(false);
          const wh = this.warehouses.find((w) => String(w.id) === String(warehouseId));
          if (this.$bvToast) {
            this.$bvToast.toast(
              (this.$t("SentToWarehouse") || "Sent to") + " " + (wh ? wh.name : ""),
              { title: this.$t("Success") || "Success", variant: "success", solid: true }
            );
          }
        } else {
          this.toastError(resp);
        }
      } catch (e) {
        this.toastError(null);
      } finally {
        this.busyId = null;
      }
    },

    reload() {
      this.fetch(true);
    },
    clearFilters() {
      this.search = "";
      this.dateFrom = null;
      this.dateTo = null;
      this.fetch(true);
    },

    toggleAutoRefresh() {
      this.stopPolling();
      if (this.autoRefresh) {
        this.pollTimer = setInterval(this.poll, 4000);
      }
    },
    stopPolling() {
      if (this.pollTimer) {
        clearInterval(this.pollTimer);
        this.pollTimer = null;
      }
    },
    async poll() {
      try {
        const resp = await axios.get("/kitchen/orders/poll", { validateStatus: () => true });
        if (!resp || resp.status >= 400 || !resp.data) return;
        const latest = Number(resp.data.latest_id || 0);
        await this.fetch(false);
        if (this.lastLatestId && latest > this.lastLatestId) {
          this.notifyNewOrder(latest - this.lastLatestId);
        }
        this.lastLatestId = latest || this.lastLatestId;
      } catch (e) {
        /* ignore poll errors */
      }
    },
    notifyNewOrder(count) {
      if (this.$bvToast) {
        this.$bvToast.toast(
          (this.$t("NewKitchenOrders") || "New kitchen order(s)") + (count > 1 ? " (" + count + ")" : ""),
          { title: this.$t("Kitchen") || "Kitchen", variant: "info", solid: true, autoHideDelay: 4000 }
        );
      }
      this.playSound();
    },
    playSound() {
      try {
        const AudioCtx = window.AudioContext || window.webkitAudioContext;
        if (!AudioCtx) return;
        const ctx = new AudioCtx();
        const osc = ctx.createOscillator();
        const gain = ctx.createGain();
        osc.connect(gain);
        gain.connect(ctx.destination);
        osc.frequency.value = 880;
        gain.gain.setValueAtTime(0.15, ctx.currentTime);
        osc.start();
        osc.stop(ctx.currentTime + 0.18);
      } catch (e) {
        /* audio not available */
      }
    },

    async setStatus(order, status) {
      this.busyId = order.id;
      try {
        const resp = await axios.patch("/kitchen/orders/" + order.id + "/status", { status }, { validateStatus: () => true });
        if (resp && resp.status < 400) {
          await this.fetch(false);
          if (this.$bvToast) {
            this.$bvToast.toast(this.$t("Successfully_Updated") || "Updated", {
              title: this.$t("Success") || "Success",
              variant: "success",
              solid: true,
            });
          }
        } else {
          this.toastError(resp);
        }
      } catch (e) {
        this.toastError(null);
      } finally {
        this.busyId = null;
      }
    },

    openDetails(order) {
      this.selected = order;
      this.assignChoice = order.assigned_to || null;
      this.detailsOpen = true;
    },

    async saveAssignment() {
      if (!this.selected) return;
      this.assignBusy = true;
      try {
        const resp = await axios.patch(
          "/kitchen/orders/" + this.selected.id + "/assign",
          { assigned_to: this.assignChoice },
          { validateStatus: () => true }
        );
        if (resp && resp.status < 400) {
          await this.fetch(false);
          if (resp.data && resp.data.order) this.selected = resp.data.order;
          if (this.$bvToast) {
            this.$bvToast.toast(this.$t("Successfully_Updated") || "Updated", {
              title: this.$t("Success") || "Success",
              variant: "success",
              solid: true,
            });
          }
        } else {
          this.toastError(resp);
        }
      } catch (e) {
        this.toastError(null);
      } finally {
        this.assignBusy = false;
      }
    },

    toastError(resp) {
      const msg =
        (resp && resp.data && (resp.data.error || resp.data.message)) ||
        this.$t("Network_error") ||
        "Error";
      if (this.$bvToast) {
        this.$bvToast.toast(String(msg), { title: this.$t("Failed") || "Failed", variant: "danger", solid: true });
      }
    },
  },
};
</script>

<style scoped>
.kitchen-page {
  --kc-pending: #64748b;
  --kc-preparing: #3b82f6;
  --kc-completed: #22c55e;
  --kc-on_hold: #f59e0b;
}

/* ---------- Header ---------- */
.kitchen-header {
  display: flex;
  justify-content: space-between;
  align-items: center;
  flex-wrap: wrap;
  gap: 12px;
  margin-bottom: 18px;
}
.kh-title {
  display: flex;
  align-items: center;
  gap: 14px;
}
.kh-icon {
  width: 52px;
  height: 52px;
  border-radius: 14px;
  display: flex;
  align-items: center;
  justify-content: center;
  color: #fff;
  background: linear-gradient(135deg, #6366f1, #8b5cf6);
  box-shadow: 0 8px 20px -6px rgba(99, 102, 241, 0.6);
}
.kh-title h1 {
  font-size: 1.5rem;
  font-weight: 700;
  margin: 0;
  line-height: 1.1;
}
.kh-sub {
  margin: 2px 0 0;
  color: #94a3b8;
  font-size: 0.82rem;
}
.kh-tools {
  display: flex;
  align-items: center;
  gap: 8px;
}
.kh-btn {
  border-radius: 10px !important;
  display: inline-flex;
  align-items: center;
  box-shadow: 0 1px 2px rgba(0, 0, 0, 0.06);
}
.live-pill {
  display: inline-flex;
  align-items: center;
  gap: 6px;
  font-size: 0.75rem;
  font-weight: 600;
  padding: 5px 12px;
  border-radius: 999px;
  background: #f1f5f9;
  color: #94a3b8;
}
.live-pill.on {
  background: rgba(34, 197, 94, 0.12);
  color: #16a34a;
}
.live-pill .dot {
  width: 8px;
  height: 8px;
  border-radius: 50%;
  background: currentColor;
}
.live-pill.on .dot {
  animation: pulse 1.4s infinite;
}
@keyframes pulse {
  0% { box-shadow: 0 0 0 0 rgba(34, 197, 94, 0.5); }
  70% { box-shadow: 0 0 0 6px rgba(34, 197, 94, 0); }
  100% { box-shadow: 0 0 0 0 rgba(34, 197, 94, 0); }
}

/* ---------- Stat chips ---------- */
.kitchen-stats {
  display: grid;
  grid-template-columns: repeat(4, 1fr);
  gap: 12px;
  margin-bottom: 16px;
}
.stat-chip {
  display: flex;
  align-items: center;
  gap: 12px;
  background: #fff;
  border: 1px solid #eef2f7;
  border-radius: 14px;
  padding: 12px 16px;
  box-shadow: 0 1px 3px rgba(0, 0, 0, 0.04);
}
.sc-ic {
  width: 40px;
  height: 40px;
  border-radius: 10px;
  display: flex;
  align-items: center;
  justify-content: center;
  color: #fff;
}
.sc-pending .sc-ic { background: var(--kc-pending); }
.sc-preparing .sc-ic { background: var(--kc-preparing); }
.sc-completed .sc-ic { background: var(--kc-completed); }
.sc-on_hold .sc-ic { background: var(--kc-on_hold); }
.sc-meta { display: flex; flex-direction: column; line-height: 1.1; }
.sc-count { font-size: 1.4rem; font-weight: 700; }
.sc-label { font-size: 0.78rem; color: #94a3b8; }

/* ---------- Toolbar ---------- */
.kitchen-toolbar {
  display: flex;
  flex-wrap: wrap;
  align-items: center;
  gap: 10px;
  margin-bottom: 18px;
}
.kt-search {
  position: relative;
  flex: 1 1 240px;
  max-width: 320px;
}
.kt-search-ic {
  position: absolute;
  left: 12px;
  top: 50%;
  transform: translateY(-50%);
  color: #94a3b8;
}
.kt-search input {
  width: 100%;
  border: 1px solid #e2e8f0;
  border-radius: 10px;
  padding: 8px 12px 8px 34px;
  font-size: 0.88rem;
  outline: none;
  transition: border-color 0.15s, box-shadow 0.15s;
}
.kt-search input:focus {
  border-color: #6366f1;
  box-shadow: 0 0 0 3px rgba(99, 102, 241, 0.15);
}
.kt-date { max-width: 190px; }
.kt-clear { border-radius: 10px !important; }

/* ---------- Board ---------- */
.kitchen-board {
  display: grid;
  grid-template-columns: repeat(4, minmax(240px, 1fr));
  gap: 16px;
}
@media (max-width: 1100px) {
  .kitchen-board { grid-template-columns: repeat(2, minmax(240px, 1fr)); }
  .kitchen-stats { grid-template-columns: repeat(2, 1fr); }
}
@media (max-width: 600px) {
  .kitchen-board { grid-template-columns: 1fr; }
  .kitchen-stats { grid-template-columns: 1fr 1fr; }
}
.kitchen-column {
  background: #f8fafc;
  border: 1px solid #eef2f7;
  border-radius: 16px;
  padding: 10px;
  min-height: 220px;
  display: flex;
  flex-direction: column;
}
.kitchen-column-header {
  display: flex;
  justify-content: space-between;
  align-items: center;
  font-weight: 600;
  font-size: 0.9rem;
  padding: 9px 12px;
  border-radius: 11px;
  color: #fff;
  margin-bottom: 12px;
}
.kch-left { display: inline-flex; align-items: center; gap: 7px; }
.kch-count {
  background: rgba(255, 255, 255, 0.28);
  border-radius: 999px;
  font-size: 0.75rem;
  min-width: 22px;
  text-align: center;
  padding: 1px 7px;
}
.kch-pending { background: var(--kc-pending); }
.kch-preparing { background: var(--kc-preparing); }
.kch-completed { background: var(--kc-completed); }
.kch-on_hold { background: var(--kc-on_hold); }
.kitchen-column-body {
  flex: 1;
  overflow-y: auto;
  max-height: calc(100vh - 360px);
  padding-right: 2px;
}

/* ---------- Ticket card ---------- */
.kitchen-ticket {
  position: relative;
  background: #fff;
  border: 1px solid #eef2f7;
  border-left: 4px solid #cbd5e1;
  border-radius: 12px;
  padding: 12px 14px;
  margin-bottom: 12px;
  box-shadow: 0 1px 2px rgba(0, 0, 0, 0.04);
  transition: transform 0.15s, box-shadow 0.15s;
}
.kitchen-ticket:hover {
  transform: translateY(-2px);
  box-shadow: 0 10px 24px -12px rgba(15, 23, 42, 0.35);
}
.kc-pending .kitchen-ticket { border-left-color: var(--kc-pending); }
.kc-preparing .kitchen-ticket { border-left-color: var(--kc-preparing); }
.kc-completed .kitchen-ticket { border-left-color: var(--kc-completed); }
.kc-on_hold .kitchen-ticket { border-left-color: var(--kc-on_hold); }

.ticket-busy {
  position: absolute;
  inset: 0;
  background: rgba(255, 255, 255, 0.7);
  border-radius: 12px;
  display: flex;
  align-items: center;
  justify-content: center;
  z-index: 3;
  color: #6366f1;
}
.ticket-top {
  display: flex;
  justify-content: space-between;
  align-items: center;
}
.ticket-ref { font-size: 0.98rem; font-weight: 700; color: #1e293b; }
.ticket-time {
  display: inline-flex;
  align-items: center;
  gap: 3px;
  font-size: 0.72rem;
  color: #94a3b8;
  background: #f1f5f9;
  border-radius: 999px;
  padding: 2px 8px;
}
.ticket-customer {
  display: flex;
  align-items: center;
  gap: 5px;
  font-size: 0.85rem;
  color: #475569;
  margin: 6px 0;
}
.ticket-items {
  list-style: none;
  padding: 0;
  margin: 8px 0;
  border-top: 1px dashed #eef2f7;
  padding-top: 8px;
}
.ticket-items li {
  display: flex;
  gap: 8px;
  font-size: 0.86rem;
  padding: 3px 0;
  color: #334155;
}
.ticket-items .qty {
  font-weight: 700;
  color: #6366f1;
  min-width: 46px;
}
.ticket-items .qty small { font-weight: 600; color: #94a3b8; }
.ticket-note {
  display: flex;
  align-items: flex-start;
  gap: 6px;
  background: #fffbeb;
  border: 1px solid #fef3c7;
  color: #92400e;
  border-radius: 8px;
  padding: 6px 9px;
  font-size: 0.8rem;
  margin: 6px 0;
}
.ticket-staff {
  display: inline-flex;
  align-items: center;
  gap: 5px;
  font-size: 0.78rem;
  color: #6366f1;
  background: #eef2ff;
  border-radius: 999px;
  padding: 3px 10px;
  margin-bottom: 4px;
}
.ic { vertical-align: middle; opacity: 0.85; }

/* ---------- Dispatch to warehouse ---------- */
.ticket-dispatch {
  margin-top: 8px;
  padding-top: 8px;
  border-top: 1px dashed #eef2f7;
}
.dispatch-badge {
  display: inline-flex;
  align-items: center;
  gap: 5px;
  font-size: 0.78rem;
  font-weight: 600;
  color: #16a34a;
  background: rgba(34, 197, 94, 0.12);
  border-radius: 999px;
  padding: 4px 11px;
  margin-bottom: 6px;
}
.dispatch-select {
  width: 100%;
  border: 1px solid #e2e8f0;
  border-radius: 8px;
  padding: 6px 9px;
  font-size: 0.8rem;
  color: #475569;
  background: #fff;
  outline: none;
  cursor: pointer;
  transition: border-color 0.15s, box-shadow 0.15s;
}
.dispatch-select:focus {
  border-color: #6366f1;
  box-shadow: 0 0 0 3px rgba(99, 102, 241, 0.15);
}
.dispatch-select:disabled { opacity: 0.6; cursor: not-allowed; }

/* ---------- Ticket actions ---------- */
.ticket-actions {
  display: flex;
  flex-wrap: wrap;
  gap: 6px;
  margin-top: 10px;
}
.t-btn {
  display: inline-flex;
  align-items: center;
  gap: 5px;
  border: none;
  border-radius: 8px;
  font-size: 0.78rem;
  font-weight: 600;
  padding: 6px 10px;
  cursor: pointer;
  transition: filter 0.15s, transform 0.1s;
}
.t-btn:active { transform: scale(0.96); }
.t-btn:disabled { opacity: 0.55; cursor: not-allowed; }
.t-primary { background: var(--kc-preparing); color: #fff; }
.t-success { background: var(--kc-completed); color: #fff; }
.t-warning { background: var(--kc-on_hold); color: #fff; }
.t-ghost { background: #f1f5f9; color: #475569; }
.t-btn:not(:disabled):hover { filter: brightness(0.94); }
.t-icon { padding: 6px 8px; margin-left: auto; }

/* ---------- Empty + skeleton ---------- */
.empty-state {
  display: flex;
  flex-direction: column;
  align-items: center;
  gap: 8px;
  color: #cbd5e1;
  padding: 30px 0;
  font-size: 0.85rem;
}
.skeleton-card {
  background: #fff;
  border: 1px solid #eef2f7;
  border-radius: 12px;
  padding: 14px;
  margin-bottom: 12px;
}
.sk-line {
  height: 10px;
  border-radius: 6px;
  margin-bottom: 9px;
  background: linear-gradient(90deg, #eef2f7 25%, #f8fafc 37%, #eef2f7 63%);
  background-size: 400% 100%;
  animation: shimmer 1.3s ease infinite;
}
.sk-line.w40 { width: 40%; }
.sk-line.w60 { width: 60%; }
.sk-line.w70 { width: 70%; }
.sk-line.w80 { width: 80%; }
@keyframes shimmer {
  0% { background-position: 100% 0; }
  100% { background-position: -100% 0; }
}

/* ---------- Transition ---------- */
.ticket-enter-active,
.ticket-leave-active { transition: all 0.25s ease; }
.ticket-enter { opacity: 0; transform: translateY(-6px); }
.ticket-leave-to { opacity: 0; transform: scale(0.96); }

.spin { animation: spinRot 0.8s linear infinite; }
@keyframes spinRot { to { transform: rotate(360deg); } }

/* ---------- Details modal ---------- */
.km-grid {
  display: grid;
  grid-template-columns: 1fr 1fr;
  gap: 12px;
}
.km-cell { display: flex; flex-direction: column; gap: 3px; }
.km-k { font-size: 0.72rem; text-transform: uppercase; letter-spacing: 0.04em; color: #94a3b8; }
.km-v { font-weight: 600; color: #1e293b; }
.km-badge { width: fit-content; }
.km-note {
  background: #fffbeb;
  border: 1px solid #fef3c7;
  border-radius: 8px;
  padding: 10px 12px;
  margin-top: 8px;
}
.km-note p { margin: 4px 0 0; color: #92400e; }
.km-assign { margin-top: 16px; }
.km-assign label { font-weight: 600; font-size: 0.85rem; margin-bottom: 5px; display: block; }
</style>
