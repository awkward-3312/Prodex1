<?php

namespace Tests\Unit;

use App\Models\Transfer;
use App\Models\TransferDetail;
use App\Models\TransferReceiptItem;
use App\Services\SafeTransferLogisticsService;
use App\Services\TransferDispatchGuardService;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

class TransferLogisticsBatchUnitsTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        $this->createSchema();
    }

    public function test_dispatch_and_receipt_keep_batch_ledger_in_base_units(): void
    {
        $origin = DB::table('warehouses')->insertGetId(['name' => 'Principal']);
        $destination = DB::table('warehouses')->insertGetId(['name' => 'Sucursal']);
        $unitId = DB::table('units')->insertGetId([
            'ShortName' => 'Caja',
            'operator' => '*',
            'operator_value' => 12,
        ]);
        DB::table('products')->insert([
            'id' => 1,
            'name' => 'Producto por caja',
            'code' => 'BOX-1',
            'unit_purchase_id' => $unitId,
            'is_batch_tracked' => 1,
        ]);

        // Aggregate source stock is already post-dispatch when the guard runs.
        DB::table('product_warehouse')->insert([
            'product_id' => 1,
            'warehouse_id' => $origin,
            'product_variant_id' => null,
            'qte' => 76,
            'manage_stock' => 1,
        ]);
        $sourceBatchId = DB::table('product_batches')->insertGetId([
            'product_id' => 1,
            'product_variant_id' => null,
            'warehouse_id' => $origin,
            'batch_no' => 'LOT-001',
            'expiry_date' => now()->addYear()->toDateString(),
            'qty' => 100,
            'unit_cost' => 5,
            'status' => 'active',
        ]);

        $transferId = DB::table('transfers')->insertGetId([
            'Ref' => 'TR_BATCH_0001',
            'date' => now()->toDateString(),
            'from_warehouse_id' => $origin,
            'to_warehouse_id' => $destination,
            'items' => 1,
            'statut' => 'sent',
            'approval_status' => 'approved',
            'logistics_status' => 'in_transit',
        ]);
        $detailId = DB::table('transfer_details')->insertGetId([
            'transfer_id' => $transferId,
            'product_id' => 1,
            // Deliberately null: production legacy rows can inherit the purchase
            // unit from products.unit_purchase_id and both dispatch + receiving
            // must resolve that fallback identically.
            'purchase_unit_id' => null,
            'quantity' => 2, // 2 boxes = 24 base units
            'cost' => 5,
            'total' => 10,
        ]);

        $transfer = Transfer::findOrFail($transferId);
        app(TransferDispatchGuardService::class)->finalizeDispatch($transfer);

        $this->assertEquals(76.0, (float) DB::table('product_batches')->where('id', $sourceBatchId)->value('qty'));
        $this->assertEquals(24.0, (float) DB::table('transfer_detail_batches')->where('transfer_detail_id', $detailId)->sum('qty'));

        $receiptId = DB::table('transfer_receipts')->insertGetId([
            'transfer_id' => $transferId,
            'warehouse_id' => $destination,
            'received_by_user_id' => 1,
            'status' => 'partial',
            'received_at' => now(),
        ]);
        $receiptItem = TransferReceiptItem::create([
            'transfer_receipt_id' => $receiptId,
            'transfer_detail_id' => $detailId,
            'quantity_good' => 1, // 1 box = 12 base units
            'quantity_defective' => 0,
            'quantity_missing' => 0,
        ]);

        $service = new class extends SafeTransferLogisticsService {
            public function creditForTest(Transfer $transfer, TransferDetail $detail, float $quantity, TransferReceiptItem $item): void
            {
                $this->creditGoodStock($transfer, $detail, $quantity, $item);
            }
        };
        $service->creditForTest($transfer, TransferDetail::findOrFail($detailId), 1, $receiptItem);

        $this->assertEquals(12.0, (float) DB::table('product_warehouse')
            ->where('warehouse_id', $destination)
            ->where('product_id', 1)
            ->value('qte'));
        $this->assertEquals(12.0, (float) DB::table('transfer_receipt_item_batches')
            ->where('transfer_receipt_item_id', $receiptItem->id)
            ->sum('quantity_good'));
        $this->assertEquals(12.0, (float) DB::table('product_batches')
            ->where('warehouse_id', $destination)
            ->where('batch_no', 'LOT-001')
            ->value('qty'));
    }

    private function createSchema(): void
    {
        Schema::create('warehouses', function ($t) {
            $t->increments('id');
            $t->string('name');
            $t->timestamps();
            $t->softDeletes();
        });
        Schema::create('units', function ($t) {
            $t->increments('id');
            $t->string('ShortName')->nullable();
            $t->string('operator')->nullable();
            $t->decimal('operator_value', 20, 6)->nullable();
            $t->timestamps();
            $t->softDeletes();
        });
        Schema::create('products', function ($t) {
            $t->increments('id');
            $t->string('name')->nullable();
            $t->string('code')->nullable();
            $t->integer('unit_purchase_id')->nullable();
            $t->boolean('is_batch_tracked')->default(false);
            $t->timestamps();
            $t->softDeletes();
        });
        Schema::create('product_warehouse', function ($t) {
            $t->increments('id');
            $t->integer('product_id');
            $t->integer('warehouse_id');
            $t->integer('product_variant_id')->nullable();
            $t->decimal('qte', 20, 6)->default(0);
            $t->integer('manage_stock')->default(1);
            $t->timestamps();
            $t->softDeletes();
        });
        Schema::create('product_batches', function ($t) {
            $t->bigIncrements('id');
            $t->unsignedInteger('product_id');
            $t->unsignedInteger('product_variant_id')->nullable();
            $t->unsignedInteger('warehouse_id');
            $t->string('batch_no');
            $t->date('expiry_date')->nullable();
            $t->date('mfg_date')->nullable();
            $t->double('qty')->default(0);
            $t->double('unit_cost')->nullable();
            $t->unsignedInteger('provider_id')->nullable();
            $t->unsignedBigInteger('source_purchase_id')->nullable();
            $t->string('status')->default('active');
            $t->string('barcode')->nullable();
            $t->text('notes')->nullable();
            $t->timestamps();
            $t->softDeletes();
        });
        Schema::create('transfers', function ($t) {
            $t->increments('id');
            $t->integer('user_id')->nullable();
            $t->string('Ref');
            $t->date('date');
            $t->time('time')->nullable();
            $t->integer('from_warehouse_id');
            $t->integer('to_warehouse_id');
            $t->decimal('items', 15)->default(0);
            $t->string('statut');
            $t->string('approval_status')->nullable();
            $t->string('receiving_token')->nullable();
            $t->string('logistics_status')->default('pending');
            $t->timestamp('dispatched_at')->nullable();
            $t->integer('dispatched_by_user_id')->nullable();
            $t->timestamp('received_at')->nullable();
            $t->integer('received_by_user_id')->nullable();
            $t->text('notes')->nullable();
            $t->decimal('GrandTotal', 20, 6)->default(0);
            $t->decimal('discount', 20, 6)->default(0);
            $t->decimal('shipping', 20, 6)->default(0);
            $t->decimal('TaxNet', 20, 6)->default(0);
            $t->decimal('tax_rate', 20, 6)->default(0);
            $t->timestamps();
            $t->softDeletes();
        });
        Schema::create('transfer_details', function ($t) {
            $t->increments('id');
            $t->integer('transfer_id');
            $t->integer('product_id');
            $t->integer('product_variant_id')->nullable();
            $t->integer('purchase_unit_id')->nullable();
            $t->decimal('quantity', 20, 6);
            $t->decimal('cost', 20, 6)->default(0);
            $t->decimal('TaxNet', 20, 6)->default(0);
            $t->decimal('discount', 20, 6)->default(0);
            $t->string('discount_method')->nullable();
            $t->string('tax_method')->nullable();
            $t->decimal('total', 20, 6)->default(0);
            $t->timestamps();
        });
        Schema::create('transfer_detail_batches', function ($t) {
            $t->bigIncrements('id');
            $t->unsignedInteger('transfer_detail_id');
            $t->unsignedBigInteger('source_batch_id');
            $t->unsignedBigInteger('dest_batch_id')->nullable();
            $t->double('qty')->default(0);
            $t->double('unit_cost')->nullable();
            $t->timestamps();
        });
        Schema::create('transfer_receipts', function ($t) {
            $t->id();
            $t->integer('transfer_id');
            $t->integer('warehouse_id');
            $t->integer('received_by_user_id');
            $t->string('status');
            $t->text('notes')->nullable();
            $t->timestamp('received_at');
            $t->timestamps();
        });
        Schema::create('transfer_receipt_items', function ($t) {
            $t->id();
            $t->unsignedBigInteger('transfer_receipt_id');
            $t->integer('transfer_detail_id');
            $t->decimal('quantity_good', 20, 6)->default(0);
            $t->decimal('quantity_defective', 20, 6)->default(0);
            $t->decimal('quantity_missing', 20, 6)->default(0);
            $t->text('notes')->nullable();
            $t->timestamps();
        });
        Schema::create('transfer_receipt_item_batches', function ($t) {
            $t->id();
            $t->unsignedBigInteger('transfer_receipt_item_id');
            $t->unsignedBigInteger('transfer_detail_batch_id');
            $t->unsignedBigInteger('source_batch_id');
            $t->unsignedBigInteger('destination_batch_id')->nullable();
            $t->decimal('quantity_good', 20, 6)->default(0);
            $t->timestamps();
        });
    }
}
