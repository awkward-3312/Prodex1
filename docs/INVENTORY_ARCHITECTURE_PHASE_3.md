# PRODEX Inventory Architecture — Phase 3

## Goal

Phase 3 prepares existing tenants for location-based inventory without switching any production flow away from `product_warehouse`.

Every existing warehouse is treated conservatively as an existing distribution center / legacy stock owner. Its current stock is mapped into one default `InventoryLocation` named `Inventario principal`.

## Safety sequence

1. Run tenant schema upgrade so Phase 1 and Phase 2 tables exist.
2. Run audit only:

   `php artisan prodex:inventory-reconcile`

3. Review negative legacy quantities and any differences.
4. Apply only after audit is understood:

   `php artisan prodex:inventory-reconcile --apply`

5. Run audit again. Every warehouse/CD must show exact reconciliation before any dual-write feature is enabled.

Tenant and warehouse filters are available:

- `--tenants=<tenant-id>` (repeatable)
- `--warehouse=<warehouse-id>`

## Backfill behavior

For each legacy warehouse/CD:

- reuse its valid default inventory location if one exists;
- otherwise create `MAIN / Inventario principal` owned by that warehouse;
- aggregate `product_warehouse` by product and variant;
- reject the warehouse if any aggregate quantity is negative;
- refuse to overwrite non-empty location stock that differs from legacy;
- write initial quantities through `InventoryService` so the initialization has a ledger entry;
- use deterministic idempotency keys per warehouse/product/variant;
- reconcile every product/variant after insertion;
- rollback the complete warehouse transaction if reconciliation is not exact.

## Important compatibility rule

After Phase 3, `product_warehouse` is STILL the production source of truth. The location engine contains a verified mirror, but POS, purchases, transfers, ecommerce, returns, damages and reports continue using legacy stock until their individual migration phase.

No feature flag should switch reads to `inventory_location_stocks` merely because backfill succeeded.

## Existing warehouse classification

Existing warehouses are preserved as legacy distribution centers for migration safety. Their historical IDs and references are not renamed or deleted. A later organizational migration can present them to users as CD/Almacén while keeping old foreign keys valid.
