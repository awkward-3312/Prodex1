<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('zatca_logs', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('zatca_document_id')->nullable()->index();
            $table->string('action', 50);                 // csr, compliance_csid, compliance_check, production_csid, reporting, clearance
            $table->string('endpoint')->nullable();
            $table->unsignedSmallInteger('http_status')->nullable();
            $table->json('request_meta')->nullable();     // safe request metadata (never keys/secrets)
            $table->longText('response_body')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('zatca_logs');
    }
};
