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
use App\Services\LocationCatalogReadService;
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
            $t->integer('unit_id')->nullable();
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
        foreach (['adjustments' => 'adjustment_details', 'damages' => 'damage_details'] as $head => $detail) {
            Schema::create($head, function ($t) {
                $t->increments('id');
                $t->string('Ref')->nullable();
                $t->string('date')->nullable();
                $t->string('time')->nullable();
                $t->integer('warehouse_id')->nullable();
                $t->integer('inventory_location_id')->nullable();
                $t->text('inventory_effect_snapshot')->nullable();
                $t->integer('user_id')->nullable();
                $t->integer('items')->nullable();
                $t->text('notes')->nullable();
                $t->boolean('source_locked')->default(false);
                $t->timestamps();
                $t->softDeletes();
            });
            Schema::create($detail, function ($t) use ($head) {
                $t->increments('id');
                $t->integer($head === 'adjustments' ? 'adjustment_id' : 'damage_id');
                $t->integer('product_id');
                $t->integer('product_variant_id')->nullable();
                $t->decimal('quantity', 12, 3);
                if ($head === 'adjustments') $t->string('type')->nullable();
                $t->timestamps();
            });
        }
    }

    // ---- fixtures ----------------------------------------------------------

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

    private function setComboComposition(int $comboId, array $components): void
    {
        DB::table('combined_products')->where('product_id', $comboId)->delete();
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

    private function seedReconciled(int $whId, int $locId, int $productId, float $qty): void
    {
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

    // ---- orquestación (imita al controller: todo dentro de una transacción) --

    private function createAdjustment(int $whId, int $locId, array $lines): Adjustment
    {
        return DB::transaction(function () use ($whId, $locId, $lines) {
            $svc = app(LocationAwareAdjustmentService::class);
            $validated = $svc->validateAndLock($whId, $locId, $lines);
            $adj = Adjustment::create([
                'Ref' => 'AD_'.uniqid(), 'date' => '2026-08-31', 'time' => '10:00:00',
                'warehouse_id' => $whId, 'inventory_location_id' => $locId, 'user_id' => 1,
                'items' => count($validated['lines']),
            ]);
            $withIds = [];
            foreach ($validated['lines'] as $ln) {
                $d = AdjustmentDetail::create([
                    'adjustment_id' => $adj->id, 'quantity' => $ln['quantity'],
                    'product_id' => $ln['product_id'], 'product_variant_id' => $ln['product_variant_id'],
                    'type' => $ln['type'],
                ]);
                $withIds[] = $ln + ['detail_id' => $d->id];
            }
            $snapshot = $svc->buildSnapshot($withIds);
            $adj->update(['inventory_effect_snapshot' => $snapshot]);
            $svc->applySnapshot($snapshot, $adj->id, $whId, $locId, 'create');

            return $adj->fresh();
        });
    }

    private function updateAdjustment(Adjustment $current, int $newWh, int $newLoc, array $lines): void
    {
        DB::transaction(function () use ($current, $newWh, $newLoc, $lines) {
            $svc = app(LocationAwareAdjustmentService::class);
            $locked = Adjustment::whereKey($current->id)->lockForUpdate()->firstOrFail();
            $old = $svc->normalizeSnapshot($locked->inventory_effect_snapshot);
            $extra = array_values(array_unique(array_map(fn ($e) => (int) $e['product_id'], $old)));
            $validated = $svc->validateAndLock($newWh, $newLoc, $lines, $extra);
            $svc->reverseSnapshot($old, $locked->id, (int) $locked->warehouse_id, (int) $locked->inventory_location_id, 'update');
            AdjustmentDetail::where('adjustment_id', $locked->id)->delete();
            $withIds = [];
            foreach ($validated['lines'] as $ln) {
                $d = AdjustmentDetail::create([
                    'adjustment_id' => $locked->id, 'quantity' => $ln['quantity'],
                    'product_id' => $ln['product_id'], 'product_variant_id' => $ln['product_variant_id'], 'type' => $ln['type'],
                ]);
                $withIds[] = $ln + ['detail_id' => $d->id];
            }
            $new = $svc->buildSnapshot($withIds);
            $svc->applySnapshot($new, $locked->id, $newWh, $newLoc, 'update');
            $locked->update(['warehouse_id' => $newWh, 'inventory_location_id' => $newLoc, 'inventory_effect_snapshot' => $new, 'items' => count($validated['lines'])]);
        });
    }

    private function destroyAdjustment(Adjustment $current): void
    {
        DB::transaction(function () use ($current) {
            $svc = app(LocationAwareAdjustmentService::class);
            $locked = Adjustment::whereKey($current->id)->lockForUpdate()->firstOrFail();
            $snap = $svc->normalizeSnapshot($locked->inventory_effect_snapshot);
            $svc->reverseSnapshot($snap, $locked->id, (int) $locked->warehouse_id, (int) $locked->inventory_location_id, 'destroy');
            $locked->details()->delete();
            $locked->update(['deleted_at' => now()]);
        });
    }

    private function createDamage(int $whId, int $locId, array $lines): Damage
    {
        return DB::transaction(function () use ($whId, $locId, $lines) {
            $svc = app(LocationAwareDamageService::class);
            $validated = $svc->validateAndLock($whId, $locId, $lines);
            $dmg = Damage::create([
                'Ref' => 'DA_'.uniqid(), 'date' => '2026-08-31', 'time' => '10:00:00',
                'warehouse_id' => $whId, 'inventory_location_id' => $locId, 'user_id' => 1,
                'items' => count($validated['lines']),
            ]);
            $withIds = [];
            foreach ($validated['lines'] as $ln) {
                $d = DamageDetail::create([
                    'damage_id' => $dmg->id, 'quantity' => $ln['quantity'],
                    'product_id' => $ln['product_id'], 'product_variant_id' => $ln['product_variant_id'],
                ]);
                $withIds[] = $ln + ['detail_id' => $d->id];
            }
            $snapshot = $svc->buildSnapshot($withIds);
            $dmg->update(['inventory_effect_snapshot' => $snapshot]);
            $svc->applySnapshot($snapshot, $dmg->id, $whId, $locId, 'create');

            return $dmg->fresh();
        });
    }

    private function destroyDamage(Damage $current): void
    {
        DB::transaction(function () use ($current) {
            $svc = app(LocationAwareDamageService::class);
            $locked = Damage::whereKey($current->id)->lockForUpdate()->firstOrFail();
            $snap = $svc->normalizeSnapshot($locked->inventory_effect_snapshot);
            $svc->reverseSnapshot($snap, $locked->id, (int) $locked->warehouse_id, (int) $locked->inventory_location_id, 'destroy');
            $locked->details()->delete();
            $locked->update(['deleted_at' => now()]);
        });
    }

    // ================= A1..A23 =================================================

    /** A1 — Adjustment simple SUB: legacy 150 intacto, location 140, provenance RECONCILED. */
    public function test_a1_adjustment_simple_sub(): void
    {
        [$wh, $loc] = $this->warehouse();
        $this->product(17);
        $this->seedReconciled($wh, $loc, 17, 150);

        $this->createAdjustment($wh, $loc, [['product_id' => 17, 'product_variant_id' => null, 'quantity' => 10, 'type' => 'sub']]);

        $this->assertSame(150.0, $this->pw($wh, 17));
        $this->assertSame(140.0, $this->loc($loc, 17));
        $movs = $this->movements(LocationAwareStockDocumentService::REF_ADJUSTMENT);
        $this->assertCount(1, $movs);
        $this->assertSame('decrease', $movs[0]->movement_type);
        $this->assertSame(10.0, (float) $movs[0]->quantity);

        $prov = app(InventoryProvenanceAuditService::class)->auditWarehouse($wh);
        $key = collect($prov['keys'])->firstWhere('product_id', 17);
        $this->assertSame(150.0, (float) $key['baseline_quantity']);
        $this->assertSame(-10.0, (float) $key['post_baseline_native_net']);
        $this->assertSame(140.0, (float) $key['current_location']);
        $this->assertSame('RECONCILED', $key['classification']);
        $plan = app(LegacyInventoryReconciliationService::class)->planIncremental($wh);
        $this->assertSame(0, $plan['add_count']);
        $this->assertSame(0, $plan['manual_review_count']);
    }

    public function test_a2_adjustment_simple_add(): void
    {
        [$wh, $loc] = $this->warehouse();
        $this->product(1);
        $this->seedReconciled($wh, $loc, 1, 40);
        $this->createAdjustment($wh, $loc, [['product_id' => 1, 'product_variant_id' => null, 'quantity' => 5, 'type' => 'add']]);
        $this->assertSame(40.0, $this->pw($wh, 1));
        $this->assertSame(45.0, $this->loc($loc, 1));
    }

    public function test_a3_variant_no_bleed(): void
    {
        [$wh, $loc] = $this->warehouse();
        $this->product(1);
        $this->variant(901, 1);
        $this->variant(902, 1);
        $this->stock($loc, 1, 20, 0, 901);
        $this->stock($loc, 1, 30, 0, 902);
        $this->createAdjustment($wh, $loc, [
            ['product_id' => 1, 'product_variant_id' => 901, 'quantity' => 4, 'type' => 'add'],
            ['product_id' => 1, 'product_variant_id' => 902, 'quantity' => 7, 'type' => 'sub'],
        ]);
        $this->assertSame(24.0, $this->loc($loc, 1, 901));
        $this->assertSame(23.0, $this->loc($loc, 1, 902));
        $this->assertSame(0.0, $this->loc($loc, 1, 0));
    }

    public function test_a4_reserved_bounds_decrease(): void
    {
        [$wh, $loc] = $this->warehouse();
        $this->product(1);
        $this->stock($loc, 1, 150, 20);
        try {
            $this->createAdjustment($wh, $loc, [['product_id' => 1, 'product_variant_id' => null, 'quantity' => 131, 'type' => 'sub']]);
            $this->fail('sub 131 sobre available 130 debía abortar');
        } catch (ValidationException $e) {
        }
        $this->assertSame(150.0, $this->loc($loc, 1));
        $this->assertSame(0, Adjustment::count());
        $this->createAdjustment($wh, $loc, [['product_id' => 1, 'product_variant_id' => null, 'quantity' => 130, 'type' => 'sub']]);
        $this->assertSame(20.0, $this->loc($loc, 1));
    }

    public function test_a5_wrong_warehouse_location(): void
    {
        [$wh1] = $this->warehouse();
        [$wh2, $loc2] = $this->warehouse();
        $this->product(1);
        $this->stock($loc2, 1, 50);
        $this->expectException(ValidationException::class);
        try {
            $this->createAdjustment($wh1, $loc2, [['product_id' => 1, 'product_variant_id' => null, 'quantity' => 5, 'type' => 'sub']]);
        } finally {
            $this->assertSame(0, Adjustment::count());
            $this->assertSame(50.0, $this->loc($loc2, 1));
        }
    }

    public function test_a6_inactive_or_deleted_location(): void
    {
        [$wh] = $this->warehouse();
        $this->product(1);
        $inactive = $this->location($wh, 'OLD', 'storage', false, false);
        $deleted = $this->location($wh, 'GONE');
        DB::table('inventory_locations')->where('id', $deleted)->update(['deleted_at' => now()]);
        foreach ([$inactive, $deleted] as $bad) {
            try {
                $this->createAdjustment($wh, $bad, [['product_id' => 1, 'product_variant_id' => null, 'quantity' => 1, 'type' => 'add']]);
                $this->fail("location {$bad} debía abortar");
            } catch (ValidationException $e) {
            }
        }
        $this->assertSame(0, Adjustment::count());
    }

    public function test_a7_batch_request_rejected(): void
    {
        [$wh, $loc] = $this->warehouse();
        $this->product(1);
        $this->product(2, 'is_single', ['is_batch_tracked' => true]);
        $this->stock($loc, 1, 50);
        try {
            $this->createAdjustment($wh, $loc, [
                ['product_id' => 1, 'product_variant_id' => null, 'quantity' => 5, 'type' => 'add'],
                ['product_id' => 2, 'product_variant_id' => null, 'quantity' => 3, 'type' => 'add'],
            ]);
            $this->fail('un producto batch debía rechazar todo el request');
        } catch (ValidationException $e) {
            $this->assertStringContainsString('lote o serie/IMEI', $e->getMessage());
        }
        $this->assertSame(0, Adjustment::count());
        $this->assertSame(0, AdjustmentDetail::count());
        $this->assertSame(0, InventoryLocationMovement::count());
    }

    public function test_a8_imei_request_rejected(): void
    {
        [$wh, $loc] = $this->warehouse();
        $this->product(1, 'is_single', ['is_imei' => 1]);
        $this->expectException(ValidationException::class);
        try {
            $this->createAdjustment($wh, $loc, [['product_id' => 1, 'product_variant_id' => null, 'quantity' => 1, 'type' => 'add']]);
        } finally {
            $this->assertSame(0, Adjustment::count());
            $this->assertSame(0, InventoryLocationMovement::count());
        }
    }

    public function test_a9_combo_adjustment_add(): void
    {
        [$wh, $loc] = $this->warehouse();
        $this->product(1);
        $this->product(2);
        $this->combo(50, [1 => 2, 2 => 3]);
        $this->stock($loc, 1, 100);
        $this->stock($loc, 2, 100);
        $this->stock($loc, 50, 0);
        $this->createAdjustment($wh, $loc, [['product_id' => 50, 'product_variant_id' => null, 'quantity' => 4, 'type' => 'add']]);
        $this->assertSame(92.0, $this->loc($loc, 1));
        $this->assertSame(88.0, $this->loc($loc, 2));
        $this->assertSame(4.0, $this->loc($loc, 50));
    }

    public function test_a10_combo_adjustment_sub(): void
    {
        [$wh, $loc] = $this->warehouse();
        $this->product(1);
        $this->product(2);
        $this->combo(50, [1 => 2, 2 => 3]);
        $this->stock($loc, 1, 100);
        $this->stock($loc, 2, 100);
        $this->stock($loc, 50, 10);
        $this->createAdjustment($wh, $loc, [['product_id' => 50, 'product_variant_id' => null, 'quantity' => 4, 'type' => 'sub']]);
        $this->assertSame(108.0, $this->loc($loc, 1));
        $this->assertSame(112.0, $this->loc($loc, 2));
        $this->assertSame(6.0, $this->loc($loc, 50));
    }

    public function test_a11_damage_simple(): void
    {
        [$wh, $loc] = $this->warehouse();
        $this->product(17);
        $this->seedReconciled($wh, $loc, 17, 150);
        $this->createDamage($wh, $loc, [['product_id' => 17, 'product_variant_id' => null, 'quantity' => 20]]);
        $this->assertSame(150.0, $this->pw($wh, 17));
        $this->assertSame(130.0, $this->loc($loc, 17));
        $this->assertSame('decrease', $this->movements(LocationAwareStockDocumentService::REF_DAMAGE)[0]->movement_type);
        $prov = app(InventoryProvenanceAuditService::class)->auditWarehouse($wh);
        $this->assertSame('RECONCILED', collect($prov['keys'])->firstWhere('product_id', 17)['classification']);
    }

    public function test_a12_damage_over_available_no_clamp(): void
    {
        [$wh, $loc] = $this->warehouse();
        $this->product(1);
        $this->stock($loc, 1, 15);
        try {
            $this->createDamage($wh, $loc, [['product_id' => 1, 'product_variant_id' => null, 'quantity' => 20]]);
            $this->fail('dañar 20 con 15 disponibles debía abortar');
        } catch (ValidationException $e) {
        }
        $this->assertSame(15.0, $this->loc($loc, 1));
        $this->assertSame(0, Damage::count());
    }

    public function test_a13_damage_variant(): void
    {
        [$wh, $loc] = $this->warehouse();
        $this->product(1);
        $this->variant(901, 1);
        $this->stock($loc, 1, 40, 0, 901);
        $this->createDamage($wh, $loc, [['product_id' => 1, 'product_variant_id' => 901, 'quantity' => 8]]);
        $this->assertSame(32.0, $this->loc($loc, 1, 901));
        $this->assertSame(0.0, $this->loc($loc, 1, 0));
    }

    public function test_a14_damage_combo(): void
    {
        [$wh, $loc] = $this->warehouse();
        $this->product(1);
        $this->product(2);
        $this->combo(50, [1 => 2, 2 => 3]);
        $this->stock($loc, 1, 100);
        $this->stock($loc, 2, 100);
        $this->stock($loc, 50, 10);
        $this->createDamage($wh, $loc, [['product_id' => 50, 'product_variant_id' => null, 'quantity' => 3]]);
        $this->assertSame(94.0, $this->loc($loc, 1));
        $this->assertSame(91.0, $this->loc($loc, 2));
        $this->assertSame(7.0, $this->loc($loc, 50));
    }

    public function test_a15_update_adjustment(): void
    {
        [$wh, $loc] = $this->warehouse();
        $this->product(1);
        $this->stock($loc, 1, 100);
        $adj = $this->createAdjustment($wh, $loc, [['product_id' => 1, 'product_variant_id' => null, 'quantity' => 10, 'type' => 'sub']]);
        $this->assertSame(90.0, $this->loc($loc, 1));
        $this->updateAdjustment($adj, $wh, $loc, [['product_id' => 1, 'product_variant_id' => null, 'quantity' => 4, 'type' => 'add']]);
        $this->assertSame(104.0, $this->loc($loc, 1)); // +10 back, +4
        $this->assertCount(1, $this->movements(LocationAwareStockDocumentService::REF_ADJUSTMENT_REVERSAL));
    }

    public function test_a16_move_adjustment_between_locations(): void
    {
        [$wh, $locA] = $this->warehouse();
        $locB = $this->location($wh, 'B');
        $this->product(1);
        $this->stock($locA, 1, 100);
        $this->stock($locB, 1, 100);
        $adj = $this->createAdjustment($wh, $locA, [['product_id' => 1, 'product_variant_id' => null, 'quantity' => 10, 'type' => 'sub']]);
        $this->updateAdjustment($adj, $wh, $locB, [['product_id' => 1, 'product_variant_id' => null, 'quantity' => 10, 'type' => 'sub']]);
        $this->assertSame(100.0, $this->loc($locA, 1));
        $this->assertSame(90.0, $this->loc($locB, 1));
    }

    public function test_a17_update_damage(): void
    {
        [$wh, $loc] = $this->warehouse();
        $this->product(1);
        $this->stock($loc, 1, 100);
        $dmg = $this->createDamage($wh, $loc, [['product_id' => 1, 'product_variant_id' => null, 'quantity' => 20]]);
        $this->assertSame(80.0, $this->loc($loc, 1));

        DB::transaction(function () use ($dmg, $wh, $loc) {
            $svc = app(LocationAwareDamageService::class);
            $locked = Damage::whereKey($dmg->id)->lockForUpdate()->firstOrFail();
            $old = $svc->normalizeSnapshot($locked->inventory_effect_snapshot);
            $validated = $svc->validateAndLock($wh, $loc, [['product_id' => 1, 'product_variant_id' => null, 'quantity' => 5]], [1]);
            $svc->reverseSnapshot($old, $locked->id, $wh, $loc, 'update');
            DamageDetail::where('damage_id', $locked->id)->delete();
            $withIds = [];
            foreach ($validated['lines'] as $ln) {
                $d = DamageDetail::create(['damage_id' => $locked->id, 'quantity' => $ln['quantity'], 'product_id' => $ln['product_id'], 'product_variant_id' => $ln['product_variant_id']]);
                $withIds[] = $ln + ['detail_id' => $d->id];
            }
            $new = $svc->buildSnapshot($withIds);
            $svc->applySnapshot($new, $locked->id, $wh, $loc, 'update');
            $locked->update(['inventory_effect_snapshot' => $new]);
        });
        $this->assertSame(95.0, $this->loc($loc, 1));
    }

    public function test_a18_destroy_adjustment(): void
    {
        [$wh, $loc] = $this->warehouse();
        $this->product(1);
        $this->stock($loc, 1, 100);
        $adj = $this->createAdjustment($wh, $loc, [['product_id' => 1, 'product_variant_id' => null, 'quantity' => 15, 'type' => 'sub']]);
        $this->assertSame(85.0, $this->loc($loc, 1));
        $this->destroyAdjustment($adj);
        $this->assertSame(100.0, $this->loc($loc, 1));
        $this->assertNotNull(DB::table('adjustments')->where('id', $adj->id)->value('deleted_at'));
        $this->assertSame(0, AdjustmentDetail::where('adjustment_id', $adj->id)->count());
        $this->assertCount(1, $this->movements(LocationAwareStockDocumentService::REF_ADJUSTMENT_REVERSAL));
    }

    public function test_a19_destroy_damage(): void
    {
        [$wh, $loc] = $this->warehouse();
        $this->product(1);
        $this->stock($loc, 1, 100);
        $dmg = $this->createDamage($wh, $loc, [['product_id' => 1, 'product_variant_id' => null, 'quantity' => 25]]);
        $this->assertSame(75.0, $this->loc($loc, 1));
        $this->destroyDamage($dmg);
        $this->assertSame(100.0, $this->loc($loc, 1));
        $this->assertNotNull(DB::table('damages')->where('id', $dmg->id)->value('deleted_at'));
    }

    public function test_a22_combo_failure_rolls_back_everything(): void
    {
        [$wh, $loc] = $this->warehouse();
        $this->product(1);
        $this->product(2);
        $this->combo(50, [1 => 2, 2 => 3]);
        $this->stock($loc, 1, 100);
        $this->stock($loc, 2, 5);
        $this->stock($loc, 50, 0);
        try {
            $this->createAdjustment($wh, $loc, [['product_id' => 50, 'product_variant_id' => null, 'quantity' => 4, 'type' => 'sub']]);
            $this->fail('el combo debía abortar');
        } catch (ValidationException $e) {
        }
        $this->assertSame(100.0, $this->loc($loc, 1));
        $this->assertSame(5.0, $this->loc($loc, 2));
        $this->assertSame(0, Adjustment::count());
        $this->assertSame(0, InventoryLocationMovement::count());
    }

    public function test_a23_movements_in_native_net_not_baseline(): void
    {
        [$wh, $loc] = $this->warehouse();
        $this->product(17);
        $this->seedReconciled($wh, $loc, 17, 150);
        $this->createAdjustment($wh, $loc, [['product_id' => 17, 'product_variant_id' => null, 'quantity' => 10, 'type' => 'sub']]);
        $this->createDamage($wh, $loc, [['product_id' => 17, 'product_variant_id' => null, 'quantity' => 5]]);
        foreach (['Adjustment', 'AdjustmentReversal', 'Damage', 'DamageReversal'] as $ref) {
            $this->assertNotContains($ref, InventoryProvenanceAuditService::RECONCILIATION_REFS);
        }
        $prov = app(InventoryProvenanceAuditService::class)->auditWarehouse($wh);
        $key = collect($prov['keys'])->firstWhere('product_id', 17);
        $this->assertSame(150.0, (float) $key['baseline_quantity']);
        $this->assertSame(-15.0, (float) $key['post_baseline_native_net']);
        $this->assertSame(135.0, (float) $key['current_location']);
        $this->assertSame('RECONCILED', $key['classification']);
    }

    // ================= B4..B14 (iteración 2) ===================================

    /** B4 — combo creado 2A+3B; se cambia a 1A+1B; destroy revierte el efecto HISTÓRICO exacto. */
    public function test_b4_destroy_reverts_historical_combo_effect_not_current_composition(): void
    {
        [$wh, $loc] = $this->warehouse();
        $this->product(1);
        $this->product(2);
        $this->combo(50, [1 => 2, 2 => 3]);
        $this->stock($loc, 1, 100);
        $this->stock($loc, 2, 100);
        $this->stock($loc, 50, 0);

        $adj = $this->createAdjustment($wh, $loc, [['product_id' => 50, 'product_variant_id' => null, 'quantity' => 4, 'type' => 'add']]);
        $this->assertSame(92.0, $this->loc($loc, 1));   // -8
        $this->assertSame(88.0, $this->loc($loc, 2));   // -12
        $this->assertSame(4.0, $this->loc($loc, 50));

        // se cambia la composición del combo.
        $this->setComboComposition(50, [1 => 1, 2 => 1]);

        $this->destroyAdjustment($adj);

        // reversa EXACTA de 2A+3B: A +8, B +12, combo -4. NO 1A+1B.
        $this->assertSame(100.0, $this->loc($loc, 1));
        $this->assertSame(100.0, $this->loc($loc, 2));
        $this->assertSame(0.0, $this->loc($loc, 50));
    }

    /** B5 — update tras cambio de combo: revierte snapshot viejo exacto y aplica composición vigente. */
    public function test_b5_update_reverts_old_snapshot_then_applies_current_composition(): void
    {
        [$wh, $loc] = $this->warehouse();
        $this->product(1);
        $this->product(2);
        $this->combo(50, [1 => 2, 2 => 3]);
        $this->stock($loc, 1, 100);
        $this->stock($loc, 2, 100);
        $this->stock($loc, 50, 0);

        $adj = $this->createAdjustment($wh, $loc, [['product_id' => 50, 'product_variant_id' => null, 'quantity' => 4, 'type' => 'add']]);
        // A 92 / B 88 / combo 4

        $this->setComboComposition(50, [1 => 1, 2 => 1]); // nueva composición

        $this->updateAdjustment($adj, $wh, $loc, [['product_id' => 50, 'product_variant_id' => null, 'quantity' => 2, 'type' => 'add']]);

        // reversa vieja exacta: A 92+8=100, B 88+12=100, combo 4-4=0.
        // nueva aplicación 1A+1B * 2: A 100-2=98, B 100-2=98, combo 0+2=2.
        $this->assertSame(98.0, $this->loc($loc, 1));
        $this->assertSame(98.0, $this->loc($loc, 2));
        $this->assertSame(2.0, $this->loc($loc, 50));

        // el nuevo snapshot refleja la composición vigente.
        $newSnap = json_decode(DB::table('adjustments')->where('id', $adj->id)->value('inventory_effect_snapshot'), true);
        $byProduct = collect($newSnap)->keyBy('product_id');
        $this->assertSame(-2.0, (float) $byProduct[1]['delta']);
        $this->assertSame(-2.0, (float) $byProduct[2]['delta']);
        $this->assertSame(2.0, (float) $byProduct[50]['delta']);
    }

    /** B6 — Damage combo: cambiar composición tras crear; destroy revierte el efecto histórico. */
    public function test_b6_damage_combo_destroy_uses_history(): void
    {
        [$wh, $loc] = $this->warehouse();
        $this->product(1);
        $this->product(2);
        $this->combo(50, [1 => 2, 2 => 3]);
        $this->stock($loc, 1, 100);
        $this->stock($loc, 2, 100);
        $this->stock($loc, 50, 10);

        $dmg = $this->createDamage($wh, $loc, [['product_id' => 50, 'product_variant_id' => null, 'quantity' => 3]]);
        $this->assertSame(94.0, $this->loc($loc, 1));
        $this->assertSame(91.0, $this->loc($loc, 2));

        $this->setComboComposition(50, [1 => 9, 2 => 9]);
        $this->destroyDamage($dmg);

        $this->assertSame(100.0, $this->loc($loc, 1));  // +6 exacto, no +27
        $this->assertSame(100.0, $this->loc($loc, 2));  // +9 exacto
        $this->assertSame(10.0, $this->loc($loc, 50));
    }

    /** B7 — catálogo por ubicación: available cambia MAIN(80) -> BODEGA(5); nunca agregado de almacén. */
    public function test_b7_catalog_available_per_location(): void
    {
        [$wh, $main] = $this->warehouse();
        $bodega = $this->location($wh, 'BODEGA');
        $this->product(1);
        $this->stock($main, 1, 100, 20);   // available 80
        $this->stock($bodega, 1, 7, 2);    // available 5

        $catMain = app(LocationCatalogReadService::class)->forLocation($main);
        $rowMain = collect($catMain['products'])->firstWhere('product_id', 1);
        $this->assertSame(80.0, $rowMain['available_quantity']);
        $this->assertSame(100.0, $rowMain['physical_quantity']);
        $this->assertSame('inventory_location', $rowMain['stock_source']);

        $catBod = app(LocationCatalogReadService::class)->forLocation($bodega);
        $rowBod = collect($catBod['products'])->firstWhere('product_id', 1);
        $this->assertSame(5.0, $rowBod['available_quantity']);
    }

    /** B8 — producto con 0 stock: aparece en el catálogo y permite Adjustment ADD. */
    public function test_b8_zero_stock_product_in_catalog_and_addable(): void
    {
        [$wh, $loc] = $this->warehouse();
        $this->product(1);          // sin fila de stock en $loc

        $cat = app(LocationCatalogReadService::class)->forLocation($loc);
        $row = collect($cat['products'])->firstWhere('product_id', 1);
        $this->assertNotNull($row);
        $this->assertSame(0.0, $row['available_quantity']);

        $this->createAdjustment($wh, $loc, [['product_id' => 1, 'product_variant_id' => null, 'quantity' => 12, 'type' => 'add']]);
        $this->assertSame(12.0, $this->loc($loc, 1));
    }

    /** B9 — Damage usa available (physical - reserved), no physical. */
    public function test_b9_damage_uses_available_not_physical(): void
    {
        [$wh, $loc] = $this->warehouse();
        $this->product(1);
        $this->stock($loc, 1, 30, 25); // physical 30, available 5

        try {
            $this->createDamage($wh, $loc, [['product_id' => 1, 'product_variant_id' => null, 'quantity' => 6]]);
            $this->fail('dañar 6 con available 5 debía abortar');
        } catch (ValidationException $e) {
        }
        $this->assertSame(30.0, $this->loc($loc, 1));
        $this->createDamage($wh, $loc, [['product_id' => 1, 'product_variant_id' => null, 'quantity' => 5]]);
        $this->assertSame(25.0, $this->loc($loc, 1));
    }

    /** B13 — snapshot y movimientos producen exactamente los mismos deltas. */
    public function test_b13_snapshot_matches_movements(): void
    {
        [$wh, $loc] = $this->warehouse();
        $this->product(1);
        $this->product(2);
        $this->combo(50, [1 => 2, 2 => 3]);
        $this->stock($loc, 1, 100);
        $this->stock($loc, 2, 100);
        $this->stock($loc, 50, 5);

        $adj = $this->createAdjustment($wh, $loc, [
            ['product_id' => 50, 'product_variant_id' => null, 'quantity' => 4, 'type' => 'add'],
            ['product_id' => 1, 'product_variant_id' => null, 'quantity' => 3, 'type' => 'sub'],
        ]);

        $snap = json_decode(DB::table('adjustments')->where('id', $adj->id)->value('inventory_effect_snapshot'), true);
        $snapByKey = [];
        foreach ($snap as $e) {
            $k = $e['product_id'].':'.((int) ($e['variant_id'] ?? 0));
            $snapByKey[$k] = ($snapByKey[$k] ?? 0) + (float) $e['delta'];
        }
        $movByKey = [];
        foreach ($this->movements(LocationAwareStockDocumentService::REF_ADJUSTMENT) as $m) {
            $k = $m->product_id.':'.((int) ($m->product_variant_id ?: 0));
            $sign = $m->movement_type === 'increase' ? 1 : -1;
            $movByKey[$k] = ($movByKey[$k] ?? 0) + $sign * (float) $m->quantity;
        }
        ksort($snapByKey);
        ksort($movByKey);
        $this->assertSame($snapByKey, $movByKey);
    }

    /** B14 — provenance Iphone17 tras Adjustment SUB 10: legacy 150, native -10, RECONCILED, plan 0/0. */
    public function test_b14_provenance_iphone17(): void
    {
        [$wh, $loc] = $this->warehouse();
        $this->product(17);
        $this->seedReconciled($wh, $loc, 17, 150);
        $this->createAdjustment($wh, $loc, [['product_id' => 17, 'product_variant_id' => null, 'quantity' => 10, 'type' => 'sub']]);

        $prov = app(InventoryProvenanceAuditService::class)->auditWarehouse($wh);
        $key = collect($prov['keys'])->firstWhere('product_id', 17);
        $this->assertSame(150.0, (float) $key['baseline_quantity']);
        $this->assertSame(-10.0, (float) $key['post_baseline_native_net']);
        $this->assertSame(140.0, (float) $key['current_location']);
        $this->assertSame('RECONCILED', $key['classification']);
        $this->assertSame(150.0, $this->pw($wh, 17));
        $plan = app(LegacyInventoryReconciliationService::class)->planIncremental($wh);
        $this->assertSame(0, $plan['add_count']);
        $this->assertSame(0, $plan['manual_review_count']);
    }

    /** update location-aware sin snapshot => FAIL CLOSED (BLOCKER 2.3). */
    public function test_update_without_snapshot_fails_closed(): void
    {
        [$wh, $loc] = $this->warehouse();
        $this->product(1);
        $this->stock($loc, 1, 100);
        $adj = $this->createAdjustment($wh, $loc, [['product_id' => 1, 'product_variant_id' => null, 'quantity' => 5, 'type' => 'sub']]);
        DB::table('adjustments')->where('id', $adj->id)->update(['inventory_effect_snapshot' => null]);

        $this->expectException(ValidationException::class);
        $this->updateAdjustment($adj->fresh(), $wh, $loc, [['product_id' => 1, 'product_variant_id' => null, 'quantity' => 1, 'type' => 'sub']]);
    }
}
