<template>
  <div>
    <b-card class="guide-card shadow-sm">
      <template #header>
        <div class="d-flex align-items-center">
          <lucide-icon class="mr-2 text-info" name="book" />
          <h5 class="mb-0 font-weight-bold">Shopify Sync Guide</h5>
        </div>
      </template>
      <b-card-text>
        <div class="guide-section mb-4">
          <h6 class="guide-title">
            <lucide-icon class="mr-2 text-info" name="key" />
            1. Create a custom app &amp; get credentials
          </h6>
          <ul class="guide-list">
            <li><lucide-icon class="mr-2 text-primary" name="mouse-pointer" />In Shopify admin: Settings → Apps and sales channels → Develop apps → Create an app.</li>
            <li><lucide-icon class="mr-2 text-primary" name="shield" />Configure Admin API scopes: <code>read_products, write_products, read_inventory, write_inventory, read_customers, write_customers, read_orders, write_orders, read_locations</code>.</li>
            <li><lucide-icon class="mr-2 text-primary" name="key" />Install the app, then copy the <strong>Admin API access token</strong> (<code>shpat_...</code>) — shown only once.</li>
            <li><lucide-icon class="mr-2 text-primary" name="lock" />Copy the <strong>API secret key</strong> too — Stocky needs it to verify webhook signatures.</li>
            <li><lucide-icon class="mr-2 text-primary" name="globe" />Shop domain: your <code>*.myshopify.com</code> domain (e.g. <code>my-store.myshopify.com</code>).</li>
          </ul>
        </div>

        <div class="guide-section mb-4">
          <h6 class="guide-title">
            <lucide-icon class="mr-2 text-info" name="settings" />
            2. Connect the store
          </h6>
          <ul class="guide-list">
            <li><lucide-icon class="mr-2 text-success" name="plus" />In the Stores tab, add the store with its domain, access token and API secret, then Test Connection.</li>
            <li><lucide-icon class="mr-2 text-success" name="map-pin" />Edit the store to pick the Shopify Location and the Stocky warehouse used for inventory and imported orders. You can add several stores — every sync is scoped to the store selected in the header.</li>
            <li><lucide-icon class="mr-2 text-success" name="webhook" />Click Register Webhooks so new orders, customer changes and product updates flow into Stocky automatically. Your site must be reachable over HTTPS from the internet.</li>
          </ul>
        </div>

        <div class="guide-section mb-4">
          <h6 class="guide-title">
            <lucide-icon class="mr-2 text-primary" name="arrow-right-left" />
            3. First sync — recommended order
          </h6>
          <ul class="guide-list">
            <li><lucide-icon class="mr-2 text-primary" name="download" />If the Shopify store already has products, run <strong>Pull products</strong> first: it links existing items by SKU instead of creating duplicates.</li>
            <li><lucide-icon class="mr-2 text-primary" name="upload" />Then <strong>Push products</strong> (only unsynced) to publish the rest of your catalog.</li>
            <li><lucide-icon class="mr-2 text-primary" name="package" />Run <strong>Inventory sync</strong> to publish stock levels to the mapped location.</li>
            <li><lucide-icon class="mr-2 text-primary" name="user" />Sync customers in either direction, then <strong>Import orders</strong> — each Shopify order becomes a Stocky sale and decrements stock.</li>
          </ul>
        </div>

        <div class="guide-section">
          <h6 class="guide-title">
            <lucide-icon class="mr-2 text-info" name="info" />
            Notes
          </h6>
          <ul class="guide-list mb-0">
            <li><lucide-icon class="mr-2 text-warning" name="alert-circle" />Keep SKUs consistent between Stocky and Shopify — SKU is the fallback used to link products and order line items.</li>
            <li><lucide-icon class="mr-2 text-warning" name="alert-circle" />Changing a store's domain resets all its mappings; items will sync again to the new shop.</li>
            <li><lucide-icon class="mr-2 text-warning" name="alert-circle" />Imported orders record totals and payment status from Shopify; refunds and edits made later in Shopify update the sale's statuses via the orders/updated webhook.</li>
          </ul>
        </div>
      </b-card-text>
    </b-card>
  </div>
</template>

<script>
export default {
  created() {
    this.$emit('ready');
  }
};
</script>

<style scoped>
.guide-card {
  border-radius: 12px;
  border: none;
}

.guide-card ::v-deep .card-header {
  background: linear-gradient(135deg, #e0f2fe 0%, #bae6fd 100%);
  border-bottom: 2px solid #e9ecef;
  padding: 1.25rem 1.5rem;
  border-radius: 12px 12px 0 0;
}

.guide-section {
  padding-bottom: 1rem;
  border-bottom: 1px solid #f0f0f0;
}

.guide-section:last-child {
  border-bottom: none;
}

.guide-title {
  font-weight: 700;
  color: #2d3748;
  margin-bottom: 0.75rem;
  font-size: 15px;
  display: flex;
  align-items: center;
}

.guide-list {
  list-style: none;
  padding-left: 0;
  margin-bottom: 0;
}

.guide-list li {
  padding: 0.5rem 0;
  display: flex;
  align-items: flex-start;
  color: #4a5568;
  line-height: 1.6;
}

.guide-list code {
  background: #e2e8f0;
  color: #1e293b;
  padding: 0.2em 0.4em;
  border-radius: 4px;
  font-size: 12px;
  font-family: 'Courier New', monospace;
}
</style>
