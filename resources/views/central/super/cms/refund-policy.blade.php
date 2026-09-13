@extends('central.super.layout')

@section('title', __('super.refund.title'))

@section('content')
<div class="breadcrumb-custom">
    <a href="{{ route('super.cms.index') }}">CMS</a>
    <span class="separator"><i class="bi bi-chevron-right"></i></span>
    <span class="current">{{ __('super.refund.title') }}</span>
</div>

<div class="page-header">
    <h1>{{ __('super.refund.title') }}</h1>
    <p class="page-subtitle">{{ __('super.refund.subtitle') }}</p>
</div>

<form method="POST" action="{{ route('super.cms.refund-policy.update') }}">
    @csrf
    @method('PUT')

    <div class="row g-4">
        <div class="col-lg-8">
            <div class="content-card mb-4">
                <div class="card-header-custom">
                    <h2><i class="bi bi-info-circle me-2 text-muted"></i>{{ __('super.refund.section_overview') }}</h2>
                </div>
                <div class="card-body-custom">
                    <div class="form-group">
                        <textarea name="overview" class="form-control" rows="4">{{ old('overview', $refund->overview) }}</textarea>
                    </div>
                </div>
            </div>

            <div class="content-card mb-4">
                <div class="card-header-custom">
                    <h2><i class="bi bi-hourglass-split me-2 text-muted"></i>{{ __('super.refund.section_subscriptions_trials') }}</h2>
                </div>
                <div class="card-body-custom">
                    <div class="form-group">
                        <textarea name="subscriptions_trials" class="form-control" rows="4">{{ old('subscriptions_trials', $refund->subscriptions_trials) }}</textarea>
                    </div>
                </div>
            </div>

            <div class="content-card mb-4">
                <div class="card-header-custom">
                    <h2><i class="bi bi-x-circle me-2 text-muted"></i>{{ __('super.refund.section_cancellations') }}</h2>
                </div>
                <div class="card-body-custom">
                    <div class="form-group">
                        <textarea name="cancellations" class="form-control" rows="4">{{ old('cancellations', $refund->cancellations) }}</textarea>
                    </div>
                </div>
            </div>

            <div class="content-card mb-4">
                <div class="card-header-custom">
                    <h2><i class="bi bi-exclamation-triangle me-2 text-muted"></i>{{ __('super.refund.section_billing_errors') }}</h2>
                </div>
                <div class="card-body-custom">
                    <div class="form-group">
                        <textarea name="billing_errors" class="form-control" rows="4">{{ old('billing_errors', $refund->billing_errors) }}</textarea>
                    </div>
                </div>
            </div>

            <div class="content-card mb-4">
                <div class="card-header-custom">
                    <h2><i class="bi bi-cash-coin me-2 text-muted"></i>{{ __('super.refund.section_eligibility') }}</h2>
                </div>
                <div class="card-body-custom">
                    <div class="form-group">
                        <textarea name="refund_eligibility" class="form-control" rows="4">{{ old('refund_eligibility', $refund->refund_eligibility) }}</textarea>
                    </div>
                </div>
            </div>

            <div class="content-card mb-4">
                <div class="card-header-custom">
                    <h2><i class="bi bi-shield-x me-2 text-muted"></i>{{ __('super.refund.section_chargebacks') }}</h2>
                </div>
                <div class="card-body-custom">
                    <div class="form-group">
                        <textarea name="chargebacks" class="form-control" rows="4">{{ old('chargebacks', $refund->chargebacks) }}</textarea>
                    </div>
                </div>
            </div>

            <div class="content-card mb-4">
                <div class="card-header-custom">
                    <h2><i class="bi bi-chat-dots me-2 text-muted"></i>{{ __('super.refund.section_how_to_request') }}</h2>
                </div>
                <div class="card-body-custom">
                    <div class="form-group">
                        <textarea name="how_to_request" class="form-control" rows="4">{{ old('how_to_request', $refund->how_to_request) }}</textarea>
                    </div>
                </div>
            </div>

            <div class="content-card mb-4">
                <div class="card-header-custom">
                    <h2><i class="bi bi-bank2 me-2 text-muted"></i>{{ __('super.refund.section_payment_processor') }}</h2>
                </div>
                <div class="card-body-custom">
                    <div class="form-group">
                        <textarea name="payment_processor" class="form-control" rows="4">{{ old('payment_processor', $refund->payment_processor) }}</textarea>
                    </div>
                </div>
            </div>
        </div>

        <div class="col-lg-4">
            <div class="content-card mb-4">
                <div class="card-header-custom">
                    <h2><i class="bi bi-sliders me-2 text-muted"></i>{{ __('super.refund.settings') }}</h2>
                </div>
                <div class="card-body-custom">
                    <div class="form-group mb-4">
                        <label class="form-label">{{ __('super.refund.last_updated') }}</label>
                        <input type="date" name="last_updated" class="form-control" value="{{ old('last_updated', $refund->last_updated ? $refund->last_updated->format('Y-m-d') : now()->format('Y-m-d')) }}">
                        <small class="text-muted">{{ __('super.refund.last_updated_hint') }}</small>
                    </div>
                    <div class="form-group">
                        <div class="form-check form-switch">
                            <input class="form-check-input" type="checkbox" name="is_active" value="1" id="isActive" {{ old('is_active', $refund->is_active) ? 'checked' : '' }}>
                            <label class="form-check-label" for="isActive">{{ __('super.refund.show_page') }}</label>
                        </div>
                        <small class="text-muted">{{ __('super.refund.show_page_hint') }}</small>
                    </div>
                </div>
            </div>

            <div class="content-card">
                <div class="card-header-custom">
                    <h2><i class="bi bi-lightbulb me-2 text-muted"></i>{{ __('super.refund.tips_title') }}</h2>
                </div>
                <div class="card-body-custom">
                    <ul class="list-unstyled mb-0 tips-list">
                        <li class="mb-2"><i class="bi bi-check-circle text-success me-2"></i>{{ __('super.refund.tip_1') }}</li>
                        <li class="mb-2"><i class="bi bi-check-circle text-success me-2"></i>{{ __('super.refund.tip_2') }}</li>
                        <li><i class="bi bi-check-circle text-success me-2"></i>{{ __('super.refund.tip_3') }}</li>
                    </ul>
                </div>
            </div>
        </div>
    </div>

    <div class="mt-4 d-flex gap-2">
        <button type="submit" class="btn btn-primary"><i class="bi bi-check-lg"></i> {{ __('super.common.save') }}</button>
        <a href="{{ route('central.refund-policy') }}" target="_blank" class="btn btn-secondary"><i class="bi bi-box-arrow-up-right"></i> {{ __('super.common.preview') }}</a>
    </div>
</form>
@endsection
