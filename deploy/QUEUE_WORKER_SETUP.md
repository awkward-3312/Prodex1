# WooCommerce Queue Worker Setup

WooCommerce product/stock syncs run as **batched background jobs**. Each batch queues the next
one on a fixed queue:

- products push & pull → `woocommerce-sync`
- stock → `woocommerce-stock`

**Something must process those queues, or syncs hang at `queued_next_batch` after the first
batch.** This guide describes the three supported ways to do that. Pick **one** persistent strategy
for production so workers are not duplicated unnecessarily.

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

## Option A — Supervisor (recommended for the PRODEX VPS)

Supervisor is a Linux process manager that keeps the worker running forever: it starts it on
boot and restarts it automatically if it crashes or exits.

### 1. Install Supervisor
```bash
# Debian / Ubuntu
sudo apt-get update && sudo apt-get install -y supervisor

# RHEL / CentOS / AlmaLinux
sudo yum install -y supervisor && sudo systemctl enable --now supervisord
```

### 2. Check for an existing worker before installing another one

Do **not** install the PRODEX config blindly. First inspect the active process manager configuration:

```bash
sudo supervisorctl status
sudo grep -R "queue:work\|queue:listen" /etc/supervisor /etc/supervisord* 2>/dev/null
systemctl list-units --type=service --all | grep -Ei 'queue|worker|stocky|prodex'
ps aux | grep -E '[p]hp .*artisan (queue:work|queue:listen)'
```

If an existing worker already consumes `woocommerce-sync,woocommerce-stock,default` for
`/var/www/prodex`, update/reuse that worker instead of starting a duplicate.

### 3. Install the PRODEX config

A ready-made PRODEX template ships at [`deploy/supervisor/prodex-queue-worker.conf`](supervisor/prodex-queue-worker.conf):

```bash
sudo cp deploy/supervisor/prodex-queue-worker.conf /etc/supervisor/conf.d/prodex-queue-worker.conf
```

The repository also keeps `stocky-queue-worker.conf` as a legacy/reference file. Do not install
that historical config on the PRODEX VPS.

The PRODEX config is prepared for:

- application root: `/var/www/prodex`
- user: `prodexadmin`
- queues: `woocommerce-sync,woocommerce-stock,default`
- log: `/var/www/prodex/storage/logs/queue-worker.log`

Review those values before enabling it on another server.

The command it runs is equivalent to:

```bash
php /var/www/prodex/artisan queue:work database \
  --queue=woocommerce-sync,woocommerce-stock,default \
  --sleep=1 --tries=1 --timeout=1200 --max-time=3600
```

Key flags explained:

| Flag | Why |
|------|-----|
| `--queue=woocommerce-sync,woocommerce-stock,default` | Listen to the sync queues (order = priority) plus the normal `default` queue. **Must include the two WooCommerce queues.** |
| `--tries=1` | Jobs manage their own batching/idempotency; don't auto-retry. |
| `--timeout=1200` | Max seconds for a single batch. Must exceed your slowest batch (image uploads can be slow). |
| `--max-time=3600` | Recycle the worker hourly to release memory; Supervisor restarts it instantly. |
| `--sleep=1` | Wait 1s when the queue is empty before checking again. |

### 4. Start it only after confirming there is no duplicate worker

```bash
sudo supervisorctl reread
sudo supervisorctl update
sudo supervisorctl start prodex-queue-worker:*
sudo supervisorctl status
```

### 5. After every deploy

New code isn't picked up by a long-running worker until it restarts:

```bash
php artisan queue:restart
```

Supervisor should then restart the process automatically. If a direct restart is needed:

```bash
sudo supervisorctl restart prodex-queue-worker:*
```

### Scaling

To process more batches in parallel, raise `numprocs` in the config only after confirming the
jobs are safe to run concurrently, then run `reread` + `update`.

### systemd alternative

If you deliberately use systemd instead of Supervisor, create `/etc/systemd/system/prodex-worker.service`:

```ini
[Unit]
Description=PRODEX queue worker
After=network.target mysql.service

[Service]
User=prodexadmin
Restart=always
WorkingDirectory=/var/www/prodex
ExecStart=/usr/bin/php /var/www/prodex/artisan queue:work database --queue=woocommerce-sync,woocommerce-stock,default --sleep=1 --tries=1 --timeout=1200 --max-time=3600

[Install]
WantedBy=multi-user.target
```

Then:

```bash
sudo systemctl daemon-reload
sudo systemctl enable --now prodex-worker
sudo systemctl status prodex-worker
```

Use **either** the intended Supervisor worker or this systemd service unless you intentionally
want multiple workers.

---

## Option B — Cron scheduler (shared hosting)

No root or no Supervisor? Use Laravel's scheduler. The app schedules a worker that drains the
sync queues periodically (see `app/Console/Kernel.php`).

Add one cron job that runs Laravel's scheduler every minute:

```cron
* * * * * cd /home/USER/path-to-app && php artisan schedule:run >> /dev/null 2>&1
```

Verify the current scheduler definition in the deployed code with:

```bash
php artisan schedule:list
```

Do not run this scheduled queue-draining strategy in parallel with a persistent worker unless
that concurrency is intentional.

---

## Option C — Manual / no-cron fallback

With **no worker and no cron**, the app can still progress through its integration fallback while
the sync page is active. This is suitable for local development or occasional manual use, not the
preferred production setup.

### One-shot CLI

```bash
php artisan woocommerce:sync --scope=all
php artisan woocommerce:sync --scope=products
php artisan woocommerce:sync --scope=stock
php artisan woocommerce:sync --scope=products --only-unsynced
```

---

## Troubleshooting

| Symptom | Cause | Fix |
|---------|-------|-----|
| Sync stalls at `queued_next_batch` | No worker on the WooCommerce queues | Confirm a worker watches `woocommerce-sync,woocommerce-stock`. |
| `stuck: no worker heartbeat for Ns` | Worker not running / watching wrong queues | `sudo supervisorctl status`; inspect the queue list. |
| New code/behaviour not taking effect | Long-running worker still on old code | `php artisan queue:restart`. |
| Jobs pile up but never run | Wrong `QUEUE_CONNECTION`, or `jobs` table missing | Ensure `QUEUE_CONNECTION=database` and the table exists. |
| Duplicate processing / unexpected concurrency | More than one worker strategy is active | Inspect Supervisor, systemd, cron and running `artisan queue:*` processes. |

Inspect queued/failed work with the Laravel commands supported by the installed framework version:

```bash
php artisan queue:failed
php artisan list | grep -E 'queue|woocommerce|prodex:tenant'
```

See also: [`app/Services/WooCommerce/README.md`](../app/Services/WooCommerce/README.md) for the
integration reference and tuning variables.
