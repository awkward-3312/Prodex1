<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * MS6-B0 — serial location-native FOUNDATION (engine inactive).
 *
 *  A. product_serial_movements.idempotency_key  — nullable string(191) UNIQUE.
 *     Legacy movements keep NULL (MySQL allows many NULLs in a unique index);
 *     native set operations write a deterministic key per serial.
 *  B. product_serial_movements.idempotency_fingerprint — nullable string(64).
 *     Lets a set replay verify it is the SAME operation (never a silent
 *     collision). Mirrors inventory_location_movements.idempotency_fingerprint.
 *  C. product_serials composite index
 *     (product_id, product_variant_id, inventory_location_id, status) — the
 *     exact predicate the native planner / POS B1 preflight hammer. The
 *     existing ps_pvws_idx is on warehouse_id, not inventory_location_id.
 *
 * NO deleted_at, NO new serial table, NO purchase_detail_serials pivot,
 * NO new foreign keys.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('product_serial_movements')) {
            Schema::table('product_serial_movements', function (Blueprint $table) {
                if (! Schema::hasColumn('product_serial_movements', 'idempotency_key')) {
                    $table->string('idempotency_key', 191)->nullable()->unique('psm_idempotency_key_uq');
                }
                if (! Schema::hasColumn('product_serial_movements', 'idempotency_fingerprint')) {
                    $table->string('idempotency_fingerprint', 64)->nullable();
                }
            });
        }

        if (Schema::hasTable('product_serials') && Schema::hasColumn('product_serials', 'inventory_location_id')) {
            Schema::table('product_serials', function (Blueprint $table) {
                $table->index(
                    ['product_id', 'product_variant_id', 'inventory_location_id', 'status'],
                    'ps_pvls_idx'
                );
            });
        }
    }

    public function down(): void
    {
        if (Schema::hasTable('product_serials')) {
            Schema::table('product_serials', function (Blueprint $table) {
                try {
                    $table->dropIndex('ps_pvls_idx');
                } catch (\Throwable $e) {
                    // index may not exist on a partial rollback — safe to ignore.
                }
            });
        }

        if (Schema::hasTable('product_serial_movements')) {
            Schema::table('product_serial_movements', function (Blueprint $table) {
                if (Schema::hasColumn('product_serial_movements', 'idempotency_key')) {
                    try {
                        $table->dropUnique('psm_idempotency_key_uq');
                    } catch (\Throwable $e) {
                    }
                    $table->dropColumn('idempotency_key');
                }
                if (Schema::hasColumn('product_serial_movements', 'idempotency_fingerprint')) {
                    $table->dropColumn('idempotency_fingerprint');
                }
            });
        }
    }
};
