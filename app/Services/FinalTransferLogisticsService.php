<?php

namespace App\Services;

use App\Models\Transfer;
use App\Models\TransferDetail;
use App\Models\TransferReceiptItem;
use App\Models\User;

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

        parent::creditIssueResolution($transfer, $detail, $quantity, $receiptItem, $issueColumn);
    }
}
