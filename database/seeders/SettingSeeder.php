<?php

namespace Database\Seeders;

use App\Models\Central\CentralLanguage;
use App\Models\Central\GeneralSetting;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;

class SettingSeeder extends Seeder
{
    public function run()
    {
        $defaults = $this->tenantDefaults();

        $language = $defaults['default_language']
            ?? $this->centralDefaultLocale()
            ?? 'es';

        $logo = 'logo-default.png';

        DB::table('settings')->insert([
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
            'CompanyPhone' => $defaults['phone'],
            'CompanyAdress' => $defaults['address'],
            'footer' => 'PRODEX',
            'developed_by' => 'PRODEX',
            'logo' => $logo,
            'app_name' => $defaults['app_name'],
            'page_title_suffix' => $defaults['page_title_suffix'],
            'favicon' => 'favicon.ico',
            'default_language' => $language,
            'country_code' => 'HN',
            'tax_regime_code' => 'SAR',
            'tax_rate' => 15.00,
            'locale' => 'es-HN',
            'legal_document_label' => 'RTN',
            'require_rtn' => 1,
            'require_rfc' => 0,
            'require_nit' => 0,
            'quotation_with_stock' => 1,
            'show_language' => 1,
            'default_tax' => 0,
            'default_dashboard_date_range' => 'week',
        ]);
    }

    protected function tenantDefaults(): array
    {
        try {
            return GeneralSetting::instance()->getTenantDefaults();
        } catch (\Throwable) {
            return [
                'app_name' => 'PRODEX',
                'company_name' => 'PRODEX',
                'email' => 'admin@prodexhub.cloud',
                'phone' => '',
                'address' => '',
                'currency_code' => 'HNL',
                'currency_symbol' => 'L',
                'default_language' => 'es',
                'footer_text' => 'PRODEX',
                'page_title_suffix' => 'Gestión empresarial',
                'developed_by' => 'PRODEX',
                'logo_path' => null,
                'favicon_path' => null,
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
