<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('zatca_documents', function (Blueprint $table) {
            $table->id();

            // Source business document: App\Models\Sale or App\Models\SaleReturn
            $table->string('source_type');
            $table->unsignedBigInteger('source_id');

            $table->string('invoice_number')->nullable();     // cbc:ID (sale Ref)
            $table->string('invoice_type_code', 3);           // 388 invoice | 381 credit note | 383 debit note
            $table->string('invoice_subtype', 20);            // standard | simplified
            $table->uuid('uuid');
            $table->unsignedBigInteger('icv');
            $table->text('invoice_hash')->nullable();
            $table->text('pih')->nullable();
            $table->text('qr_code')->nullable();              // Base64 TLV payload (Phase 2, tags 1-9)

            $table->string('status', 20)->default('pending'); // pending | reported | cleared | failed
            $table->string('environment', 20)->default('sandbox');
            $table->longText('invoice_xml')->nullable();      // signed submitted XML
            $table->longText('cleared_xml')->nullable();      // XML returned by clearance (standard invoices)
            $table->json('zatca_response')->nullable();
            $table->json('warnings')->nullable();
            $table->json('errors')->nullable();
            $table->timestamp('submitted_at')->nullable();

            $table->timestamps();

            $table->unique(['source_type', 'source_id']);
            $table->index(['status']);
            $table->index(['uuid']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('zatca_documents');
    }
};
