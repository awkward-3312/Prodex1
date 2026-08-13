<?php

namespace App\Http\Middleware;

use App\Http\Controllers\LocaleSyncController;
use Closure;
use Illuminate\Http\Request;

/**
 * Sets the app locale for server-side rendered output (Blade PDFs) on the API
 * group, so downloaded/printed documents match the language chosen in the SPA.
 *
 * Resolution order: X-Pdf-Locale header -> pdf_locale query -> app_locale cookie
 * (written by LocaleSyncController). Validated against the supported locales so a
 * bogus value can't force an unknown locale.
 */
class SetPdfLocale
{
    /** Supported locales (mirrors the active central languages / SetLocale fallback list). */
    protected const SUPPORTED = ['en', 'fr', 'ar', 'es', 'hi', 'bn', 'tr', 'de', 'pt', 'ur'];

    public function handle(Request $request, Closure $next)
    {
        $locale = $request->header('X-Pdf-Locale')
            ?: $request->query('pdf_locale')
            ?: $request->cookie(LocaleSyncController::COOKIE_NAME);

        if (is_string($locale)) {
            $locale = strtolower(substr($locale, 0, 5));

            if (in_array($locale, self::SUPPORTED, true)) {
                app()->setLocale($locale);
            }
        }

        return $next($request);
    }
}
