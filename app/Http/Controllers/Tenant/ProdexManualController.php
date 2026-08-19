<?php

namespace App\Http\Controllers\Tenant;

use App\Http\Controllers\Controller;
use App\Models\Central\KbArticle;
use App\Models\Central\KbCategory;
use Illuminate\Http\Request;

class ProdexManualController extends Controller
{
    public function categories()
    {
        $categories = KbCategory::query()
            ->whereHas('articles', fn ($query) => $query->where('is_published', true))
            ->orderBy('sort_order')
            ->orderBy('name')
            ->get(['id', 'name', 'slug', 'description', 'icon', 'sort_order']);

        return response()->json($categories);
    }

    public function articles(Request $request)
    {
        $query = KbArticle::query()
            ->where('is_published', true)
            ->with('category:id,name,slug,icon')
            ->select([
                'id',
                'kb_category_id',
                'title',
                'slug',
                'content',
                'sort_order',
                'updated_at',
            ]);

        $search = trim((string) $request->query('q', ''));
        if ($search !== '') {
            $query->where(function ($subQuery) use ($search) {
                $subQuery->where('title', 'like', '%' . $search . '%')
                    ->orWhere('content', 'like', '%' . $search . '%');
            });
        }

        $categoryId = $request->query('category_id');
        if ($categoryId !== null && $categoryId !== '') {
            $query->where('kb_category_id', (int) $categoryId);
        }

        $perPage = min(max((int) $request->query('per_page', 15), 1), 50);

        return response()->json(
            $query->orderBy('sort_order')
                ->orderBy('title')
                ->paginate($perPage)
        );
    }

    public function show(int $id)
    {
        $article = KbArticle::query()
            ->where('is_published', true)
            ->with('category:id,name,slug,icon')
            ->findOrFail($id);

        return response()->json([
            'id' => $article->id,
            'title' => $article->title,
            'slug' => $article->slug,
            'content' => $article->content,
            'updated_at' => optional($article->updated_at)->toIso8601String(),
            'category' => $article->category ? [
                'id' => $article->category->id,
                'name' => $article->category->name,
                'slug' => $article->category->slug,
                'icon' => $article->category->icon,
            ] : null,
        ]);
    }
}
