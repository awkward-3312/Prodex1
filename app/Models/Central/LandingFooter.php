<?php

namespace App\Models\Central;

use App\Traits\HasCentralTranslations;
use Illuminate\Database\Eloquent\Model;

class LandingFooter extends Model
{
    use HasCentralTranslations;

    protected $connection = 'central';

    protected $table = 'landing_footer';

    protected array $translatable = ['footer_about', 'copyright_text'];

    protected $fillable = [
        'footer_about',
        'copyright_text',
        'contact_email',
        'contact_phone',
        'address',
        'facebook',
        'twitter',
        'linkedin',
        'instagram',
        'youtube',
        'show_admin_login',
    ];

    protected $casts = [
        'show_admin_login' => 'boolean',
    ];
}
