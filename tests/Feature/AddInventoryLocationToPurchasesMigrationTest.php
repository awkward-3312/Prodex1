<?php

namespace Tests\Feature;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

/**
 * MS1 — exercises the additive migration
 * 2026_09_02_000000_add_inventory_location_to_purchases_and_returns.php
 * against a schema that already has `purchases` / `purchase_returns`.
 *
 * The project skips real migrations in tests (Tests\TestCase), so this test
 * builds the two base tables by hand, then runs the migration's up()/down()
 * exactly as the migrator would.
 */
class AddInventoryLocationToPurchasesMigrationTest extends TestCase
{
    private const MIGRATION =
        'database/migrations/tenant/2026_09_02_000000_add_inventory_location_to_purchases_and_returns.php';

    protected function setUp(): void
    {
        parent::setUp();

        foreach (['purchases', 'purchase_returns'] as $table) {
            Schema::create($table, function ($t) {
                $t->increments('id');
                $t->integer('warehouse_id')->nullable();
                $t->string('statut')->nullable();
                $t->timestamps();
                $t->softDeletes();
            });
        }

        // Historical rows that must survive the migration untouched.
        DB::table('purchases')->insert([
            ['warehouse_id' => 1, 'statut' => 'received', 'created_at' => now(), 'updated_at' => now()],
            ['warehouse_id' => 2, 'statut' => 'pending', 'created_at' => now(), 'updated_at' => now()],
        ]);
        DB::table('purchase_returns')->insert([
            ['warehouse_id' => 1, 'statut' => 'completed', 'created_at' => now(), 'updated_at' => now()],
        ]);
    }

    private function migration()
    {
        return require dirname(__DIR__, 2).'/'.self::MIGRATION;
    }

    public function test_up_adds_nullable_columns_and_leaves_historical_rows_null(): void
    {
        foreach (['purchases', 'purchase_returns'] as $table) {
            $this->assertFalse(Schema::hasColumn($table, 'inventory_location_id'));
            $this->assertFalse(Schema::hasColumn($table, 'inventory_effect_snapshot'));
        }

        $this->migration()->up();

        foreach (['purchases', 'purchase_returns'] as $table) {
            $this->assertTrue(Schema::hasColumn($table, 'inventory_location_id'), "$table.inventory_location_id");
            $this->assertTrue(Schema::hasColumn($table, 'inventory_effect_snapshot'), "$table.inventory_effect_snapshot");
        }

        // NO backfill: every pre-existing row keeps NULL in both new columns.
        $this->assertSame(2, DB::table('purchases')->whereNull('inventory_location_id')->count());
        $this->assertSame(2, DB::table('purchases')->whereNull('inventory_effect_snapshot')->count());
        $this->assertSame(1, DB::table('purchase_returns')->whereNull('inventory_location_id')->count());
    }

    public function test_up_columns_are_nullable_and_store_json_snapshot(): void
    {
        $this->migration()->up();

        // nullable: a row may be inserted without touching the new columns.
        $id = DB::table('purchases')->insertGetId([
            'warehouse_id' => 9, 'statut' => 'received', 'created_at' => now(), 'updated_at' => now(),
        ]);
        $row = DB::table('purchases')->find($id);
        $this->assertNull($row->inventory_location_id);
        $this->assertNull($row->inventory_effect_snapshot);

        // a location-native row: integer id + JSON snapshot round-trip.
        $snapshot = [
            'version' => 1, 'revision' => 1, 'document_type' => 'purchase',
            'warehouse_id' => 9, 'inventory_location_id' => 55,
            'effects' => [[
                'source_detail_id' => 7, 'product_id' => 3, 'product_variant_id' => null,
                'quantity_base' => 12.0, 'delta' => 12.0,
            ]],
        ];
        DB::table('purchases')->where('id', $id)->update([
            'inventory_location_id' => 55,
            'inventory_effect_snapshot' => json_encode($snapshot),
        ]);
        $row = DB::table('purchases')->find($id);
        $this->assertSame(55, (int) $row->inventory_location_id);
        $this->assertEquals($snapshot, json_decode($row->inventory_effect_snapshot, true));
    }

    public function test_up_is_idempotent(): void
    {
        $this->migration()->up();
        // hasColumn guards => a second up() is a no-op, not an error.
        $this->migration()->up();
        $this->assertTrue(Schema::hasColumn('purchases', 'inventory_location_id'));
        $this->assertTrue(Schema::hasColumn('purchase_returns', 'inventory_effect_snapshot'));
    }

    public function test_down_drops_both_columns_from_both_tables(): void
    {
        $this->migration()->up();
        $this->migration()->down();

        foreach (['purchases', 'purchase_returns'] as $table) {
            $this->assertFalse(Schema::hasColumn($table, 'inventory_location_id'), "$table.inventory_location_id dropped");
            $this->assertFalse(Schema::hasColumn($table, 'inventory_effect_snapshot'), "$table.inventory_effect_snapshot dropped");
        }
        // base columns + rows still there.
        $this->assertTrue(Schema::hasColumn('purchases', 'warehouse_id'));
        $this->assertSame(2, DB::table('purchases')->count());
    }
}
