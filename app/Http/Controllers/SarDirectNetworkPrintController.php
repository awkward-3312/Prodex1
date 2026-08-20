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

        $width = (int) ($pos->receipt_paper_size ?? 80) === 58 ? 32 : 48;
        $lines = [];
        $center = fn ($text) => str_pad(substr((string) $text, 0, $width), $width, ' ', STR_PAD_BOTH);
        $pair = function ($label, $value) use ($width) {
            $value = (string) $value;
            $left = max(1, $width - strlen($value) - 1);
            return substr((string) $label, 0, $left).str_repeat(' ', max(1, $width - min(strlen((string) $label), $left) - strlen($value))).$value;
        };
        $money = fn ($value) => 'L '.number_format((float) $value, 2, '.', ',');
        $sep = str_repeat('-', $width);

        $lines[] = $center($issuer['trade_name'] ?? $issuer['legal_name'] ?? '');
        if (! empty($issuer['legal_name']) && ! empty($issuer['trade_name'])) $lines[] = $center($issuer['legal_name']);
        if (! empty($issuer['rtn'])) $lines[] = $center('RTN: '.$issuer['rtn']);
        if (! empty($issuer['point_of_issue_address'])) $lines[] = $center($issuer['point_of_issue_address']);
        elseif (! empty($issuer['head_office_address'])) $lines[] = $center($issuer['head_office_address']);
        if (! empty($issuer['phone'])) $lines[] = $center('Tel: '.$issuer['phone']);
        if (! empty($settings['website'])) $lines[] = $center($settings['website']);
        $lines[] = $sep;
        $lines[] = $center(trim($settings['document_title'].' '.$settings['sale_type_label']));
        if ($doc->status === 'voided') $lines[] = $center('*** ANULADA ***');
        $lines[] = $center($doc->fiscal_number);
        $lines[] = 'CAI: '.$doc->cai;
        $prefix = implode('-', array_slice(explode('-', $doc->fiscal_number), 0, 3)).'-';
        $rangeStart = $prefix.str_pad((string) optional($doc->authorization)->range_start, 8, '0', STR_PAD_LEFT);
        $rangeEnd = $prefix.str_pad((string) optional($doc->authorization)->range_end, 8, '0', STR_PAD_LEFT);
        $lines[] = 'Rango: '.$rangeStart;
        $lines[] = '       '.$rangeEnd;
        $lines[] = 'Limite: '.optional($doc->deadline)->format('Y-m-d');
        $lines[] = $sep;
        $lines[] = 'Cliente: '.($customer['name'] ?? 'Consumidor final');
        if (! empty($customer['rtn'])) $lines[] = 'RTN: '.$customer['rtn'];
        elseif (! empty($customer['identification_number'])) $lines[] = ($customer['identification_type'] ?? 'ID').': '.$customer['identification_number'];
        if (! empty($customer['sar_registry_number'])) $lines[] = 'Registro SAG/SAR: '.$customer['sar_registry_number'];
        if (! empty($customer['exempt_purchase_order_number'])) $lines[] = 'Orden compra exenta: '.$customer['exempt_purchase_order_number'];
        if (! empty($customer['exoneration_registry_number'])) $lines[] = 'Registro exonerado: '.$customer['exoneration_registry_number'];
        if (! empty($customer['exonerated_card_number'])) $lines[] = 'Carnet exonerado: '.$customer['exonerated_card_number'];
        if (! empty($settings['show_internal_reference']) && ! empty($snap['internal_reference'])) $lines[] = 'Ref: '.$snap['internal_reference'];
        if (! empty($settings['show_warehouse']) && ! empty($snap['warehouse_name'])) $lines[] = 'Almacen: '.$snap['warehouse_name'];
        if (! empty($settings['show_cashier']) && ! empty($snap['seller_name'])) $lines[] = 'Cajero: '.$snap['seller_name'];
        $lines[] = 'Fecha: '.optional($doc->issued_at)->format('Y-m-d H:i:s');
        $lines[] = $sep;

        foreach ((array) ($snap['lines'] ?? []) as $item) {
            $description = trim((string) ($item['description'] ?? 'Producto'));
            $lines[] = substr($description, 0, $width);
            $rateLabel = ($item['tax_category'] ?? '') === 'taxed' ? number_format((float) ($item['tax_rate'] ?? 0), 0).'%' : strtoupper((string) ($item['tax_category'] ?? ''));
            $qty = rtrim(rtrim(number_format((float) ($item['quantity'] ?? 0), 2, '.', ''), '0'), '.');
            $lines[] = $pair($qty.' x '.$money($item['unit_price'] ?? 0).' '.$rateLabel, $money($item['line_total'] ?? 0));
        }

        $lines[] = $sep;
        $lines[] = $pair('Descuentos y rebajas', $money($totals['discount_total'] ?? 0));
        $lines[] = $pair('Subtotal', $money($totals['subtotal'] ?? 0));
        $lines[] = $pair('Importe exonerado', $money($totals['exonerated_amount'] ?? 0));
        $lines[] = $pair('Importe exento', $money($totals['exempt_amount'] ?? 0));
        if ((float) ($totals['zero_rate_amount'] ?? 0) > 0) $lines[] = $pair('Importe tasa cero', $money($totals['zero_rate_amount']));
        $lines[] = $pair('Gravado 15%', $money($totals['taxable_15_amount'] ?? 0));
        $lines[] = $pair('Gravado 18%', $money($totals['taxable_18_amount'] ?? 0));
        $lines[] = $pair('ISV 15%', $money($totals['tax_15_amount'] ?? 0));
        $lines[] = $pair('ISV 18%', $money($totals['tax_18_amount'] ?? 0));
        if (abs((float) ($totals['rounding_adjustment'] ?? 0)) >= 0.005) $lines[] = $pair('Ajuste redondeo', $money($totals['rounding_adjustment']));
        if ((float) ($totals['shipping'] ?? 0) > 0) $lines[] = $pair('Envio', $money($totals['shipping']));
        $lines[] = $sep;
        $lines[] = $pair('TOTAL', $money($totals['grand_total'] ?? $sale->GrandTotal));

        if (! empty($settings['show_payment_summary']) && ! empty($snap['payments'])) {
            $lines[] = $sep;
            $lines[] = 'PAGO';
            foreach ((array) $snap['payments'] as $payment) {
                $lines[] = $pair($payment['method'] ?? $payment['reference'] ?? 'Pago', $money($payment['amount'] ?? 0));
                if ((float) ($payment['change'] ?? 0) != 0.0) $lines[] = $pair('Cambio', $money($payment['change']));
            }
        }
        $lines[] = $sep;

        if (! empty($settings['show_total_in_words'])) {
            $words = app(SpanishMoneyWords::class)->lempiras((float) ($totals['grand_total'] ?? $sale->GrandTotal));
            foreach (str_split($words, $width) as $chunk) $lines[] = $center($chunk);
        }
        $lines[] = $center($settings['original_label']);
        $lines[] = $center($settings['copy_label']);
        if (! empty($settings['footer_message'])) $lines[] = $center($settings['footer_message']);
        if ($doc->status === 'voided' && $doc->void_reason) $lines[] = $center('Motivo: '.$doc->void_reason);

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
