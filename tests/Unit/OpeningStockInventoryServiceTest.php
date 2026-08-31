<?php

namespace Tests\Unit;

use App\Models\InventoryLocation;
use App\Models\InventoryLocationMovement;
use App\Models\InventoryLocationStock;
use App\Models\Warehouse;
use App\Services\InventoryProvenanceAuditService;
use App\Services\InventoryService;
use App\Services\LegacyInventoryReconciliationService;
use App\Services\OpeningStockInventoryService;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Validation\ValidationException;
use RuntimeException;
use Tests\TestCase;

class OpeningStockInventoryServiceTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        Schema::create('warehouses', function ($t) {
            $t->increments('id');
            $t->integer('default_inventory_location_id')->nullable();
            $t->string('name');
            $t->timestamps();
            $t->softDeletes();
        });

        Schema::create('products', function ($t) {
            $t->increments('id');
            $t->string('name')->nullable();
            $t->string('code')->nullable();
            $t->string('type')->default('is_single');
            $t->boolean('is_batch_tracked')->default(false);
            $t->integer('is_imei')->default(0);
            $t->timestamps();
            $t->softDeletes();
        });

        Schema::create('product_variants', function ($t) {
            $t->increments('id');
            $t->integer('product_id');
            $t->string('name')->nullable();
            $t->string('code')->nullable();
            $t->timestamps();
            $t->softDeletes();
        });

        Schema::create('product_warehouse', function ($t) {
            $t->increments('id');
            $t->integer('product_id');
            $t->integer('warehouse_id');
            $t->integer('product_variant_id')->nullable();
            $t->decimal('qte', 12, 3)->default(0);
            $t->boolean('manage_stock')->default(true);
            $t->timestamps();
            $t->softDeletes();
        });

        Schema::create('inventory_locations', function ($t) {
            $t->increments('id');
            $t->integer('branch_id')->nullable();
            $t->integer('warehouse_id')->nullable();
            $t->string('code');
            $t->string('name');
            $t->string('type')->default('storage');
            $t->boolean('is_sellable')->default(false);
            $t->boolean('is_quarantine')->default(false);
            $t->boolean('is_active')->default(true);
            $t->timestamps();
            $t->softDeletes();
        });

        Schema::create('inventory_location_stocks', function ($t) {
            $t->increments('id');
            $t->integer('inventory_location_id');
            $t->integer('product_id');
            $t->integer('product_variant_id')->nullable();
            $t->integer('variant_key')->default(0);
            $t->decimal('quantity', 12, 3)->default(0);
            $t->decimal('reserved_quantity', 12, 3)->default(0);
            $t->boolean('manage_stock')->default(true);
            $t->timestamps(6);
            $t->unique(['inventory_location_id', 'product_id', 'variant_key'], 'ils_open_unique');
        });

        Schema::create('inventory_location_movements', function ($t) {
            $t->increments('id');
            $t->string('movement_type', 40);
            $t->integer('product_id');
            $t->integer('product_variant_id')->nullable();
            $t->integer('from_inventory_location_id')->nullable();
            $t->integer('to_inventory_location_id')->nullable();
            $t->decimal('quantity', 12, 3);
            $t->integer('user_id')->nullable();
            $t->string('reference_type', 80)->nullable();
            $t->string('reference_id', 120)->nullable();
            $t->string('idempotency_key', 120)->nullable()->unique();
            $t->string('idempotency_fingerprint', 64)->nullable();
            $t->string('notes', 500)->nullable();
            $t->text('metadata')->nullable();
            $t->timestamps(6);
        });

        Schema::create('inventory_transition_states', function ($t) {
            $t->increments('id');
            $t->integer('warehouse_id')->unique();
            $t->integer('inventory_location_id')->nullable();
            $t->string('mode')->default('legacy_only');
            $t->string('status')->default('pending');
            $t->unsignedInteger('mismatch_count')->default(0);
            $t->timestamp('last_audited_at')->nullable();
            $t->timestamp('last_reconciled_at')->nullable();
            $t->timestamp('shadow_enabled_at')->nullable();
            $t->text('metadata')->nullable();
            $t->timestamps();
        });
    }

    // ---- helpers ---------------------------------------------------------

    private function product(int $id, array $flags = []): void
    {
        DB::table('products')->insert(array_merge([
            'id' => $id, 'name' => 'P'.$id, 'code' => 'P'.$id, 'type' => 'is_single',
            'is_batch_tracked' => false, 'is_imei' => 0, 'created_at' => now(), 'updated_at' => now(),
        ], $flags));
    }

    private function variant(int $id, int $productId, ?string $deletedAt = null): void
    {
        DB::table('product_variants')->insert([
            'id' => $id, 'product_id' => $productId, 'name' => 'V'.$id, 'code' => 'V'.$id,
            'deleted_at' => $deletedAt, 'created_at' => now(), 'updated_at' => now(),
        ]);
    }

    private function warehouseWithDefault(string $type = 'storage', bool $quarantine = false, bool $active = true, ?int $wrongWarehouse = null): array
    {
        $wh = Warehouse::create(['name' => 'CD']);
        $locId = DB::table('inventory_locations')->insertGetId([
            'warehouse_id' => $wrongWarehouse ?? $wh->id,
            'code' => 'MAIN', 'name' => 'MAIN', 'type' => $type,
            'is_quarantine' => $quarantine ? 1 : 0, 'is_active' => $active ? 1 : 0,
            'created_at' => now(), 'updated_at' => now(),
        ]);
        DB::table('warehouses')->where('id', $wh->id)->update(['default_inventory_location_id' => $locId]);
        return [$wh->id, (int) $locId];
    }

    private function pw(int $wh, int $product, ?int $variant = null): float
    {
        return (float) (DB::table('product_warehouse')->where('warehouse_id', $wh)
            ->where('product_id', $product)
            ->when($variant === null, fn ($q) => $q->whereNull('product_variant_id'))
            ->when($variant !== null, fn ($q) => $q->where('product_variant_id', $variant))
            ->value('qte') ?? 0);
    }

    private function loc(int $locId, int $product, int $variantKey = 0): float
    {
        return (float) (DB::table('inventory_location_stocks')->where('inventory_location_id', $locId)
            ->where('product_id', $product)->where('variant_key', $variantKey)->value('quantity') ?? 0);
    }

    private function syncMovements(): \Illuminate\Support\Collection
    {
        return DB::table('inventory_location_movements')
            ->where('reference_type', OpeningStockInventoryService::REFERENCE_TYPE)->get();
    }

    /** El servicio EXIGE transacción de negocio: todos los tests lo llaman así. */
    private function apply(int $wh, int $product, ?int $variant, float $qty, array $ctx = []): void
    {
        DB::transaction(fn () => app(OpeningStockInventoryService::class)
            ->applyOpeningStock($wh, $product, $variant, $qty, $ctx));
    }

    // ---- L1..L12 -------------------------------------------------------------

    /** L1 — caso exacto Iphone X nuevo: simple, default MAIN, opening=100. */
    public function test_l1_new_simple_product_opening_100(): void
    {
        [$wh, $main] = $this->warehouseWithDefault();
        $this->product(8);

        $this->apply($wh, 8, null, 100, ['source' => 'product_create']);

        $this->assertSame(100.0, $this->pw($wh, 8));
        $this->assertSame(100.0, $this->loc($main, 8));
        $this->assertSame(0.0, (float) DB::table('inventory_location_stocks')->where('inventory_location_id', $main)->where('product_id', 8)->value('reserved_quantity'));

        $movs = $this->syncMovements();
        $this->assertCount(1, $movs);
        $this->assertSame(100.0, (float) $movs[0]->quantity);
        $this->assertSame($main, (int) $movs[0]->to_inventory_location_id);

        $recon = app(LegacyInventoryReconciliationService::class);
        $plan = $recon->planIncremental($wh);
        $this->assertSame(0, $plan['add_count']);
        $this->assertSame(0, $plan['manual_review_count']);

        $prov = app(InventoryProvenanceAuditService::class)->auditWarehouse($wh);
        $this->assertSame('RECONCILED', collect($prov['keys'])->firstWhere('product_id', 8)['classification']);
    }

    /** L2 — variantes: A opening 7, B opening 3, sin mezcla entre variant_key. */
    public function test_l2_variants_do_not_bleed_between_variant_keys(): void
    {
        [$wh, $main] = $this->warehouseWithDefault();
        $this->product(8);
        $this->variant(901, 8);
        $this->variant(902, 8);

        $this->apply($wh, 8, 901, 7);
        $this->apply($wh, 8, 902, 3);

        $this->assertSame(7.0, $this->pw($wh, 8, 901));
        $this->assertSame(3.0, $this->pw($wh, 8, 902));
        $this->assertSame(7.0, $this->loc($main, 8, 901));
        $this->assertSame(3.0, $this->loc($main, 8, 902));
        $this->assertSame(0.0, $this->loc($main, 8, 0));
    }

    /** L3 — warehouse sin default location => abort, nunca legacy100/location0. */
    public function test_l3_no_default_location_aborts(): void
    {
        $wh = Warehouse::create(['name' => 'CD sin default'])->id;
        $this->product(8);

        try {
            DB::transaction(fn () => app(OpeningStockInventoryService::class)->applyOpeningStock($wh, 8, null, 100));
            $this->fail('debía abortar sin ubicación principal');
        } catch (ValidationException $e) {
            $this->assertStringContainsString('ubicación principal', $e->getMessage());
        }

        $this->assertSame(0.0, $this->pw($wh, 8));
        $this->assertSame(0, InventoryLocationStock::count());
        $this->assertSame(0, InventoryLocationMovement::count());
    }

    /** L4 — default inactive / quarantine / wrong warehouse / no-storage => abort total. */
    public function test_l4_ineligible_default_location_aborts(): void
    {
        $cases = [
            'inactive' => $this->warehouseWithDefault('storage', false, false),
            'quarantine' => $this->warehouseWithDefault('storage', true, true),
            'wrong_type' => $this->warehouseWithDefault('sales_floor', false, true),
        ];
        // wrong warehouse: la default apunta a una ubicación de OTRO almacén
        $otherWh = Warehouse::create(['name' => 'otro'])->id;
        $cases['wrong_warehouse'] = $this->warehouseWithDefault('storage', false, true, $otherWh);

        $pid = 8;
        $this->product($pid);

        foreach ($cases as $label => [$wh, $loc]) {
            try {
                DB::transaction(fn () => app(OpeningStockInventoryService::class)->applyOpeningStock($wh, $pid, null, 50));
                $this->fail("[$label] debía abortar");
            } catch (ValidationException $e) {
                // ok
            }
            $this->assertSame(0.0, $this->pw($wh, $pid), "[$label] legacy debe seguir 0");
        }
        $this->assertSame(0, InventoryLocationMovement::count());
        $this->assertSame(0.0, (float) DB::table('inventory_location_stocks')->sum('quantity'));
    }

    /** L5 — batch tracked opening > 0 => rechazo, 0 stock legacy, 0 stock location. */
    public function test_l5_batch_tracked_opening_positive_is_rejected(): void
    {
        [$wh, $main] = $this->warehouseWithDefault();
        $this->product(8, ['is_batch_tracked' => true]);

        try {
            DB::transaction(fn () => app(OpeningStockInventoryService::class)->applyOpeningStock($wh, 8, null, 10));
            $this->fail('un producto batch-tracked no debía aceptar opening stock por cantidad');
        } catch (ValidationException $e) {
            $this->assertStringContainsString('lote o serie/IMEI', $e->getMessage());
        }

        $this->assertSame(0.0, $this->pw($wh, 8));
        $this->assertSame(0.0, $this->loc($main, 8));
        $this->assertSame(0, InventoryLocationMovement::count());
    }

    /** L6 — IMEI opening > 0 => igual. */
    public function test_l6_imei_opening_positive_is_rejected(): void
    {
        [$wh, $main] = $this->warehouseWithDefault();
        $this->product(8, ['is_imei' => 1]);

        $this->expectException(ValidationException::class);
        try {
            $this->apply($wh, 8, null, 1);
        } finally {
            $this->assertSame(0.0, $this->pw($wh, 8));
            $this->assertSame(0.0, $this->loc($main, 8));
            $this->assertSame(0, InventoryLocationMovement::count());
        }
    }

    /** L7 — tracked opening 0 => el producto puede crearse normalmente (no se llama al servicio). */
    public function test_l7_tracked_opening_zero_is_not_a_service_call(): void
    {
        // Contrato: el caller NO invoca el servicio con qty<=0; y si lo hiciera,
        // el servicio rechaza qty<=0 ANTES de mirar el flag tracked.
        [$wh, $main] = $this->warehouseWithDefault();
        $this->product(8, ['is_batch_tracked' => true]);

        try {
            $this->apply($wh, 8, null, 0);
            $this->fail('qty 0 no es stock inicial');
        } catch (ValidationException $e) {
            $this->assertStringContainsString('mayor que cero', $e->getMessage());
        }
        $this->assertSame(0.0, $this->pw($wh, 8));
        $this->assertSame(0, InventoryLocationMovement::count());
    }

    /** L8 — opening_stock_import_single: producto existente, +10 => legacy y location reciben exactamente +10. */
    public function test_l8_import_single_existing_product_accumulates_exactly(): void
    {
        [$wh, $main] = $this->warehouseWithDefault();
        $this->product(8);
        // producto ya existente en el almacén (sincronizado: legacy 40 / location 40).
        DB::table('product_warehouse')->insert([
            'product_id' => 8, 'warehouse_id' => $wh, 'product_variant_id' => null,
            'qte' => 40, 'manage_stock' => 1, 'created_at' => now(), 'updated_at' => now(),
        ]);
        DB::table('inventory_location_stocks')->insert([
            'inventory_location_id' => $main, 'product_id' => 8, 'product_variant_id' => null,
            'variant_key' => 0, 'quantity' => 40, 'reserved_quantity' => 0, 'manage_stock' => 1,
            'created_at' => now(), 'updated_at' => now(),
        ]);
        DB::table('inventory_location_movements')->insert([
            'movement_type' => 'increase', 'product_id' => 8, 'quantity' => 40,
            'reference_type' => OpeningStockInventoryService::REFERENCE_TYPE, 'to_inventory_location_id' => $main,
            'created_at' => now(), 'updated_at' => now(),
        ]);

        $this->apply($wh, 8, null, 10, ['source' => 'import_single']);

        $this->assertSame(50.0, $this->pw($wh, 8));
        $this->assertSame(50.0, $this->loc($main, 8));
        $this->assertCount(2, $this->syncMovements());
        $prov = app(InventoryProvenanceAuditService::class)->auditWarehouse($wh);
        $this->assertSame('RECONCILED', collect($prov['keys'])->firstWhere('product_id', 8)['classification']);
    }

    /** L9 — opening_stock_import_variants: igual por variante, sin mezcla. */
    public function test_l9_import_variants_accumulate_per_variant(): void
    {
        [$wh, $main] = $this->warehouseWithDefault();
        $this->product(8);
        $this->variant(901, 8);
        $this->variant(902, 8);
        foreach ([901 => 5.0, 902 => 12.0] as $variant => $start) {
            DB::table('product_warehouse')->insert([
                'product_id' => 8, 'warehouse_id' => $wh, 'product_variant_id' => $variant,
                'qte' => $start, 'manage_stock' => 1, 'created_at' => now(), 'updated_at' => now(),
            ]);
            DB::table('inventory_location_stocks')->insert([
                'inventory_location_id' => $main, 'product_id' => 8, 'product_variant_id' => $variant,
                'variant_key' => $variant, 'quantity' => $start, 'reserved_quantity' => 0, 'manage_stock' => 1,
                'created_at' => now(), 'updated_at' => now(),
            ]);
            DB::table('inventory_location_movements')->insert([
                'movement_type' => 'increase', 'product_id' => 8, 'product_variant_id' => $variant, 'quantity' => $start,
                'reference_type' => OpeningStockInventoryService::REFERENCE_TYPE, 'to_inventory_location_id' => $main,
                'created_at' => now(), 'updated_at' => now(),
            ]);
        }

        $this->apply($wh, 8, 901, 10, ['source' => 'import_variants']);
        $this->apply($wh, 8, 902, 3, ['source' => 'import_variants']);

        $this->assertSame(15.0, $this->pw($wh, 8, 901));
        $this->assertSame(15.0, $this->loc($main, 8, 901));
        $this->assertSame(15.0, $this->pw($wh, 8, 902));
        $this->assertSame(15.0, $this->loc($main, 8, 902));
    }

    /** L10 — caso Iphone15 analógico: producto ya baselined, native dispatch -28, luego opening +10. */
    public function test_l10_already_baselined_product_stays_reconciled_after_opening(): void
    {
        [$wh, $main] = $this->warehouseWithDefault();
        $this->product(6);

        // Estado tipo Iphone15: legacy 88, location 60 (88 baseline − 28 dispatch).
        DB::table('product_warehouse')->insert([
            'product_id' => 6, 'warehouse_id' => $wh, 'product_variant_id' => null,
            'qte' => 88, 'manage_stock' => 1, 'created_at' => now(), 'updated_at' => now(),
        ]);
        DB::table('inventory_location_stocks')->insert([
            'inventory_location_id' => $main, 'product_id' => 6, 'product_variant_id' => null,
            'variant_key' => 0, 'quantity' => 60, 'reserved_quantity' => 0, 'manage_stock' => 1,
            'created_at' => now(), 'updated_at' => now(),
        ]);
        DB::table('inventory_transition_states')->insert([
            'warehouse_id' => $wh, 'inventory_location_id' => $main, 'mode' => 'legacy_only',
            'status' => 'pending', 'mismatch_count' => 0, 'last_reconciled_at' => '2026-08-22 00:00:00',
            'created_at' => now(), 'updated_at' => now(),
        ]);
        DB::table('inventory_location_movements')->insert([
            'movement_type' => 'increase', 'product_id' => 6, 'quantity' => 88,
            'reference_type' => 'legacy_product_warehouse_backfill', 'to_inventory_location_id' => $main,
            'created_at' => '2026-08-21 23:00:00', 'updated_at' => '2026-08-21 23:00:00',
        ]);
        DB::table('inventory_location_movements')->insert([
            'movement_type' => 'decrease', 'product_id' => 6, 'quantity' => 28,
            'reference_type' => 'TransferDispatch', 'from_inventory_location_id' => $main,
            'created_at' => '2026-08-25 00:00:00', 'updated_at' => '2026-08-25 00:00:00',
        ]);

        $this->apply($wh, 6, null, 10, ['source' => 'import_single']);

        $this->assertSame(98.0, $this->pw($wh, 6));
        $this->assertSame(70.0, $this->loc($main, 6));

        $prov = app(InventoryProvenanceAuditService::class)->auditWarehouse($wh);
        $key = collect($prov['keys'])->firstWhere('product_id', 6);
        $this->assertSame(98.0, (float) $key['baseline_quantity']);
        $this->assertSame(-28.0, (float) $key['post_baseline_native_net']);
        $this->assertSame(70.0, (float) $key['expected_location']);
        $this->assertSame(70.0, (float) $key['current_location']);
        $this->assertSame('RECONCILED', $key['classification']);
        // El baseline temporal global NO se movió (sigue el del backfill / state).
        $this->assertSame('2026-08-22 00:00:00', (string) $prov['baseline_at']);
        $this->assertSame(0.0, $prov['legacy_only_pending_total']);
        $this->assertSame(0, app(LegacyInventoryReconciliationService::class)->planIncremental($wh)['add_count']);
    }

    /** L11 — dual_write accidental: opening +10 => location +10 exacto, sin doble mirror. */
    public function test_l11_no_double_mirror_even_if_warehouse_in_dual_write(): void
    {
        [$wh, $main] = $this->warehouseWithDefault();
        $this->product(8);
        DB::table('inventory_transition_states')->insert([
            'warehouse_id' => $wh, 'inventory_location_id' => $main, 'mode' => 'dual_write',
            'status' => 'healthy', 'mismatch_count' => 0, 'created_at' => now(), 'updated_at' => now(),
        ]);

        $this->apply($wh, 8, null, 10);

        $this->assertSame(10.0, $this->pw($wh, 8));
        $this->assertSame(10.0, $this->loc($main, 8)); // NO 20
        $movs = DB::table('inventory_location_movements')->get();
        $this->assertCount(1, $movs);
        $this->assertSame(OpeningStockInventoryService::REFERENCE_TYPE, $movs[0]->reference_type);
        $this->assertNotSame('legacy_shadow_sync', $movs[0]->reference_type);
    }

    /** L12 — fallo de InventoryService tras modificar legacy => rollback total. */
    public function test_l12_inventory_service_failure_rolls_back_legacy(): void
    {
        [$wh, $main] = $this->warehouseWithDefault();
        $this->product(8);
        DB::table('product_warehouse')->insert([
            'product_id' => 8, 'warehouse_id' => $wh, 'product_variant_id' => null,
            'qte' => 0, 'manage_stock' => 1, 'created_at' => now(), 'updated_at' => now(),
        ]);

        $this->app->instance(InventoryService::class, new class extends InventoryService
        {
            public function increase(int $locationId, int $productId, float $quantity, ?int $variantId = null, array $context = []): InventoryLocationMovement
            {
                throw new RuntimeException('simulated InventoryService failure');
            }
        });

        try {
            DB::transaction(fn () => app(OpeningStockInventoryService::class)->applyOpeningStock($wh, 8, null, 25));
            $this->fail('debía propagar el fallo de InventoryService');
        } catch (RuntimeException $e) {
            $this->assertStringContainsString('simulated InventoryService failure', $e->getMessage());
        }

        $this->assertSame(0.0, $this->pw($wh, 8));                     // legacy revertido
        $this->assertSame(0.0, $this->loc($main, 8));                  // location intacta
        $this->assertSame(0, InventoryLocationMovement::count());      // 0 movimientos
    }

    // ---- Iteración 2: soft-delete, locks de filas padre, contrato de transacción ----

    /** M1 — existe una fila product_warehouse SOFT-DELETED: se crea una NUEVA activa, no se resucita. */
    public function test_m1_soft_deleted_product_warehouse_is_not_resurrected(): void
    {
        [$wh, $main] = $this->warehouseWithDefault();
        $this->product(8);
        $deletedId = DB::table('product_warehouse')->insertGetId([
            'product_id' => 8, 'warehouse_id' => $wh, 'product_variant_id' => null,
            'qte' => 50, 'manage_stock' => 1, 'deleted_at' => '2026-08-01 00:00:00',
            'created_at' => now(), 'updated_at' => now(),
        ]);

        $this->apply($wh, 8, null, 10, ['source' => 'import_single']);

        // la fila borrada sigue borrada e intacta.
        $old = DB::table('product_warehouse')->where('id', $deletedId)->first();
        $this->assertNotNull($old->deleted_at);
        $this->assertSame(50.0, (float) $old->qte);

        // hay UNA fila activa nueva a qte = 10.
        $active = DB::table('product_warehouse')->where('warehouse_id', $wh)->where('product_id', 8)
            ->whereNull('deleted_at')->get();
        $this->assertCount(1, $active);
        $this->assertNotSame($deletedId, (int) $active[0]->id);
        $this->assertSame(10.0, (float) $active[0]->qte);

        $this->assertSame(10.0, $this->loc($main, 8));
        $prov = app(InventoryProvenanceAuditService::class)->auditWarehouse($wh);
        $this->assertSame('RECONCILED', collect($prov['keys'])->firstWhere('product_id', 8)['classification']);
        $plan = app(LegacyInventoryReconciliationService::class)->planIncremental($wh);
        $this->assertSame(0, $plan['add_count']);
        $this->assertSame(0, $plan['manual_review_count']);
    }

    /** M2 — dos filas product_warehouse ACTIVAS para la misma clave => abort total. */
    public function test_m2_multiple_active_product_warehouse_rows_abort(): void
    {
        [$wh, $main] = $this->warehouseWithDefault();
        $this->product(8);
        foreach ([30.0, 15.0] as $q) {
            DB::table('product_warehouse')->insert([
                'product_id' => 8, 'warehouse_id' => $wh, 'product_variant_id' => null,
                'qte' => $q, 'manage_stock' => 1, 'created_at' => now(), 'updated_at' => now(),
            ]);
        }

        try {
            $this->apply($wh, 8, null, 10);
            $this->fail('dos filas activas para la misma clave debían abortar');
        } catch (ValidationException $e) {
            $this->assertStringContainsString('filas product_warehouse ACTIVAS para la misma clave', $e->getMessage());
        }

        $this->assertSame([30.0, 15.0], DB::table('product_warehouse')->where('warehouse_id', $wh)
            ->where('product_id', 8)->orderBy('id')->pluck('qte')->map(fn ($q) => (float) $q)->all());
        $this->assertSame(0.0, $this->loc($main, 8));
        $this->assertSame(0, InventoryLocationMovement::count());
    }

    /** M5 — variante inválida (de otro producto / borrada / inexistente) => abort total. */
    public function test_m5_invalid_variant_aborts(): void
    {
        [$wh, $main] = $this->warehouseWithDefault();
        $this->product(8);
        $this->product(9);
        $this->variant(700, 9);            // pertenece a OTRO producto
        $this->variant(701, 8, '2026-08-01 00:00:00'); // borrada

        foreach ([999, 700, 701] as $badVariant) {
            try {
                $this->apply($wh, 8, $badVariant, 5);
                $this->fail("variante {$badVariant} debía abortar");
            } catch (ValidationException $e) {
                $this->assertStringContainsString('no pertenece al producto 8', $e->getMessage());
            }
        }
        $this->assertSame(0.0, (float) DB::table('product_warehouse')->where('warehouse_id', $wh)->sum('qte'));
        $this->assertSame(0, InventoryLocationMovement::count());
    }

    /** M6 — llamar al servicio FUERA de una transacción => rechazo inmediato, 0 cambios. */
    public function test_m6_outside_transaction_is_rejected(): void
    {
        [$wh, $main] = $this->warehouseWithDefault();
        $this->product(8);

        $this->assertSame(0, DB::transactionLevel());
        try {
            app(OpeningStockInventoryService::class)->applyOpeningStock($wh, 8, null, 10);
            $this->fail('el servicio no debía ejecutarse fuera de una transacción');
        } catch (ValidationException $e) {
            $this->assertStringContainsString('dentro de una transacción de negocio', $e->getMessage());
        }

        $this->assertSame(0.0, $this->pw($wh, 8));
        $this->assertSame(0, DB::table('product_warehouse')->count());
        $this->assertSame(0.0, $this->loc($main, 8));
        $this->assertSame(0, InventoryLocationMovement::count());
    }
}
