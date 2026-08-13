@extends('central.super.layout')

@section('title', __('super.faq.title'))

@section('content')
<div class="breadcrumb-custom">
    <a href="{{ route('super.cms.index') }}">CMS</a>
    <span class="separator"><i class="bi bi-chevron-right"></i></span>
    <span class="current">{{ __('super.faq.title') }}</span>
</div>

<div class="page-header d-flex flex-wrap align-items-center justify-content-between gap-3">
    <div>
        <h1>{{ __('super.faq.title') }}</h1>
        <p class="page-subtitle">{{ __('super.faq.subtitle') }}</p>
    </div>
    <a href="{{ route('super.cms.faq.create') }}" class="btn btn-primary"><i class="bi bi-plus-lg"></i> {{ __('super.faq.add') }}</a>
</div>

<div class="content-card">
    <div class="card-header-custom">
        <h2><i class="bi bi-question-circle me-2 text-muted"></i>{{ __('super.faq.questions') }}</h2>
        <span class="text-muted fs-sm2">{{ $faqs->count() }} {{ $faqs->count() == 1 ? __('super.common.item') : __('super.common.items') }}</span>
    </div>
    @if($faqs->isNotEmpty())
    <div class="card-body-custom p-0">
        @foreach($faqs as $faq)
        <div class="d-flex align-items-start gap-3 p-4 faq-item-row">
            <div class="faq-sort-number">
                {{ $faq->sort_order }}
            </div>
            <div class="flex-grow-1 min-w-0">
                <p class="mb-1 fw-600 fs-md">{{ $faq->question }}</p>
                <p class="mb-0 text-muted fs-sm2">{{ Str::limit($faq->answer, 120) }}</p>
            </div>
            <div class="d-flex align-items-center gap-2 flex-shrink-0">
                <span class="badge badge-{{ $faq->is_active ? 'yes' : 'no' }}">{{ $faq->is_active ? __('super.common.active') : __('super.common.inactive') }}</span>
                <a href="{{ route('super.cms.faq.edit', $faq) }}" class="btn btn-outline btn-sm">{{ __('super.common.edit') }}</a>
                <form method="POST" action="{{ route('super.cms.faq.destroy', $faq) }}" class="d-inline swal-confirm-form" data-swal-confirm="{{ json_encode(['title' => __('super.common.delete') . '?', 'text' => __('super.faq.delete_confirm'), 'icon' => 'warning', 'confirmButtonText' => __('super.common.delete'), 'confirmButtonColor' => '#ef4444']) }}">
                    @csrf @method('DELETE')
                    <button type="submit" class="btn btn-outline btn-sm btn-ghost-danger">{{ __('super.common.delete') }}</button>
                </form>
            </div>
        </div>
        @endforeach
    </div>
    @else
    <div class="empty-state">
        <div class="empty-icon"><i class="bi bi-question-circle"></i></div>
        <h3>{{ __('super.faq.no_faqs') }}</h3>
        <p>{{ __('super.faq.no_faqs_desc') }}</p>
        <a href="{{ route('super.cms.faq.create') }}" class="btn btn-primary btn-sm"><i class="bi bi-plus-lg"></i> {{ __('super.faq.add_first') }}</a>
    </div>
    @endif
</div>
@push('scripts')
<script src="{{ asset('assets_super/js/admins-index.js') }}"></script>
@endpush

@endsection
