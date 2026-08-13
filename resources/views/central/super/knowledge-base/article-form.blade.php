@extends('central.super.layout')

@section('title', $article ? __('super.kb.edit_article_title', ['name' => $article->title]) : __('super.kb.new_article_title'))

@section('content')
<div class="breadcrumb-custom">
    <a href="{{ route('super.knowledge-base.index') }}">{{ __('super.kb.title') }}</a>
    <span class="separator">/</span>
    <span class="current">{{ $article ? __('super.kb.edit_article') : __('super.kb.new_article_title') }}</span>
</div>

<div class="page-header">
    <h1>{{ $article ? __('super.kb.edit_article') : __('super.kb.new_article_title') }}</h1>
    <p class="page-subtitle">{{ $article ? __('super.kb.update_desc') : __('super.kb.new_article_desc') }}</p>
</div>

<form method="POST" action="{{ $article ? route('super.knowledge-base.articles.update', $article) : route('super.knowledge-base.articles.store') }}">
    @csrf
    @if($article) @method('PUT') @endif

    <div class="row g-4">
        <div class="col-lg-8">
            <div class="content-card mb-4">
                <div class="card-header-custom">
                    <h2><i class="bi bi-file-earmark-text me-2 text-muted"></i>{{ __('super.kb.article_details') }}</h2>
                </div>
                <div class="card-body-custom">
                    <div class="row g-3 mb-4">
                        <div class="col-md-6">
                            <div class="form-group">
                                <label class="form-label">{{ __('super.kb.article_title') }}</label>
                                <input type="text" name="title" id="articleTitle" class="form-control @error('title') is-invalid @enderror" value="{{ old('title', $article->title ?? '') }}" required placeholder="e.g. How to create a sale">
                                @error('title') <div class="invalid-feedback">{{ $message }}</div> @enderror
                            </div>
                        </div>
                        <div class="col-md-6">
                            <div class="form-group">
                                <label class="form-label">{{ __('super.kb.slug') }}</label>
                                <input type="text" name="slug" id="articleSlug" class="form-control @error('slug') is-invalid @enderror" value="{{ old('slug', $article->slug ?? '') }}" required placeholder="how-to-create-a-sale">
                                @error('slug') <div class="invalid-feedback">{{ $message }}</div> @enderror
                            </div>
                        </div>
                    </div>

                    <div class="form-group mb-4">
                        <label class="form-label">{{ __('super.kb.category') }}</label>
                        <select name="kb_category_id" class="form-control @error('kb_category_id') is-invalid @enderror" required>
                            <option value="">{{ __('super.kb.select_category') }}</option>
                            @foreach($categories as $cat)
                                <option value="{{ $cat->id }}" {{ old('kb_category_id', $article->kb_category_id ?? $preselectedCategory ?? '') == $cat->id ? 'selected' : '' }}>
                                    {{ $cat->name }}
                                </option>
                            @endforeach
                        </select>
                        @error('kb_category_id') <div class="invalid-feedback">{{ $message }}</div> @enderror
                    </div>

                    <div class="form-group mb-4">
                        <div class="d-flex align-items-center justify-content-between mb-2">
                            <label class="form-label mb-0">{{ __('super.kb.content') }}</label>
                            <button type="button" id="toggleEditor" class="btn btn-sm btn-outline btn-toggle-editor">
                                <i class="bi bi-eye"></i> <span id="toggleLabel">{{ __('super.kb.visual_preview') }}</span>
                            </button>
                        </div>
                        <div id="codeEditor">
                            <textarea
                                name="content"
                                id="articleContent"
                                class="form-control textarea-code @error('content') is-invalid @enderror"
                                rows="18"
                                placeholder="{{ __('super.kb.content_placeholder') }}"
                            >{{ old('content', $article->content ?? '') }}</textarea>
                        </div>
                        <div id="visualPreview">
                            <div id="previewContent" class="form-control preview-pane"></div>
                        </div>
                        @error('content') <div class="invalid-feedback d-block">{{ $message }}</div> @enderror
                        <small class="text-muted">{{ __('super.kb.content_hint') }}</small>
                    </div>
                </div>
            </div>
        </div>

        <div class="col-lg-4">
            <div class="content-card mb-4 sticky-sidebar">
                <div class="card-header-custom">
                    <h2><i class="bi bi-sliders me-2 text-muted"></i>{{ __('super.kb.settings') }}</h2>
                </div>
                <div class="card-body-custom">
                    <div class="form-group mb-4">
                        <label class="d-flex align-items-center gap-2 cursor-pointer">
                            <input type="checkbox" name="is_published" value="1" class="form-check-input form-check-lg" {{ old('is_published', $article->is_published ?? true) ? 'checked' : '' }}>
                            <span class="fw-600 fs-sm4">{{ __('super.kb.published_label') }}</span>
                        </label>
                        <small class="text-muted d-block mt-1">{{ __('super.kb.unpublished_hint') }}</small>
                    </div>

                    <div class="form-group mb-4">
                        <label class="form-label">{{ __('super.kb.sort_order') }}</label>
                        <input type="number" name="sort_order" class="form-control max-w-120 @error('sort_order') is-invalid @enderror" value="{{ old('sort_order', $article->sort_order ?? 0) }}" min="0">
                        @error('sort_order') <div class="invalid-feedback">{{ $message }}</div> @enderror
                    </div>

                    @if($article)
                    <div class="divider"></div>
                    <div class="d-flex flex-column gap-2 article-meta-list">
                        <div class="d-flex justify-content-between">
                            <span class="text-muted">{{ __('super.kb.created') }}</span>
                            <span class="fw-600">{{ $article->created_at->format('M d, Y') }}</span>
                        </div>
                        <div class="d-flex justify-content-between">
                            <span class="text-muted">{{ __('super.kb.updated') }}</span>
                            <span class="fw-600">{{ $article->updated_at->diffForHumans() }}</span>
                        </div>
                    </div>
                    @endif

                    <div class="d-flex flex-column gap-2 mt-4">
                        <button type="submit" class="btn btn-primary w-100">
                            <i class="bi bi-check-lg"></i> {{ $article ? __('super.kb.save_changes') : __('super.kb.create_article') }}
                        </button>
                        <a href="{{ route('super.knowledge-base.index') }}" class="btn btn-outline w-100">{{ __('super.kb.cancel') }}</a>
                    </div>
                </div>
            </div>
        </div>
    </div>
</form>

@push('scripts')
<script>
window.KbArticleData = {
    isNew: {{ $article ? 'false' : 'true' }},
    trans: {
        labelVisual: @json(__('super.kb.visual_preview')),
        labelSource: @json(__('super.kb.source_code')),
    },
};
</script>
<script src="{{ asset('assets_super/js/kb-article-form.js') }}"></script>
@endpush
@endsection
