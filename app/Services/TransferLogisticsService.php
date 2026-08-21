<?php

namespace App\Services;

use App\Models\ProductBatch;
use App\Models\product_warehouse;
use App\Models\Transfer;
use App\Models\TransferDetail;
use App\Models\TransferDetailBatch;
use App\Models\TransferReceipt;
use App\Models\TransferReceiptItem;
use App\Models\Unit;
use App\Models\User;
use App\Models\UserOperationalAssignment;
use App\Models\UserWarehouse;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

class TransferLogisticsService
{
    public const RECEIVE_PERMISSION = 'transfer_receive';

    public function userCanReceive(User $user, Transfer $transfer): bool
    {
        if (! $user->hasPermissionName(self::RECEIVE_PERMISSION)) {
            return false;
        }

        return in_array((int) $transfer->to_warehouse_id, $this->warehouseIdsForUser($user), true);
    }

    public function warehouseIdsForUser(User $user): array
    {
        if ((int) $user->is_all_warehouses === 1) {
            return DB::table('warehouses')->whereNull('deleted_at')->pluck('id')->map(fn ($id) => (int) $id)->all();
        }

        $ids = UserWarehouse::where('user_id', $user->id)->pluck('warehouse_id')->map(fn ($id) => (int) $id)->all();

        if ($user->default_warehouse_id) {
            $ids[] = (int) $user->default_warehouse_id;
        }

        if (Schema::hasTable('user_operational_assignments')) {
            $temporary = UserOperationalAssignment::where('user_id', $user->id)
                ->where('status', UserOperationalAssignment::STATUS_ACTIVE)
                ->where(function ($query) {
                    $query->whereNull('ends_at')->orWhere('ends_at', '>', now());
                })
                ->pluck('temporary_warehouse_id')
                ->filter()
                ->map(fn ($id) => (int) $id)
                ->all();
            $ids = array_merge($ids, $temporary);
        }

        return array_values(array_unique(array_filter($ids)));
    }

    public function syncDispatchState(Transfer $transfer, ?User $actor = null): void
    {
        if (! Schema::hasColumn('transfers', 'logistics_status')) {
            return;
        }

        if (! $transfer->isApproved() || $transfer->statut !== 'sent') {
            return;
        }

        if ($transfer->logistics_status === 'received' || $transfer->logistics_status === 'received_with_issues') {
            return;
        }

        $changes = [];
        if (! $transfer->receiving_token) {
            $changes['receiving_token'] = $this->generateReceivingToken();
        }
        if (! $transfer->dispatched_at) {
            $changes['dispatched_at'] = now();
        }
        if (! $transfer->dispatched_by_user_id && $actor) {
            $changes['dispatched_by_user_id'] = $actor->id;
        }
        if ($transfer->logistics_status !== 'partially_received') {
            $changes['logistics_status'] = 'in_transit';
        }

        if ($changes) {
            DB::table('transfers')->where('id', $transfer->id)->update($changes);
            $transfer->fill($changes);
        }

        $this->recordEvent($transfer->id, 'dispatched', $actor?->id ?? $transfer->user_id, $transfer->from_warehouse_id, [
            'from_warehouse_id' => (int) $transfer->from_warehouse_id,
            'to_warehouse_id' => (int) $transfer->to_warehouse_id,
            'reference' => $transfer->Ref,
        ], true);

        $this->notifyDestinationReceivers($transfer->fresh(['from_warehouse', 'to_warehouse']));
    }

    public function notifyDestinationReceivers(Transfer $transfer): void
    {
        if (! Schema::hasTable('transfer_notifications')) {
            return;
        }

        $candidateIds = User::query()
            ->where('statut', 1)
            ->where(function ($q) use ($transfer) {
                $q->where('default_warehouse_id', $transfer->to_warehouse_id)
                    ->orWhereIn('id', UserWarehouse::where('warehouse_id', $transfer->to_warehouse_id)->select('user_id'));
            })
            ->pluck('id');

        if (Schema::hasTable('user_operational_assignments')) {
            $temporaryIds = UserOperationalAssignment::where('temporary_warehouse_id', $transfer->to_warehouse_id)
                ->where('status', UserOperationalAssignment::STATUS_ACTIVE)
                ->where(function ($query) {
                    $query->whereNull('ends_at')->orWhere('ends_at', '>', now());
                })
                ->pluck('user_id');
            $candidateIds = $candidateIds->merge($temporaryIds);
        }

        $users = User::whereIn('id', $candidateIds->unique()->values())
            ->get()
            ->filter(fn (User $user) => $user->hasPermissionName(self::RECEIVE_PERMISSION));

        foreach ($users as $user) {
            DB::table('transfer_notifications')->updateOrInsert(
                [
                    'transfer_id' => $transfer->id,
                    'user_id' => $user->id,
                    'type' => 'incoming_transfer',
                ],
                [
                    'title' => 'Transferencia en camino',
                    'message' => sprintf(
                        '%s envió %s hacia %s.',
                        optional($transfer->from_warehouse)->name ?: 'La bodega de origen',
                        $transfer->Ref,
                        optional($transfer->to_warehouse)->name ?: 'tu bodega'
                    ),
                    'updated_at' => now(),
                    'created_at' => now(),
                ]
            );
        }
    }

    public function receive(Transfer $transfer, User $user, array $items, ?string $notes = null): Transfer
    {
        if (! $this->userCanReceive($user, $transfer)) {
            abort(403, 'No tienes permiso para recibir esta transferencia en la bodega destino.');
        }

        return DB::transaction(function () use ($transfer, $user, $items, $notes) {
            $locked = Transfer::whereKey($transfer->id)->lockForUpdate()->firstOrFail();

            if (! $locked->isApproved() || ! in_array($locked->logistics_status, ['in_transit', 'partially_received'], true)) {
                throw ValidationException::withMessages([
                    'transfer' => 'La transferencia no está disponible para recepción.',
                ]);
            }

            if ($locked->statut !== 'sent') {
                throw ValidationException::withMessages([
                    'transfer' => 'La transferencia debe estar despachada antes de poder recibirse.',
                ]);
            }

            $details = TransferDetail::where('transfer_id', $locked->id)->lockForUpdate()->get()->keyBy('id');
            $prior = DB::table('transfer_receipt_items')
                ->join('transfer_receipts', 'transfer_receipts.id', '=', 'transfer_receipt_items.transfer_receipt_id')
                ->where('transfer_receipts.transfer_id', $locked->id)
                ->selectRaw('transfer_receipt_items.transfer_detail_id, SUM(quantity_good + quantity_defective + quantity_missing) as accounted')
                ->groupBy('transfer_receipt_items.transfer_detail_id')
                ->pluck('accounted', 'transfer_detail_id');

            $normalized = [];
            foreach ($items as $row) {
                $detailId = (int) ($row['transfer_detail_id'] ?? 0);
                $detail = $details->get($detailId);
                if (! $detail) {
                    throw ValidationException::withMessages(['items' => "La línea {$detailId} no pertenece a esta transferencia."]);
                }

                $good = max(0, (float) ($row['quantity_good'] ?? 0));
                $defective = max(0, (float) ($row['quantity_defective'] ?? 0));
                $missing = max(0, (float) ($row['quantity_missing'] ?? 0));
                $delta = $good + $defective + $missing;
                $already = (float) ($prior[$detailId] ?? 0);
                $remaining = max(0, (float) $detail->quantity - $already);

                if ($delta <= 0) {
                    continue;
                }
                if ($delta > $remaining + 0.000001) {
                    throw ValidationException::withMessages([
                        'items' => "La cantidad indicada para la línea {$detailId} supera lo pendiente por recibir ({$remaining}).",
                    ]);
                }

                $normalized[] = compact('detail', 'good', 'defective', 'missing');
            }

            if (! $normalized) {
                throw ValidationException::withMessages(['items' => 'Debes registrar al menos una cantidad recibida.']);
            }

            $receipt = TransferReceipt::create([
                'transfer_id' => $locked->id,
                'warehouse_id' => $locked->to_warehouse_id,
                'received_by_user_id' => $user->id,
                'status' => 'partial',
                'notes' => $notes,
                'received_at' => now(),
            ]);

            foreach ($normalized as $line) {
                /** @var TransferDetail $detail */
                $detail = $line['detail'];
                $receiptItem = TransferReceiptItem::create([
                    'transfer_receipt_id' => $receipt->id,
                    'transfer_detail_id' => $detail->id,
                    'quantity_good' => $line['good'],
                    'quantity_defective' => $line['defective'],
                    'quantity_missing' => $line['missing'],
                    'notes' => null,
                ]);

                if ($line['good'] > 0) {
                    $this->creditGoodStock($locked, $detail, $line['good'], $receiptItem);
                }

                if ($line['defective'] > 0) {
                    DB::table('transfer_quarantine_stock')->insert([
                        'transfer_id' => $locked->id,
                        'transfer_detail_id' => $detail->id,
                        'warehouse_id' => $locked->to_warehouse_id,
                        'product_id' => $detail->product_id,
                        'product_variant_id' => $detail->product_variant_id,
                        'quantity' => $line['defective'],
                        'status' => 'quarantined',
                        'notes' => 'Recibido defectuoso durante transferencia.',
                        'created_by_user_id' => $user->id,
                        'created_at' => now(),
                        'updated_at' => now(),
                    ]);
                    $this->createDiscrepancy($locked, $detail, $user, 'defective', $line['defective']);
                }

                if ($line['missing'] > 0) {
                    $this->createDiscrepancy($locked, $detail, $user, 'missing', $line['missing']);
                }
            }

            $totals = DB::table('transfer_receipt_items')
                ->join('transfer_receipts', 'transfer_receipts.id', '=', 'transfer_receipt_items.transfer_receipt_id')
                ->where('transfer_receipts.transfer_id', $locked->id)
                ->selectRaw('transfer_receipt_items.transfer_detail_id, SUM(quantity_good + quantity_defective + quantity_missing) as accounted')
                ->groupBy('transfer_receipt_items.transfer_detail_id')
                ->pluck('accounted', 'transfer_detail_id');

            $fullyAccounted = true;
            foreach ($details as $detail) {
                if ((float) ($totals[$detail->id] ?? 0) + 0.000001 < (float) $detail->quantity) {
                    $fullyAccounted = false;
                    break;
                }
            }

            $hasIssues = DB::table('transfer_discrepancies')->where('transfer_id', $locked->id)->exists();
            if ($fullyAccounted) {
                $status = $hasIssues ? 'received_with_issues' : 'received';
                DB::table('transfers')->where('id', $locked->id)->update([
                    'logistics_status' => $status,
                    'statut' => 'completed',
                    'received_at' => now(),
                    'received_by_user_id' => $user->id,
                    'updated_at' => now(),
                ]);
                $receipt->update(['status' => $hasIssues ? 'completed_with_issues' : 'completed']);
                DB::table('transfer_notifications')->where('transfer_id', $locked->id)->where('user_id', $user->id)->update(['read_at' => now(), 'updated_at' => now()]);
                $eventType = $hasIssues ? 'received_with_issues' : 'received';
            } else {
                DB::table('transfers')->where('id', $locked->id)->update([
                    'logistics_status' => 'partially_received',
                    'updated_at' => now(),
                ]);
                $eventType = 'partially_received';
            }

            $this->recordEvent($locked->id, $eventType, $user->id, $locked->to_warehouse_id, [
                'receipt_id' => $receipt->id,
                'has_issues' => $hasIssues,
            ]);

            return Transfer::with(['from_warehouse', 'to_warehouse', 'details.product'])->findOrFail($locked->id);
        }, 5);
    }

    protected function creditGoodStock(Transfer $transfer, TransferDetail $detail, float $quantity, TransferReceiptItem $receiptItem): void
    {
        $unit = $detail->purchase_unit_id ? Unit::find($detail->purchase_unit_id) : null;
        $stockQty = $this->convertToBaseQuantity($quantity, $unit);

        $query = product_warehouse::whereNull('deleted_at')
            ->where('warehouse_id', $transfer->to_warehouse_id)
            ->where('product_id', $detail->product_id)
            ->where(function ($q) use ($detail) {
                if ($detail->product_variant_id) {
                    $q->where('product_variant_id', $detail->product_variant_id);
                } else {
                    $q->whereNull('product_variant_id');
                }
            });

        $row = $query->lockForUpdate()->first();
        if (! $row) {
            $row = new product_warehouse();
            $row->warehouse_id = $transfer->to_warehouse_id;
            $row->product_id = $detail->product_id;
            $row->product_variant_id = $detail->product_variant_id;
            $row->qte = 0;
        }
        $row->qte = (float) $row->qte + $stockQty;
        $row->save();

        $this->creditBatchStockIfApplicable($transfer, $detail, $quantity, $receiptItem);
    }

    protected function creditBatchStockIfApplicable(Transfer $transfer, TransferDetail $detail, float $quantity, TransferReceiptItem $receiptItem): void
    {
        if (! Schema::hasTable('transfer_detail_batches') || ! Schema::hasTable('transfer_receipt_item_batches') || ! Schema::hasTable('product_batches')) {
            return;
        }

        $pivots = TransferDetailBatch::with('sourceBatch')->where('transfer_detail_id', $detail->id)->orderBy('id')->lockForUpdate()->get();
        if ($pivots->isEmpty()) {
            return;
        }

        $remaining = $quantity;
        foreach ($pivots as $pivot) {
            if ($remaining <= 0) {
                break;
            }

            $alreadyCredited = (float) DB::table('transfer_receipt_item_batches')
                ->where('transfer_detail_batch_id', $pivot->id)
                ->sum('quantity_good');
            $available = max(0, (float) $pivot->qty - $alreadyCredited);
            $take = min($available, $remaining);
            if ($take <= 0 || ! $pivot->sourceBatch) {
                continue;
            }

            $source = $pivot->sourceBatch;
            $destination = ProductBatch::where('warehouse_id', $transfer->to_warehouse_id)
                ->where('product_id', $source->product_id)
                ->where('batch_no', $source->batch_no)
                ->where(function ($q) use ($source) {
                    if ($source->product_variant_id) {
                        $q->where('product_variant_id', $source->product_variant_id);
                    } else {
                        $q->whereNull('product_variant_id');
                    }
                })
                ->lockForUpdate()
                ->first();

            if (! $destination) {
                $destination = ProductBatch::create([
                    'product_id' => $source->product_id,
                    'product_variant_id' => $source->product_variant_id,
                    'warehouse_id' => $transfer->to_warehouse_id,
                    'batch_no' => $source->batch_no,
                    'expiry_date' => $source->expiry_date,
                    'mfg_date' => $source->mfg_date,
                    'qty' => 0,
                    'unit_cost' => $pivot->unit_cost ?? $source->unit_cost,
                    'provider_id' => $source->provider_id,
                    'status' => 'active',
                    'notes' => 'Recibido mediante '.$transfer->Ref,
                ]);
            }

            $destination->qty = (float) $destination->qty + $take;
            $destination->save();

            DB::table('transfer_receipt_item_batches')->insert([
                'transfer_receipt_item_id' => $receiptItem->id,
                'transfer_detail_batch_id' => $pivot->id,
                'source_batch_id' => $pivot->source_batch_id,
                'destination_batch_id' => $destination->id,
                'quantity_good' => $take,
                'created_at' => now(),
                'updated_at' => now(),
            ]);

            // Keep legacy pivot useful once the whole source allocation reaches destination.
            if (! $pivot->dest_batch_id) {
                $pivot->dest_batch_id = $destination->id;
                $pivot->save();
            }

            $remaining -= $take;
        }
    }

    protected function convertToBaseQuantity(float $quantity, ?Unit $unit): float
    {
        if (! $unit || ! $unit->operator_value) {
            return $quantity;
        }

        return $unit->operator === '/'
            ? $quantity / (float) $unit->operator_value
            : $quantity * (float) $unit->operator_value;
    }

    protected function createDiscrepancy(Transfer $transfer, TransferDetail $detail, User $user, string $type, float $quantity): void
    {
        DB::table('transfer_discrepancies')->insert([
            'transfer_id' => $transfer->id,
            'transfer_detail_id' => $detail->id,
            'warehouse_id' => $transfer->to_warehouse_id,
            'reported_by_user_id' => $user->id,
            'type' => $type,
            'quantity' => $quantity,
            'resolution_status' => 'open',
            'notes' => null,
            'reported_at' => now(),
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    public function recordEvent(int $transferId, string $eventType, ?int $actorUserId, ?int $warehouseId, array $payload = [], bool $once = false): void
    {
        if (! Schema::hasTable('transfer_events')) {
            return;
        }

        if ($once && DB::table('transfer_events')->where('transfer_id', $transferId)->where('event_type', $eventType)->exists()) {
            return;
        }

        DB::table('transfer_events')->insert([
            'transfer_id' => $transferId,
            'event_type' => $eventType,
            'actor_user_id' => $actorUserId,
            'warehouse_id' => $warehouseId,
            'payload' => $payload ? json_encode($payload) : null,
            'created_at' => now(),
        ]);
    }

    public function generateReceivingToken(): string
    {
        do {
            $token = 'TRF-'.now()->format('ymd').'-'.strtoupper(Str::random(12));
        } while (DB::table('transfers')->where('receiving_token', $token)->exists());

        return $token;
    }
}
