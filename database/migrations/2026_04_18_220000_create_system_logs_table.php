<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (Schema::connection('central')->hasTable('system_logs')) {
            return;
        }

        Schema::connection('central')->create('system_logs', function (Blueprint $table) {
            $table->bigIncrements('id');
            $table->string('tenant_id')->nullable()->index();
            $table->string('type', 50)->index();          // dns, ssl, database, provisioning, exception, general
            $table->string('severity', 20)->default('warning')->index();   // info, warning, critical
            $table->string('message', 1000);
            $table->json('context')->nullable();
            $table->text('suggested_cause')->nullable();
            $table->string('source', 100)->nullable();    // where it was captured (e.g. controller, job, handler)
            $table->string('status', 20)->default('unresolved')->index();  // unresolved, resolved
            $table->unsignedBigInteger('resolved_by')->nullable();
            $table->timestamp('resolved_at')->nullable();
            $table->text('resolution_note')->nullable();
            $table->timestamp('occurred_at')->useCurrent()->index();
            $table->timestamps();

            $table->index(['tenant_id', 'type']);
            $table->index(['status', 'severity']);
        });
    }

    public function down(): void
    {
        Schema::connection('central')->dropIfExists('system_logs');
    }
};
