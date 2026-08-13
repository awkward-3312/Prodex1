<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::create('portal_clients', function (Blueprint $table) {
            $table->collation = 'utf8mb4_unicode_ci';
            $table->charset = 'utf8mb4';

            $table->bigIncrements('id');
            $table->integer('client_id')->index('portal_clients_client_id_foreign');
            $table->string('email', 192)->unique();
            $table->string('password')->nullable();
            $table->boolean('status')->default(false)->comment('0=disabled, 1=active');
            $table->string('invitation_token', 64)->nullable();
            $table->timestamp('invitation_sent_at')->nullable();
            $table->rememberToken();
            $table->timestamps();

            $table->index(['email', 'status']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('portal_clients');
    }
};
