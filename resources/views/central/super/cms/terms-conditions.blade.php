@extends('central.super.layout')

@section('title', __('super.terms.title'))

@section('content')
<div class="breadcrumb-custom">
    <a href="{{ route('super.cms.index') }}">CMS</a>
    <span class="separator"><i class="bi bi-chevron-right"></i></span>
    <span class="current">{{ __('super.terms.title') }}</span>
</div>

<div class="page-header">
    <h1>{{ __('super.terms.title') }}</h1>
    <p class="page-subtitle">{{ __('super.terms.subtitle') }}</p>
</div>

<form method="POST" action="{{ route('super.cms.terms-conditions.update') }}">
    @csrf
    @method('PUT')

    <div class="row g-4">
        <div class="col-lg-8">
            <div class="content-card mb-4">
                <div class="card-header-custom">
                    <h2><i class="bi bi-file-earmark-check me-2 text-muted"></i>{{ __('super.terms.section_acceptance') }}</h2>
                </div>
                <div class="card-body-custom">
                    <div class="form-group">
                        <textarea name="acceptance" class="form-control" rows="4">{{ old('acceptance', $terms->acceptance) }}</textarea>
                    </div>
                </div>
            </div>

            <div class="content-card mb-4">
                <div class="card-header-custom">
                    <h2><i class="bi bi-box-arrow-in-right me-2 text-muted"></i>{{ __('super.terms.section_license') }}</h2>
                </div>
                <div class="card-body-custom">
                    <div class="form-group">
                        <textarea name="use_license" class="form-control" rows="4">{{ old('use_license', $terms->use_license) }}</textarea>
                    </div>
                </div>
            </div>

            <div class="content-card mb-4">
                <div class="card-header-custom">
                    <h2><i class="bi bi-person-gear me-2 text-muted"></i>{{ __('super.terms.section_accounts') }}</h2>
                </div>
                <div class="card-body-custom">
                    <div class="form-group">
                        <textarea name="user_accounts" class="form-control" rows="4">{{ old('user_accounts', $terms->user_accounts) }}</textarea>
                    </div>
                </div>
            </div>

            <div class="content-card mb-4">
                <div class="card-header-custom">
                    <h2><i class="bi bi-credit-card me-2 text-muted"></i>{{ __('super.terms.section_payments') }}</h2>
                </div>
                <div class="card-body-custom">
                    <div class="form-group">
                        <textarea name="payments" class="form-control" rows="4">{{ old('payments', $terms->payments) }}</textarea>
                    </div>
                </div>
            </div>

            <div class="content-card mb-4">
                <div class="card-header-custom">
                    <h2><i class="bi bi-slash-circle me-2 text-muted"></i>{{ __('super.terms.section_prohibited') }}</h2>
                </div>
                <div class="card-body-custom">
                    <div class="form-group">
                        <textarea name="prohibited" class="form-control" rows="4">{{ old('prohibited', $terms->prohibited) }}</textarea>
                    </div>
                </div>
            </div>

            <div class="content-card mb-4">
                <div class="card-header-custom">
                    <h2><i class="bi bi-c-circle me-2 text-muted"></i>{{ __('super.terms.section_ip') }}</h2>
                </div>
                <div class="card-body-custom">
                    <div class="form-group">
                        <textarea name="intellectual_property" class="form-control" rows="4">{{ old('intellectual_property', $terms->intellectual_property) }}</textarea>
                    </div>
                </div>
            </div>

            <div class="content-card mb-4">
                <div class="card-header-custom">
                    <h2><i class="bi bi-shield-exclamation me-2 text-muted"></i>{{ __('super.terms.section_liability') }}</h2>
                </div>
                <div class="card-body-custom">
                    <div class="form-group">
                        <textarea name="liability" class="form-control" rows="4">{{ old('liability', $terms->liability) }}</textarea>
                    </div>
                </div>
            </div>

            <div class="content-card mb-4">
                <div class="card-header-custom">
                    <h2><i class="bi bi-bank me-2 text-muted"></i>{{ __('super.terms.section_law') }}</h2>
                </div>
                <div class="card-body-custom">
                    <div class="form-group">
                        <textarea name="governing_law" class="form-control" rows="4">{{ old('governing_law', $terms->governing_law) }}</textarea>
                    </div>
                </div>
            </div>
        </div>

        <div class="col-lg-4">
            <div class="content-card mb-4">
                <div class="card-header-custom">
                    <h2><i class="bi bi-sliders me-2 text-muted"></i>{{ __('super.terms.settings') }}</h2>
                </div>
                <div class="card-body-custom">
                    <div class="form-group mb-4">
                        <label class="form-label">{{ __('super.terms.last_updated') }}</label>
                        <input type="date" name="last_updated" class="form-control" value="{{ old('last_updated', $terms->last_updated ? $terms->last_updated->format('Y-m-d') : now()->format('Y-m-d')) }}">
                        <small class="text-muted">{{ __('super.terms.last_updated_hint') }}</small>
                    </div>
                    <div class="form-group">
                        <div class="form-check form-switch">
                            <input class="form-check-input" type="checkbox" name="is_active" value="1" id="isActive" {{ old('is_active', $terms->is_active) ? 'checked' : '' }}>
                            <label class="form-check-label" for="isActive">{{ __('super.terms.show_page') }}</label>
                        </div>
                        <small class="text-muted">{{ __('super.terms.show_page_hint') }}</small>
                    </div>
                </div>
            </div>

            <div class="content-card">
                <div class="card-header-custom">
                    <h2><i class="bi bi-lightbulb me-2 text-muted"></i>{{ __('super.terms.tips_title') }}</h2>
                </div>
                <div class="card-body-custom">
                    <ul class="list-unstyled mb-0 tips-list">
                        <li class="mb-2"><i class="bi bi-check-circle text-success me-2"></i>{{ __('super.terms.tip_1') }}</li>
                        <li class="mb-2"><i class="bi bi-check-circle text-success me-2"></i>{{ __('super.terms.tip_2') }}</li>
                        <li><i class="bi bi-check-circle text-success me-2"></i>{{ __('super.terms.tip_3') }}</li>
                    </ul>
                </div>
            </div>
        </div>
    </div>

    <div class="mt-4 d-flex gap-2">
        <button type="submit" class="btn btn-primary"><i class="bi bi-check-lg"></i> {{ __('super.common.save') }}</button>
        <a href="{{ route('central.terms-conditions') }}" target="_blank" class="btn btn-secondary"><i class="bi bi-box-arrow-up-right"></i> {{ __('super.common.preview') }}</a>
    </div>
</form>
@endsection
