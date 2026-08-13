<?php

namespace App\Models\Central;

use Illuminate\Database\Eloquent\Model;

class CentralLanguage extends Model
{
    protected $connection = 'central';

    protected $fillable = [
        'name',
        'locale',
        'flag',
        'is_active',
        'is_default',
        'is_rtl',
        'sort_order',
    ];

    protected $casts = [
        'is_active'  => 'boolean',
        'is_default' => 'boolean',
        'is_rtl'     => 'boolean',
    ];

    /** Active languages, sorted for display. */
    public static function active()
    {
        return static::where('is_active', true)->orderBy('sort_order')->get();
    }

    /** Default language locale. */
    public static function defaultLocale(): string
    {
        return static::where('is_default', true)->value('locale') ?? config('app.locale', 'en');
    }
}
