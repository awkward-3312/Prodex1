<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('sar_fiscal_profiles', function (Blueprint $table) {
            $table->id();
            $table->boolean('enabled')->default(false);
            $table->string('rtn', 20);
            $table->string('legal_name', 191);
            $table->string('trade_name', 191)->nullable();
            $table->text('head_office_address');
            $table->string('phone', 50)->nullable();
            $table->string('email', 191)->nullable();
            $table->timestamps();
        });

        Schema::create('sar_points_of_issue', function (Blueprint $table) {
            $table->id();
            $table->string('establishment_code', 3);
            $table->string('point_code', 3);
            $table->string('name', 191);
            $table->text('address');
            $table->unsignedInteger('warehouse_id')->nullable();
            $table->unsignedInteger('cash_drawer_id')->nullable();
            $table->boolean('active')->default(true);
            $table->timestamps();
            $table->unique(['establishment_code', 'point_code'], 'sar_point_codes_unique');
        });

        Schema::create('sar_authorizations', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('point_of_issue_id');
            $table->string('document_type', 2)->default('01');
            $table->string('cai', 64);
            $table->unsignedBigInteger('range_start');
            $table->unsignedBigInteger('range_end');
            $table->unsignedBigInteger('next_number');
            $table->date('authorization_date')->nullable();
            $table->date('deadline');
            $table->enum('status', ['draft', 'active', 'exhausted', 'expired', 'disabled'])->default('draft');
            $table->timestamps();

            $table->foreign('point_of_issue_id')
                ->references('id')->on('sar_points_of_issue')
                ->cascadeOnUpdate()->restrictOnDelete();
            $table->unique(['point_of_issue_id', 'document_type', 'cai'], 'sar_authorization_unique');
        });

        Schema::create('sar_fiscal_documents', function (Blueprint $table) {
            $table->id();
            $table->unsignedInteger('sale_id');
            $table->unsignedBigInteger('authorization_id');
            $table->unsignedBigInteger('sequence');
            $table->string('fiscal_number', 19);
            $table->string('cai', 64);
            $table->date('deadline');
            $table->enum('status', ['issued', 'voided'])->default('issued');
            $table->timestamp('issued_at');
            $table->timestamp('voided_at')->nullable();
            $table->string('void_reason', 500)->nullable();
            $table->unsignedInteger('voided_by')->nullable();
            $table->json('issuer_snapshot');
            $table->json('customer_snapshot');
            $table->json('sale_snapshot');
            $table->timestamps();

            $table->foreign('sale_id')->references('id')->on('sales')->restrictOnDelete();
            $table->foreign('authorization_id')->references('id')->on('sar_authorizations')->restrictOnDelete();
            $table->unique('sale_id');
            $table->unique('fiscal_number');
            $table->unique(['authorization_id', 'sequence'], 'sar_authorization_sequence_unique');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('sar_fiscal_documents');
        Schema::dropIfExists('sar_authorizations');
        Schema::dropIfExists('sar_points_of_issue');
        Schema::dropIfExists('sar_fiscal_profiles');
    }
};
