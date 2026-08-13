<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up()
    {
        Schema::create('marketing_campaign_recipients', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('campaign_id');
            $table->unsignedBigInteger('client_id')->nullable();
            $table->string('name')->nullable();
            $table->string('phone')->nullable();
            $table->string('email')->nullable();
            $table->enum('status', ['pending', 'sent', 'delivered', 'failed', 'skipped'])->default('pending');
            $table->text('error_message')->nullable();
            $table->dateTime('sent_at')->nullable();
            $table->dateTime('delivered_at')->nullable();
            $table->timestamps(6);

            $table->foreign('campaign_id')->references('id')->on('marketing_campaigns')->cascadeOnDelete();
            $table->index(['campaign_id', 'status']);
        });
    }

    public function down()
    {
        Schema::dropIfExists('marketing_campaign_recipients');
    }
};
