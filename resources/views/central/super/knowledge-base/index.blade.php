@extends('central.super.layout')

@section('title', __('super.kb.title'))

@section('content')
<div class="page-header d-flex flex-wrap align-items-center justify-content-between gap-3">
    <div>
        <h1>{{ __('super.kb.title') }}</h1>
        <p class="page-subtitle">{{ __('super.kb.subtitle') }}</p>
    </div>
    <div class="d-flex gap-2">
        <a href="{{ route('super.knowledge-base.categories.create') }}" class="btn btn-outline">
            <i class="bi bi-folder-plus"></i> {{ __('super.kb.new_category') }}
        </a>
        <a href="{{ route('super.knowledge-base.articles.create') }}" class="btn btn-primary">
            <i class="bi bi-plus-lg"></i> {{ __('super.kb.new_article') }}
        </a>
    </div>
</div>

{{-- Stats --}}
<div class="row g-3 mb-4">
    <div class="col-6 col-lg-3">
        <div class="content-card kb-stat-card">
            <div class="d-flex align-items-center gap-3">
                <div class="stat-icon stat-icon-info kb-stat-icon">
                    <i class="bi bi-folder2"></i>
                </div>
                <div>
                    <p class="mb-0 fw-800 kb-stat-value">{{ $categories->count() }}</p>
                    <p class="mb-0 text-muted text-uppercase-label-sm">{{ __('super.kb.categories') }}</p>
                </div>
            </div>
        </div>
    </div>
    <div class="col-6 col-lg-3">
        <div class="content-card kb-stat-card">
            <div class="d-flex align-items-center gap-3">
                <div class="stat-icon stat-icon-success kb-stat-icon">
                    <i class="bi bi-file-earmark-text"></i>
                </div>
                <div>
                    <p class="mb-0 fw-800 kb-stat-value">{{ $totalArticles }}</p>
                    <p class="mb-0 text-muted text-uppercase-label-sm">{{ __('super.kb.total_articles') }}</p>
                </div>
            </div>
        </div>
    </div>
    <div class="col-6 col-lg-3">
        <div class="content-card kb-stat-card">
            <div class="d-flex align-items-center gap-3">
                <div class="stat-icon kb-stat-icon kb-stat-icon-published">
                    <i class="bi bi-check-circle"></i>
                </div>
                <div>
                    <p class="mb-0 fw-800 kb-stat-value">{{ $publishedArticles }}</p>
                    <p class="mb-0 text-muted text-uppercase-label-sm">{{ __('super.kb.published') }}</p>
                </div>
            </div>
        </div>
    </div>
    <div class="col-6 col-lg-3">
        <div class="content-card kb-stat-card">
            <div class="d-flex align-items-center gap-3">
                <div class="stat-icon stat-icon-warning kb-stat-icon">
                    <i class="bi bi-eye-slash"></i>
                </div>
                <div>
                    <p class="mb-0 fw-800 kb-stat-value">{{ $totalArticles - $publishedArticles }}</p>
                    <p class="mb-0 text-muted text-uppercase-label-sm">{{ __('super.kb.draft') }}</p>
                </div>
            </div>
        </div>
    </div>
</div>

{{-- Categories with articles --}}
@forelse($categories as $cat)
@php
    $gradients = [['#6366f1','#818cf8'],['#3b82f6','#60a5fa'],['#10b981','#34d399'],['#f59e0b','#fbbf24'],['#ec4899','#f472b6']];
    $g = $gradients[$loop->index % count($gradients)];
@endphp
<div class="content-card mb-4">
    <div class="card-header-custom d-flex align-items-center justify-content-between flex-wrap gap-2">
        <div class="d-flex align-items-center gap-3">
            <div class="stat-icon kb-cat-icon" style="background: linear-gradient(135deg, {{ $g[0] }}, {{ $g[1] }});">
                <i class="bi bi-{{ $cat->icon ?: 'folder2' }}"></i>
            </div>
            <div>
                <h2 class="mb-0 kb-cat-title">{{ $cat->name }}</h2>
                @if($cat->description)
                    <p class="mb-0 text-muted fs-xs3">{{ Str::limit($cat->description, 80) }}</p>
                @endif
            </div>
            <span class="badge badge-active badge-sm">{{ $cat->articles_count }} {{ $cat->articles_count == 1 ? __('super.kb.article') : __('super.kb.articles') }}</span>
        </div>
        <div class="d-flex gap-2">
            <a href="{{ route('super.knowledge-base.articles.create', ['category' => $cat->id]) }}" class="btn btn-sm btn-outline" title="{{ __('super.kb.add_article') }}">
                <i class="bi bi-plus-lg"></i> {{ __('super.kb.add_article') }}
            </a>
            <a href="{{ route('super.knowledge-base.categories.edit', $cat) }}" class="btn btn-sm btn-outline">
                <i class="bi bi-pencil"></i>
            </a>
            @php $catDeleteConfig = json_encode(["title" => __("super.kb.delete_category"), "text" => __("super.kb.delete_category_text", ["name" => $cat->name]), "icon" => "warning", "confirmButtonText" => __("super.kb.yes_delete"), "confirmButtonColor" => "#ef4444"]); @endphp
            <form method="POST" action="{{ route('super.knowledge-base.categories.destroy', $cat) }}" class="swal-confirm-form" data-swal-confirm='{{ $catDeleteConfig }}'>
                @csrf @method('DELETE')
                <button type="submit" class="btn btn-sm btn-outline btn-outline-danger-sm">
                    <i class="bi bi-trash"></i>
                </button>
            </form>
        </div>
    </div>

    @if($cat->articles_count > 0)
    <div class="card-body-custom p-0">
        <table class="table mb-0">
            <thead>
                <tr>
                    <th>{{ __('super.kb.title_col') }}</th>
                    <th class="kb-col-status">{{ __('super.kb.status') }}</th>
                    <th class="kb-col-order">{{ __('super.kb.order') }}</th>
                    <th class="kb-col-updated">{{ __('super.kb.updated') }}</th>
                    <th class="action-cell kb-col-actions"></th>
                </tr>
            </thead>
            <tbody>
                @foreach($cat->articles as $article)
                <tr>
                    <td>
                        <span class="fw-600 fs-sm4">{{ $article->title }}</span>
                        <span class="d-block text-muted fs-xs2">{{ $article->slug }}</span>
                    </td>
                    <td>
                        @if($article->is_published)
                            <span class="badge badge-active badge-sm">{{ __('super.kb.published') }}</span>
                        @else
                            <span class="badge badge-suspended badge-sm">{{ __('super.kb.draft') }}</span>
                        @endif
                    </td>
                    <td>
                        <span class="text-muted fs-sm3">{{ $article->sort_order }}</span>
                    </td>
                    <td>
                        <span class="text-muted fs-sm2">{{ $article->updated_at->diffForHumans() }}</span>
                    </td>
                    <td class="action-cell">
                        <div class="d-flex gap-1 justify-content-end">
                            <a href="{{ route('super.knowledge-base.articles.edit', $article) }}" class="btn btn-sm btn-outline">
                                <i class="bi bi-pencil"></i>
                            </a>
                            @php $articleDeleteConfig = json_encode(["title" => __("super.kb.delete_article"), "text" => __("super.kb.delete_article_text", ["name" => $article->title]), "icon" => "warning", "confirmButtonText" => __("super.kb.delete"), "confirmButtonColor" => "#ef4444"]); @endphp
                            <form method="POST" action="{{ route('super.knowledge-base.articles.destroy', $article) }}" class="swal-confirm-form" data-swal-confirm='{{ $articleDeleteConfig }}'>
                                @csrf @method('DELETE')
                                <button type="submit" class="btn btn-sm btn-outline btn-outline-danger-sm">
                                    <i class="bi bi-trash"></i>
                                </button>
                            </form>
                        </div>
                    </td>
                </tr>
                @endforeach
            </tbody>
        </table>
    </div>
    @else
    <div class="card-body-custom text-center py-4">
        <p class="text-muted mb-2 fs-sm4">{{ __('super.kb.no_articles') }}</p>
        <a href="{{ route('super.knowledge-base.articles.create', ['category' => $cat->id]) }}" class="btn btn-sm btn-primary">
            <i class="bi bi-plus-lg"></i> {{ __('super.kb.create_first_article') }}
        </a>
    </div>
    @endif
</div>
@empty
<div class="content-card">
    <div class="card-body-custom">
        <div class="empty-state">
            <div class="empty-icon"><i class="bi bi-book"></i></div>
            <h3>{{ __('super.kb.no_categories') }}</h3>
            <p>{{ __('super.kb.no_categories_desc') }}</p>
            <a href="{{ route('super.knowledge-base.categories.create') }}" class="btn btn-primary">
                <i class="bi bi-folder-plus"></i> {{ __('super.kb.create_first_category') }}
            </a>
        </div>
    </div>
</div>
@endforelse
@push('scripts')
<script src="{{ asset('assets_super/js/admins-index.js') }}"></script>
@endpush

@endsection
