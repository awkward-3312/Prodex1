@extends('central.super.layout')

@section('title', __('super.payments.create_title'))

@section('content')
<div class="breadcrumb-custom">
    <a href="{{ route('super.payments.index') }}">{{ __('super.payments.transactions_title') }}</a>
    <span class="separator"><i class="bi bi-chevron-right"></i></span>
    <span class="current">{{ __('super.payments.record_payment') }}</span>
</div>

<div class="page-header">
    <h1>{{ __('super.payments.create_sub') }}</h1>
    <p class="page-subtitle">{{ __('super.payments.create_desc') }}</p>
</div>

<div class="row g-4">
    <div class="col-lg-8">
        <div class="content-card">
            <div class="card-header-custom">
                <h2><i class="bi bi-plus-circle me-2 text-muted"></i>{{ __('super.payments.payment_details') }}</h2>
            </div>
            <div class="card-body-custom">
                <form method="POST" action="{{ route('super.payments.store') }}">
                    @csrf

                    <div class="form-row mb-4">
                        <div class="form-group">
                            <label class="form-label">{{ __('super.payments.tenant') }} <span class="text-danger">*</span></label>
                            <select name="tenant_id" class="form-control" required>
                                <option value="">{{ __('super.payments.select_tenant') }}</option>
                                @foreach($tenants as $tenant)
                                    <option value="{{ $tenant->id }}" {{ old('tenant_id') === $tenant->id ? 'selected' : '' }}>
                                        {{ $tenant->id }}
                                        @if($tenant->subscription && $tenant->subscription->plan)
                                            — {{ $tenant->subscription->plan->name }}
                                        @endif
                                    </option>
                                @endforeach
                            </select>
                            @error('tenant_id')<p class="text-danger form-error">{{ $message }}</p>@enderror
                        </div>
                        <div class="form-group">
                            <label class="form-label">{{ __('super.payments.plan') }}</label>
                            <select name="plan_id" class="form-control">
                                <option value="">{{ __('super.payments.auto_detect_plan') }}</option>
                                @foreach($plans as $plan)
                                    <option value="{{ $plan->id }}" {{ old('plan_id') == $plan->id ? 'selected' : '' }}>
                                        {{ $plan->name }} ({{ $currencySymbol }}{{ number_format($plan->price, 2) }})
                                    </option>
                                @endforeach
                            </select>
                        </div>
                    </div>

                    <div class="form-row mb-4">
                        <div class="form-group">
                            <label class="form-label">{{ __('super.common.amount') }} <span class="text-danger">*</span></label>
                            <div class="d-flex gap-2">
                                <div class="currency-input-wrap">
                                    <span class="currency-input-symbol">{{ $currencySymbol }}</span>
                                    <input type="number" name="amount" class="form-control currency-input" value="{{ old('amount') }}" step="0.01" min="0.01" required>
                                </div>
                            </div>
                            @error('amount')<p class="text-danger form-error">{{ $message }}</p>@enderror
                        </div>
                        <div class="form-group">
                            <label class="form-label">{{ __('super.payments.tax') }}</label>
                            <div class="currency-input-wrap">
                                <span class="currency-input-symbol">{{ $currencySymbol }}</span>
                                <input type="number" name="tax" class="form-control currency-input" value="{{ old('tax', '0') }}" step="0.01" min="0">
                            </div>
                        </div>
                    </div>

                    <div class="form-row mb-4">
                        <div class="form-group">
                            <label class="form-label">{{ __('super.payments.currency') }} <span class="text-danger">*</span></label>
                            <input type="text" name="currency" class="form-control" value="{{ old('currency', $currencyCode) }}" maxlength="3" required>
                        </div>
                        <div class="form-group">
                            <label class="form-label">{{ __('super.payments.billing_cycle') }} <span class="text-danger">*</span></label>
                            <select name="billing_cycle" class="form-control" required>
                                <option value="monthly" {{ old('billing_cycle') === 'monthly' ? 'selected' : '' }}>{{ __('super.payments.monthly') }}</option>
                                <option value="yearly" {{ old('billing_cycle') === 'yearly' ? 'selected' : '' }}>{{ __('super.payments.yearly') }}</option>
                            </select>
                        </div>
                    </div>

                    <div class="form-row mb-4">
                        <div class="form-group">
                            <label class="form-label">{{ __('super.payments.payment_gateway') }} <span class="text-danger">*</span></label>
                            <select name="gateway" class="form-control" required>
                                @foreach(\App\Models\Central\TenantBillingPayment::GATEWAYS as $key => $label)
                                    <option value="{{ $key }}" {{ old('gateway', 'manual') === $key ? 'selected' : '' }}>{{ $label }}</option>
                                @endforeach
                            </select>
                        </div>
                        <div class="form-group">
                            <label class="form-label">{{ __('super.payments.transaction_id') }}</label>
                            <input type="text" name="transaction_id" class="form-control" value="{{ old('transaction_id') }}" placeholder="{{ __('super.payments.transaction_hint') }}">
                        </div>
                    </div>

                    <div class="form-row mb-4">
                        <div class="form-group">
                            <label class="form-label">{{ __('super.common.status') }} <span class="text-danger">*</span></label>
                            <select name="status" class="form-control" required>
                                @foreach(\App\Models\Central\TenantBillingPayment::STATUSES as $s)
                                    <option value="{{ $s }}" {{ old('status', 'paid') === $s ? 'selected' : '' }}>{{ ucfirst($s) }}</option>
                                @endforeach
                            </select>
                        </div>
                        <div class="form-group">
                            <label class="form-label">{{ __('super.payments.payment_date') }}</label>
                            <input type="datetime-local" name="paid_at" class="form-control" value="{{ old('paid_at', now()->format('Y-m-d\TH:i')) }}">
                        </div>
                    </div>

                    <div class="form-group mb-4">
                        <label class="form-label">{{ __('super.common.notes') }}</label>
                        <textarea name="notes" class="form-control" rows="3" placeholder="{{ __('super.payments.notes_hint') }}">{{ old('notes') }}</textarea>
                    </div>

                    <div class="divider"></div>
                    <div class="d-flex gap-2">
                        <button type="submit" class="btn btn-primary"><i class="bi bi-plus-lg"></i> {{ __('super.payments.record_payment') }}</button>
                        <a href="{{ route('super.payments.index') }}" class="btn btn-secondary">{{ __('super.common.cancel') }}</a>
                    </div>
                </form>
            </div>
        </div>
    </div>

    <div class="col-lg-4">
        <div class="content-card sticky-sidebar">
            <div class="card-body-custom">
                <div class="d-flex align-items-center gap-2 mb-3">
                    <i class="bi bi-lightbulb text-muted fs-base"></i>
                    <h3 class="mb-0 fw-700 tips-card-title">{{ __('super.payments.tips') }}</h3>
                </div>
                <div class="d-flex flex-column gap-3 tips-card-body">
                    <div class="d-flex gap-2">
                        <i class="bi bi-info-circle tips-icon-info"></i>
                        <span>{{ __('super.payments.tip_1') }}</span>
                    </div>
                    <div class="d-flex gap-2">
                        <i class="bi bi-info-circle tips-icon-info"></i>
                        <span>{{ __('super.payments.tip_2') }}</span>
                    </div>
                    <div class="d-flex gap-2">
                        <i class="bi bi-info-circle tips-icon-info"></i>
                        <span>{{ __('super.payments.tip_3') }}</span>
                    </div>
                    <div class="d-flex gap-2">
                        <i class="bi bi-shield-check tips-icon-success"></i>
                        <span>{{ __('super.payments.tip_4') }}</span>
                    </div>
                </div>
            </div>
        </div>
    </div>
</div>
@endsection
