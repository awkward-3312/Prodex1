<?php

namespace App\Providers;

use Illuminate\Support\ServiceProvider;
use Illuminate\Support\Facades\Schema;

class MailConfigServiceProvider extends ServiceProvider
{
    public function boot(): void
    {
        if (! $this->app->runningInConsole() || $this->app->runningUnitTests()) {
            $this->applyMailSettings();
        }

        if ($this->app->runningInConsole()) {
            $this->applyMailSettings();
        }
    }

    protected function applyMailSettings(): void
    {
        try {
            if (! Schema::connection('central')->hasTable('mail_settings')) {
                return;
            }

            $setting = \App\Models\Central\MailSetting::first();

            if (! $setting) {
                return;
            }

            config([
                'mail.default'                 => $setting->mail_mailer ?: 'smtp',
                'mail.mailers.smtp.host'       => $setting->mail_host,
                'mail.mailers.smtp.port'       => $setting->mail_port,
                'mail.mailers.smtp.username'   => $setting->mail_username,
                'mail.mailers.smtp.password'   => $setting->getDecryptedPassword(),
                'mail.mailers.smtp.encryption' => $setting->mail_encryption ?: null,
                'mail.from.address'            => $setting->mail_from_address ?: config('mail.from.address'),
                'mail.from.name'               => $setting->mail_from_name ?: config('mail.from.name'),
            ]);
        } catch (\Throwable $e) {
            // DB not available yet (e.g. during setup) — silently skip
        }
    }
}
