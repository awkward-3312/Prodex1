<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;

/**
 * Sincroniza el idioma seleccionado en Vue con una cookie para que los PDF
 * generados por Blade utilicen el mismo idioma. Español es el valor seguro
 * cuando no existe un locale válido.
 */
class LocaleSyncController extends Controller
{
    public const COOKIE_NAME = 'app_locale';

    protected const SUPPORTED = ['es', 'en', 'fr', 'ar', 'hi', 'bn', 'tr', 'de', 'pt', 'ur'];

    public function sync(Request $request)
    {
        $locale = $request->input('locale', $request->header('X-Locale'));

        if (! is_string($locale) || $locale === '') {
            $appLocale = $request->cookie(self::COOKIE_NAME, 'es');
            return response()->json(['ok' => true, 'locale' => $appLocale]);
        }

        $locale = strtolower(substr($locale, 0, 5));
        $appLocale = in_array($locale, self::SUPPORTED, true) ? $locale : 'es';

        $cookie = cookie(
            self::COOKIE_NAME,
            $appLocale,
            60 * 24 * 365,
            '/',
            null,
            false,
            false,
            false,
            'lax'
        );

        return response()->json(['ok' => true, 'locale' => $appLocale])->cookie($cookie);
    }
}
