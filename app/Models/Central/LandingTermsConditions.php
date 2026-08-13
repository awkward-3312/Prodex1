<?php

namespace App\Models\Central;

use App\Traits\HasCentralTranslations;
use Illuminate\Database\Eloquent\Model;

class LandingTermsConditions extends Model
{
    use HasCentralTranslations;

    protected $connection = 'central';

    protected $table = 'landing_terms_conditions';

    protected array $translatable = [
        'acceptance',
        'use_license',
        'user_accounts',
        'payments',
        'prohibited',
        'intellectual_property',
        'liability',
        'governing_law',
    ];

    protected $fillable = [
        'acceptance',
        'use_license',
        'user_accounts',
        'payments',
        'prohibited',
        'intellectual_property',
        'liability',
        'governing_law',
        'last_updated',
        'is_active',
    ];

    protected $casts = [
        'is_active'    => 'boolean',
        'last_updated' => 'date',
    ];
}
