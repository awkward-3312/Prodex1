<?php

namespace Database\Seeders\Central;

use App\Models\Central\GeneralSetting;
use App\Models\Central\SmsTemplate;
use Illuminate\Database\Seeder;

class SmsTemplatesSeeder extends Seeder
{
    public function run(): void
    {
        // Carry over any texts the admin already customized in General
        // Settings so existing installs migrate into the template system.
        $setting = GeneralSetting::instance();

        $templates = [
            [
                'trigger_key' => SmsTemplate::TRIGGER_EXPIRING_SOON,
                'name'        => 'Subscription Expiring Soon',
                'body'        => $setting->reminderSmsTemplate(),
            ],
            [
                'trigger_key' => SmsTemplate::TRIGGER_TRIAL_ENDING,
                'name'        => 'Trial Ending Soon',
                'body'        => $setting->trialSmsTemplate(),
            ],
        ];

        foreach ($templates as $tpl) {
            // firstOrCreate (not updateOrCreate) so re-seeding never clobbers
            // bodies the admin has customized.
            SmsTemplate::firstOrCreate(
                ['trigger_key' => $tpl['trigger_key']],
                $tpl
            );
        }
    }
}
