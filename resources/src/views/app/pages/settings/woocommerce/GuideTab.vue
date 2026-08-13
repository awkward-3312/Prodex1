<template>
  <div class="woo-guide">
    <b-alert variant="info" show class="info-alert-modern mb-4">
      <div class="d-flex align-items-start">
        <lucide-icon class="mr-3 mt-1" name="info" />
        <div>
          This guide explains how to connect your WooCommerce store and keep your products,
          stock, customers and orders in sync. Follow the steps in order the first time you set
          things up.
        </div>
      </div>
    </b-alert>

    <!-- Quick steps -->
    <b-card class="action-card shadow-sm mb-4">
      <div class="d-flex align-items-center mb-3">
        <lucide-icon class="mr-2" name="rocket" />
        <strong>Quick start (3 steps)</strong>
      </div>
      <div class="guide-steps">
        <div class="guide-step">
          <div class="guide-step-num">1</div>
          <div>
            <div class="guide-step-title">Connect your store</div>
            <div class="guide-step-text">
              Open the <strong>Settings</strong> tab, enter your store URL and WooCommerce API
              keys, then click <strong>Test connection</strong> until the badge at the top shows
              <b-badge variant="success">Connected</b-badge>.
            </div>
          </div>
        </div>
        <div class="guide-step">
          <div class="guide-step-num">2</div>
          <div>
            <div class="guide-step-title">Sync categories &amp; brands first</div>
            <div class="guide-step-text">
              In the <strong>Categories</strong> and <strong>Brands</strong> tabs, run the sync so
              products land in the right place. Do this before syncing products.
            </div>
          </div>
        </div>
        <div class="guide-step">
          <div class="guide-step-num">3</div>
          <div>
            <div class="guide-step-title">Sync products &amp; stock</div>
            <div class="guide-step-text">
              Use the <strong>Products</strong> tab to push or pull products, then the
              <strong>Stock</strong> tab to keep quantities up to date.
            </div>
          </div>
        </div>
      </div>
    </b-card>

    <!-- What each tab does -->
    <b-card class="action-card shadow-sm mb-4">
      <div class="d-flex align-items-center mb-3">
        <lucide-icon class="mr-2" name="layout-grid" />
        <strong>What each tab does</strong>
      </div>
      <div class="guide-grid">
        <div class="guide-item">
          <lucide-icon class="guide-item-icon" name="settings" />
          <div>
            <div class="guide-item-title">Settings</div>
            <div class="guide-item-text">Connect your store and check the connection status.</div>
          </div>
        </div>
        <div class="guide-item">
          <lucide-icon class="guide-item-icon" name="barcode" />
          <div>
            <div class="guide-item-title">Products</div>
            <div class="guide-item-text">Push products to WooCommerce or pull them into Stocky.</div>
          </div>
        </div>
        <div class="guide-item">
          <lucide-icon class="guide-item-icon" name="package" />
          <div>
            <div class="guide-item-title">Stock</div>
            <div class="guide-item-text">Send current stock quantities to WooCommerce.</div>
          </div>
        </div>
        <div class="guide-item">
          <lucide-icon class="guide-item-icon" name="folder" />
          <div>
            <div class="guide-item-title">Categories</div>
            <div class="guide-item-text">Match Stocky categories with WooCommerce categories.</div>
          </div>
        </div>
        <div class="guide-item">
          <lucide-icon class="guide-item-icon" name="tag" />
          <div>
            <div class="guide-item-title">Brands</div>
            <div class="guide-item-text">Match Stocky brands with WooCommerce brands.</div>
          </div>
        </div>
        <div class="guide-item">
          <lucide-icon class="guide-item-icon" name="user" />
          <div>
            <div class="guide-item-title">Customers</div>
            <div class="guide-item-text">Sync customers in either direction.</div>
          </div>
        </div>
        <div class="guide-item">
          <lucide-icon class="guide-item-icon" name="shopping-bag" />
          <div>
            <div class="guide-item-title">Orders</div>
            <div class="guide-item-text">Import WooCommerce orders into Stocky as sales.</div>
          </div>
        </div>
        <div class="guide-item">
          <lucide-icon class="guide-item-icon" name="clipboard-list" />
          <div>
            <div class="guide-item-title">View Logs</div>
            <div class="guide-item-text">See what happened during each sync, including errors.</div>
          </div>
        </div>
      </div>
    </b-card>

    <!-- Push vs Pull -->
    <b-card class="action-card shadow-sm mb-4">
      <div class="d-flex align-items-center mb-3">
        <lucide-icon class="mr-2" name="arrow-left-right" />
        <strong>Push vs. Pull — what's the difference?</strong>
      </div>
      <div class="guide-grid">
        <div class="guide-item">
          <lucide-icon class="guide-item-icon text-success" name="chevron-up" />
          <div>
            <div class="guide-item-title">Push (Stocky → WooCommerce)</div>
            <div class="guide-item-text">
              Sends your Stocky products/stock up to your online store. Use this when Stocky is
              your source of truth.
            </div>
          </div>
        </div>
        <div class="guide-item">
          <lucide-icon class="guide-item-icon text-primary" name="chevron-down" />
          <div>
            <div class="guide-item-title">Pull (WooCommerce → Stocky)</div>
            <div class="guide-item-text">
              Brings products/customers/orders from your online store into Stocky. Use this to
              import an existing store.
            </div>
          </div>
        </div>
      </div>
      <b-alert variant="warning" show class="mt-3 mb-0">
        <div class="d-flex align-items-start">
          <lucide-icon class="mr-2 mt-1" name="lightbulb" />
          <div>
            <strong>Tip:</strong> Products are matched by <strong>SKU</strong>. Make sure each
            product has a unique SKU/code in both systems to avoid duplicates.
          </div>
        </div>
      </b-alert>
    </b-card>

    <!-- Understanding progress -->
    <b-card class="action-card shadow-sm mb-4">
      <div class="d-flex align-items-center mb-3">
        <lucide-icon class="mr-2" name="activity" />
        <strong>Understanding sync progress</strong>
      </div>
      <ul class="guide-list">
        <li>
          Large syncs run in the background in small <strong>batches</strong>, so the progress bar
          may pause briefly between batches — this is normal.
        </li>
        <li>
          Keep this page open while a sync is running. If you close the tab, the sync may pause
          until you reopen it (unless your administrator has set up a background worker).
        </li>
        <li>
          You can stop a running sync at any time with the <strong>Stop</strong> button; already
          synced items are kept.
        </li>
        <li>
          Images can make product syncs slower because each image is uploaded to your store.
        </li>
      </ul>
    </b-card>

    <!-- Admin: keep syncs running automatically -->
    <b-card class="action-card shadow-sm mb-4">
      <div class="d-flex align-items-center mb-3">
        <lucide-icon class="mr-2" name="server-cog" />
        <strong>For administrators: keep syncs running automatically</strong>
      </div>

      <b-alert variant="warning" show class="mb-4">
        <div class="d-flex align-items-start">
          <lucide-icon class="mr-2 mt-1" name="alert-triangle" />
          <div>
            Large syncs run in the background in batches. A <strong>queue worker</strong> must be
            running on the server, otherwise a sync imports a few items and then stops at
            <code>queued_next_batch</code>. Choose <strong>one</strong> of the three options below
            (they can also run together).
          </div>
        </div>
      </b-alert>

      <!-- Option A -->
      <div class="guide-option">
        <div class="guide-option-head">
          <b-badge variant="success" class="guide-option-badge">Recommended</b-badge>
          <span class="guide-option-title">Option A — Persistent worker (Supervisor)</span>
        </div>
        <div class="guide-option-text">
          Best for a VPS / dedicated / self-hosted server. Supervisor keeps the worker running
          forever and restarts it automatically. A ready config ships at
          <code>deploy/supervisor/stocky-queue-worker.conf</code>. It runs:
        </div>
        <pre class="guide-code"><code>php artisan queue:work database \
  --queue=woocommerce-sync,woocommerce-stock,default \
  --sleep=1 --tries=1 --timeout=1200 --max-time=3600</code></pre>
        <div class="guide-option-text">
          Install &amp; start:
        </div>
        <pre class="guide-code"><code>sudo cp deploy/supervisor/stocky-queue-worker.conf /etc/supervisor/conf.d/
sudo supervisorctl reread &amp;&amp; sudo supervisorctl update
sudo supervisorctl start stocky-queue-worker:*</code></pre>
        <div class="guide-option-note">
          <lucide-icon class="mr-1" name="info" /> After every deploy, run
          <code>php artisan queue:restart</code> so the worker picks up new code.
        </div>
      </div>

      <!-- Option B -->
      <div class="guide-option">
        <div class="guide-option-head">
          <b-badge variant="primary" class="guide-option-badge">Shared hosting</b-badge>
          <span class="guide-option-title">Option B — Cron scheduler</span>
        </div>
        <div class="guide-option-text">
          No root access or Supervisor? Add a <strong>single</strong> cron entry that runs the
          Laravel scheduler every minute. The app already drains the sync queues from there.
        </div>
        <pre class="guide-code"><code>* * * * * cd /path-to-app &amp;&amp; php artisan schedule:run >> /dev/null 2>&amp;1</code></pre>
        <div class="guide-option-note">
          <lucide-icon class="mr-1" name="info" /> Slower than Option A (advances about once per
          minute) but needs no extra software.
        </div>
      </div>

      <!-- Option C -->
      <div class="guide-option">
        <div class="guide-option-head">
          <b-badge variant="secondary" class="guide-option-badge">Manual</b-badge>
          <span class="guide-option-title">Option C — Manual / no setup</span>
        </div>
        <div class="guide-option-text">
          With no worker and no cron, syncs still progress <strong>while this page stays open</strong>
          (each refresh runs one batch). Closing the tab pauses the sync. Good for small, occasional
          syncs. You can also run a full sync from the command line, which finishes on its own:
        </div>
        <pre class="guide-code"><code>php artisan woocommerce:sync --scope=all
php artisan woocommerce:sync --scope=products --only-unsynced</code></pre>
      </div>

      <div class="guide-option-note mt-2">
        <lucide-icon class="mr-1" name="book-open" /> Full step-by-step guide (with systemd
        alternative and troubleshooting): <code>deploy/QUEUE_WORKER_SETUP.md</code>.
      </div>
    </b-card>

    <!-- Troubleshooting -->
    <b-card class="action-card shadow-sm mb-4">
      <div class="d-flex align-items-center mb-3">
        <lucide-icon class="mr-2" name="life-buoy" />
        <strong>Troubleshooting</strong>
      </div>
      <div class="guide-faq">
        <div class="guide-faq-item">
          <div class="guide-faq-q">
            <lucide-icon class="mr-2" name="help-circle" />
            The connection badge shows "Disconnected"
          </div>
          <div class="guide-faq-a">
            Re-check your store URL and API keys in the Settings tab. The consumer key/secret must
            have <strong>Read/Write</strong> permission, and your store must be reachable over the
            internet.
          </div>
        </div>
        <div class="guide-faq-item">
          <div class="guide-faq-q">
            <lucide-icon class="mr-2" name="help-circle" />
            The sync stopped after only a few products
          </div>
          <div class="guide-faq-a">
            Make sure this page stayed open. For unattended/large syncs, ask your administrator to
            enable a background queue worker so syncs finish on their own.
          </div>
        </div>
        <div class="guide-faq-item">
          <div class="guide-faq-q">
            <lucide-icon class="mr-2" name="help-circle" />
            Product images aren't appearing in WooCommerce
          </div>
          <div class="guide-faq-a">
            Image uploads need a WordPress <strong>username</strong> and
            <strong>application password</strong> in the Settings tab.
          </div>
        </div>
        <div class="guide-faq-item">
          <div class="guide-faq-q">
            <lucide-icon class="mr-2" name="help-circle" />
            I see duplicate products in WooCommerce
          </div>
          <div class="guide-faq-a">
            This usually means SKUs don't match between the two systems. Fix the SKUs, then use
            <strong>Reset products sync</strong> and run the sync again.
          </div>
        </div>
        <div class="guide-faq-item">
          <div class="guide-faq-q">
            <lucide-icon class="mr-2" name="help-circle" />
            Something looks wrong — where do I look?
          </div>
          <div class="guide-faq-a">
            Open the <strong>View Logs</strong> tab. Each sync records what it did and any errors,
            which helps you or your administrator find the cause.
          </div>
        </div>
      </div>
    </b-card>

    <b-alert variant="secondary" show class="mb-0">
      <div class="d-flex align-items-start">
        <lucide-icon class="mr-2 mt-1" name="shield-check" />
        <div>
          <strong>Safe to retry:</strong> Running a sync again won't duplicate items that are
          already linked — it only updates or adds what's missing.
        </div>
      </div>
    </b-alert>
  </div>
</template>

<script>
export default {
  name: 'GuideTab',
  mounted() {
    // No data to load; signal the parent so the tab spinner clears.
    this.$emit('ready');
  },
};
</script>

<style scoped>
.guide-steps {
  display: flex;
  flex-direction: column;
  gap: 1rem;
}
.guide-step {
  display: flex;
  align-items: flex-start;
  gap: 0.85rem;
}
.guide-step-num {
  flex: 0 0 auto;
  width: 30px;
  height: 30px;
  border-radius: 50%;
  background: #7f54b3; /* WooCommerce purple */
  color: #fff;
  display: flex;
  align-items: center;
  justify-content: center;
  font-weight: 700;
  font-size: 0.9rem;
}
.guide-step-title {
  font-weight: 600;
  margin-bottom: 2px;
}
.guide-step-text {
  color: #6b7280;
  font-size: 0.9rem;
}
.guide-grid {
  display: grid;
  grid-template-columns: repeat(auto-fill, minmax(260px, 1fr));
  gap: 1rem;
}
.guide-item {
  display: flex;
  align-items: flex-start;
  gap: 0.65rem;
  padding: 0.85rem;
  border: 1px solid #eef0f4;
  border-radius: 10px;
  background: #fbfbfd;
}
.guide-item-icon {
  flex: 0 0 auto;
  margin-top: 2px;
  color: #7f54b3;
}
.guide-item-title {
  font-weight: 600;
  margin-bottom: 2px;
}
.guide-item-text {
  color: #6b7280;
  font-size: 0.88rem;
}
.guide-list {
  margin: 0;
  padding-left: 1.1rem;
}
.guide-list li {
  margin-bottom: 0.5rem;
  color: #4b5563;
}
.guide-faq {
  display: flex;
  flex-direction: column;
  gap: 0.9rem;
}
.guide-faq-q {
  display: flex;
  align-items: center;
  font-weight: 600;
}
.guide-faq-a {
  color: #6b7280;
  font-size: 0.9rem;
  margin-top: 3px;
  padding-left: 1.6rem;
}
.guide-option {
  padding: 1rem 1.1rem;
  border: 1px solid #eef0f4;
  border-left: 3px solid #7f54b3;
  border-radius: 10px;
  background: #fbfbfd;
  margin-bottom: 1rem;
}
.guide-option:last-of-type {
  margin-bottom: 0;
}
.guide-option-head {
  display: flex;
  align-items: center;
  gap: 0.5rem;
  margin-bottom: 0.5rem;
}
.guide-option-badge {
  font-size: 0.7rem;
}
.guide-option-title {
  font-weight: 600;
}
.guide-option-text {
  color: #4b5563;
  font-size: 0.9rem;
  margin-bottom: 0.5rem;
}
.guide-code {
  background: #1e1e2e;
  color: #e5e7eb;
  padding: 0.75rem 0.9rem;
  border-radius: 8px;
  font-size: 0.82rem;
  line-height: 1.5;
  overflow-x: auto;
  margin-bottom: 0.6rem;
  white-space: pre;
}
.guide-code code {
  background: transparent;
  color: inherit;
  padding: 0;
}
.guide-option-note {
  color: #6b7280;
  font-size: 0.85rem;
  display: flex;
  align-items: center;
  flex-wrap: wrap;
}
.guide-option-note code,
.guide-option-text code,
.guide-faq-a code {
  background: #f1edf8;
  color: #7f54b3;
  padding: 1px 6px;
  border-radius: 4px;
  font-size: 0.85em;
}
</style>
