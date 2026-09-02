<?php

namespace Tests\Unit;

use App\Http\Controllers\FinalTransferController;
use App\Models\Transfer;
use App\Models\TransferDetail;
use App\Models\TransferDetailBatch;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use ReflectionMethod;
use Tests\TestCase;

/**
 * Hardening C3.26 — blindaje de edición de traslados location-aware.
 *
 * FinalTransferController::update NO debe ejecutar la reversión legacy
 * warehouse-based sobre un traslado location-aware que ya se movió/despachó/recibió.
 *   - legacy (sin ubicación): sigue por parent::update;
 *   - location-aware pendiente y sin movimiento: editable, sin revertir stock;
 *   - location-aware aprobado/despachado/en tránsito/recibido/con movimientos: 409.
 *
 * Aquí se verifica:
 *   1) el contrato (estructura del guard en el controlador);
 *   2) la lógica de decisión de locationTransferEditBlockReason con estado real
 *      + evidencia de movimientos/recibos.
 */
class TransferLocationEditGuardTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        Schema::create('transfers', function ($t) {
            $t->increments('id');
            $t->string('Ref')->nullable();
            $t->integer('from_warehouse_id')->nullable();
            $t->integer('to_warehouse_id')->nullable();
            $t->integer('from_inventory_location_id')->nullable();
            $t->integer('to_inventory_location_id')->nullable();
            $t->string('statut')->nullable();
            $t->string('approval_status')->nullable();
            $t->string('logistics_status')->nullable();
            $t->timestamp('dispatched_at')->nullable();
            $t->timestamp('received_at')->nullable();
            $t->timestamps();
            $t->softDeletes();
        });
        Schema::create('transfer_details', function ($t) {
            $t->increments('id');
            $t->integer('transfer_id');
            $t->integer('product_id')->nullable();
            $t->decimal('quantity', 20, 6)->default(0);
            $t->timestamps();
        });
        Schema::create('transfer_detail_batches', function ($t) {
            $t->increments('id');
            $t->integer('transfer_detail_id');
            $t->integer('source_batch_id');
            $t->integer('dest_batch_id')->nullable();
            $t->double('qty')->default(0);
            $t->timestamps();
        });
        Schema::create('transfer_receipts', function ($t) {
            $t->increments('id');
            $t->integer('transfer_id');
            $t->timestamps();
        });
        Schema::create('transfer_discrepancies', function ($t) {
            $t->increments('id');
            $t->integer('transfer_id');
            $t->timestamps();
        });
        Schema::create('product_batch_location_movements', function ($t) {
            $t->increments('id');
            $t->string('reference_type')->nullable();
            $t->string('reference_id')->nullable();
            $t->timestamps();
        });
        Schema::create('inventory_location_movements', function ($t) {
            $t->increments('id');
            $t->string('reference_type')->nullable();
            $t->string('reference_id')->nullable();
            $t->timestamps();
        });
    }

    private function reason(Transfer $transfer): ?string
    {
        $m = new ReflectionMethod(FinalTransferController::class, 'locationTransferEditBlockReason');
        $m->setAccessible(true);

        return $m->invoke(app(FinalTransferController::class), $transfer);
    }

    private function transfer(array $overrides = []): Transfer
    {
        return Transfer::create(array_merge([
            'Ref' => 'TR_X',
            'from_warehouse_id' => 1, 'to_warehouse_id' => 2,
            'from_inventory_location_id' => 10, 'to_inventory_location_id' => 20,
            'statut' => 'pending', 'approval_status' => 'pending', 'logistics_status' => 'pending',
        ], $overrides));
    }

    // ---------- contrato ----------

    public function test_update_gates_location_aware_transfers_before_parent_update(): void
    {
        $src = file_get_contents(dirname(__DIR__, 2).'/app/Http/Controllers/FinalTransferController.php');

        $this->assertStringContainsString('$isLocationAware = $transfer->from_inventory_location_id || $transfer->to_inventory_location_id;', $src);
        $this->assertStringContainsString('if (! $isLocationAware) {', $src);
        $this->assertStringContainsString('return parent::update($request, $id);', $src);
        $this->assertStringContainsString('$blockReason = $this->locationTransferEditBlockReason($transfer);', $src);
        $this->assertStringContainsString("'code' => 'transfer_not_editable',", $src);
        $this->assertStringContainsString('], 409);', $src);
        $this->assertStringContainsString('return $this->updatePendingLocationAware($request, $transfer);', $src);
        // La ruta location-aware NUNCA debe caer en la reversión legacy.
        $this->assertStringNotContainsString("if (! \$isLocationAware) {\n            return parent::update(\$request, \$id);\n        }\n\n        return parent::update", $src);
    }

    // ---------- lógica de decisión ----------

    public function test_pending_location_aware_with_no_movement_is_editable(): void
    {
        $this->assertNull($this->reason($this->transfer()));
    }

    public function test_rejected_pending_transfer_is_still_editable(): void
    {
        $this->assertNull($this->reason($this->transfer(['approval_status' => 'rejected'])));
    }

    public function test_approved_transfer_is_blocked(): void
    {
        $r = $this->reason($this->transfer(['approval_status' => 'approved']));
        $this->assertNotNull($r);
        $this->assertStringContainsString('aprobada', $r);
    }

    public function test_in_transit_transfer_is_blocked(): void
    {
        $this->assertNotNull($this->reason($this->transfer([
            'approval_status' => 'approved', 'logistics_status' => 'in_transit', 'statut' => 'sent',
        ])));
    }

    public function test_pending_with_statut_sent_but_no_movement_is_still_editable(): void
    {
        // The creator picked "Enviado" as the initial status; nothing was approved
        // or dispatched, so no stock moved and the transfer stays editable.
        $this->assertNull($this->reason($this->transfer(['approval_status' => 'pending', 'statut' => 'sent'])));
    }

    public function test_approved_and_sent_is_blocked(): void
    {
        $r = $this->reason($this->transfer([
            'approval_status' => 'approved', 'statut' => 'sent', 'logistics_status' => 'in_transit',
        ]));
        $this->assertNotNull($r);
    }

    public function test_dispatched_at_timestamp_blocks(): void
    {
        $this->assertNotNull($this->reason($this->transfer(['dispatched_at' => now()])));
    }

    public function test_existing_transfer_detail_batch_blocks(): void
    {
        $tr = $this->transfer();
        $d = TransferDetail::create(['transfer_id' => $tr->id, 'product_id' => 5, 'quantity' => 4]);
        TransferDetailBatch::create(['transfer_detail_id' => $d->id, 'source_batch_id' => 1, 'qty' => 3]);

        $r = $this->reason($tr);
        $this->assertNotNull($r);
        $this->assertStringContainsString('lotes asignados', $r);
    }

    public function test_existing_receipt_blocks(): void
    {
        $tr = $this->transfer();
        DB::table('transfer_receipts')->insert(['transfer_id' => $tr->id, 'created_at' => now(), 'updated_at' => now()]);

        $this->assertNotNull($this->reason($tr));
    }

    public function test_existing_discrepancy_blocks(): void
    {
        $tr = $this->transfer();
        DB::table('transfer_discrepancies')->insert(['transfer_id' => $tr->id, 'created_at' => now(), 'updated_at' => now()]);

        $this->assertNotNull($this->reason($tr));
    }

    public function test_existing_batch_location_movement_blocks(): void
    {
        $tr = $this->transfer();
        $d = TransferDetail::create(['transfer_id' => $tr->id, 'product_id' => 5, 'quantity' => 4]);
        DB::table('product_batch_location_movements')->insert([
            'reference_type' => 'TransferDispatchBatch', 'reference_id' => (string) $d->id,
            'created_at' => now(), 'updated_at' => now(),
        ]);

        $this->assertNotNull($this->reason($tr));
    }

    public function test_existing_inventory_location_movement_blocks(): void
    {
        $tr = $this->transfer();
        DB::table('inventory_location_movements')->insert([
            'reference_type' => 'TransferDispatch', 'reference_id' => (string) $tr->id,
            'created_at' => now(), 'updated_at' => now(),
        ]);

        $this->assertNotNull($this->reason($tr));
    }
}
