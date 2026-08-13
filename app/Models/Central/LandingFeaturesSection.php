<?php

namespace App\Models\Central;

use App\Traits\HasCentralTranslations;
use Illuminate\Database\Eloquent\Model;

class LandingFeaturesSection extends Model
{
    use HasCentralTranslations;

    protected $connection = 'central';

    protected $table = 'landing_features_section';

    protected array $translatable = ['section_title', 'section_subtitle'];

    protected $fillable = [
        'section_title',
        'section_subtitle',
        'is_active',
    ];

    protected $casts = [
        'is_active' => 'boolean',
    ];
}
