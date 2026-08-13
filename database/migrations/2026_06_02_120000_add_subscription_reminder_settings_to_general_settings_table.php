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
            if (! Schema::connection('central')->hasColumn('general_settings', 'subscription_reminders_enabled')) {
                $table->boolean('subscription_reminders_enabled')->default(true);
            }
            if (! Schema::connection('central')->hasColumn('general_settings', 'subscription_reminder_offsets')) {
                $table->json('subscription_reminder_offsets')->nullable();
            }
            if (! Schema::connection('central')->hasColumn('general_settings', 'subscription_reminder_channels')) {
                $table->json('subscription_reminder_channels')->nullable();
            }
            if (! Schema::connection('central')->hasColumn('general_settings', 'trial_reminders_enabled')) {
                $table->boolean('trial_reminders_enabled')->default(true);
            }
            if (! Schema::connection('central')->hasColumn('general_settings', 'trial_reminder_offsets')) {
                $table->json('trial_reminder_offsets')->nullable();
            }
            if (! Schema::connection('central')->hasColumn('general_settings', 'subscription_banner_threshold_days')) {
                $table->unsignedSmallInteger('subscription_banner_threshold_days')->default(7);
            }
        });
    }

    public function down(): void
    {
        Schema::connection('central')->table('general_settings', function (Blueprint $table) {
            $table->dropColumn([
                'subscription_reminders_enabled',
                'subscription_reminder_offsets',
                'subscription_reminder_channels',
                'trial_reminders_enabled',
                'trial_reminder_offsets',
                'subscription_banner_threshold_days',
            ]);
        });
    }
};
