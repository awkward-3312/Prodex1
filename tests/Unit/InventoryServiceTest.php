<?php

namespace Tests\Unit;

use App\Models\Branch;
use App\Models\InventoryLocation;
use App\Models\InventoryLocationMovement;
use App\Services\InventoryService;
use Illuminate\Support\Facades\Schema;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;

class InventoryServiceTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        Schema::create('branches', function ($table) {
            $table->increments('id');
            $table->string('name');
            $table->boolean('is_active')->default(true);
            $table->integer('default_inventory_location_id')->nullable();
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
            $table->unique(['inventory_location_id', 'product_id', 'variant_key'], 'ils_unique');
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
            $table->string('notes')->nullable();
            $table->json('metadata')->nullable();
            $table->timestamps();
        });
    }

    public function test_increase_and_decrease_track_physical_stock(): void
    {
        $location = $this->location('PISO');
        $service = app(InventoryService::class);

        $service->increase($location->id, 10, 12.5);
        $this->assertSame(12.5, $service->quantity($location->id, 10));

        $service->decrease($location->id, 10, 2.25);
        $this->assertSame(10.25, $service->quantity($location->id, 10));
        $this->assertSame(10.25, $service->available($location->id, 10));
    }

    public function test_reserved_stock_is_not_available_for_normal_decrease(): void
    {
        $location = $this->location('PISO');
        $service = app(InventoryService::class);

        $service->increase($location->id, 10, 10);
        $service->reserve($location->id, 10, 7);

        $this->assertSame(10.0, $service->quantity($location->id, 10));
        $this->assertSame(7.0, $service->reserved($location->id, 10));
        $this->assertSame(3.0, $service->available($location->id, 10));

        $this->expectException(ValidationException::class);
        $service->decrease($location->id, 10, 4);
    }

    public function test_reserved_stock_can_be_consumed_atomically(): void
    {
        $location = $this->location('PISO');
        $service = app(InventoryService::class);

        $service->increase($location->id, 10, 10);
        $service->reserve($location->id, 10, 4);
        $service->consumeReserved($location->id, 10, 3);

        $this->assertSame(7.0, $service->quantity($location->id, 10));
        $this->assertSame(1.0, $service->reserved($location->id, 10));
        $this->assertSame(6.0, $service->available($location->id, 10));
    }

    public function test_move_is_atomic_and_creates_one_ledger_entry(): void
    {
        $from = $this->location('BODEGA');
        $to = $this->location('PISO');
        $service = app(InventoryService::class);

        $service->increase($from->id, 20, 25);
        $movement = $service->move($from->id, $to->id, 20, 8, null, [
            'reference_type' => 'internal_move',
            'reference_id' => 'MOVE-001',
        ]);

        $this->assertSame(17.0, $service->quantity($from->id, 20));
        $this->assertSame(8.0, $service->quantity($to->id, 20));
        $this->assertSame(InventoryService::MOVEMENT_TRANSFER, $movement->movement_type);
        $this->assertSame($from->id, $movement->from_inventory_location_id);
        $this->assertSame($to->id, $movement->to_inventory_location_id);
    }

    public function test_idempotency_key_prevents_duplicate_stock_mutation(): void
    {
        $location = $this->location('PISO');
        $service = app(InventoryService::class);
        $context = ['idempotency_key' => 'sale:100:line:1'];

        $first = $service->increase($location->id, 30, 5, null, $context);
        $second = $service->increase($location->id, 30, 5, null, $context);

        $this->assertSame($first->id, $second->id);
        $this->assertSame(5.0, $service->quantity($location->id, 30));
        $this->assertSame(1, InventoryLocationMovement::where('idempotency_key', 'sale:100:line:1')->count());
    }

    public function test_variants_have_independent_stock_rows(): void
    {
        $location = $this->location('PISO');
        $service = app(InventoryService::class);

        $service->increase($location->id, 50, 4, 501);
        $service->increase($location->id, 50, 9, 502);

        $this->assertSame(4.0, $service->quantity($location->id, 50, 501));
        $this->assertSame(9.0, $service->quantity($location->id, 50, 502));
        $this->assertSame(0.0, $service->quantity($location->id, 50));
    }

    public function test_adjustment_cannot_drop_below_reserved_quantity(): void
    {
        $location = $this->location('PISO');
        $service = app(InventoryService::class);

        $service->increase($location->id, 60, 10);
        $service->reserve($location->id, 60, 6);

        $this->expectException(ValidationException::class);
        $service->adjustTo($location->id, 60, 5);
    }

    private function location(string $code): InventoryLocation
    {
        $branch = Branch::first() ?: Branch::create(['name' => 'Sucursal Prueba', 'is_active' => true]);

        return InventoryLocation::create([
            'branch_id' => $branch->id,
            'warehouse_id' => null,
            'code' => $code,
            'name' => $code,
            'type' => InventoryLocation::TYPE_STORAGE,
            'is_active' => true,
        ]);
    }
}
