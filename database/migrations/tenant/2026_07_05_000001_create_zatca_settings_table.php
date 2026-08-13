<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('zatca_settings', function (Blueprint $table) {
            $table->id();

            // Master switches
            $table->boolean('enabled')->default(false);          // Phase 2 integration on/off
            $table->boolean('auto_submit')->default(true);       // report/clear automatically on sale creation
            $table->string('environment', 20)->default('sandbox'); // sandbox | simulation | production

            // EGS unit identity (CSR subject / SAN fields)
            $table->string('common_name')->nullable();           // CN
            $table->string('organization_name')->nullable();     // O  (falls back to Setting.CompanyName)
            $table->string('organization_unit')->nullable();     // OU (branch name; for 11-digit group VAT = TIN of branch)
            $table->string('solution_name')->default('Rasheed'); // SN part 1
            $table->string('solution_version')->default('1.0');  // SN part 2
            $table->string('device_serial')->nullable();         // SN part 3 (generated UUID if empty)
            $table->string('business_category')->nullable();     // industry
            $table->string('invoice_types', 4)->default('1100'); // TSCZ functionality map (T=standard, S=simplified)
            $table->string('crn')->nullable();                   // Commercial Registration Number

            // Seller address (national address, required by BR-KSA rules)
            $table->string('street_name')->nullable();
            $table->string('building_number', 10)->nullable();   // 4 digits
            $table->string('plot_identification', 10)->nullable();
            $table->string('sub_division')->nullable();          // district
            $table->string('city')->nullable();
            $table->string('postal_zone', 10)->nullable();       // 5 digits
            $table->string('country_code', 2)->default('SA');

            // Zero-rated tax handling
            $table->string('zero_tax_reason_code')->default('VATEX-SA-32');
            $table->string('zero_tax_reason')->default('Export of goods');

            // Cryptographic material & onboarding state
            $table->longText('private_key')->nullable();         // encrypted PEM
            $table->longText('csr')->nullable();                 // PEM CSR
            $table->string('compliance_request_id')->nullable();
            $table->longText('ccsid_token')->nullable();         // encrypted compliance CSID (binarySecurityToken)
            $table->longText('ccsid_secret')->nullable();        // encrypted
            $table->longText('pcsid_token')->nullable();         // encrypted production CSID
            $table->longText('pcsid_secret')->nullable();        // encrypted
            $table->timestamp('pcsid_requested_at')->nullable();
            $table->string('onboarding_status', 30)->default('not_started'); // not_started|csid_issued|compliance_checked|ready
            $table->json('compliance_results')->nullable();

            // Invoice counter chain (per EGS unit)
            $table->unsignedBigInteger('icv')->default(0);
            $table->text('last_invoice_hash')->nullable();       // PIH for the next invoice

            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('zatca_settings');
    }
};
