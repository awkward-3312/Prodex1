<?php

namespace Tests\Unit;

use App\Models\ProductBatch;
use App\Models\ProductBatchLocationStock;
use App\Models\Transfer;
use App\Models\TransferDetail;
use App\Models\TransferReceiptItem;
use App\Services\TransferBatchIssueService;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

class TransferBatchIssueServiceTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        Schema::create('product_batches', function ($table) {
            $table->increments('id');
            $table->integer('product_id');
            $table->integer('product_variant_id')->nullable();
            $table->integer('warehouse_id');
            $table->string('batch_no');
            $table->date('expiry_date')->nullable();
            $table->date('mfg_date')->nullable();
            $table->decimal('qty', 20, 6)->default(0);
            $table->decimal('unit_cost', 20, 6)->default(0);
            $table->integer('provider_id')->nullable();
            $table->integer('source_purchase_id')->nullable();
            $table->string('status')->default('active');
            $table->string('barcode')->nullable();
            $table->text('notes')->nullable();
            $table->timestamps();
            $table->softDeletes();
        });

        Schema::create('transfer_detail_batches', function ($table) {
            $table->increments('id');
            $table->integer('transfer_detail_id');
            $table->integer('source_batch_id');
            $table->integer('dest_batch_id')->nullable();
            $table->decimal('qty', 20, 6);
            $table->decimal('unit_cost', 20, 6)->nullable();
            $table->timestamps();
        });

        Schema::create('transfer_receipt_items', function ($table) {
            $table->increments('id');
            $table->integer('transfer_receipt_id')->nullable();
            $table->integer('transfer_detail_id');
            $table->decimal('quantity_good', 20, 6)->default(0);
            $table->decimal('quantity_defective', 20, 6)->default(0);
            $table->decimal('quantity_missing', 20, 6)->default(0);
            $table->text('notes')->nullable();
            $table->timestamps();
        });

        Schema::create('transfer_receipt_item_batches', function ($table) {
            $table->increments('id');
            $table->integer('transfer_receipt_item_id');
            $table->integer('transfer_detail_batch_id');
            $table->integer('source_batch_id');
            $table->integer('destination_batch_id')->nullable();
            $table->decimal('quantity_good', 20, 6)->default(0);
            $table->timestamps();
        });

        Schema::create('transfer_receipt_item_batch_issues', function ($table) {
            $table->increments('id');
            $table->integer('transfer_receipt_item_id');
            $table->integer('transfer_detail_batch_id');
            $table->integer('source_batch_id');
            $table->integer('destination_batch_id')->nullable();
            $table->integer('inventory_location_id')->nullable();
            $table->string('issue_type');
            $table->decimal('quantity', 20, 6);
            $table->decimal('resolved_quantity', 20, 6)->default(0);
            $table->string('resolution_status')->default('open');
            $table->string('resolution_code')->nullable();
            $table->timestamp('resolved_at')->nullable();
            $table->timestamps();
        });

        Schema::create('product_batch_location_stocks', function ($table) {
            $table->increments('id');
            $table->integer('product_batch_id');
            $table->integer('inventory_location_id');
            $table->decimal('quantity', 20, 6)->default(0);
            $table->decimal('reserved_quantity', 20, 6)->default(0);
            $table->timestamps();
        });

        Schema::create('product_batch_location_movements', function ($table) {
            $table->increments('id');
            $table->integer('product_batch_id');
            $table->integer('from_inventory_location_id')->nullable();
            $table->integer('to_inventory_location_id')->nullable();
            $table->decimal('quantity', 20, 6);
            $table->integer('user_id')->nullable();
            $table->string('reference_type')->nullable();
            $table->string('reference_id')->nullable();
            $table->string('idempotency_key')->nullable()->unique();
            $table->string('notes')->nullable();
            $table->json('metadata')->nullable();
            $table->timestamps();
        });

        DB::table('product_batches')->insert([
            'id' => 1,
            'product_id' => 10,
            'warehouse_id' => 1,
            'batch_no' => 'LOT-A',
            'expiry_date' => '2027-12-31',
            'qty' => 0,
            'unit_cost' => 5,
            'status' => 'active',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        DB::table('transfer_detail_batches')->insert([
            'id' => 11,
            'transfer_detail_id' => 20,
            'source_batch_id' => 1,
            'qty' => 5,
            'unit_cost' => 5,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        DB::table('transfer_receipt_items')->insert([
            'id' => 50,
            'transfer_receipt_id' => 40,
            'transfer_detail_id' => 20,
            'quantity_good' => 2,
            'quantity_defective' => 2,
            'quantity_missing' => 1,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    public function test_batch_identity_survives_good_defective_missing_and_resolution(): void
    {
        $transfer = new Transfer();
        $transfer->id = 100;
        $transfer->Ref = 'TR-100';
        $transfer->from_warehouse_id = 1;
        $transfer->to_warehouse_id = 2;
        $transfer->from_inventory_location_id = 10;
        $transfer->to_inventory_location_id = 20;
        $transfer->exists = true;

        $detail = new TransferDetail();
        $detail->id = 20;
        $detail->product_id = 10;
        $detail->transfer_id = 100;
        $detail->exists = true;

        $receiptItem = TransferReceiptItem::findOrFail(50);
        $service = app(TransferBatchIssueService::class);

        $service->allocateGood($transfer, $detail, 2, $receiptItem, 20);
        $service->allocateIssue($transfer, $detail, 2, $receiptItem, 'defective', 30);
        $service->allocateIssue($transfer, $detail, 1, $receiptItem, 'missing');

        $destinationBatch = ProductBatch::where('warehouse_id', 2)->where('batch_no', 'LOT-A')->firstOrFail();
        $this->assertEqualsWithDelta(2, (float) $destinationBatch->qty, 0.0001);
        $this->assertEqualsWithDelta(2, $this->batchStock($destinationBatch->id, 20), 0.0001);
        $this->assertEqualsWithDelta(2, $this->batchStock($destinationBatch->id, 30), 0.0001);
        $this->assertEqualsWithDelta(3, (float) DB::table('transfer_receipt_item_batch_issues')->sum('quantity'), 0.0001);

        $service->reclassifyToGood($transfer, $detail, 1, $receiptItem, 'defective', 20);
        $service->reclassifyToGood($transfer, $detail, 1, $receiptItem, 'missing', 20);

        $destinationBatch->refresh();
        $this->assertEqualsWithDelta(4, (float) $destinationBatch->qty, 0.0001);
        $this->assertEqualsWithDelta(4, $this->batchStock($destinationBatch->id, 20), 0.0001);
        $this->assertEqualsWithDelta(1, $this->batchStock($destinationBatch->id, 30), 0.0001);

        $service->resolveDisposition(
            (object) ['type' => 'defective', 'quantity' => 1],
            $transfer,
            $detail,
            'written_off'
        );

        $this->assertEqualsWithDelta(0, $this->batchStock($destinationBatch->id, 30), 0.0001);
        $this->assertEqualsWithDelta(3, (float) DB::table('transfer_receipt_item_batch_issues')->sum('resolved_quantity'), 0.0001);
    }

    private function batchStock(int $batchId, int $locationId): float
    {
        return (float) (ProductBatchLocationStock::where('product_batch_id', $batchId)
            ->where('inventory_location_id', $locationId)
            ->value('quantity') ?? 0);
    }
}
