<?php

namespace Tests\Unit;

use App\Models\Branch;
use App\Models\InventoryLocation;
use App\Models\ProductSerial;
use App\Models\ProductSerialMovement;
use App\Services\SerialLocationService;
use Illuminate\Support\Facades\Schema;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;

class SerialLocationServiceTest extends TestCase
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

        Schema::create('product_serials', function ($table) {
            $table->increments('id');
            $table->string('serial_number')->unique();
            $table->integer('product_id');
            $table->integer('product_variant_id')->nullable();
            $table->integer('warehouse_id')->nullable();
            $table->integer('inventory_location_id')->nullable();
            $table->string('status');
            $table->integer('purchase_id')->nullable();
            $table->integer('purchase_detail_id')->nullable();
            $table->integer('provider_id')->nullable();
            $table->decimal('cost', 12, 3)->nullable();
            $table->integer('sale_id')->nullable();
            $table->integer('sale_detail_id')->nullable();
            $table->integer('client_id')->nullable();
            $table->text('notes')->nullable();
            $table->timestamps();
        });

        Schema::create('product_serial_movements', function ($table) {
            $table->increments('id');
            $table->integer('product_serial_id');
            $table->string('serial_number');
            $table->string('action');
            $table->string('from_status')->nullable();
            $table->string('to_status')->nullable();
            $table->integer('warehouse_id')->nullable();
            $table->integer('from_inventory_location_id')->nullable();
            $table->integer('to_inventory_location_id')->nullable();
            $table->string('reference_type')->nullable();
            $table->integer('reference_id')->nullable();
            $table->integer('user_id')->nullable();
            $table->text('notes')->nullable();
            $table->timestamp('created_at')->nullable();
        });
    }

    public function test_available_serials_are_scoped_to_physical_location(): void
    {
        [$from, $to] = $this->locations();
        ProductSerial::create([
            'serial_number' => 'IMEI-001',
            'product_id' => 10,
            'inventory_location_id' => $from->id,
            'status' => ProductSerial::STATUS_AVAILABLE,
        ]);
        ProductSerial::create([
            'serial_number' => 'IMEI-002',
            'product_id' => 10,
            'inventory_location_id' => $to->id,
            'status' => ProductSerial::STATUS_AVAILABLE,
        ]);

        $rows = app(SerialLocationService::class)->availableSerials($from->id, 10);

        $this->assertCount(1, $rows);
        $this->assertSame('IMEI-001', $rows[0]['serial_number']);
    }

    public function test_serial_move_changes_location_and_records_audit(): void
    {
        [$from, $to] = $this->locations();
        $serial = ProductSerial::create([
            'serial_number' => 'IMEI-003',
            'product_id' => 11,
            'inventory_location_id' => $from->id,
            'status' => ProductSerial::STATUS_AVAILABLE,
        ]);

        $moved = app(SerialLocationService::class)->moveSerials([$serial->id], $from->id, $to->id, [
            'reference_type' => 'internal_move',
            'reference_id' => 15,
        ]);

        $this->assertSame([$serial->id], $moved);
        $this->assertSame($to->id, (int) $serial->fresh()->inventory_location_id);
        $movement = ProductSerialMovement::where('product_serial_id', $serial->id)->firstOrFail();
        $this->assertSame(ProductSerialMovement::ACTION_LOCATION_MOVED, $movement->action);
        $this->assertSame($from->id, (int) $movement->from_inventory_location_id);
        $this->assertSame($to->id, (int) $movement->to_inventory_location_id);
    }

    public function test_sold_serial_cannot_be_moved_as_stock(): void
    {
        [$from, $to] = $this->locations();
        $serial = ProductSerial::create([
            'serial_number' => 'IMEI-004',
            'product_id' => 12,
            'inventory_location_id' => $from->id,
            'status' => ProductSerial::STATUS_SOLD,
        ]);

        $this->expectException(ValidationException::class);
        app(SerialLocationService::class)->moveSerials([$serial->id], $from->id, $to->id);
    }

    private function locations(): array
    {
        $branch = Branch::create(['name' => 'Sucursal', 'is_active' => true]);
        $from = InventoryLocation::create([
            'branch_id' => $branch->id,
            'code' => 'BODEGA',
            'name' => 'Bodega',
            'type' => InventoryLocation::TYPE_STORAGE,
            'is_active' => true,
        ]);
        $to = InventoryLocation::create([
            'branch_id' => $branch->id,
            'code' => 'PISO',
            'name' => 'Piso',
            'type' => InventoryLocation::TYPE_SALES_FLOOR,
            'is_sellable' => true,
            'is_active' => true,
        ]);

        return [$from, $to];
    }
}
