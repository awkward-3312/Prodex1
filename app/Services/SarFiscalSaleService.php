<?php

namespace App\Services;

use App\Exceptions\SarFiscalException;
use App\Models\Sale;
use App\Models\SarAuthorization;
use App\Models\SarFiscalDocument;
use App\Models\SarFiscalProfile;
use App\Models\SarPointOfIssue;

class SarFiscalSaleService
{
    public function issueIfEnabled(Sale $sale, ?int $cashDrawerId = null): ?SarFiscalDocument
    {
        $profile = SarFiscalProfile::first();
        if (! $profile || ! $profile->enabled) {
            return null;
        }

        $point = $this->resolvePoint((int) $sale->warehouse_id, $cashDrawerId);
        $authorization = SarAuthorization::where('point_of_issue_id', $point->id)
            ->where('document_type', '01')
            ->where('status', 'active')
            ->orderBy('deadline')
            ->first();

        if (! $authorization) {
            throw new SarFiscalException('No existe una autorización SAR activa para el punto de emisión seleccionado.');
        }

        $sale->loadMissing(['client', 'saleDetails.product', 'warehouse']);
        $customer = $sale->client;

        if (! $customer) {
            throw new SarFiscalException('La venta fiscal requiere un cliente válido.');
        }

        // The SAR requires customer identification for transactions at or above
        // the regulatory threshold. Keep the check server-side so the POS cannot bypass it.
        if ((float) $sale->GrandTotal >= 10000 && trim((string) $customer->tax_number) === '') {
            throw new SarFiscalException('Para una venta de L 10,000 o más debes registrar el RTN del cliente.');
        }

        $customerSnapshot = [
            'id' => $customer->id,
            'name' => $customer->name,
            'rtn' => $customer->tax_number,
            'address' => $customer->adresse,
            'phone' => $customer->phone,
            'email' => $customer->email,
        ];

        $lines = $sale->saleDetails->map(function ($detail) {
            return [
                'product_id' => $detail->product_id,
                'code' => optional($detail->product)->code,
                'description' => optional($detail->product)->name,
                'quantity' => (float) $detail->quantity,
                'unit_price' => (float) $detail->price,
                'discount' => (float) $detail->discount,
                'tax' => (float) $detail->TaxNet,
                'tax_method' => $detail->tax_method,
                'total' => (float) $detail->total,
            ];
        })->values()->all();

        $saleSnapshot = [
            'sale_id' => $sale->id,
            'internal_reference' => $sale->Ref,
            'date' => (string) $sale->date,
            'time' => (string) $sale->time,
            'warehouse_id' => $sale->warehouse_id,
            'warehouse_name' => optional($sale->warehouse)->name,
            'cash_drawer_id' => $cashDrawerId,
            'tax_rate' => (float) $sale->tax_rate,
            'tax_total' => (float) $sale->TaxNet,
            'discount' => (float) $sale->discount,
            'shipping' => (float) $sale->shipping,
            'grand_total' => (float) $sale->GrandTotal,
            'lines' => $lines,
        ];

        return app(SarFiscalNumberService::class)->issue(
            $sale,
            $authorization->id,
            $customerSnapshot,
            $saleSnapshot
        );
    }

    private function resolvePoint(int $warehouseId, ?int $cashDrawerId): SarPointOfIssue
    {
        $query = SarPointOfIssue::where('active', true)
            ->where('warehouse_id', $warehouseId);

        if ($cashDrawerId) {
            $exact = (clone $query)->where('cash_drawer_id', $cashDrawerId)->get();
            if ($exact->count() > 1) {
                throw new SarFiscalException('Hay más de un punto SAR asignado a la misma caja.');
            }
            if ($exact->count() === 1) {
                return $exact->first();
            }
        }

        $defaults = $query->whereNull('cash_drawer_id')->get();
        if ($defaults->count() !== 1) {
            throw new SarFiscalException(
                $defaults->isEmpty()
                    ? 'No existe un punto SAR para el almacén y la caja seleccionados.'
                    : 'Hay más de un punto SAR predeterminado para este almacén.'
            );
        }

        return $defaults->first();
    }
}
