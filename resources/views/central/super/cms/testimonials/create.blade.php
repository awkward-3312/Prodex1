@extends('central.super.layout')

@section('title', __('super.testimonials.add_title'))

@section('content')
<div class="breadcrumb-custom">
    <a href="{{ route('super.cms.index') }}">CMS</a>
    <span class="separator"><i class="bi bi-chevron-right"></i></span>
    <a href="{{ route('super.cms.testimonials') }}">{{ __('super.testimonials.title') }}</a>
    <span class="separator"><i class="bi bi-chevron-right"></i></span>
    <span class="current">{{ __('super.testimonials.add_title') }}</span>
</div>

<div class="page-header">
    <h1>{{ __('super.testimonials.add_title') }}</h1>
    <p class="page-subtitle">{{ __('super.testimonials.add_desc') }}</p>
</div>

<div class="row">
    <div class="col-lg-8">
        <div class="content-card">
            <div class="card-body-custom">
                <form method="POST" action="{{ route('super.cms.testimonials.store') }}" enctype="multipart/form-data">
                    @csrf
                    <div class="form-row mb-4">
                        <div class="form-group">
                            <label class="form-label">{{ __('super.testimonials.client_name') }} <span class="text-danger">*</span></label>
                            <input type="text" name="client_name" class="form-control" value="{{ old('client_name') }}" placeholder="e.g. John Smith" required>
                        </div>
                        <div class="form-group">
                            <label class="form-label">{{ __('super.testimonials.company_name') }}</label>
                            <input type="text" name="company_name" class="form-control" value="{{ old('company_name') }}" placeholder="e.g. Acme Corp">
                        </div>
                    </div>
                    <div class="form-group mb-4">
                        <label class="form-label">{{ __('super.testimonials.review') }} <span class="text-danger">*</span></label>
                        <textarea name="review" class="form-control" rows="4" placeholder="{{ __('super.testimonials.review_hint') }}" required>{{ old('review') }}</textarea>
                    </div>
                    <div class="form-row mb-4">
                        <div class="form-group">
                            <label class="form-label">{{ __('super.testimonials.rating') }}</label>
                            <select name="rating" class="form-control">
                                <option value="">{{ __('super.testimonials.no_rating') }}</option>
                                @for($i = 5; $i >= 1; $i--)
                                    <option value="{{ $i }}" {{ old('rating') == $i ? 'selected' : '' }}>{{ $i }} {{ $i == 1 ? __('super.testimonials.star') : __('super.testimonials.stars') }}</option>
                                @endfor
                            </select>
                        </div>
                        <div class="form-group">
                            <label class="form-label">{{ __('super.testimonials.avatar') }}</label>
                            <input type="file" name="avatar" class="form-control" accept="image/*">
                        </div>
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
                        <a href="{{ route('super.cms.testimonials') }}" class="btn btn-secondary">{{ __('super.common.cancel') }}</a>
                    </div>
                </form>
            </div>
        </div>
    </div>
</div>
@endsection
