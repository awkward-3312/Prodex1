<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::connection('central')->table('general_settings', function (Blueprint $table) {
            // Which SMS provider the platform uses to send reminders to tenants.
            // Provider credentials continue to live in .env (shared with SmsOtpSender).
            if (! Schema::connection('central')->hasColumn('general_settings', 'sms_gateway')) {
                $table->string('sms_gateway', 32)->nullable();
            }
            if (! Schema::connection('central')->hasColumn('general_settings', 'subscription_reminder_sms')) {
                $table->text('subscription_reminder_sms')->nullable();
            }
            if (! Schema::connection('central')->hasColumn('general_settings', 'trial_reminder_sms')) {
                $table->text('trial_reminder_sms')->nullable();
            }
        });
    }

    public function down(): void
    {
        Schema::connection('central')->table('general_settings', function (Blueprint $table) {
            $table->dropColumn([
                'sms_gateway',
                'subscription_reminder_sms',
                'trial_reminder_sms',
            ]);
        });
    }
};
