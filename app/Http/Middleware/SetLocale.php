<?php

namespace App\Http\Middleware;

use App\Models\Central\CentralLanguage;
use Closure;
use Illuminate\Support\Facades\App;
use Illuminate\Support\Facades\Session;

class SetLocale
{
    public function handle($request, Closure $next)
    {
        // Prefer session, fall back to cookie, then query param,
        // then the configured system-default language, else the app default.
        $locale = Session::get('locale')
            ?: $request->cookie('locale')
            ?: $request->query('lang')
            ?: $this->getDefaultLocale();

        // Validate against active central languages (with fallback list)
        $supported = $this->getSupportedLocales();

        if (! in_array($locale, $supported, true)) {
            $locale = $this->getDefaultLocale();
        }

        App::setLocale($locale);

        return $next($request);
    }

    protected function getSupportedLocales(): array
    {
        try {
            $locales = CentralLanguage::where('is_active', true)->pluck('locale')->toArray();

            return ! empty($locales) ? $locales : ['en', 'fr', 'ar', 'es', 'hi', 'bn', 'tr', 'de', 'pt', 'ur'];
        } catch (\Throwable $e) {
            // Table may not exist yet (pre-migration)
            return ['en', 'fr', 'ar', 'es', 'hi', 'bn', 'tr', 'de', 'pt', 'ur'];
        }
    }

    protected function getDefaultLocale(): string
    {
        try {
            return CentralLanguage::defaultLocale();
        } catch (\Throwable $e) {
            return config('app.locale', 'en');
        }
    }
}
