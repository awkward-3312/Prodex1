<?php

namespace Tests\Unit;

use App\Models\InventoryTransitionState;
use App\Models\Warehouse;
use App\Services\InventoryCompatibilityService;
use App\Services\LegacyInventoryReconciliationService;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;

class InventoryCompatibilityServiceTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        Schema::create('warehouses', function ($table) {
            $table->increments('id');
            $table->integer('branch_id')->nullable();
            $table->integer('default_inventory_location_id')->nullable();
            $table->string('name');
            $table->string('mobile')->nullable();
            $table->string('country')->nullable();
            $table->string('city')->nullable();
            $table->string('email')->nullable();
            $table->string('zip')->nullable();
            $table->timestamps();
            $table->softDeletes();
        });

        Schema::create('inventory_locations', function ($table) {
            $table->increments('id');
            $table->integer('branch_id')->nullable();
            $table->integer('warehouse_id')->nullable();
            $table->string('code');
            $table->string('name');
            $table->string('type')->default('storage');
            $table->boolean('is_sellable')->default(false);
            $table->boolean('is_default_sales')->default(false);
            $table->boolean('is_quarantine')->default(false);
            $table->boolean('is_active')->default(true);
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
            $table->unique(['inventory_location_id', 'product_id', 'variant_key']);
        });

        Schema::create('inventory_location_movements', function ($table) {
            $table->increments('id');
            $table->string('movement_type');
            $table->integer('product_id');
            $table->integer('product_variant_id')->nullable();
            $table->integer('from_inventory_location_id')->nullable();
            $table->integer('to_inventory_location_id')->nullable();
            $table->decimal('quantity', 12, 3);
            $table->integer('user_id')->nullable();
            $table->string('reference_type')->nullable();
            $table->string('reference_id')->nullable();
            $table->string('idempotency_key')->nullable()->unique();
            $table->string('idempotency_fingerprint')->nullable();
            $table->string('notes')->nullable();
            $table->json('metadata')->nullable();
            $table->timestamps();
        });

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
    }

    public function test_legacy_only_reads_product_warehouse_without_requiring_shadow_stock(): void
    {
        $warehouse = $this->warehouse();
        $this->legacy($warehouse->id, 10, 7.5);

        $service = app(InventoryCompatibilityService::class);

        $this->assertSame(7.5, $service->readQuantity($warehouse->id, 10));
        $this->assertSame(InventoryTransitionState::MODE_LEGACY_ONLY, $service->state($warehouse->id)->mode);
        $this->assertNull($service->shadowQuantity($warehouse->id, 10));
    }

    public function test_dual_write_cannot_be_enabled_before_exact_reconciliation(): void
    {
        $warehouse = $this->warehouse();
        $this->legacy($warehouse->id, 10, 4);

        $this->expectException(ValidationException::class);
        app(InventoryCompatibilityService::class)->enableDualWrite($warehouse->id);
    }

    public function test_dual_write_mirrors_resulting_legacy_snapshot_exactly(): void
    {
        $warehouse = $this->warehouse();
        $this->legacy($warehouse->id, 10, 5);

        app(LegacyInventoryReconciliationService::class)->backfillWarehouse($warehouse->id);

        $service = app(InventoryCompatibilityService::class);
        $state = $service->enableDualWrite($warehouse->id);
        $this->assertSame(InventoryTransitionState::MODE_DUAL_WRITE, $state->mode);

        DB::table('product_warehouse')
            ->where('warehouse_id', $warehouse->id)
            ->where('product_id', 10)
            ->update(['qte' => 9]);

        $service->mirrorLegacySnapshot($warehouse->id, 10, null, [
            'reference_type' => 'test_sale',
            'reference_id' => '100',
            'idempotency_key' => 'compat:test:warehouse:'.$warehouse->id.':product:10:9',
        ]);

        $this->assertSame(9.0, $service->legacyQuantity($warehouse->id, 10));
        $this->assertSame(9.0, $service->shadowQuantity($warehouse->id, 10));
        $this->assertTrue($service->compareKey($warehouse->id, 10)['matches']);
    }

    public function test_shadow_compare_never_changes_legacy_or_location_stock(): void
    {
        $warehouse = $this->warehouse();
        $this->legacy($warehouse->id, 10, 5);
        app(LegacyInventoryReconciliationService::class)->backfillWarehouse($warehouse->id);

        $service = app(InventoryCompatibilityService::class);
        $service->enableShadowCompare($warehouse->id);

        DB::table('product_warehouse')
            ->where('warehouse_id', $warehouse->id)
            ->where('product_id', 10)
            ->update(['qte' => 8]);

        $service->mirrorLegacySnapshot($warehouse->id, 10);

        $this->assertSame(8.0, $service->legacyQuantity($warehouse->id, 10));
        $this->assertSame(5.0, $service->shadowQuantity($warehouse->id, 10));
        $this->assertFalse($service->compareKey($warehouse->id, 10)['matches']);
        $this->assertSame('mismatch', $service->state($warehouse->id)->status);
    }

    public function test_read_remains_legacy_in_dual_write_mode(): void
    {
        $warehouse = $this->warehouse();
        $this->legacy($warehouse->id, 10, 6);
        app(LegacyInventoryReconciliationService::class)->backfillWarehouse($warehouse->id);
        $service = app(InventoryCompatibilityService::class);
        $service->enableDualWrite($warehouse->id);

        DB::table('product_warehouse')
            ->where('warehouse_id', $warehouse->id)
            ->where('product_id', 10)
            ->update(['qte' => 11]);

        // Until location_primary is explicitly introduced in a later phase,
        // production reads continue to come from the legacy source.
        $this->assertSame(11.0, $service->readQuantity($warehouse->id, 10));
        $this->assertSame(6.0, $service->shadowQuantity($warehouse->id, 10));
    }

    private function warehouse(): Warehouse
    {
        return Warehouse::create(['name' => 'CD Principal']);
    }

    private function legacy(int $warehouseId, int $productId, float $quantity, ?int $variantId = null): void
    {
        DB::table('product_warehouse')->insert([
            'product_id' => $productId,
            'warehouse_id' => $warehouseId,
            'product_variant_id' => $variantId,
            'qte' => $quantity,
            'manage_stock' => 1,
            'created_at' => now(),
            'updated_at' => now(),
            'deleted_at' => null,
        ]);
    }
}
