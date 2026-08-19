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
        $locale = Session::get('locale')
            ?: $request->cookie('locale')
            ?: $request->query('lang')
            ?: $this->getDefaultLocale();

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

            return ! empty($locales) ? $locales : ['es', 'en', 'fr', 'ar', 'hi', 'bn', 'tr', 'de', 'pt', 'ur'];
        } catch (\Throwable $e) {
            return ['es', 'en', 'fr', 'ar', 'hi', 'bn', 'tr', 'de', 'pt', 'ur'];
        }
    }

    protected function getDefaultLocale(): string
    {
        try {
            return CentralLanguage::defaultLocale();
        } catch (\Throwable $e) {
            return config('app.locale', 'es');
        }
    }
}
