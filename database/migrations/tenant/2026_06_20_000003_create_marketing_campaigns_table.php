<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up()
    {
        Schema::create('marketing_campaigns', function (Blueprint $table) {
            $table->id();
            $table->string('title');
            $table->text('description')->nullable();
            $table->enum('type', ['sms', 'email', 'whatsapp'])->default('sms');
            $table->string('subject')->nullable();           // email subject
            $table->text('message_content');
            $table->string('attachment')->nullable();         // email attachment (flyer/catalog)
            $table->unsignedBigInteger('template_id')->nullable();
            $table->unsignedBigInteger('segment_id')->nullable();
            $table->boolean('all_customers')->default(false);
            $table->enum('status', ['draft', 'scheduled', 'sending', 'sent', 'failed', 'cancelled'])->default('draft');
            $table->boolean('send_immediately')->default(true);
            $table->dateTime('scheduled_at')->nullable();
            $table->dateTime('sent_at')->nullable();
            $table->integer('total_recipients')->default(0);
            $table->integer('sent_count')->default(0);
            $table->integer('delivered_count')->default(0);
            $table->integer('failed_count')->default(0);
            $table->integer('pending_count')->default(0);
            $table->unsignedBigInteger('created_by')->nullable();
            $table->timestamps(6);
            $table->softDeletes();

            $table->index(['type', 'status']);
            $table->index('scheduled_at');
        });
    }

    public function down()
    {
        Schema::dropIfExists('marketing_campaigns');
    }
};
