<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (Schema::connection('central')->hasTable('subscription_reminders')) {
            return;
        }

        Schema::connection('central')->create('subscription_reminders', function (Blueprint $table) {
            $table->id();
            $table->string('tenant_id')->nullable()->index();
            $table->unsignedBigInteger('tenant_subscription_id')->nullable()->index();

            // What kind of reminder: expiry | trial | expired | plan_ended
            $table->string('type', 32);
            // Delivery channel: email | sms | banner
            $table->string('channel', 16);
            // Days before the target date this reminder represents (0 for expired/plan_ended)
            $table->unsignedSmallInteger('offset_days')->default(0);

            // The date the reminder was anchored to (ends_at / trial_ends_at as a date).
            // Combined with type/channel/offset this gives us idempotency.
            $table->date('reference_date')->nullable();

            $table->string('target')->nullable();          // email address or phone number
            $table->string('status', 16)->default('sent'); // sent | failed | skipped
            $table->text('error')->nullable();
            $table->timestamp('sent_at')->nullable();
            $table->timestamps();

            $table->unique(
                ['tenant_subscription_id', 'type', 'channel', 'offset_days', 'reference_date'],
                'sub_reminder_idempotency'
            );
        });
    }

    public function down(): void
    {
        Schema::connection('central')->dropIfExists('subscription_reminders');
    }
};
