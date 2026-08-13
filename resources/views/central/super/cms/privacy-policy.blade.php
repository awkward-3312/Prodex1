@extends('central.super.layout')

@section('title', __('super.privacy.title'))

@section('content')
<div class="breadcrumb-custom">
    <a href="{{ route('super.cms.index') }}">CMS</a>
    <span class="separator"><i class="bi bi-chevron-right"></i></span>
    <span class="current">{{ __('super.privacy.title') }}</span>
</div>

<div class="page-header">
    <h1>{{ __('super.privacy.title') }}</h1>
    <p class="page-subtitle">{{ __('super.privacy.subtitle') }}</p>
</div>

<form method="POST" action="{{ route('super.cms.privacy-policy.update') }}">
    @csrf
    @method('PUT')

    <div class="row g-4">
        <div class="col-lg-8">
            <div class="content-card mb-4">
                <div class="card-header-custom">
                    <h2><i class="bi bi-file-text me-2 text-muted"></i>{{ __('super.privacy.section_introduction') }}</h2>
                </div>
                <div class="card-body-custom">
                    <div class="form-group">
                        <textarea name="introduction" class="form-control" rows="4" placeholder="{{ __('super.privacy.placeholder_intro') }}">{{ old('introduction', $privacy->introduction) }}</textarea>
                    </div>
                </div>
            </div>

            <div class="content-card mb-4">
                <div class="card-header-custom">
                    <h2><i class="bi bi-database me-2 text-muted"></i>{{ __('super.privacy.section_data_collection') }}</h2>
                </div>
                <div class="card-body-custom">
                    <div class="form-group">
                        <textarea name="data_collection" class="form-control" rows="4" placeholder="{{ __('super.privacy.placeholder_collection') }}">{{ old('data_collection', $privacy->data_collection) }}</textarea>
                    </div>
                </div>
            </div>

            <div class="content-card mb-4">
                <div class="card-header-custom">
                    <h2><i class="bi bi-gear me-2 text-muted"></i>{{ __('super.privacy.section_data_usage') }}</h2>
                </div>
                <div class="card-body-custom">
                    <div class="form-group">
                        <textarea name="data_usage" class="form-control" rows="4" placeholder="{{ __('super.privacy.placeholder_usage') }}">{{ old('data_usage', $privacy->data_usage) }}</textarea>
                    </div>
                </div>
            </div>

            <div class="content-card mb-4">
                <div class="card-header-custom">
                    <h2><i class="bi bi-cookie me-2 text-muted"></i>{{ __('super.privacy.section_cookies') }}</h2>
                </div>
                <div class="card-body-custom">
                    <div class="form-group">
                        <textarea name="cookies_usage" class="form-control" rows="4" placeholder="{{ __('super.privacy.placeholder_cookies') }}">{{ old('cookies_usage', $privacy->cookies_usage) }}</textarea>
                    </div>
                </div>
            </div>

            <div class="content-card mb-4">
                <div class="card-header-custom">
                    <h2><i class="bi bi-share me-2 text-muted"></i>{{ __('super.privacy.section_third_party') }}</h2>
                </div>
                <div class="card-body-custom">
                    <div class="form-group">
                        <textarea name="third_party" class="form-control" rows="4" placeholder="{{ __('super.privacy.placeholder_third_party') }}">{{ old('third_party', $privacy->third_party) }}</textarea>
                    </div>
                </div>
            </div>

            <div class="content-card mb-4">
                <div class="card-header-custom">
                    <h2><i class="bi bi-lock me-2 text-muted"></i>{{ __('super.privacy.section_protection') }}</h2>
                </div>
                <div class="card-body-custom">
                    <div class="form-group">
                        <textarea name="data_protection" class="form-control" rows="4" placeholder="{{ __('super.privacy.placeholder_protection') }}">{{ old('data_protection', $privacy->data_protection) }}</textarea>
                    </div>
                </div>
            </div>

            <div class="content-card mb-4">
                <div class="card-header-custom">
                    <h2><i class="bi bi-person-check me-2 text-muted"></i>{{ __('super.privacy.section_rights') }}</h2>
                </div>
                <div class="card-body-custom">
                    <div class="form-group">
                        <textarea name="user_rights" class="form-control" rows="4" placeholder="{{ __('super.privacy.placeholder_rights') }}">{{ old('user_rights', $privacy->user_rights) }}</textarea>
                    </div>
                </div>
            </div>

            <div class="content-card mb-4">
                <div class="card-header-custom">
                    <h2><i class="bi bi-envelope me-2 text-muted"></i>{{ __('super.privacy.section_contact') }}</h2>
                </div>
                <div class="card-body-custom">
                    <div class="form-group">
                        <textarea name="contact_info" class="form-control" rows="3" placeholder="{{ __('super.privacy.placeholder_contact') }}">{{ old('contact_info', $privacy->contact_info) }}</textarea>
                    </div>
                </div>
            </div>
        </div>

        <div class="col-lg-4">
            <div class="content-card mb-4">
                <div class="card-header-custom">
                    <h2><i class="bi bi-sliders me-2 text-muted"></i>{{ __('super.privacy.settings') }}</h2>
                </div>
                <div class="card-body-custom">
                    <div class="form-group mb-4">
                        <label class="form-label">{{ __('super.privacy.last_updated') }}</label>
                        <input type="date" name="last_updated" class="form-control" value="{{ old('last_updated', $privacy->last_updated ? $privacy->last_updated->format('Y-m-d') : now()->format('Y-m-d')) }}">
                        <small class="text-muted">{{ __('super.privacy.last_updated_hint') }}</small>
                    </div>
                    <div class="form-group">
                        <div class="form-check form-switch">
                            <input class="form-check-input" type="checkbox" name="is_active" value="1" id="isActive" {{ old('is_active', $privacy->is_active) ? 'checked' : '' }}>
                            <label class="form-check-label" for="isActive">{{ __('super.privacy.show_page') }}</label>
                        </div>
                        <small class="text-muted">{{ __('super.privacy.show_page_hint') }}</small>
                    </div>
                </div>
            </div>

            <div class="content-card">
                <div class="card-header-custom">
                    <h2><i class="bi bi-lightbulb me-2 text-muted"></i>{{ __('super.privacy.tips_title') }}</h2>
                </div>
                <div class="card-body-custom">
                    <ul class="list-unstyled mb-0 tips-list">
                        <li class="mb-2"><i class="bi bi-check-circle text-success me-2"></i>{{ __('super.privacy.tip_1') }}</li>
                        <li class="mb-2"><i class="bi bi-check-circle text-success me-2"></i>{{ __('super.privacy.tip_2') }}</li>
                        <li><i class="bi bi-check-circle text-success me-2"></i>{{ __('super.privacy.tip_3') }}</li>
                    </ul>
                </div>
            </div>
        </div>
    </div>

    <div class="mt-4 d-flex gap-2">
        <button type="submit" class="btn btn-primary"><i class="bi bi-check-lg"></i> {{ __('super.common.save') }}</button>
        <a href="{{ route('central.privacy-policy') }}" target="_blank" class="btn btn-secondary"><i class="bi bi-box-arrow-up-right"></i> {{ __('super.common.preview') }}</a>
    </div>
</form>
@endsection
