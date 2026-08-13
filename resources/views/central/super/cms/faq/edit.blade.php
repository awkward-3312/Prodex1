@extends('central.super.layout')

@section('title', __('super.faq.edit_title'))

@section('content')
<div class="breadcrumb-custom">
    <a href="{{ route('super.cms.index') }}">CMS</a>
    <span class="separator"><i class="bi bi-chevron-right"></i></span>
    <a href="{{ route('super.cms.faqs') }}">{{ __('super.faq.title') }}</a>
    <span class="separator"><i class="bi bi-chevron-right"></i></span>
    <span class="current">{{ __('super.common.edit') }}</span>
</div>

<div class="page-header">
    <h1>{{ __('super.faq.edit_title') }}</h1>
    <p class="page-subtitle">{{ Str::limit($faq->question, 60) }}</p>
</div>

<div class="row">
    <div class="col-lg-8">
        <div class="content-card">
            <div class="card-body-custom">
                <form method="POST" action="{{ route('super.cms.faq.update', $faq) }}">
                    @csrf
                    @method('PUT')
                    <div class="form-group mb-4">
                        <label class="form-label">{{ __('super.faq.question') }} <span class="text-danger">*</span></label>
                        <input type="text" name="question" class="form-control" value="{{ old('question', $faq->question) }}" required>
                    </div>
                    <div class="form-group mb-4">
                        <label class="form-label">{{ __('super.faq.answer') }} <span class="text-danger">*</span></label>
                        <textarea name="answer" class="form-control" rows="5" required>{{ old('answer', $faq->answer) }}</textarea>
                    </div>
                    <div class="form-row mb-4">
                        <div class="form-group">
                            <label class="form-label">{{ __('super.common.sort_order') }}</label>
                            <input type="number" name="sort_order" class="form-control" value="{{ old('sort_order', $faq->sort_order) }}" min="0">
                        </div>
                        <div class="form-group d-flex align-items-end">
                            <label class="form-switch-label">
                                <input type="checkbox" name="is_active" value="1" class="form-check-input" {{ old('is_active', $faq->is_active) ? 'checked' : '' }}>
                                <span>{{ __('super.common.active') }}</span>
                            </label>
                        </div>
                    </div>
                    <div class="divider"></div>
                    <div class="d-flex gap-2">
                        <button type="submit" class="btn btn-primary"><i class="bi bi-check-lg"></i> {{ __('super.common.update') }}</button>
                        <a href="{{ route('super.cms.faqs') }}" class="btn btn-secondary">{{ __('super.common.cancel') }}</a>
                    </div>
                </form>
            </div>
        </div>
    </div>
</div>
@endsection
