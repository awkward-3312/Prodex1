<?php

namespace App\Models\Central;

use App\Traits\HasCentralTranslations;
use Illuminate\Database\Eloquent\Model;

class LandingTestimonial extends Model
{
    use HasCentralTranslations;

    protected $connection = 'central';

    protected $table = 'landing_testimonials';

    protected array $translatable = ['client_name', 'company_name', 'review'];

    protected $fillable = [
        'client_name',
        'company_name',
        'review',
        'rating',
        'avatar',
        'sort_order',
        'is_active',
    ];

    protected $casts = [
        'rating' => 'integer',
        'is_active' => 'boolean',
    ];
}
