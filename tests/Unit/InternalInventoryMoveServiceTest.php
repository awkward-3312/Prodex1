<?php

namespace Tests\Unit;

use App\Models\Branch;
use App\Models\InventoryLocation;
use App\Services\InternalInventoryMoveService;
use App\Services\InventoryService;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

class InternalInventoryMoveServiceTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        Schema::create('branches', function ($table) {
            $table->increments('id');
            $table->string('name');
            $table->boolean('is_active')->default(true);
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
        Schema::create('products', function ($table) {
            $table->increments('id');
            $table->string('name');
            $table->string('type')->default('is_single');
            $table->boolean('is_batch_tracked')->default(false);
            $table->boolean('is_imei')->default(false);
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
            $table->unique(['inventory_location_id', 'product_id', 'variant_key'], 'internal_move_stock_unique');
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
    }

    public function test_simple_product_move_changes_only_location_distribution(): void
    {
        $branch = Branch::create(['name' => 'Sucursal Mall', 'is_active' => true]);
        $storage = InventoryLocation::create([
            'branch_id' => $branch->id,
            'code' => 'BODEGA',
            'name' => 'Bodega interna',
            'type' => InventoryLocation::TYPE_STORAGE,
            'is_active' => true,
        ]);
        $floor = InventoryLocation::create([
            'branch_id' => $branch->id,
            'code' => 'PISO',
            'name' => 'Piso de venta',
            'type' => InventoryLocation::TYPE_SALES_FLOOR,
            'is_sellable' => true,
            'is_active' => true,
        ]);
        $productId = DB::table('products')->insertGetId([
            'name' => 'Producto',
            'type' => 'is_single',
            'is_batch_tracked' => false,
            'is_imei' => false,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $inventory = app(InventoryService::class);
        $inventory->increase($storage->id, $productId, 25);

        $result = app(InternalInventoryMoveService::class)->move(
            $storage->id,
            $floor->id,
            $productId,
            8,
            null,
            ['idempotency_key' => 'replenishment:1']
        );

        $this->assertSame(17.0, $inventory->quantity($storage->id, $productId));
        $this->assertSame(8.0, $inventory->quantity($floor->id, $productId));
        $this->assertSame($storage->id, (int) $result['movement']->from_inventory_location_id);
        $this->assertSame($floor->id, (int) $result['movement']->to_inventory_location_id);
    }
}
