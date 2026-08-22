<?php

namespace App\Http\Controllers;

use App\Models\Transfer;
use App\Models\TransferDetail;
use App\Services\TransferIssueLocationResolutionService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class LocationAwareTransferDiscrepancyController extends TransferDiscrepancyController
{
    public function resolve(Request $request, int $id)
    {
        return DB::transaction(function () use ($request, $id) {
            $issue = DB::table('transfer_discrepancies')->where('id', $id)->lockForUpdate()->first();
            abort_unless($issue, 404);

            $transfer = Transfer::whereNull('deleted_at')->findOrFail($issue->transfer_id);
            $detail = TransferDetail::findOrFail($issue->transfer_detail_id);
            $resolutionCode = (string) $request->input('resolution_code');

            $request->attributes->set('prodex_transfer_issue_type', (string) $issue->type);
            $request->attributes->set('prodex_transfer_issue_id', (int) $issue->id);

            $quarantineLocationId = null;
            $beforeQuarantineMaxId = 0;
            if ($transfer->to_inventory_location_id && $issue->type === 'defective') {
                $quarantineLocationId = DB::table('transfer_quarantine_stock')
                    ->where('transfer_id', $transfer->id)
                    ->where('transfer_detail_id', $detail->id)
                    ->where('status', 'quarantined')
                    ->whereNotNull('inventory_location_id')
                    ->orderBy('id')
                    ->value('inventory_location_id');

                $beforeQuarantineMaxId = (int) (DB::table('transfer_quarantine_stock')->max('id') ?? 0);
            }

            $response = parent::resolve($request, $id);

            if ($transfer->to_inventory_location_id) {
                // The legacy resolver may split one quarantine audit row. Its insert
                // predates inventory_location_id, so restore that physical context on
                // only the newly-created split rows before final stock disposition.
                if ($quarantineLocationId && $beforeQuarantineMaxId > 0) {
                    DB::table('transfer_quarantine_stock')
                        ->where('id', '>', $beforeQuarantineMaxId)
                        ->where('transfer_id', $transfer->id)
                        ->where('transfer_detail_id', $detail->id)
                        ->whereNull('inventory_location_id')
                        ->update([
                            'inventory_location_id' => (int) $quarantineLocationId,
                            'updated_at' => now(),
                        ]);
                }

                app(TransferIssueLocationResolutionService::class)->apply(
                    $issue,
                    $transfer,
                    $detail,
                    $resolutionCode,
                    optional($request->user('api'))->id,
                    $quarantineLocationId ? (int) $quarantineLocationId : null
                );
            }

            return $response;
        }, 5);
    }
}
