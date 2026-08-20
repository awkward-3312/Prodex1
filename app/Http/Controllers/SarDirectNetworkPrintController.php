<?php

namespace App\Http\Controllers;

use App\Models\PosSetting;
use App\Models\Sale;
use App\Services\SpanishMoneyWords;
use Illuminate\Http\Request;

class SarDirectNetworkPrintController extends BaseController
{
    public function print(Request $request, $id)
    {
        $sale = Sale::with(['sarFiscalDocument.authorization.pointOfIssue', 'client', 'warehouse'])
            ->whereNull('deleted_at')
            ->findOrFail($id);

        $this->authorizeForUser($request->user('api'), 'view', Sale::class);

        if (! $sale->sarFiscalDocument) {
            return app(SalesController::class)->Direct_Network_Print_POS($request, $id);
        }

        $pos = PosSetting::whereNull('deleted_at')->first();
        if (! $pos || ! $pos->direct_network_printing) {
            return response()->json(['success' => false, 'message' => 'Direct Network Printing is disabled.'], 422);
        }

        $ip = trim((string) ($pos->network_printer_ip ?? ''));
        $port = (int) ($pos->network_printer_port ?? 9100);
        if ($ip === '' || $port < 1 || $port > 65535) {
            return response()->json(['success' => false, 'message' => 'Direct network printer IP/port is not configured.'], 422);
        }

        $doc = $sale->sarFiscalDocument;
        $issuer = (array) $doc->issuer_snapshot;
        $customer = (array) $doc->customer_snapshot;
        $snap = (array) $doc->sale_snapshot;
        $totals = (array) ($snap['fiscal_totals'] ?? []);
        $settings = array_merge([
            'document_title' => 'FACTURA',
            'sale_type_label' => 'CONTADO',
            'website' => '',
            'footer_message' => 'Gracias por su compra.',
            'original_label' => 'Original: Cliente',
            'copy_label' => 'Copia: Obligado Tributario Emisor',
            'show_internal_reference' => true,
            'show_cashier' => true,
            'show_warehouse' => true,
            'show_payment_summary' => true,
            'show_total_in_words' => true,
        ], (array) ($issuer['invoice_settings'] ?? []));

        $paper = (int) ($pos->receipt_paper_size ?? 80);
        $width = $paper === 58 ? 32 : ($paper === 88 ? 52 : 48);

        $normalizeAlign = static function ($value, $fallback) {
            $value = strtolower(trim((string) $value));
            return in_array($value, ['left', 'center', 'right'], true) ? $value : $fallback;
        };

        $headerAlign = $normalizeAlign($pos->receipt_header_alignment ?? null, 'center');
        $fiscalAlign = $normalizeAlign($pos->receipt_fiscal_alignment ?? null, 'center');
        $customerAlign = $normalizeAlign($pos->receipt_customer_alignment ?? null, 'left');
        $itemsAlign = $normalizeAlign($pos->receipt_items_alignment ?? null, 'left');
        $totalsAlign = $normalizeAlign($pos->receipt_totals_alignment ?? null, 'right');
        $footerAlign = $normalizeAlign($pos->receipt_footer_alignment ?? null, 'center');

        $alignText = static function ($text, $alignment) use ($width) {
            $text = substr((string) $text, 0, $width);
            if ($alignment === 'center') {
                return str_pad($text, $width, ' ', STR_PAD_BOTH);
            }
            if ($alignment === 'right') {
                return str_pad($text, $width, ' ', STR_PAD_LEFT);
            }
            return str_pad($text, $width, ' ', STR_PAD_RIGHT);
        };

        $pair = static function ($label, $value) use ($width, $totalsAlign) {
            $label = (string) $label;
            $value = (string) $value;
            if ($totalsAlign === 'left') {
                return substr($label.' '.$value, 0, $width);
            }
            if ($totalsAlign === 'center') {
                return str_pad(substr($label.' '.$value, 0, $width), $width, ' ', STR_PAD_BOTH);
            }
            $left = max(1, $width - strlen($value) - 1);
            return substr($label, 0, $left).str_repeat(' ', max(1, $width - min(strlen($label), $left) - strlen($value))).$value;
        };

        $money = static fn ($value) => 'L '.number_format((float) $value, 2, '.', ',');
        $separatorType = strtolower((string) ($pos->receipt_separator ?? 'dashed'));
        $separatorChar = $separatorType === 'dotted' ? '.' : ($separatorType === 'solid' ? '=' : '-');
        $sep = $separatorType === 'none' ? '' : str_repeat($separatorChar, $width);
        $lines = [];
        $addSep = static function () use (&$lines, $sep) {
            if ($sep !== '') $lines[] = $sep;
        };

        if ($pos->show_store_name) {
            $lines[] = $alignText($issuer['trade_name'] ?? $issuer['legal_name'] ?? '', $headerAlign);
        }
        if (! empty($issuer['legal_name']) && ! empty($issuer['trade_name'])) $lines[] = $alignText($issuer['legal_name'], $headerAlign);
        if (! empty($issuer['rtn'])) $lines[] = $alignText('RTN: '.$issuer['rtn'], $headerAlign);
        if ($pos->show_address) {
            if (! empty($issuer['point_of_issue_address'])) $lines[] = $alignText($issuer['point_of_issue_address'], $headerAlign);
            elseif (! empty($issuer['head_office_address'])) $lines[] = $alignText($issuer['head_office_address'], $headerAlign);
        }
        if ($pos->show_phone && ! empty($issuer['phone'])) $lines[] = $alignText('Tel: '.$issuer['phone'], $headerAlign);
        if ($pos->show_email && ! empty($issuer['email'])) $lines[] = $alignText($issuer['email'], $headerAlign);
        if (! empty($settings['website'])) $lines[] = $alignText($settings['website'], $headerAlign);

        $addSep();
        $lines[] = $alignText(trim($settings['document_title'].' '.$settings['sale_type_label']), $fiscalAlign);
        if ($doc->status === 'voided') $lines[] = $alignText('*** ANULADA ***', $fiscalAlign);
        $lines[] = $alignText($doc->fiscal_number, $fiscalAlign);
        if ($doc->cai) $lines[] = $alignText('CAI: '.$doc->cai, $fiscalAlign);
        $prefix = implode('-', array_slice(explode('-', $doc->fiscal_number), 0, 3)).'-';
        $rangeStart = $prefix.str_pad((string) optional($doc->authorization)->range_start, 8, '0', STR_PAD_LEFT);
        $rangeEnd = $prefix.str_pad((string) optional($doc->authorization)->range_end, 8, '0', STR_PAD_LEFT);
        $lines[] = $alignText('Rango: '.$rangeStart, $fiscalAlign);
        $lines[] = $alignText($rangeEnd, $fiscalAlign);
        $lines[] = $alignText('Limite: '.optional($doc->deadline)->format('Y-m-d'), $fiscalAlign);
        $addSep();

        $lines[] = $alignText('Cliente: '.($customer['name'] ?? 'Consumidor final'), $customerAlign);
        if (! empty($customer['rtn'])) $lines[] = $alignText('RTN: '.$customer['rtn'], $customerAlign);
        elseif (! empty($customer['identification_number'])) $lines[] = $alignText(($customer['identification_type'] ?? 'ID').': '.$customer['identification_number'], $customerAlign);
        if (! empty($customer['sar_registry_number'])) $lines[] = $alignText('Registro SAG/SAR: '.$customer['sar_registry_number'], $customerAlign);
        if (! empty($customer['exempt_purchase_order_number'])) $lines[] = $alignText('Orden compra exenta: '.$customer['exempt_purchase_order_number'], $customerAlign);
        if (! empty($customer['exoneration_registry_number'])) $lines[] = $alignText('Registro exonerado: '.$customer['exoneration_registry_number'], $customerAlign);
        if (! empty($customer['exonerated_card_number'])) $lines[] = $alignText('Carnet exonerado: '.$customer['exonerated_card_number'], $customerAlign);
        if ($pos->show_reference && ! empty($settings['show_internal_reference']) && ! empty($snap['internal_reference'])) $lines[] = $alignText('Ref: '.$snap['internal_reference'], $customerAlign);
        if ($pos->show_Warehouse && ! empty($settings['show_warehouse']) && ! empty($snap['warehouse_name'])) $lines[] = $alignText('Almacen: '.$snap['warehouse_name'], $customerAlign);
        if ($pos->show_seller && ! empty($settings['show_cashier']) && ! empty($snap['seller_name'])) $lines[] = $alignText('Cajero: '.$snap['seller_name'], $customerAlign);
        if ($pos->show_date) $lines[] = $alignText('Fecha: '.optional($doc->issued_at)->format('Y-m-d H:i:s'), $customerAlign);
        $addSep();

        foreach ((array) ($snap['lines'] ?? []) as $item) {
            $description = trim((string) ($item['description'] ?? 'Producto'));
            $lines[] = $alignText($description, $itemsAlign);
            $rateLabel = ($item['tax_category'] ?? '') === 'taxed' ? number_format((float) ($item['tax_rate'] ?? 0), 0).'%' : strtoupper((string) ($item['tax_category'] ?? ''));
            $qty = rtrim(rtrim(number_format((float) ($item['quantity'] ?? 0), 2, '.', ''), '0'), '.');
            $lines[] = $pair($qty.' x '.$money($item['unit_price'] ?? 0).' '.$rateLabel, $money($item['line_total'] ?? 0));
        }

        $addSep();
        if ($pos->show_discount) $lines[] = $pair('Descuentos y rebajas', $money($totals['discount_total'] ?? 0));
        $lines[] = $pair('Subtotal', $money($totals['subtotal'] ?? 0));
        $lines[] = $pair('Importe exonerado', $money($totals['exonerated_amount'] ?? 0));
        $lines[] = $pair('Importe exento', $money($totals['exempt_amount'] ?? 0));
        if ((float) ($totals['zero_rate_amount'] ?? 0) > 0) $lines[] = $pair('Importe tasa cero', $money($totals['zero_rate_amount']));
        $lines[] = $pair('Gravado 15%', $money($totals['taxable_15_amount'] ?? 0));
        $lines[] = $pair('Gravado 18%', $money($totals['taxable_18_amount'] ?? 0));
        $lines[] = $pair('ISV 15%', $money($totals['tax_15_amount'] ?? 0));
        $lines[] = $pair('ISV 18%', $money($totals['tax_18_amount'] ?? 0));
        if (abs((float) ($totals['rounding_adjustment'] ?? 0)) >= 0.005) $lines[] = $pair('Ajuste redondeo', $money($totals['rounding_adjustment']));
        if ($pos->show_shipping && (float) ($totals['shipping'] ?? 0) > 0) $lines[] = $pair('Envio', $money($totals['shipping']));
        $addSep();
        $lines[] = $pair('TOTAL', $money($totals['grand_total'] ?? $sale->GrandTotal));

        if ($pos->show_payments && ! empty($settings['show_payment_summary']) && ! empty($snap['payments'])) {
            $addSep();
            $lines[] = $alignText('PAGO', $totalsAlign);
            foreach ((array) $snap['payments'] as $payment) {
                $lines[] = $pair($payment['method'] ?? $payment['reference'] ?? 'Pago', $money($payment['amount'] ?? 0));
                if ((float) ($payment['change'] ?? 0) != 0.0) $lines[] = $pair('Cambio', $money($payment['change']));
            }
        }
        $addSep();

        if (! empty($settings['show_total_in_words'])) {
            $words = app(SpanishMoneyWords::class)->lempiras((float) ($totals['grand_total'] ?? $sale->GrandTotal));
            foreach (str_split($words, $width) as $chunk) $lines[] = $alignText($chunk, $footerAlign);
        }
        $lines[] = $alignText($settings['original_label'], $footerAlign);
        $lines[] = $alignText($settings['copy_label'], $footerAlign);
        if (! empty($settings['footer_message'])) $lines[] = $alignText($settings['footer_message'], $footerAlign);
        if ($doc->status === 'voided' && $doc->void_reason) $lines[] = $alignText('Motivo: '.$doc->void_reason, $footerAlign);

        $ESC = "\x1B";
        $GS = "\x1D";
        $out = $ESC."@";
        $out .= $ESC."a"."\x00";
        $out .= implode("\n", $lines)."\n\n\n\n";
        $out .= $GS."V"."\x00";

        $errno = 0;
        $errstr = '';
        $fp = @fsockopen($ip, $port, $errno, $errstr, 3);
        if (! $fp) {
            return response()->json(['success' => false, 'message' => 'Could not reach printer at '.$ip.':'.$port.' ('.$errstr.')'], 502);
        }

        stream_set_timeout($fp, 4);
        fwrite($fp, $out);
        fclose($fp);

        return response()->json(['success' => true, 'fiscal_number' => $doc->fiscal_number]);
    }
}
