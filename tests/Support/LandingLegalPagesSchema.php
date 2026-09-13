<?php

declare(strict_types=1);

namespace Tests\Support;

use Illuminate\Support\Facades\Schema;

/**
 * Mirrors the central migrations needed to render the public legal pages
 * (Terms, Privacy, Refund Policy) end to end: general_settings (just the
 * columns GeneralSetting::instance()'s fallback create() writes),
 * landing_footer, landing_seo, and the three landing_* legal tables
 * themselves (created via the real migration files in
 * database/migrations, run through this trait, so the actual seeded
 * content is exercised rather than a hand-rolled copy of it).
 */
trait LandingLegalPagesSchema
{
    protected function buildLandingLegalPagesSchema(): void
    {
        if (! Schema::connection('central')->hasTable('general_settings')) {
            Schema::connection('central')->create('general_settings', function ($table) {
                $table->id();
                $table->string('app_name')->nullable();
                $table->string('company_name')->nullable();
                $table->string('currency_code')->nullable();
                $table->string('currency_symbol')->nullable();
                $table->string('landing_template')->nullable();
                $table->string('dashboard_footer_text')->nullable();
                $table->string('logo_path')->nullable();
                $table->string('favicon_path')->nullable();
                $table->string('email')->nullable();
                $table->string('phone')->nullable();
                $table->string('address')->nullable();
                $table->string('website')->nullable();
                $table->string('tenant_app_name')->nullable();
                $table->string('tenant_company_name')->nullable();
                $table->string('tenant_currency_code')->nullable();
                $table->string('tenant_currency_symbol')->nullable();
                $table->string('tenant_default_language')->nullable();
                $table->string('tenant_footer_text')->nullable();
                $table->string('tenant_page_title_suffix')->nullable();
                $table->string('tenant_developed_by')->nullable();
                $table->timestamps();
            });
        }

        if (! Schema::connection('central')->hasTable('landing_footer')) {
            Schema::connection('central')->create('landing_footer', function ($table) {
                $table->id();
                $table->text('footer_about')->nullable();
                $table->string('copyright_text')->nullable();
                $table->string('contact_email')->nullable();
                $table->string('contact_phone')->nullable();
                $table->text('address')->nullable();
                $table->string('facebook')->nullable();
                $table->string('twitter')->nullable();
                $table->string('linkedin')->nullable();
                $table->string('instagram')->nullable();
                $table->string('youtube')->nullable();
                $table->timestamps();
            });
        }

        if (! Schema::connection('central')->hasTable('landing_seo')) {
            Schema::connection('central')->create('landing_seo', function ($table) {
                $table->id();
                $table->string('meta_title')->nullable();
                $table->text('meta_description')->nullable();
                $table->text('meta_keywords')->nullable();
                $table->string('og_image')->nullable();
                $table->string('favicon')->nullable();
                $table->timestamps();
            });
        }

        if (! Schema::connection('central')->hasTable('landing_terms_conditions')) {
            (require __DIR__ . '/../../database/migrations/2026_06_16_120000_create_landing_terms_conditions_table.php')->up();
        }

        // This one predates the anonymous-class migration convention (no
        // `return new class ...`) — it declares a named class instead, so
        // `require` just defines it; it must be instantiated separately,
        // and only once per process (a second require of the same file
        // fatals with a "cannot redeclare class" error).
        if (! Schema::connection('central')->hasTable('landing_privacy_policy')) {
            if (! class_exists('CreateLandingPrivacyPolicyTable')) {
                require __DIR__ . '/../../database/migrations/2026_04_04_100001_create_landing_privacy_policy_table.php';
            }
            (new \CreateLandingPrivacyPolicyTable())->up();
        }

        if (! Schema::connection('central')->hasTable('landing_refund_policy')) {
            (require __DIR__ . '/../../database/migrations/2026_09_14_000000_create_landing_refund_policy_table.php')->up();
        }

        // Backfills the CMS-editable narrative fields (acceptance,
        // use_license, ... on Terms/Privacy; overview, ... on Refund) the
        // same way production deploy does — via the real seeding migration,
        // not a hand-copied fixture. Each test method gets a fresh
        // ':memory:' central connection (a new application instance boots
        // per test), so this must re-run every time, not just once per
        // process; its own "only fill blanks" logic keeps it idempotent
        // and cheap.
        (require __DIR__ . '/../../database/migrations/2026_09_14_000001_seed_landing_legal_pages_content.php')->up();
    }
}
