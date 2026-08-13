<?php

namespace App\Services\Zatca;

/**
 * Builds the unsigned UBL 2.1 invoice XML per the ZATCA "E-Invoice XML
 * Implementation Standard" (KSA-EN16931 customization).
 *
 * The document is generated WITHOUT ext:UBLExtensions, the QR
 * AdditionalDocumentReference and cac:Signature — exactly the node set the
 * ZATCA hashing transform removes — so the SHA-256 of this document's C14N11
 * form equals the invoice hash of the final signed document (the signer
 * injects those blocks without altering any surrounding whitespace).
 *
 * Output uses 4-space indentation matching the official ZATCA samples.
 */
class UblInvoiceXmlGenerator
{
    /**
     * @param array $inv Normalized invoice data (see SaleInvoiceMapper).
     */
    public function generate(array $inv): string
    {
        $c = $inv['currency'] ?? 'SAR';
        $x = '<?xml version="1.0" encoding="UTF-8"?>'."\n";
        $x .= '<Invoice xmlns="urn:oasis:names:specification:ubl:schema:xsd:Invoice-2" xmlns:cac="urn:oasis:names:specification:ubl:schema:xsd:CommonAggregateComponents-2" xmlns:cbc="urn:oasis:names:specification:ubl:schema:xsd:CommonBasicComponents-2" xmlns:ext="urn:oasis:names:specification:ubl:schema:xsd:CommonExtensionComponents-2">'."\n";

        $x .= $this->el(1, 'cbc:ProfileID', 'reporting:1.0');
        $x .= $this->el(1, 'cbc:ID', $inv['id']);
        $x .= $this->el(1, 'cbc:UUID', $inv['uuid']);
        $x .= $this->el(1, 'cbc:IssueDate', $inv['issue_date']);
        $x .= $this->el(1, 'cbc:IssueTime', $inv['issue_time']);

        $subtypeName = ($inv['subtype'] === 'standard' ? '01' : '02').'00000';
        $x .= $this->el(1, 'cbc:InvoiceTypeCode', $inv['type_code'], ['name' => $subtypeName]);

        if (! empty($inv['note'])) {
            $x .= $this->el(1, 'cbc:Note', $inv['note']);
        }

        $x .= $this->el(1, 'cbc:DocumentCurrencyCode', $c);
        $x .= $this->el(1, 'cbc:TaxCurrencyCode', 'SAR');

        if (! empty($inv['billing_reference'])) {
            $x .= $this->open(1, 'cac:BillingReference');
            $x .= $this->open(2, 'cac:InvoiceDocumentReference');
            $x .= $this->el(3, 'cbc:ID', $inv['billing_reference']);
            $x .= $this->close(2, 'cac:InvoiceDocumentReference');
            $x .= $this->close(1, 'cac:BillingReference');
        }

        // ICV — invoice counter value
        $x .= $this->open(1, 'cac:AdditionalDocumentReference');
        $x .= $this->el(2, 'cbc:ID', 'ICV');
        $x .= $this->el(2, 'cbc:UUID', (string) $inv['icv']);
        $x .= $this->close(1, 'cac:AdditionalDocumentReference');

        // PIH — previous invoice hash. The signer injects the QR document
        // reference and cac:Signature immediately after this block.
        $x .= $this->open(1, 'cac:AdditionalDocumentReference');
        $x .= $this->el(2, 'cbc:ID', 'PIH');
        $x .= $this->open(2, 'cac:Attachment');
        $x .= $this->el(3, 'cbc:EmbeddedDocumentBinaryObject', $inv['pih'], ['mimeCode' => 'text/plain']);
        $x .= $this->close(2, 'cac:Attachment');
        $x .= $this->close(1, 'cac:AdditionalDocumentReference');

        $x .= $this->party(1, 'cac:AccountingSupplierParty', $inv['supplier']);

        if (! empty($inv['customer'])) {
            $x .= $this->party(1, 'cac:AccountingCustomerParty', $inv['customer']);
        } else {
            // KSA-EN16931: the customer party element must exist even on
            // simplified invoices without buyer details.
            $x .= $this->open(1, 'cac:AccountingCustomerParty');
            $x .= $this->open(2, 'cac:Party');
            $x .= $this->open(3, 'cac:PartyLegalEntity');
            $x .= $this->el(4, 'cbc:RegistrationName', $inv['walk_in_name'] ?? 'Walk-in Customer');
            $x .= $this->close(3, 'cac:PartyLegalEntity');
            $x .= $this->close(2, 'cac:Party');
            $x .= $this->close(1, 'cac:AccountingCustomerParty');
        }

        if (! empty($inv['delivery_date'])) {
            $x .= $this->open(1, 'cac:Delivery');
            $x .= $this->el(2, 'cbc:ActualDeliveryDate', $inv['delivery_date']);
            $x .= $this->close(1, 'cac:Delivery');
        }

        $x .= $this->open(1, 'cac:PaymentMeans');
        $x .= $this->el(2, 'cbc:PaymentMeansCode', $inv['payment_means_code'] ?? '10');
        if (! empty($inv['instruction_note'])) {
            $x .= $this->el(2, 'cbc:InstructionNote', $inv['instruction_note']);
        }
        $x .= $this->close(1, 'cac:PaymentMeans');

        foreach (array_merge($inv['allowances'] ?? [], $inv['charges'] ?? []) as $ac) {
            $x .= $this->open(1, 'cac:AllowanceCharge');
            $x .= $this->el(2, 'cbc:ChargeIndicator', ! empty($ac['is_charge']) ? 'true' : 'false');
            $x .= $this->el(2, 'cbc:AllowanceChargeReason', $ac['reason'] ?? (! empty($ac['is_charge']) ? 'charge' : 'discount'));
            $x .= $this->el(2, 'cbc:Amount', $this->amt($ac['amount']), ['currencyID' => $c]);
            $x .= $this->taxCategory(2, 'cac:TaxCategory', $ac, true);
            $x .= $this->close(1, 'cac:AllowanceCharge');
        }

        // TaxTotal with subtotals (SAR), then bare TaxTotal (tax currency).
        $x .= $this->open(1, 'cac:TaxTotal');
        $x .= $this->el(2, 'cbc:TaxAmount', $this->amt($inv['totals']['tax_total']), ['currencyID' => 'SAR']);
        foreach ($inv['tax_subtotals'] as $st) {
            $x .= $this->open(2, 'cac:TaxSubtotal');
            $x .= $this->el(3, 'cbc:TaxableAmount', $this->amt($st['taxable']), ['currencyID' => $c]);
            $x .= $this->el(3, 'cbc:TaxAmount', $this->amt($st['tax']), ['currencyID' => $c]);
            $x .= $this->taxCategory(3, 'cac:TaxCategory', $st, true);
            $x .= $this->close(2, 'cac:TaxSubtotal');
        }
        $x .= $this->close(1, 'cac:TaxTotal');
        $x .= $this->open(1, 'cac:TaxTotal');
        $x .= $this->el(2, 'cbc:TaxAmount', $this->amt($inv['totals']['tax_total']), ['currencyID' => 'SAR']);
        $x .= $this->close(1, 'cac:TaxTotal');

        $t = $inv['totals'];
        $x .= $this->open(1, 'cac:LegalMonetaryTotal');
        $x .= $this->el(2, 'cbc:LineExtensionAmount', $this->amt($t['line_extension']), ['currencyID' => $c]);
        $x .= $this->el(2, 'cbc:TaxExclusiveAmount', $this->amt($t['tax_exclusive']), ['currencyID' => $c]);
        $x .= $this->el(2, 'cbc:TaxInclusiveAmount', $this->amt($t['tax_inclusive']), ['currencyID' => $c]);
        $x .= $this->el(2, 'cbc:AllowanceTotalAmount', $this->amt($t['allowance_total'] ?? 0), ['currencyID' => $c]);
        if (! empty($t['charge_total'])) {
            $x .= $this->el(2, 'cbc:ChargeTotalAmount', $this->amt($t['charge_total']), ['currencyID' => $c]);
        }
        $x .= $this->el(2, 'cbc:PrepaidAmount', $this->amt($t['prepaid'] ?? 0), ['currencyID' => $c]);
        if (isset($t['payable_rounding']) && abs((float) $t['payable_rounding']) > 0.0) {
            $x .= $this->el(2, 'cbc:PayableRoundingAmount', $this->amt($t['payable_rounding']), ['currencyID' => $c]);
        }
        $x .= $this->el(2, 'cbc:PayableAmount', $this->amt($t['payable']), ['currencyID' => $c]);
        $x .= $this->close(1, 'cac:LegalMonetaryTotal');

        foreach ($inv['lines'] as $i => $line) {
            $x .= $this->open(1, 'cac:InvoiceLine');
            $x .= $this->el(2, 'cbc:ID', (string) ($i + 1));
            $x .= $this->el(2, 'cbc:InvoicedQuantity', $this->qty($line['quantity']), ['unitCode' => $line['unit_code'] ?? 'PCE']);
            $x .= $this->el(2, 'cbc:LineExtensionAmount', $this->amt($line['net_amount']), ['currencyID' => $c]);
            $x .= $this->open(2, 'cac:TaxTotal');
            $x .= $this->el(3, 'cbc:TaxAmount', $this->amt($line['tax_amount']), ['currencyID' => $c]);
            $x .= $this->el(3, 'cbc:RoundingAmount', $this->amt($line['net_amount'] + $line['tax_amount']), ['currencyID' => $c]);
            $x .= $this->close(2, 'cac:TaxTotal');
            $x .= $this->open(2, 'cac:Item');
            $x .= $this->el(3, 'cbc:Name', $line['name']);
            $x .= $this->open(3, 'cac:ClassifiedTaxCategory');
            $x .= $this->el(4, 'cbc:ID', $line['tax_category']);
            $x .= $this->el(4, 'cbc:Percent', $this->amt($line['tax_rate']));
            $x .= $this->open(4, 'cac:TaxScheme');
            $x .= $this->el(5, 'cbc:ID', 'VAT');
            $x .= $this->close(4, 'cac:TaxScheme');
            $x .= $this->close(3, 'cac:ClassifiedTaxCategory');
            $x .= $this->close(2, 'cac:Item');
            $x .= $this->open(2, 'cac:Price');
            $x .= $this->el(3, 'cbc:PriceAmount', $this->amt($line['unit_price'], 6), ['currencyID' => $c]);
            if (! empty($line['discount']) && (float) $line['discount'] > 0) {
                $x .= $this->open(3, 'cac:AllowanceCharge');
                $x .= $this->el(4, 'cbc:ChargeIndicator', 'false');
                $x .= $this->el(4, 'cbc:AllowanceChargeReason', 'discount');
                $x .= $this->el(4, 'cbc:Amount', $this->amt($line['discount']), ['currencyID' => $c]);
                $x .= $this->close(3, 'cac:AllowanceCharge');
            }
            $x .= $this->close(2, 'cac:Price');
            $x .= $this->close(1, 'cac:InvoiceLine');
        }

        $x .= '</Invoice>';

        return $x;
    }

    /**
     * Supplier/customer party block.
     */
    protected function party(int $depth, string $wrapper, array $p): string
    {
        $x = $this->open($depth, $wrapper);
        $x .= $this->open($depth + 1, 'cac:Party');

        if (! empty($p['other_id'])) {
            $x .= $this->open($depth + 2, 'cac:PartyIdentification');
            $x .= $this->el($depth + 3, 'cbc:ID', $p['other_id'], ['schemeID' => $p['other_id_scheme'] ?? 'CRN']);
            $x .= $this->close($depth + 2, 'cac:PartyIdentification');
        }

        if (! empty($p['street']) || ! empty($p['city'])) {
            $x .= $this->open($depth + 2, 'cac:PostalAddress');
            if (! empty($p['street'])) {
                $x .= $this->el($depth + 3, 'cbc:StreetName', $p['street']);
            }
            if (! empty($p['building'])) {
                $x .= $this->el($depth + 3, 'cbc:BuildingNumber', $p['building']);
            }
            if (! empty($p['plot'])) {
                $x .= $this->el($depth + 3, 'cbc:PlotIdentification', $p['plot']);
            }
            if (! empty($p['sub_division'])) {
                $x .= $this->el($depth + 3, 'cbc:CitySubdivisionName', $p['sub_division']);
            }
            if (! empty($p['city'])) {
                $x .= $this->el($depth + 3, 'cbc:CityName', $p['city']);
            }
            if (! empty($p['postal'])) {
                $x .= $this->el($depth + 3, 'cbc:PostalZone', $p['postal']);
            }
            $x .= $this->open($depth + 3, 'cac:Country');
            $x .= $this->el($depth + 4, 'cbc:IdentificationCode', $p['country'] ?? 'SA');
            $x .= $this->close($depth + 3, 'cac:Country');
            $x .= $this->close($depth + 2, 'cac:PostalAddress');
        }

        if (! empty($p['vat'])) {
            $x .= $this->open($depth + 2, 'cac:PartyTaxScheme');
            $x .= $this->el($depth + 3, 'cbc:CompanyID', $p['vat']);
            $x .= $this->open($depth + 3, 'cac:TaxScheme');
            $x .= $this->el($depth + 4, 'cbc:ID', 'VAT');
            $x .= $this->close($depth + 3, 'cac:TaxScheme');
            $x .= $this->close($depth + 2, 'cac:PartyTaxScheme');
        }

        $x .= $this->open($depth + 2, 'cac:PartyLegalEntity');
        $x .= $this->el($depth + 3, 'cbc:RegistrationName', $p['name']);
        $x .= $this->close($depth + 2, 'cac:PartyLegalEntity');

        $x .= $this->close($depth + 1, 'cac:Party');
        $x .= $this->close($depth, $wrapper);

        return $x;
    }

    /**
     * TaxCategory element with UN/ECE scheme attributes and optional
     * exemption reason (required for categories other than S).
     */
    protected function taxCategory(int $depth, string $name, array $data, bool $schemeAttrs): string
    {
        $category = $data['tax_category'] ?? $data['category'] ?? 'S';
        $rate = $data['tax_rate'] ?? $data['rate'] ?? 15;

        $x = $this->open($depth, $name);
        $x .= $this->el(
            $depth + 1,
            'cbc:ID',
            $category,
            $schemeAttrs ? ['schemeID' => 'UN/ECE 5305', 'schemeAgencyID' => '6'] : []
        );
        $x .= $this->el($depth + 1, 'cbc:Percent', $this->amt($rate));
        if ($category !== 'S') {
            if (! empty($data['exemption_code'])) {
                $x .= $this->el($depth + 1, 'cbc:TaxExemptionReasonCode', $data['exemption_code']);
            }
            if (! empty($data['exemption_reason'])) {
                $x .= $this->el($depth + 1, 'cbc:TaxExemptionReason', $data['exemption_reason']);
            }
        }
        $x .= $this->open($depth + 1, 'cac:TaxScheme');
        $x .= $this->el(
            $depth + 2,
            'cbc:ID',
            'VAT',
            $schemeAttrs ? ['schemeID' => 'UN/ECE 5153', 'schemeAgencyID' => '6'] : []
        );
        $x .= $this->close($depth + 1, 'cac:TaxScheme');
        $x .= $this->close($depth, $name);

        return $x;
    }

    protected function el(int $depth, string $name, string $value, array $attrs = []): string
    {
        $a = '';
        foreach ($attrs as $k => $v) {
            $a .= ' '.$k.'="'.$this->esc((string) $v).'"';
        }

        return str_repeat('    ', $depth).'<'.$name.$a.'>'.$this->esc($value).'</'.$name.'>'."\n";
    }

    protected function open(int $depth, string $name): string
    {
        return str_repeat('    ', $depth).'<'.$name.'>'."\n";
    }

    protected function close(int $depth, string $name): string
    {
        return str_repeat('    ', $depth).'</'.$name.'>'."\n";
    }

    protected function esc(string $value): string
    {
        return htmlspecialchars($value, ENT_XML1 | ENT_QUOTES, 'UTF-8');
    }

    /** Format a monetary amount (2 decimals unless more precision requested). */
    protected function amt($value, int $decimals = 2): string
    {
        return number_format(round((float) $value, $decimals), $decimals, '.', '');
    }

    /** Format a quantity, trimming insignificant zeros (max 6 decimals). */
    protected function qty($value): string
    {
        $s = number_format((float) $value, 6, '.', '');
        $s = rtrim(rtrim($s, '0'), '.');

        return $s === '' ? '0' : $s;
    }
}
