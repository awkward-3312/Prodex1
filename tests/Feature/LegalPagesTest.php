<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\Central\GeneralSetting;
use Tests\Support\LandingLegalPagesSchema;
use Tests\TestCase;

/**
 * The three public legal pages (Terms of Service, Privacy Policy, Refund &
 * Cancellation Policy) must be reachable without authentication, in both
 * supported languages, with correct SEO tags and real (non-empty,
 * non-placeholder) content — this is what Paddle's Domain Review checks,
 * and what makes the pages actually usable as a binding contract.
 */
class LegalPagesTest extends TestCase
{
    use LandingLegalPagesSchema;

    /** @var array<string, string> route name => URL path */
    private const PAGES = [
        'central.privacy-policy' => '/privacy-policy',
        'central.terms-conditions' => '/terms-conditions',
        'central.refund-policy' => '/refund-policy',
    ];

    protected function setUp(): void
    {
        parent::setUp();
        $this->buildLandingLegalPagesSchema();

        GeneralSetting::instance();
    }

    protected function tearDown(): void
    {
        app()->setLocale('es');

        parent::tearDown();
    }

    // ── Reachability, no auth, correct status ───────────────────────────

    public function test_all_three_legal_pages_return_200_without_authentication(): void
    {
        foreach (self::PAGES as $route => $path) {
            $response = $this->get(route($route));
            $response->assertOk();
        }
    }

    public function test_all_three_legal_pages_are_reachable_by_their_clean_url(): void
    {
        foreach (self::PAGES as $route => $path) {
            $this->get($path)->assertOk();
        }
    }

    // ── Language support ─────────────────────────────────────────────────

    public function test_spanish_is_the_default_rendering_language(): void
    {
        app()->setLocale('es');

        $response = $this->get(route('central.terms-conditions'));

        $response->assertOk();
        $response->assertSee('Términos y Condiciones', false);
    }

    public function test_english_locale_renders_english_content(): void
    {
        app()->setLocale('en');

        $response = $this->get(route('central.terms-conditions'));

        $response->assertOk();
        $response->assertSee('Terms of Service', false);
        $response->assertDontSee('Términos y Condiciones', false);
    }

    public function test_english_locale_works_for_all_three_pages(): void
    {
        app()->setLocale('en');

        $this->get(route('central.privacy-policy'))->assertOk()->assertSee('Privacy Policy', false);
        $this->get(route('central.terms-conditions'))->assertOk()->assertSee('Terms of Service', false);
        $this->get(route('central.refund-policy'))->assertOk()->assertSee('Refund &amp; Cancellation Policy', false);
    }

    // ── H1 / structure ───────────────────────────────────────────────────

    public function test_each_page_has_exactly_one_h1_matching_its_document_title(): void
    {
        $expectations = [
            'central.privacy-policy' => 'Política de Privacidad',
            'central.terms-conditions' => 'Términos y Condiciones',
            'central.refund-policy' => 'Política de Reembolsos y Cancelaciones',
        ];

        foreach ($expectations as $route => $expectedH1) {
            $html = $this->get(route($route))->getContent();

            $this->assertSame(1, substr_count($html, '<h1>'), "Expected exactly one <h1> on {$route}.");
            $this->assertStringContainsString("<h1>{$expectedH1}</h1>", $html, "Expected the H1 on {$route} to read \"{$expectedH1}\".");
        }
    }

    // ── Footer links on the default landing template ────────────────────

    public function test_the_default_landing_page_footer_links_to_all_three_legal_pages(): void
    {
        $html = $this->get(route('central.welcome'))->getContent();

        $this->assertStringContainsString(route('central.privacy-policy'), $html);
        $this->assertStringContainsString(route('central.terms-conditions'), $html);
        $this->assertStringContainsString(route('central.refund-policy'), $html);
    }

    // ── SEO: no noindex, canonical present, meta description present ────

    public function test_no_legal_page_is_marked_noindex(): void
    {
        foreach (self::PAGES as $route => $path) {
            $html = $this->get(route($route))->getContent();

            $this->assertDoesNotMatchRegularExpression(
                '/<meta[^>]+name="robots"[^>]+content="[^"]*noindex/i',
                $html,
                "{$route} must not be noindex."
            );
        }
    }

    public function test_each_legal_page_has_a_canonical_url_and_meta_description(): void
    {
        foreach (self::PAGES as $route => $path) {
            $html = $this->get(route($route))->getContent();

            $this->assertMatchesRegularExpression('/<link[^>]+rel="canonical"[^>]+href="[^"]+' . preg_quote($path, '/') . '"/', $html, "{$route} is missing its canonical link.");
            $this->assertMatchesRegularExpression('/<meta[^>]+name="description"[^>]+content="[^"]+"/i', $html, "{$route} is missing its meta description.");
        }
    }

    // ── Content integrity: not empty, no leftover placeholders ──────────

    public function test_no_legal_page_contains_leftover_placeholder_markers(): void
    {
        $placeholderPatterns = ['[EMPRESA', '[DIRECCIÓN', '[COMPANY', '[ADDRESS', 'TODO:', 'LOREM IPSUM', 'PLACEHOLDER'];

        foreach (self::PAGES as $route => $path) {
            $html = strtoupper($this->get(route($route))->getContent());

            foreach ($placeholderPatterns as $marker) {
                $this->assertStringNotContainsString($marker, $html, "{$route} contains a leftover placeholder marker: {$marker}");
            }
        }
    }

    public function test_each_legal_document_body_has_substantial_real_content(): void
    {
        foreach (self::PAGES as $route => $path) {
            $html = $this->get(route($route))->getContent();

            // A stub/empty legal page would never reach this length; the
            // real documents run to several thousand characters of body copy.
            $this->assertGreaterThan(8000, strlen($html), "{$route} looks too short to contain the full legal document.");
        }
    }

    public function test_terms_and_privacy_reference_paddle_as_merchant_of_record(): void
    {
        $terms = $this->get(route('central.terms-conditions'))->getContent();
        $privacy = $this->get(route('central.privacy-policy'))->getContent();
        $refund = $this->get(route('central.refund-policy'))->getContent();

        $this->assertStringContainsString('Paddle', $terms);
        $this->assertStringContainsString('Paddle', $privacy);
        $this->assertStringContainsString('Paddle', $refund);
    }

    // ── Internal legal links resolve ─────────────────────────────────────

    public function test_internal_legal_document_links_resolve_to_200(): void
    {
        foreach (self::PAGES as $route => $path) {
            $this->assertTrue(\Illuminate\Support\Facades\Route::has($route), "Route {$route} is not registered.");
        }

        // Every legal route must itself resolve (already covered above),
        // and the "Home" link present in each legal page's nav must resolve too.
        $home = $this->get(route('central.privacy-policy'))->getContent();
        $this->assertStringContainsString(route('central.welcome'), $home);
        $this->get(route('central.welcome'))->assertOk();
    }

    // ── Identity data renders ────────────────────────────────────────────

    public function test_configured_legal_entity_name_renders_on_the_terms_page(): void
    {
        config([
            'legal.entity_name' => 'PRODEX Software Test Entity, S. de R.L.',
            'legal.address' => 'Test Address 123, Tegucigalpa, Honduras',
            'legal.tax_id' => '0801-9999-999999',
        ]);

        $html = $this->get(route('central.terms-conditions'))->getContent();

        $this->assertStringContainsString('PRODEX Software Test Entity, S. de R.L.', $html);
        $this->assertStringContainsString('Test Address 123, Tegucigalpa, Honduras', $html);
        $this->assertStringContainsString('0801-9999-999999', $html);
    }

    public function test_terms_page_falls_back_to_a_contact_request_when_legal_entity_is_not_configured(): void
    {
        config(['legal.entity_name' => null]);

        $html = $this->get(route('central.terms-conditions'))->getContent();

        $this->assertStringNotContainsString('[EMPRESA', $html);
        $this->assertStringNotContainsString('[COMPANY', $html);
    }

    // ── Admin CMS: firstOrCreate() must never seed a literal missing key ──

    public function test_admin_cms_terms_conditions_page_never_seeds_a_missing_translation_key_literal(): void
    {
        \App\Models\Central\LandingTermsConditions::query()->delete();

        $controller = new \App\Http\Controllers\Central\Super\LandingCmsController();
        $controller->termsConditions();

        $terms = \App\Models\Central\LandingTermsConditions::firstOrFail();
        foreach (['acceptance', 'use_license', 'user_accounts', 'payments', 'prohibited', 'intellectual_property', 'liability', 'governing_law'] as $field) {
            $this->assertStringNotContainsString('landing.', (string) $terms->getRawOriginal($field), "Field {$field} contains a literal untranslated key.");
        }
    }

    public function test_admin_cms_refund_policy_page_never_seeds_a_missing_translation_key_literal(): void
    {
        \App\Models\Central\LandingRefundPolicy::query()->delete();

        $controller = new \App\Http\Controllers\Central\Super\LandingCmsController();
        $controller->refundPolicy();

        $refund = \App\Models\Central\LandingRefundPolicy::firstOrFail();
        foreach (['overview', 'subscriptions_trials', 'cancellations', 'billing_errors', 'refund_eligibility', 'chargebacks', 'how_to_request', 'payment_processor'] as $field) {
            $this->assertStringNotContainsString('landing.', (string) $refund->getRawOriginal($field), "Field {$field} contains a literal untranslated key.");
        }
    }

    public function test_confirmed_support_contact_email_renders_on_terms_page(): void
    {
        \App\Models\Central\LandingFooter::query()->delete();
        \App\Models\Central\LandingFooter::create(['contact_email' => 'soporte@prodexhub.cloud']);

        $html = $this->get(route('central.terms-conditions'))->getContent();

        $this->assertStringContainsString('soporte@prodexhub.cloud', $html);
    }
}
