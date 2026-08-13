# WooCommerce Queue Worker Setup

WooCommerce product/stock syncs run as **batched background jobs**. Each batch queues the next
one on a fixed queue:

- products push & pull → `woocommerce-sync`
- stock → `woocommerce-stock`

**Something must process those queues, or syncs hang at `queued_next_batch` after the first
batch.** This guide describes the three supported ways to do that. Pick **one** (A is
recommended for real servers). They can coexist safely.

| Option | Best for | Runs unattended? | Needs extra software? |
|--------|----------|:---:|:---:|
| **A — Supervisor** | VPS / dedicated / self-hosted SaaS (Linux) | ✅ | Supervisor |
| **B — Cron scheduler** | Shared hosting (no root) | ✅ | Just cron |
| **C — Manual / no-cron** | Occasional manual syncs, local dev | ⚠️ only while the page is open | None |

> Quick check that a worker is the problem: start a sync. If it imports a few products then
> stalls at `queued_next_batch`, no worker is consuming the queues. The first batch always runs
> (it executes in the web request), which is why a few items import even with no worker.

---

## Prerequisites (all options)

- The store is connected (Settings tab shows **Connected**).
- The queue connection is `database` (default). Confirm `.env`:
  ```env
  QUEUE_CONNECTION=database
  ```
- The `jobs` table exists. If not:
  ```bash
  php artisan queue:table   # only if the migration doesn't exist yet
  php artisan migrate
  ```
- This is a **multi-tenant** app: each tenant has its own database and its own `jobs` table.
  A single worker process handles whichever tenant context the job carries — you do **not** need
  one worker per tenant.

---

## Option A — Supervisor (recommended)

Supervisor is a Linux process manager that keeps the worker running forever: it starts it on
boot and restarts it automatically if it crashes or exits.

### 1. Install Supervisor
```bash
# Debian / Ubuntu
sudo apt-get update && sudo apt-get install -y supervisor

# RHEL / CentOS / AlmaLinux
sudo yum install -y supervisor && sudo systemctl enable --now supervisord
```

### 2. Install the config
A ready-made template ships at [`deploy/supervisor/stocky-queue-worker.conf`](supervisor/stocky-queue-worker.conf):
```bash
sudo cp deploy/supervisor/stocky-queue-worker.conf /etc/supervisor/conf.d/
```

### 3. Edit it for your server
Open `/etc/supervisor/conf.d/stocky-queue-worker.conf` and set:

- **`command`** — the absolute path to `php` and to your `artisan` file
- **`directory`** — your application root
- **`user`** — the web/app user (often `www-data`, `nginx`, or your cPanel user)
- **`stdout_logfile`** — a writable log path

The command it runs:
```bash
php /var/www/stocky/artisan queue:work database \
  --queue=woocommerce-sync,woocommerce-stock,default \
  --sleep=1 --tries=1 --timeout=1200 --max-time=3600
```

Key flags explained:

| Flag | Why |
|------|-----|
| `--queue=woocommerce-sync,woocommerce-stock,default` | Listen to the sync queues (order = priority) plus the normal `default` queue. **Must include the two woo queues.** |
| `--tries=1` | Jobs manage their own batching/idempotency; don't auto-retry. |
| `--timeout=1200` | Max seconds for a single batch. Must exceed your slowest batch (image uploads can be slow). |
| `--max-time=3600` | Recycle the worker hourly to release memory; Supervisor restarts it instantly. |
| `--sleep=1` | Wait 1s when the queue is empty before checking again. |

### 4. Start it
```bash
sudo supervisorctl reread
sudo supervisorctl update
sudo supervisorctl start stocky-queue-worker:*
sudo supervisorctl status            # should show RUNNING
```

### 5. After every deploy
New code isn't picked up by a long-running worker until it restarts:
```bash
php artisan queue:restart            # graceful: tells workers to finish current job and exit
# Supervisor then auto-restarts them. Or force it:
sudo supervisorctl restart stocky-queue-worker:*
```

### Scaling
To process more batches in parallel, raise `numprocs` in the config (e.g. `numprocs=3`), then
`reread` + `update`. Each process handles one batch at a time.

### systemd alternative
If you prefer systemd over Supervisor, create `/etc/systemd/system/stocky-worker.service`:
```ini
[Unit]
Description=Stocky queue worker
After=network.target mysql.service

[Service]
User=www-data
Restart=always
WorkingDirectory=/var/www/stocky
ExecStart=/usr/bin/php /var/www/stocky/artisan queue:work database --queue=woocommerce-sync,woocommerce-stock,default --sleep=1 --tries=1 --timeout=1200 --max-time=3600

[Install]
WantedBy=multi-user.target
```
Then:
```bash
sudo systemctl daemon-reload
sudo systemctl enable --now stocky-worker
sudo systemctl status stocky-worker
```

---

## Option B — Cron scheduler (shared hosting)

No root, no Supervisor? Use Laravel's scheduler. The app already schedules a worker that drains
the sync queues every minute (see `app/Console/Kernel.php`):

```bash
queue:work database --stop-when-empty --max-time=50 \
  --queue=woocommerce-sync,woocommerce-stock,default --sleep=1 --tries=1 --timeout=...
```

It drains all pending batches (up to ~50s) then exits, resuming the next minute. Slower than a
persistent worker, but needs only a single cron entry.

### Set up the cron
Add **one** cron job that runs the Laravel scheduler every minute. In cPanel → *Cron Jobs*, or
via `crontab -e`:

```cron
* * * * * cd /home/USER/path-to-app && php artisan schedule:run >> /dev/null 2>&1
```

Replace the path with your application root. That single line is all that's required — the
scheduler triggers the queue draining internally.

### Verify
```bash
php artisan schedule:list      # shows the scheduled queue:work entry
```
Then start a sync from the UI and watch it progress (it advances roughly once per minute as the
cron fires).

### Tuning for shared hosting
If batches get killed by short PHP limits, lower the batch size so each batch finishes faster:
```env
WOO_PRODUCTS_PER_JOB=3
QUEUE_WORKER_TIMEOUT=600
```

---

## Option C — Manual / no-cron fallback

With **no worker and no cron**, the app still works through the **progress-polling endpoints**:
while the sync page is open, each poll runs one batch inline (guarded by a lock).

- ✅ Zero setup — "Sync now" just works.
- ⚠️ **Only progresses while the browser tab stays open and keeps polling.** Close the tab and
  the sync pauses at `queued_next_batch` until you reopen it.
- Best for small, occasional syncs or local development.

### One-shot CLI (manual, but completes without the browser)
You can also run a full sync from the command line; it runs its own inline worker loop until
finished:
```bash
php artisan woocommerce:sync --scope=all        # products + stock
php artisan woocommerce:sync --scope=products
php artisan woocommerce:sync --scope=stock
php artisan woocommerce:sync --scope=products --only-unsynced
```
This is handy for a manual catch-up, or to schedule a periodic full sync via cron.

---

## Troubleshooting

| Symptom | Cause | Fix |
|---------|-------|-----|
| Sync stalls at `queued_next_batch` | No worker on the woo queues | Set up Option A or B; confirm `--queue` includes `woocommerce-sync,woocommerce-stock`. |
| `stuck: no worker heartbeat for Ns` | Worker not running / watching wrong queues | `supervisorctl status`; check the queue list. |
| New code/behaviour not taking effect | Long-running worker still on old code | `php artisan queue:restart` (or restart Supervisor). |
| Batches killed mid-run on shared hosting | PHP time/memory limits | Lower `WOO_PRODUCTS_PER_JOB`; raise `QUEUE_WORKER_TIMEOUT`. |
| Jobs pile up but never run | Wrong `QUEUE_CONNECTION`, or `jobs` table missing | Ensure `QUEUE_CONNECTION=database` and the table exists. |

Inspect what's queued / failed:
```bash
php artisan queue:monitor woocommerce-sync,woocommerce-stock,default
php artisan queue:failed
```

See also: [`app/Services/WooCommerce/README.md`](../app/Services/WooCommerce/README.md) for the
full integration reference and tuning env vars.
