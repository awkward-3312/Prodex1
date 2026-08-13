<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;

class SmsGatewaySeeder extends Seeder
{
    public function run()
    {
        // insertOrIgnore keeps this idempotent: the add_custom_sms_gateway
        // migration already inserts 'custom' at id 2 before seeding runs, and
        // provisioning may retry, so we must not blow up on existing rows.
        DB::table('sms_gateway')->insertOrIgnore([
            ['id' => 1, 'title' => 'twilio'],
            ['id' => 2, 'title' => 'custom'],
            ['id' => 3, 'title' => 'infobip'],
            ['id' => 4, 'title' => 'termii'],
        ]);
    }
}
