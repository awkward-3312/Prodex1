@extends('central.super.layout')

@section('title', __('super.footer.title'))

@section('content')
<div class="breadcrumb-custom">
    <a href="{{ route('super.cms.index') }}">CMS</a>
    <span class="separator"><i class="bi bi-chevron-right"></i></span>
    <span class="current">{{ __('super.footer.title') }}</span>
</div>

<div class="page-header">
    <h1>{{ __('super.footer.title') }}</h1>
    <p class="page-subtitle">{{ __('super.footer.subtitle') }}</p>
</div>

<form method="POST" action="{{ route('super.cms.footer.update') }}">
    @csrf
    @method('PUT')

    <div class="row g-4">
        <div class="col-lg-6">
            <div class="content-card mb-4">
                <div class="card-header-custom">
                    <h2><i class="bi bi-info-circle me-2 text-muted"></i>{{ __('super.footer.general') }}</h2>
                </div>
                <div class="card-body-custom">
                    <div class="form-group mb-4">
                        <label class="form-label">{{ __('super.footer.about_text') }}</label>
                        <textarea name="footer_about" class="form-control" rows="3" placeholder="Brief description of your company">{{ old('footer_about', $footer->footer_about) }}</textarea>
                    </div>
                    <div class="form-group mb-4">
                        <label class="form-label">{{ __('super.footer.copyright') }}</label>
                        <input type="text" name="copyright_text" class="form-control" value="{{ old('copyright_text', $footer->copyright_text) }}" placeholder="e.g. © 2026 Stocky. All rights reserved.">
                    </div>
                </div>
            </div>

            <div class="content-card">
                <div class="card-header-custom">
                    <h2><i class="bi bi-envelope me-2 text-muted"></i>{{ __('super.footer.contact_info') }}</h2>
                </div>
                <div class="card-body-custom">
                    <div class="form-group mb-4">
                        <label class="form-label">{{ __('super.footer.email') }}</label>
                        <input type="email" name="contact_email" class="form-control" value="{{ old('contact_email', $footer->contact_email) }}" placeholder="hello@company.com">
                    </div>
                    <div class="form-group mb-4">
                        <label class="form-label">{{ __('super.footer.phone') }}</label>
                        <input type="text" name="contact_phone" class="form-control" value="{{ old('contact_phone', $footer->contact_phone) }}" placeholder="+1 (555) 123-4567">
                    </div>
                    <div class="form-group">
                        <label class="form-label">{{ __('super.footer.address') }}</label>
                        <textarea name="address" class="form-control" rows="2" placeholder="123 Main St, City, Country">{{ old('address', $footer->address) }}</textarea>
                    </div>
                </div>
            </div>
        </div>

        <div class="col-lg-6">
            <div class="content-card">
                <div class="card-header-custom">
                    <h2><i class="bi bi-share me-2 text-muted"></i>{{ __('super.footer.social_links') }}</h2>
                </div>
                <div class="card-body-custom">
                    <div class="form-group mb-4">
                        <label class="form-label"><i class="bi bi-facebook me-2 text-muted"></i>{{ __('super.footer.facebook') }}</label>
                        <input type="url" name="facebook" class="form-control" value="{{ old('facebook', $footer->facebook) }}" placeholder="https://facebook.com/yourpage">
                    </div>
                    <div class="form-group mb-4">
                        <label class="form-label"><i class="bi bi-twitter-x me-2 text-muted"></i>{{ __('super.footer.twitter') }}</label>
                        <input type="url" name="twitter" class="form-control" value="{{ old('twitter', $footer->twitter) }}" placeholder="https://x.com/yourhandle">
                    </div>
                    <div class="form-group mb-4">
                        <label class="form-label"><i class="bi bi-linkedin me-2 text-muted"></i>{{ __('super.footer.linkedin') }}</label>
                        <input type="url" name="linkedin" class="form-control" value="{{ old('linkedin', $footer->linkedin) }}" placeholder="https://linkedin.com/company/yours">
                    </div>
                    <div class="form-group mb-4">
                        <label class="form-label"><i class="bi bi-instagram me-2 text-muted"></i>{{ __('super.footer.instagram') }}</label>
                        <input type="url" name="instagram" class="form-control" value="{{ old('instagram', $footer->instagram) }}" placeholder="https://instagram.com/yourpage">
                    </div>
                    <div class="form-group">
                        <label class="form-label"><i class="bi bi-youtube me-2 text-muted"></i>{{ __('super.footer.youtube') }}</label>
                        <input type="url" name="youtube" class="form-control" value="{{ old('youtube', $footer->youtube) }}" placeholder="https://youtube.com/@yourchannel">
                    </div>
                </div>
            </div>
        </div>
    </div>

    <div class="mt-4 d-flex gap-2">
        <button type="submit" class="btn btn-primary"><i class="bi bi-check-lg"></i> {{ __('super.common.save') }}</button>
        <a href="{{ route('central.welcome') }}" target="_blank" class="btn btn-secondary"><i class="bi bi-box-arrow-up-right"></i> {{ __('super.common.preview') }}</a>
    </div>
</form>
@endsection
