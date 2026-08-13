<?php

namespace Database\Seeders;

use App\Models\Central\CentralLanguage;
use App\Models\Central\GeneralSetting;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;

class SettingSeeder extends Seeder
{
    /**
     * Run the database seeds.
     *
     * @return void
     */
    public function run()
    {
        // Pull central (Super Admin) tenant defaults so each new tenant
        // inherits the platform-wide branding instead of the hardcoded
        // "Stocky" placeholders. Each value has a fallback so installs
        // without configured defaults still get a working settings row.
        $defaults = $this->tenantDefaults();

        // Resolve the seeded language locale to one that actually exists
        // in the tenant's languages table. CentralLanguage::defaultLocale()
        // is the cross-tenant baseline; the tenant default overrides it.
        $language = $defaults['default_language']
            ?? $this->centralDefaultLocale()
            ?? 'en';

        // The logo setting is always stored as a bare filename. The frontend
        // resolves it via $imgUrl('settings', logo), which prepends the
        // tenant-aware upload path and the settings/ subfolder
        // (/images/tenants/{id}/settings/{logo}). ProvisionTenantWorkspace
        // copies any super-admin-configured tenant default to
        // images/tenants/{id}/settings/logo-default.png, so the same bare
        // "logo-default.png" filename resolves to the tenant-scoped copy.
        $logo = 'logo-default.png';

        DB::table('settings')->insert(
            [
                'id' => 1,
                'email' => $defaults['email'],
                'currency_id' => 1,
                'client_id' => 1,
                'sms_gateway' => 1,
                'point_to_amount_rate' => 1,
                'is_invoice_footer' => 0,
                'invoice_footer' => null,
                'warehouse_id' => null,
                'CompanyName' => $defaults['company_name'],
                'CompanyPhone' => $defaults['phone'] ?: '6315996770',
                'CompanyAdress' => $defaults['address'] ?: '3618 Abia Martin Drive',
                'footer' => $defaults['footer_text'],
                'developed_by' => $defaults['developed_by'],
                'logo' => $logo,
                'app_name' => $defaults['app_name'],
                'page_title_suffix' => $defaults['page_title_suffix'],
                'favicon' => 'favicon.ico',
                'default_language' => $language,
                'quotation_with_stock' => 1,
                'show_language' => 1,
                'default_tax' => 0,
                'default_dashboard_date_range' => 'week',
            ]
        );
    }

    /**
     * Pull the tenant defaults configured in the central GeneralSetting
     * Tenant Settings tab. Falls back to safe values when central settings
     * are unavailable (e.g. during the initial install).
     */
    protected function tenantDefaults(): array
    {
        try {
            return GeneralSetting::instance()->getTenantDefaults();
        } catch (\Throwable) {
            return [
                'app_name'          => 'Stocky | Ultimate Inventory With POS',
                'company_name'      => 'Stocky',
                'email'             => 'admin@example.com',
                'phone'             => '',
                'address'           => '',
                'currency_code'     => 'USD',
                'currency_symbol'   => '$',
                'default_language'  => null,
                'footer_text'       => 'Stocky - Ultimate Inventory With POS',
                'page_title_suffix' => 'Ultimate Inventory With POS',
                'developed_by'      => 'Stocky',
                'logo_path'         => null,
                'favicon_path'      => null,
            ];
        }
    }

    protected function centralDefaultLocale(): ?string
    {
        try {
            return CentralLanguage::defaultLocale();
        } catch (\Throwable) {
            return null;
        }
    }
}
