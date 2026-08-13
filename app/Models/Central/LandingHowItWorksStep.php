<?php

namespace App\Models\Central;

use App\Traits\HasCentralTranslations;
use Illuminate\Database\Eloquent\Model;

class LandingHowItWorksStep extends Model
{
    use HasCentralTranslations;

    protected $connection = 'central';

    protected $table = 'landing_how_it_works_steps';

    protected array $translatable = ['title', 'description'];

    protected $fillable = [
        'title',
        'description',
        'icon',
        'icon_color',
        'sort_order',
        'is_active',
    ];

    protected $casts = [
        'is_active' => 'boolean',
    ];
}
