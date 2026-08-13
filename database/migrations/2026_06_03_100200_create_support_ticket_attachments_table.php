<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::connection('central')->create('support_ticket_attachments', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('support_ticket_id');
            $table->unsignedBigInteger('support_ticket_message_id')->nullable();

            $table->string('file_path');                    // relative path under public/
            $table->string('original_name')->nullable();
            $table->string('mime_type')->nullable();
            $table->unsignedBigInteger('size')->default(0); // bytes
            $table->timestamps();

            $table->foreign('support_ticket_id')->references('id')->on('support_tickets')
                ->onUpdate('cascade')->onDelete('cascade');
            $table->foreign('support_ticket_message_id')->references('id')->on('support_ticket_messages')
                ->onUpdate('cascade')->onDelete('cascade');

            $table->index('support_ticket_id');
            $table->index('support_ticket_message_id');
        });
    }

    public function down(): void
    {
        Schema::connection('central')->dropIfExists('support_ticket_attachments');
    }
};
