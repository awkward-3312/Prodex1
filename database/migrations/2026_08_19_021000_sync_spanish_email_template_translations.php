<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::connection('central')->hasTable('email_template_translations')) {
            return;
        }

        $templates = DB::connection('central')->table('email_templates')
            ->whereIn('trigger_key', [
                'subscription_expired',
                'expiring_soon',
                'trial_ending',
                'payment_success',
                'payment_failed',
                'plan_ended',
                'tenant_pending',
                'tenant_under_review',
                'tenant_approved',
                'tenant_rejected',
                'support_ticket_created',
                'support_ticket_reply',
                'support_ticket_status',
            ])
            ->get(['id', 'subject', 'body_html']);

        foreach ($templates as $template) {
            DB::connection('central')->table('email_template_translations')->updateOrInsert(
                [
                    'email_template_id' => $template->id,
                    'locale' => 'es',
                ],
                [
                    'subject' => $template->subject,
                    'body_html' => $template->body_html,
                    'updated_at' => now(),
                    'created_at' => now(),
                ]
            );
        }
    }

    public function down(): void
    {
        // Do not delete administrator-editable translations on rollback.
    }
};
