<?php

namespace App\Services\Zatca;

use App\Models\Sale;
use App\Models\SaleReturn;
use App\Models\Setting;
use App\Models\ZatcaSetting;
use Carbon\Carbon;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Str;
use RuntimeException;

/**
 * Maps the application's business documents (Sale = tax invoice 388,
 * SaleReturn = credit note 381) to the normalized structure consumed by
 * UblInvoiceXmlGenerator, applying the KSA-EN16931 calculation rules:
 *
 *  - line net / VAT derived from the stored unit price, discount and tax
 *    method (exclusive / inclusive);
 *  - the document-level discount (entered VAT-inclusive in the app) is
 *    converted to a net allowance against the dominant VAT category;
 *  - shipping is emitted as a standard-rated document-level charge
 *    (treated as VAT-inclusive);
 *  - any residual difference against the charged GrandTotal is emitted as
 *    cbc:PayableRoundingAmount so PayableAmount matches what the customer
 *    actually paid.
 */
class SaleInvoiceMapper
{
    /**
     * @param Sale|SaleReturn $source
     */
    public function map(Model $source, ZatcaSetting $zatca, Setting $setting, int $icv, string $pih): array
    {
        $isReturn = $source instanceof SaleReturn;
        $source->loadMissing(['details.product', 'client']);

        $client = $source->client;
        $subtype = $this->resolveSubtype($source, $zatca);

        $lines = [];
        $totalLineNet = 0.0;
        $groups = []; // "category|rate" => ['taxable' =>, 'category' =>, 'rate' =>]

        foreach ($source->details as $detail) {
            $qty = (float) $detail->quantity;
            if ($qty <= 0) {
                continue;
            }
            $rate = (float) ($detail->TaxNet ?? 0);
            $price = (float) $detail->price;
            $unitDiscount = ((string) ($detail->discount_method ?? '2')) === '1'
                ? $price * (float) ($detail->discount ?? 0) / 100
                : (float) ($detail->discount ?? 0);

            $unitGross = max(0.0, $price - $unitDiscount);

            // tax_method '1' = exclusive (price excludes VAT), '2' = inclusive
            $unitNet = ((string) ($detail->tax_method ?? '1')) === '2'
                ? $unitGross / (1 + $rate / 100)
                : $unitGross;

            $lineNet = round($unitNet * $qty, 2);
            $lineVat = round($lineNet * $rate / 100, 2);
            $category = $rate > 0 ? 'S' : 'Z';

            $name = $detail->product->name ?? ('Item #'.$detail->product_id);
            if (! empty($detail->pack_name)) {
                $name .= ' ('.$detail->pack_name.')';
            }

            $lines[] = [
                'name' => $name,
                'quantity' => $qty,
                'unit_code' => 'PCE',
                'unit_price' => $unitNet,
                'discount' => 0,
                'net_amount' => $lineNet,
                'tax_amount' => $lineVat,
                'tax_category' => $category,
                'tax_rate' => $rate,
            ];

            $totalLineNet += $lineNet;
            $key = $category.'|'.number_format($rate, 2);
            $groups[$key] = [
                'category' => $category,
                'rate' => $rate,
                'taxable' => ($groups[$key]['taxable'] ?? 0) + $lineNet,
            ];
        }

        if (empty($lines)) {
            throw new RuntimeException('Document has no billable lines.');
        }

        // Dominant category absorbs document-level allowance/charge.
        uasort($groups, fn ($a, $b) => $b['taxable'] <=> $a['taxable']);
        $dominantKey = array_key_first($groups);
        $dominantRate = (float) $groups[$dominantKey]['rate'];
        $dominantCategory = $groups[$dominantKey]['category'];

        // Document discount: stored gross (VAT-inclusive); percent variant is
        // a percentage of the item lines' gross total.
        $grossLines = (float) $source->details->sum('total');
        $discountGross = ((string) ($source->discount_Method ?? '2')) === '1'
            ? $grossLines * (float) ($source->discount ?? 0) / 100
            : (float) ($source->discount ?? 0);
        $allowanceNet = $discountGross > 0 ? round($discountGross / (1 + $dominantRate / 100), 2) : 0.0;

        $shippingGross = (float) ($source->shipping ?? 0);
        $chargeNet = $shippingGross > 0 ? round($shippingGross / (1 + $dominantRate / 100), 2) : 0.0;

        $allowances = [];
        $charges = [];
        $zeroTax = [
            'exemption_code' => $zatca->zero_tax_reason_code ?: 'VATEX-SA-32',
            'exemption_reason' => $zatca->zero_tax_reason ?: 'Export of goods',
        ];
        if ($allowanceNet > 0) {
            $allowances[] = array_merge([
                'amount' => $allowanceNet,
                'reason' => 'Discount',
                'tax_category' => $dominantCategory,
                'tax_rate' => $dominantRate,
            ], $dominantCategory === 'Z' ? $zeroTax : []);
            $groups[$dominantKey]['taxable'] -= $allowanceNet;
        }
        if ($chargeNet > 0) {
            $charges[] = array_merge([
                'amount' => $chargeNet,
                'reason' => 'Shipping',
                'is_charge' => true,
                'tax_category' => $dominantCategory,
                'tax_rate' => $dominantRate,
            ], $dominantCategory === 'Z' ? $zeroTax : []);
            $groups[$dominantKey]['taxable'] += $chargeNet;
        }

        $taxSubtotals = [];
        $taxTotal = 0.0;
        foreach ($groups as $g) {
            $taxable = round($g['taxable'], 2);
            $tax = round($taxable * $g['rate'] / 100, 2);
            $taxTotal += $tax;
            $taxSubtotals[] = array_merge([
                'taxable' => $taxable,
                'tax' => $tax,
                'category' => $g['category'],
                'rate' => $g['rate'],
            ], $g['category'] === 'Z' ? $zeroTax : []);
        }
        $taxTotal = round($taxTotal, 2);

        $taxExclusive = round($totalLineNet - $allowanceNet + $chargeNet, 2);
        $taxInclusive = round($taxExclusive + $taxTotal, 2);

        // Reconcile against the amount actually charged.
        $grandTotal = round((float) $source->GrandTotal, 2);
        $rounding = round($grandTotal - $taxInclusive, 2);
        if (abs($rounding) > 0.99) {
            $rounding = 0.0;
        }
        $payable = round($taxInclusive + $rounding, 2);

        $issueDate = Carbon::parse((string) $source->date)->format('Y-m-d');
        $issueTime = $source->time ? Carbon::parse((string) $source->time)->format('H:i:s') : '00:00:00';

        $supplier = [
            'name' => $setting->CompanyName ?: 'Company',
            'vat' => (string) $setting->vat_number,
            'other_id' => $zatca->crn ?: null,
            'other_id_scheme' => 'CRN',
            'street' => $zatca->street_name ?: ($setting->CompanyAdress ?: 'N/A'),
            'building' => $zatca->building_number ?: null,
            'plot' => $zatca->plot_identification ?: null,
            'sub_division' => $zatca->sub_division ?: null,
            'city' => $zatca->city ?: 'Riyadh',
            'postal' => $zatca->postal_zone ?: null,
            'country' => strtoupper($zatca->country_code ?: 'SA'),
        ];

        $customer = null;
        if ($client) {
            $clientVat = preg_replace('/\D/', '', (string) ($client->tax_number ?? ''));
            $hasVat = preg_match('/^3\d{13}3$/', $clientVat) === 1;
            if ($subtype === 'standard' || $hasVat || ! $this->isWalkIn($client)) {
                $customer = [
                    'name' => $client->name ?: 'Customer',
                    'vat' => $hasVat ? $clientVat : null,
                    'other_id' => $hasVat ? null : ($client->code ? (string) $client->code : (string) $client->id),
                    'other_id_scheme' => 'OTH',
                    'street' => $client->adresse ?: null,
                    'sub_division' => $client->state ?: null,
                    'city' => $client->city ?: null,
                    'postal' => $client->zip ?: null,
                    'country' => 'SA',
                ];
            }
        }

        $typeCode = $isReturn ? '381' : '388';
        $billingReference = null;
        $instructionNote = null;
        if ($isReturn) {
            $originalRef = $source->sale->Ref ?? $source->sale_id;
            $billingReference = (string) $originalRef;
            $instructionNote = 'Return of goods';
        }

        return [
            'id' => (string) $source->Ref,
            'uuid' => $this->documentUuid($source),
            'issue_date' => $issueDate,
            'issue_time' => $issueTime,
            'type_code' => $typeCode,
            'subtype' => $subtype,
            'icv' => $icv,
            'pih' => $pih,
            'currency' => 'SAR',
            'billing_reference' => $billingReference,
            'instruction_note' => $instructionNote,
            'payment_means_code' => $isReturn ? '10' : $this->paymentMeansCode($source),
            'supplier' => $supplier,
            'customer' => $customer,
            'delivery_date' => $subtype === 'standard' ? $issueDate : null,
            'allowances' => $allowances,
            'charges' => $charges,
            'lines' => $lines,
            'tax_subtotals' => $taxSubtotals,
            'totals' => [
                'line_extension' => round($totalLineNet, 2),
                'tax_exclusive' => $taxExclusive,
                'tax_inclusive' => $taxInclusive,
                'allowance_total' => $allowanceNet,
                'charge_total' => $chargeNet,
                'prepaid' => 0,
                'payable_rounding' => $rounding,
                'payable' => $payable,
                'tax_total' => $taxTotal,
            ],
            // QR metadata
            'seller_name' => $setting->company_name_ar ?: ($setting->CompanyName ?: 'Company'),
            'vat_number' => (string) $setting->vat_number,
            'issue_datetime' => $issueDate.'T'.$issueTime.'Z',
            'total_with_vat' => $payable,
            'vat_amount' => $taxTotal,
        ];
    }

    /**
     * Standard (0100000, cleared) vs simplified (0200000, reported).
     * Honors the functionality map declared during onboarding; otherwise
     * POS / walk-in / non-VAT buyers get simplified invoices.
     */
    public function resolveSubtype(Model $source, ZatcaSetting $zatca): string
    {
        $types = (string) ($zatca->invoice_types ?: '1100');
        $supportsStandard = ($types[0] ?? '1') === '1';
        $supportsSimplified = ($types[1] ?? '1') === '1';

        if (! $supportsSimplified) {
            return 'standard';
        }
        if (! $supportsStandard) {
            return 'simplified';
        }

        if ((int) ($source->is_pos ?? 0) === 1) {
            return 'simplified';
        }

        $clientVat = preg_replace('/\D/', '', (string) ($source->client->tax_number ?? ''));

        return preg_match('/^3\d{13}3$/', $clientVat) ? 'standard' : 'simplified';
    }

    /**
     * Stable UUID per business document (kept on the ZatcaDocument row; the
     * sale_uuid column seeds it when present).
     */
    protected function documentUuid(Model $source): string
    {
        if (! empty($source->sale_uuid) && Str::isUuid((string) $source->sale_uuid)) {
            return (string) $source->sale_uuid;
        }

        return (string) Str::uuid();
    }

    /**
     * UNTDID 4461: 10 cash, 30 credit, 48 bank card, 42 bank account.
     */
    protected function paymentMeansCode(Model $source): string
    {
        return ((string) ($source->payment_statut ?? '')) === 'paid' ? '10' : '30';
    }

    protected function isWalkIn($client): bool
    {
        $name = mb_strtolower(trim((string) ($client->name ?? '')));

        return in_array($name, ['walk-in customer', 'walk in customer', 'walkin customer', ''], true);
    }
}
