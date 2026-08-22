<?php

namespace App\Services;

use App\Models\Transfer;
use App\Models\TransferDetail;
use App\Models\TransferReceiptItem;

/**
 * Final production transfer binding. The discrepancy controller exposes the
 * issue currently being resolved through a request attribute, allowing the
 * location-aware inventory layer to distinguish missing vs defective stock
 * without changing the legacy controller method contract.
 */
class FinalTransferLogisticsService extends LocationAwareTransferLogisticsService
{
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
