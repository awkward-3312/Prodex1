<?php

namespace Tests\Unit;

use App\Models\Adjustment;
use App\Models\AdjustmentDetail;
use App\Models\Damage;
use App\Models\DamageDetail;
use App\Models\InventoryLocationMovement;
use App\Services\InventoryProvenanceAuditService;
use App\Services\LegacyInventoryReconciliationService;
use App\Services\LocationAwareAdjustmentService;
use App\Services\LocationAwareDamageService;
use App\Services\LocationAwareStockDocumentService;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;

class LocationAwareStockDocumentServiceTest extends TestCase
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
        Schema::create('combined_products', function ($t) {
            $t->increments('id');
            $t->integer('product_id');
            $t->integer('combined_product_id');
            $t->decimal('quantity', 12, 3)->default(1);
            $t->timestamps();
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
            $t->unique(['inventory_location_id', 'product_id', 'variant_key'], 'ils_la_unique');
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
        Schema::create('adjustments', function ($t) {
            $t->increments('id');
            $t->string('Ref')->nullable();
            $t->string('date')->nullable();
            $t->string('time')->nullable();
            $t->integer('warehouse_id')->nullable();
            $t->integer('inventory_location_id')->nullable();
            $t->integer('user_id')->nullable();
            $t->integer('items')->nullable();
            $t->text('notes')->nullable();
            $t->timestamps();
            $t->softDeletes();
        });
        Schema::create('adjustment_details', function ($t) {
            $t->increments('id');
            $t->integer('adjustment_id');
            $t->integer('product_id');
            $t->integer('product_variant_id')->nullable();
            $t->decimal('quantity', 12, 3);
            $t->string('type')->nullable();
            $t->timestamps();
        });
        Schema::create('damages', function ($t) {
            $t->increments('id');
            $t->string('Ref')->nullable();
            $t->string('date')->nullable();
            $t->string('time')->nullable();
            $t->integer('warehouse_id')->nullable();
            $t->integer('inventory_location_id')->nullable();
            $t->integer('user_id')->nullable();
            $t->integer('items')->nullable();
            $t->text('notes')->nullable();
            $t->boolean('source_locked')->default(false);
            $t->timestamps();
            $t->softDeletes();
        });
        Schema::create('damage_details', function ($t) {
            $t->increments('id');
            $t->integer('damage_id');
            $t->integer('product_id');
            $t->integer('product_variant_id')->nullable();
            $t->decimal('quantity', 12, 3);
            $t->timestamps();
        });
    }

    // ---- helpers ---------------------------------------------------------

    private function warehouse(): array
    {
        $whId = DB::table('warehouses')->insertGetId(['name' => 'CD', 'created_at' => now(), 'updated_at' => now()]);
        $locId = DB::table('inventory_locations')->insertGetId([
            'warehouse_id' => $whId, 'code' => 'MAIN', 'name' => 'Inventario principal', 'type' => 'storage',
            'is_active' => 1, 'is_quarantine' => 0, 'created_at' => now(), 'updated_at' => now(),
        ]);
        DB::table('warehouses')->where('id', $whId)->update(['default_inventory_location_id' => $locId]);

        return [(int) $whId, (int) $locId];
    }

    private function location(int $whId, string $code, string $type = 'storage', bool $quarantine = false, bool $active = true): int
    {
        return (int) DB::table('inventory_locations')->insertGetId([
            'warehouse_id' => $whId, 'code' => $code, 'name' => $code, 'type' => $type,
            'is_active' => $active ? 1 : 0, 'is_quarantine' => $quarantine ? 1 : 0,
            'created_at' => now(), 'updated_at' => now(),
        ]);
    }

    private function product(int $id, string $type = 'is_single', array $flags = []): void
    {
        DB::table('products')->insert(array_merge([
            'id' => $id, 'name' => 'P'.$id, 'code' => 'P'.$id, 'type' => $type,
            'is_batch_tracked' => false, 'is_imei' => 0, 'created_at' => now(), 'updated_at' => now(),
        ], $flags));
    }

    private function variant(int $id, int $productId): void
    {
        DB::table('product_variants')->insert([
            'id' => $id, 'product_id' => $productId, 'name' => 'V'.$id, 'code' => 'V'.$id,
            'created_at' => now(), 'updated_at' => now(),
        ]);
    }

    private function combo(int $comboId, array $components): void
    {
        $this->product($comboId, 'is_combo');
        foreach ($components as $componentId => $qty) {
            DB::table('combined_products')->insert([
                'product_id' => $comboId, 'combined_product_id' => $componentId, 'quantity' => $qty,
                'created_at' => now(), 'updated_at' => now(),
            ]);
        }
    }

    private function stock(int $locId, int $productId, float $qty, float $reserved = 0, ?int $variantId = null): void
    {
        DB::table('inventory_location_stocks')->insert([
            'inventory_location_id' => $locId, 'product_id' => $productId,
            'product_variant_id' => $variantId, 'variant_key' => (int) ($variantId ?: 0),
            'quantity' => $qty, 'reserved_quantity' => $reserved, 'manage_stock' => 1,
            'created_at' => now(), 'updated_at' => now(),
        ]);
    }

    private function loc(int $locId, int $productId, int $variantKey = 0): float
    {
        return (float) (DB::table('inventory_location_stocks')->where('inventory_location_id', $locId)
            ->where('product_id', $productId)->where('variant_key', $variantKey)->value('quantity') ?? 0);
    }

    private function pw(int $whId, int $productId): float
    {
        return (float) (DB::table('product_warehouse')->where('warehouse_id', $whId)->where('product_id', $productId)
            ->whereNull('product_variant_id')->value('qte') ?? 0);
    }

    private function movements(string $ref): \Illuminate\Support\Collection
    {
        return DB::table('inventory_location_movements')->where('reference_type', $ref)->get();
    }

    /** crea un Adjustment location-aware con details persistidos y aplica el efecto. */
    private function makeAdjustment(int $whId, int $locId, array $lines): Adjustment
    {
        return DB::transaction(function () use ($whId, $locId, $lines) {
            $svc = app(LocationAwareAdjustmentService::class);
            $validated = $svc->validateRequest($whId, $locId, $lines);
            $adj = Adjustment::create([
                'Ref' => 'AD_'.uniqid(), 'date' => '2026-08-31', 'time' => '10:00:00',
                'warehouse_id' => $whId, 'inventory_location_id' => $locId, 'user_id' => 1,
                'items' => count($validated['lines']), 'notes' => null,
            ]);
            $engineLines = [];
            foreach ($validated['lines'] as $ln) {
                $d = AdjustmentDetail::create([
                    'adjustment_id' => $adj->id, 'quantity' => $ln['quantity'],
                    'product_id' => $ln['product_id'], 'product_variant_id' => $ln['product_variant_id'],
                    'type' => $ln['type'],
                ]);
                $engineLines[] = $ln + ['detail_id' => $d->id];
            }
            $svc->apply($adj->id, $whId, $locId, $engineLines, 'create');

            return $adj;
        });
    }

    private function makeDamage(int $whId, int $locId, array $lines): Damage
    {
        return DB::transaction(function () use ($whId, $locId, $lines) {
            $svc = app(LocationAwareDamageService::class);
            $validated = $svc->validateRequest($whId, $locId, $lines);
            $dmg = Damage::create([
                'Ref' => 'DA_'.uniqid(), 'date' => '2026-08-31', 'time' => '10:00:00',
                'warehouse_id' => $whId, 'inventory_location_id' => $locId, 'user_id' => 1,
                'items' => count($validated['lines']), 'notes' => null,
            ]);
            $engineLines = [];
            foreach ($validated['lines'] as $ln) {
                $d = DamageDetail::create([
                    'damage_id' => $dmg->id, 'quantity' => $ln['quantity'],
                    'product_id' => $ln['product_id'], 'product_variant_id' => $ln['product_variant_id'],
                ]);
                $engineLines[] = $ln + ['detail_id' => $d->id];
            }
            $svc->apply($dmg->id, $whId, $locId, $engineLines, 'create');

            return $dmg;
        });
    }

    private function seedReconciled(int $whId, int $locId, int $productId, float $qty): void
    {
        // Estado tipo Iphone17: legacy = location = baseline, con movimiento de backfill.
        DB::table('product_warehouse')->insert([
            'product_id' => $productId, 'warehouse_id' => $whId, 'product_variant_id' => null,
            'qte' => $qty, 'manage_stock' => 1, 'created_at' => now(), 'updated_at' => now(),
        ]);
        $this->stock($locId, $productId, $qty);
        DB::table('inventory_transition_states')->insert([
            'warehouse_id' => $whId, 'inventory_location_id' => $locId, 'mode' => 'legacy_only',
            'status' => 'pending', 'mismatch_count' => 0, 'last_reconciled_at' => '2026-08-22 00:00:00',
            'created_at' => now(), 'updated_at' => now(),
        ]);
        DB::table('inventory_location_movements')->insert([
            'movement_type' => 'increase', 'product_id' => $productId, 'quantity' => $qty,
            'reference_type' => 'legacy_product_warehouse_backfill', 'to_inventory_location_id' => $locId,
            'created_at' => '2026-08-21 23:00:00', 'updated_at' => '2026-08-21 23:00:00',
        ]);
    }

    // ---- A1..A25 -------------------------------------------------------------

    /** A1 — Adjustment simple SUB: legacy intacto, location -10, provenance RECONCILED. */
    public function test_a1_adjustment_simple_sub(): void
    {
        [$wh, $loc] = $this->warehouse();
        $this->product(17);
        $this->seedReconciled($wh, $loc, 17, 150);

        $this->makeAdjustment($wh, $loc, [['product_id' => 17, 'product_variant_id' => null, 'quantity' => 10, 'type' => 'sub']]);

        $this->assertSame(150.0, $this->pw($wh, 17));                       // legacy INTACTO
        $this->assertSame(140.0, $this->loc($loc, 17));
        $this->assertSame(0.0, (float) DB::table('inventory_location_stocks')->where('inventory_location_id', $loc)->where('product_id', 17)->value('reserved_quantity'));

        $movs = $this->movements(LocationAwareStockDocumentService::REF_ADJUSTMENT);
        $this->assertCount(1, $movs);
        $this->assertSame('decrease', $movs[0]->movement_type);
        $this->assertSame(10.0, (float) $movs[0]->quantity);

        $prov = app(InventoryProvenanceAuditService::class)->auditWarehouse($wh);
        $key = collect($prov['keys'])->firstWhere('product_id', 17);
        $this->assertSame(150.0, (float) $key['baseline_quantity']);
        $this->assertSame(-10.0, (float) $key['post_baseline_native_net']);
        $this->assertSame(140.0, (float) $key['expected_location']);
        $this->assertSame(140.0, (float) $key['current_location']);
        $this->assertSame('RECONCILED', $key['classification']);
        $plan = app(LegacyInventoryReconciliationService::class)->planIncremental($wh);
        $this->assertSame(0, $plan['add_count']);
        $this->assertSame(0, $plan['manual_review_count']);
    }

    /** A2 — Adjustment simple ADD: legacy intacto, location +5. */
    public function test_a2_adjustment_simple_add(): void
    {
        [$wh, $loc] = $this->warehouse();
        $this->product(1);
        $this->seedReconciled($wh, $loc, 1, 40);

        $this->makeAdjustment($wh, $loc, [['product_id' => 1, 'product_variant_id' => null, 'quantity' => 5, 'type' => 'add']]);

        $this->assertSame(40.0, $this->pw($wh, 1));
        $this->assertSame(45.0, $this->loc($loc, 1));
        $this->assertSame('increase', $this->movements(LocationAwareStockDocumentService::REF_ADJUSTMENT)[0]->movement_type);
    }

    /** A3 — variant add/sub sin bleed entre variant_key. */
    public function test_a3_variant_no_bleed(): void
    {
        [$wh, $loc] = $this->warehouse();
        $this->product(1);
        $this->variant(901, 1);
        $this->variant(902, 1);
        $this->stock($loc, 1, 20, 0, 901);
        $this->stock($loc, 1, 30, 0, 902);

        $this->makeAdjustment($wh, $loc, [
            ['product_id' => 1, 'product_variant_id' => 901, 'quantity' => 4, 'type' => 'add'],
            ['product_id' => 1, 'product_variant_id' => 902, 'quantity' => 7, 'type' => 'sub'],
        ]);

        $this->assertSame(24.0, $this->loc($loc, 1, 901));
        $this->assertSame(23.0, $this->loc($loc, 1, 902));
        $this->assertSame(0.0, $this->loc($loc, 1, 0));
    }

    /** A4 — reserved: physical 150 / reserved 20 / available 130. sub 131 aborta, sub 130 ok. */
    public function test_a4_reserved_stock_bounds_decrease(): void
    {
        [$wh, $loc] = $this->warehouse();
        $this->product(1);
        $this->stock($loc, 1, 150, 20);

        try {
            $this->makeAdjustment($wh, $loc, [['product_id' => 1, 'product_variant_id' => null, 'quantity' => 131, 'type' => 'sub']]);
            $this->fail('sub 131 sobre available 130 debía abortar');
        } catch (ValidationException $e) {
            // ok
        }
        $this->assertSame(150.0, $this->loc($loc, 1));
        $this->assertCount(0, $this->movements(LocationAwareStockDocumentService::REF_ADJUSTMENT));

        $this->makeAdjustment($wh, $loc, [['product_id' => 1, 'product_variant_id' => null, 'quantity' => 130, 'type' => 'sub']]);
        $this->assertSame(20.0, $this->loc($loc, 1));
    }

    /** A5 — location de otro warehouse => abort antes de writes. */
    public function test_a5_wrong_warehouse_location(): void
    {
        [$wh1, $loc1] = $this->warehouse();
        [$wh2, $loc2] = $this->warehouse();
        $this->product(1);
        $this->stock($loc2, 1, 50);

        $this->expectException(ValidationException::class);
        try {
            $this->makeAdjustment($wh1, $loc2, [['product_id' => 1, 'product_variant_id' => null, 'quantity' => 5, 'type' => 'sub']]);
        } finally {
            $this->assertSame(0, Adjustment::count());
            $this->assertSame(50.0, $this->loc($loc2, 1));
        }
    }

    /** A6 — location inactiva / eliminada => abort. */
    public function test_a6_inactive_or_deleted_location(): void
    {
        [$wh, $loc] = $this->warehouse();
        $this->product(1);
        $inactive = $this->location($wh, 'OLD', 'storage', false, false);
        $deleted = $this->location($wh, 'GONE');
        DB::table('inventory_locations')->where('id', $deleted)->update(['deleted_at' => now()]);

        foreach ([$inactive, $deleted] as $bad) {
            try {
                $this->makeAdjustment($wh, $bad, [['product_id' => 1, 'product_variant_id' => null, 'quantity' => 1, 'type' => 'add']]);
                $this->fail("location {$bad} debía abortar");
            } catch (ValidationException $e) {
                // ok
            }
        }
        $this->assertSame(0, Adjustment::count());
    }

    /** A7 — request con producto batch-tracked => abort 0 header/details/movements. */
    public function test_a7_batch_tracked_request_rejected(): void
    {
        [$wh, $loc] = $this->warehouse();
        $this->product(1);
        $this->product(2, 'is_single', ['is_batch_tracked' => true]);
        $this->stock($loc, 1, 50);

        try {
            $this->makeAdjustment($wh, $loc, [
                ['product_id' => 1, 'product_variant_id' => null, 'quantity' => 5, 'type' => 'add'],
                ['product_id' => 2, 'product_variant_id' => null, 'quantity' => 3, 'type' => 'add'],
            ]);
            $this->fail('un producto batch-tracked debía rechazar todo el request');
        } catch (ValidationException $e) {
            $this->assertStringContainsString('lote o serie/IMEI', $e->getMessage());
        }
        $this->assertSame(0, Adjustment::count());
        $this->assertSame(0, AdjustmentDetail::count());
        $this->assertSame(0, InventoryLocationMovement::count());
    }

    /** A8 — request con producto IMEI => igual. */
    public function test_a8_imei_request_rejected(): void
    {
        [$wh, $loc] = $this->warehouse();
        $this->product(1, 'is_single', ['is_imei' => 1]);

        $this->expectException(ValidationException::class);
        try {
            $this->makeAdjustment($wh, $loc, [['product_id' => 1, 'product_variant_id' => null, 'quantity' => 1, 'type' => 'add']]);
        } finally {
            $this->assertSame(0, Adjustment::count());
            $this->assertSame(0, InventoryLocationMovement::count());
        }
    }

    /** A9 — combo Adjustment ADD: componentes -, combo +, misma location. */
    public function test_a9_combo_adjustment_add(): void
    {
        [$wh, $loc] = $this->warehouse();
        $this->product(1);
        $this->product(2);
        $this->combo(50, [1 => 2, 2 => 3]); // combo 50 = 2*p1 + 3*p2
        $this->stock($loc, 1, 100);
        $this->stock($loc, 2, 100);
        $this->stock($loc, 50, 0);

        $this->makeAdjustment($wh, $loc, [['product_id' => 50, 'product_variant_id' => null, 'quantity' => 4, 'type' => 'add']]);

        $this->assertSame(92.0, $this->loc($loc, 1));  // 100 - 2*4
        $this->assertSame(88.0, $this->loc($loc, 2));  // 100 - 3*4
        $this->assertSame(4.0, $this->loc($loc, 50));  // 0 + 4
    }

    /** A10 — combo Adjustment SUB: inversa exacta. */
    public function test_a10_combo_adjustment_sub(): void
    {
        [$wh, $loc] = $this->warehouse();
        $this->product(1);
        $this->product(2);
        $this->combo(50, [1 => 2, 2 => 3]);
        $this->stock($loc, 1, 100);
        $this->stock($loc, 2, 100);
        $this->stock($loc, 50, 10);

        $this->makeAdjustment($wh, $loc, [['product_id' => 50, 'product_variant_id' => null, 'quantity' => 4, 'type' => 'sub']]);

        $this->assertSame(108.0, $this->loc($loc, 1));  // 100 + 2*4
        $this->assertSame(112.0, $this->loc($loc, 2));  // 100 + 3*4
        $this->assertSame(6.0, $this->loc($loc, 50));   // 10 - 4
    }

    /** A11 — Damage simple: 150 - 20 => 130, legacy intacto, provenance RECONCILED. */
    public function test_a11_damage_simple(): void
    {
        [$wh, $loc] = $this->warehouse();
        $this->product(17);
        $this->seedReconciled($wh, $loc, 17, 150);

        $this->makeDamage($wh, $loc, [['product_id' => 17, 'product_variant_id' => null, 'quantity' => 20]]);

        $this->assertSame(150.0, $this->pw($wh, 17));
        $this->assertSame(130.0, $this->loc($loc, 17));
        $movs = $this->movements(LocationAwareStockDocumentService::REF_DAMAGE);
        $this->assertCount(1, $movs);
        $this->assertSame('decrease', $movs[0]->movement_type);
        $prov = app(InventoryProvenanceAuditService::class)->auditWarehouse($wh);
        $this->assertSame('RECONCILED', collect($prov['keys'])->firstWhere('product_id', 17)['classification']);
    }

    /** A12 — Damage sobre available insuficiente => abort; NO clamp a 0. */
    public function test_a12_damage_over_available_aborts_no_clamp(): void
    {
        [$wh, $loc] = $this->warehouse();
        $this->product(1);
        $this->stock($loc, 1, 15);

        try {
            $this->makeDamage($wh, $loc, [['product_id' => 1, 'product_variant_id' => null, 'quantity' => 20]]);
            $this->fail('dañar 20 con 15 disponibles debía abortar');
        } catch (ValidationException $e) {
            // ok
        }
        $this->assertSame(15.0, $this->loc($loc, 1)); // NO 0
        $this->assertSame(0, Damage::count());
        $this->assertCount(0, $this->movements(LocationAwareStockDocumentService::REF_DAMAGE));
    }

    /** A13 — Damage variant. */
    public function test_a13_damage_variant(): void
    {
        [$wh, $loc] = $this->warehouse();
        $this->product(1);
        $this->variant(901, 1);
        $this->stock($loc, 1, 40, 0, 901);

        $this->makeDamage($wh, $loc, [['product_id' => 1, 'product_variant_id' => 901, 'quantity' => 8]]);
        $this->assertSame(32.0, $this->loc($loc, 1, 901));
        $this->assertSame(0.0, $this->loc($loc, 1, 0));
    }

    /** A14 — Damage combo: componentes - y combo - (semántica legacy exacta). */
    public function test_a14_damage_combo_legacy_equivalent(): void
    {
        [$wh, $loc] = $this->warehouse();
        $this->product(1);
        $this->product(2);
        $this->combo(50, [1 => 2, 2 => 3]);
        $this->stock($loc, 1, 100);
        $this->stock($loc, 2, 100);
        $this->stock($loc, 50, 10);

        $this->makeDamage($wh, $loc, [['product_id' => 50, 'product_variant_id' => null, 'quantity' => 3]]);

        $this->assertSame(94.0, $this->loc($loc, 1));  // 100 - 2*3
        $this->assertSame(91.0, $this->loc($loc, 2));  // 100 - 3*3
        $this->assertSame(7.0, $this->loc($loc, 50));  // 10 - 3
    }

    /** A15 — Update location-aware Adjustment: revierte viejo + aplica nuevo, atómico. */
    public function test_a15_update_adjustment_reverses_and_reapplies(): void
    {
        [$wh, $loc] = $this->warehouse();
        $this->product(1);
        $this->stock($loc, 1, 100);
        $adj = $this->makeAdjustment($wh, $loc, [['product_id' => 1, 'product_variant_id' => null, 'quantity' => 10, 'type' => 'sub']]);
        $this->assertSame(90.0, $this->loc($loc, 1));

        // update: sub 10 -> add 4
        $engine = app(LocationAwareStockDocumentService::class);
        $svc = app(LocationAwareAdjustmentService::class);
        DB::transaction(function () use ($adj, $wh, $loc, $engine, $svc) {
            $old = AdjustmentDetail::where('adjustment_id', $adj->id)->get();
            $svc->reverse($adj->id, $wh, $loc, $engine->hydrateLines($old), 'update');   // +10 back => 100
            AdjustmentDetail::where('adjustment_id', $adj->id)->delete();
            $validated = $svc->validateRequest($wh, $loc, [['product_id' => 1, 'product_variant_id' => null, 'quantity' => 4, 'type' => 'add']]);
            $lines = [];
            foreach ($validated['lines'] as $ln) {
                $d = AdjustmentDetail::create(['adjustment_id' => $adj->id, 'quantity' => $ln['quantity'], 'product_id' => $ln['product_id'], 'product_variant_id' => $ln['product_variant_id'], 'type' => $ln['type']]);
                $lines[] = $ln + ['detail_id' => $d->id];
            }
            $svc->apply($adj->id, $wh, $loc, $lines, 'update');                          // +4 => 104
        });

        $this->assertSame(104.0, $this->loc($loc, 1));
        $this->assertCount(1, $this->movements(LocationAwareStockDocumentService::REF_ADJUSTMENT_REVERSAL));
    }

    /** A16 — mover Adjustment location A -> B: reversa en A, nuevo efecto en B. */
    public function test_a16_move_adjustment_between_locations(): void
    {
        [$wh, $locA] = $this->warehouse();
        $locB = $this->location($wh, 'B');
        $this->product(1);
        $this->stock($locA, 1, 100);
        $this->stock($locB, 1, 100);
        $adj = $this->makeAdjustment($wh, $locA, [['product_id' => 1, 'product_variant_id' => null, 'quantity' => 10, 'type' => 'sub']]);
        $this->assertSame(90.0, $this->loc($locA, 1));

        $engine = app(LocationAwareStockDocumentService::class);
        $svc = app(LocationAwareAdjustmentService::class);
        DB::transaction(function () use ($adj, $wh, $locA, $locB, $engine, $svc) {
            $old = AdjustmentDetail::where('adjustment_id', $adj->id)->get();
            $svc->reverse($adj->id, $wh, $locA, $engine->hydrateLines($old), 'update');  // A: back to 100
            AdjustmentDetail::where('adjustment_id', $adj->id)->delete();
            $validated = $svc->validateRequest($wh, $locB, [['product_id' => 1, 'product_variant_id' => null, 'quantity' => 10, 'type' => 'sub']]);
            $lines = [];
            foreach ($validated['lines'] as $ln) {
                $d = AdjustmentDetail::create(['adjustment_id' => $adj->id, 'quantity' => $ln['quantity'], 'product_id' => $ln['product_id'], 'product_variant_id' => $ln['product_variant_id'], 'type' => $ln['type']]);
                $lines[] = $ln + ['detail_id' => $d->id];
            }
            $svc->apply($adj->id, $wh, $locB, $lines, 'update');                          // B: 90
            $adj->update(['inventory_location_id' => $locB]);
        });

        $this->assertSame(100.0, $this->loc($locA, 1));
        $this->assertSame(90.0, $this->loc($locB, 1));
    }

    /** A17 — Update Damage equivalente. */
    public function test_a17_update_damage(): void
    {
        [$wh, $loc] = $this->warehouse();
        $this->product(1);
        $this->stock($loc, 1, 100);
        $dmg = $this->makeDamage($wh, $loc, [['product_id' => 1, 'product_variant_id' => null, 'quantity' => 20]]);
        $this->assertSame(80.0, $this->loc($loc, 1));

        $engine = app(LocationAwareStockDocumentService::class);
        $svc = app(LocationAwareDamageService::class);
        DB::transaction(function () use ($dmg, $wh, $loc, $engine, $svc) {
            $old = DamageDetail::where('damage_id', $dmg->id)->get();
            $svc->reverse($dmg->id, $wh, $loc, $engine->hydrateLines($old), 'update');   // +20 => 100
            DamageDetail::where('damage_id', $dmg->id)->delete();
            $validated = $svc->validateRequest($wh, $loc, [['product_id' => 1, 'product_variant_id' => null, 'quantity' => 5]]);
            $lines = [];
            foreach ($validated['lines'] as $ln) {
                $d = DamageDetail::create(['damage_id' => $dmg->id, 'quantity' => $ln['quantity'], 'product_id' => $ln['product_id'], 'product_variant_id' => $ln['product_variant_id']]);
                $lines[] = $ln + ['detail_id' => $d->id];
            }
            $svc->apply($dmg->id, $wh, $loc, $lines, 'update');                          // -5 => 95
        });

        $this->assertSame(95.0, $this->loc($loc, 1));
    }

    /** A18 — Destroy Adjustment location-aware: reversa + soft-delete. */
    public function test_a18_destroy_adjustment(): void
    {
        [$wh, $loc] = $this->warehouse();
        $this->product(1);
        $this->stock($loc, 1, 100);
        $adj = $this->makeAdjustment($wh, $loc, [['product_id' => 1, 'product_variant_id' => null, 'quantity' => 15, 'type' => 'sub']]);
        $this->assertSame(85.0, $this->loc($loc, 1));

        $engine = app(LocationAwareStockDocumentService::class);
        DB::transaction(function () use ($adj, $wh, $loc, $engine) {
            $details = AdjustmentDetail::where('adjustment_id', $adj->id)->get();
            app(LocationAwareAdjustmentService::class)->reverse($adj->id, $wh, $loc, $engine->hydrateLines($details), 'destroy');
            $adj->details()->delete();
            $adj->update(['deleted_at' => now()]);
        });

        $this->assertSame(100.0, $this->loc($loc, 1));
        $this->assertNotNull(DB::table('adjustments')->where('id', $adj->id)->value('deleted_at'));
        $this->assertSame(0, AdjustmentDetail::where('adjustment_id', $adj->id)->count());
        $this->assertCount(1, $this->movements(LocationAwareStockDocumentService::REF_ADJUSTMENT_REVERSAL));
    }

    /** A19 — Destroy Damage. */
    public function test_a19_destroy_damage(): void
    {
        [$wh, $loc] = $this->warehouse();
        $this->product(1);
        $this->stock($loc, 1, 100);
        $dmg = $this->makeDamage($wh, $loc, [['product_id' => 1, 'product_variant_id' => null, 'quantity' => 25]]);
        $this->assertSame(75.0, $this->loc($loc, 1));

        $engine = app(LocationAwareStockDocumentService::class);
        DB::transaction(function () use ($dmg, $wh, $loc, $engine) {
            $details = DamageDetail::where('damage_id', $dmg->id)->get();
            app(LocationAwareDamageService::class)->reverse($dmg->id, $wh, $loc, $engine->hydrateLines($details), 'destroy');
            $dmg->details()->delete();
            $dmg->update(['deleted_at' => now()]);
        });

        $this->assertSame(100.0, $this->loc($loc, 1));
        $this->assertNotNull(DB::table('damages')->where('id', $dmg->id)->value('deleted_at'));
    }

    /** A22 — fallo en mitad de combo => rollback total (stocks + movements + header/details). */
    public function test_a22_combo_failure_rolls_back_everything(): void
    {
        [$wh, $loc] = $this->warehouse();
        $this->product(1);
        $this->product(2);
        $this->combo(50, [1 => 2, 2 => 3]);
        $this->stock($loc, 1, 100);
        $this->stock($loc, 2, 5);   // insuficiente para 3*4 = 12
        $this->stock($loc, 50, 0);

        try {
            $this->makeAdjustment($wh, $loc, [['product_id' => 50, 'product_variant_id' => null, 'quantity' => 4, 'type' => 'sub']]);
            $this->fail('el combo debía abortar por stock insuficiente de un componente');
        } catch (ValidationException $e) {
            // ok
        }

        $this->assertSame(100.0, $this->loc($loc, 1));   // revertido
        $this->assertSame(5.0, $this->loc($loc, 2));
        $this->assertSame(0.0, $this->loc($loc, 50));
        $this->assertSame(0, Adjustment::count());
        $this->assertSame(0, AdjustmentDetail::count());
        $this->assertSame(0, InventoryLocationMovement::count());
    }

    /** A23 — provenance: los movimientos Adjustment/Damage cuentan en native_net, NO en baseline. */
    public function test_a23_movements_count_in_native_net_not_baseline(): void
    {
        [$wh, $loc] = $this->warehouse();
        $this->product(17);
        $this->seedReconciled($wh, $loc, 17, 150);

        $this->makeAdjustment($wh, $loc, [['product_id' => 17, 'product_variant_id' => null, 'quantity' => 10, 'type' => 'sub']]);
        $this->makeDamage($wh, $loc, [['product_id' => 17, 'product_variant_id' => null, 'quantity' => 5]]);

        // reference_type NO está en RECONCILIATION_REFS.
        foreach ([
            LocationAwareStockDocumentService::REF_ADJUSTMENT,
            LocationAwareStockDocumentService::REF_ADJUSTMENT_REVERSAL,
            LocationAwareStockDocumentService::REF_DAMAGE,
            LocationAwareStockDocumentService::REF_DAMAGE_REVERSAL,
        ] as $ref) {
            $this->assertNotContains($ref, InventoryProvenanceAuditService::RECONCILIATION_REFS);
        }

        $prov = app(InventoryProvenanceAuditService::class)->auditWarehouse($wh);
        $key = collect($prov['keys'])->firstWhere('product_id', 17);
        $this->assertSame(150.0, (float) $key['baseline_quantity']);          // baseline SIN tocar
        $this->assertSame(-15.0, (float) $key['post_baseline_native_net']);   // -10 adj -5 damage
        $this->assertSame(135.0, (float) $key['current_location']);
        $this->assertSame('RECONCILED', $key['classification']);
    }

    /** A20/A21 no aplican al motor (son de la rama legacy del controller): cubiertos por el contract test. */
}
