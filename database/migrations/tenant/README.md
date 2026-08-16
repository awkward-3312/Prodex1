# Tenant migrations

These migrations run **only on tenant databases** when a new tenant is created by the TenantCreated event pipeline or by tenant-scoped upgrade commands.

For controlled production upgrades, prefer `php artisan prodex:tenant-upgrade`. This project version does not rely on a `tenants:migrate` namespace for health checks or safe incremental upgrades.

They contain the full Stocky business schema: users, products, sales, purchases, warehouses, settings, etc.

- **Do not** add central-only tables here (tenants, domains, plans, tenant_subscriptions, tenant_billing_payments). Those live in `database/migrations/` and run on the central connection.
- Migrations in this folder use the default (tenant) connection when run in tenant context.
