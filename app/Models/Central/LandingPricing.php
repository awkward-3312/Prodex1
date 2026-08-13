<?php

namespace App\Models\Central;

use App\Traits\HasCentralTranslations;
use Illuminate\Database\Eloquent\Model;

class LandingPricing extends Model
{
    use HasCentralTranslations;

    protected $connection = 'central';

    protected $table = 'landing_pricing';

    protected array $translatable = ['section_title', 'section_subtitle'];

    protected $fillable = [
        'section_title',
        'section_subtitle',
        'show_monthly_pricing',
        'show_yearly_pricing',
        'load_plans_from_database',
        'is_active',
    ];

    protected $casts = [
        'show_monthly_pricing' => 'boolean',
        'show_yearly_pricing' => 'boolean',
        'load_plans_from_database' => 'boolean',
        'is_active' => 'boolean',
    ];
}
