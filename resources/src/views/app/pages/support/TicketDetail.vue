<template>
  <div class="main-content">
    <breadcumb :page="ticket ? ticket.ticket_number : $t('ticket')" :folder="$t('support_center')" />

    <div v-if="isLoading" class="loading_page spinner spinner-primary mr-3"></div>

    <div v-else-if="ticket">
      <b-row>
        <!-- Conversation -->
        <b-col lg="8" md="12">
          <b-card no-body>
            <div class="card-header bg-transparent d-flex justify-content-between align-items-center flex-wrap">
              <div>
                <h5 class="m-0">{{ ticket.subject }}</h5>
                <small class="text-muted">
                  {{ ticket.ticket_number }} · {{ $t('cat_' + ticket.category) }} · {{ $t('opened_on') }} {{ formatDateTime(ticket.created_at) }}
                </small>
              </div>
              <div>
                <b-badge :variant="priorityVariant(ticket.priority)" class="mr-1">{{ $t('pri_' + ticket.priority) }}</b-badge>
                <b-badge :variant="statusVariant(ticket.status)">{{ $t('status_' + ticket.status) }}</b-badge>
              </div>
            </div>

            <div class="card-body st-thread" ref="thread">
              <div
                v-for="m in ticket.messages"
                :key="m.id"
                class="st-row"
                :class="m.author_type === 'tenant' ? 'st-row-me' : 'st-row-them'"
              >
                <div class="st-bubble" :class="m.author_type === 'tenant' ? 'st-bubble-me' : 'st-bubble-them'">
                  <div class="st-bubble-head">
                    <span class="font-weight-bold">
                      {{ m.author_type === 'tenant' ? (m.author_name || $t('you')) : (m.author_name || $t('support_team')) }}
                    </span>
                    <span class="st-time">{{ formatDateTime(m.created_at) }}</span>
                  </div>
                  <div class="st-bubble-body">{{ m.body }}</div>

                  <div v-if="m.attachments && m.attachments.length" class="st-files">
                    <template v-for="a in m.attachments">
                      <a
                        v-if="a.is_image"
                        :key="a.id"
                        :href="a.url"
                        target="_blank"
                        class="st-thumb"
                      >
                        <img :src="a.url" :alt="a.original_name" />
                      </a>
                      <a v-else :key="a.id" :href="a.url" target="_blank" class="st-file-link">
                        <lucide-icon name="paperclip" /> {{ a.original_name }}
                      </a>
                    </template>
                  </div>
                </div>
              </div>
            </div>

            <!-- Reply box -->
            <div class="card-footer bg-transparent" v-if="ticket.status !== 'closed'">
              <b-form @submit.prevent="sendReply">
                <b-form-textarea
                  v-model="replyBody"
                  rows="3"
                  :placeholder="$t('write_reply')"
                ></b-form-textarea>
                <div class="d-flex justify-content-between align-items-center mt-2 flex-wrap">
                  <b-form-file
                    v-model="replyFiles"
                    multiple
                    size="sm"
                    class="st-reply-file"
                    :placeholder="$t('attach_files')"
                    accept=".jpg,.jpeg,.png,.pdf"
                  ></b-form-file>
                  <b-button type="submit" variant="primary" size="sm" :disabled="sending || !replyBody">
                    <lucide-icon name="send" /> {{ $t('send_reply') }}
                  </b-button>
                </div>
              </b-form>
            </div>
            <div class="card-footer bg-transparent text-center text-muted" v-else>
              {{ $t('status_closed') }}
            </div>
          </b-card>
        </b-col>

        <!-- Sidebar -->
        <b-col lg="4" md="12">
          <b-card>
            <ul class="list-unstyled st-meta m-0">
              <li><span>{{ $t('status') }}</span><b-badge :variant="statusVariant(ticket.status)">{{ $t('status_' + ticket.status) }}</b-badge></li>
              <li><span>{{ $t('priority') }}</span><b-badge :variant="priorityVariant(ticket.priority)">{{ $t('pri_' + ticket.priority) }}</b-badge></li>
              <li><span>{{ $t('category') }}</span><strong>{{ $t('cat_' + ticket.category) }}</strong></li>
              <li><span>{{ $t('ticket_number') }}</span><strong>{{ ticket.ticket_number }}</strong></li>
              <li><span>{{ $t('opened_on') }}</span><strong>{{ formatDateTime(ticket.created_at) }}</strong></li>
            </ul>
            <router-link to="/app/support/tickets" class="btn btn-outline-secondary btn-block btn-sm mt-3">
              <lucide-icon name="arrow-left" /> {{ $t('back_to_tickets') }}
            </router-link>
          </b-card>
        </b-col>
      </b-row>
    </div>
  </div>
</template>

<script>
import NProgress from "nprogress";

export default {
  metaInfo: { title: "Support Ticket" },
  data() {
    return {
      isLoading: true,
      sending: false,
      ticket: null,
      replyBody: "",
      replyFiles: [],
      pollTimer: null
    };
  },
  computed: {
    ticketId() {
      return this.$route.params.id;
    }
  },
  methods: {
    statusVariant(s) {
      return { open: "danger", pending: "warning", resolved: "success", closed: "secondary" }[s] || "secondary";
    },
    priorityVariant(p) {
      return { urgent: "danger", high: "warning", medium: "info", low: "success" }[p] || "info";
    },
    formatDateTime(value) {
      if (!value) return "";
      const d = new Date(value);
      if (isNaN(d.getTime())) return value;
      const p = n => String(n).padStart(2, "0");
      return `${d.getFullYear()}-${p(d.getMonth() + 1)}-${p(d.getDate())} ${p(d.getHours())}:${p(d.getMinutes())}`;
    },
    scrollToBottom() {
      this.$nextTick(() => {
        const el = this.$refs.thread;
        if (el) el.scrollTop = el.scrollHeight;
      });
    },
    fetchTicket(initial = false) {
      if (initial) {
        NProgress.start();
        NProgress.set(0.1);
      }
      return axios
        .get("support/tickets/" + this.ticketId)
        .then(response => {
          const prevCount = this.ticket && this.ticket.messages ? this.ticket.messages.length : 0;
          this.ticket = response.data.ticket;
          this.isLoading = false;
          if (initial || (this.ticket.messages && this.ticket.messages.length !== prevCount)) {
            this.scrollToBottom();
          }
          if (initial) NProgress.done();
        })
        .catch(() => {
          this.isLoading = false;
          if (initial) {
            NProgress.done();
            this.$router.push("/app/support/tickets");
          }
        });
    },
    sendReply() {
      if (!this.replyBody) return;
      this.sending = true;
      NProgress.start();
      NProgress.set(0.1);

      const data = new FormData();
      data.append("message", this.replyBody);
      if (this.replyFiles && this.replyFiles.length) {
        this.replyFiles.forEach(f => data.append("attachments[]", f));
      }

      axios
        .post("support/tickets/" + this.ticketId + "/reply", data, {
          headers: { "Content-Type": "multipart/form-data" }
        })
        .then(() => {
          this.replyBody = "";
          this.replyFiles = [];
          this.sending = false;
          NProgress.done();
          this.makeToast("success", this.$t("reply_sent_success"), this.$t("Success"));
          this.fetchTicket();
        })
        .catch(() => {
          this.sending = false;
          NProgress.done();
          this.makeToast("danger", this.$t("please_fill_required"), this.$t("Failed"));
        });
    },
    startPolling() {
      this.stopPolling();
      // Near-real-time: refresh the thread every 12s while the tab is visible.
      this.pollTimer = setInterval(() => {
        if (typeof document !== "undefined" && document.hidden) return;
        this.fetchTicket();
      }, 12000);
    },
    stopPolling() {
      if (this.pollTimer) {
        clearInterval(this.pollTimer);
        this.pollTimer = null;
      }
    },
    makeToast(variant, msg, title) {
      this.$root.$bvToast.toast(msg, {
        title: title,
        variant: variant,
        solid: true
      });
    }
  },
  created() {
    this.fetchTicket(true).then(() => this.startPolling());
  },
  beforeDestroy() {
    this.stopPolling();
  }
};
</script>

<style scoped>
.st-thread {
  max-height: 55vh;
  overflow-y: auto;
}
.st-row {
  display: flex;
  margin-bottom: 14px;
}
.st-row-me { justify-content: flex-end; }
.st-row-them { justify-content: flex-start; }
.st-bubble {
  max-width: 78%;
  padding: 10px 14px;
  border-radius: 12px;
  border: 1px solid #e5e7eb;
}
.st-bubble-me { background: #eef2ff; border-color: #c7d2fe; border-bottom-right-radius: 2px; }
.st-bubble-them { background: #f8fafc; border-bottom-left-radius: 2px; }
.st-bubble-head {
  display: flex;
  justify-content: space-between;
  gap: 12px;
  margin-bottom: 4px;
  font-size: 0.8rem;
}
.st-time { color: #9ca3af; font-size: 0.72rem; white-space: nowrap; }
.st-bubble-body { white-space: pre-wrap; line-height: 1.5; }
.st-files { margin-top: 8px; display: flex; flex-wrap: wrap; gap: 8px; }
.st-thumb img { max-width: 120px; max-height: 120px; border-radius: 8px; border: 1px solid #e5e7eb; }
.st-file-link { display: inline-flex; align-items: center; gap: 4px; font-size: 0.85rem; }
.st-meta li { display: flex; justify-content: space-between; align-items: center; padding: 8px 0; border-bottom: 1px solid #f1f5f9; }
.st-meta li:last-child { border-bottom: 0; }
.st-meta li span { color: #6b7280; font-size: 0.85rem; }
.st-reply-file { max-width: 320px; display: inline-block; }
</style>
