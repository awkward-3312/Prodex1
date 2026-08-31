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

    // ---- Blocker 2: transición single-MAIN vs almacén multi-ubicación --------

    /**
     * TEST OBLIGATORIO: legacy 100 = MAIN 70 + QUARANTINE 30.
     * audit => reconciled. compareKey => agregado del almacén (100), no MAIN (70).
     */
    public function test_compare_uses_warehouse_aggregate_not_only_main(): void
    {
        $warehouse = $this->warehouse();
        $this->legacy($warehouse->id, 10, 100);
        app(LegacyInventoryReconciliationService::class)->backfillWarehouse($warehouse->id); // MAIN 100
        $service = app(InventoryCompatibilityService::class);
        $main = (int) DB::table('warehouses')->where('id', $warehouse->id)->value('default_inventory_location_id');

        // Repartir: MAIN 70 + QUARANTINE 30 (agregado sigue 100).
        DB::table('inventory_location_stocks')->where('inventory_location_id', $main)->where('product_id', 10)->update(['quantity' => 70]);
        $quar = $this->addLocation($warehouse->id, 'QUARANTINE');
        $this->addLocStock($quar, 10, 30);

        $audit = $service->audit($warehouse->id);
        $this->assertTrue($audit['is_reconciled']);
        $this->assertSame(2, $audit['stocked_location_count']);
        $this->assertFalse($audit['is_single_location']);

        $this->assertSame(100.0, $service->shadowQuantity($warehouse->id, 10)); // agregado, no 70
        $this->assertTrue($service->compareKey($warehouse->id, 10)['matches']);
    }

    /** enableDualWrite se bloquea si el almacén tiene stock en >1 ubicación. */
    public function test_dual_write_blocked_for_multi_location_warehouse(): void
    {
        $warehouse = $this->warehouse();
        $this->legacy($warehouse->id, 10, 100);
        app(LegacyInventoryReconciliationService::class)->backfillWarehouse($warehouse->id);
        $service = app(InventoryCompatibilityService::class);
        $main = (int) DB::table('warehouses')->where('id', $warehouse->id)->value('default_inventory_location_id');
        DB::table('inventory_location_stocks')->where('inventory_location_id', $main)->where('product_id', 10)->update(['quantity' => 70]);
        $this->addLocStock($this->addLocation($warehouse->id, 'QUAR'), 10, 30);

        $this->expectException(ValidationException::class);
        $service->enableDualWrite($warehouse->id);
    }

    /** enableMode exige una MAIN destino aunque is_reconciled sea true. */
    public function test_enable_mode_requires_a_target_main_even_when_reconciled(): void
    {
        $warehouse = $this->warehouse();
        // Sin legacy y sin ubicaciones: is_reconciled vacuamente true, pero sin MAIN.
        $service = app(InventoryCompatibilityService::class);
        $audit = app(LegacyInventoryReconciliationService::class)->auditWarehouse($warehouse->id);
        $this->assertTrue($audit['is_reconciled']);
        $this->assertFalse($audit['has_target_location']);
        $this->assertFalse($audit['transition_ready']);

        $this->expectException(ValidationException::class);
        $service->enableShadowCompare($warehouse->id);
    }

    /**
     * Un mirror futuro NUNCA puede convertir (MAIN 70 + QUAR 30) en MAIN 110:
     * mirrorLegacySnapshot rehúsa y marca mismatch, sin escribir.
     */
    public function test_mirror_refuses_when_stock_lives_outside_main(): void
    {
        $warehouse = $this->warehouse();
        $this->legacy($warehouse->id, 10, 100);
        app(LegacyInventoryReconciliationService::class)->backfillWarehouse($warehouse->id);
        $service = app(InventoryCompatibilityService::class);
        $service->enableDualWrite($warehouse->id); // permitido: 1 sola ubicación en este punto
        $main = (int) DB::table('warehouses')->where('id', $warehouse->id)->value('default_inventory_location_id');

        // Después aparece QUARANTINE con 30 (MAIN baja a 70).
        DB::table('inventory_location_stocks')->where('inventory_location_id', $main)->where('product_id', 10)->update(['quantity' => 70]);
        $this->addLocStock($this->addLocation($warehouse->id, 'QUAR'), 10, 30);

        // El legacy sube a 110.
        DB::table('product_warehouse')->where('warehouse_id', $warehouse->id)->where('product_id', 10)->update(['qte' => 110]);

        try {
            $service->mirrorLegacySnapshot($warehouse->id, 10);
            $this->fail('mirrorLegacySnapshot debía rehusar con stock fuera de MAIN');
        } catch (ValidationException $e) {
            $this->assertStringContainsString('fuera de MAIN', $e->getMessage());
        }

        // MAIN no cambió (sigue 70), agregado sigue 100, estado en mismatch.
        $this->assertSame(70.0, (float) DB::table('inventory_location_stocks')
            ->where('inventory_location_id', $main)->where('product_id', 10)->value('quantity'));
        $this->assertSame(100.0, $service->warehouseAggregateQuantity($warehouse->id, 10));
        $this->assertSame('mismatch', $service->state($warehouse->id)->status);
    }

    /**
     * Si tras activar dual_write alguien vuelve la ubicación destino a cuarentena
     * (o le quita el flag de default), el mirror rehúsa y marca mismatch — jamás
     * escribe en una ubicación que dejó de ser apta.
     */
    public function test_mirror_refuses_when_target_stopped_being_eligible(): void
    {
        $warehouse = $this->warehouse();
        $this->legacy($warehouse->id, 10, 100);
        app(LegacyInventoryReconciliationService::class)->backfillWarehouse($warehouse->id);
        $service = app(InventoryCompatibilityService::class);
        $service->enableDualWrite($warehouse->id);
        $main = (int) DB::table('warehouses')->where('id', $warehouse->id)->value('default_inventory_location_id');

        // Config cambiada tras activar dual_write: MAIN pasa a cuarentena.
        DB::table('inventory_locations')->where('id', $main)->update(['type' => 'quarantine', 'is_quarantine' => 1]);
        DB::table('product_warehouse')->where('warehouse_id', $warehouse->id)->where('product_id', 10)->update(['qte' => 130]);

        try {
            $service->mirrorLegacySnapshot($warehouse->id, 10);
            $this->fail('mirrorLegacySnapshot debía rehusar con destino no apto');
        } catch (ValidationException $e) {
            $this->assertStringContainsString('dejó de ser apta', $e->getMessage());
        }

        $this->assertSame(100.0, (float) DB::table('inventory_location_stocks')
            ->where('inventory_location_id', $main)->where('product_id', 10)->value('quantity'));
        $this->assertSame('mismatch', $service->state($warehouse->id)->status);
    }

    // ---- Blocker: dual_write exige que TODO el stock esté en el destino -------

    /** 1. legacy 100 / MAIN 100 / STORAGE2 0  => dual_write permitido. */
    public function test_dual_write_allowed_when_target_holds_all_stock(): void
    {
        $warehouse = $this->warehouse();
        $this->legacy($warehouse->id, 10, 100);
        app(LegacyInventoryReconciliationService::class)->backfillWarehouse($warehouse->id); // MAIN 100
        $st2 = $this->addLocation($warehouse->id, 'STORAGE2');
        $this->addLocStock($st2, 10, 0); // fila con 0

        $audit = app(LegacyInventoryReconciliationService::class)->auditWarehouse($warehouse->id);
        $this->assertTrue($audit['target_holds_all_stock']);
        $this->assertSame(0.0, $audit['stock_outside_target_quantity']);

        $state = app(InventoryCompatibilityService::class)->enableDualWrite($warehouse->id);
        $this->assertSame(InventoryTransitionState::MODE_DUAL_WRITE, $state->mode);
    }

    /** 2. legacy 100 / MAIN 0 / STORAGE2 100 => reconciled pero dual_write RECHAZADO. */
    public function test_dual_write_rejected_when_all_stock_is_outside_target(): void
    {
        $warehouse = $this->warehouse();
        $this->legacy($warehouse->id, 10, 100);
        app(LegacyInventoryReconciliationService::class)->backfillWarehouse($warehouse->id);
        $main = (int) DB::table('warehouses')->where('id', $warehouse->id)->value('default_inventory_location_id');
        DB::table('inventory_location_stocks')->where('inventory_location_id', $main)->where('product_id', 10)->update(['quantity' => 0]);
        $this->addLocStock($this->addLocation($warehouse->id, 'STORAGE2'), 10, 100);

        $audit = app(LegacyInventoryReconciliationService::class)->auditWarehouse($warehouse->id);
        $this->assertTrue($audit['is_reconciled']);              // paridad warehouse-wide 100 == 100
        $this->assertFalse($audit['target_holds_all_stock']);
        $this->assertSame(1, $audit['stocked_location_count']);  // sólo STORAGE2 tiene stock

        $this->expectException(ValidationException::class);
        app(InventoryCompatibilityService::class)->enableDualWrite($warehouse->id);
    }

    /** 3. legacy 100 / MAIN 70 / QUARANTINE 30 => dual_write RECHAZADO. */
    public function test_dual_write_rejected_when_stock_split_main_and_quarantine(): void
    {
        $warehouse = $this->warehouse();
        $this->legacy($warehouse->id, 10, 100);
        app(LegacyInventoryReconciliationService::class)->backfillWarehouse($warehouse->id);
        $main = (int) DB::table('warehouses')->where('id', $warehouse->id)->value('default_inventory_location_id');
        DB::table('inventory_location_stocks')->where('inventory_location_id', $main)->where('product_id', 10)->update(['quantity' => 70]);
        $this->addLocStock($this->addLocation($warehouse->id, 'QUAR'), 10, 30);

        $audit = app(LegacyInventoryReconciliationService::class)->auditWarehouse($warehouse->id);
        $this->assertFalse($audit['target_holds_all_stock']);
        $this->assertSame(30.0, $audit['stock_outside_target_quantity']);

        $this->expectException(ValidationException::class);
        app(InventoryCompatibilityService::class)->enableDualWrite($warehouse->id);
    }

    /** 4. Almacén vacío reconciliado con MAIN storage válida => dual_write permitido. */
    public function test_dual_write_allowed_on_empty_reconciled_warehouse_with_valid_target(): void
    {
        $warehouse = $this->warehouse();
        $this->legacy($warehouse->id, 10, 0); // sin stock legacy
        app(LegacyInventoryReconciliationService::class)->backfillWarehouse($warehouse->id);

        $audit = app(LegacyInventoryReconciliationService::class)->auditWarehouse($warehouse->id);
        $this->assertTrue($audit['is_reconciled']);
        $this->assertTrue($audit['has_target_location']);
        $this->assertTrue($audit['target_holds_all_stock']); // 0 stock, destino válido

        $state = app(InventoryCompatibilityService::class)->enableDualWrite($warehouse->id);
        $this->assertSame(InventoryTransitionState::MODE_DUAL_WRITE, $state->mode);
    }

    private function addLocation(int $warehouseId, string $code, string $type = 'storage', bool $quarantine = false): int
    {
        return (int) DB::table('inventory_locations')->insertGetId([
            'warehouse_id' => $warehouseId, 'branch_id' => null, 'code' => $code, 'name' => $code,
            'type' => $type, 'is_quarantine' => $quarantine ? 1 : 0, 'is_active' => 1,
            'created_at' => now(), 'updated_at' => now(),
        ]);
    }

    private function addLocStock(int $locationId, int $productId, float $qty, ?int $variantId = null): void
    {
        DB::table('inventory_location_stocks')->insert([
            'inventory_location_id' => $locationId, 'product_id' => $productId, 'product_variant_id' => $variantId,
            'variant_key' => (int) ($variantId ?: 0), 'quantity' => $qty, 'reserved_quantity' => 0,
            'manage_stock' => 1, 'created_at' => now(), 'updated_at' => now(),
        ]);
    }

    // ---- hotfix dual_write / provenance guard (feedback post-merge #77) ------

    /**
     * Escenario Iphone15/16: baseline 88, TransferDispatch −28 posterior,
     * legacy_now 88, location_now 60 → provenance RECONCILED PERO enableDualWrite
     * DEBE rechazarse (no hay paridad snapshot, y no hay reverse-mirror).
     */
    private function driftedButProvenanceReconciled(int $legacyNow, int $dispatchOut): array
    {
        $wh = $this->warehouse();
        $this->legacy($wh->id, 10, $legacyNow);
        $main = $this->addLocation($wh->id, 'MAIN');
        DB::table('warehouses')->where('id', $wh->id)->update(['default_inventory_location_id' => $main]);
        $this->addLocStock($main, 10, $legacyNow - $dispatchOut);
        DB::table('inventory_transition_states')->insert([
            'warehouse_id' => $wh->id, 'inventory_location_id' => $main,
            'mode' => 'legacy_only', 'status' => 'pending', 'mismatch_count' => 0,
            'last_reconciled_at' => '2026-08-22 00:00:00', 'created_at' => now(), 'updated_at' => now(),
        ]);
        $this->movement('increase', 10, $legacyNow, 'legacy_product_warehouse_backfill', null, $main, '2026-08-21 23:00:00');
        $this->movement('decrease', 10, $dispatchOut, 'TransferDispatch', $main, null, '2026-08-25 00:00:00');
        return [$wh, $main];
    }

    private function movement(string $type, int $productId, float $qty, string $ref, ?int $from, ?int $to, string $at): void
    {
        DB::table('inventory_location_movements')->insert([
            'movement_type' => $type, 'product_id' => $productId, 'product_variant_id' => null,
            'from_inventory_location_id' => $from, 'to_inventory_location_id' => $to,
            'quantity' => $qty, 'reference_type' => $ref, 'created_at' => $at, 'updated_at' => $at,
        ]);
    }

    /** F1: baseline 88 / legacy 88 / location 60 (dispatch −28) => enableDualWrite RECHAZADO. */
    public function test_F1_dual_write_rejected_when_snapshot_not_equal_iphone15(): void
    {
        [$wh] = $this->driftedButProvenanceReconciled(88, 28);
        $svc = app(LegacyInventoryReconciliationService::class)->auditWarehouse($wh->id);
        $this->assertTrue($svc['provenance_reconciled']);
        $this->assertFalse($svc['snapshot_equal']);
        $this->assertFalse($svc['dual_write_compatible']);

        try {
            app(InventoryCompatibilityService::class)->enableDualWrite($wh->id);
            $this->fail('enableDualWrite debía rechazar por falta de paridad snapshot');
        } catch (ValidationException $e) {
            $this->assertStringContainsString('paridad actual legacy/location', $e->getMessage());
        }
    }

    /** F2: baseline 90 / legacy 90 / location 78 (dispatch −12) => rechazado. */
    public function test_F2_dual_write_rejected_iphone16(): void
    {
        [$wh] = $this->driftedButProvenanceReconciled(90, 12);
        $this->expectException(ValidationException::class);
        app(InventoryCompatibilityService::class)->enableDualWrite($wh->id);
    }

    /** F3: legacy 100 / location 100, single-target => dual_write permitido. */
    public function test_F3_dual_write_allowed_when_snapshot_equal(): void
    {
        $wh = $this->warehouse();
        $this->legacy($wh->id, 10, 100);
        app(LegacyInventoryReconciliationService::class)->backfillWarehouse($wh->id); // MAIN 100, baseline
        $audit = app(LegacyInventoryReconciliationService::class)->auditWarehouse($wh->id);
        $this->assertTrue($audit['snapshot_equal']);
        $this->assertTrue($audit['dual_write_compatible']);

        $state = app(InventoryCompatibilityService::class)->enableDualWrite($wh->id);
        $this->assertSame(InventoryTransitionState::MODE_DUAL_WRITE, $state->mode);
    }

    /** F4: dual_write ya activo por estado viejo + drift => mirror RECHAZA y MAIN NO pasa a 88. */
    public function test_F4_mirror_rejects_and_never_recreates_moved_stock(): void
    {
        [$wh, $main] = $this->driftedButProvenanceReconciled(88, 28);
        DB::table('inventory_transition_states')->where('warehouse_id', $wh->id)
            ->update(['mode' => 'dual_write', 'status' => 'healthy']);

        try {
            app(InventoryCompatibilityService::class)->mirrorLegacySnapshot($wh->id, 10);
            $this->fail('mirrorLegacySnapshot debía rehusar con movimientos location-native posteriores al baseline');
        } catch (ValidationException $e) {
            $this->assertStringContainsString('location-native posteriores al baseline', $e->getMessage());
        }

        // MAIN sigue 60 — jamás 88.
        $this->assertSame(60.0, (float) DB::table('inventory_location_stocks')
            ->where('inventory_location_id', $main)->where('product_id', 10)->value('quantity'));
        $this->assertSame('mismatch', app(InventoryCompatibilityService::class)->state($wh->id)->status);
    }

    /** F5: compareKey con baseline 88 + dispatch −28 + current 60 => NO mismatch falso. */
    public function test_F5_compare_key_no_false_mismatch_from_snapshot_diff(): void
    {
        [$wh] = $this->driftedButProvenanceReconciled(88, 28);
        $svc = app(InventoryCompatibilityService::class);
        $res = $svc->compareKey($wh->id, 10);
        $this->assertTrue($res['matches']);
        $this->assertSame('RECONCILED', $res['classification']);
        $this->assertNotSame('mismatch', $svc->state($wh->id)->status);

        // snapshotCompareKey (diagnóstico) SÍ ve la diferencia, pero NO marca mismatch.
        $snap = $svc->snapshotCompareKey($wh->id, 10);
        $this->assertFalse($snap['snapshot_equal']);
        $this->assertSame(88.0, $snap['legacy_quantity']);
        $this->assertSame(60.0, $snap['location_quantity']);
    }

    /** F6: activar shadow_compare NO modifica last_reconciled_at histórico. */
    public function test_F6_enable_shadow_compare_keeps_historic_baseline(): void
    {
        $wh = $this->warehouse();
        $this->legacy($wh->id, 10, 5);
        $main = $this->addLocation($wh->id, 'MAIN');
        DB::table('warehouses')->where('id', $wh->id)->update(['default_inventory_location_id' => $main]);
        $this->addLocStock($main, 10, 5);
        DB::table('inventory_transition_states')->insert([
            'warehouse_id' => $wh->id, 'inventory_location_id' => $main,
            'mode' => 'legacy_only', 'status' => 'pending', 'mismatch_count' => 0,
            'last_reconciled_at' => '2026-08-22 18:11:46', 'created_at' => now(), 'updated_at' => now(),
        ]);
        $this->movement('increase', 10, 5, 'legacy_product_warehouse_backfill', null, $main, '2026-08-22 18:01:44');

        app(InventoryCompatibilityService::class)->enableShadowCompare($wh->id);

        $this->assertSame(
            '2026-08-22 18:11:46',
            (string) DB::table('inventory_transition_states')->where('warehouse_id', $wh->id)->value('last_reconciled_at')
        );
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
