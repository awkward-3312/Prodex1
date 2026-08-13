<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('property_inquiries', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('property_id')->nullable();
            $table->string('name');
            $table->string('phone')->nullable();
            $table->string('email')->nullable();
            $table->text('message')->nullable();
            $table->enum('status', ['new', 'read', 'responded', 'closed'])->default('new');
            $table->timestamps(6);
            $table->softDeletes();

            $table->index('property_id');
            $table->index('status');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('property_inquiries');
    }
};
