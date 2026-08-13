<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::connection('central')->create('support_ticket_messages', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('support_ticket_id');

            $table->string('author_type');                  // tenant | admin
            $table->unsignedBigInteger('author_id')->nullable(); // tenant user id OR central_user id
            $table->string('author_name')->nullable();      // snapshot for display

            // Internal notes are only ever visible to super admins, never the tenant.
            $table->boolean('is_internal_note')->default(false);

            $table->text('body')->nullable();
            $table->timestamps();

            $table->foreign('support_ticket_id')->references('id')->on('support_tickets')
                ->onUpdate('cascade')->onDelete('cascade');

            $table->index('support_ticket_id');
        });
    }

    public function down(): void
    {
        Schema::connection('central')->dropIfExists('support_ticket_messages');
    }
};
