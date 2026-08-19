<?php

namespace App\Http\Middleware;

use App\Http\Controllers\LocaleSyncController;
use Closure;
use Illuminate\Http\Request;

/**
 * Define el idioma de documentos generados en servidor (Blade/PDF).
 * Si no existe un locale válido, PRODEX utiliza Español.
 */
class SetPdfLocale
{
    protected const SUPPORTED = ['es', 'en', 'fr', 'ar', 'hi', 'bn', 'tr', 'de', 'pt', 'ur'];

    public function handle(Request $request, Closure $next)
    {
        $locale = $request->header('X-Pdf-Locale')
            ?: $request->query('pdf_locale')
            ?: $request->cookie(LocaleSyncController::COOKIE_NAME)
            ?: 'es';

        $locale = is_string($locale) ? strtolower(substr($locale, 0, 5)) : 'es';
        app()->setLocale(in_array($locale, self::SUPPORTED, true) ? $locale : 'es');

        return $next($request);
    }
}
