<?php

namespace App\Models\Central;

use Illuminate\Database\Eloquent\Model;

class KbCategory extends Model
{
    protected $connection = 'central';

    protected $table = 'kb_categories';

    protected $fillable = [
        'name',
        'slug',
        'description',
        'icon',
        'sort_order',
    ];

    protected $casts = [
        'sort_order' => 'integer',
    ];

    public function articles()
    {
        return $this->hasMany(KbArticle::class, 'kb_category_id')
            ->orderBy('sort_order')
            ->orderBy('title');
    }
}
