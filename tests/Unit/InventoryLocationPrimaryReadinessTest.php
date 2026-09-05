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

/**
 * FINAL INVENTORY CLOSURE — readiness gate for location_primary + the
 * returnToLegacyOnly() demotion guard. Same fixture pattern as
 * InventoryCompatibilityServiceTest (mirrored, not reused via inheritance —
 * that file's setUp() is not extracted into a shared trait).
 */
class InventoryLocationPrimaryReadinessTest extends TestCase
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

        Schema::create('products', function ($table) {
            $table->increments('id');
            $table->string('name')->nullable();
            $table->string('type')->default('is_single');
            $table->boolean('is_batch_tracked')->default(false);
            $table->integer('is_imei')->default(0);
            $table->timestamps();
            $table->softDeletes();
        });

        Schema::create('product_batches', function ($table) {
            $table->increments('id');
            $table->integer('product_id');
            $table->integer('product_variant_id')->nullable();
            $table->integer('warehouse_id')->nullable();
            $table->string('batch_no')->nullable();
            $table->decimal('qty', 12, 3)->default(0);
            $table->string('status')->nullable();
            $table->timestamps();
            $table->softDeletes();
        });

        Schema::create('product_batch_location_stocks', function ($table) {
            $table->increments('id');
            $table->integer('product_batch_id');
            $table->integer('inventory_location_id');
            $table->decimal('quantity', 12, 3)->default(0);
            $table->decimal('reserved_quantity', 12, 3)->default(0);
            $table->timestamps();
        });

        Schema::create('product_serials', function ($table) {
            $table->increments('id');
            $table->string('serial_number')->nullable();
            $table->integer('product_id');
            $table->integer('product_variant_id')->nullable();
            $table->integer('warehouse_id')->nullable();
            $table->integer('inventory_location_id')->nullable();
            $table->string('status')->default('available');
            $table->timestamps();
        });

        Schema::create('transfers', function ($table) {
            $table->increments('id');
            $table->integer('from_warehouse_id')->nullable();
            $table->integer('to_warehouse_id')->nullable();
            $table->string('logistics_status')->nullable();
            $table->timestamps();
            $table->softDeletes();
        });
    }

    // ---- fixtures shared with InventoryCompatibilityServiceTest's style ----

    private function warehouse(): Warehouse
    {
        return Warehouse::create(['name' => 'CD Principal']);
    }

    private function legacy(int $warehouseId, int $productId, float $quantity): void
    {
        DB::table('product_warehouse')->insert([
            'product_id' => $productId, 'warehouse_id' => $warehouseId, 'product_variant_id' => null,
            'qte' => $quantity, 'manage_stock' => 1, 'created_at' => now(), 'updated_at' => now(), 'deleted_at' => null,
        ]);
    }

    private function product(int $id, array $overrides = []): void
    {
        DB::table('products')->insert(array_merge([
            'id' => $id, 'name' => 'P'.$id, 'type' => 'is_single',
            'is_batch_tracked' => false, 'is_imei' => 0,
            'created_at' => now(), 'updated_at' => now(),
        ], $overrides));
    }

    /** A clean, fully reconciled, single-location warehouse — the READY baseline. */
    private function readyWarehouse(int $productId = 10, float $qty = 50): array
    {
        $wh = $this->warehouse();
        $this->product($productId);
        $this->legacy($wh->id, $productId, $qty);
        app(LegacyInventoryReconciliationService::class)->backfillWarehouse($wh->id);
        $main = (int) DB::table('warehouses')->where('id', $wh->id)->value('default_inventory_location_id');

        return [$wh, $main];
    }

    // ---- 1. warehouse ready can be promoted --------------------------------

    public function test_ready_warehouse_can_be_promoted_to_location_primary(): void
    {
        [$wh] = $this->readyWarehouse();

        $service = app(InventoryCompatibilityService::class);
        $readiness = $service->readinessForLocationPrimary($wh->id);
        $this->assertTrue($readiness['ready'], implode(' | ', $readiness['reasons']));

        $state = $service->promoteToLocationPrimary($wh->id);
        $this->assertSame(InventoryTransitionState::MODE_LOCATION_PRIMARY, $state->mode);
        $this->assertSame('healthy', $state->status);
    }

    // ---- 2. general mismatch blocks ----------------------------------------

    public function test_general_unreconciled_mismatch_blocks_promotion(): void
    {
        $wh = $this->warehouse();
        $this->product(10);
        // Legacy stock with NO location-native counterpart at all and no
        // baseline => LEGACY_ONLY_PENDING, not reconciled.
        $this->legacy($wh->id, 10, 40);

        $service = app(InventoryCompatibilityService::class);
        $readiness = $service->readinessForLocationPrimary($wh->id);

        $this->assertFalse($readiness['ready']);
        $this->assertNotEmpty(array_filter($readiness['reasons'], fn ($r) => str_starts_with($r, 'General:')));

        $this->expectException(ValidationException::class);
        $service->promoteToLocationPrimary($wh->id);
    }

    // ---- 3. batch mismatch blocks -------------------------------------------

    public function test_batch_coverage_mismatch_blocks_promotion(): void
    {
        [$wh, $main] = $this->readyWarehouse(10, 50);
        // Make product 10 batch-tracked and desync its batch ledger from the
        // general location quantity (50 general vs 30 in the batch slice).
        DB::table('products')->where('id', 10)->update(['is_batch_tracked' => true]);
        $batchId = DB::table('product_batches')->insertGetId([
            'product_id' => 10, 'warehouse_id' => $wh->id, 'batch_no' => 'B1', 'qty' => 30,
            'created_at' => now(), 'updated_at' => now(),
        ]);
        DB::table('product_batch_location_stocks')->insert([
            'product_batch_id' => $batchId, 'inventory_location_id' => $main, 'quantity' => 30,
            'created_at' => now(), 'updated_at' => now(),
        ]);

        $service = app(InventoryCompatibilityService::class);
        $readiness = $service->readinessForLocationPrimary($wh->id);

        $this->assertFalse($readiness['ready']);
        $this->assertNotEmpty($readiness['batch_mismatches']);
        $this->assertNotEmpty(array_filter($readiness['reasons'], fn ($r) => str_starts_with($r, 'Batch:')));
    }

    // ---- 4. serial mismatch blocks ------------------------------------------

    public function test_serial_coverage_mismatch_blocks_promotion(): void
    {
        [$wh, $main] = $this->readyWarehouse(10, 3);
        DB::table('products')->where('id', 10)->update(['is_imei' => 1]);
        // General quantity is 3 but only 1 available serial is actually
        // located here — coverage mismatch.
        DB::table('product_serials')->insert([
            'serial_number' => 'SN-1', 'product_id' => 10, 'warehouse_id' => $wh->id,
            'inventory_location_id' => $main, 'status' => 'available',
            'created_at' => now(), 'updated_at' => now(),
        ]);

        $service = app(InventoryCompatibilityService::class);
        $readiness = $service->readinessForLocationPrimary($wh->id);

        $this->assertFalse($readiness['ready']);
        $this->assertNotEmpty($readiness['serial_mismatches']);
        $this->assertNotEmpty(array_filter($readiness['reasons'], fn ($r) => str_starts_with($r, 'Serial:')));
    }

    public function test_unmigrated_legacy_serial_without_location_blocks_promotion(): void
    {
        [$wh] = $this->readyWarehouse(10, 50);
        DB::table('products')->where('id', 10)->update(['is_imei' => 1]);
        // Available serial that was never assigned to a location.
        DB::table('product_serials')->insert([
            'serial_number' => 'SN-ORPHAN', 'product_id' => 10, 'warehouse_id' => $wh->id,
            'inventory_location_id' => null, 'status' => 'available',
            'created_at' => now(), 'updated_at' => now(),
        ]);

        $readiness = app(InventoryCompatibilityService::class)->readinessForLocationPrimary($wh->id);

        $this->assertFalse($readiness['ready']);
        $this->assertSame(1, $readiness['unmigrated_legacy_serials']);
    }

    // ---- 5. invalid/quarantine default location blocks ----------------------

    public function test_quarantine_default_location_blocks_promotion(): void
    {
        [$wh, $main] = $this->readyWarehouse(10, 50);
        // The default location itself becomes quarantine after promotion setup.
        DB::table('inventory_locations')->where('id', $main)->update(['is_quarantine' => 1]);

        $readiness = app(InventoryCompatibilityService::class)->readinessForLocationPrimary($wh->id);

        $this->assertFalse($readiness['ready']);
        $this->assertFalse($readiness['has_target_location']);
        $this->assertNotEmpty(array_filter($readiness['reasons'], fn ($r) => str_starts_with($r, 'Location:')));
    }

    // ---- 6. unresolved transfer blocks --------------------------------------

    public function test_pending_transfer_in_transit_blocks_promotion(): void
    {
        [$wh] = $this->readyWarehouse(10, 50);
        DB::table('transfers')->insert([
            'from_warehouse_id' => $wh->id, 'to_warehouse_id' => 999, 'logistics_status' => 'in_transit',
            'created_at' => now(), 'updated_at' => now(),
        ]);

        $readiness = app(InventoryCompatibilityService::class)->readinessForLocationPrimary($wh->id);

        $this->assertFalse($readiness['ready']);
        $this->assertSame(1, $readiness['pending_transfers']);
    }

    public function test_fully_received_transfer_does_not_block_promotion(): void
    {
        [$wh] = $this->readyWarehouse(10, 50);
        DB::table('transfers')->insert([
            'from_warehouse_id' => $wh->id, 'to_warehouse_id' => 999, 'logistics_status' => 'received',
            'created_at' => now(), 'updated_at' => now(),
        ]);

        $readiness = app(InventoryCompatibilityService::class)->readinessForLocationPrimary($wh->id);

        $this->assertTrue($readiness['ready'], implode(' | ', $readiness['reasons']));
    }

    // ---- 7. demotion insecure blocks ----------------------------------------

    public function test_demotion_blocked_when_legacy_is_stale_after_location_primary(): void
    {
        [$wh, $main] = $this->readyWarehouse(10, 50);
        $service = app(InventoryCompatibilityService::class);
        $service->promoteToLocationPrimary($wh->id);

        // A location-native movement happens (e.g. a native sale/transfer) that
        // legacy product_warehouse never learns about — legacy is now stale.
        DB::table('inventory_location_stocks')->where('inventory_location_id', $main)->where('product_id', 10)->update(['quantity' => 20]);

        $this->expectException(ValidationException::class);
        $service->returnToLegacyOnly($wh->id);
    }

    public function test_demotion_allowed_when_legacy_still_matches_exactly(): void
    {
        [$wh] = $this->readyWarehouse(10, 50);
        $service = app(InventoryCompatibilityService::class);
        $service->promoteToLocationPrimary($wh->id);

        // Nothing moved since promotion — legacy and location are still exactly equal.
        $state = $service->returnToLegacyOnly($wh->id);

        $this->assertSame(InventoryTransitionState::MODE_LEGACY_ONLY, $state->mode);
    }
}
