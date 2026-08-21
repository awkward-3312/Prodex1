# PRODEX Inventory Architecture — Phase 2

## Purpose

Phase 2 introduces the future stock engine without changing the production source of truth. Existing business flows continue reading and writing `product_warehouse.qte` until a later controlled migration phase.

## New source model

`inventory_locations` identifies the exact physical/logical place where inventory can exist. A location belongs to exactly one owner:

- a branch (`branch_id`), or
- a distribution center / warehouse (`warehouse_id`).

`inventory_location_stocks` stores quantity per location, product and variant. It separates:

- `quantity`: physical stock,
- `reserved_quantity`: physical stock already committed,
- available stock: `quantity - reserved_quantity`.

The `variant_key` column avoids the MySQL multiple-NULL uniqueness problem: `0` represents a simple product and a positive value represents a product variant.

## Movement ledger

Every mutation performed through `InventoryService` creates an `inventory_location_movements` row. Supported engine operations are:

- increase,
- decrease,
- transfer between locations,
- reserve,
- release reservation,
- consume reserved inventory,
- absolute stock adjustment.

Movement records can include user, reference type/id, notes, metadata and an idempotency key.

## Safety rules

- No negative physical stock.
- No negative reserved stock.
- Reserved stock cannot exceed physical stock.
- Normal decrease/move operations can only consume available stock.
- An adjustment cannot put physical stock below reserved stock.
- Movement between locations locks stock rows in deterministic location order to reduce deadlocks.
- Stock changes and ledger writes happen in one DB transaction.
- Idempotency keys prevent the same request from mutating stock twice.
- Inactive or deleted inventory locations cannot be used by the engine.

## Compatibility contract

Phase 2 MUST NOT:

- change `product_warehouse.qte`,
- redirect POS to the new stock engine,
- redirect sales/purchases/returns/transfers to the new stock engine,
- backfill legacy quantities yet,
- make `inventory_location_stocks` authoritative in production.

The new engine remains parallel and empty until the reconciliation/backfill phase.

## Next phase

Before dual-write begins:

1. create one default `InventoryLocation` for each existing warehouse/CD;
2. build a deterministic backfill from `product_warehouse` to that location;
3. reconcile totals per warehouse/product/variant;
4. reject activation if any difference exists;
5. add a compatibility layer so migrated flows can write legacy + new stock atomically;
6. migrate business domains one at a time (POS, purchases, transfers, damages, ecommerce, reports);
7. only after sustained reconciliation make location stock authoritative.
