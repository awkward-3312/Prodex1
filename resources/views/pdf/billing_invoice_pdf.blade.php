<!DOCTYPE html>
<html>
<head>
    <meta charset="utf-8">
    <title>Invoice {{ $payment->invoice_number }}</title>
    @php
        $billingInvoiceCssPath = public_path('css/billing_invoice_pdf.css');
    @endphp
    @if (is_file($billingInvoiceCssPath) && is_readable($billingInvoiceCssPath))
        <style>{!! file_get_contents($billingInvoiceCssPath) !!}</style>
    @endif
</head>
<body>
<div class="bil-invoice-wrapper">

    {{-- Header --}}
    <table class="bil-flex-table bil-header-table">
        <tr>
            <td>
                <h1 class="bil-title">INVOICE</h1>
                <p class="bil-subtitle">Subscription Payment</p>
            </td>
            <td class="bil-cell-right">
                <p class="bil-invoice-label">Invoice Number</p>
                <p class="bil-invoice-number">{{ $payment->invoice_number }}</p>
                <p class="bil-date-line">
                    Date: {{ $payment->paid_at ? $payment->paid_at->format('M d, Y') : $payment->created_at->format('M d, Y') }}
                </p>
            </td>
        </tr>
    </table>

    {{-- Info Section --}}
    <table class="bil-flex-table bil-info-table">
        <tr>
            <td class="bil-info-cell">
                <p class="bil-section-label">Billed To</p>
                <p class="bil-name">{{ $tenant->company_name ?? $tenant->id ?? $payment->tenant_id }}</p>
                @if(($tenant->admin_email ?? null))
                    <p class="bil-muted">{{ $tenant->admin_email }}</p>
                @endif
            </td>
            <td class="bil-info-cell bil-info-cell--right">
                <p class="bil-section-label">Payment Details</p>
                <p>Gateway: <strong>{{ ucfirst($payment->gateway) }}</strong></p>
                <p>Status: <span class="bil-badge bil-badge--paid">Paid</span></p>
                @if($payment->transaction_id)
                    <p class="bil-ref">Ref: {{ $payment->transaction_id }}</p>
                @endif
            </td>
        </tr>
    </table>

    {{-- Line Items --}}
    <div class="bil-table-wrapper">
        <table class="bil-line-items-table">
            <thead>
                <tr>
                    <th>Description</th>
                    <th>Billing Cycle</th>
                    <th class="bil-text-right">Amount</th>
                </tr>
            </thead>
            <tbody>
                <tr>
                    <td>
                        <strong>{{ $plan->name ?? 'Subscription' }} Plan</strong>
                        @if($subscription && $subscription->starts_at && $subscription->ends_at)
                            <br><span class="bil-period-muted">
                                {{ $subscription->starts_at->format('M d, Y') }} — {{ $subscription->ends_at->format('M d, Y') }}
                            </span>
                        @endif
                    </td>
                    <td>{{ ucfirst($payment->billing_cycle) }}</td>
                    <td class="bil-text-right">{{ \App\Models\Central\GeneralSetting::currencySymbol() }}{{ number_format($payment->amount, 2) }} {{ $payment->currency }}</td>
                </tr>
            </tbody>
        </table>
    </div>

    {{-- Totals --}}
    <table class="bil-totals-table">
        <tr class="bil-totals-row">
            <td>Subtotal</td>
            <td class="bil-totals-value">{{ \App\Models\Central\GeneralSetting::currencySymbol() }}{{ number_format($payment->amount, 2) }}</td>
        </tr>
        @if((float) $payment->tax > 0)
        <tr class="bil-totals-row">
            <td>Tax</td>
            <td class="bil-totals-value">{{ \App\Models\Central\GeneralSetting::currencySymbol() }}{{ number_format($payment->tax, 2) }}</td>
        </tr>
        @endif
        <tr class="bil-totals-row bil-totals-row--grand">
            <td>Total</td>
            <td class="bil-totals-value">
                {{ \App\Models\Central\GeneralSetting::currencySymbol() }}{{ number_format($payment->amount + ($payment->tax ?? 0), 2) }} {{ $payment->currency }}
            </td>
        </tr>
    </table>

    @if($payment->conversion_applied && $payment->gateway_currency)
    <div class="bil-currency-note">
        <strong>Currency Note:</strong> Payment was charged as {{ number_format($payment->gateway_amount, 2) }} {{ $payment->gateway_currency }}
        (exchange rate: 1 {{ $payment->currency }} = {{ number_format($payment->exchange_rate, 4) }} {{ $payment->gateway_currency }}).
    </div>
    @endif

    {{-- Footer --}}
    <div class="bil-footer">
        <p>Thank you for your subscription!</p>
        <p class="bil-footer-extra">This invoice was generated automatically. If you have questions, contact support.</p>
    </div>

</div>
</body>
</html>
