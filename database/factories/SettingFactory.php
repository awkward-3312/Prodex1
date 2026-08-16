<?php

namespace Database\Factories;

use App\Models\Setting;
use Illuminate\Database\Eloquent\Factories\Factory;

class SettingFactory extends Factory
{
    /**
     * The name of the factory's corresponding model.
     *
     * @var string
     */
    protected $model = Setting::class;

    /**
     * Define the model's default state.
     *
     * @return array
     */
    public function definition()
    {
        return [
            'email' => $this->faker->email(),
            'CompanyName' => $this->faker->company(),
            'CompanyPhone' => $this->faker->phoneNumber(),
            'CompanyAdress' => $this->faker->address(),
            'app_name' => 'Prodex Testing',
            'currency_id' => 1,
            'default_language' => 'en',
            'show_language' => 1,
            'quotation_with_stock' => 1,
            'is_invoice_footer' => 0,
            'customize_button_visible' => 1,
            'hide_site_name' => 0,
            'country_code' => 'HN',
            'tax_regime_code' => 'SAR',
            'tax_rate' => 15.0,
            'legal_document_label' => 'RTN',
            'require_rtn' => true,
            'require_rfc' => false,
            'require_nit' => false,
            'default_tax' => 15.0,
            'timezone' => 'America/Tegucigalpa',
            'locale' => 'es-HN',
        ];
    }
}
