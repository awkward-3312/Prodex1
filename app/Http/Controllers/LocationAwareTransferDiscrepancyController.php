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

            // Capture the physical quarantine location before the legacy resolver
            // updates/splits its audit rows. The outer transaction makes the parent
            // resolution and the physical stock disposition one atomic operation.
            $quarantineLocationId = null;
            if ($transfer->to_inventory_location_id && $issue->type === 'defective') {
                $quarantineLocationId = DB::table('transfer_quarantine_stock')
                    ->where('transfer_id', $transfer->id)
                    ->where('transfer_detail_id', $detail->id)
                    ->where('status', 'quarantined')
                    ->whereNotNull('inventory_location_id')
                    ->orderBy('id')
                    ->value('inventory_location_id');
            }

            $response = parent::resolve($request, $id);

            if ($transfer->to_inventory_location_id) {
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
