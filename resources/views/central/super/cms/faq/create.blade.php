@extends('central.super.layout')

@section('title', __('super.faq.add_title'))

@section('content')
<div class="breadcrumb-custom">
    <a href="{{ route('super.cms.index') }}">CMS</a>
    <span class="separator"><i class="bi bi-chevron-right"></i></span>
    <a href="{{ route('super.cms.faqs') }}">{{ __('super.faq.title') }}</a>
    <span class="separator"><i class="bi bi-chevron-right"></i></span>
    <span class="current">{{ __('super.faq.add_title') }}</span>
</div>

<div class="page-header">
    <h1>{{ __('super.faq.add_title') }}</h1>
    <p class="page-subtitle">{{ __('super.faq.add_subtitle') }}</p>
</div>

<div class="row">
    <div class="col-lg-8">
        <div class="content-card">
            <div class="card-body-custom">
                <form method="POST" action="{{ route('super.cms.faq.store') }}">
                    @csrf
                    <div class="form-group mb-4">
                        <label class="form-label">{{ __('super.faq.question') }} <span class="text-danger">*</span></label>
                        <input type="text" name="question" class="form-control" value="{{ old('question') }}" placeholder="e.g. How do I get started?" required>
                    </div>
                    <div class="form-group mb-4">
                        <label class="form-label">{{ __('super.faq.answer') }} <span class="text-danger">*</span></label>
                        <textarea name="answer" class="form-control" rows="5" placeholder="{{ __('super.faq.answer_hint') }}" required>{{ old('answer') }}</textarea>
                    </div>
                    <div class="form-row mb-4">
                        <div class="form-group">
                            <label class="form-label">{{ __('super.common.sort_order') }}</label>
                            <input type="number" name="sort_order" class="form-control" value="{{ old('sort_order', 0) }}" min="0">
                        </div>
                        <div class="form-group d-flex align-items-end">
                            <label class="form-switch-label">
                                <input type="checkbox" name="is_active" value="1" class="form-check-input" {{ old('is_active', true) ? 'checked' : '' }}>
                                <span>{{ __('super.common.active') }}</span>
                            </label>
                        </div>
                    </div>
                    <div class="divider"></div>
                    <div class="d-flex gap-2">
                        <button type="submit" class="btn btn-primary"><i class="bi bi-plus-lg"></i> {{ __('super.common.create') }}</button>
                        <a href="{{ route('super.cms.faqs') }}" class="btn btn-secondary">{{ __('super.common.cancel') }}</a>
                    </div>
                </form>
            </div>
        </div>
    </div>
</div>
@endsection
