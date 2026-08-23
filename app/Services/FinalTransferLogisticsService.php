<?php

namespace App\Services;

use App\Models\Product;
use App\Models\Transfer;
use App\Models\TransferDetail;
use App\Models\TransferReceiptItem;
use App\Models\User;
use Illuminate\Support\Facades\DB;

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

            if (! $transfer->to_inventory_location_id || ! app(TransferBatchIssueService::class)->isSupported()) {
                return $updated;
            }

            $receipt = DB::table('transfer_receipts')
                ->where('transfer_id', $transfer->id)
                ->when($requestToken, fn ($query) => $query->where('request_token', $requestToken))
                ->when(! $requestToken, fn ($query) => $query->where('received_by_user_id', $user->id))
                ->orderByDesc('id')
                ->first();
            if (! $receipt) return $updated;

            $receiptItems = TransferReceiptItem::where('transfer_receipt_id', $receipt->id)
                ->orderBy('id')->lockForUpdate()->get();

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
