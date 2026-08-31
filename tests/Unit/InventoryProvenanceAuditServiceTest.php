<?php

namespace Tests\Unit;

use App\Services\InventoryProvenanceAuditService;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

/**
 * Auditor basado en eventos: la diferencia legacy - location POSTERIOR a un
 * baseline puede ser 100% operaciones location-native legítimas y NO stock
 * legacy pendiente. Casos reales de prueba02 (Iphone 15/16 vs Iphone X).
 */
class InventoryProvenanceAuditServiceTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        Schema::create('inventory_transition_states', function ($t) {
            $t->increments('id');
            $t->integer('warehouse_id')->unique();
            $t->timestamp('last_reconciled_at')->nullable();
            $t->timestamps();
        });
        Schema::create('inventory_locations', function ($t) {
            $t->increments('id');
            $t->integer('warehouse_id')->nullable();
            $t->string('code');
            $t->boolean('is_active')->default(true);
            $t->timestamps();
            $t->softDeletes();
        });
        Schema::create('inventory_location_stocks', function ($t) {
            $t->increments('id');
            $t->integer('inventory_location_id');
            $t->integer('product_id');
            $t->integer('variant_key')->default(0);
            $t->decimal('quantity', 14, 3)->default(0);
            $t->timestamps();
        });
        Schema::create('inventory_location_movements', function ($t) {
            $t->increments('id');
            $t->string('movement_type');
            $t->integer('product_id');
            $t->integer('product_variant_id')->nullable();
            $t->integer('from_inventory_location_id')->nullable();
            $t->integer('to_inventory_location_id')->nullable();
            $t->decimal('quantity', 14, 3);
            $t->string('reference_type')->nullable();
            $t->timestamps();
        });
        Schema::create('product_warehouse', function ($t) {
            $t->increments('id');
            $t->integer('product_id');
            $t->integer('warehouse_id');
            $t->integer('product_variant_id')->nullable();
            $t->decimal('qte', 14, 3)->default(0);
            $t->timestamps();
            $t->softDeletes();
        });
    }

    private int $wh = 1;
    private int $main;

    private function baseline(string $at): void
    {
        DB::table('inventory_transition_states')->insert([
            'warehouse_id' => $this->wh, 'last_reconciled_at' => $at,
            'created_at' => now(), 'updated_at' => now(),
        ]);
    }

    private function locations(): void
    {
        $this->main = (int) DB::table('inventory_locations')->insertGetId([
            'warehouse_id' => $this->wh, 'code' => 'MAIN', 'is_active' => 1,
            'created_at' => now(), 'updated_at' => now(),
        ]);
    }

    private function legacy(int $productId, float $qty, ?int $variantId = null): void
    {
        DB::table('product_warehouse')->insert([
            'product_id' => $productId, 'warehouse_id' => $this->wh, 'product_variant_id' => $variantId,
            'qte' => $qty, 'created_at' => now(), 'updated_at' => now(),
        ]);
    }

    private function stock(int $productId, float $qty, ?int $variantId = null): void
    {
        DB::table('inventory_location_stocks')->insert([
            'inventory_location_id' => $this->main, 'product_id' => $productId,
            'variant_key' => (int) ($variantId ?: 0), 'quantity' => $qty,
            'created_at' => now(), 'updated_at' => now(),
        ]);
    }

    private function backfillMovement(int $productId, float $qty, string $at, ?int $variantId = null): void
    {
        DB::table('inventory_location_movements')->insert([
            'movement_type' => 'increase', 'product_id' => $productId, 'product_variant_id' => $variantId,
            'from_inventory_location_id' => null, 'to_inventory_location_id' => $this->main,
            'quantity' => $qty, 'reference_type' => 'legacy_product_warehouse_backfill',
            'created_at' => $at, 'updated_at' => $at,
        ]);
    }

    private function movement(string $type, int $productId, float $qty, string $ref, string $at, bool $out = true, ?int $variantId = null): void
    {
        DB::table('inventory_location_movements')->insert([
            'movement_type' => $type, 'product_id' => $productId, 'product_variant_id' => $variantId,
            'from_inventory_location_id' => $out ? $this->main : null,
            'to_inventory_location_id' => $out ? null : $this->main,
            'quantity' => $qty, 'reference_type' => $ref,
            'created_at' => $at, 'updated_at' => $at,
        ]);
    }

    private function keyFor(array $audit, int $productId): array
    {
        return collect($audit['keys'])->firstWhere('product_id', $productId);
    }

    /** Caso 1 (Iphone 15): baseline 88=88, luego TransferDispatch -28 => RECONCILED, sin pending. */
    public function test_case1_dispatches_after_baseline_are_not_pending(): void
    {
        $this->baseline('2026-08-22 18:11:46');
        $this->locations();
        $this->legacy(6, 88);
        $this->stock(6, 60);
        $this->backfillMovement(6, 88, '2026-08-22 18:01:44');
        $this->movement('decrease', 6, 10, 'TransferDispatch', '2026-08-23 09:00:00');
        $this->movement('decrease', 6, 10, 'TransferDispatch', '2026-08-24 09:00:00');
        $this->movement('decrease', 6, 8, 'TransferDispatch', '2026-08-25 09:00:00');

        $row = $this->keyFor(app(InventoryProvenanceAuditService::class)->auditWarehouse($this->wh), 6);

        $this->assertSame('RECONCILED', $row['classification']);
        $this->assertSame(0.0, $row['legacy_only_pending_quantity']);
        $this->assertSame(0.0, $row['snapshot_drift']);
        $this->assertSame(-28.0, $row['post_baseline_location_net']);
        $this->assertSame(60.0, $row['expected_location']);
    }

    /** Caso 2 (Iphone 16): baseline 90=90, luego -12 => sin pending. */
    public function test_case2_partial_dispatch_after_baseline_not_pending(): void
    {
        $this->baseline('2026-08-22 18:11:46');
        $this->locations();
        $this->legacy(7, 90);
        $this->stock(7, 78);
        $this->backfillMovement(7, 90, '2026-08-22 18:01:44');
        $this->movement('decrease', 7, 7, 'TransferDispatch', '2026-08-23 09:00:00');
        $this->movement('decrease', 7, 5, 'TransferDispatch', '2026-08-24 09:00:00');

        $row = $this->keyFor(app(InventoryProvenanceAuditService::class)->auditWarehouse($this->wh), 7);
        $this->assertSame('RECONCILED', $row['classification']);
        $this->assertSame(0.0, $row['legacy_only_pending_quantity']);
    }

    /** Caso 3 (Iphone X): opening stock legacy +100 tras baseline, sin movimiento => LEGACY_ONLY_PENDING +100. */
    public function test_case3_legacy_opening_stock_without_location_movement_is_pending(): void
    {
        $this->baseline('2026-08-22 18:11:46');
        $this->locations();
        $this->legacy(8, 100); // creado el 30-ago, sin backfill, sin stock ni movimiento location

        $row = $this->keyFor(app(InventoryProvenanceAuditService::class)->auditWarehouse($this->wh), 8);
        $this->assertSame('LEGACY_ONLY_PENDING', $row['classification']);
        $this->assertSame(100.0, $row['legacy_only_pending_quantity']);
        $this->assertFalse($row['baselined']);
    }

    /** Caso 4: legacy +100 tras baseline CON movimiento location equivalente +100 => MIRRORED. */
    public function test_case4_legacy_increase_with_matching_location_movement_is_mirrored(): void
    {
        $this->baseline('2026-08-22 18:11:46');
        $this->locations();
        $this->legacy(9, 150); // baseline 50 + compra 100
        $this->stock(9, 150);
        $this->backfillMovement(9, 50, '2026-08-22 18:01:44');
        $this->movement('increase', 9, 100, 'Purchase', '2026-08-25 09:00:00', out: false);

        $row = $this->keyFor(app(InventoryProvenanceAuditService::class)->auditWarehouse($this->wh), 9);
        $this->assertSame('MIRRORED', $row['classification']);
        $this->assertSame(0.0, $row['legacy_only_pending_quantity']);
    }

    /** Caso 5: drift inexplicado (stock cambió sin movimiento) => UNKNOWN_REVIEW, nunca ADD. */
    public function test_case5_unexplained_drift_is_unknown_review_never_add(): void
    {
        $this->baseline('2026-08-22 18:11:46');
        $this->locations();
        $this->legacy(10, 40);
        $this->stock(10, 33); // 7 menos que baseline sin ningún movimiento que lo explique
        $this->backfillMovement(10, 40, '2026-08-22 18:01:44');

        $row = $this->keyFor(app(InventoryProvenanceAuditService::class)->auditWarehouse($this->wh), 10);
        $this->assertSame('UNKNOWN_REVIEW', $row['classification']);
        $this->assertSame(0.0, $row['legacy_only_pending_quantity']);
        $this->assertSame(-7.0, $row['snapshot_drift']);
    }

    public function test_audit_aggregates_and_legacy_only_pending_helper(): void
    {
        $this->baseline('2026-08-22 18:11:46');
        $this->locations();
        // 6: reconciled ; 8: pending +100
        $this->legacy(6, 88); $this->stock(6, 60); $this->backfillMovement(6, 88, '2026-08-22 18:01:44');
        $this->movement('decrease', 6, 28, 'TransferDispatch', '2026-08-23 09:00:00');
        $this->legacy(8, 100);

        $svc = app(InventoryProvenanceAuditService::class);
        $audit = $svc->auditWarehouse($this->wh);

        $this->assertSame(100.0, $audit['legacy_only_pending_total']);
        $this->assertSame(1, $audit['counts']['RECONCILED']);
        $this->assertSame(1, $audit['counts']['LEGACY_ONLY_PENDING']);
        $this->assertFalse($audit['has_unknown_review']);

        $pending = $svc->legacyOnlyPending($this->wh);
        $this->assertCount(1, $pending);
        $this->assertSame(8, $pending[0]['product_id']);
    }
}
