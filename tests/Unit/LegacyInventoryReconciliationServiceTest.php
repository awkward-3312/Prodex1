<?php

namespace Tests\Unit;

use App\Models\InventoryLocation;
use App\Models\InventoryLocationStock;
use App\Models\Warehouse;
use App\Services\LegacyInventoryReconciliationService;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;

class LegacyInventoryReconciliationServiceTest extends TestCase
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
            $table->unique(['inventory_location_id', 'product_id', 'variant_key'], 'ils_reconcile_unique');
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
            $table->string('idempotency_fingerprint', 64)->nullable();
            $table->string('notes')->nullable();
            $table->json('metadata')->nullable();
            $table->timestamps();
        });

        Schema::create('product_warehouse', function ($table) {
            $table->increments('id');
            $table->integer('product_id');
            $table->integer('warehouse_id');
            $table->integer('product_variant_id')->nullable();
            $table->decimal('qte', 12, 3);
            $table->boolean('manage_stock')->default(true);
            $table->timestamps();
            $table->softDeletes();
        });

        Schema::create('products', function ($table) {
            $table->increments('id');
            $table->string('name')->nullable();
            $table->string('code')->nullable();
            $table->string('type')->default('is_single');
            $table->boolean('is_batch_tracked')->default(false);
            $table->integer('is_imei')->default(0);
            $table->timestamps();
            $table->softDeletes();
        });
    }

    public function test_batch_tracked_product_blocks_backfill_and_is_reported_in_audit(): void
    {
        $warehouse = Warehouse::create(['name' => 'CD Principal']);
        $this->legacy($warehouse->id, 10, null, 5);
        $this->legacy($warehouse->id, 11, null, 3);
        $this->product(10, ['is_batch_tracked' => true]);
        $this->product(11);

        $service = app(LegacyInventoryReconciliationService::class);

        $audit = $service->auditWarehouse($warehouse->id);
        $this->assertCount(1, $audit['batch_or_serial_products']);
        $this->assertSame(10, $audit['batch_or_serial_products'][0]['product_id']);
        $this->assertFalse($audit['is_backfillable']);

        $this->expectException(ValidationException::class);
        $service->backfillWarehouse($warehouse->id);
    }

    public function test_serial_imei_product_blocks_backfill(): void
    {
        $warehouse = Warehouse::create(['name' => 'CD Principal']);
        $this->legacy($warehouse->id, 10, null, 5);
        $this->product(10, ['is_imei' => 1]);

        $this->expectException(ValidationException::class);
        app(LegacyInventoryReconciliationService::class)->backfillWarehouse($warehouse->id);

        $this->assertSame(0, InventoryLocationStock::count());
    }

    public function test_plain_products_still_backfill_when_products_table_present(): void
    {
        $warehouse = Warehouse::create(['name' => 'CD Principal']);
        $this->legacy($warehouse->id, 10, null, 5);
        $this->product(10);

        $service = app(LegacyInventoryReconciliationService::class);

        $before = $service->auditWarehouse($warehouse->id);
        $this->assertTrue($before['is_backfillable']);
        $this->assertFalse($before['main_location_has_stock']);

        $result = $service->backfillWarehouse($warehouse->id);
        $this->assertTrue($result['is_reconciled']);
        $this->assertEmpty($result['batch_or_serial_products']);
        // Tras poblar MAIN ya no es candidato a backfill de almacén completo.
        $this->assertTrue($result['main_location_has_stock']);
        $this->assertFalse($result['is_backfillable']);
    }

    public function test_plan_incremental_adds_delta_for_partially_diverged_warehouse(): void
    {
        // Escenario prueba02: MAIN ya reconciliada, luego opening stock legacy.
        $warehouse = Warehouse::create(['name' => 'Centro de Distribución']);
        $this->legacy($warehouse->id, 6, null, 60);
        $this->legacy($warehouse->id, 7, null, 78);
        $this->product(6); $this->product(7);
        $service = app(LegacyInventoryReconciliationService::class);
        $service->backfillWarehouse($warehouse->id); // MAIN: 6→60, 7→78

        // Divergencia posterior sólo en legacy.
        DB::table('product_warehouse')->where('warehouse_id', $warehouse->id)->where('product_id', 6)->update(['qte' => 88]);
        DB::table('product_warehouse')->where('warehouse_id', $warehouse->id)->where('product_id', 7)->update(['qte' => 90]);
        $this->legacy($warehouse->id, 8, null, 100); $this->product(8); // nuevo, 0 en MAIN

        $audit = $service->auditWarehouse($warehouse->id);
        $this->assertTrue($audit['main_location_has_stock']);
        $this->assertTrue($audit['needs_incremental']);
        $this->assertFalse($audit['is_backfillable']);

        // El backfill de almacén completo ahora se rechaza y remite al plan.
        try {
            $service->backfillWarehouse($warehouse->id);
            $this->fail('backfillWarehouse debía rechazar MAIN no vacía');
        } catch (ValidationException $e) {
            $this->assertStringContainsString('plan incremental', $e->getMessage());
        }

        $plan = $service->planIncremental($warehouse->id);
        $rows = collect($plan['plan'])->keyBy('product_id');
        $this->assertSame(28.0, $rows[6]['delta']);
        $this->assertSame(12.0, $rows[7]['delta']);
        $this->assertSame(100.0, $rows[8]['delta']);
        $this->assertSame('ADD', $rows[6]['action']);
        $this->assertSame('ADD', $rows[7]['action']);
        $this->assertSame('ADD', $rows[8]['action']);
        $this->assertSame(3, $plan['add_count']);
        $this->assertSame(0, $plan['manual_review_count']);
        $this->assertSame(140.0, $plan['add_total_delta']);
    }

    public function test_plan_incremental_legacy_decrease_is_unknown_review_and_reserved_blocks_add(): void
    {
        $warehouse = Warehouse::create(['name' => 'CD Principal']);
        $this->legacy($warehouse->id, 10, null, 40);
        $this->legacy($warehouse->id, 11, null, 5);
        $this->product(10); $this->product(11);
        $service = app(LegacyInventoryReconciliationService::class);
        $service->backfillWarehouse($warehouse->id); // baseline: MAIN 10→40, 11→5

        // 10: legacy BAJA respecto al baseline sin movimiento location => UNKNOWN_REVIEW,
        //     nunca se descuenta en automático.
        DB::table('product_warehouse')->where('warehouse_id', $warehouse->id)->where('product_id', 10)->update(['qte' => 30]);
        // 11: legacy SUBE +7 (opening stock sin espejo) pero la ubicación tiene reservado => ADD bloqueado.
        DB::table('product_warehouse')->where('warehouse_id', $warehouse->id)->where('product_id', 11)->update(['qte' => 12]);
        $loc = Warehouse::find($warehouse->id)->default_inventory_location_id;
        DB::table('inventory_location_stocks')->where('inventory_location_id', $loc)->where('product_id', 11)->update(['reserved_quantity' => 2]);

        $plan = collect($service->planIncremental($warehouse->id)['plan'])->keyBy('product_id');
        $this->assertSame('MANUAL_REVIEW', $plan[10]['action']);
        $this->assertSame('UNKNOWN_REVIEW', $plan[10]['classification']);
        $this->assertContains('provenance_desconocida', $plan[10]['reasons']);
        $this->assertSame('MANUAL_REVIEW', $plan[11]['action']);
        $this->assertSame('LEGACY_ONLY_PENDING', $plan[11]['classification']);
        $this->assertContains('reservado', $plan[11]['reasons']);
    }

    private function product(int $id, array $overrides = []): void
    {
        DB::table('products')->insert(array_merge([
            'id' => $id,
            'name' => 'Producto '.$id,
            'code' => 'P'.$id,
            'type' => 'is_single',
            'is_batch_tracked' => false,
            'is_imei' => 0,
            'created_at' => now(),
            'updated_at' => now(),
        ], $overrides));
    }

    public function test_backfill_creates_default_cd_location_and_reconciles_exactly(): void
    {
        $warehouse = Warehouse::create(['name' => 'CD Principal']);
        $this->legacy($warehouse->id, 10, null, 7.5);
        $this->legacy($warehouse->id, 10, null, 2.5);
        $this->legacy($warehouse->id, 20, 201, 4);

        $result = app(LegacyInventoryReconciliationService::class)->backfillWarehouse($warehouse->id);

        $this->assertTrue($result['is_reconciled']);
        $this->assertTrue($result['backfilled']);
        $this->assertSame(14.0, $result['legacy_total']);
        $this->assertSame(14.0, $result['location_total']);
        $this->assertEmpty($result['differences']);

        $warehouse->refresh();
        $this->assertNotNull($warehouse->default_inventory_location_id);
        $this->assertSame(10.0, (float) InventoryLocationStock::where('inventory_location_id', $warehouse->default_inventory_location_id)
            ->where('product_id', 10)->where('variant_key', 0)->value('quantity'));
        $this->assertSame(4.0, (float) InventoryLocationStock::where('inventory_location_id', $warehouse->default_inventory_location_id)
            ->where('product_id', 20)->where('variant_key', 201)->value('quantity'));
    }

    public function test_backfill_is_idempotent_after_exact_reconciliation(): void
    {
        $warehouse = Warehouse::create(['name' => 'CD Principal']);
        $this->legacy($warehouse->id, 10, null, 5);

        $service = app(LegacyInventoryReconciliationService::class);
        $service->backfillWarehouse($warehouse->id);
        $second = $service->backfillWarehouse($warehouse->id);

        $this->assertTrue($second['is_reconciled']);
        $this->assertTrue($second['already_reconciled']);
        $this->assertFalse($second['backfilled']);
        $this->assertSame(1, InventoryLocationStock::count());
    }

    public function test_negative_legacy_stock_blocks_backfill(): void
    {
        $warehouse = Warehouse::create(['name' => 'CD Principal']);
        $this->legacy($warehouse->id, 10, null, -1);

        $this->expectException(ValidationException::class);
        app(LegacyInventoryReconciliationService::class)->backfillWarehouse($warehouse->id);
    }

    public function test_existing_divergent_location_stock_blocks_overwrite(): void
    {
        $warehouse = Warehouse::create(['name' => 'CD Principal']);
        $this->legacy($warehouse->id, 10, null, 5);
        $service = app(LegacyInventoryReconciliationService::class);
        $service->backfillWarehouse($warehouse->id);

        DB::table('product_warehouse')->where('warehouse_id', $warehouse->id)->update(['qte' => 6]);

        $this->expectException(ValidationException::class);
        $service->backfillWarehouse($warehouse->id);
    }

    private function legacy(int $warehouseId, int $productId, ?int $variantId, float $quantity): void
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

    private function location(int $warehouseId, string $code, bool $default = false, string $type = 'storage', bool $quarantine = false): int
    {
        $id = DB::table('inventory_locations')->insertGetId([
            'warehouse_id' => $warehouseId,
            'branch_id' => null,
            'code' => $code,
            'name' => $code,
            'type' => $type,
            'is_quarantine' => $quarantine ? 1 : 0,
            'is_active' => 1,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        if ($default) DB::table('warehouses')->where('id', $warehouseId)->update(['default_inventory_location_id' => $id]);
        return (int) $id;
    }

    private function locStock(int $locationId, int $productId, ?int $variantId, float $qty, float $reserved = 0): void
    {
        DB::table('inventory_location_stocks')->insert([
            'inventory_location_id' => $locationId,
            'product_id' => $productId,
            'product_variant_id' => $variantId,
            'variant_key' => (int) ($variantId ?: 0),
            'quantity' => $qty,
            'reserved_quantity' => $reserved,
            'manage_stock' => 1,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    // ---- Granularidad multi-ubicación (feedback PR #77) --------------------

    /** Caso 1: legacy 100 = MAIN 70 + QUARANTINE 30 → reconciliado, plan vacío. */
    public function test_case1_split_across_locations_is_reconciled_no_plan(): void
    {
        $wh = Warehouse::create(['name' => 'CD']);
        $this->legacy($wh->id, 5, null, 100);
        $main = $this->location($wh->id, 'MAIN', true);
        $quar = $this->location($wh->id, 'QUARANTINE');
        $this->locStock($main, 5, null, 70);
        $this->locStock($quar, 5, null, 30);

        $svc = app(LegacyInventoryReconciliationService::class);
        $audit = $svc->auditWarehouse($wh->id);

        $this->assertTrue($audit['is_reconciled']);
        $this->assertSame(100.0, $audit['location_total']);
        $this->assertEmpty($audit['differences']);
        $this->assertFalse($audit['needs_incremental']);
        $this->assertEmpty($svc->planIncremental($wh->id)['plan']);
    }

    /** Caso 2: legacy 110, MAIN 70 + QUARANTINE 30 → delta +10, ADD a MAIN. */
    public function test_case2_partial_divergence_across_locations_plans_add_to_main(): void
    {
        $wh = Warehouse::create(['name' => 'CD']);
        $this->legacy($wh->id, 5, null, 110);
        $main = $this->location($wh->id, 'MAIN', true);
        $quar = $this->location($wh->id, 'QUARANTINE');
        $this->locStock($main, 5, null, 70);
        $this->locStock($quar, 5, null, 30);

        $plan = app(LegacyInventoryReconciliationService::class)->planIncremental($wh->id);
        $this->assertCount(1, $plan['plan']);
        $r = $plan['plan'][0];
        $this->assertSame(5, $r['product_id']);
        $this->assertSame(110.0, $r['legacy']);
        $this->assertSame(70.0, $r['main_quantity']);
        $this->assertSame(30.0, $r['other_locations_quantity']);
        $this->assertSame(100.0, $r['warehouse_location_quantity']);
        $this->assertSame(10.0, $r['delta']);
        $this->assertSame('ADD', $r['action']);
        $this->assertSame($main, $r['target_inventory_location_id']);
    }

    /** Caso 4: como el 2 pero con reservado en QUARANTINE → MANUAL_REVIEW. */
    public function test_case4_reserved_in_any_location_forces_manual_review(): void
    {
        $wh = Warehouse::create(['name' => 'CD']);
        $this->legacy($wh->id, 5, null, 110);
        $main = $this->location($wh->id, 'MAIN', true);
        $quar = $this->location($wh->id, 'QUARANTINE');
        $this->locStock($main, 5, null, 70);
        $this->locStock($quar, 5, null, 30, 2); // reservado en QUARANTINE

        $r = app(LegacyInventoryReconciliationService::class)->planIncremental($wh->id)['plan'][0];
        $this->assertSame(10.0, $r['delta']);
        $this->assertSame('MANUAL_REVIEW', $r['action']);
        $this->assertContains('reservado', $r['reasons']);
    }

    /** Caso 6: la agregación conserva product_id + variant_key. */
    public function test_case6_aggregation_is_variant_aware(): void
    {
        $wh = Warehouse::create(['name' => 'CD']);
        $this->legacy($wh->id, 5, 900, 50); // variante 900
        $this->legacy($wh->id, 5, 901, 50); // variante 901
        $main = $this->location($wh->id, 'MAIN', true);
        $quar = $this->location($wh->id, 'QUARANTINE');
        $this->locStock($main, 5, 900, 30);
        $this->locStock($quar, 5, 900, 20); // v900 reconciliada (50)
        $this->locStock($main, 5, 901, 10); // v901 corta (10 de 50)

        $plan = collect(app(LegacyInventoryReconciliationService::class)->planIncremental($wh->id)['plan']);
        $this->assertCount(1, $plan); // sólo v901 diverge
        $this->assertSame(901, $plan[0]['product_variant_id']);
        $this->assertSame(40.0, $plan[0]['delta']);
        $this->assertSame('ADD', $plan[0]['action']);
    }

    // ---- Contrato de ubicación destino apta (feedback PR #77) -------------

    /** 5. delta +100 y sin default => MANUAL_REVIEW(sin_ubicacion_destino), nunca ADD. */
    public function test_case5_no_target_location_forces_manual_review_never_add(): void
    {
        $wh = Warehouse::create(['name' => 'CD']);
        $this->legacy($wh->id, 5, null, 100); // sin ninguna inventory_location / default

        $svc = app(LegacyInventoryReconciliationService::class);
        $audit = $svc->auditWarehouse($wh->id);
        $this->assertFalse($audit['has_target_location']);
        $this->assertFalse($audit['transition_ready']);

        $r = $svc->planIncremental($wh->id)['plan'][0];
        $this->assertSame(100.0, $r['delta']);
        $this->assertSame('MANUAL_REVIEW', $r['action']);
        $this->assertContains('sin_ubicacion_destino', $r['reasons']);
        $this->assertNull($r['target_inventory_location_id']);
    }

    /** 6. default = QUARANTINE => no apta como target automático. */
    public function test_case6b_quarantine_default_is_not_an_eligible_target(): void
    {
        $wh = Warehouse::create(['name' => 'CD']);
        $this->legacy($wh->id, 5, null, 100);
        // default apunta a una ubicación de cuarentena.
        $this->location($wh->id, 'QUAR', true, 'quarantine', true);

        $svc = app(LegacyInventoryReconciliationService::class);
        $audit = $svc->auditWarehouse($wh->id);
        $this->assertFalse($audit['has_target_location']);   // QUARANTINE no cuenta
        $this->assertNull($audit['inventory_location_id']);

        $r = $svc->planIncremental($wh->id)['plan'][0];
        $this->assertSame('MANUAL_REVIEW', $r['action']);
        $this->assertContains('sin_ubicacion_destino', $r['reasons']);
    }

    /** También damaged / returns quedan excluidas. */
    public function test_damaged_or_returns_default_is_not_an_eligible_target(): void
    {
        $wh = Warehouse::create(['name' => 'CD']);
        $this->legacy($wh->id, 5, null, 10);
        $this->location($wh->id, 'RET', true, 'returns');

        $audit = app(LegacyInventoryReconciliationService::class)->auditWarehouse($wh->id);
        $this->assertFalse($audit['has_target_location']);
    }

    /** 7. default storage activa => target válida (ADD permitido si no hay blockers). */
    public function test_case7_active_storage_default_is_an_eligible_target(): void
    {
        $wh = Warehouse::create(['name' => 'CD']);
        $this->legacy($wh->id, 5, null, 100);
        $main = $this->location($wh->id, 'MAIN', true, 'storage');
        $this->locStock($main, 5, null, 60); // divergencia +40

        $svc = app(LegacyInventoryReconciliationService::class);
        $audit = $svc->auditWarehouse($wh->id);
        $this->assertTrue($audit['has_target_location']);
        $this->assertSame($main, $audit['inventory_location_id']);

        $r = $svc->planIncremental($wh->id)['plan'][0];
        $this->assertSame(40.0, $r['delta']);
        $this->assertSame('ADD', $r['action']);
        $this->assertSame($main, $r['target_inventory_location_id']);
        $this->assertNotContains('sin_ubicacion_destino', $r['reasons']);
    }

    // ---- backfillWarehouse respeta el contrato de destino apto -------------

    /** A. default QUARANTINE (no MAIN), sin stock, MAIN storage existe => backfill usa MAIN storage. */
    public function test_backfill_A_uses_existing_storage_main_never_quarantine_default(): void
    {
        $wh = Warehouse::create(['name' => 'CD']);
        $this->product(5);
        $this->legacy($wh->id, 5, null, 100);
        $quar = $this->location($wh->id, 'CUARENTENA', true, 'quarantine', true); // default = quarantine
        $main = $this->location($wh->id, 'MAIN', false, 'storage');               // MAIN storage apta

        $result = app(LegacyInventoryReconciliationService::class)->backfillWarehouse($wh->id);

        $this->assertTrue($result['is_reconciled']);
        $this->assertSame($main, (int) Warehouse::find($wh->id)->default_inventory_location_id);
        $this->assertSame(100.0, (float) InventoryLocationStock::where('inventory_location_id', $main)->where('product_id', 5)->value('quantity'));
        $this->assertSame(0, InventoryLocationStock::where('inventory_location_id', $quar)->count());
    }

    /** B. default QUARANTINE, no existe MAIN => crea MAIN storage y usa esa. */
    public function test_backfill_B_creates_storage_main_when_none_exists(): void
    {
        $wh = Warehouse::create(['name' => 'CD']);
        $this->product(5);
        $this->legacy($wh->id, 5, null, 100);
        $this->location($wh->id, 'CUARENTENA', true, 'quarantine', true);

        $result = app(LegacyInventoryReconciliationService::class)->backfillWarehouse($wh->id);

        $this->assertTrue($result['is_reconciled']);
        $created = InventoryLocation::where('warehouse_id', $wh->id)->where('code', 'MAIN')->first();
        $this->assertNotNull($created);
        $this->assertSame('storage', $created->type);
        $this->assertFalse((bool) $created->is_quarantine);
        $this->assertSame($created->id, (int) Warehouse::find($wh->id)->default_inventory_location_id);
        $this->assertSame(100.0, (float) InventoryLocationStock::where('inventory_location_id', $created->id)->where('product_id', 5)->value('quantity'));
    }

    /** C. existe code=MAIN pero es QUARANTINE => --apply rechaza, 0 escrituras. */
    public function test_backfill_C_rejects_when_code_main_is_quarantine(): void
    {
        $wh = Warehouse::create(['name' => 'CD']);
        $this->product(5);
        $this->legacy($wh->id, 5, null, 100);
        $this->location($wh->id, 'MAIN', true, 'quarantine', true); // code=MAIN pero inválida

        try {
            app(LegacyInventoryReconciliationService::class)->backfillWarehouse($wh->id);
            $this->fail('backfill debía rechazar una MAIN de cuarentena');
        } catch (ValidationException $e) {
            $this->assertStringContainsString('no es un destino apto', $e->getMessage());
        }

        $this->assertSame(0, InventoryLocationStock::count());
    }

    /** D. default storage válida => comportamiento existente intacto. */
    public function test_backfill_D_storage_default_unchanged_behavior(): void
    {
        $wh = Warehouse::create(['name' => 'CD']);
        $this->product(5);
        $this->legacy($wh->id, 5, null, 42);
        $main = $this->location($wh->id, 'MAIN', true, 'storage');

        $result = app(LegacyInventoryReconciliationService::class)->backfillWarehouse($wh->id);

        $this->assertTrue($result['is_reconciled']);
        $this->assertTrue($result['backfilled']);
        $this->assertSame(42.0, (float) InventoryLocationStock::where('inventory_location_id', $main)->where('product_id', 5)->value('quantity'));
    }

    /** E. tras backfill: destino storage/no-cuarentena y location total == legacy exacto. */
    public function test_backfill_E_target_is_storage_and_totals_match_exactly(): void
    {
        $wh = Warehouse::create(['name' => 'CD']);
        $this->product(5); $this->product(6);
        $this->legacy($wh->id, 5, null, 30);
        $this->legacy($wh->id, 6, 700, 12);
        $this->location($wh->id, 'CUARENTENA', true, 'quarantine', true);

        $svc = app(LegacyInventoryReconciliationService::class);
        $result = $svc->backfillWarehouse($wh->id);

        $target = InventoryLocation::find($result['inventory_location_id']);
        $this->assertSame('storage', $target->type);
        $this->assertFalse((bool) $target->is_quarantine);

        $audit = $svc->auditWarehouse($wh->id);
        $this->assertSame(42.0, $audit['legacy_total']);
        $this->assertSame(42.0, $audit['location_total']);
        $this->assertEmpty($audit['differences']);
        $this->assertTrue($audit['target_holds_all_stock']);
    }
}
