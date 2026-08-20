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
    private const TAX_CATEGORIES = ['taxed', 'exempt', 'exonerated', 'zero_rate'];

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

        $rtn = trim((string) $customer->tax_number);
        $identificationNumber = trim((string) ($customer->identification_number ?? ''));
        if ((float) $sale->GrandTotal >= 10000 && $rtn === '' && $identificationNumber === '') {
            throw new SarFiscalException('Para una venta de L 10,000 o más debes registrar el RTN o documento de identificación del cliente.');
        }

        $exemptionData = is_array($sale->fiscal_exemption_data) ? $sale->fiscal_exemption_data : [];
        $customerSnapshot = [
            'id' => $customer->id,
            'name' => $customer->name,
            'rtn' => $rtn,
            'identification_type' => $customer->identification_type ?? null,
            'identification_number' => $customer->identification_number ?? null,
            'address' => $customer->adresse,
            'phone' => $customer->phone,
            'email' => $customer->email,
            'sar_registry_number' => $exemptionData['sar_registry_number'] ?? $customer->sar_registry_number ?? null,
            'exoneration_registry_number' => $exemptionData['exoneration_registry_number'] ?? $customer->exoneration_registry_number ?? null,
            'exempt_purchase_order_number' => $exemptionData['exempt_purchase_order_number'] ?? null,
            'exonerated_card_number' => $exemptionData['exonerated_card_number'] ?? null,
        ];

        $totals = [
            'discount_total' => 0.0,
            'exempt_amount' => 0.0,
            'exonerated_amount' => 0.0,
            'zero_rate_amount' => 0.0,
            'taxable_15_amount' => 0.0,
            'tax_15_amount' => 0.0,
            'taxable_18_amount' => 0.0,
            'tax_18_amount' => 0.0,
            'other_taxable_amount' => 0.0,
            'other_tax_amount' => 0.0,
        ];

        $lines = $sale->saleDetails->map(function ($detail) use (&$totals) {
            $quantity = (float) $detail->quantity;
            $unitPrice = (float) $detail->price;
            $lineTotal = (float) $detail->total;
            $taxAmount = (float) $detail->TaxNet;
            $discountAmount = (float) $detail->discount;
            $rate = $detail->fiscal_tax_rate !== null
                ? (float) $detail->fiscal_tax_rate
                : $this->inferRate($detail);
            $category = $this->normalizeCategory(
                $detail->fiscal_tax_category ?? optional($detail->product)->fiscal_tax_category,
                $rate
            );

            $taxableAmount = $this->taxableAmount($lineTotal, $taxAmount, $rate, $detail->tax_method, $category);
            $totals['discount_total'] += $discountAmount;

            if ($category === 'exempt') {
                $totals['exempt_amount'] += $taxableAmount;
            } elseif ($category === 'exonerated') {
                $totals['exonerated_amount'] += $taxableAmount;
            } elseif ($category === 'zero_rate') {
                $totals['zero_rate_amount'] += $taxableAmount;
            } elseif (abs($rate - 15.0) < 0.001) {
                $totals['taxable_15_amount'] += $taxableAmount;
                $totals['tax_15_amount'] += $taxAmount;
            } elseif (abs($rate - 18.0) < 0.001) {
                $totals['taxable_18_amount'] += $taxableAmount;
                $totals['tax_18_amount'] += $taxAmount;
            } else {
                $totals['other_taxable_amount'] += $taxableAmount;
                $totals['other_tax_amount'] += $taxAmount;
            }

            return [
                'product_id' => $detail->product_id,
                'code' => optional($detail->product)->code,
                'description' => optional($detail->product)->name,
                'quantity' => $quantity,
                'unit_price' => $unitPrice,
                'discount' => $discountAmount,
                'tax_category' => $category,
                'tax_rate' => round($rate, 2),
                'taxable_amount' => round($taxableAmount, 2),
                'tax_amount' => round($taxAmount, 2),
                'tax_method' => $detail->tax_method,
                'line_total' => round($lineTotal, 2),
            ];
        })->values()->all();

        foreach ($totals as $key => $value) {
            $totals[$key] = round($value, 2);
        }
        $totals['subtotal'] = round(
            $totals['exempt_amount'] + $totals['exonerated_amount'] + $totals['zero_rate_amount']
            + $totals['taxable_15_amount'] + $totals['taxable_18_amount'] + $totals['other_taxable_amount'],
            2
        );
        $totals['tax_total'] = round(
            $totals['tax_15_amount'] + $totals['tax_18_amount'] + $totals['other_tax_amount'],
            2
        );
        $totals['shipping'] = round((float) $sale->shipping, 2);
        $totals['sale_discount'] = round((float) $sale->discount, 2);
        $totals['grand_total'] = round((float) $sale->GrandTotal, 2);

        $saleSnapshot = [
            'sale_id' => $sale->id,
            'internal_reference' => $sale->Ref,
            'date' => (string) $sale->date,
            'time' => (string) $sale->time,
            'warehouse_id' => $sale->warehouse_id,
            'warehouse_name' => optional($sale->warehouse)->name,
            'cash_drawer_id' => $cashDrawerId,
            // Legacy summary fields retained for backwards compatibility.
            'tax_rate' => (float) $sale->tax_rate,
            'tax_total' => (float) $sale->TaxNet,
            'discount' => (float) $sale->discount,
            'shipping' => (float) $sale->shipping,
            'grand_total' => (float) $sale->GrandTotal,
            // New normalized fiscal representation used by every invoice renderer.
            'fiscal_totals' => $totals,
            'exemption_data' => $exemptionData,
            'lines' => $lines,
        ];

        return app(SarFiscalNumberService::class)->issue(
            $sale,
            $authorization->id,
            $customerSnapshot,
            $saleSnapshot
        );
    }

    private function normalizeCategory(?string $category, float $rate): string
    {
        $category = strtolower(trim((string) $category));
        if (in_array($category, self::TAX_CATEGORIES, true)) {
            return $category;
        }

        // Backwards-compatible default for products created before fiscal classification existed.
        return $rate > 0 ? 'taxed' : 'exempt';
    }

    private function inferRate($detail): float
    {
        $productTax = optional($detail->product)->TaxNet;
        if ($productTax !== null && is_numeric($productTax)) {
            return (float) $productTax;
        }

        $lineTotal = (float) $detail->total;
        $taxAmount = (float) $detail->TaxNet;
        if ($taxAmount > 0 && $lineTotal > $taxAmount) {
            $base = $lineTotal - $taxAmount;
            return $base > 0 ? round(($taxAmount / $base) * 100, 2) : 0.0;
        }

        return 0.0;
    }

    private function taxableAmount(float $lineTotal, float $taxAmount, float $rate, $taxMethod, string $category): float
    {
        if ($category !== 'taxed' || $rate <= 0) {
            return max(0.0, $lineTotal);
        }

        $inclusive = (string) $taxMethod === '2' || strtolower((string) $taxMethod) === 'inclusive';
        if ($inclusive) {
            return max(0.0, $lineTotal - $taxAmount);
        }

        // Existing sale details normally store total including TaxNet; subtracting the frozen
        // tax amount keeps the fiscal base tied to the exact historical sale calculation.
        return max(0.0, $lineTotal - $taxAmount);
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
