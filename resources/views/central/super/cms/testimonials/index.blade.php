@extends('central.super.layout')

@section('title', __('super.testimonials.title'))

@section('content')
<div class="breadcrumb-custom">
    <a href="{{ route('super.cms.index') }}">CMS</a>
    <span class="separator"><i class="bi bi-chevron-right"></i></span>
    <span class="current">{{ __('super.testimonials.title') }}</span>
</div>

<div class="page-header d-flex flex-wrap align-items-center justify-content-between gap-3">
    <div>
        <h1>{{ __('super.testimonials.title') }}</h1>
        <p class="page-subtitle">{{ __('super.testimonials.subtitle') }}</p>
    </div>
    <a href="{{ route('super.cms.testimonials.create') }}" class="btn btn-primary"><i class="bi bi-plus-lg"></i> {{ __('super.testimonials.add') }}</a>
</div>

<div class="content-card">
    <div class="card-header-custom">
        <h2><i class="bi bi-chat-quote me-2 text-muted"></i>{{ __('super.testimonials.title') }}</h2>
        <span class="text-muted fs-sm2">{{ $testimonials->count() }} {{ $testimonials->count() == 1 ? __('super.common.item') : __('super.common.items') }}</span>
    </div>
    @if($testimonials->isNotEmpty())
    <div class="table-responsive">
        <table>
            <thead>
                <tr>
                    <th class="w-50px">#</th>
                    <th>{{ __('super.testimonials.client') }}</th>
                    <th>{{ __('super.testimonials.review') }}</th>
                    <th>{{ __('super.testimonials.rating') }}</th>
                    <th>{{ __('super.common.status') }}</th>
                    <th class="action-cell">{{ __('super.common.actions') }}</th>
                </tr>
            </thead>
            <tbody>
                @foreach($testimonials as $t)
                <tr>
                    <td>{{ $t->sort_order }}</td>
                    <td>
                        <div class="d-flex align-items-center gap-2">
                            @if($t->avatar)
                                <img src="{{ asset($t->avatar) }}" alt="" class="avatar-circle-sm">
                            @else
                                <div class="tenant-avatar tenant-avatar-purple avatar-placeholder-sm">{{ strtoupper(mb_substr($t->client_name, 0, 1)) }}</div>
                            @endif
                            <div>
                                <p class="mb-0 fw-600 fs-sm5">{{ $t->client_name }}</p>
                                @if($t->company_name)
                                    <p class="mb-0 text-muted fs-xs2">{{ $t->company_name }}</p>
                                @endif
                            </div>
                        </div>
                    </td>
                    <td><span class="fs-sm3">{{ Str::limit($t->review, 60) }}</span></td>
                    <td>
                        @if($t->rating)
                            <span class="text-amber-star fs-sm3">
                                @for($i = 1; $i <= 5; $i++)
                                    <i class="bi bi-star{{ $i <= $t->rating ? '-fill' : '' }}"></i>
                                @endfor
                            </span>
                        @else
                            <span class="text-muted">—</span>
                        @endif
                    </td>
                    <td><span class="badge badge-{{ $t->is_active ? 'yes' : 'no' }}">{{ $t->is_active ? __('super.common.active') : __('super.common.inactive') }}</span></td>
                    <td class="action-cell">
                        <div class="d-flex gap-2 justify-content-end">
                            <a href="{{ route('super.cms.testimonials.edit', $t) }}" class="btn btn-outline btn-sm">{{ __('super.common.edit') }}</a>
                            <form method="POST" action="{{ route('super.cms.testimonials.destroy', $t) }}" class="d-inline swal-confirm-form" data-swal-confirm="{{ json_encode(['title' => __('super.common.delete') . '?', 'text' => __('super.testimonials.delete_confirm'), 'icon' => 'warning', 'confirmButtonText' => __('super.common.delete'), 'confirmButtonColor' => '#ef4444']) }}">
                                @csrf @method('DELETE')
                                <button type="submit" class="btn btn-outline btn-sm btn-ghost-danger">{{ __('super.common.delete') }}</button>
                            </form>
                        </div>
                    </td>
                </tr>
                @endforeach
            </tbody>
        </table>
    </div>
    @else
    <div class="empty-state">
        <div class="empty-icon"><i class="bi bi-chat-quote"></i></div>
        <h3>{{ __('super.testimonials.no_testimonials') }}</h3>
        <p>{{ __('super.testimonials.no_test_desc') }}</p>
        <a href="{{ route('super.cms.testimonials.create') }}" class="btn btn-primary btn-sm"><i class="bi bi-plus-lg"></i> {{ __('super.testimonials.add_first') }}</a>
    </div>
    @endif
</div>
@push('scripts')
<script src="{{ asset('assets_super/js/admins-index.js') }}"></script>
@endpush

@endsection
