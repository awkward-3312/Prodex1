<?php

namespace App\Models\Central;

use Illuminate\Database\Eloquent\Model;

class KbArticle extends Model
{
    protected $connection = 'central';

    protected $table = 'kb_articles';

    protected $fillable = [
        'kb_category_id',
        'title',
        'slug',
        'content',
        'is_published',
        'sort_order',
    ];

    protected $casts = [
        'is_published' => 'boolean',
        'sort_order'   => 'integer',
    ];

    public function category()
    {
        return $this->belongsTo(KbCategory::class, 'kb_category_id');
    }
}
