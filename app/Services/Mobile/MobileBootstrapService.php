<?php

namespace App\Services\Mobile;

use App\Models\Setting;
use App\Models\User;
use Illuminate\Support\Facades\Schema;

class MobileBootstrapService
{
    public function forUser(User $user): array
    {
        $settingsQuery = Setting::with('Currency');
        if (Schema::hasColumn('settings', 'deleted_at')) {
            $settingsQuery->where('deleted_at', null);
        }

        $settings = $settingsQuery->first();
        $tenant = tenant();
        $domain = $tenant?->domains()->first()?->domain;
        $currency = $settings?->Currency;

        return [
            'user' => [
                'id' => $user->id,
                'name' => trim((string) ($user->firstname.' '.$user->lastname)) ?: $user->username,
                'username' => $user->username,
                'email' => $user->email,
                'avatar' => $user->avatar,
            ],
            'tenant' => [
                'workspace' => $domain && ! str_contains($domain, '.') ? $domain : ($domain ? explode('.', $domain)[0] : null),
                'domain' => $domain,
                'company_name' => $settings->CompanyName ?? '',
                'logo' => $settings->logo ?? null,
            ],
            'preferences' => [
                'locale' => $settings->default_language ?? $settings->locale ?? config('app.locale'),
                'currency_code' => $currency->code ?? 'usd',
                'currency_symbol' => $currency->symbol ?? '',
                'timezone' => $settings->timezone ?? config('app.timezone'),
                'date_format' => $settings->date_format ?? 'YYYY-MM-DD',
                'price_format' => $settings->price_format ?? null,
                'price_decimals' => (bool) ($settings->enable_3_decimal_pricing ?? false) ? 3 : 2,
            ],
            'permissions' => $this->permissions($user),
            'operational_context' => app(PosOperationalContextReadService::class)->forUser($user),
        ];
    }

    private function permissions(User $user): array
    {
        return $user->roles()
            ->with('permissions:id,name')
            ->get()
            ->flatMap(fn ($role) => $role->permissions->pluck('name'))
            ->filter()
            ->unique()
            ->values()
            ->all();
    }
}
