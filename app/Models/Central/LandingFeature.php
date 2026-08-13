<?php

namespace App\Models\Central;

use App\Traits\HasCentralTranslations;
use Illuminate\Database\Eloquent\Model;

class LandingFeature extends Model
{
    use HasCentralTranslations;

    protected $connection = 'central';

    protected $table = 'landing_features';

    protected array $translatable = ['title', 'description'];

    protected $fillable = [
        'title',
        'description',
        'icon',
        'image',
        'sort_order',
        'is_active',
    ];

    protected $casts = [
        'is_active' => 'boolean',
    ];
}
