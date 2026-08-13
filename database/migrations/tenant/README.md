# Tenant migrations

These migrations run **only on tenant databases** when a new tenant is created (via `php artisan tenants:migrate` or the TenantCreated event pipeline).

They contain the full Stocky business schema: users, products, sales, purchases, warehouses, settings, etc.

- **Do not** add central-only tables here (tenants, domains, plans, tenant_subscriptions, tenant_billing_payments). Those live in `database/migrations/` and run on the central connection.
- Migrations in this folder use the default (tenant) connection when run in tenant context.
