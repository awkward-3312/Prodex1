<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;

class DatabaseSeeder extends Seeder
{
    /**
     * Seed the application's database.
     *
     * @return void
     */
    public function run()
    {

        $this->call([
            ClientSeeder::class,
            CurrencySeeder::class,
            SettingSeeder::class,
            ServerSeeder::class,
            PaymentMethodsSeeder::class,
            PermissionsSeeder::class,
            RoleSeeder::class,
            UserSeeder::class,
            UserRoleSeeder::class,
            PermissionRoleSeeder::class,
            Warehouse::class,
            StoreSettingSeeder::class,
            PosSettingsSeeder::class,
            SmsGatewaySeeder::class,
            EmailMessagesSeeder::class,
            SmsMessagesSeeder::class,
            LanguagesSeeder::class,
            ReportQuestionsSeeder::class,
            EmailTemplatesForLanguagesSeeder::class,
            SmsTemplatesForLanguagesSeeder::class,
            WhatsappTemplatesSeeder::class,
            TranslationSeeder::class,
        ]);

    }
}
