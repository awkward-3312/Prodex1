<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;

/**
 * Syncs the Vue i18n selected language to a cookie so that Blade PDFs
 * use the same locale. Uses cookie (not session) to avoid adding session
 * to the API group, which would break Passport/auth. Any supported locale
 * is preserved; unsupported values fall back to English.
 */
class LocaleSyncController extends Controller
{
    public const COOKIE_NAME = 'app_locale';

    /** Supported locales (mirrors the active central languages / SetPdfLocale list). */
    protected const SUPPORTED = ['en', 'fr', 'ar', 'es', 'hi', 'bn', 'tr', 'de', 'pt', 'ur'];

    public function sync(Request $request)
    {
        $locale = $request->input('locale', $request->header('X-Locale'));

        if (! is_string($locale) || $locale === '') {
            $appLocale = $request->cookie(self::COOKIE_NAME, 'en');
            return response()->json(['ok' => true, 'locale' => $appLocale]);
        }

        $locale = strtolower(substr($locale, 0, 5));
        $appLocale = in_array($locale, self::SUPPORTED, true) ? $locale : 'en';

        $cookie = cookie(
            self::COOKIE_NAME,
            $appLocale,
            60 * 24 * 365, // 1 year in minutes
            '/',
            null,
            false, // secure
            false, // httpOnly - allow JS read if needed
            false,
            'lax'
        );

        return response()->json(['ok' => true, 'locale' => $appLocale])->cookie($cookie);
    }
}
