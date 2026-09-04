<?php

namespace Tests\Support;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * MS6-B0A — serial / IMEI ledger tables for the legacy characterization
 * ("golden master") suites.
 *
 * Mirrors the production migrations EXACTLY as of HEAD bf56686:
 *   - 2026_06_22_000001_create_product_serials_table
 *   - 2026_06_22_000002_create_product_serial_movements_table
 *   - 2026_08_21_183000_add_location_tracking_to_batches_and_serials
 *     (product_serials.inventory_location_id, product_serial_movements
 *      .from_/to_inventory_location_id)
 *
 * NOTE the deliberate omissions (they are what MS6-B0 foundation will ADD):
 *   - product_serials has NO deleted_at        (no SoftDeletes today)
 *   - product_serial_movements has NO idempotency_key / unique constraint
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
                // NO $t->softDeletes()  — matches production.
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
                $t->timestamp('created_at', 6)->nullable();
                // NO idempotency_key, NO unique constraint — matches production.
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
