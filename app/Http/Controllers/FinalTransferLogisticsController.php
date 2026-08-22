<?php

namespace App\Http\Controllers;

use App\Models\Transfer;
use App\Models\TransferDetailSerial;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Schema;

class FinalTransferLogisticsController extends TransferLogisticsController
{
    public function incoming(Request $request)
    {
        return $this->augmentSummaryResponse(parent::incoming($request));
    }

    public function showByToken(Request $request, string $token)
    {
        return $this->augmentReceivingResponse(parent::showByToken($request, $token));
    }

    public function show(Request $request, int $id)
    {
        return $this->augmentReceivingResponse(parent::show($request, $id));
    }

    public function receive(Request $request, int $id)
    {
        return $this->augmentSummaryResponse(parent::receive($request, $id));
    }

    private function augmentReceivingResponse($response)
    {
        if (! $response instanceof JsonResponse) return $response;
        $data = $response->getData(true);
        $transferId = (int) ($data['transfer']['id'] ?? 0);
        if ($transferId <= 0) return $response;

        $transfer = Transfer::with(['fromInventoryLocation', 'toInventoryLocation'])->find($transferId);
        if ($transfer) {
            $data['transfer'] = $this->physicalSummary($data['transfer'] ?? [], $transfer);
        }

        if ($transfer && Schema::hasTable('transfer_detail_serials') && Schema::hasTable('product_serials')) {
            $detailIds = collect($data['details'] ?? [])->pluck('transfer_detail_id')->filter()->map(fn ($id) => (int) $id)->all();
            $serials = TransferDetailSerial::whereIn('transfer_detail_id', $detailIds)
                ->with('serial:id,serial_number')
                ->orderBy('id')
                ->get()
                ->groupBy('transfer_detail_id');

            foreach ($data['details'] as &$line) {
                $rows = $serials->get((int) ($line['transfer_detail_id'] ?? 0), collect());
                $manifest = $rows->map(fn (TransferDetailSerial $row) => [
                    'serial_number' => (string) optional($row->serial)->serial_number,
                    'status' => (string) $row->status,
                    'issue_type' => $row->issue_type,
                ])->filter(fn ($row) => $row['serial_number'] !== '')->values()->all();

                $line['serials'] = $manifest;
                if ($manifest) {
                    $numbers = collect($manifest)->pluck('serial_number')->implode(', ');
                    $line['code'] = trim((string) ($line['code'] ?? '').' · Serie/IMEI: '.$numbers);
                }
            }
            unset($line);
        }

        return response()->json($data, $response->getStatusCode());
    }

    private function augmentSummaryResponse($response)
    {
        if (! $response instanceof JsonResponse) return $response;
        $data = $response->getData(true);

        if (isset($data['transfer']['id'])) {
            $transfer = Transfer::with(['fromInventoryLocation', 'toInventoryLocation'])->find((int) $data['transfer']['id']);
            if ($transfer) $data['transfer'] = $this->physicalSummary($data['transfer'], $transfer);
        }

        if (isset($data['transfers']) && is_array($data['transfers'])) {
            $ids = collect($data['transfers'])->pluck('id')->filter()->map(fn ($id) => (int) $id)->all();
            $models = Transfer::with(['fromInventoryLocation', 'toInventoryLocation'])->whereIn('id', $ids)->get()->keyBy('id');
            foreach ($data['transfers'] as &$summary) {
                $transfer = $models->get((int) ($summary['id'] ?? 0));
                if ($transfer) $summary = $this->physicalSummary($summary, $transfer);
            }
            unset($summary);
        }

        return response()->json($data, $response->getStatusCode());
    }

    private function physicalSummary(array $summary, Transfer $transfer): array
    {
        if (! $transfer->from_inventory_location_id && ! $transfer->to_inventory_location_id) return $summary;

        $from = $transfer->fromInventoryLocation;
        $to = $transfer->toInventoryLocation;
        $summary['from_inventory_location_id'] = $transfer->from_inventory_location_id ? (int) $transfer->from_inventory_location_id : null;
        $summary['to_inventory_location_id'] = $transfer->to_inventory_location_id ? (int) $transfer->to_inventory_location_id : null;
        $summary['from_inventory_location'] = $from?->name;
        $summary['to_inventory_location'] = $to?->name;
        $summary['legacy_from_warehouse'] = $summary['from_warehouse'] ?? null;
        $summary['legacy_to_warehouse'] = $summary['to_warehouse'] ?? null;

        if ($from) $summary['from_warehouse'] = $from->name;
        if ($to) $summary['to_warehouse'] = $to->name;

        return $summary;
    }
}
