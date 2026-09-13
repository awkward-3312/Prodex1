<?php
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('mobile_customer_operations', function (Blueprint $table) {
            $table->id();
            $table->uuid('operation_uuid')->unique();
            $table->unsignedBigInteger('user_id');
            $table->unsignedBigInteger('client_id')->index();
            $table->char('payload_fingerprint', 64);
            $table->timestamps();
        });
    }
    public function down(): void { Schema::dropIfExists('mobile_customer_operations'); }
};
