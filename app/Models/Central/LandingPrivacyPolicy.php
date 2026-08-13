<?php

namespace App\Models\Central;

use App\Traits\HasCentralTranslations;
use Illuminate\Database\Eloquent\Model;

class LandingPrivacyPolicy extends Model
{
    use HasCentralTranslations;

    protected $connection = 'central';

    protected $table = 'landing_privacy_policy';

    protected array $translatable = [
        'introduction',
        'data_collection',
        'data_usage',
        'cookies_usage',
        'third_party',
        'data_protection',
        'user_rights',
        'contact_info',
    ];

    protected $fillable = [
        'introduction',
        'data_collection',
        'data_usage',
        'cookies_usage',
        'third_party',
        'data_protection',
        'user_rights',
        'contact_info',
        'last_updated',
        'is_active',
    ];

    protected $casts = [
        'is_active'    => 'boolean',
        'last_updated' => 'date',
    ];
}
