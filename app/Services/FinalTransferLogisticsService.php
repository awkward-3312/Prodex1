<?php

namespace App\Services;

use App\Models\Product;
use App\Models\Transfer;
use App\Models\TransferDetail;
use App\Models\TransferReceiptItem;
use App\Models\User;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Final production transfer binding. Request-scoped compatibility hints are used
 * only while resolving a known location-aware transfer; normal legacy warehouse
 * authorization remains unchanged.
 */
class FinalTransferLogisticsService extends LocationAwareTransferLogisticsService
{
    public function warehouseIdsForUser(User $user): array
    {
        $ids = parent::warehouseIdsForUser($user);

        if (! app()->bound('request')) return $ids;
        $extra = request()->attributes->get('prodex_transfer_authorized_warehouse_ids', []);
        if (! is_array($extra) || ! $extra) return $ids;

        foreach ($extra as $id) {
            if (is_numeric($id) && (int) $id > 0) $ids[] = (int) $id;
        }

        return array_values(array_unique($ids));
    }

    public function userCanReceive(User $user, Transfer $transfer): bool
    {
        if (! $transfer->to_inventory_location_id) {
            return parent::userCanReceive($user, $transfer);
        }

        return $user->hasPermissionName(self::RECEIVE_PERMISSION)
            && app(InventoryLocationScopeService::class)->canReceiveAt(
                $user,
                (int) $transfer->to_inventory_location_id
            );
    }

    public function receive(
        Transfer $transfer,
        User $user,
        array $items,
        ?string $notes = null,
        ?string $requestToken = null
    ): Transfer {
        return DB::transaction(function () use ($transfer, $user, $items, $notes, $requestToken) {
            $updated = parent::receive($transfer, $user, $items, $notes, $requestToken);

            $receipt = DB::table('transfer_receipts')
                ->where('transfer_id', $transfer->id)
                ->when($requestToken, fn ($query) => $query->where('request_token', $requestToken))
                ->when(! $requestToken, fn ($query) => $query->where('received_by_user_id', $user->id))
                ->orderByDesc('id')
                ->first();

            if (! $receipt) return $updated;

            $receiptItems = TransferReceiptItem::where('transfer_receipt_id', $receipt->id)
                ->orderBy('id')->lockForUpdate()->get();

            // Defective units are already excluded from sellable destination stock by the
            // logistics flow. Mirror them into the Damage module as immutable audit
            // documents without subtracting inventory a second time.
            $this->syncTransferDamages($transfer, $receiptItems, $user);

            if (! $transfer->to_inventory_location_id || ! app(TransferBatchIssueService::class)->isSupported()) {
                return $updated;
            }

            foreach ($receiptItems as $receiptItem) {
                $detail = TransferDetail::find($receiptItem->transfer_detail_id);
                $product = $detail ? Product::find($detail->product_id) : null;
                if (! $detail || ! $product) continue;

                if ((float) $receiptItem->quantity_defective > 0) {
                    $base = app(TransferSerialLocationService::class)->baseQuantityForDetail(
                        $detail,
                        $product,
                        (float) $receiptItem->quantity_defective
                    );
                    $quarantineLocationId = DB::table('transfer_quarantine_stock')
                        ->where('transfer_id', $transfer->id)
                        ->where('transfer_detail_id', $detail->id)
                        ->whereNotNull('inventory_location_id')
                        ->orderByDesc('id')
                        ->value('inventory_location_id');

                    app(TransferBatchIssueService::class)->allocateIssue(
                        $transfer,
                        $detail,
                        $base,
                        $receiptItem,
                        'defective',
                        $quarantineLocationId ? (int) $quarantineLocationId : null
                    );
                }

                if ((float) $receiptItem->quantity_missing > 0) {
                    $base = app(TransferSerialLocationService::class)->baseQuantityForDetail(
                        $detail,
                        $product,
                        (float) $receiptItem->quantity_missing
                    );
                    app(TransferBatchIssueService::class)->allocateIssue(
                        $transfer,
                        $detail,
                        $base,
                        $receiptItem,
                        'missing'
                    );
                }
            }

            return $updated;
        }, 5);
    }

    private function syncTransferDamages(Transfer $transfer, $receiptItems, User $user): void
    {
        if (! Schema::hasTable('damages')
            || ! Schema::hasTable('damage_details')
            || ! Schema::hasTable('transfer_quarantine_stock')
            || ! Schema::hasColumn('damages', 'source_type')
            || ! Schema::hasColumn('damages', 'source_id')
            || ! Schema::hasColumn('damages', 'transfer_id')
            || ! Schema::hasColumn('damages', 'source_locked')) {
            return;
        }

        $detailIds = $receiptItems
            ->filter(fn ($item) => (float) $item->quantity_defective > 0)
            ->pluck('transfer_detail_id')
            ->map(fn ($id) => (int) $id)
            ->unique()
            ->values();

        if ($detailIds->isEmpty()) return;

        $rows = DB::table('transfer_quarantine_stock')
            ->where('transfer_id', $transfer->id)
            ->whereIn('transfer_detail_id', $detailIds)
            ->where('quantity', '>', 0)
            ->orderBy('id')
            ->lockForUpdate()
            ->get();

        foreach ($rows as $row) {
            $exists = DB::table('damages')
                ->where('source_type', 'transfer_quarantine')
                ->where('source_id', $row->id)
                ->exists();
            if ($exists) continue;

            $createdAt = $row->created_at ? Carbon::parse($row->created_at) : now();
            $updatedAt = $row->updated_at ? Carbon::parse($row->updated_at) : $createdAt;

            $damageId = DB::table('damages')->insertGetId([
                'user_id' => $row->created_by_user_id ?: $user->id,
                'date' => $createdAt->toDateString(),
                'time' => $createdAt->format('H:i:s'),
                'Ref' => 'TR-DMG-'.$row->transfer_id.'-'.$row->id,
                // warehouse_id remains as the legacy compatibility owner. The UI resolves
                // the actual physical destination through transfers.to_inventory_location_id.
                'warehouse_id' => $row->warehouse_id,
                'items' => 1,
                'notes' => 'Daño registrado automáticamente durante la recepción de una transferencia.',
                'source_type' => 'transfer_quarantine',
                'source_id' => $row->id,
                'transfer_id' => $row->transfer_id,
                'source_locked' => 1,
                'created_at' => $createdAt,
                'updated_at' => $updatedAt,
            ]);

            DB::table('damage_details')->insert([
                'damage_id' => $damageId,
                'quantity' => $row->quantity,
                'product_id' => $row->product_id,
                'product_variant_id' => $row->product_variant_id,
                'created_at' => $createdAt,
                'updated_at' => $updatedAt,
            ]);
        }
    }

    protected function creditBatchStockIfApplicable(
        Transfer $transfer,
        TransferDetail $detail,
        float $quantity,
        TransferReceiptItem $receiptItem
    ): void {
        if (! $transfer->to_inventory_location_id || ! app(TransferBatchIssueService::class)->isSupported()) {
            parent::creditBatchStockIfApplicable($transfer, $detail, $quantity, $receiptItem);
            return;
        }

        $issueType = app()->bound('request')
            ? request()->attributes->get('prodex_transfer_batch_resolution_type')
            : null;

        if (in_array($issueType, ['missing', 'defective'], true)) {
            app(TransferBatchIssueService::class)->reclassifyToGood(
                $transfer,
                $detail,
                $quantity,
                $receiptItem,
                $issueType,
                (int) $transfer->to_inventory_location_id
            );
            return;
        }

        app(TransferBatchIssueService::class)->allocateGood(
            $transfer,
            $detail,
            $quantity,
            $receiptItem,
            (int) $transfer->to_inventory_location_id
        );
    }

    public function creditIssueResolution(
        Transfer $transfer,
        TransferDetail $detail,
        float $quantity,
        TransferReceiptItem $receiptItem,
        ?string $issueColumn = null
    ): void {
        if ($issueColumn === null && app()->bound('request')) {
            $type = request()->attributes->get('prodex_transfer_issue_type');
            $issueColumn = match ($type) {
                'missing' => 'quantity_missing',
                'defective' => 'quantity_defective',
                default => null,
            };
        }

        $issueType = match ($issueColumn) {
            'quantity_missing' => 'missing',
            'quantity_defective' => 'defective',
            default => null,
        };

        if (app()->bound('request') && $issueType) {
            request()->attributes->set('prodex_transfer_batch_resolution_type', $issueType);
        }

        try {
            parent::creditIssueResolution($transfer, $detail, $quantity, $receiptItem, $issueColumn);
        } finally {
            if (app()->bound('request')) {
                request()->attributes->remove('prodex_transfer_batch_resolution_type');
            }
        }
    }
}
