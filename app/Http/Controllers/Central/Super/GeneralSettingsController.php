<?php

namespace App\Http\Controllers\Central\Super;

use App\Http\Controllers\Controller;
use App\Models\Central\CentralLanguage;
use App\Models\Central\GeneralSetting;
use App\Models\Central\LandingFooter;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\File;

class GeneralSettingsController extends Controller
{
    public function index()
    {
        $setting = GeneralSetting::instance();
        $footer = LandingFooter::firstOrCreate([]);
        $backups = (new \App\Http\Controllers\Central\Super\BackupController())->listBackups();
        $languages = CentralLanguage::active();
        $allLanguages = CentralLanguage::orderBy('sort_order')->get();
        $defaultLocale = CentralLanguage::defaultLocale();

        $env = [
            'APP_ENV'   => config('app.env', 'production'),
            'APP_DEBUG' => config('app.debug') ? 'true' : 'false',
            'APP_URL'   => config('app.url', ''),
        ];

        return view('central.super.settings.general', [
            'setting'        => $setting,
            'footer'         => $footer,
            'backups'        => $backups,
            'languages'      => $languages,
            'allLanguages'   => $allLanguages,
            'defaultLocale'  => $defaultLocale,
            'env'            => $env,
        ]);
    }

    public function update(Request $request)
    {
        // Fonts may be one of the curated families or the admin's own uploaded
        // custom font (new in this request, or already saved).
        $customFontName = trim((string) $request->input('landing_custom_font_name', ''));
        $allowedFonts = array_merge(
            GeneralSetting::landingFontKeys(),
            array_values(array_filter([$customFontName, (string) (GeneralSetting::instance()->landing_custom_font_name ?? '')]))
        );

        $validated = $request->validate([
            'app_name'      => ['required', 'string', 'max:255'],
            'currency_code'   => ['required', 'string', 'max:10', 'regex:/^[A-Z]{3}$/'],
            'currency_symbol' => ['required', 'string', 'max:5'],
            'company_name'    => ['nullable', 'string', 'max:255'],
            'phone'        => ['nullable', 'string', 'max:50'],
            'email'        => ['nullable', 'email', 'max:255'],
            'address'      => ['nullable', 'string', 'max:500'],
            'website'      => ['nullable', 'url', 'max:255'],
            'logo'         => ['nullable', 'image', 'mimes:png,jpg,jpeg,svg,webp', 'max:2048'],
            'favicon'      => ['nullable', 'file', 'mimes:png,ico,jpg,jpeg,svg', 'max:1024'],
            'hosting_mode'   => ['nullable', 'in:vps,shared'],
            'default_language' => ['nullable', 'string', 'max:10'],
            'languages_active'   => ['nullable', 'array'],
            'languages_active.*' => ['string', 'max:10'],
            'dashboard_footer_text' => ['nullable', 'string', 'max:500'],
            'landing_font'         => ['nullable', 'string', \Illuminate\Validation\Rule::in($allowedFonts)],
            'landing_heading_font' => ['nullable', 'string', \Illuminate\Validation\Rule::in($allowedFonts)],
            'landing_custom_font_name' => ['nullable', 'string', 'max:50', 'regex:/^[A-Za-z0-9 _-]+$/'],
            'custom_font_file'         => ['nullable', 'file', 'max:5120'],
            'bank_details'                  => ['nullable', 'array'],
            'bank_details.bank_name'        => ['nullable', 'string', 'max:255'],
            'bank_details.account_holder'   => ['nullable', 'string', 'max:255'],
            'bank_details.account_number'   => ['nullable', 'string', 'max:255'],
            'bank_details.iban'             => ['nullable', 'string', 'max:255'],
            'bank_details.swift'            => ['nullable', 'string', 'max:50'],
            'bank_details.branch'           => ['nullable', 'string', 'max:255'],
            'bank_details.instructions'     => ['nullable', 'string', 'max:2000'],
            'app_env'   => ['nullable', 'in:local,staging,production'],
            'app_debug' => ['nullable', 'in:true,false,1,0'],
            'app_url'   => ['nullable', 'url', 'max:255'],

            // ── Tenant Settings (defaults applied to NEW tenants only) ──
            'tenant_app_name'          => ['nullable', 'string', 'max:255'],
            'tenant_company_name'      => ['nullable', 'string', 'max:255'],
            'tenant_email'             => ['nullable', 'email', 'max:255'],
            'tenant_phone'             => ['nullable', 'string', 'max:50'],
            'tenant_address'           => ['nullable', 'string', 'max:500'],
            'tenant_logo'              => ['nullable', 'image', 'mimes:png,jpg,jpeg,svg,webp', 'max:2048'],
            'tenant_favicon'           => ['nullable', 'file', 'mimes:png,ico,jpg,jpeg,svg', 'max:1024'],
            'tenant_currency_code'     => ['nullable', 'string', 'max:10', 'regex:/^[A-Za-z]{3}$/'],
            'tenant_currency_symbol'   => ['nullable', 'string', 'max:5'],
            'tenant_default_language'  => ['nullable', 'string', 'max:10'],
            'tenant_footer_text'       => ['nullable', 'string', 'max:255'],
            'tenant_page_title_suffix' => ['nullable', 'string', 'max:255'],
            'tenant_developed_by'      => ['nullable', 'string', 'max:255'],
            'reserved_subdomains'      => ['nullable', 'string', 'max:5000'],

            // ── Subscription Reminders ──
            'subscription_reminder_offsets'  => ['nullable', 'string', 'max:255'],
            'trial_reminder_offsets'         => ['nullable', 'string', 'max:255'],
            'subscription_reminder_channels'   => ['nullable', 'array'],
            'subscription_reminder_channels.*' => ['string', 'in:email,sms,banner'],
            'subscription_banner_threshold_days' => ['nullable', 'integer', 'min:1', 'max:90'],
            'sms_gateway'                    => ['nullable', 'in:twilio,infobip,termii,custom'],
            'subscription_reminder_sms'      => ['nullable', 'string', 'max:480'],
            'trial_reminder_sms'             => ['nullable', 'string', 'max:480'],
        ]);

        if (! empty($validated['tenant_currency_code'])) {
            $validated['tenant_currency_code'] = strtoupper($validated['tenant_currency_code']);
        }

        // Reserved subdomains: textarea (newline or comma separated) → normalized array.
        // Keep only valid hostname-style tokens, lowercase, deduplicated.
        $raw = (string) ($validated['reserved_subdomains'] ?? '');
        $tokens = preg_split('/[\s,;]+/', $raw) ?: [];
        $reserved = [];
        foreach ($tokens as $token) {
            $token = strtolower(trim($token));
            if ($token === '') {
                continue;
            }
            if (preg_match('/^[a-z0-9](?:[a-z0-9\-]{0,61}[a-z0-9])?$/', $token)) {
                $reserved[$token] = true;
            }
        }
        $validated['reserved_subdomains'] = array_keys($reserved);

        // Coerce checkbox value (unchecked checkboxes are not submitted)
        $validated['show_customizer_button'] = $request->boolean('show_customizer_button');
        $validated['show_site_name'] = $request->boolean('show_site_name');

        // Seed demo data into newly provisioned tenants when enabled.
        $validated['demo_data_enabled'] = $request->boolean('demo_data_enabled');

        // ── Subscription reminders ──
        $validated['subscription_reminders_enabled'] = $request->boolean('subscription_reminders_enabled');
        $validated['trial_reminders_enabled'] = $request->boolean('trial_reminders_enabled');
        $validated['subscription_reminder_offsets'] = $this->parseOffsets($validated['subscription_reminder_offsets'] ?? null);
        $validated['trial_reminder_offsets'] = $this->parseOffsets($validated['trial_reminder_offsets'] ?? null);

        // Persist the enabled channels: email/sms (delivered by the daily
        // command) and banner (passive in-app display in the tenant app).
        $channels = array_values(array_intersect(['email', 'sms', 'banner'], $validated['subscription_reminder_channels'] ?? []));
        $validated['subscription_reminder_channels'] = empty($channels) ? ['email'] : $channels;

        if (empty($validated['subscription_banner_threshold_days'])) {
            unset($validated['subscription_banner_threshold_days']);
        }

        // Normalize empty SMS strings to null so model accessors fall back to defaults.
        $validated['sms_gateway'] = $validated['sms_gateway'] ?? null;
        $validated['subscription_reminder_sms'] = trim((string) ($validated['subscription_reminder_sms'] ?? '')) ?: null;
        $validated['trial_reminder_sms'] = trim((string) ($validated['trial_reminder_sms'] ?? '')) ?: null;

        $envUpdates = [];
        if ($request->filled('app_env')) {
            $envUpdates['APP_ENV'] = $validated['app_env'];
        }
        if ($request->has('app_debug')) {
            $envUpdates['APP_DEBUG'] = in_array($validated['app_debug'] ?? null, ['true', '1', 1, true], true) ? 'true' : 'false';
        }
        if ($request->filled('app_url')) {
            $envUpdates['APP_URL'] = rtrim($validated['app_url'], '/');
        }
        unset($validated['app_env'], $validated['app_debug'], $validated['app_url']);

        if (!empty($envUpdates)) {
            $this->writeEnv($envUpdates);
            Artisan::call('config:clear');
        }

        $setting = GeneralSetting::instance();

        $uploadDir = upload_public_path('settings');
        if (! File::isDirectory($uploadDir)) {
            File::makeDirectory($uploadDir, 0755, true);
        }

        // Handle logo upload
        if ($request->hasFile('logo')) {
            if ($setting->logo_path && file_exists(public_path($setting->logo_path))) {
                @unlink(public_path($setting->logo_path));
            }
            $file = $request->file('logo');
            $filename = 'logo_' . time() . '.' . $file->getClientOriginalExtension();
            $file->move($uploadDir, $filename);
            $validated['logo_path'] = upload_path('settings') . '/' . $filename;
        }

        // Handle favicon upload
        if ($request->hasFile('favicon')) {
            if ($setting->favicon_path && file_exists(public_path($setting->favicon_path))) {
                @unlink(public_path($setting->favicon_path));
            }
            $file = $request->file('favicon');
            $filename = 'favicon_' . time() . '.' . $file->getClientOriginalExtension();
            $file->move($uploadDir, $filename);
            $validated['favicon_path'] = upload_path('settings') . '/' . $filename;
        }

        // Handle custom landing font (upload / remove). Stored under images/super/fonts.
        $fontDir = upload_public_path('fonts');
        if (! File::isDirectory($fontDir)) {
            File::makeDirectory($fontDir, 0755, true);
        }
        if ($request->boolean('remove_custom_font')) {
            if ($setting->landing_custom_font_path && file_exists(public_path($setting->landing_custom_font_path))) {
                @unlink(public_path($setting->landing_custom_font_path));
            }
            $validated['landing_custom_font_name'] = null;
            $validated['landing_custom_font_path'] = null;
            // Drop any selection that pointed at the now-removed custom font.
            foreach (['landing_font', 'landing_heading_font'] as $sel) {
                if (($validated[$sel] ?? null) === $setting->landing_custom_font_name) {
                    $validated[$sel] = null;
                }
            }
        } elseif ($request->hasFile('custom_font_file')) {
            $file = $request->file('custom_font_file');
            $ext = strtolower($file->getClientOriginalExtension());
            if (! in_array($ext, ['woff2', 'woff', 'ttf', 'otf'], true)) {
                return back()->withInput()->withErrors([
                    'custom_font_file' => __('super.general_settings.custom_font_invalid'),
                ]);
            }
            if ($setting->landing_custom_font_path && file_exists(public_path($setting->landing_custom_font_path))) {
                @unlink(public_path($setting->landing_custom_font_path));
            }
            $filename = 'font_' . time() . '.' . $ext;
            $file->move($fontDir, $filename);
            $validated['landing_custom_font_path'] = upload_path('fonts') . '/' . $filename;

            // Family name: use the provided one, else derive from the original filename.
            $name = trim((string) ($validated['landing_custom_font_name'] ?? ''));
            if ($name === '') {
                $name = preg_replace('/[^A-Za-z0-9 _-]+/', '', pathinfo($file->getClientOriginalName(), PATHINFO_FILENAME)) ?: 'Custom Font';
            }
            $validated['landing_custom_font_name'] = $name;
        } else {
            // No upload and no removal: keep the existing custom font untouched.
            $validated['landing_custom_font_name'] = $setting->landing_custom_font_name;
            $validated['landing_custom_font_path'] = $setting->landing_custom_font_path;
        }

        // Handle tenant default logo upload
        if ($request->hasFile('tenant_logo')) {
            if ($setting->tenant_logo_path && file_exists(public_path($setting->tenant_logo_path))) {
                @unlink(public_path($setting->tenant_logo_path));
            }
            $file = $request->file('tenant_logo');
            $filename = 'tenant_logo_' . time() . '.' . $file->getClientOriginalExtension();
            $file->move($uploadDir, $filename);
            $validated['tenant_logo_path'] = upload_path('settings') . '/' . $filename;
        }

        // Handle tenant default favicon upload
        if ($request->hasFile('tenant_favicon')) {
            if ($setting->tenant_favicon_path && file_exists(public_path($setting->tenant_favicon_path))) {
                @unlink(public_path($setting->tenant_favicon_path));
            }
            $file = $request->file('tenant_favicon');
            $filename = 'tenant_favicon_' . time() . '.' . $file->getClientOriginalExtension();
            $file->move($uploadDir, $filename);
            $validated['tenant_favicon_path'] = upload_path('settings') . '/' . $filename;
        }

        // Default language lives on the central_languages table (is_default flag),
        // not on general_settings — persist it there and strip it from the settings payload.
        $defaultLanguage = $validated['default_language'] ?? null;
        unset($validated['default_language']);

        if ($defaultLanguage) {
            $target = CentralLanguage::where('locale', $defaultLanguage)
                ->where('is_active', true)
                ->first();

            if ($target) {
                CentralLanguage::where('is_default', true)
                    ->where('id', '!=', $target->id)
                    ->update(['is_default' => false]);

                if (! $target->is_default) {
                    $target->update(['is_default' => true]);
                }
            }
        }

        // Enable/disable languages shown in the language selector. The default
        // language is always kept active so a fallback locale is guaranteed.
        $activeLocales = $validated['languages_active'] ?? [];
        unset($validated['languages_active']);
        $defaultLocale = CentralLanguage::defaultLocale();

        foreach (CentralLanguage::all() as $language) {
            $shouldBeActive = $language->locale === $defaultLocale
                || in_array($language->locale, $activeLocales, true);

            if ($language->is_active !== $shouldBeActive) {
                $language->update(['is_active' => $shouldBeActive]);
            }
        }

        // Remove file inputs from validated data
        unset($validated['logo'], $validated['favicon'], $validated['tenant_logo'], $validated['tenant_favicon']);

        $setting->update($validated);

        // Mirror admin-login visibility and contact email into the landing footer
        // so the landing page / privacy policy stay in sync with general settings.
        LandingFooter::firstOrCreate([])->update([
            'show_admin_login' => $request->boolean('show_admin_login'),
            'contact_email'    => $validated['email'] ?? null,
        ]);

        return back()->with('success', 'General settings saved successfully.');
    }

    public function removeLogo()
    {
        $setting = GeneralSetting::instance();

        if ($setting->logo_path && file_exists(public_path($setting->logo_path))) {
            @unlink(public_path($setting->logo_path));
        }
        $setting->update(['logo_path' => null]);

        return back()->with('success', 'Logo removed.');
    }

    public function removeFavicon()
    {
        $setting = GeneralSetting::instance();

        if ($setting->favicon_path && file_exists(public_path($setting->favicon_path))) {
            @unlink(public_path($setting->favicon_path));
        }
        $setting->update(['favicon_path' => null]);

        return back()->with('success', 'Favicon removed.');
    }

    public function removeTenantLogo()
    {
        $setting = GeneralSetting::instance();

        if ($setting->tenant_logo_path && file_exists(public_path($setting->tenant_logo_path))) {
            @unlink(public_path($setting->tenant_logo_path));
        }
        $setting->update(['tenant_logo_path' => null]);

        return back()->with('success', 'Tenant default logo removed.');
    }

    public function removeTenantFavicon()
    {
        $setting = GeneralSetting::instance();

        if ($setting->tenant_favicon_path && file_exists(public_path($setting->tenant_favicon_path))) {
            @unlink(public_path($setting->tenant_favicon_path));
        }
        $setting->update(['tenant_favicon_path' => null]);

        return back()->with('success', 'Tenant default favicon removed.');
    }

    public function clearCache()
    {
        Artisan::call('config:clear');
        Artisan::call('route:clear');
        Artisan::call('view:clear');
        Artisan::call('cache:clear');

        return back()->with('success', 'Central cache cleared successfully.');
    }

    /**
     * Parse a comma/space separated list of "days before" values into a
     * sorted, deduplicated array of positive integers (max 365).
     *
     * @return int[]
     */
    private function parseOffsets(?string $raw): array
    {
        $tokens = preg_split('/[\s,;]+/', (string) $raw) ?: [];
        $offsets = [];

        foreach ($tokens as $token) {
            $token = trim($token);
            if ($token === '' || ! ctype_digit($token)) {
                continue;
            }
            $value = (int) $token;
            if ($value >= 1 && $value <= 365) {
                $offsets[$value] = true;
            }
        }

        $offsets = array_keys($offsets);
        rsort($offsets);

        return $offsets;
    }

    private function writeEnv(array $data): void
    {
        $path = base_path('.env');
        if (! is_file($path) || ! is_writable($path)) {
            return;
        }

        $content = file_get_contents($path);

        foreach ($data as $key => $value) {
            $stringValue = (string) $value;
            $line = preg_match('/\s|#|"/', $stringValue)
                ? $key . '="' . addcslashes($stringValue, '"\\') . '"'
                : $key . '=' . $stringValue;

            $pattern = '/^' . preg_quote($key, '/') . '=.*$/m';
            if (preg_match($pattern, $content)) {
                $content = preg_replace($pattern, $line, $content);
            } else {
                $content = rtrim($content, "\r\n") . PHP_EOL . $line . PHP_EOL;
            }
        }

        file_put_contents($path, $content);
    }
}
