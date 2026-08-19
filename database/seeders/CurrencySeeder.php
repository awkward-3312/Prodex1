<?php

namespace Database\Seeders;

use App\Models\Central\GeneralSetting;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;

class CurrencySeeder extends Seeder
{
    public function run()
    {
        [$code, $symbol] = $this->tenantCurrency();

        DB::table('currencies')->insert([
            'id' => 1,
            'code' => $code,
            'name' => $this->currencyName($code),
            'symbol' => $symbol,
        ]);
    }

    /**
     * Los tenants nuevos heredan la moneda definida por SuperAdmin.
     * Si todavía no existe configuración, PRODEX usa Honduras por defecto.
     */
    protected function tenantCurrency(): array
    {
        try {
            $defaults = GeneralSetting::instance()->getTenantDefaults();
            return [$defaults['currency_code'] ?: 'HNL', $defaults['currency_symbol'] ?: 'L'];
        } catch (\Throwable) {
            return ['HNL', 'L'];
        }
    }

    /**
     * Nombres visibles en español para monedas ISO 4217 comunes.
     * Los códigos ISO permanecen intactos porque son identificadores técnicos.
     */
    protected function currencyName(string $code): string
    {
        $map = [
            'HNL' => 'Lempira hondureño',
            'USD' => 'Dólar estadounidense',
            'EUR' => 'Euro',
            'GBP' => 'Libra esterlina',
            'NGN' => 'Naira nigeriana',
            'GHS' => 'Cedi ghanés',
            'ZAR' => 'Rand sudafricano',
            'KES' => 'Chelín keniano',
            'INR' => 'Rupia india',
            'CAD' => 'Dólar canadiense',
            'AUD' => 'Dólar australiano',
            'JPY' => 'Yen japonés',
            'CNY' => 'Yuan chino',
            'BRL' => 'Real brasileño',
            'MAD' => 'Dírham marroquí',
            'AED' => 'Dírham de Emiratos Árabes Unidos',
            'SAR' => 'Riyal saudí',
            'EGP' => 'Libra egipcia',
            'TRY' => 'Lira turca',
            'PKR' => 'Rupia pakistaní',
            'BDT' => 'Taka bangladesí',
            'MXN' => 'Peso mexicano',
            'CHF' => 'Franco suizo',
            'SEK' => 'Corona sueca',
            'NOK' => 'Corona noruega',
            'DKK' => 'Corona danesa',
            'PLN' => 'Złoty polaco',
            'RUB' => 'Rublo ruso',
            'SGD' => 'Dólar de Singapur',
            'MYR' => 'Ringgit malasio',
            'IDR' => 'Rupia indonesia',
            'PHP' => 'Peso filipino',
            'THB' => 'Baht tailandés',
            'VND' => 'Đồng vietnamita',
        ];

        return $map[strtoupper($code)] ?? strtoupper($code);
    }
}
