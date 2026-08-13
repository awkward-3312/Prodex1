# WooCommerce Integration

Two-way sync between Stocky (POS) and a WooCommerce store: **products, stock, categories,
brands, customers and orders**. This document is the reference for how it works, how to run
the queue workers correctly, and how to troubleshoot the common failure modes.

> TL;DR for ops: WooCommerce sync runs in **batched queue jobs** on the fixed queues
> `woocommerce-sync` and `woocommerce-stock`. A worker **must** process those queues or
> syncs hang at `queued_next_batch`. See [Queue & worker setup](#queue--worker-setup).

---

## 1. Components

| File | Role |
|------|------|
| [`Client.php`](Client.php) | Thin signed HTTP client for the Woo REST API (`wc/v3`) + WordPress media. Handles retries, timeouts, and a per-store `fingerprint()`. |
| [`SyncService.php`](SyncService.php) | All business logic: push/pull products, stock, categories, brands, customers, orders; SKU matching; the remote product index. |
| [`../../Jobs/WooCommerceProductsSyncJob.php`](../../Jobs/WooCommerceProductsSyncJob.php) | Push products Stocky → Woo, in batches. Re-dispatches itself for the next batch. |
| [`../../Jobs/WooCommerceProductsPullJob.php`](../../Jobs/WooCommerceProductsPullJob.php) | Pull products Woo → Stocky, in batches (cursor = page/index). |
| [`../../Jobs/WooCommerceStockSyncJob.php`](../../Jobs/WooCommerceStockSyncJob.php) | Push stock quantities Stocky → Woo, in batches. |
| [`../../Http/Controllers/WooCommerceSyncController.php`](../../Http/Controllers/WooCommerceSyncController.php) | All `woocommerce/*` API endpoints: start syncs, progress polling, stats, resets, logs. |
| [`../../Http/Controllers/SyncJobController.php`](../../Http/Controllers/SyncJobController.php) | DB-backed job status/cancel endpoints (`sync/status/{id}`, `woo-sync/*`). |
| [`../../Console/Commands/WooCommerceSync.php`](../../Console/Commands/WooCommerceSync.php) | `php artisan woocommerce:sync` — server-side sync that runs its own inline worker. |

State is stored in two places:

- **`SyncJob` model** (DB table `sync_jobs`) — durable per-run record: status, stage,
  counts, percentage, `worker_heartbeat_at`. Drives the `sync/status/{id}` UI.
- **File cache** (`Cache::store('file')`), keyed by a **token** (e.g. `woo_products_sync_<uniqid>`) —
  fine-grained live progress. The file store is used deliberately so the web request and the
  queue worker (separate processes) share the same state.

---

## 2. Sync flows

### Products push (Stocky → Woo)
`POST woocommerce/sync/products` (default `mode=push`) → creates a `SyncJob`, a progress token,
and dispatches `WooCommerceProductsSyncJob` on queue **`woocommerce-sync`**.

1. Job builds/loads the **remote index** (see §4), then processes up to `WOO_PRODUCTS_PER_JOB`
   products ordered by `id`, starting after the saved cursor `last_product_id`.
2. After the batch it counts remaining products (`id > last_product_id`). If any remain it sets
   stage `queued_next_batch` and **re-dispatches itself** on `woocommerce-sync`.
3. When none remain it marks the `SyncJob` `completed` / stage `finished`.

### Products pull (Woo → Stocky)
`POST woocommerce/sync/products?mode=pull` → dispatches `WooCommerceProductsPullJob` on
**`woocommerce-sync`**. Same batching pattern, but the cursor is `cursor_page` / `cursor_index`
over the remote catalog. Re-dispatches until `done`.

### Stock push (Stocky → Woo)
`POST woocommerce/sync/stock` (alias `woocommerce/sync-stock`) → dispatches
`WooCommerceStockSyncJob` on **`woocommerce-stock`**. Batches of `WOO_STOCK_PRODUCTS_PER_JOB`,
re-dispatching until no products remain.

### Synchronous flows (no queue)
Categories, brands, customers and orders run **inline inside the request** via `SyncService`
(no job/queue): `woocommerce/sync/categories`, `woocommerce/sync/brands`,
`woocommerce/sync/customers`, `woocommerce/sync/orders`.

---

## 3. Queue & worker setup

> **This is the #1 cause of "sync stuck after a few products".**

Product/stock syncs process in small batches; each batch **re-dispatches the next batch** onto a
fixed queue:

- products push & pull → **`woocommerce-sync`**
- stock → **`woocommerce-stock`**

A worker **must** consume those queues. There are three supported ways to run them — pick one
(they can coexist). Full step-by-step instructions for all three are in
[`deploy/QUEUE_WORKER_SETUP.md`](../../../deploy/QUEUE_WORKER_SETUP.md); summary below:

### Option A — Persistent worker (recommended, Supervisor)
Use [`deploy/supervisor/stocky-queue-worker.conf`](../../../deploy/supervisor/stocky-queue-worker.conf).
It runs:

```bash
php artisan queue:work database \
  --queue=woocommerce-sync,woocommerce-stock,default \
  --sleep=1 --tries=1 --timeout=1200 --max-time=3600
```

Increase `numprocs` for more parallel batches. Restart workers after every deploy
(`supervisorctl restart stocky-queue-worker:*`).

### Option B — Cron (shared hosting, no Supervisor)
A single cron entry running the Laravel scheduler every minute:

```cron
* * * * * cd /path/to/app && php artisan schedule:run >> /dev/null 2>&1
```

The scheduler ([`app/Console/Kernel.php`](../../Console/Kernel.php)) runs each minute:

```bash
queue:work database --stop-when-empty --max-time=50 \
  --queue=woocommerce-sync,woocommerce-stock,default ...
```

i.e. it drains all pending batches (up to ~50s) then exits, resuming next minute. Slower than a
persistent worker but needs no extra services.

### Option C — One-shot CLI
`php artisan woocommerce:sync --scope=all` runs a sync **and its own inline worker loop** until
finished. Good for manual runs or a dedicated cron. Scopes: `products`, `stock`, `all`.
Add `--only-unsynced` to push only products without a `woocommerce_id`.

### "No-cron" browser tick (fallback, automatic)
If neither a worker nor the scheduler is running, the **progress-polling endpoints** run one
batch inline per poll (`tickProductsQueueOnce` / `tickStockQueueOnce`, guarded by a cache lock).
This makes "Sync now" work with no infrastructure — **but only while the browser tab stays open
and keeps polling.** Closing the tab stalls the sync at `queued_next_batch`. Prefer Option A or B
for unattended/large syncs.

> ⚠️ Queue connection is **`database`** (the `jobs` table). Each tenant has its own database, so
> the fixed queue names are safe — they don't collide across tenants.

---

## 4. Remote product index (performance)

To match local SKUs to existing remote products without an API call per product, `pushProducts`
builds a **remote index** (`SKU → wooId` and external-id → wooId) by paging the entire remote
catalog once. Because pushes run in small batches, the index is **cached per store** across the
batches of a run:

- Key: `woo_remote_index:<client fingerprint>` in the file cache.
- TTL: `WOO_REMOTE_INDEX_TTL` seconds (default 900, capped at 3600; set `0` to disable caching
  and rebuild every batch).
- Built by `buildRemoteIndex()`, wrapped by `getRemoteIndexCached()`.

**Invalidation** (`SyncService::forgetRemoteIndexCache()`) happens automatically when:
- a new products sync starts (UI `syncProducts`, or `php artisan woocommerce:sync`), and
- mappings are reset (`reset-sync`, `reset-products-sync`).

This prevents a stale index from creating **duplicate** remote products after a reset.

---

## 5. Progress, stages & stuck detection

Stages reported during a run: `queued` → `running` → (`start`/`media`/`variants`/`done` per
product) → `queued_next_batch` (between batches) → `finished` (or `failed` / `cancelled`).

The status/progress endpoints implement **stuck detection** based on `worker_heartbeat_at`:

- Normal stall threshold: `WOO_SYNC_STUCK_SECONDS` (default 600s).
- While `stage` starts with `queued`, the threshold is raised to `WOO_SYNC_QUEUE_WAIT_SECONDS`
  (default 1800s) to allow time for a worker tick.
- During `media`, raised to at least `WOO_WP_MEDIA_UPLOAD_TIMEOUT` + 60s.

If the heartbeat is older than the effective threshold the run is force-failed with
`stuck: no worker heartbeat for Ns` so the UI doesn't hang forever. **If you see this and a
worker is configured, the worker isn't consuming the woo queues** — re-check §3.

### Cancelling
- `POST woocommerce/sync/products/stop` / `.../stock/stop` (by token), or
- `POST woo-sync/{id}/cancel` (by SyncJob id).

Both set a cancel flag the job checks at each product boundary; queued jobs for the run are deleted.

---

## 6. Reset operations

| Endpoint | Clears |
|----------|--------|
| `POST woocommerce/reset-sync` | All mappings (products, variants, categories, brands, clients) + logs + last-sync; cancels running syncs; invalidates remote index. |
| `POST woocommerce/reset-products-sync` | Product + variant mappings; cancels running syncs; invalidates remote index. |
| `POST woocommerce/reset-categories-sync` | Category mappings. |
| `POST woocommerce/reset-brands-sync` | Brand mappings. |
| `POST woocommerce/reset-customers-sync` | Client mappings. |
| `POST woocommerce/reset-stock-sync` | Stock-related logs only. |

Changing the store URL/key/secret in `saveSettings` also clears all mappings (the data now points
at a different store).

---

## 7. Configuration (`.env`)

### Credentials (stored in DB via the Settings UI, not `.env`)
`store_url`, `consumer_key`, `consumer_secret`, and optional `wp_username` / `wp_app_password`
(WordPress application password, required for image/media uploads).

### Tuning env vars

| Var | Default | Purpose |
|-----|--------:|---------|
| `WOO_PRODUCTS_PER_JOB` | `5` | Products processed per push/pull batch (push clamped 1–100, pull 1–200). Higher = fewer batches, longer per job. |
| `WOO_STOCK_PRODUCTS_PER_JOB` | `1` | Products per stock batch. |
| `QUEUE_WORKER_TIMEOUT` | `1200` | Per-job timeout (s) for inline/scheduled workers. Must exceed the slowest single batch. |
| `WOO_REMOTE_INDEX_TTL` | `900` | Remote index cache TTL (s); capped at 3600. `0` disables caching. |
| `WOO_REMOTE_INDEX_PAGE_CAP` | `200` | Max pages (×100) when building the index. Raise for very large stores. |
| `WOO_SYNC_STUCK_SECONDS` | `600` | Heartbeat staleness before a run is marked stuck. |
| `WOO_SYNC_QUEUE_WAIT_SECONDS` | `1800` | Extra grace while between batches (`queued*` stages). |
| `WOO_POLL_TICK_MAX_SECONDS` | `300` | Max time a no-cron browser tick may run a batch inline. |
| `WOO_MAX_CONSECUTIVE_FAILURES` | `7` | Abort a push after this many consecutive Woo API failures. |
| `WOO_TIMEOUT_MS_BATCH` | `30000` | Timeout (ms) for Woo batch create/update calls. |
| `WOO_HTTP_RETRIES` / `WOO_HTTP_RETRY_BASE_MS` | `2` / `300` | Client retry count and backoff base. |
| `WOO_WP_MEDIA_UPLOAD_TIMEOUT` | `60`/`120` | Timeout (s) for WordPress media uploads. |
| `WOO_WP_MEDIA_RETRIES` / `WOO_WP_MEDIA_RETRY_SLEEP_MS` | `1`–`2` / `250`–`800` | Media upload retry behaviour. |
| `WOO_WP_MEDIA_SEARCH_TIMEOUT` | `10`/`15` | Timeout (s) for existing-media lookups. |
| `WOO_API_BASE_URL` | — | Override the API base (tunneling/local proxy); keeps the real `Host` header. |
| `WOO_PULL_STATS_PAGE_CAP` / `WOO_AUTOLINK_PAGE_CAP` | `200` | Page caps for pull-stats and auto-link scans. |
| `WOO_PULL_ORDER_NOTES_MAX` | `25` | Max Woo order notes pulled per order. |
| `WOO_SKU_SEARCH_PAGE_CAP` | `5` | Page cap for per-SKU remote searches. |
| `WOO_*_VARIATIONS_PAGE_CAP` | `50`–`100` | Page caps for variation list/pull/delete. |
| `WOO_ALLOW_ALT_SKU_ON_LOOKUP_CONFLICT` | `false` | Allow alternate-SKU linking when a lookup is ambiguous. |

---

## 8. API endpoints

All under the tenant API prefix and gated by `tenant.feature:woocommerce`
(see [`routes/tenant_api.php`](../../../routes/tenant_api.php)). Key ones:

- **Settings/connection:** `GET|POST woocommerce/settings`, `POST woocommerce/test-connection`
- **Start sync:** `POST woocommerce/sync/products` (`?mode=push|pull&only_unsynced=1`),
  `POST woocommerce/sync/stock`, `POST woocommerce/sync/{categories|brands|customers|orders}`
- **Progress:** `GET woocommerce/sync/products/progress?token=…`,
  `GET woocommerce/sync/stock/progress?token=…`, `GET sync/status/{id}`, `GET woo-sync/latest`
- **Stop/cancel:** `POST woocommerce/sync/products/stop`, `POST woo-sync/{id}/cancel`
- **Stats:** `GET woocommerce/{products|categories|brands}/pull-stats`,
  `GET woocommerce/customers/stats`, `GET woocommerce/*/unsynced-count`, `GET woocommerce/stock-metrics`
- **Maintenance:** `woocommerce/reset-*`, `woocommerce/products/{fix-categories,auto-link}`,
  `GET|DELETE woocommerce/logs`

---

## 9. Troubleshooting

| Symptom | Likely cause | Fix |
|---------|--------------|-----|
| Imports a few products then stuck at `queued_next_batch` | No worker consuming `woocommerce-sync` / `woocommerce-stock` | Configure a worker (§3 Option A or B). The first batch runs via `dispatchAfterResponse`, so a few products always import even with no worker — that's the tell. |
| `stuck: no worker heartbeat for Ns` in the UI | Worker not running, or watching the wrong queues | Confirm the worker's `--queue` includes both woo queues; check `php artisan queue:work` is alive. |
| Very slow large pushes / Woo rate-limit errors | Remote index rebuilt too often, or batch too small | Ensure `WOO_REMOTE_INDEX_TTL > 0`; raise `WOO_PRODUCTS_PER_JOB`. |
| Duplicate products created on Woo after a reset | Stale remote index | Already handled (reset invalidates the index). If it recurs, set `WOO_REMOTE_INDEX_TTL=0` temporarily. |
| Images not uploading | Missing/invalid `wp_username` + `wp_app_password`, or media timeout | Set a WordPress application password; raise `WOO_WP_MEDIA_UPLOAD_TIMEOUT`. |
| Sync only advances while the browser tab is open | Relying on the no-cron tick | Set up a worker/scheduler (§3). |

Logs: `GET woocommerce/logs` (UI) or the `woocommerce_logs` table; worker output in
`storage/logs/`.

---

## 10. History / design notes

- Jobs previously dispatched to **dynamic per-sync queues** (`woocommerce-sync-{id}`), which no
  static worker could subscribe to — the root cause of the `queued_next_batch` hangs. Now fixed to
  the static `woocommerce-sync` / `woocommerce-stock` names.
- The remote index was previously rebuilt on **every** batch (full catalog download per ~5
  products); it is now cached per store across a run.
