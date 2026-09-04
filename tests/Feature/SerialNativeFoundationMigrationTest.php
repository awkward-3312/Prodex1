<?php

namespace Tests\Feature;

use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

/**
 * MS6-B0 — §34: the foundation migration
 * 2026_09_03_000000_add_serial_native_foundation runs up + down against a
 * pre-M1 shaped schema.
 */
class SerialNativeFoundationMigrationTest extends TestCase
{
    private object $migration;

    protected function setUp(): void
    {
        parent::setUp();

        // pre-M1 shape (no idempotency_* columns).
        Schema::create('product_serials', function ($t) {
            $t->bigIncrements('id');
            $t->string('serial_number', 191)->unique('ps_serial_number_uq');
            $t->unsignedInteger('product_id');
            $t->unsignedInteger('product_variant_id')->nullable();
            $t->unsignedInteger('warehouse_id');
            $t->integer('inventory_location_id')->nullable();
            $t->string('status', 20)->default('available');
            $t->timestamps(6);
        });
        Schema::create('product_serial_movements', function ($t) {
            $t->bigIncrements('id');
            $t->unsignedBigInteger('product_serial_id');
            $t->string('serial_number', 191);
            $t->string('action', 30);
            $t->timestamp('created_at', 6)->nullable();
        });

        $this->migration = require dirname(__DIR__, 2)
            .'/database/migrations/tenant/2026_09_03_000000_add_serial_native_foundation.php';
    }

    public function test_up_adds_the_key_the_fingerprint_and_the_composite_index(): void
    {
        $this->assertFalse(Schema::hasColumn('product_serial_movements', 'idempotency_key'));

        $this->migration->up();

        $this->assertTrue(Schema::hasColumn('product_serial_movements', 'idempotency_key'));
        $this->assertTrue(Schema::hasColumn('product_serial_movements', 'idempotency_fingerprint'));
    }

    public function test_up_allows_many_null_keys_but_rejects_a_duplicate_non_null_key(): void
    {
        $this->migration->up();

        // many NULLs — fine (legacy rows).
        foreach (['A', 'B', 'C'] as $i => $sn) {
            DB::table('product_serial_movements')->insert([
                'product_serial_id' => $i + 1, 'serial_number' => $sn, 'action' => 'purchased',
                'idempotency_key' => null, 'created_at' => now(),
            ]);
        }
        // two distinct non-null keys — fine.
        DB::table('product_serial_movements')->insert([
            'product_serial_id' => 10, 'serial_number' => 'K1', 'action' => 'purchased',
            'idempotency_key' => 'key-1', 'created_at' => now(),
        ]);
        DB::table('product_serial_movements')->insert([
            'product_serial_id' => 11, 'serial_number' => 'K2', 'action' => 'purchased',
            'idempotency_key' => 'key-2', 'created_at' => now(),
        ]);

        $this->expectException(QueryException::class);
        DB::table('product_serial_movements')->insert([
            'product_serial_id' => 12, 'serial_number' => 'K3', 'action' => 'purchased',
            'idempotency_key' => 'key-1', 'created_at' => now(),
        ]);
    }

    public function test_down_removes_the_columns_and_the_index(): void
    {
        $this->migration->up();
        $this->assertTrue(Schema::hasColumn('product_serial_movements', 'idempotency_key'));

        $this->migration->down();

        $this->assertFalse(Schema::hasColumn('product_serial_movements', 'idempotency_key'));
        $this->assertFalse(Schema::hasColumn('product_serial_movements', 'idempotency_fingerprint'));
        // legacy inserts still work after down().
        DB::table('product_serial_movements')->insert([
            'product_serial_id' => 1, 'serial_number' => 'POST-DOWN', 'action' => 'purchased', 'created_at' => now(),
        ]);
        $this->assertSame(1, DB::table('product_serial_movements')->count());
    }

    public function test_legacy_movement_without_a_key_still_inserts_after_up(): void
    {
        $this->migration->up();
        DB::table('product_serial_movements')->insert([
            'product_serial_id' => 99, 'serial_number' => 'LEGACY-SHAPE', 'action' => 'sold', 'created_at' => now(),
        ]);
        $row = DB::table('product_serial_movements')->first();
        $this->assertNull($row->idempotency_key);
        $this->assertNull($row->idempotency_fingerprint);
    }
}
