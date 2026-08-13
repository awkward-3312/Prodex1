<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::connection('central')->create('support_tickets', function (Blueprint $table) {
            $table->id();
            $table->string('ticket_number')->unique();          // human-friendly ref, e.g. TKT-000123
            $table->string('tenant_id');                        // FK -> tenants.id

            // Snapshot of the tenant user who opened the ticket. The user lives in
            // the tenant database, so we keep an id reference plus a name/email copy.
            $table->unsignedBigInteger('opened_by_user_id')->nullable();
            $table->string('opened_by_name')->nullable();
            $table->string('opened_by_email')->nullable();

            $table->string('subject');
            $table->string('category')->default('technical');   // bug, billing, technical, feature_request, account
            $table->string('priority')->default('medium');      // low, medium, high, urgent
            $table->string('status')->default('open');          // open, pending, resolved, closed

            // Optional assignment to a super-admin / support staff member.
            $table->unsignedBigInteger('assigned_to')->nullable();

            // Lightweight unread tracking for both sides.
            $table->timestamp('last_reply_at')->nullable();
            $table->string('last_reply_by')->nullable();        // tenant | admin
            $table->boolean('tenant_unread')->default(false);   // unseen admin reply for the tenant
            $table->boolean('admin_unread')->default(true);     // unseen tenant activity for the admin

            $table->timestamp('resolved_at')->nullable();
            $table->timestamp('closed_at')->nullable();
            $table->timestamps();

            $table->foreign('tenant_id')->references('id')->on('tenants')
                ->onUpdate('cascade')->onDelete('cascade');
            $table->foreign('assigned_to')->references('id')->on('central_users')
                ->onUpdate('cascade')->onDelete('set null');

            $table->index('tenant_id');
            $table->index('status');
            $table->index('priority');
            $table->index('category');
            $table->index('assigned_to');
        });
    }

    public function down(): void
    {
        Schema::connection('central')->dropIfExists('support_tickets');
    }
};
