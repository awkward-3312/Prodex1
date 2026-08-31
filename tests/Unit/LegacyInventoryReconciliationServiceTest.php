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

        Schema::create('transfers', function ($table) {
            $table->increments('id');
            $table->integer('from_inventory_location_id')->nullable();
            $table->integer('to_inventory_location_id')->nullable();
            $table->string('logistics_status')->nullable();
            $table->timestamps();
            $table->softDeletes();
        });

        Schema::create('transfer_details', function ($table) {
            $table->increments('id');
            $table->integer('transfer_id');
            $table->integer('product_id');
            $table->integer('product_variant_id')->nullable();
            $table->decimal('quantity', 12, 3)->default(0);
            $table->timestamps();
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

    private function movement(string $type, int $productId, float $qty, string $ref, ?int $from, ?int $to, string $at, ?int $variantId = null): void
    {
        DB::table('inventory_location_movements')->insert([
            'movement_type' => $type,
            'product_id' => $productId,
            'product_variant_id' => $variantId,
            'from_inventory_location_id' => $from,
            'to_inventory_location_id' => $to,
            'quantity' => $qty,
            'reference_type' => $ref,
            'created_at' => $at,
            'updated_at' => $at,
        ]);
    }

    private function transitionState(int $warehouseId, ?int $locationId, string $reconciledAt): void
    {
        DB::table('inventory_transition_states')->insert([
            'warehouse_id' => $warehouseId,
            'inventory_location_id' => $locationId,
            'mode' => 'legacy_only',
            'status' => 'pending',
            'mismatch_count' => 0,
            'last_reconciled_at' => $reconciledAt,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    private function outboundTransfer(int $fromLocationId, int $productId, float $quantity, ?int $variantId = null, string $status = 'in_transit'): void
    {
        $transferId = DB::table('transfers')->insertGetId([
            'from_inventory_location_id' => $fromLocationId,
            'logistics_status' => $status,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        DB::table('transfer_details')->insert([
            'transfer_id' => $transferId,
            'product_id' => $productId,
            'product_variant_id' => $variantId,
            'quantity' => $quantity,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    /** El mapa `expect` tal y como lo construye la CLI a partir del pre-plan. */
    private function expectFromPlan(array $plan): array
    {
        $expect = [];
        foreach ($plan['plan'] as $r) {
            $expect[$r['product_id'].':'.((int) ($r['product_variant_id'] ?: 0))] = [
                'action' => $r['action'],
                'delta' => $r['delta'],
                'legacy' => $r['legacy'],
                'location_before' => $r['warehouse_location_quantity'],
                'classification' => $r['classification'],
            ];
        }
        return $expect;
    }

    private function reconMovements(): int
    {
        return DB::table('inventory_location_movements')
            ->where('reference_type', 'legacy_product_warehouse_incremental_reconciliation')->count();
    }

    // ---- Reconciliación incremental segura (--apply-incremental, PR nuevo) ----

    /** J1: Iphone X — legacy 100, ubicación 0, LEGACY_ONLY_PENDING 100 => apply => 100, un movimiento, pending 0. */
    public function test_j1_iphone_x_incremental_apply_reaches_parity_with_single_movement(): void
    {
        $wh = Warehouse::create(['name' => 'Centro de Distribución']);
        $this->product(8);
        $this->legacy($wh->id, 8, null, 100);
        $main = $this->location($wh->id, 'MAIN', true, 'storage'); // apta, vacía

        $svc = app(LegacyInventoryReconciliationService::class);

        $planBefore = $svc->planIncremental($wh->id);
        $this->assertSame(1, $planBefore['add_count']);
        $this->assertSame('LEGACY_ONLY_PENDING', $planBefore['plan'][0]['classification']);
        $this->assertSame(100.0, $planBefore['plan'][0]['delta']);

        $res = $svc->applyIncremental($wh->id, 8);

        $this->assertSame(1, $res['applied_count']);
        $this->assertSame(100.0, $res['applied_total_delta']);
        $this->assertSame(0.0, (float) $res['applied'][0]['location_before']);
        $this->assertSame(100.0, (float) $res['applied'][0]['location_after']);

        $this->assertSame(100.0, (float) InventoryLocationStock::where('inventory_location_id', $main)
            ->where('product_id', 8)->where('variant_key', 0)->value('quantity'));

        $movs = DB::table('inventory_location_movements')
            ->where('reference_type', 'legacy_product_warehouse_incremental_reconciliation')->get();
        $this->assertCount(1, $movs);
        $this->assertSame(100.0, (float) $movs[0]->quantity);
        $this->assertSame((int) $main, (int) $movs[0]->to_inventory_location_id);

        // product_warehouse intacto.
        $this->assertSame(100.0, (float) DB::table('product_warehouse')->where('warehouse_id', $wh->id)->where('product_id', 8)->sum('qte'));

        // Postcondición del auditor: ya no hay pendiente / revisión.
        $audit = $svc->auditWarehouse($wh->id);
        $this->assertSame(0.0, $audit['legacy_only_pending_total']);
        $this->assertEmpty($audit['differences']);
        $this->assertEmpty($svc->planIncremental($wh->id)['plan']);
    }

    /** J2: ejecutar exactamente lo mismo otra vez => 0 escritura, ubicación sigue 100. */
    public function test_j2_second_identical_apply_is_a_noop_zero_writes(): void
    {
        $wh = Warehouse::create(['name' => 'CD']);
        $this->product(8);
        $this->legacy($wh->id, 8, null, 100);
        $main = $this->location($wh->id, 'MAIN', true, 'storage');

        $svc = app(LegacyInventoryReconciliationService::class);
        $svc->applyIncremental($wh->id, 8);
        $this->assertSame(100.0, (float) InventoryLocationStock::where('inventory_location_id', $main)->where('product_id', 8)->value('quantity'));
        $this->assertSame(1, DB::table('inventory_location_movements')->where('reference_type', 'legacy_product_warehouse_incremental_reconciliation')->count());

        // Segundo intento con el mismo estado: la clave ya es RECONCILED, no hay
        // fila pendiente => abort, 0 escrituras nuevas.
        try {
            $svc->applyIncremental($wh->id, 8);
            $this->fail('el segundo apply debía rechazar (nada pendiente)');
        } catch (ValidationException $e) {
            $this->assertStringContainsString('no tiene ninguna fila LEGACY_ONLY_PENDING pendiente', $e->getMessage());
        }

        $this->assertSame(100.0, (float) InventoryLocationStock::where('inventory_location_id', $main)->where('product_id', 8)->value('quantity'));
        $this->assertSame(1, DB::table('inventory_location_movements')->where('reference_type', 'legacy_product_warehouse_incremental_reconciliation')->count());
    }

    /** J3: el plan dice +100 pero antes del apply legacy cambió a 120 => abort, nunca aplica los 100 viejos. */
    public function test_j3_stale_plan_when_legacy_changed_aborts_never_applies_old_delta(): void
    {
        $wh = Warehouse::create(['name' => 'CD']);
        $this->product(8);
        $this->legacy($wh->id, 8, null, 100);
        $main = $this->location($wh->id, 'MAIN', true, 'storage');

        $svc = app(LegacyInventoryReconciliationService::class);
        $expect = $this->expectFromPlan($svc->planIncremental($wh->id));
        $this->assertSame(100.0, $expect['8:0']['delta']);
        $this->assertSame('ADD', $expect['8:0']['action']);

        // legacy cambia por debajo del plan.
        DB::table('product_warehouse')->where('warehouse_id', $wh->id)->where('product_id', 8)->update(['qte' => 120]);

        try {
            $svc->applyIncremental($wh->id, 8, $expect);
            $this->fail('debía abortar por plan obsoleto');
        } catch (ValidationException $e) {
            $this->assertStringContainsString('plan quedó obsoleto', $e->getMessage());
        }

        $this->assertSame(0, DB::table('inventory_location_movements')->where('reference_type', 'legacy_product_warehouse_incremental_reconciliation')->count());
        $this->assertSame(0, InventoryLocationStock::where('inventory_location_id', $main)->count());
    }

    /** J4: la ubicación cambió antes del apply => abort con el plan previo, no aplica los 100 viejos. */
    public function test_j4_stale_plan_when_location_changed_aborts(): void
    {
        $wh = Warehouse::create(['name' => 'CD']);
        $this->product(8);
        $this->legacy($wh->id, 8, null, 100);
        $main = $this->location($wh->id, 'MAIN', true, 'storage');

        $svc = app(LegacyInventoryReconciliationService::class);
        $expect = $this->expectFromPlan($svc->planIncremental($wh->id));

        // alguien mete existencia en la ubicación antes del apply.
        $this->locStock($main, 8, null, 30);

        try {
            $svc->applyIncremental($wh->id, 8, $expect);
            $this->fail('debía abortar por ubicación cambiada');
        } catch (ValidationException $e) {
            $this->assertStringContainsString('plan quedó obsoleto', $e->getMessage());
        }

        $this->assertSame(0, DB::table('inventory_location_movements')->where('reference_type', 'legacy_product_warehouse_incremental_reconciliation')->count());
        $this->assertSame(30.0, (float) InventoryLocationStock::where('inventory_location_id', $main)->where('product_id', 8)->value('quantity'));
    }

    /** J5 + J6: Iphone15/16 — legacy = baseline, gap explicado por TransferDispatch => no candidato, apply lo rechaza. */
    public function test_j5_j6_dispatched_products_are_reconciled_and_incremental_apply_rejects_them(): void
    {
        $wh = Warehouse::create(['name' => 'Centro de Distribución']);
        $this->product(6); $this->product(7);
        $this->legacy($wh->id, 6, null, 88);
        $this->legacy($wh->id, 7, null, 90);
        $main = $this->location($wh->id, 'MAIN', true, 'storage');
        $this->locStock($main, 6, null, 60); // 88 baseline − 28 dispatch
        $this->locStock($main, 7, null, 78); // 90 baseline − 12 dispatch
        $this->transitionState($wh->id, $main, '2026-08-22 00:00:00');
        $this->movement('increase', 6, 88, 'legacy_product_warehouse_backfill', null, $main, '2026-08-21 23:00:00');
        $this->movement('increase', 7, 90, 'legacy_product_warehouse_backfill', null, $main, '2026-08-21 23:00:00');
        $this->movement('decrease', 6, 28, 'TransferDispatch', $main, null, '2026-08-25 00:00:00');
        $this->movement('decrease', 7, 12, 'TransferDispatch', $main, null, '2026-08-25 00:00:00');

        $svc = app(LegacyInventoryReconciliationService::class);

        $audit = $svc->auditWarehouse($wh->id);
        $this->assertSame(0.0, $audit['legacy_only_pending_total']);
        $this->assertEmpty($svc->planIncremental($wh->id)['plan']);

        // quirúrgico (--product=6): rechaza, no es candidato.
        try {
            $svc->applyIncremental($wh->id, 6);
            $this->fail('Iphone15 no debía ser candidato de apply incremental');
        } catch (ValidationException $e) {
            $this->assertStringContainsString('no tiene ninguna fila LEGACY_ONLY_PENDING pendiente', $e->getMessage());
        }
        try {
            $svc->applyIncremental($wh->id, 7);
            $this->fail('Iphone16 no debía ser candidato de apply incremental');
        } catch (ValidationException $e) {
            $this->assertStringContainsString('no tiene ninguna fila LEGACY_ONLY_PENDING pendiente', $e->getMessage());
        }

        $this->assertSame(0, DB::table('inventory_location_movements')->where('reference_type', 'legacy_product_warehouse_incremental_reconciliation')->count());
        $this->assertSame(60.0, (float) InventoryLocationStock::where('inventory_location_id', $main)->where('product_id', 6)->value('quantity'));
        $this->assertSame(78.0, (float) InventoryLocationStock::where('inventory_location_id', $main)->where('product_id', 7)->value('quantity'));
    }

    /** J7: batch-tracked / IMEI => rechazado, nunca reconciliación quantity-only. */
    public function test_j7_batch_or_imei_product_is_rejected(): void
    {
        foreach ([['is_batch_tracked' => true], ['is_imei' => 1]] as $i => $flags) {
            $wh = Warehouse::create(['name' => 'CD '.$i]);
            $pid = 20 + $i;
            $this->product($pid, $flags);
            $this->legacy($wh->id, $pid, null, 100);
            $main = $this->location($wh->id, 'MAIN', true, 'storage');

            $svc = app(LegacyInventoryReconciliationService::class);
            $row = $svc->planIncremental($wh->id)['plan'][0];
            $this->assertSame('MANUAL_REVIEW', $row['action']);
            $this->assertContains('lote_o_serie', $row['reasons']);

            try {
                $svc->applyIncremental($wh->id, $pid);
                $this->fail('un producto batch/IMEI no debía aplicarse');
            } catch (ValidationException $e) {
                // ok
            }

            $this->assertSame(0, DB::table('inventory_location_movements')->where('reference_type', 'legacy_product_warehouse_incremental_reconciliation')->count());
            $this->assertSame(0, InventoryLocationStock::where('inventory_location_id', $main)->count());
        }
    }

    /** J8: reservado > 0 en la ubicación => rechazado / manual. */
    public function test_j8_reserved_stock_blocks_incremental_apply(): void
    {
        $wh = Warehouse::create(['name' => 'CD']);
        $this->product(5);
        $this->legacy($wh->id, 5, null, 110);
        $main = $this->location($wh->id, 'MAIN', true, 'storage');
        $this->locStock($main, 5, null, 100, 5); // reservado 5

        $svc = app(LegacyInventoryReconciliationService::class);
        $row = $svc->planIncremental($wh->id)['plan'][0];
        $this->assertSame('MANUAL_REVIEW', $row['action']);
        $this->assertContains('reservado', $row['reasons']);

        $this->expectException(ValidationException::class);
        try {
            $svc->applyIncremental($wh->id, 5);
        } finally {
            $this->assertSame(100.0, (float) InventoryLocationStock::where('inventory_location_id', $main)->where('product_id', 5)->value('quantity'));
            $this->assertSame(0, DB::table('inventory_location_movements')->where('reference_type', 'legacy_product_warehouse_incremental_reconciliation')->count());
        }
    }

    /** J9: tránsito de salida no recibido => rechazado / manual. */
    public function test_j9_outbound_in_transit_blocks_incremental_apply(): void
    {
        $wh = Warehouse::create(['name' => 'CD']);
        $this->product(5);
        $this->legacy($wh->id, 5, null, 110);
        $main = $this->location($wh->id, 'MAIN', true, 'storage');
        $this->locStock($main, 5, null, 100);
        $this->outboundTransfer($main, 5, 8); // 8 uds en tránsito de salida

        $svc = app(LegacyInventoryReconciliationService::class);
        $row = $svc->planIncremental($wh->id)['plan'][0];
        $this->assertSame('MANUAL_REVIEW', $row['action']);
        $this->assertContains('transito_salida', $row['reasons']);

        try {
            $svc->applyIncremental($wh->id, 5);
            $this->fail('un producto con tránsito de salida no debía aplicarse');
        } catch (ValidationException $e) {
            // ok
        }
        $this->assertSame(100.0, (float) InventoryLocationStock::where('inventory_location_id', $main)->where('product_id', 5)->value('quantity'));
        $this->assertSame(0, DB::table('inventory_location_movements')->where('reference_type', 'legacy_product_warehouse_incremental_reconciliation')->count());
    }

    /** J10: la postcondición falla (existencia fantasma sin movimiento) => rollback total, 0 cambios. */
    public function test_j10_postcondition_failure_rolls_back_everything(): void
    {
        $wh = Warehouse::create(['name' => 'CD']);
        $this->product(5);
        $this->legacy($wh->id, 5, null, 100);
        $main = $this->location($wh->id, 'MAIN', true, 'storage');
        // 20 uds en la ubicación SIN movimiento que las respalde: sin baseline el
        // plan las trata como parte del pendiente (delta 80), pero al aplicar y
        // recalcular con baseline queda drift 20 => UNKNOWN_REVIEW => rollback.
        $this->locStock($main, 5, null, 20);

        $svc = app(LegacyInventoryReconciliationService::class);
        $row = $svc->planIncremental($wh->id)['plan'][0];
        $this->assertSame('ADD', $row['action']);
        $this->assertSame(80.0, $row['delta']);

        try {
            $svc->applyIncremental($wh->id, 5);
            $this->fail('la postcondición debía fallar y hacer rollback');
        } catch (ValidationException $e) {
            $this->assertStringContainsString('postcondición falló', $e->getMessage());
        }

        $this->assertSame(20.0, (float) InventoryLocationStock::where('inventory_location_id', $main)->where('product_id', 5)->value('quantity'));
        $this->assertSame(0, DB::table('inventory_location_movements')->where('reference_type', 'legacy_product_warehouse_incremental_reconciliation')->count());
    }

    /** J11a: --product=seguro sólo aplica ese; el inválido queda intacto. */
    public function test_j11_surgical_product_applies_only_that_product(): void
    {
        $wh = Warehouse::create(['name' => 'CD']);
        $this->product(5);
        $this->product(6, ['is_batch_tracked' => true]);
        $this->legacy($wh->id, 5, null, 100);
        $this->legacy($wh->id, 6, null, 50);
        $main = $this->location($wh->id, 'MAIN', true, 'storage');

        $svc = app(LegacyInventoryReconciliationService::class);
        $res = $svc->applyIncremental($wh->id, 5);

        $this->assertSame(1, $res['applied_count']);
        $this->assertSame(5, $res['applied'][0]['product_id']);
        $this->assertSame(100.0, (float) InventoryLocationStock::where('inventory_location_id', $main)->where('product_id', 5)->value('quantity'));
        // el producto batch NO se tocó.
        $this->assertSame(0, InventoryLocationStock::where('inventory_location_id', $main)->where('product_id', 6)->count());
        $this->assertSame(1, DB::table('inventory_location_movements')->where('reference_type', 'legacy_product_warehouse_incremental_reconciliation')->count());
    }

    /** J11b: v1 — la ESCRITURA batch (sin --product) está deshabilitada; --plan (lectura) sigue funcionando. */
    public function test_j11_batch_write_is_disabled_read_only_plan_still_works(): void
    {
        $wh = Warehouse::create(['name' => 'CD']);
        $this->product(5);
        $this->product(6, ['is_batch_tracked' => true]);
        $this->legacy($wh->id, 5, null, 100);
        $this->legacy($wh->id, 6, null, 50);
        $main = $this->location($wh->id, 'MAIN', true, 'storage');

        $svc = app(LegacyInventoryReconciliationService::class);

        try {
            $svc->applyIncremental($wh->id, null);
            $this->fail('la escritura batch debía estar deshabilitada en v1');
        } catch (ValidationException $e) {
            $this->assertStringContainsString('requiere --product', $e->getMessage());
        }

        // 0 escrituras.
        $this->assertSame(0, $this->reconMovements());
        $this->assertSame(0, InventoryLocationStock::count());

        // planIncremental (sólo lectura) sí funciona sin --product.
        $plan = $svc->planIncremental($wh->id);
        $this->assertSame(1, $plan['add_count']);         // product5
        $this->assertSame(1, $plan['manual_review_count']); // product6 (lote_o_serie)
    }

    // ---- TOCTOU / concurrencia: snapshot bloqueado + expect de conjunto completo ----

    /** K1: legacy cambia antes del snapshot bloqueado => con expect aborta; sin expect recalcula y aplica el valor NUEVO, jamás el +100 viejo. */
    public function test_k1_locked_replan_uses_new_legacy_never_stale_delta(): void
    {
        $wh = Warehouse::create(['name' => 'CD']);
        $this->product(8);
        $this->legacy($wh->id, 8, null, 100);
        $main = $this->location($wh->id, 'MAIN', true, 'storage');

        $svc = app(LegacyInventoryReconciliationService::class);
        $expect = $this->expectFromPlan($svc->planIncremental($wh->id));
        $this->assertSame(100.0, $expect['8:0']['delta']);

        // el legacy sube antes de aplicar.
        DB::table('product_warehouse')->where('warehouse_id', $wh->id)->where('product_id', 8)->update(['qte' => 130]);

        // con el plan previo: abort, nunca aplica 100.
        try {
            $svc->applyIncremental($wh->id, 8, $expect);
            $this->fail('debía abortar por plan obsoleto');
        } catch (ValidationException $e) {
            $this->assertStringContainsString('plan quedó obsoleto', $e->getMessage());
        }
        $this->assertSame(0, $this->reconMovements());
        $this->assertSame(0, InventoryLocationStock::where('inventory_location_id', $main)->count());

        // sin plan previo: replan bloqueado => aplica el valor NUEVO (130), no 100.
        $res = $svc->applyIncremental($wh->id, 8);
        $this->assertSame(130.0, $res['applied_total_delta']);
        $this->assertSame(130.0, (float) InventoryLocationStock::where('inventory_location_id', $main)->where('product_id', 8)->value('quantity'));
    }

    /** K2: la ubicación cambia (recepción location-native) antes del snapshot bloqueado => el replan bloqueado ve el estado nuevo y aborta; nunca aplica los 100 viejos. */
    public function test_k2_locked_replan_uses_new_location_state(): void
    {
        $wh = Warehouse::create(['name' => 'CD']);
        $this->product(8);
        $this->legacy($wh->id, 8, null, 100);
        $main = $this->location($wh->id, 'MAIN', true, 'storage');

        $svc = app(LegacyInventoryReconciliationService::class);
        $expect = $this->expectFromPlan($svc->planIncremental($wh->id));
        $this->assertSame(100.0, $expect['8:0']['delta']);

        // llega existencia location-native (recepción) antes de aplicar: 0 -> 20.
        $this->locStock($main, 8, null, 20);
        $this->movement('increase', 8, 20, 'Receipt', null, $main, (string) now());

        // con el plan previo: abort (el replan bloqueado ya no ve un ADD limpio).
        try {
            $svc->applyIncremental($wh->id, 8, $expect);
            $this->fail('debía abortar por estado de ubicación cambiado');
        } catch (ValidationException $e) {
            $this->assertStringContainsString('0 escrituras', $e->getMessage());
        }
        $this->assertSame(0, $this->reconMovements());
        $this->assertSame(20.0, (float) InventoryLocationStock::where('inventory_location_id', $main)->where('product_id', 8)->value('quantity'));

        // sin plan previo: el replan bloqueado clasifica UNKNOWN_REVIEW y también
        // rechaza — jamás aplica los 100 viejos a ciegas.
        try {
            $svc->applyIncremental($wh->id, 8);
            $this->fail('sin plan previo también debía rechazar (UNKNOWN_REVIEW)');
        } catch (ValidationException $e) {
            $this->assertStringContainsString('no es candidato ADD seguro', $e->getMessage());
        }
        $this->assertSame(0, $this->reconMovements());
        $this->assertSame(20.0, (float) InventoryLocationStock::where('inventory_location_id', $main)->where('product_id', 8)->value('quantity'));
    }

    /** K3: --product; el pre-plan tenía ADD para el producto y en el replan bloqueado pasa a MANUAL_REVIEW (reservado) => ABORT TOTAL por conjunto ADD cambiado. */
    public function test_k3_aborts_when_expected_add_key_drops_from_add_set(): void
    {
        $wh = Warehouse::create(['name' => 'CD']);
        $this->product(8);
        $this->legacy($wh->id, 8, null, 100);
        $main = $this->location($wh->id, 'MAIN', true, 'storage');

        $svc = app(LegacyInventoryReconciliationService::class);
        $expect = $this->expectFromPlan($svc->planIncremental($wh->id));
        $this->assertSame('ADD', $expect['8:0']['action']);

        // aparece reservado en la ubicación antes del apply => LEGACY_ONLY_PENDING
        // sigue, pero action pasa a MANUAL_REVIEW: sale del conjunto ADD.
        $this->locStock($main, 8, null, 0, 5);

        try {
            $svc->applyIncremental($wh->id, 8, $expect);
            $this->fail('debía abortar porque la clave salió del conjunto ADD');
        } catch (ValidationException $e) {
            $this->assertStringContainsString('conjunto de claves ADD ya no coincide', $e->getMessage());
        }

        $this->assertSame(0, $this->reconMovements());
        $this->assertSame(0.0, (float) InventoryLocationStock::where('inventory_location_id', $main)->where('product_id', 8)->value('quantity'));
    }

    /** K4: --product; el pre-plan tenía ADD y en el replan bloqueado la clave cambia de classification (LEGACY_ONLY_PENDING -> UNKNOWN_REVIEW) => ABORT TOTAL. */
    public function test_k4_aborts_when_key_changes_classification(): void
    {
        $wh = Warehouse::create(['name' => 'CD']);
        $this->product(8);
        $this->legacy($wh->id, 8, null, 80);
        $main = $this->location($wh->id, 'MAIN', true, 'storage');

        $svc = app(LegacyInventoryReconciliationService::class);
        $expect = $this->expectFromPlan($svc->planIncremental($wh->id));
        $this->assertSame('ADD', $expect['8:0']['action']);

        // actividad location-native no explicada antes del apply: la clave pasa a
        // UNKNOWN_REVIEW.
        $this->locStock($main, 8, null, 20);
        $this->movement('increase', 8, 20, 'Receipt', null, $main, (string) now());

        try {
            $svc->applyIncremental($wh->id, 8, $expect);
            $this->fail('debía abortar porque la clave cambió de classification');
        } catch (ValidationException $e) {
            $this->assertStringContainsString('conjunto de claves ADD ya no coincide', $e->getMessage());
        }

        $this->assertSame(0, $this->reconMovements());
        $this->assertSame(20.0, (float) InventoryLocationStock::where('inventory_location_id', $main)->where('product_id', 8)->value('quantity'));
    }

    // ---- Iteración 3: materializar+lockear TODAS las filas del producto + --product obligatorio ----

    /** K6: producto sin fila de stock en una ubicación secundaria => apply materializa la fila 0 y la incluye en el conjunto bloqueado. */
    public function test_k6_materializes_zero_row_in_every_active_location(): void
    {
        $wh = Warehouse::create(['name' => 'CD']);
        $this->product(8);
        $this->legacy($wh->id, 8, null, 100);
        $main = $this->location($wh->id, 'MAIN', true, 'storage');
        $loc2 = $this->location($wh->id, 'LOC2', false, 'storage');
        $loc3 = $this->location($wh->id, 'LOC3', false, 'storage');

        $svc = app(LegacyInventoryReconciliationService::class);
        $res = $svc->applyIncremental($wh->id, 8);

        $this->assertSame(1, $res['applied_count']);
        // MAIN recibe el delta; LOC2/LOC3 quedan materializadas a 0.
        $this->assertSame(100.0, (float) InventoryLocationStock::where('inventory_location_id', $main)->where('product_id', 8)->value('quantity'));
        $this->assertSame(0.0, (float) InventoryLocationStock::where('inventory_location_id', $loc2)->where('product_id', 8)->value('quantity'));
        $this->assertSame(0.0, (float) InventoryLocationStock::where('inventory_location_id', $loc3)->where('product_id', 8)->value('quantity'));
        $this->assertSame(3, InventoryLocationStock::where('product_id', 8)->count());
    }

    /** K7: la ubicación TARGET pasa a inactive/quarantine entre pre-plan y apply => abort, 0 inventory write. */
    public function test_k7_target_becomes_ineligible_before_apply_aborts(): void
    {
        foreach ([['is_active' => 0], ['is_quarantine' => 1]] as $i => $mutation) {
            $pid = 30 + $i;
            $wh = Warehouse::create(['name' => 'CD '.$i]);
            $this->product($pid);
            $this->legacy($wh->id, $pid, null, 100);
            $main = $this->location($wh->id, 'MAIN', true, 'storage');

            $svc = app(LegacyInventoryReconciliationService::class);
            $expect = $this->expectFromPlan($svc->planIncremental($wh->id));
            $this->assertSame('ADD', $expect[$pid.':0']['action']);

            // la ubicación destino deja de ser apta.
            DB::table('inventory_locations')->where('id', $main)->update($mutation);

            try {
                $svc->applyIncremental($wh->id, $pid, $expect);
                $this->fail('debía abortar: target dejó de ser apto');
            } catch (ValidationException $e) {
                // ok
            }
            $this->assertSame(0, $this->reconMovements());
            $this->assertSame(0.0, (float) (InventoryLocationStock::where('inventory_location_id', $main)->where('product_id', $pid)->value('quantity') ?? 0));
        }
    }

    /** K8: el producto pasa a is_batch_tracked / is_imei antes del apply => abort, 0 write. */
    public function test_k8_product_becomes_tracked_before_apply_aborts(): void
    {
        foreach ([['is_batch_tracked' => 1], ['is_imei' => 1]] as $i => $mutation) {
            $pid = 40 + $i;
            $wh = Warehouse::create(['name' => 'CD '.$i]);
            $this->product($pid);
            $this->legacy($wh->id, $pid, null, 100);
            $main = $this->location($wh->id, 'MAIN', true, 'storage');

            $svc = app(LegacyInventoryReconciliationService::class);
            $expect = $this->expectFromPlan($svc->planIncremental($wh->id));
            $this->assertSame('ADD', $expect[$pid.':0']['action']);

            DB::table('products')->where('id', $pid)->update($mutation);

            try {
                $svc->applyIncremental($wh->id, $pid, $expect);
                $this->fail('debía abortar: el producto pasó a batch/IMEI');
            } catch (ValidationException $e) {
                // ok
            }
            $this->assertSame(0, $this->reconMovements());
            $this->assertSame(0, InventoryLocationStock::where('inventory_location_id', $main)->where('product_id', $pid)->where('quantity', '>', 0)->count());
        }
    }

    /** K9: applyIncremental (escritura) SIN --product => rechazo claro; planIncremental (lectura) sin --product sigue permitido. */
    public function test_k9_incremental_write_requires_product(): void
    {
        $wh = Warehouse::create(['name' => 'CD']);
        $this->product(8);
        $this->legacy($wh->id, 8, null, 100);
        $this->location($wh->id, 'MAIN', true, 'storage');

        $svc = app(LegacyInventoryReconciliationService::class);

        try {
            $svc->applyIncremental($wh->id, null);
            $this->fail('la escritura incremental sin --product debía rechazarse');
        } catch (ValidationException $e) {
            $this->assertStringContainsString('requiere --product', $e->getMessage());
        }
        $this->assertSame(0, $this->reconMovements());

        // lectura: sin --product sigue funcionando.
        $this->assertSame(1, $svc->planIncremental($wh->id)['add_count']);
    }

    /** K10: Iphone X (product8, simple), tres ubicaciones: sólo MAIN recibe +100, las otras quedan 0, provenance final RECONCILED. */
    public function test_k10_iphone_x_three_locations_only_main_gets_delta_final_reconciled(): void
    {
        $wh = Warehouse::create(['name' => 'Centro de Distribución']);
        $this->product(8);
        $this->legacy($wh->id, 8, null, 100);
        $main = $this->location($wh->id, 'MAIN', true, 'storage');
        $loc2 = $this->location($wh->id, 'LOC2', false, 'storage');
        $loc3 = $this->location($wh->id, 'LOC3', false, 'storage');

        $svc = app(LegacyInventoryReconciliationService::class);
        $res = $svc->applyIncremental($wh->id, 8);

        $this->assertSame(1, $res['applied_count']);
        $this->assertSame(100.0, $res['applied_total_delta']);
        $this->assertGreaterThan(0, (int) $res['applied'][0]['movement_id']);
        $this->assertSame(100.0, (float) InventoryLocationStock::where('inventory_location_id', $main)->where('product_id', 8)->value('quantity'));
        $this->assertSame(0.0, (float) InventoryLocationStock::where('inventory_location_id', $loc2)->where('product_id', 8)->value('quantity'));
        $this->assertSame(0.0, (float) InventoryLocationStock::where('inventory_location_id', $loc3)->where('product_id', 8)->value('quantity'));
        $this->assertSame(1, $this->reconMovements());

        $prov = app(\App\Services\InventoryProvenanceAuditService::class)->auditWarehouse($wh->id);
        $key = collect($prov['keys'])->firstWhere('product_id', 8);
        $this->assertSame('RECONCILED', $key['classification']);
        $this->assertSame(0.0, $prov['legacy_only_pending_total']);
        $this->assertEmpty($svc->planIncremental($wh->id)['plan']);
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
