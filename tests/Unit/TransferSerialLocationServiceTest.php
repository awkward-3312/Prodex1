<?php

namespace Tests\Unit;

use App\Models\Product;
use App\Models\ProductSerial;
use App\Models\Transfer;
use App\Models\TransferDetail;
use App\Models\TransferDetailSerial;
use App\Models\TransferReceiptItem;
use App\Services\TransferSerialLocationService;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

class TransferSerialLocationServiceTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        Schema::create('products', function ($table) {
            $table->increments('id');
            $table->string('name');
            $table->boolean('is_imei')->default(false);
            $table->integer('unit_purchase_id')->nullable();
            $table->timestamps();
            $table->softDeletes();
        });

        Schema::create('units', function ($table) {
            $table->increments('id');
            $table->string('operator');
            $table->decimal('operator_value', 12, 3)->default(1);
            $table->string('ShortName')->nullable();
            $table->timestamps();
        });

        Schema::create('transfer_details', function ($table) {
            $table->increments('id');
            $table->integer('transfer_id');
            $table->decimal('quantity', 12, 3);
            $table->integer('purchase_unit_id')->nullable();
            $table->integer('product_id');
            $table->integer('product_variant_id')->nullable();
            $table->timestamps();
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

        Schema::create('transfer_detail_serials', function ($table) {
            $table->increments('id');
            $table->integer('transfer_detail_id');
            $table->integer('product_serial_id');
            $table->integer('transfer_receipt_item_id')->nullable();
            $table->string('status')->default('in_transit');
            $table->string('issue_type')->nullable();
            $table->timestamp('received_at')->nullable();
            $table->timestamps();
        });

        DB::table('units')->insert([
            'id' => 1, 'operator' => '*', 'operator_value' => 1, 'ShortName' => 'Und',
            'created_at' => now(), 'updated_at' => now(),
        ]);
        DB::table('products')->insert([
            'id' => 10, 'name' => 'Teléfono', 'is_imei' => 1, 'unit_purchase_id' => 1,
            'created_at' => now(), 'updated_at' => now(),
        ]);
        DB::table('transfer_details')->insert([
            'id' => 20, 'transfer_id' => 100, 'quantity' => 2, 'purchase_unit_id' => 1,
            'product_id' => 10, 'product_variant_id' => null,
            'created_at' => now(), 'updated_at' => now(),
        ]);
        foreach (['IMEI-A', 'IMEI-B'] as $serial) {
            DB::table('product_serials')->insert([
                'serial_number' => $serial, 'product_id' => 10, 'warehouse_id' => 1,
                'inventory_location_id' => 5, 'status' => ProductSerial::STATUS_AVAILABLE,
                'created_at' => now(), 'updated_at' => now(),
            ]);
        }
    }

    public function test_serials_leave_source_and_issue_reclassification_is_retry_safe(): void
    {
        $transfer = new Transfer();
        $transfer->id = 100;
        $transfer->from_warehouse_id = 1;
        $transfer->to_warehouse_id = 1;
        $transfer->from_inventory_location_id = 5;
        $transfer->to_inventory_location_id = 9;
        $transfer->exists = true;

        $detail = TransferDetail::findOrFail(20);
        $product = Product::findOrFail(10);
        $service = app(TransferSerialLocationService::class);

        $service->dispatchDetail($transfer, $detail, $product, 2);

        $this->assertSame(2, TransferDetailSerial::where('status', 'in_transit')->count());
        $this->assertSame(2, ProductSerial::where('status', ProductSerial::STATUS_RESERVED)->whereNull('inventory_location_id')->count());

        $goodItem = new TransferReceiptItem();
        $goodItem->id = 501;
        $goodItem->exists = true;
        $service->receiveGood($transfer, $detail, 1, $goodItem);

        $this->assertSame(1, ProductSerial::where('status', ProductSerial::STATUS_AVAILABLE)->where('inventory_location_id', 9)->count());
        $this->assertSame(1, TransferDetailSerial::where('status', 'received')->count());

        $missingItem = new TransferReceiptItem();
        $missingItem->id = 502;
        $missingItem->exists = true;
        $service->receiveMissing($transfer, $detail, 1, $missingItem);

        $this->assertSame(1, TransferDetailSerial::where('status', 'missing')->count());
        $this->assertSame(1, ProductSerial::where('status', ProductSerial::STATUS_RESERVED)->whereNull('inventory_location_id')->count());

        $service->reclassifyIssueToGood($transfer, $detail, 1, $missingItem, 'quantity_missing');
        $service->reclassifyIssueToGood($transfer, $detail, 1, $missingItem, 'quantity_missing');

        $this->assertSame(2, ProductSerial::where('status', ProductSerial::STATUS_AVAILABLE)->where('inventory_location_id', 9)->count());
        $this->assertSame(1, TransferDetailSerial::where('status', 'received')->where('issue_type', 'resolved_missing')->count());
        $this->assertSame(0, TransferDetailSerial::where('status', 'missing')->count());
        $this->assertSame(5, DB::table('product_serial_movements')->count());
    }
}
