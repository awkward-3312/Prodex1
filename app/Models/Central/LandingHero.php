<?php

namespace App\Models\Central;

use App\Traits\HasCentralTranslations;
use Illuminate\Database\Eloquent\Model;

class LandingHero extends Model
{
    use HasCentralTranslations;

    protected $connection = 'central';

    protected $table = 'landing_hero';

    protected array $translatable = ['title', 'subtitle', 'description', 'primary_button_text', 'secondary_button_text'];

    /** Image-path fields that can differ per language (stored in the translations JSON). */
    protected array $translatableImages = ['hero_image', 'background_image'];

    protected $fillable = [
        'title',
        'subtitle',
        'description',
        'primary_button_text',
        'primary_button_url',
        'secondary_button_text',
        'secondary_button_url',
        'hero_image',
        'background_image',
        'is_active',
    ];

    protected $casts = [
        'is_active' => 'boolean',
    ];
}
