<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up()
    {
        Schema::table('service_jobs', function (Blueprint $table) {
            // End of the scheduled time window — mirrors the Start/End hours
            // already available on regular bookings (booking_time/booking_end_time).
            if (! Schema::hasColumn('service_jobs', 'scheduled_end_date')) {
                $table->dateTime('scheduled_end_date')->nullable()->after('scheduled_date');
            }
        });
    }

    public function down()
    {
        Schema::table('service_jobs', function (Blueprint $table) {
            if (Schema::hasColumn('service_jobs', 'scheduled_end_date')) {
                $table->dropColumn('scheduled_end_date');
            }
        });
    }
};
