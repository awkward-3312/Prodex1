<?php

namespace Database\Seeders;

use App\Models\Central\GeneralSetting;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;

class CurrencySeeder extends Seeder
{
    /**
     * Run the database seeds.
     *
     * @return void
     */
    public function run()
    {
        [$code, $symbol] = $this->tenantCurrency();

        DB::table('currencies')->insert(
            [
                'id'     => 1,
                'code'   => $code,
                'name'   => $this->currencyName($code),
                'symbol' => $symbol,
            ]
        );
    }

    /**
     * Resolve the currency code + symbol seeded into new tenants. Pulls
     * from the super admin Tenant Settings tab; falls back to USD/$.
     */
    protected function tenantCurrency(): array
    {
        try {
            $defaults = GeneralSetting::instance()->getTenantDefaults();
            return [$defaults['currency_code'] ?: 'USD', $defaults['currency_symbol'] ?: '$'];
        } catch (\Throwable) {
            return ['USD', '$'];
        }
    }

    /**
     * Best-effort full name lookup for common ISO 4217 codes.
     * Unknown codes fall back to the code itself.
     */
    protected function currencyName(string $code): string
    {
        $map = [
            'USD' => 'US Dollar',
            'EUR' => 'Euro',
            'GBP' => 'British Pound',
            'NGN' => 'Nigerian Naira',
            'GHS' => 'Ghanaian Cedi',
            'ZAR' => 'South African Rand',
            'KES' => 'Kenyan Shilling',
            'INR' => 'Indian Rupee',
            'CAD' => 'Canadian Dollar',
            'AUD' => 'Australian Dollar',
            'JPY' => 'Japanese Yen',
            'CNY' => 'Chinese Yuan',
            'BRL' => 'Brazilian Real',
            'MAD' => 'Moroccan Dirham',
            'AED' => 'UAE Dirham',
            'SAR' => 'Saudi Riyal',
            'EGP' => 'Egyptian Pound',
            'TRY' => 'Turkish Lira',
            'PKR' => 'Pakistani Rupee',
            'BDT' => 'Bangladeshi Taka',
            'MXN' => 'Mexican Peso',
            'CHF' => 'Swiss Franc',
            'SEK' => 'Swedish Krona',
            'NOK' => 'Norwegian Krone',
            'DKK' => 'Danish Krone',
            'PLN' => 'Polish Złoty',
            'RUB' => 'Russian Ruble',
            'SGD' => 'Singapore Dollar',
            'MYR' => 'Malaysian Ringgit',
            'IDR' => 'Indonesian Rupiah',
            'PHP' => 'Philippine Peso',
            'THB' => 'Thai Baht',
            'VND' => 'Vietnamese Đồng',
        ];

        return $map[strtoupper($code)] ?? $code;
    }
}
