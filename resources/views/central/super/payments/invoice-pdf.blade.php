<!DOCTYPE html>
<html>
<head>
    <meta charset="utf-8">
    <title>{{ __('super.invoice_pdf.title') }} {{ $payment->invoice_number }}</title>
    @php $superInvoicePdfCssPath = public_path('assets_super/css/super_invoice_pdf.css'); @endphp
    @if (is_file($superInvoicePdfCssPath) && is_readable($superInvoicePdfCssPath))
        <style>{!! file_get_contents($superInvoicePdfCssPath) !!}</style>
    @endif
    @php
        $appName = \App\Models\Central\GeneralSetting::instance()->app_name ?: 'Stocky';
        $supinvStatus = strtolower((string) $payment->status);
        $supinvStatusClass = match ($supinvStatus) {
            'paid' => 'supinv-status--paid',
            'pending' => 'supinv-status--pending',
            'failed' => 'supinv-status--failed',
            'refunded' => 'supinv-status--refunded',
            default => 'supinv-status--default',
        };
    @endphp
</head>
<body>
    <div class="supinv-wrap">
        <div class="supinv-header">
            <div class="supinv-header-left">
                <div class="supinv-brand">{{ $appName }}</div>
                <p class="supinv-brand-sub">{{ __('super.invoice_pdf.subscription') }}</p>
            </div>
            <div class="supinv-header-right">
                <div class="supinv-invoice-title">{{ __('super.invoice_pdf.title') }}</div>
                <div class="supinv-invoice-number">{{ $payment->invoice_number }}</div>
                <p class="supinv-status-wrap">
                    <span class="supinv-status {{ $supinvStatusClass }}">{{ ucfirst($payment->status) }}</span>
                </p>
            </div>
        </div>

        <div class="supinv-bill-to">
            <div class="supinv-bill-col">
                <div class="supinv-bill-heading">{{ __('super.invoice_pdf.bill_to') }}</div>
                <p class="supinv-bill-name">{{ $payment->tenant->company_name ?? $payment->tenant_id }}</p>
                @if($payment->tenant && $payment->tenant->domains && $payment->tenant->domains->isNotEmpty())
                    <p class="supinv-muted">{{ $payment->tenant->domains->first()->domain }}</p>
                @endif
            </div>
            <div class="supinv-bill-col supinv-bill-col--right">
                <div class="supinv-bill-heading">{{ __('super.invoice_pdf.payment_details') }}</div>
                <table class="supinv-meta-table" cellpadding="0" cellspacing="0">
                    <tr>
                        <td class="supinv-meta-label">{{ __('super.invoice_pdf.date') }}</td>
                        <td class="supinv-meta-value">{{ ($payment->paid_at ?? $payment->created_at)->format('M d, Y') }}</td>
                    </tr>
                    <tr>
                        <td class="supinv-meta-label">{{ __('super.invoice_pdf.gateway') }}</td>
                        <td class="supinv-meta-value">{{ $payment->gateway_label }}</td>
                    </tr>
                    @if($payment->transaction_id)
                    <tr>
                        <td class="supinv-meta-label">{{ __('super.invoice_pdf.txn_id') }}</td>
                        <td class="supinv-meta-value">{{ $payment->transaction_id }}</td>
                    </tr>
                    @endif
                    <tr>
                        <td class="supinv-meta-label">{{ __('super.invoice_pdf.cycle') }}</td>
                        <td class="supinv-meta-value">{{ ucfirst($payment->billing_cycle) }}</td>
                    </tr>
                </table>
            </div>
        </div>

        <table class="supinv-items-table">
            <thead>
                <tr>
                    <th>{{ __('super.invoice_pdf.description') }}</th>
                    <th>{{ __('super.invoice_pdf.billing_cycle') }}</th>
                    <th class="supinv-text-right">{{ __('super.common.amount') }}</th>
                </tr>
            </thead>
            <tbody>
                <tr>
                    <td>
                        <strong>{{ $payment->plan->name ?? __('super.invoice_pdf.subscription') }} {{ __('super.invoice_pdf.plan') }}</strong>
                        <br><span class="supinv-item-note">{{ __('super.invoice_pdf.sub_payment') }}</span>
                    </td>
                    <td>{{ ucfirst($payment->billing_cycle) }}</td>
                    <td class="supinv-text-right">{{ $currencySymbol }}{{ number_format($payment->amount, 2) }}</td>
                </tr>
            </tbody>
        </table>

        <table class="supinv-totals">
            <tr>
                <td class="supinv-totals-muted">{{ __('super.invoice_pdf.subtotal') }}</td>
                <td class="supinv-text-right">{{ $currencySymbol }}{{ number_format($payment->amount, 2) }}</td>
            </tr>
            @if($payment->tax > 0)
            <tr>
                <td class="supinv-totals-muted">{{ __('super.invoice_pdf.tax') }}</td>
                <td class="supinv-text-right">{{ $currencySymbol }}{{ number_format($payment->tax, 2) }}</td>
            </tr>
            @endif
            <tr class="supinv-totals-total-row">
                <td>{{ __('super.invoice_pdf.total') }} ({{ $payment->currency }})</td>
                <td class="supinv-text-right">{{ $currencySymbol }}{{ number_format($payment->total, 2) }}</td>
            </tr>
        </table>

        @if($payment->notes)
        <div class="supinv-notes">
            <p class="supinv-notes-title">{{ __('super.common.notes') }}</p>
            <p>{{ $payment->notes }}</p>
        </div>
        @endif

        <div class="supinv-footer">
            <p>{{ $appName }} &middot; {{ __('super.invoice_pdf.auto_generated') }}</p>
            <p class="supinv-footer-url">{{ url('/') }}</p>
        </div>
    </div>
</body>
</html>
