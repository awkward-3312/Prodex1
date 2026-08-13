<?php

namespace App\Http\Controllers\Central\Super;

use App\Http\Controllers\Controller;
use App\Models\Central\KbArticle;
use App\Models\Central\KbCategory;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Illuminate\View\View;

class KnowledgeBaseController extends Controller
{
    // ── Index: categories with articles ──

    public function index(): View
    {
        $categories = KbCategory::withCount('articles')
            ->orderBy('sort_order')
            ->orderBy('name')
            ->get();

        $totalArticles = KbArticle::count();
        $publishedArticles = KbArticle::where('is_published', true)->count();

        return view('central.super.knowledge-base.index', compact('categories', 'totalArticles', 'publishedArticles'));
    }

    // ── Categories ──

    public function createCategory(): View
    {
        return view('central.super.knowledge-base.category-form', ['category' => null]);
    }

    public function storeCategory(Request $request): RedirectResponse
    {
        $data = $request->validate([
            'name'        => ['required', 'string', 'max:190'],
            'slug'        => ['required', 'string', 'max:190', 'alpha_dash', 'unique:central.kb_categories,slug'],
            'description' => ['nullable', 'string', 'max:500'],
            'icon'        => ['nullable', 'string', 'max:64'],
            'sort_order'  => ['nullable', 'integer', 'min:0'],
        ]);

        $data['sort_order'] = $data['sort_order'] ?? 0;

        KbCategory::create($data);

        return redirect()->route('super.knowledge-base.index')->with('success', "Category \"{$data['name']}\" created.");
    }

    public function editCategory(KbCategory $category): View
    {
        return view('central.super.knowledge-base.category-form', compact('category'));
    }

    public function updateCategory(Request $request, KbCategory $category): RedirectResponse
    {
        $data = $request->validate([
            'name'        => ['required', 'string', 'max:190'],
            'slug'        => ['required', 'string', 'max:190', 'alpha_dash', Rule::unique('central.kb_categories', 'slug')->ignore($category->id)],
            'description' => ['nullable', 'string', 'max:500'],
            'icon'        => ['nullable', 'string', 'max:64'],
            'sort_order'  => ['nullable', 'integer', 'min:0'],
        ]);

        $category->update($data);

        return redirect()->route('super.knowledge-base.index')->with('success', "Category \"{$category->name}\" updated.");
    }

    public function destroyCategory(KbCategory $category): RedirectResponse
    {
        $name = $category->name;
        $category->delete(); // cascades to articles

        return redirect()->route('super.knowledge-base.index')->with('success', "Category \"{$name}\" and its articles deleted.");
    }

    // ── Articles ──

    public function createArticle(Request $request): View
    {
        $categories = KbCategory::orderBy('sort_order')->orderBy('name')->get();
        $preselectedCategory = $request->query('category');

        return view('central.super.knowledge-base.article-form', [
            'article'    => null,
            'categories' => $categories,
            'preselectedCategory' => $preselectedCategory,
        ]);
    }

    public function storeArticle(Request $request): RedirectResponse
    {
        $data = $request->validate([
            'kb_category_id' => ['required', 'integer', 'exists:central.kb_categories,id'],
            'title'          => ['required', 'string', 'max:190'],
            'slug'           => ['required', 'string', 'max:190', 'alpha_dash'],
            'content'        => ['nullable', 'string'],
            'is_published'   => ['nullable'],
            'sort_order'     => ['nullable', 'integer', 'min:0'],
        ]);

        $request->validate([
            'slug' => [Rule::unique('central.kb_articles', 'slug')->where('kb_category_id', $data['kb_category_id'])],
        ]);

        $data['is_published'] = $request->has('is_published');
        $data['sort_order'] = $data['sort_order'] ?? 0;

        KbArticle::create($data);

        return redirect()->route('super.knowledge-base.index')->with('success', "Article \"{$data['title']}\" created.");
    }

    public function editArticle(KbArticle $article): View
    {
        $categories = KbCategory::orderBy('sort_order')->orderBy('name')->get();

        return view('central.super.knowledge-base.article-form', compact('article', 'categories'));
    }

    public function updateArticle(Request $request, KbArticle $article): RedirectResponse
    {
        $data = $request->validate([
            'kb_category_id' => ['required', 'integer', 'exists:central.kb_categories,id'],
            'title'          => ['required', 'string', 'max:190'],
            'slug'           => ['required', 'string', 'max:190', 'alpha_dash'],
            'content'        => ['nullable', 'string'],
            'is_published'   => ['nullable'],
            'sort_order'     => ['nullable', 'integer', 'min:0'],
        ]);

        $request->validate([
            'slug' => [Rule::unique('central.kb_articles', 'slug')->where('kb_category_id', $data['kb_category_id'])->ignore($article->id)],
        ]);

        $data['is_published'] = $request->has('is_published');

        $article->update($data);

        return redirect()->route('super.knowledge-base.index')->with('success', "Article \"{$article->title}\" updated.");
    }

    public function destroyArticle(KbArticle $article): RedirectResponse
    {
        $title = $article->title;
        $article->delete();

        return redirect()->route('super.knowledge-base.index')->with('success', "Article \"{$title}\" deleted.");
    }
}
