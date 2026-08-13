/**
 * Shared batch-loop for Shopify sync tabs.
 *
 * Backend sync endpoints are cursor-based: each call processes one bounded
 * batch/page and returns `has_more` plus a cursor (`last_id` for local pushes,
 * `next_page_info` for remote pulls). This mixin keeps calling until done,
 * accumulating counters for the progress UI.
 */
export default {
  data() {
    return {
      running: false,
      cancelRequested: false,
      counters: {},
      batchErrors: [],
      finishedAt: null,
    };
  },
  computed: {
    hasCounters() {
      return Object.keys(this.counters).length > 0;
    },
  },
  methods: {
    resetRun() {
      this.counters = {};
      this.batchErrors = [];
      this.finishedAt = null;
    },
    cancelRun() {
      this.cancelRequested = true;
    },
    accumulate(data) {
      ['processed', 'created', 'updated', 'failed', 'skipped', 'imported'].forEach(key => {
        if (typeof data[key] === 'number') {
          this.$set(this.counters, key, (this.counters[key] || 0) + data[key]);
        }
      });
      if (Array.isArray(data.errors) && data.errors.length) {
        this.batchErrors = this.batchErrors.concat(data.errors).slice(-50);
      }
    },
    /**
     * @param {string} url    e.g. 'shopify/sync/products?mode=push'
     * @param {object} params extra body params (store_id is added automatically)
     * @param {string} kind   'push' (cursor: last_id) or 'pull' (cursor: next_page_info)
     */
    runSyncLoop(url, params, kind) {
      if (this.running) return Promise.resolve();
      this.running = true;
      this.cancelRequested = false;
      this.resetRun();

      const body = Object.assign({ store_id: this.store.id }, params || {});

      const step = () => {
        return axios.post(url, body).then(({ data }) => {
          this.accumulate(data);
          if (this.cancelRequested || !data.has_more) {
            return data;
          }
          if (kind === 'push') {
            body.start_after_id = data.last_id;
          } else {
            body.page_info = data.next_page_info;
          }
          return step();
        });
      };

      return step().then(() => {
        this.finishedAt = new Date();
        const failed = this.counters.failed || 0;
        this.notify(failed ? 'warning' : 'success',
          this.cancelRequested ? this.$t('Sync_cancelled') : this.$t('Sync_completed'));
      }).catch(err => {
        const msg = err.response && err.response.data && (err.response.data.error || err.response.data.message);
        this.notify('danger', msg || this.$t('Sync_failed'));
      }).finally(() => {
        this.running = false;
        this.$emit('refreshed');
      });
    },
    notify(variant, msg) {
      this.$root.$bvToast.toast(msg, { title: 'Shopify', variant, solid: true });
    },
  },
};
