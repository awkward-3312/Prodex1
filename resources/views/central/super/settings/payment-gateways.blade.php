@extends('central.super.layout')

@section('title', __('super.gateway_settings.title'))

@section('content')
<div class="page-header">
    <h1>{{ __('super.gateway_settings.title') }}</h1>
    <p class="page-subtitle">{{ __('super.gateway_settings.subtitle') }}</p>
</div>

{{-- Gateway tabs --}}
<div class="gateway-tabs mb-4">
    <div class="d-flex flex-wrap gap-2">
        @foreach($gateways as $key => $gw)
        <button
            class="gateway-tab-btn {{ (request('active', array_key_first($gateways)) === $key) ? 'active' : '' }}"
            data-gateway="{{ $key }}"
            type="button"
        >
            <span class="gateway-dot" style="background: {{ $gw['definition']['color'] }};"></span>
            <span>{{ $gw['definition']['label'] }}</span>
            @if($gw['setting']->is_active)
                <i class="bi bi-check-circle-fill gateway-tab-check"></i>
            @endif
        </button>
        @endforeach
    </div>
</div>

{{-- Gateway panels --}}
@foreach($gateways as $key => $gw)
<div class="gateway-panel" id="panel-{{ $key }}" style="{{ (request('active', array_key_first($gateways)) === $key) ? '' : 'display: none;' }}">
    <form method="POST" action="{{ route('super.settings.payment-gateways.update', $key) }}">
        @csrf
        @method('PUT')

        <div class="row g-4">
            <div class="col-lg-8">
                {{-- Gateway header card --}}
                <div class="content-card mb-4">
                    <div class="card-body-custom">
                        <div class="d-flex align-items-center justify-content-between flex-wrap gap-3">
                            <div class="d-flex align-items-center gap-3">
                                <div class="gw-header-icon" style="background: {{ $gw['definition']['color'] }}15;">
                                    <i class="bi {{ $gw['definition']['icon'] }}" style="font-size: 1.5rem; color: {{ $gw['definition']['color'] }};"></i>
                                </div>
                                <div>
                                    <h2 class="mb-0 fw-800 fs-lg3">{{ $gw['definition']['label'] }}</h2>
                                    <p class="mb-0 text-muted fs-sm3">{{ $gw['definition']['description'] }}</p>
                                </div>
                            </div>
                            <div class="d-flex align-items-center gap-3">
                                <label class="form-switch-label mb-0">
                                    <input type="checkbox" name="gateways[{{ $key }}][is_active]" value="1" class="form-check-input" {{ $gw['setting']->is_active ? 'checked' : '' }}>
                                    <span class="fw-600">{{ $gw['setting']->is_active ? __('super.common.active') : __('super.common.inactive') }}</span>
                                </label>
                            </div>
                        </div>
                    </div>
                </div>

                @if($key !== 'offline')
                {{-- Mode toggle --}}
                <div class="content-card mb-4">
                    <div class="card-header-custom">
                        <h2><i class="bi bi-toggles me-2 text-muted"></i>{{ __('super.gateway_settings.environment') }}</h2>
                    </div>
                    <div class="card-body-custom">
                        <div class="d-flex align-items-center gap-4">
                            <label class="gateway-mode-option {{ $gw['setting']->test_mode ? 'selected' : '' }}">
                                <input type="radio" name="gateways[{{ $key }}][test_mode]" value="1" {{ $gw['setting']->test_mode ? 'checked' : '' }} class="d-none gateway-mode-radio">
                                <div class="gateway-mode-card">
                                    <i class="bi bi-bug fs-xl color-warning"></i>
                                    <div>
                                        <p class="mb-0 fw-700 fs-md">{{ __('super.gateway_settings.sandbox') }}</p>
                                        <p class="mb-0 text-muted fs-xs3">{{ __('super.gateway_settings.sandbox_desc') }}</p>
                                    </div>
                                </div>
                            </label>
                            <label class="gateway-mode-option {{ !$gw['setting']->test_mode ? 'selected' : '' }}">
                                <input type="radio" name="gateways[{{ $key }}][test_mode]" value="0" {{ !$gw['setting']->test_mode ? 'checked' : '' }} class="d-none gateway-mode-radio">
                                <div class="gateway-mode-card">
                                    <i class="bi bi-shield-check fs-xl color-success"></i>
                                    <div>
                                        <p class="mb-0 fw-700 fs-md">{{ __('super.gateway_settings.live') }}</p>
                                        <p class="mb-0 text-muted fs-xs3">{{ __('super.gateway_settings.live_desc') }}</p>
                                    </div>
                                </div>
                            </label>
                        </div>
                    </div>
                </div>

                {{-- Currency configuration --}}
                <div class="content-card mb-4">
                    <div class="card-header-custom">
                        <h2><i class="bi bi-currency-exchange me-2 text-muted"></i>{{ __('central.CurrencyConfiguration') }}</h2>
                    </div>
                    <div class="card-body-custom">
                        <div class="row g-3 mb-3">
                            <div class="col-md-8">
                                <label class="form-label">{{ __('central.SupportedCurrencies') }}</label>
                                <input
                                    type="text"
                                    name="gateways[{{ $key }}][supported_currencies]"
                                    class="form-control"
                                    value="{{ implode(', ', $gw['supported_currencies'] ?? []) }}"
                                    placeholder="e.g. USD, EUR, GBP, NGN"
                                >
                                <p class="form-hint mt-1">{{ __('central.SupportedCurrenciesHint') }}</p>
                            </div>
                            <div class="col-md-4">
                                <label class="form-label">{{ __('central.DefaultCurrency') }}</label>
                                <input
                                    type="text"
                                    name="gateways[{{ $key }}][default_currency]"
                                    class="form-control text-upper-currency"
                                    value="{{ $gw['default_currency'] ?? '' }}"
                                    placeholder="e.g. USD"
                                    maxlength="3"
                                >
                                <p class="form-hint mt-1">{{ __('central.DefaultCurrencyHint') }}</p>
                            </div>
                        </div>
                        @if(! empty($gw['currency_presets']))
                        <div class="d-flex align-items-center gap-2 fs-xs3 text-muted">
                            <i class="bi bi-info-circle"></i>
                            <span>{{ __('central.CurrencyDefaults') }}: {{ implode(', ', array_slice($gw['currency_presets']['supported_currencies'], 0, 8)) }}{{ count($gw['currency_presets']['supported_currencies']) > 8 ? '...' : '' }}</span>
                        </div>
                        @endif
                    </div>
                </div>

                {{-- API credentials --}}
                <div class="content-card">
                    <div class="card-header-custom">
                        <h2><i class="bi bi-key me-2 text-muted"></i>{{ __('super.gateway_settings.api_credentials') }}</h2>
                    </div>
                    <div class="card-body-custom">
                        @foreach($gw['definition']['fields'] as $fieldKey => $field)
                        <div class="form-group mb-4">
                            <label class="form-label">
                                {{ $field['label'] }}
                                @if($field['secret'])
                                    <i class="bi bi-lock-fill text-muted fs-xs" title="{{ __('super.gateway_settings.encrypted') }}"></i>
                                @endif
                            </label>
                            <div class="position-relative">
                                <input
                                    type="{{ $field['secret'] ? 'password' : 'text' }}"
                                    name="gateways[{{ $key }}][{{ $fieldKey }}]"
                                    class="form-control{{ $field['secret'] ? ' secret-field' : '' }}"
                                    value="{{ $field['secret'] ? (!empty($gw['credentials'][$fieldKey]) ? '••••••••' : '') : ($gw['credentials'][$fieldKey] ?? '') }}"
                                    placeholder="{{ $field['placeholder'] ?? '' }}"
                                    autocomplete="off"
                                >
                                @if($field['secret'])
                                <button type="button" class="btn-toggle-secret" title="{{ __('central.ToggleVisibility') }}" tabindex="-1">
                                    <i class="bi bi-eye"></i>
                                </button>
                                @endif
                            </div>
                            @if($field['secret'] && !empty($gw['credentials'][$fieldKey]))
                                <p class="form-hint">{{ __('super.gateway_settings.keep_current') }}</p>
                            @endif
                        </div>
                        @endforeach

                        <div class="divider"></div>
                        <button type="submit" class="btn btn-primary">
                            <i class="bi bi-check-lg"></i> {{ __('super.gateway_settings.save_settings', ['gateway' => $gw['definition']['label']]) }}
                        </button>
                    </div>
                </div>
                @else
                {{-- Offline gateway: no API credentials needed --}}
                <div class="content-card">
                    <div class="card-header-custom">
                        <h2><i class="bi bi-info-circle me-2 text-muted"></i>{{ __('central.HowItWorks') }}</h2>
                    </div>
                    <div class="card-body-custom">
                        <div class="d-flex flex-column gap-3 mb-4">
                            <div class="d-flex gap-2">
                                <i class="bi bi-1-circle-fill flex-shrink-0 offline-step-icon"></i>
                                <span>{!! __('central.OfflineStep1') !!}</span>
                            </div>
                            <div class="d-flex gap-2">
                                <i class="bi bi-2-circle-fill flex-shrink-0 offline-step-icon"></i>
                                <span>{!! __('central.OfflineStep2') !!}</span>
                            </div>
                            <div class="d-flex gap-2">
                                <i class="bi bi-3-circle-fill flex-shrink-0 offline-step-icon"></i>
                                <span>{!! __('central.OfflineStep3') !!}</span>
                            </div>
                            <div class="d-flex gap-2">
                                <i class="bi bi-4-circle-fill flex-shrink-0 offline-step-icon"></i>
                                <span>{{ __('central.OfflineStep4') }}</span>
                            </div>
                        </div>

                        <div class="p-3 rounded-3 offline-notice-box">
                            <p class="mb-0 offline-notice-text">
                                <i class="bi bi-gear me-1"></i>
                                {!! __('central.ConfigureBankDetailsNotice', ['url' => route('super.settings.general') . '#bank-details']) !!}
                            </p>
                        </div>

                        <div class="divider"></div>
                        <button type="submit" class="btn btn-primary">
                            <i class="bi bi-check-lg"></i> {{ __('super.gateway_settings.save_settings', ['gateway' => $gw['definition']['label']]) }}
                        </button>
                    </div>
                </div>
                @endif
            </div>

            {{-- Sidebar --}}
            <div class="col-lg-4">
                <div class="content-card sticky-sidebar">
                    <div class="card-header-custom">
                        <h2><i class="bi bi-info-circle me-2 text-muted"></i>{{ __('super.gateway_settings.setup_guide') }}</h2>
                    </div>
                    <div class="card-body-custom">
                        @if($key === 'offline')
                        <div class="d-flex flex-column gap-3 tips-card-body">
                            <div class="d-flex gap-2">
                                <span class="guide-step-num">1</span>
                                <span>{{ __('central.OfflineGuideStep1') }}</span>
                            </div>
                            <div class="d-flex gap-2">
                                <span class="guide-step-num">2</span>
                                <span>{!! __('central.OfflineGuideStep2') !!}</span>
                            </div>
                            <div class="d-flex gap-2">
                                <span class="guide-step-num">3</span>
                                <span>{!! __('central.OfflineGuideStep3') !!}</span>
                            </div>
                        </div>
                        @else
                        <div class="d-flex flex-column gap-3 tips-card-body">
                            <div class="d-flex gap-2">
                                <span class="guide-step-num">1</span>
                                <span>{{ __('super.gateway_settings.guide_step1', ['gateway' => $gw['definition']['label']]) }}</span>
                            </div>
                            <div class="d-flex gap-2">
                                <span class="guide-step-num">2</span>
                                <span>{{ __('super.gateway_settings.guide_step2') }}</span>
                            </div>
                            <div class="d-flex gap-2">
                                <span class="guide-step-num">3</span>
                                <span>{{ __('super.gateway_settings.guide_step3') }}</span>
                            </div>
                            <div class="d-flex gap-2">
                                <span class="guide-step-num">4</span>
                                <span>{{ __('super.gateway_settings.guide_step4') }}
                                    <code class="guide-webhook-code">{{ url('/webhook/' . $key) }}</code>
                                    @php
                                        $webhookEvents = match($key) {
                                            'stripe' => [
                                                'checkout.session.completed',
                                                'checkout.session.async_payment_succeeded',
                                                'checkout.session.async_payment_failed',
                                            ],
                                            'paypal' => [
                                                'PAYMENT.CAPTURE.COMPLETED',
                                                'PAYMENT.SALE.COMPLETED',
                                                'PAYMENT.CAPTURE.REFUNDED',
                                                'PAYMENT.SALE.REFUNDED',
                                                'PAYMENT.SALE.REVERSED',
                                                'PAYMENT.CAPTURE.DENIED',
                                            ],
                                            'paystack' => [
                                                'charge.success',
                                                'charge.failed',
                                                'refund.processed',
                                                'transfer.reversed',
                                            ],
                                            'flutterwave' => [
                                                'charge.completed',
                                            ],
                                            default => [],
                                        };
                                    @endphp
                                    @if(! empty($webhookEvents))
                                    <span class="guide-events-label">{{ __('super.gateway_settings.guide_events_label') }}</span>
                                    <div class="guide-events-list">
                                        @foreach($webhookEvents as $evt)
                                            <code class="guide-event-code">{{ $evt }}</code>
                                        @endforeach
                                    </div>
                                    @endif
                                </span>
                            </div>
                            <div class="d-flex gap-2">
                                <span class="guide-step-num">5</span>
                                <span>{{ __('super.gateway_settings.guide_step5') }}</span>
                            </div>
                        </div>

                        <div class="divider"></div>

                        <div class="d-flex flex-column gap-2 fs-sm2">
                            <div class="d-flex align-items-center gap-2">
                                <i class="bi bi-shield-lock-fill color-success"></i>
                                <span class="text-muted">{{ __('super.gateway_settings.security_encrypted') }}</span>
                            </div>
                            <div class="d-flex align-items-center gap-2">
                                <i class="bi bi-exclamation-triangle-fill color-warning"></i>
                                <span class="text-muted">{{ __('super.gateway_settings.security_never_share') }}</span>
                            </div>
                        </div>
                        @endif
                    </div>
                </div>
            </div>
        </div>
    </form>
</div>
@endforeach

@push('scripts')
<script>
window.PaymentGatewaysData = {
    activeLabel:   @json(__('super.common.active')),
    inactiveLabel: @json(__('super.common.inactive')),
};
</script>
<script src="{{ asset('assets_super/js/settings-payment-gateways.js') }}"></script>
@endpush

@endsection
