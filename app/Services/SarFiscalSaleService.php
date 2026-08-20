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

        $storedExemption = is_array($sale->fiscal_exemption_data) ? $sale->fiscal_exemption_data : [];
        $requestExemption = request()->input('fiscal_exemption_data', []);
        $requestExemption = is_array($requestExemption) ? array_filter($requestExemption, fn ($value) => trim((string) $value) !== '') : [];
        $exemptionData = ! empty($storedExemption) ? $storedExemption : $requestExemption;

        if (! empty($exemptionData) && empty($storedExemption)) {
            $sale->forceFill(['fiscal_exemption_data' => $exemptionData])->saveQuietly();
        }

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

        $cartSubtotal = round((float) $sale->saleDetails->sum(fn ($detail) => max(0.0, (float) $detail->total)), 2);
        $saleDiscounts = $this->saleLevelDiscounts($sale, $cartSubtotal);
        $globalTaxRate = max(0.0, (float) $sale->tax_rate);

        $totals = [
            'product_discount_total' => 0.0,
            'manual_discount' => $saleDiscounts['manual'],
            'points_discount' => $saleDiscounts['points'],
            'promotion_discount' => $saleDiscounts['promotion'],
            'sale_level_discount_total' => $saleDiscounts['total'],
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

        $lines = $sale->saleDetails->map(function ($detail) use (&$totals, $cartSubtotal, $saleDiscounts, $globalTaxRate) {
            $quantity = (float) $detail->quantity;
            $unitPrice = (float) $detail->price;
            $storedLineTotal = max(0.0, (float) $detail->total);
            $productRate = $detail->fiscal_tax_rate !== null
                ? max(0.0, (float) $detail->fiscal_tax_rate)
                : max(0.0, (float) $detail->TaxNet);

            $rawCategory = $detail->fiscal_tax_category ?? optional($detail->product)->fiscal_tax_category;
            $explicitCategory = trim((string) $rawCategory) !== '';
            $fallbackRate = $explicitCategory ? $productRate : ($globalTaxRate > 0 ? $globalTaxRate : $productRate);
            $category = $this->normalizeCategory($rawCategory, $fallbackRate);

            $usesLineTax = $explicitCategory || $detail->fiscal_tax_rate !== null;
            $rate = $category === 'taxed'
                ? ($usesLineTax ? $productRate : ($globalTaxRate > 0 ? $globalTaxRate : $productRate))
                : 0.0;

            $allocatedSaleDiscount = $cartSubtotal > 0
                ? round($saleDiscounts['total'] * ($storedLineTotal / $cartSubtotal), 2)
                : 0.0;
            $discountedStoredTotal = max(0.0, round($storedLineTotal - $allocatedSaleDiscount, 2));

            if ($category === 'taxed' && $rate > 0) {
                if (! $usesLineTax && $globalTaxRate > 0) {
                    $taxableAmount = $discountedStoredTotal;
                    $taxAmount = round($taxableAmount * ($rate / 100), 2);
                    $lineTotal = round($taxableAmount + $taxAmount, 2);
                } else {
                    $taxableAmount = round($discountedStoredTotal / (1 + ($rate / 100)), 2);
                    $taxAmount = round($discountedStoredTotal - $taxableAmount, 2);
                    $lineTotal = $discountedStoredTotal;
                }
            } else {
                $taxableAmount = $discountedStoredTotal;
                $taxAmount = 0.0;
                $lineTotal = $discountedStoredTotal;
            }

            $productDiscount = $this->detailDiscountAmount($detail, $quantity, $unitPrice);
            $totals['product_discount_total'] += $productDiscount;

            if ($detail->fiscal_tax_category === null || $detail->fiscal_tax_rate === null) {
                $detail->forceFill([
                    'fiscal_tax_category' => $category,
                    'fiscal_tax_rate' => round($rate, 2),
                ])->saveQuietly();
            }

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
                'unit_price' => round($unitPrice, 2),
                'product_discount' => round($productDiscount, 2),
                'allocated_sale_discount' => round($allocatedSaleDiscount, 2),
                'tax_category' => $category,
                'tax_rate' => round($rate, 2),
                'taxable_amount' => round($taxableAmount, 2),
                'tax_amount' => round($taxAmount, 2),
                'tax_method' => $detail->tax_method,
                'line_total' => round($lineTotal, 2),
            ];
        })->values()->all();

        foreach ($totals as $key => $value) {
            $totals[$key] = round((float) $value, 2);
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
        $totals['discount_total'] = round($totals['product_discount_total'] + $totals['sale_level_discount_total'], 2);
        $totals['shipping'] = round((float) $sale->shipping, 2);
        $totals['grand_total'] = round((float) $sale->GrandTotal, 2);

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

        return $rate > 0 ? 'taxed' : 'exempt';
    }

    private function saleLevelDiscounts(Sale $sale, float $cartSubtotal): array
    {
        $discountValue = max(0.0, (float) $sale->discount);
        $discountMethod = strtolower(trim((string) $sale->discount_Method));
        $manual = ($discountMethod === '1' || $discountMethod === 'percent' || $discountMethod === 'percentage')
            ? round($cartSubtotal * ($discountValue / 100), 2)
            : min($discountValue, $cartSubtotal);

        $remaining = max(0.0, $cartSubtotal - $manual);
        $points = min(max(0.0, (float) $sale->discount_from_points), $remaining);
        $remaining = max(0.0, $remaining - $points);
        $promotion = min(max(0.0, (float) $sale->promotion_discount), $remaining);

        return [
            'manual' => round($manual, 2),
            'points' => round($points, 2),
            'promotion' => round($promotion, 2),
            'total' => round($manual + $points + $promotion, 2),
        ];
    }

    private function detailDiscountAmount($detail, float $quantity, float $unitPrice): float
    {
        $discount = max(0.0, (float) $detail->discount);
        $method = strtolower(trim((string) $detail->discount_method));
        $perUnit = ($method === '1' || $method === 'percent' || $method === 'percentage')
            ? $unitPrice * ($discount / 100)
            : $discount;

        return round(max(0.0, $perUnit * $quantity), 2);
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
