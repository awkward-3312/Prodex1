<?php

namespace Tests\Support;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * MS6-B0A — serial / IMEI ledger tables for the legacy characterization
 * ("golden master") suites.
 *
 * Mirrors the production migrations:
 *   - 2026_06_22_000001_create_product_serials_table
 *   - 2026_06_22_000002_create_product_serial_movements_table
 *   - 2026_08_21_183000_add_location_tracking_to_batches_and_serials
 *   - 2026_09_03_000000_add_serial_native_foundation (MS6-B0):
 *       product_serial_movements.idempotency_key (nullable UNIQUE) +
 *       .idempotency_fingerprint, and product_serials.ps_pvls_idx.
 *
 * NOTE the deliberate omission (unchanged in MS6-B0):
 *   - product_serials has NO deleted_at   (no SoftDeletes — `voided` status).
 *
 * Complements Tests\Support\LegacyPurchaseTestSchema (which provides
 * products.is_imei but NOT the serial tables — so SerialNumberService
 * ::isSupported() is false until buildSerialSchema() runs).
 */
trait SerialTestSchema
{
    protected function buildSerialSchema(): void
    {
        if (! Schema::hasTable('product_serials')) {
            Schema::create('product_serials', function ($t) {
                $t->bigIncrements('id');
                $t->string('serial_number', 191);
                $t->unsignedInteger('product_id');
                $t->unsignedInteger('product_variant_id')->nullable();
                $t->unsignedInteger('warehouse_id');
                $t->integer('inventory_location_id')->nullable();
                $t->string('status', 20)->default('available');
                $t->unsignedBigInteger('purchase_id')->nullable();
                $t->unsignedBigInteger('purchase_detail_id')->nullable();
                $t->unsignedInteger('provider_id')->nullable();
                $t->double('cost')->nullable();
                $t->unsignedBigInteger('sale_id')->nullable();
                $t->unsignedBigInteger('sale_detail_id')->nullable();
                $t->unsignedInteger('client_id')->nullable();
                $t->text('notes')->nullable();
                $t->timestamps(6);
                $t->unique('serial_number', 'ps_serial_number_uq');
                // MS6-B0 — the native-hot predicate index.
                $t->index(['product_id', 'product_variant_id', 'inventory_location_id', 'status'], 'ps_pvls_idx');
                // NO $t->softDeletes()  — `voided` status, not a soft delete.
            });
        }

        if (! Schema::hasTable('product_serial_movements')) {
            Schema::create('product_serial_movements', function ($t) {
                $t->bigIncrements('id');
                $t->unsignedBigInteger('product_serial_id');
                $t->string('serial_number', 191);
                $t->string('action', 30);
                $t->string('from_status', 20)->nullable();
                $t->string('to_status', 20)->nullable();
                $t->unsignedInteger('warehouse_id')->nullable();
                $t->integer('from_inventory_location_id')->nullable();
                $t->integer('to_inventory_location_id')->nullable();
                $t->string('reference_type', 40)->nullable();
                $t->unsignedBigInteger('reference_id')->nullable();
                $t->unsignedInteger('user_id')->nullable();
                $t->text('notes')->nullable();
                // MS6-B0 — legacy rows leave these NULL; native set ops write them.
                $t->string('idempotency_key', 191)->nullable()->unique('psm_idempotency_key_uq');
                $t->string('idempotency_fingerprint', 64)->nullable();
                $t->timestamp('created_at', 6)->nullable();
            });
        }
    }

    // ---------------------------------------------------------------------
    // Read helpers
    // ---------------------------------------------------------------------

    /** All serial rows for a serial_number (there can only ever be 0 or 1). */
    protected function serialRow(string $serialNumber): ?object
    {
        return DB::table('product_serials')->where('serial_number', $serialNumber)->first();
    }

    protected function serialCount(?array $where = null): int
    {
        $q = DB::table('product_serials');
        foreach ($where ?? [] as $k => $v) {
            $q->where($k, $v);
        }

        return (int) $q->count();
    }

    protected function serialMovements(string $serialNumber): array
    {
        return DB::table('product_serial_movements')
            ->where('serial_number', $serialNumber)
            ->orderBy('id')
            ->get()
            ->map(fn ($r) => (array) $r)
            ->all();
    }

    protected function serialMovementCount(?array $where = null): int
    {
        $q = DB::table('product_serial_movements');
        foreach ($where ?? [] as $k => $v) {
            $q->where($k, $v);
        }

        return (int) $q->count();
    }
}
