@php
    $pdfLocale = app()->getLocale();
    $isRtl = $pdfLocale === 'ar';
    $priceFormat = $setting['price_format'] ?? null;
    $isFiscal = !empty($sar_fiscal);
    $issuer = $isFiscal ? ($sar_fiscal['issuer'] ?? []) : [];
    $customer = $isFiscal ? ($sar_fiscal['customer'] ?? []) : [];
    $fiscalSale = $isFiscal ? ($sar_fiscal['sale'] ?? []) : [];
    $fiscalTotals = $fiscalSale['fiscal_totals'] ?? [];
    $invoiceSettings = $issuer['invoice_settings'] ?? [];
    $documentTitle = $invoiceSettings['document_title'] ?? 'FACTURA';
    $saleTypeLabel = $invoiceSettings['sale_type_label'] ?? '';
    $showLogo = !array_key_exists('show_logo', $invoiceSettings) || (bool)$invoiceSettings['show_logo'];
    $showReference = !array_key_exists('show_internal_reference', $invoiceSettings) || (bool)$invoiceSettings['show_internal_reference'];
    $showWarehouse = !array_key_exists('show_warehouse', $invoiceSettings) || (bool)$invoiceSettings['show_warehouse'];
    $showCustomerAddress = !array_key_exists('show_customer_address', $invoiceSettings) || (bool)$invoiceSettings['show_customer_address'];
    $showItemCode = !array_key_exists('show_item_code', $invoiceSettings) || (bool)$invoiceSettings['show_item_code'];
    $showTotalWords = !array_key_exists('show_total_in_words', $invoiceSettings) || (bool)$invoiceSettings['show_total_in_words'];

    if (!function_exists('prodexInvoiceMoney')) {
        function prodexInvoiceMoney($number, $decimals = 2, $priceFormat = null) {
            $number = (float)$number;
            switch ($priceFormat) {
                case 'dot_comma': return number_format($number, $decimals, ',', '.');
                case 'space_comma': return number_format($number, $decimals, ',', ' ');
                default: return number_format($number, $decimals, '.', ',');
            }
        }
    }
    if (!function_exists('prodexFiscalRange')) {
        function prodexFiscalRange($sequence, $fiscalNumber) {
            if ($sequence === null || $sequence === '') return '';
            $raw = (string)$sequence;
            if (strpos($raw, '-') !== false) return $raw;
            $parts = explode('-', (string)$fiscalNumber);
            $prefix = count($parts) >= 4 ? implode('-', array_slice($parts, 0, 3)).'-' : '';
            return $prefix.str_pad(preg_replace('/\D+/', '', $raw), 8, '0', STR_PAD_LEFT);
        }
    }

    $logoSrc = null;
    if ($showLogo && !empty($setting['logo'])) {
        $logoPath = upload_public_path('settings/'.$setting['logo']);
        if (!is_file($logoPath)) $logoPath = public_path('images/'.$setting['logo']);
        if (is_file($logoPath) && is_readable($logoPath)) {
            $rawLogo = @file_get_contents($logoPath);
            if ($rawLogo !== false) {
                $ext = strtolower(pathinfo($logoPath, PATHINFO_EXTENSION));
                $mime = $ext === 'jpg' ? 'image/jpeg' : ($ext === 'svg' ? 'image/svg+xml' : 'image/'.($ext ?: 'png'));
                $logoSrc = 'data:'.$mime.';base64,'.base64_encode($rawLogo);
            }
        }
    }

    $rangeStart = $isFiscal ? prodexFiscalRange($sar_fiscal['range_start'] ?? null, $sar_fiscal['fiscal_number'] ?? '') : '';
    $rangeEnd = $isFiscal ? prodexFiscalRange($sar_fiscal['range_end'] ?? null, $sar_fiscal['fiscal_number'] ?? '') : '';
@endphp
<!DOCTYPE html>
<html lang="{{ $pdfLocale }}" dir="{{ $isRtl ? 'rtl' : 'ltr' }}">
<head>
    <meta charset="utf-8">
    <meta http-equiv="Content-Type" content="text/html; charset=utf-8">
    <title>{{ $isFiscal ? $documentTitle : __('pdf.sales_invoice') }} - {{ $isFiscal ? ($sar_fiscal['fiscal_number'] ?? $sale['Ref']) : $sale['Ref'] }}</title>
    <style>
        @page { size: A4; margin: 11mm 13mm; }
        * { box-sizing: border-box; }
        body { font-family: 'DejaVu Sans', sans-serif; color:#111827; font-size:9pt; line-height:1.35; margin:0; }
        .center { text-align:center; }
        .right { text-align:right; }
        .muted { color:#6b7280; }
        .strong { font-weight:700; }
        .title { font-size:16pt; font-weight:800; letter-spacing:.4px; }
        .fiscal-number { font-size:12pt; font-weight:800; margin-top:2px; }
        .box { border:1px solid #d1d5db; border-radius:4px; padding:8px 10px; }
        .box-title { font-size:8pt; font-weight:700; text-transform:uppercase; color:#4b5563; margin-bottom:4px; }
        .divider { border-top:1px solid #d1d5db; margin:8px 0; }
        table { width:100%; border-collapse:collapse; }
        .two td { width:50%; vertical-align:top; padding:0 4px; }
        .two td:first-child { padding-left:0; }
        .two td:last-child { padding-right:0; }
        .items th { background:#f3f4f6; padding:6px 5px; border-bottom:1px solid #9ca3af; font-size:8pt; text-align:left; }
        .items td { padding:6px 5px; border-bottom:1px solid #e5e7eb; vertical-align:top; }
        .items .num { text-align:right; white-space:nowrap; }
        .totals { width:52%; margin-left:auto; margin-top:10px; }
        .totals td { padding:3px 5px; }
        .totals td:last-child { text-align:right; white-space:nowrap; }
        .total-row td { border-top:2px solid #111827; font-size:11pt; font-weight:800; padding-top:6px; }
        .fiscal-lines { margin-top:10px; }
        .legal { margin-top:10px; font-size:8pt; text-align:center; }
        .voided { display:inline-block; border:2px solid #991b1b; color:#991b1b; padding:3px 12px; font-weight:800; margin:4px 0; }
        .footer-message { text-align:center; margin-top:7px; font-size:8pt; }
    </style>
</head>
<body>
@if($isFiscal)
    <div class="center">
        @if($logoSrc)<img src="{{ $logoSrc }}" alt="Logo" style="max-height:58px;max-width:180px;margin-bottom:5px;">@endif
        <div class="strong" style="font-size:12pt;">{{ $issuer['trade_name'] ?? $issuer['legal_name'] ?? '' }}</div>
        @if(!empty($issuer['trade_name']) && !empty($issuer['legal_name']))<div>{{ $issuer['legal_name'] }}</div>@endif
        @if(!empty($issuer['rtn']))<div><strong>RTN:</strong> {{ $issuer['rtn'] }}</div>@endif
        @if(!empty($issuer['head_office_address']))<div><strong>Casa matriz:</strong> {{ $issuer['head_office_address'] }}</div>@endif
        @if(!empty($issuer['point_of_issue_address']))<div><strong>Punto de emisión:</strong> {{ $issuer['point_of_issue_address'] }}</div>@endif
        @if(!empty($issuer['phone']) || !empty($issuer['email']))<div>{{ $issuer['phone'] ?? '' }}@if(!empty($issuer['phone']) && !empty($issuer['email'])) · @endif{{ $issuer['email'] ?? '' }}</div>@endif
        @if(!empty($invoiceSettings['website']))<div>{{ $invoiceSettings['website'] }}</div>@endif
        <div class="divider"></div>
        <div class="title">{{ $documentTitle }}{{ $saleTypeLabel ? ' '.$saleTypeLabel : '' }}</div>
        @if(($sar_fiscal['status'] ?? '') === 'voided')<div class="voided">ANULADA</div>@endif
        <div class="fiscal-number">{{ $sar_fiscal['fiscal_number'] ?? '' }}</div>
        @if(!empty($sar_fiscal['cai']))<div><strong>CAI:</strong> {{ $sar_fiscal['cai'] }}</div>@endif
        @if($rangeStart || $rangeEnd)<div><strong>Rango autorizado:</strong> {{ $rangeStart }} al {{ $rangeEnd }}</div>@endif
        @if(!empty($sar_fiscal['deadline']))<div><strong>Fecha límite de emisión:</strong> {{ $sar_fiscal['deadline'] }}</div>@endif
    </div>

    <table class="two" style="margin-top:10px;"><tr>
        <td><div class="box">
            <div class="box-title">Transacción</div>
            <div><strong>Fecha:</strong> {{ $sar_fiscal['issued_at'] ?? (($sale['date'] ?? '').' '.($sale['time'] ?? '')) }}</div>
            @if($showReference && !empty($fiscalSale['internal_reference']))<div><strong>Referencia:</strong> {{ $fiscalSale['internal_reference'] }}</div>@endif
            @if($showWarehouse && !empty($fiscalSale['warehouse_name']))<div><strong>Almacén:</strong> {{ $fiscalSale['warehouse_name'] }}</div>@endif
            @if(!empty($issuer['establishment_code']) || !empty($issuer['point_code']))<div><strong>Establecimiento/Punto:</strong> {{ $issuer['establishment_code'] ?? '' }}-{{ $issuer['point_code'] ?? '' }}</div>@endif
        </div></td>
        <td><div class="box">
            <div class="box-title">Cliente</div>
            <div class="strong">{{ $customer['name'] ?? 'Consumidor final' }}</div>
            @if(!empty($customer['rtn']))<div><strong>RTN:</strong> {{ $customer['rtn'] }}</div>
            @elseif(!empty($customer['identification_number']))<div><strong>{{ $customer['identification_type'] ?? 'Identificación' }}:</strong> {{ $customer['identification_number'] }}</div>@endif
            @if($showCustomerAddress && !empty($customer['address']))<div>{{ $customer['address'] }}</div>@endif
            @if(!empty($customer['sar_registry_number']))<div><strong>Registro SAG/SAR:</strong> {{ $customer['sar_registry_number'] }}</div>@endif
            @if(!empty($customer['exempt_purchase_order_number']))<div><strong>Orden de compra exenta:</strong> {{ $customer['exempt_purchase_order_number'] }}</div>@endif
            @if(!empty($customer['exoneration_registry_number']))<div><strong>Registro exonerado:</strong> {{ $customer['exoneration_registry_number'] }}</div>@endif
            @if(!empty($customer['exonerated_card_number']))<div><strong>Carnet exonerado:</strong> {{ $customer['exonerated_card_number'] }}</div>@endif
        </div></td>
    </tr></table>
@else
    <table class="two"><tr>
        <td>
            @if($logoSrc)<img src="{{ $logoSrc }}" alt="Logo" style="max-height:58px;max-width:180px;">@endif
            <div class="strong" style="font-size:12pt;">{{ $setting['CompanyName'] ?? '' }}</div>
            <div>{{ $setting['CompanyAdress'] ?? '' }}</div>
            <div>{{ $setting['CompanyPhone'] ?? '' }} {{ $setting['email'] ?? '' }}</div>
        </td>
        <td class="right"><div class="title">{{ __('pdf.sales_invoice') }}</div><div class="strong">{{ $sale['Ref'] ?? '' }}</div><div>{{ $sale['date'] ?? '' }}</div></td>
    </tr></table>
    <div class="divider"></div>
    <table class="two"><tr>
        <td><div class="box"><div class="box-title">{{ __('pdf.bill_to') }}</div><div class="strong">{{ $sale['client_name'] ?? '' }}</div><div>{{ $sale['client_phone'] ?? '' }}</div><div>{{ $sale['client_email'] ?? '' }}</div><div>{{ $sale['client_adr'] ?? '' }}</div>@if(!empty($sale['client_tax']))<div>{{ __('pdf.tax_no') }}: {{ $sale['client_tax'] }}</div>@endif</div></td>
        <td><div class="box"><div class="box-title">{{ __('pdf.status') }}</div><div>{{ $sale['statut'] ?? '' }}</div><div>{{ __('pdf.payment') }}: {{ $sale['payment_status'] ?? '' }}</div><div>{{ $sale['warehouse_name'] ?? '' }}</div></div></td>
    </tr></table>
@endif

<table class="items" style="margin-top:12px;">
    <thead><tr>
        <th style="width:34%;">{{ __('pdf.product') }}</th>
        <th class="num" style="width:13%;">{{ __('pdf.price') }}</th>
        <th class="num" style="width:11%;">{{ __('pdf.quantity') }}</th>
        <th class="num" style="width:12%;">{{ __('pdf.discount') }}</th>
        <th class="num" style="width:12%;">{{ $isFiscal ? 'ISV' : __('pdf.tax') }}</th>
        <th class="num" style="width:18%;">{{ __('pdf.total_label') }}</th>
    </tr></thead>
    <tbody>
    @foreach($details as $index => $detail)
        @php $fLine = $isFiscal ? (($fiscalSale['lines'][$index] ?? [])) : []; @endphp
        <tr>
            <td><div class="strong">{{ $detail['name'] ?? '' }}</div>@if($showItemCode && !empty($detail['code']))<div class="muted">{{ $detail['code'] }}</div>@endif @if(!empty($detail['imei_number']))<div class="muted">SN: {{ $detail['imei_number'] }}</div>@endif</td>
            <td class="num">{{ $symbol }} {{ prodexInvoiceMoney($detail['price'] ?? 0, 2, $priceFormat) }}</td>
            <td class="num">{{ $detail['quantity'] ?? 0 }} {{ $detail['pack_name'] ?? $detail['unitSale'] ?? '' }}</td>
            <td class="num">{{ $symbol }} {{ prodexInvoiceMoney($detail['DiscountNet'] ?? 0, 2, $priceFormat) }}</td>
            <td class="num">@if($isFiscal){{ prodexInvoiceMoney($fLine['tax_rate'] ?? 0, 2, $priceFormat) }}%<br><span class="muted">{{ $symbol }} {{ prodexInvoiceMoney($fLine['tax_amount'] ?? 0, 2, $priceFormat) }}</span>@else{{ $symbol }} {{ prodexInvoiceMoney($detail['taxe'] ?? 0, 2, $priceFormat) }}@endif</td>
            <td class="num strong">{{ $symbol }} {{ prodexInvoiceMoney($isFiscal ? ($fLine['line_total'] ?? $detail['total'] ?? 0) : ($detail['total'] ?? 0), 2, $priceFormat) }}</td>
        </tr>
    @endforeach
    </tbody>
</table>

@if($isFiscal)
<table class="totals">
    <tr><td>Descuentos y rebajas</td><td>{{ $symbol }} {{ prodexInvoiceMoney($fiscalTotals['discount_total'] ?? 0,2,$priceFormat) }}</td></tr>
    <tr><td>Subtotal</td><td>{{ $symbol }} {{ prodexInvoiceMoney($fiscalTotals['subtotal'] ?? 0,2,$priceFormat) }}</td></tr>
    <tr><td>Importe exonerado</td><td>{{ $symbol }} {{ prodexInvoiceMoney($fiscalTotals['exonerated_amount'] ?? 0,2,$priceFormat) }}</td></tr>
    <tr><td>Importe exento</td><td>{{ $symbol }} {{ prodexInvoiceMoney($fiscalTotals['exempt_amount'] ?? 0,2,$priceFormat) }}</td></tr>
    @if((float)($fiscalTotals['zero_rate_amount'] ?? 0)>0)<tr><td>Importe tasa cero</td><td>{{ $symbol }} {{ prodexInvoiceMoney($fiscalTotals['zero_rate_amount'],2,$priceFormat) }}</td></tr>@endif
    <tr><td>Importe gravado 15%</td><td>{{ $symbol }} {{ prodexInvoiceMoney($fiscalTotals['taxable_15_amount'] ?? 0,2,$priceFormat) }}</td></tr>
    <tr><td>Importe gravado 18%</td><td>{{ $symbol }} {{ prodexInvoiceMoney($fiscalTotals['taxable_18_amount'] ?? 0,2,$priceFormat) }}</td></tr>
    <tr><td>ISV 15%</td><td>{{ $symbol }} {{ prodexInvoiceMoney($fiscalTotals['tax_15_amount'] ?? 0,2,$priceFormat) }}</td></tr>
    <tr><td>ISV 18%</td><td>{{ $symbol }} {{ prodexInvoiceMoney($fiscalTotals['tax_18_amount'] ?? 0,2,$priceFormat) }}</td></tr>
    @if((float)($fiscalTotals['other_tax_amount'] ?? 0)>0)<tr><td>Otros impuestos</td><td>{{ $symbol }} {{ prodexInvoiceMoney($fiscalTotals['other_tax_amount'],2,$priceFormat) }}</td></tr>@endif
    @if((float)($fiscalTotals['shipping'] ?? 0)>0)<tr><td>{{ __('pdf.shipping') }}</td><td>{{ $symbol }} {{ prodexInvoiceMoney($fiscalTotals['shipping'],2,$priceFormat) }}</td></tr>@endif
    <tr class="total-row"><td>TOTAL</td><td>{{ $symbol }} {{ prodexInvoiceMoney($fiscalTotals['grand_total'] ?? ($sale['GrandTotal'] ?? 0),2,$priceFormat) }}</td></tr>
</table>
@if($showTotalWords && !empty($sar_fiscal['total_in_words']))<div class="center strong" style="margin-top:9px;">{{ $sar_fiscal['total_in_words'] }}</div>@endif
<div class="legal">{{ $invoiceSettings['original_label'] ?? 'Original: Cliente' }}<br>{{ $invoiceSettings['copy_label'] ?? 'Copia: Obligado Tributario Emisor' }}</div>
@if(!empty($invoiceSettings['footer_message']))<div class="footer-message">{{ $invoiceSettings['footer_message'] }}</div>@endif
@if(($sar_fiscal['status'] ?? '') === 'voided' && !empty($sar_fiscal['void_reason']))<div class="center" style="margin-top:6px;"><strong>Motivo de anulación:</strong> {{ $sar_fiscal['void_reason'] }}</div>@endif
@else
    @php
        $genericSubtotal = collect($details)->sum(fn($d)=>(float)($d['total'] ?? 0));
        $discountMethod = (string)($sale['discount_Method'] ?? '2');
        $discountValue = (float)($sale['discount'] ?? 0);
        $manualDiscount = $discountMethod === '1' ? $genericSubtotal*($discountValue/100) : min($discountValue,$genericSubtotal);
    @endphp
    <table class="totals">
        <tr><td>{{ __('pdf.subtotal') }}</td><td>{{ $symbol }} {{ prodexInvoiceMoney($genericSubtotal,2,$priceFormat) }}</td></tr>
        <tr><td>{{ __('pdf.order_tax') }}</td><td>{{ $symbol }} {{ prodexInvoiceMoney($sale['TaxNet'] ?? 0,2,$priceFormat) }}</td></tr>
        <tr><td>{{ __('pdf.discount') }}</td><td>- {{ $symbol }} {{ prodexInvoiceMoney($manualDiscount,2,$priceFormat) }}</td></tr>
        @if((float)($sale['shipping'] ?? 0)>0)<tr><td>{{ __('pdf.shipping') }}</td><td>{{ $symbol }} {{ prodexInvoiceMoney($sale['shipping'],2,$priceFormat) }}</td></tr>@endif
        <tr class="total-row"><td>{{ __('pdf.total_label') }}</td><td>{{ $symbol }} {{ prodexInvoiceMoney($sale['GrandTotal'] ?? 0,2,$priceFormat) }}</td></tr>
        <tr><td>{{ __('pdf.paid_amount') }}</td><td>{{ $symbol }} {{ prodexInvoiceMoney($sale['paid_amount'] ?? 0,2,$priceFormat) }}</td></tr>
        <tr><td>{{ __('pdf.amount_due') }}</td><td>{{ $symbol }} {{ prodexInvoiceMoney($sale['due'] ?? 0,2,$priceFormat) }}</td></tr>
    </table>
@endif

@if(!empty($sale['notes']) || !empty($sale['payment_note']))
<div class="box" style="margin-top:12px;">
    @if(!empty($sale['notes']))<div><strong>{{ __('pdf.notes') }}:</strong> {{ $sale['notes'] }}</div>@endif
    @if(!empty($sale['payment_note']))<div><strong>{{ __('pdf.payment_note') }}:</strong> {{ $sale['payment_note'] }}</div>@endif
</div>
@endif
</body>
</html>
