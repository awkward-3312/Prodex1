<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

class AddTrialFieldsToPlansTable extends Migration
{
    public function up(): void
    {
        Schema::connection('central')->table('plans', function (Blueprint $table) {
            $table->boolean('is_trial')->default(false)->after('is_active');
            $table->unsignedInteger('trial_days')->default(0)->after('is_trial');
        });
    }

    public function down(): void
    {
        Schema::connection('central')->table('plans', function (Blueprint $table) {
            $table->dropColumn(['is_trial', 'trial_days']);
        });
    }
}
