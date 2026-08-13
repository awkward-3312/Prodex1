<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

class AddCustomSmsGateway extends Migration
{
    public function up()
    {
        $exists = DB::table('sms_gateway')->where('title', 'custom')->exists();

        if (! $exists) {
            // Use the explicit free id (2) that SmsGatewaySeeder leaves between
            // twilio (1) and infobip (3). On a fresh tenant DB this migration
            // runs before the seeder, so without an explicit id 'custom' would
            // take auto-increment id 1 and collide with the seeder's twilio row.
            DB::table('sms_gateway')->insertOrIgnore([
                'id' => 2,
                'title' => 'custom',
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        }
    }

    public function down()
    {
        DB::table('sms_gateway')->where('title', 'custom')->delete();
    }
}
