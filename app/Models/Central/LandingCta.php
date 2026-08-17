<?php

namespace App\Models\Central;

use App\Traits\HasCentralTranslations;
use Illuminate\Database\Eloquent\Model;

class LandingCta extends Model
{
    use HasCentralTranslations;

    protected $connection = 'central';

    protected $table = 'landing_cta';

    protected array $translatable = ['title', 'subtitle', 'button_text', 'sales_button_text'];

    protected $fillable = [
        'title',
        'subtitle',
        'button_text',
        'button_url',
        'sales_button_text',
        'sales_button_url',
        'background_image',
        'is_active',
        'show_commercial_cta',
    ];

    protected $casts = [
        'is_active' => 'boolean',
        'show_commercial_cta' => 'boolean',
    ];
}
