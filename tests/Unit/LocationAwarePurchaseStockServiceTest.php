<?php

namespace Tests\Unit;

use App\Models\InventoryLocation;
use App\Models\InventoryLocationMovement;
use App\Models\InventoryLocationStock;
use App\Services\LocationAwarePurchaseStockService as Svc;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;

/**
 * MS1 — motor location-native de compra / devolución de compra.
 *
 * El servicio NO está conectado a ningún controller todavía. Estos tests
 * verifican el motor de forma aislada: validación + locks, conversión a unidad
 * base congelada en el snapshot, apply/reverse vía InventoryService, idempotencia
 * por revisión, artifact-safety y CERO escrituras a la tabla legacy por almacén.
 */
class LocationAwarePurchaseStockServiceTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        Schema::create('warehouses', function ($t) {
            $t->increments('id');
            $t->integer('branch_id')->nullable();
            $t->string('name')->nullable();
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

        Schema::create('units', function ($t) {
            $t->increments('id');
            $t->string('name')->nullable();
            $t->string('ShortName')->nullable();
            $t->string('operator')->nullable();
            $t->float('operator_value')->default(1);
            $t->integer('is_active')->default(1);
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
            $t->boolean('is_default_sales')->default(false);
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
            $t->timestamps();
            $t->unique(['inventory_location_id', 'product_id', 'variant_key'], 'ils_unique');
        });

        Schema::create('inventory_location_movements', function ($t) {
            $t->increments('id');
            $t->string('movement_type');
            $t->integer('product_id');
            $t->integer('product_variant_id')->nullable();
            $t->integer('from_inventory_location_id')->nullable();
            $t->integer('to_inventory_location_id')->nullable();
            $t->decimal('quantity', 12, 3);
            $t->integer('user_id')->nullable();
            $t->string('reference_type')->nullable();
            $t->string('reference_id')->nullable();
            $t->string('idempotency_key')->nullable()->unique();
            $t->string('idempotency_fingerprint', 64)->nullable();
            $t->string('notes')->nullable();
            $t->json('metadata')->nullable();
            $t->timestamps();
        });
    }

    // ---------------------------------------------------------------- helpers

    private function svc(): Svc
    {
        return app(Svc::class);
    }

    private function wh(): int
    {
        return (int) DB::table('warehouses')->insertGetId(['name' => 'CD', 'created_at' => now(), 'updated_at' => now()]);
    }

    private function loc(int $wh, array $o = []): int
    {
        return (int) DB::table('inventory_locations')->insertGetId(array_merge([
            'warehouse_id' => $wh,
            'code' => 'L'.\Illuminate\Support\Str::random(4),
            'name' => 'Loc',
            'type' => 'storage',
            'is_active' => true,
            'created_at' => now(),
            'updated_at' => now(),
        ], $o));
    }

    private function product(array $o = []): int
    {
        return (int) DB::table('products')->insertGetId(array_merge([
            'name' => 'P', 'code' => 'C'.\Illuminate\Support\Str::random(5),
            'type' => 'is_single', 'is_batch_tracked' => false, 'is_imei' => 0,
            'created_at' => now(), 'updated_at' => now(),
        ], $o));
    }

    private function variant(int $pid, array $o = []): int
    {
        return (int) DB::table('product_variants')->insertGetId(array_merge([
            'product_id' => $pid, 'name' => 'V', 'code' => 'V'.\Illuminate\Support\Str::random(4),
            'created_at' => now(), 'updated_at' => now(),
        ], $o));
    }

    private function unit(string $operator = '*', float $value = 1.0): int
    {
        return (int) DB::table('units')->insertGetId([
            'name' => $operator.$value, 'ShortName' => 'U',
            'operator' => $operator, 'operator_value' => $value, 'is_active' => 1,
            'created_at' => now(), 'updated_at' => now(),
        ]);
    }

    private function seedLocStock(int $locId, int $pid, float $qty, ?int $vid = null): void
    {
        DB::table('inventory_location_stocks')->insert([
            'inventory_location_id' => $locId, 'product_id' => $pid,
            'product_variant_id' => $vid, 'variant_key' => (int) ($vid ?: 0),
            'quantity' => $qty, 'reserved_quantity' => 0, 'manage_stock' => true,
            'created_at' => now(), 'updated_at' => now(),
        ]);
    }

    private function locQty(int $locId, int $pid, ?int $vid = null): float
    {
        return round((float) DB::table('inventory_location_stocks')
            ->where('inventory_location_id', $locId)->where('product_id', $pid)
            ->where('variant_key', (int) ($vid ?: 0))->value('quantity'), 3);
    }

    private function line(int $pid, int $unit, float $qty, ?int $vid = null, ?int $detailId = null): array
    {
        return [
            'product_id' => $pid,
            'product_variant_id' => $vid,
            'quantity' => $qty,
            'purchase_unit_id' => $unit,
            'source_detail_id' => $detailId,
        ];
    }

    /** run a callback inside a business transaction (the service requires it). */
    private function tx(callable $fn)
    {
        return DB::transaction($fn);
    }

    // =====================================================================
    // VALIDACIÓN
    // =====================================================================

    public function test_requires_a_db_transaction(): void
    {
        $wh = $this->wh();
        $loc = $this->loc($wh);
        $u = $this->unit();
        $p = $this->product();

        $this->assertSame(0, DB::transactionLevel());
        try {
            $this->svc()->validateAndLock(Svc::DOC_PURCHASE, $wh, $loc, [$this->line($p, $u, 1)]);
            $this->fail('debe exigir transacción');
        } catch (ValidationException $e) {
            $this->assertStringContainsString('dentro de la transacción', $e->getMessage());
        }
        $this->assertSame(0, InventoryLocationMovement::count());
    }

    public function test_rejects_missing_warehouse(): void
    {
        $u = $this->unit();
        $p = $this->product();
        $this->expectException(ValidationException::class);
        $this->tx(fn () => $this->svc()->validateAndLock(Svc::DOC_PURCHASE, 999, 1, [$this->line($p, $u, 1)]));
    }

    public function test_rejects_missing_location(): void
    {
        $wh = $this->wh();
        $u = $this->unit();
        $p = $this->product();
        $this->expectException(ValidationException::class);
        $this->tx(fn () => $this->svc()->validateAndLock(Svc::DOC_PURCHASE, $wh, 4242, [$this->line($p, $u, 1)]));
    }

    public function test_rejects_null_location(): void
    {
        $wh = $this->wh();
        $u = $this->unit();
        $p = $this->product();
        $this->expectException(ValidationException::class);
        $this->tx(fn () => $this->svc()->validateAndLock(Svc::DOC_PURCHASE, $wh, null, [$this->line($p, $u, 1)]));
    }

    public function test_rejects_inactive_location(): void
    {
        $wh = $this->wh();
        $loc = $this->loc($wh, ['is_active' => false]);
        $u = $this->unit();
        $p = $this->product();
        $this->expectException(ValidationException::class);
        $this->tx(fn () => $this->svc()->validateAndLock(Svc::DOC_PURCHASE, $wh, $loc, [$this->line($p, $u, 1)]));
    }

    public function test_rejects_location_of_another_warehouse(): void
    {
        $wh1 = $this->wh();
        $wh2 = $this->wh();
        $loc2 = $this->loc($wh2);
        $u = $this->unit();
        $p = $this->product();
        try {
            $this->tx(fn () => $this->svc()->validateAndLock(Svc::DOC_PURCHASE, $wh1, $loc2, [$this->line($p, $u, 1)]));
            $this->fail('debe rechazar ubicación de otro almacén');
        } catch (ValidationException $e) {
            $this->assertStringContainsString('no pertenece al almacén', $e->getMessage());
        }
    }

    public function test_rejects_empty_details(): void
    {
        $wh = $this->wh();
        $loc = $this->loc($wh);
        $this->expectException(ValidationException::class);
        $this->tx(fn () => $this->svc()->validateAndLock(Svc::DOC_PURCHASE, $wh, $loc, []));
    }

    public function test_rejects_non_positive_quantity(): void
    {
        $wh = $this->wh();
        $loc = $this->loc($wh);
        $u = $this->unit();
        $p = $this->product();
        $this->expectException(ValidationException::class);
        $this->tx(fn () => $this->svc()->validateAndLock(Svc::DOC_PURCHASE, $wh, $loc, [$this->line($p, $u, 0)]));
    }

    public function test_rejects_deleted_product(): void
    {
        $wh = $this->wh();
        $loc = $this->loc($wh);
        $u = $this->unit();
        $p = $this->product(['deleted_at' => now()]);
        $this->expectException(ValidationException::class);
        $this->tx(fn () => $this->svc()->validateAndLock(Svc::DOC_PURCHASE, $wh, $loc, [$this->line($p, $u, 1)]));
    }

    public function test_requires_variant_for_is_variant_product(): void
    {
        $wh = $this->wh();
        $loc = $this->loc($wh);
        $u = $this->unit();
        $p = $this->product(['type' => 'is_variant']);
        try {
            $this->tx(fn () => $this->svc()->validateAndLock(Svc::DOC_PURCHASE, $wh, $loc, [$this->line($p, $u, 1)]));
            $this->fail('is_variant requiere product_variant_id');
        } catch (ValidationException $e) {
            $this->assertStringContainsString('falta product_variant_id', $e->getMessage());
        }
    }

    public function test_rejects_variant_of_another_product(): void
    {
        $wh = $this->wh();
        $loc = $this->loc($wh);
        $u = $this->unit();
        $p = $this->product(['type' => 'is_variant']);
        $other = $this->product(['type' => 'is_variant']);
        $vOther = $this->variant($other);
        $this->expectException(ValidationException::class);
        $this->tx(fn () => $this->svc()->validateAndLock(Svc::DOC_PURCHASE, $wh, $loc, [$this->line($p, $u, 1, $vOther)]));
    }

    public function test_rejects_variant_sent_for_is_single(): void
    {
        $wh = $this->wh();
        $loc = $this->loc($wh);
        $u = $this->unit();
        $p = $this->product(['type' => 'is_single']);
        $bogusVariant = $this->variant($p);
        try {
            $this->tx(fn () => $this->svc()->validateAndLock(Svc::DOC_PURCHASE, $wh, $loc, [$this->line($p, $u, 1, $bogusVariant)]));
            $this->fail('is_single no admite variante');
        } catch (ValidationException $e) {
            $this->assertStringContainsString('no admite variante', $e->getMessage());
        }
    }

    public function test_rejects_missing_purchase_unit(): void
    {
        $wh = $this->wh();
        $loc = $this->loc($wh);
        $p = $this->product();
        $this->expectException(ValidationException::class);
        $this->tx(fn () => $this->svc()->validateAndLock(Svc::DOC_PURCHASE, $wh, $loc, [$this->line($p, 777, 1)]));
    }

    public function test_batch_tracked_line_fails_closed(): void
    {
        $wh = $this->wh();
        $loc = $this->loc($wh);
        $u = $this->unit();
        $p = $this->product(['is_batch_tracked' => true]);
        try {
            $this->tx(fn () => $this->svc()->validateAndLock(Svc::DOC_PURCHASE, $wh, $loc, [$this->line($p, $u, 1)]));
            $this->fail('batch => fail closed');
        } catch (ValidationException $e) {
            $this->assertStringContainsString('artifact-aware', $e->getMessage());
        }
        $this->assertSame(0, InventoryLocationMovement::count());
    }

    public function test_imei_tracked_line_fails_closed(): void
    {
        $wh = $this->wh();
        $loc = $this->loc($wh);
        $u = $this->unit();
        $p = $this->product(['is_imei' => 1]);
        try {
            $this->tx(fn () => $this->svc()->validateAndLock(Svc::DOC_PURCHASE, $wh, $loc, [$this->line($p, $u, 1)]));
            $this->fail('IMEI => fail closed');
        } catch (ValidationException $e) {
            $this->assertStringContainsString('artifact-aware', $e->getMessage());
        }
    }

    // =====================================================================
    // UNIDADES — qty_base congelada
    // =====================================================================

    public function test_multiply_operator_freezes_quantity_base(): void
    {
        $wh = $this->wh();
        $loc = $this->loc($wh);
        $u = $this->unit('*', 12);
        $p = $this->product();

        $snap = $this->tx(function () use ($wh, $loc, $u, $p) {
            $v = $this->svc()->validateAndLock(Svc::DOC_PURCHASE, $wh, $loc, [$this->line($p, $u, 2)]);

            return $this->svc()->buildSnapshot($v);
        });

        $this->assertSame(24.0, (float) $snap['effects'][0]['quantity_base']);
        $this->assertSame(24.0, (float) $snap['effects'][0]['delta']);
    }

    public function test_divide_operator_freezes_quantity_base(): void
    {
        $wh = $this->wh();
        $loc = $this->loc($wh);
        $u = $this->unit('/', 6);
        $p = $this->product();

        $snap = $this->tx(function () use ($wh, $loc, $u, $p) {
            $v = $this->svc()->validateAndLock(Svc::DOC_PURCHASE, $wh, $loc, [$this->line($p, $u, 12)]);

            return $this->svc()->buildSnapshot($v);
        });

        $this->assertSame(2.0, (float) $snap['effects'][0]['quantity_base']);
    }

    public function test_rejects_invalid_operator_value(): void
    {
        $wh = $this->wh();
        $loc = $this->loc($wh);
        $u = $this->unit('*', 0);
        $p = $this->product();
        try {
            $this->tx(fn () => $this->svc()->validateAndLock(Svc::DOC_PURCHASE, $wh, $loc, [$this->line($p, $u, 1)]));
            $this->fail('operator_value <= 0 => rechazo');
        } catch (ValidationException $e) {
            $this->assertStringContainsString('factor de conversión inválido', $e->getMessage());
        }
    }

    public function test_changing_the_unit_after_build_does_not_alter_the_snapshot(): void
    {
        $wh = $this->wh();
        $loc = $this->loc($wh);
        $u = $this->unit('*', 12);
        $p = $this->product();

        $snap = $this->tx(function () use ($wh, $loc, $u, $p) {
            $v = $this->svc()->validateAndLock(Svc::DOC_PURCHASE, $wh, $loc, [$this->line($p, $u, 2)]);

            return $this->svc()->buildSnapshot($v);
        });
        $this->assertSame(24.0, (float) $snap['effects'][0]['quantity_base']);

        // Someone edits the unit factor afterwards.
        DB::table('units')->where('id', $u)->update(['operator_value' => 1, 'operator' => '*']);

        // Re-applying the SAME persisted snapshot must still move 24, not 2.
        $this->tx(fn () => $this->svc()->applySnapshot($snap, 4242));
        $this->assertSame(24.0, $this->locQty($loc, $p));
    }

    // =====================================================================
    // PURCHASE  (delta > 0)
    // =====================================================================

    public function test_purchase_snapshot_has_positive_delta_and_apply_increases(): void
    {
        $wh = $this->wh();
        $loc = $this->loc($wh);
        $u = $this->unit();
        $p = $this->product();
        $this->seedLocStock($loc, $p, 5);

        $this->tx(function () use ($wh, $loc, $u, $p) {
            $v = $this->svc()->validateAndLock(Svc::DOC_PURCHASE, $wh, $loc, [$this->line($p, $u, 10, null, 77)]);
            $snap = $this->svc()->buildSnapshot($v);
            $this->assertSame('purchase', $snap['document_type']);
            $this->assertSame(10.0, (float) $snap['effects'][0]['delta']);
            $this->svc()->applySnapshot($snap, 100);
        });

        $this->assertSame(15.0, $this->locQty($loc, $p));

        $m = InventoryLocationMovement::where('reference_type', Svc::REF_PURCHASE)->firstOrFail();
        $this->assertSame('increase', $m->movement_type);
        $this->assertSame('100', (string) $m->reference_id);
        $this->assertSame(10.0, (float) $m->quantity);
        $this->assertSame('purchase:100:rev:1:effect:0:apply', $m->idempotency_key);
    }

    public function test_purchase_reverse_decreases_exactly_and_tags_reversal(): void
    {
        $wh = $this->wh();
        $loc = $this->loc($wh);
        $u = $this->unit();
        $p = $this->product();
        $this->seedLocStock($loc, $p, 0);

        $snap = $this->tx(function () use ($wh, $loc, $u, $p) {
            $v = $this->svc()->validateAndLock(Svc::DOC_PURCHASE, $wh, $loc, [$this->line($p, $u, 8)]);
            $s = $this->svc()->buildSnapshot($v);
            $this->svc()->applySnapshot($s, 200);

            return $s;
        });
        $this->assertSame(8.0, $this->locQty($loc, $p));

        $this->tx(fn () => $this->svc()->reverseSnapshot($snap, 200));
        $this->assertSame(0.0, $this->locQty($loc, $p));

        $r = InventoryLocationMovement::where('reference_type', Svc::REF_PURCHASE_REVERSAL)->firstOrFail();
        $this->assertSame('decrease', $r->movement_type);
        $this->assertSame('purchase:200:rev:1:effect:0:reverse', $r->idempotency_key);
    }

    /**
     * BLOCKER — reverse must be an exact -delta on the row, never a reset.
     * Starting from PREEXISTING stock so "decrease by 10" and "reset to 0" are
     * distinguishable (0->10->0 would not catch a reset).
     */
    public function test_purchase_apply_then_reverse_from_preexisting_stock(): void
    {
        $wh = $this->wh();
        $loc = $this->loc($wh);
        $u = $this->unit();
        $p = $this->product();
        $this->seedLocStock($loc, $p, 5); // preexisting

        $snap = $this->tx(function () use ($wh, $loc, $u, $p) {
            $v = $this->svc()->validateAndLock(Svc::DOC_PURCHASE, $wh, $loc, [$this->line($p, $u, 10)]);
            $s = $this->svc()->buildSnapshot($v);
            $this->assertSame(10.0, (float) $s['effects'][0]['delta']);
            $this->svc()->applySnapshot($s, 111);

            return $s;
        });
        $this->assertSame(15.0, $this->locQty($loc, $p)); // 5 + 10

        $this->tx(fn () => $this->svc()->reverseSnapshot($snap, 111));
        $this->assertSame(5.0, $this->locQty($loc, $p)); // 15 - 10 -> back to 5, NOT 0

        $r = InventoryLocationMovement::where('reference_type', Svc::REF_PURCHASE_REVERSAL)->firstOrFail();
        $this->assertSame('decrease', $r->movement_type);
        $this->assertSame(10.0, (float) $r->quantity); // exact -delta, not the running total
    }

    public function test_purchase_variant_line_moves_variant_scoped_stock(): void
    {
        $wh = $this->wh();
        $loc = $this->loc($wh);
        $u = $this->unit();
        $p = $this->product(['type' => 'is_variant']);
        $v = $this->variant($p);
        $this->seedLocStock($loc, $p, 3);
        $this->seedLocStock($loc, $p, 1, $v);

        $this->tx(function () use ($wh, $loc, $u, $p, $v) {
            $val = $this->svc()->validateAndLock(Svc::DOC_PURCHASE, $wh, $loc, [$this->line($p, $u, 4, $v)]);
            $this->svc()->applySnapshot($this->svc()->buildSnapshot($val), 300);
        });

        $this->assertSame(5.0, $this->locQty($loc, $p, $v));
        $this->assertSame(3.0, $this->locQty($loc, $p)); // base row untouched
    }

    // =====================================================================
    // PURCHASE RETURN  (delta < 0)
    // =====================================================================

    public function test_return_snapshot_has_negative_delta_and_apply_decreases(): void
    {
        $wh = $this->wh();
        $loc = $this->loc($wh);
        $u = $this->unit();
        $p = $this->product();
        $this->seedLocStock($loc, $p, 20);

        $this->tx(function () use ($wh, $loc, $u, $p) {
            $v = $this->svc()->validateAndLock(Svc::DOC_PURCHASE_RETURN, $wh, $loc, [$this->line($p, $u, 5)]);
            $snap = $this->svc()->buildSnapshot($v);
            $this->assertSame('purchase_return', $snap['document_type']);
            $this->assertSame(-5.0, (float) $snap['effects'][0]['delta']);
            $this->assertSame(5.0, (float) $snap['effects'][0]['quantity_base']);
            $this->svc()->applySnapshot($snap, 400);
        });

        $this->assertSame(15.0, $this->locQty($loc, $p));
        $m = InventoryLocationMovement::where('reference_type', Svc::REF_PURCHASE_RETURN)->firstOrFail();
        $this->assertSame('decrease', $m->movement_type);
        $this->assertSame('purchase_return:400:rev:1:effect:0:apply', $m->idempotency_key);
    }

    public function test_return_apply_fails_when_stock_is_insufficient_no_clamp(): void
    {
        $wh = $this->wh();
        $loc = $this->loc($wh);
        $u = $this->unit();
        $p = $this->product();
        $this->seedLocStock($loc, $p, 3);

        try {
            $this->tx(function () use ($wh, $loc, $u, $p) {
                $v = $this->svc()->validateAndLock(Svc::DOC_PURCHASE_RETURN, $wh, $loc, [$this->line($p, $u, 5)]);
                $this->svc()->applySnapshot($this->svc()->buildSnapshot($v), 401);
            });
            $this->fail('debe fallar por stock insuficiente, sin clamp');
        } catch (ValidationException $e) {
            // expected — InventoryService::decrease rechaza
        }

        $this->assertSame(3.0, $this->locQty($loc, $p)); // untouched, not clamped to 0
    }

    public function test_return_reverse_increases_exactly_and_tags_reversal(): void
    {
        $wh = $this->wh();
        $loc = $this->loc($wh);
        $u = $this->unit();
        $p = $this->product();
        $this->seedLocStock($loc, $p, 20);

        $snap = $this->tx(function () use ($wh, $loc, $u, $p) {
            $v = $this->svc()->validateAndLock(Svc::DOC_PURCHASE_RETURN, $wh, $loc, [$this->line($p, $u, 5)]);
            $s = $this->svc()->buildSnapshot($v);
            $this->svc()->applySnapshot($s, 402);

            return $s;
        });
        $this->assertSame(15.0, $this->locQty($loc, $p));

        $this->tx(fn () => $this->svc()->reverseSnapshot($snap, 402));
        $this->assertSame(20.0, $this->locQty($loc, $p));

        $r = InventoryLocationMovement::where('reference_type', Svc::REF_PURCHASE_RETURN_REVERSAL)->firstOrFail();
        $this->assertSame('increase', $r->movement_type);
        $this->assertSame('purchase_return:402:rev:1:effect:0:reverse', $r->idempotency_key);
    }

    // =====================================================================
    // IDEMPOTENCIA
    // =====================================================================

    public function test_same_revision_and_operation_yield_the_same_key(): void
    {
        $svc = $this->svc();
        $k1 = $svc->idempotencyKey('purchase', 7, 1, 0, 'apply');
        $k2 = $svc->idempotencyKey('purchase', 7, 1, 0, 'apply');
        $this->assertSame('purchase:7:rev:1:effect:0:apply', $k1);
        $this->assertSame($k1, $k2);
        $this->assertNotSame($k1, $svc->idempotencyKey('purchase', 7, 2, 0, 'apply'));
        $this->assertNotSame($k1, $svc->idempotencyKey('purchase', 7, 1, 0, 'reverse'));
    }

    public function test_revision_2_snapshot_produces_new_keys_vs_revision_1(): void
    {
        $wh = $this->wh();
        $loc = $this->loc($wh);
        $u = $this->unit();
        $p = $this->product();
        $this->seedLocStock($loc, $p, 0);

        $this->tx(function () use ($wh, $loc, $u, $p) {
            $v = $this->svc()->validateAndLock(Svc::DOC_PURCHASE, $wh, $loc, [$this->line($p, $u, 4)]);
            $rev1 = $this->svc()->buildSnapshot($v, 1);
            $rev2 = $this->svc()->buildSnapshot($v, 2);
            $this->svc()->applySnapshot($rev1, 500);
            $this->svc()->applySnapshot($rev2, 500);
        });

        // rev1 + rev2 are DIFFERENT movements (edition, not a duplicate).
        $keys = InventoryLocationMovement::where('reference_id', '500')->pluck('idempotency_key')->sort()->values()->all();
        $this->assertSame([
            'purchase:500:rev:1:effect:0:apply',
            'purchase:500:rev:2:effect:0:apply',
        ], $keys);
        $this->assertSame(8.0, $this->locQty($loc, $p)); // 4 + 4
    }

    public function test_repeating_apply_of_the_same_revision_does_not_duplicate(): void
    {
        $wh = $this->wh();
        $loc = $this->loc($wh);
        $u = $this->unit();
        $p = $this->product();
        $this->seedLocStock($loc, $p, 0);

        $snap = $this->tx(function () use ($wh, $loc, $u, $p) {
            $v = $this->svc()->validateAndLock(Svc::DOC_PURCHASE, $wh, $loc, [$this->line($p, $u, 6)]);
            $s = $this->svc()->buildSnapshot($v);
            $this->svc()->applySnapshot($s, 600);

            return $s;
        });
        $this->assertSame(6.0, $this->locQty($loc, $p));

        $this->tx(fn () => $this->svc()->applySnapshot($snap, 600)); // replay
        $this->assertSame(6.0, $this->locQty($loc, $p)); // not 12
        $this->assertSame(1, InventoryLocationMovement::where('idempotency_key', 'purchase:600:rev:1:effect:0:apply')->count());
    }

    /**
     * BLOCKER §4 — idempotency measured from PREEXISTING stock. Replayed apply
     * / reverse of the SAME revision must be a no-op on the row.
     */
    public function test_idempotency_from_preexisting_stock_purchase(): void
    {
        $wh = $this->wh();
        $loc = $this->loc($wh);
        $u = $this->unit();
        $p = $this->product();
        $this->seedLocStock($loc, $p, 5); // preexisting

        $snap = $this->tx(function () use ($wh, $loc, $u, $p) {
            $v = $this->svc()->validateAndLock(Svc::DOC_PURCHASE, $wh, $loc, [$this->line($p, $u, 10)]);

            return $this->svc()->buildSnapshot($v, 1);
        });

        $this->tx(fn () => $this->svc()->applySnapshot($snap, 900));
        $this->assertSame(15.0, $this->locQty($loc, $p)); // 5 -> 15

        $this->tx(fn () => $this->svc()->applySnapshot($snap, 900)); // replay
        $this->assertSame(15.0, $this->locQty($loc, $p)); // still 15

        $this->tx(fn () => $this->svc()->reverseSnapshot($snap, 900));
        $this->assertSame(5.0, $this->locQty($loc, $p)); // 15 -> 5

        $this->tx(fn () => $this->svc()->reverseSnapshot($snap, 900)); // replay
        $this->assertSame(5.0, $this->locQty($loc, $p)); // still 5

        $this->assertSame(1, InventoryLocationMovement::where('idempotency_key', 'purchase:900:rev:1:effect:0:apply')->count());
        $this->assertSame(1, InventoryLocationMovement::where('idempotency_key', 'purchase:900:rev:1:effect:0:reverse')->count());
    }

    public function test_idempotency_from_preexisting_stock_purchase_return(): void
    {
        $wh = $this->wh();
        $loc = $this->loc($wh);
        $u = $this->unit();
        $p = $this->product();
        $this->seedLocStock($loc, $p, 20); // preexisting

        $snap = $this->tx(function () use ($wh, $loc, $u, $p) {
            $v = $this->svc()->validateAndLock(Svc::DOC_PURCHASE_RETURN, $wh, $loc, [$this->line($p, $u, 5)]);

            return $this->svc()->buildSnapshot($v, 1);
        });

        $this->tx(fn () => $this->svc()->applySnapshot($snap, 901));
        $this->assertSame(15.0, $this->locQty($loc, $p)); // 20 -> 15

        $this->tx(fn () => $this->svc()->applySnapshot($snap, 901)); // replay
        $this->assertSame(15.0, $this->locQty($loc, $p)); // still 15

        $this->tx(fn () => $this->svc()->reverseSnapshot($snap, 901));
        $this->assertSame(20.0, $this->locQty($loc, $p)); // 15 -> 20, NOT 0

        $this->tx(fn () => $this->svc()->reverseSnapshot($snap, 901)); // replay
        $this->assertSame(20.0, $this->locQty($loc, $p)); // still 20
    }

    public function test_repeating_reverse_of_the_same_revision_does_not_duplicate(): void
    {
        $wh = $this->wh();
        $loc = $this->loc($wh);
        $u = $this->unit();
        $p = $this->product();
        $this->seedLocStock($loc, $p, 0);

        $snap = $this->tx(function () use ($wh, $loc, $u, $p) {
            $v = $this->svc()->validateAndLock(Svc::DOC_PURCHASE, $wh, $loc, [$this->line($p, $u, 6)]);
            $s = $this->svc()->buildSnapshot($v);
            $this->svc()->applySnapshot($s, 601);
            $this->svc()->reverseSnapshot($s, 601);

            return $s;
        });
        $this->assertSame(0.0, $this->locQty($loc, $p));

        $this->tx(fn () => $this->svc()->reverseSnapshot($snap, 601)); // replay
        $this->assertSame(0.0, $this->locQty($loc, $p)); // not -6
        $this->assertSame(1, InventoryLocationMovement::where('idempotency_key', 'purchase:601:rev:1:effect:0:reverse')->count());
    }

    // =====================================================================
    // SNAPSHOT — normalize + artifact safety
    // =====================================================================

    public function test_normalize_rejects_wrong_version(): void
    {
        $this->expectException(ValidationException::class);
        $this->svc()->normalizeSnapshot([
            'version' => 99, 'revision' => 1, 'document_type' => 'purchase',
            'warehouse_id' => 1, 'inventory_location_id' => 1,
            'effects' => [['product_id' => 1, 'quantity_base' => 1, 'delta' => 1]],
        ]);
    }

    public function test_normalize_rejects_bad_revision(): void
    {
        $this->expectException(ValidationException::class);
        $this->svc()->normalizeSnapshot([
            'version' => 1, 'revision' => 0, 'document_type' => 'purchase',
            'warehouse_id' => 1, 'inventory_location_id' => 1,
            'effects' => [['product_id' => 1, 'quantity_base' => 1, 'delta' => 1]],
        ]);
    }

    public function test_normalize_rejects_malformed_snapshot(): void
    {
        $this->expectException(ValidationException::class);
        $this->svc()->normalizeSnapshot('not-json-and-not-array');
    }

    public function test_normalize_rejects_delta_sign_incompatible_with_document_type(): void
    {
        $this->expectException(ValidationException::class);
        $this->svc()->normalizeSnapshot([
            'version' => 1, 'revision' => 1, 'document_type' => 'purchase',
            'warehouse_id' => 1, 'inventory_location_id' => 1,
            'effects' => [['product_id' => 1, 'quantity_base' => 1, 'delta' => -1]], // purchase must be +
        ]);
    }

    public function test_artifact_safe_detects_product_that_became_batch_tracked(): void
    {
        $wh = $this->wh();
        $loc = $this->loc($wh);
        $u = $this->unit();
        $p = $this->product();
        $this->seedLocStock($loc, $p, 0);

        $snap = $this->tx(function () use ($wh, $loc, $u, $p) {
            $v = $this->svc()->validateAndLock(Svc::DOC_PURCHASE, $wh, $loc, [$this->line($p, $u, 3)]);
            $s = $this->svc()->buildSnapshot($v);
            $this->svc()->applySnapshot($s, 700);

            return $s;
        });

        DB::table('products')->where('id', $p)->update(['is_batch_tracked' => true]);

        try {
            $this->tx(fn () => $this->svc()->assertSnapshotArtifactSafeAndLock($snap));
            $this->fail('debe fail closed si el producto ahora es batch');
        } catch (ValidationException $e) {
            $this->assertStringContainsString('artifact-aware', $e->getMessage());
        }
    }

    public function test_artifact_safe_detects_product_that_became_imei_tracked(): void
    {
        $wh = $this->wh();
        $loc = $this->loc($wh);
        $u = $this->unit();
        $p = $this->product();
        $this->seedLocStock($loc, $p, 0);

        $snap = $this->tx(function () use ($wh, $loc, $u, $p) {
            $v = $this->svc()->validateAndLock(Svc::DOC_PURCHASE, $wh, $loc, [$this->line($p, $u, 3)]);
            $s = $this->svc()->buildSnapshot($v);
            $this->svc()->applySnapshot($s, 701);

            return $s;
        });

        DB::table('products')->where('id', $p)->update(['is_imei' => 1]);

        $this->expectException(ValidationException::class);
        $this->tx(fn () => $this->svc()->assertSnapshotArtifactSafeAndLock($snap));
    }

    public function test_artifact_safe_detects_hard_missing_product(): void
    {
        $wh = $this->wh();
        $loc = $this->loc($wh);
        $u = $this->unit();
        $p = $this->product();
        $this->seedLocStock($loc, $p, 0);

        $snap = $this->tx(function () use ($wh, $loc, $u, $p) {
            $v = $this->svc()->validateAndLock(Svc::DOC_PURCHASE, $wh, $loc, [$this->line($p, $u, 3)]);

            return $this->svc()->buildSnapshot($v);
        });

        DB::table('products')->where('id', $p)->delete(); // hard delete

        try {
            $this->tx(fn () => $this->svc()->assertSnapshotArtifactSafeAndLock($snap));
            $this->fail('debe fail closed si el producto ya no existe');
        } catch (ValidationException $e) {
            $this->assertStringContainsString('ya no existen', $e->getMessage());
        }
    }

    // =====================================================================
    // NO LEGACY WRITES
    // =====================================================================

    public function test_apply_and_reverse_never_touch_the_legacy_per_warehouse_table(): void
    {
        $wh = $this->wh();
        $loc = $this->loc($wh);
        $u = $this->unit('*', 3);
        $p = $this->product();
        $this->seedLocStock($loc, $p, 10);

        // Seed a legacy row and freeze its exact state.
        DB::table('product_warehouse')->insert([
            'product_id' => $p, 'warehouse_id' => $wh, 'product_variant_id' => null,
            'qte' => 42.5, 'manage_stock' => 1,
            'created_at' => '2020-01-01 00:00:00', 'updated_at' => '2020-01-01 00:00:00',
        ]);
        $before = DB::table('product_warehouse')->orderBy('id')->get()->toArray();

        $snap = $this->tx(function () use ($wh, $loc, $u, $p) {
            $v = $this->svc()->validateAndLock(Svc::DOC_PURCHASE, $wh, $loc, [$this->line($p, $u, 4)]);
            $s = $this->svc()->buildSnapshot($v);
            $this->svc()->applySnapshot($s, 800);

            return $s;
        });
        $this->tx(fn () => $this->svc()->reverseSnapshot($snap, 800));

        $after = DB::table('product_warehouse')->orderBy('id')->get()->toArray();
        $this->assertEquals($before, $after, 'the legacy per-warehouse table must be byte-for-byte unchanged');

        // location engine did move (and net to zero after reverse).
        $this->assertSame(10.0, $this->locQty($loc, $p));
        $this->assertGreaterThan(0, InventoryLocationMovement::count());
    }
}
