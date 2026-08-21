<?php

namespace Tests\Unit;

use App\Models\InventoryTransitionState;
use App\Services\InventoryReadService;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

class InventoryReadServiceTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        Schema::create('inventory_transition_states', function ($table) {
            $table->increments('id');
            $table->integer('warehouse_id')->unique();
            $table->integer('inventory_location_id')->nullable();
            $table->string('mode')->default('legacy_only');
            $table->string('status')->default('pending');
            $table->unsignedInteger('mismatch_count')->default(0);
            $table->timestamp('last_audited_at')->nullable();
            $table->timestamp('last_reconciled_at')->nullable();
            $table->timestamp('shadow_enabled_at')->nullable();
            $table->json('metadata')->nullable();
            $table->timestamps();
        });

        Schema::create('product_warehouse', function ($table) {
            $table->increments('id');
            $table->integer('product_id');
            $table->integer('warehouse_id');
            $table->integer('product_variant_id')->nullable();
            $table->decimal('qte', 12, 3)->default(0);
            $table->boolean('manage_stock')->default(true);
            $table->timestamps();
            $table->softDeletes();
        });

        Schema::create('inventory_location_stocks', function ($table) {
            $table->increments('id');
            $table->integer('inventory_location_id');
            $table->integer('product_id');
            $table->integer('product_variant_id')->nullable();
            $table->integer('variant_key')->default(0);
            $table->decimal('quantity', 12, 3)->default(0);
            $table->decimal('reserved_quantity', 12, 3)->default(0);
            $table->boolean('manage_stock')->default(true);
            $table->timestamps();
        });
    }

    public function test_unconfigured_and_shadow_modes_read_legacy_stock(): void
    {
        $this->legacy(1, 10, 4);
        $this->legacy(2, 10, 7);

        InventoryTransitionState::create([
            'warehouse_id' => 2,
            'inventory_location_id' => 20,
            'mode' => InventoryTransitionState::MODE_SHADOW_COMPARE,
            'status' => 'healthy',
        ]);
        $this->location(20, 10, 99);

        $totals = app(InventoryReadService::class)->totalsByProduct([10], [1, 2]);

        $this->assertSame(11.0, (float) $totals[10]);
    }

    public function test_dual_write_mode_still_reads_legacy_source(): void
    {
        $this->legacy(1, 10, 8);
        $this->location(10, 10, 8);
        InventoryTransitionState::create([
            'warehouse_id' => 1,
            'inventory_location_id' => 10,
            'mode' => InventoryTransitionState::MODE_DUAL_WRITE,
            'status' => 'healthy',
        ]);

        $this->assertSame(8.0, app(InventoryReadService::class)->totalForProduct(10, [1]));
    }

    public function test_location_primary_uses_location_stock_only_when_healthy(): void
    {
        $this->legacy(1, 10, 3);
        $this->location(10, 10, 12);
        InventoryTransitionState::create([
            'warehouse_id' => 1,
            'inventory_location_id' => 10,
            'mode' => InventoryTransitionState::MODE_LOCATION_PRIMARY,
            'status' => 'healthy',
        ]);

        $this->assertSame(12.0, app(InventoryReadService::class)->totalForProduct(10, [1]));
    }

    public function test_unhealthy_location_primary_fails_safe_to_legacy(): void
    {
        $this->legacy(1, 10, 5);
        $this->location(10, 10, 50);
        InventoryTransitionState::create([
            'warehouse_id' => 1,
            'inventory_location_id' => 10,
            'mode' => InventoryTransitionState::MODE_LOCATION_PRIMARY,
            'status' => 'mismatch',
        ]);

        $this->assertSame(5.0, app(InventoryReadService::class)->totalForProduct(10, [1]));
    }

    public function test_mixed_sources_are_summed_without_double_counting(): void
    {
        $this->legacy(1, 10, 5);
        $this->legacy(2, 10, 9);
        $this->location(20, 10, 7);

        InventoryTransitionState::create([
            'warehouse_id' => 2,
            'inventory_location_id' => 20,
            'mode' => InventoryTransitionState::MODE_LOCATION_PRIMARY,
            'status' => 'healthy',
        ]);

        $totals = app(InventoryReadService::class)->totalsByProduct([10], [1, 2]);
        $this->assertSame(12.0, (float) $totals[10]);
    }

    private function legacy(int $warehouseId, int $productId, float $quantity): void
    {
        DB::table('product_warehouse')->insert([
            'product_id' => $productId,
            'warehouse_id' => $warehouseId,
            'product_variant_id' => null,
            'qte' => $quantity,
            'manage_stock' => 1,
            'created_at' => now(),
            'updated_at' => now(),
            'deleted_at' => null,
        ]);
    }

    private function location(int $locationId, int $productId, float $quantity): void
    {
        DB::table('inventory_location_stocks')->insert([
            'inventory_location_id' => $locationId,
            'product_id' => $productId,
            'product_variant_id' => null,
            'variant_key' => 0,
            'quantity' => $quantity,
            'reserved_quantity' => 0,
            'manage_stock' => 1,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }
}
