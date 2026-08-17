<?php

namespace App\Models\Central;

use App\Traits\HasCentralTranslations;
use Illuminate\Database\Eloquent\Model;

class LandingFooter extends Model
{
    use HasCentralTranslations;

    protected $connection = 'central';

    protected $table = 'landing_footer';

    protected array $translatable = ['footer_about', 'copyright_text', 'sales_whatsapp_message'];

    protected $fillable = [
        'footer_about',
        'copyright_text',
        'contact_email',
        'contact_phone',
        'sales_email',
        'sales_whatsapp_number',
        'sales_whatsapp_message',
        'address',
        'facebook',
        'twitter',
        'linkedin',
        'instagram',
        'youtube',
        'show_admin_login',
        'show_sales_floating_button',
    ];

    protected $casts = [
        'show_admin_login' => 'boolean',
        'show_sales_floating_button' => 'boolean',
    ];
}
