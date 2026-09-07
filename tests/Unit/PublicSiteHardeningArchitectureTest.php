<?php

namespace Tests\Unit;

use PHPUnit\Framework\TestCase;

/**
 * Launch hardening contract for the public marketing site. File-content
 * assertions (no DB / HTTP) — same style as {@see LandingPrimeContractTest} —
 * so the guarantees survive refactors and a future private route can never
 * silently drift into the sitemap or lose its noindex.
 */
class PublicSiteHardeningArchitectureTest extends TestCase
{
    private function root(): string
    {
        return dirname(__DIR__, 2);
    }

    private function read(string $rel): string
    {
        $path = $this->root().'/'.$rel;
        $this->assertFileExists($path, $rel);

        return (string) file_get_contents($path);
    }

    // 1 / 2 — privacy + terms are wired, public and SEO-complete ------------

    public function test_privacy_and_terms_routes_are_public_and_named(): void
    {
        $routes = $this->read('routes/central.php');
        $this->assertMatchesRegularExpression(
            "#Route::get\('/privacy-policy',.*->name\('central\.privacy-policy'\)#s",
            $routes
        );
        $this->assertMatchesRegularExpression(
            "#Route::get\('/terms-conditions',.*->name\('central\.terms-conditions'\)#s",
            $routes
        );
        // Neither is behind auth / a guarded group.
        $this->assertStringNotContainsString("privacy-policy', ['middleware' => 'auth", $routes);
    }

    public function test_privacy_and_terms_views_emit_canonical_and_seo_head(): void
    {
        foreach (['privacy-policy', 'terms-conditions'] as $view) {
            $src = $this->read("resources/views/central/{$view}.blade.php");
            $this->assertStringContainsString('$seoCanonicalUrl', $src, $view);
            $this->assertStringContainsString("central.partials.analytics", $src, $view);
        }
        // seo-head is included directly by privacy, and via landing-font by terms.
        $this->assertStringContainsString("@include('central.partials.seo-head')", $this->read('resources/views/central/privacy-policy.blade.php'));
        $this->assertStringContainsString("central.partials.seo-head", $this->read('resources/views/central/partials/landing-font.blade.php'));
    }

    public function test_privacy_policy_covers_payments_retention_and_changes(): void
    {
        $src = $this->read('resources/views/central/privacy-policy.blade.php');
        foreach (["'payments'", "'retention'", "'changes'", 'privacy_payments_text', 'privacy_retention_text', 'privacy_changes_text'] as $needle) {
            $this->assertStringContainsString($needle, $src, $needle);
        }
        foreach (['es', 'en'] as $loc) {
            $lang = $this->read("resources/lang/{$loc}/landing.php");
            foreach (['privacy_payments_text', 'privacy_retention_text', 'privacy_changes_text', 'privacy_meta_description', 'terms_meta_description'] as $key) {
                $this->assertStringContainsString("'{$key}'", $lang, "{$loc}:{$key}");
            }
        }
    }

    public function test_terms_page_covers_the_saas_essentials(): void
    {
        $src = $this->read('resources/views/central/terms-conditions.blade.php');
        foreach (["'trial'", "'cancellation'", "'availability'", "'customer-data'", "'termination'", "'contact'"] as $id) {
            $this->assertStringContainsString($id, $src, $id);
        }
        foreach (['es', 'en'] as $loc) {
            $lang = $this->read("resources/lang/{$loc}/landing.php");
            foreach (['terms_trial_text', 'terms_cancellation_text', 'terms_availability_text', 'terms_customer_data_text', 'terms_termination_text'] as $key) {
                $this->assertStringContainsString("'{$key}'", $lang, "{$loc}:{$key}");
            }
        }
    }

    // 3 — no secret ever shipped to the front end / bundle -----------------

    public function test_no_known_secret_pattern_in_frontend_or_bundles(): void
    {
        $targets = array_merge(
            glob($this->root().'/public/js/*.min.js') ?: [],
            glob($this->root().'/public/assets_super/js/*.js') ?: [],
            [$this->root().'/webpack.mix.js'],
        );
        $patterns = [
            '/sk_live_[0-9A-Za-z]{16,}/',
            '/\bAKIA[0-9A-Z]{16}\b/',
            '/AIza[0-9A-Za-z_\-]{30,}/',
            '/-----BEGIN [A-Z ]*PRIVATE KEY-----/',
            '/xox[bp]-[0-9A-Za-z-]{10,}/',
            "/client_secret['\"]?\s*[:=]\s*['\"][A-Za-z0-9_\-]{12,}['\"]/",
        ];
        foreach ($targets as $file) {
            if (! is_file($file)) {
                continue;
            }
            $body = (string) file_get_contents($file);
            foreach ($patterns as $re) {
                $this->assertSame(0, preg_match($re, $body), basename($file).' matched '.$re);
            }
        }
    }

    public function test_analytics_id_comes_from_env_never_hard_coded(): void
    {
        // A real GA id contains digits; the docs placeholder "G-XXXXXXXXXX" must
        // not count as a hard-coded id.
        $realGa = '/\bG-(?=[A-Z0-9]{8,}\b)[A-Z0-9]*[0-9][A-Z0-9]*\b/';

        $cfg = $this->read('config/analytics.php');
        $this->assertStringContainsString("env('GA_MEASUREMENT_ID', '')", $cfg);
        $this->assertSame(0, preg_match($realGa, $cfg), 'literal GA id in config');

        $partial = $this->read('resources/views/central/partials/analytics.blade.php');
        $this->assertSame(0, preg_match($realGa, $partial), 'literal GA id in analytics partial');
    }

    // 4 — force HTTPS behind the proxy, never locally ---------------------

    public function test_trust_proxies_registered_and_https_forced_only_in_production(): void
    {
        $kernel = $this->read('app/Http/Kernel.php');
        $this->assertStringContainsString('\App\Http\Middleware\TrustProxies::class', $kernel);

        $mw = $this->read('app/Http/Middleware/TrustProxies.php');
        $this->assertStringContainsString('X_FORWARDED_PROTO', $mw);
        $this->assertStringContainsString("env('TRUSTED_PROXIES', '*')", $mw);

        $provider = $this->read('app/Providers/AppServiceProvider.php');
        $this->assertMatchesRegularExpression(
            "#environment\('production'\)\)\s*\{\s*\\\\Illuminate\\\\Support\\\\Facades\\\\URL::forceScheme\('https'\)#s",
            $provider
        );
    }

    // 21 — security headers -------------------------------------------------

    public function test_security_headers_middleware_is_in_the_web_group(): void
    {
        $kernel = $this->read('app/Http/Kernel.php');
        $this->assertMatchesRegularExpression("#'web' => \[.*SecurityHeaders::class.*\],#s", $kernel);

        $mw = $this->read('app/Http/Middleware/SecurityHeaders.php');
        foreach (['X-Content-Type-Options', 'Referrer-Policy', 'X-Frame-Options', 'Permissions-Policy'] as $h) {
            $this->assertStringContainsString($h, $mw, $h);
        }
        // HSTS must be gated on real https + production.
        $this->assertMatchesRegularExpression("#isSecure\(\)\s*&&\s*app\(\)->environment\('production'\)#", $mw);
        // No blindly-enforced CSP.
        $this->assertStringNotContainsString("\$headers->set('Content-Security-Policy',", $mw);
        $this->assertStringContainsString('Content-Security-Policy-Report-Only', $mw);
    }

    // 5 — cookie consent gates analytics ---------------------------------

    public function test_consent_script_never_loads_ga_before_analytics_consent(): void
    {
        $js = $this->read('public/assets_super/js/prodex-consent.js');
        $this->assertStringContainsString('googletagmanager.com/gtag/js', $js);
        // The only place the GA <script> is appended is inside loadGa(), which is
        // only reached from applyAnalytics(granted) / a granted stored decision.
        $this->assertMatchesRegularExpression('/function loadGa\(\)\s*\{[^}]*if \(gaLoaded \|\| !GA_ID\) return;/s', $js);
        $this->assertStringContainsString('function applyAnalytics(granted)', $js);
        $this->assertStringContainsString('if (granted) {', $js);
        // track() is a no-op until consent + gtag exist.
        $this->assertMatchesRegularExpression('/track: function[^}]*!API\.has\("analytics"\)[^}]*return;/s', $js);

        // landing-prime no longer owns a second, ungated consent store.
        $lp = $this->read('public/assets_super/js/landing-prime.js');
        $this->assertStringNotContainsString('localStorage.setItem(KEY, JSON.stringify(Object.assign', $lp);
    }

    public function test_cookie_banner_is_reachable_by_keyboard_and_screen_readers(): void
    {
        $view = $this->read('resources/views/central/landing-prime.blade.php');
        $this->assertMatchesRegularExpression('/id="lpCookie"[^>]*role="dialog"/s', $view);
        $this->assertStringContainsString('aria-labelledby="lpCookieTitle"', $view);
        $this->assertStringContainsString('id="lpCookiePrefs"', $view); // footer re-open trigger

        $js = $this->read('public/assets_super/js/prodex-consent.js');
        $this->assertStringContainsString('openPreferences', $js);
        $this->assertStringContainsString('"Escape"', $js);
        $this->assertStringContainsString('e.key === "Tab"', $js);
    }

    // 6 / 7 — SEO meta on every indexable public page ---------------------

    public function test_seo_head_partial_emits_the_full_meta_set(): void
    {
        $p = $this->read('resources/views/central/partials/seo-head.blade.php');
        foreach ([
            '<link rel="canonical"', 'name="robots"', 'name="description"',
            'property="og:title"', 'property="og:description"', 'property="og:url"',
            'property="og:type"', 'property="og:image"', 'property="og:image:width"',
            'name="twitter:card"', 'name="twitter:title"', 'name="twitter:image"',
            'rel="apple-touch-icon"', 'application/ld+json',
        ] as $needle) {
            $this->assertStringContainsString($needle, $p, $needle);
        }
        // Canonical is per-page, not a hard-coded root.
        $this->assertStringContainsString('$seoCanonicalUrl', $p);
        $this->assertStringNotContainsString("\$seoCanonical = 'https://prodexhub.cloud/';", $p);
    }

    public function test_static_seo_pages_have_canonical_og_image_and_favicons(): void
    {
        $pages = [
            'sistema-pos-honduras', 'sistema-facturacion-honduras', 'software-inventario-honduras',
            'erp-honduras', 'software-pymes-honduras', 'software-recursos-humanos-honduras',
        ];
        foreach ($pages as $slug) {
            $html = $this->read("public/{$slug}/index.html");
            $this->assertStringContainsString('rel="canonical"', $html, $slug);
            $this->assertStringContainsString('prodex-og.png', $html, $slug);
            $this->assertStringContainsString('twitter:card" content="summary_large_image"', $html, $slug);
            $this->assertStringContainsString('apple-touch-icon', $html, $slug);
            $this->assertStringContainsString('prodex-consent.js', $html, $slug);
            $this->assertStringContainsString('name="robots" content="index', $html, $slug);
            $this->assertStringNotContainsString('© 2026 PRODEX', $html, $slug);
        }
    }

    // 7 — OG fallback image + favicons exist -----------------------------

    public function test_brand_social_image_and_favicons_exist_with_right_dimensions(): void
    {
        $og = $this->root().'/public/images/social/prodex-og.png';
        $this->assertFileExists($og);
        [$w, $h] = getimagesize($og);
        $this->assertSame(1200, $w);
        $this->assertSame(630, $h);

        foreach (['favicon-16x16.png' => 16, 'favicon-32x32.png' => 32, 'apple-touch-icon.png' => 180] as $file => $size) {
            $path = $this->root()."/public/images/social/{$file}";
            $this->assertFileExists($path);
            [$fw] = getimagesize($path);
            $this->assertSame($size, $fw, $file);
        }
        $this->assertGreaterThan(0, filesize($this->root().'/public/favicon.ico'), 'root favicon.ico must not be empty');
    }

    // 9 — robots + sitemap only expose public, indexable URLs -------------

    public function test_robots_points_at_sitemap_and_blocks_private_areas(): void
    {
        $robots = $this->read('public/robots.txt');
        $this->assertStringContainsString('Sitemap: https://prodexhub.cloud/sitemap.xml', $robots);
        foreach (['/super/', '/register', '/checkout/', '/workspace/', '/api/', '/setup/'] as $block) {
            $this->assertStringContainsString("Disallow: {$block}", $robots, $block);
        }
        // robots.txt is not a security control — no secret paths listed.
        $this->assertStringNotContainsString('token', $robots);
    }

    public function test_sitemap_is_valid_https_public_only_and_has_no_private_route(): void
    {
        $xml = $this->read('public/sitemap.xml');
        $doc = simplexml_load_string($xml);
        $this->assertNotFalse($doc, 'sitemap.xml must be valid XML');

        $locs = [];
        foreach ($doc->url as $u) {
            $locs[] = (string) $u->loc;
        }
        $this->assertNotEmpty($locs);

        $allowed = [
            'https://prodexhub.cloud/',
            'https://prodexhub.cloud/sistema-pos-honduras/',
            'https://prodexhub.cloud/sistema-facturacion-honduras/',
            'https://prodexhub.cloud/software-inventario-honduras/',
            'https://prodexhub.cloud/erp-honduras/',
            'https://prodexhub.cloud/software-pymes-honduras/',
            'https://prodexhub.cloud/software-recursos-humanos-honduras/',
            'https://prodexhub.cloud/privacy-policy',
            'https://prodexhub.cloud/terms-conditions',
        ];
        $forbidden = ['/super', '/register', '/login', '/checkout', '/workspace', '/app', '/api', '/setup', '/update', '/portal'];

        foreach ($locs as $loc) {
            $this->assertStringStartsWith('https://', $loc, $loc);
            $this->assertContains($loc, $allowed, "sitemap exposes an unexpected URL: {$loc}");
            foreach ($forbidden as $bad) {
                $this->assertStringNotContainsString($bad, $loc, "sitemap must never contain {$bad}");
            }
            $this->assertStringContainsString('<lastmod>', $xml);
        }
    }

    // 15 — custom, real, noindex 404 (+ friends) -------------------------

    public function test_branded_error_views_exist_and_are_noindex(): void
    {
        $layout = $this->read('resources/views/errors/minimal.blade.php');
        $this->assertStringContainsString('name="robots" content="noindex, nofollow"', $layout);
        $this->assertStringContainsString("route('central.welcome')", $layout);

        foreach (['403', '404', '419', '429', '500', '503'] as $code) {
            $v = $this->read("resources/views/errors/{$code}.blade.php");
            $this->assertStringContainsString("@extends('errors.minimal')", $v, $code);
            $this->assertStringContainsString("__('errors.{$code}_title')", $v, $code);
        }
        // Handler renders the branded 404 for the central host, and still speaks
        // JSON to API clients (no HTML error bodies for JSON requests).
        $handler = $this->read('app/Exceptions/Handler.php');
        $this->assertStringContainsString("response()->view('errors.404', [], 404)", $handler);
        $this->assertStringContainsString("expectsJson()", $handler);
        $this->assertStringNotContainsString("redirect('/')", $handler); // no soft-404
    }

    // 17 — backend is the validation authority for public forms ---------

    public function test_register_form_has_server_side_validation_and_a_honeypot(): void
    {
        $controller = $this->read('app/Http/Controllers/Central/TenantRegistrationController.php');
        $this->assertStringContainsString('$request->validate([', $controller);
        $this->assertStringContainsString("'admin_password' => ['required', 'string', 'min:8', 'confirmed']", $controller);
        $this->assertStringContainsString('company_website', $controller);   // honeypot field
        $this->assertStringContainsString('form_loaded_at', $controller);    // timing trap

        $view = $this->read('resources/views/central/register.blade.php');
        $this->assertStringContainsString('@csrf', $view);
        $this->assertStringContainsString('name="company_website"', $view);
        $this->assertStringContainsString('aria-hidden="true"', $view);
    }

    // 18 — abuse protection on the public write endpoints ---------------

    public function test_public_auth_and_write_endpoints_are_throttled(): void
    {
        $routes = $this->read('routes/central.php');
        $this->assertMatchesRegularExpression("#post\('/super/login'.*throttle:central-auth#s", $routes);
        $this->assertMatchesRegularExpression("#post\('/super/forgot-password'.*throttle:central-auth#s", $routes);
        $this->assertMatchesRegularExpression("#post\('/super/reset-password'.*throttle:central-auth#s", $routes);
        $this->assertMatchesRegularExpression("#post\('/register'.*throttle:central-register#s", $routes);
        $this->assertMatchesRegularExpression("#post\('/checkout/\{token\}'.*throttle:central-checkout#s", $routes);

        $provider = $this->read('app/Providers/AppServiceProvider.php');
        $this->assertStringContainsString("RateLimiter::for('central-auth'", $provider);
        $this->assertStringContainsString("RateLimiter::for('central-register'", $provider);
        // Keyed on ip + identifier, not a blanket per-IP ban.
        $this->assertStringContainsString("sha1(\$request->ip().'|'.\$id)", $provider);
    }

    public function test_forgot_password_does_not_reveal_whether_an_account_exists(): void
    {
        $controller = $this->read('app/Http/Controllers/Central/CentralForgotPasswordController.php');
        // The "no account" branch returns the SAME neutral status, never a
        // distinguishing error.
        $this->assertStringContainsString("If an account exists for that email", $controller);
        $this->assertStringNotContainsString("'No account found with that email address.'\n            ])->withInput()", $controller);
        $this->assertMatchesRegularExpression(
            "#if \(! \\\$user\) \{\s*return back\(\)->with\('status', \\\$genericStatus\);#s",
            $controller
        );
    }

    // 19 — analytics is consent-gated and PII-free ----------------------

    public function test_analytics_partial_is_consent_gated_and_env_scoped(): void
    {
        $partial = $this->read('resources/views/central/partials/analytics.blade.php');
        $this->assertStringContainsString("config('analytics.ga_measurement_id'", $partial);
        $this->assertStringContainsString("config('analytics.enabled_environments'", $partial);
        $this->assertStringContainsString('prodex-consent.js', $partial);
        $this->assertStringContainsString('data-ga-id=', $partial);

        $js = $this->read('public/assets_super/js/prodex-consent.js');
        // event names present, but never any PII field.
        $this->assertStringContainsString('registration_started', $this->read('public/assets_super/js/landing-prime.js'));
        foreach (['admin_email', 'owner_phone', 'company_name', 'tenant_name'] as $pii) {
            $this->assertStringNotContainsString($pii, $js, $pii);
        }
    }

    // 20 — a single, canonical primary CTA -----------------------------

    public function test_primary_cta_points_at_the_real_registration_route(): void
    {
        $view = $this->read('resources/views/central/landing-prime.blade.php');
        // navbar primary buttons resolve to the register route.
        $this->assertStringContainsString('$lpRegisterUrl = route(\'central.register\')', $view);
        $this->assertMatchesRegularExpression('/href="\{\{ \$lpRegisterUrl \}\}" class="lp-btn lp-btn--primary/', $view);
        // the hero primary CTA can never silently become a login link.
        $this->assertMatchesRegularExpression(
            "#\\\$lpHeroPrimaryUrl = .*rtrim\(\\\$lpLoginUrl, '/'\).*\\\$hero->primary_button_url\s*:\s*\\\$lpRegisterUrl#s",
            $view
        );
        $this->assertStringContainsString('href="{{ $lpHeroPrimaryUrl }}"', $view);

        // the featured plan card promotes to a primary CTA; the rest stay ghost.
        $plans = $this->read('resources/views/central/partials/prime/plans.blade.php');
        $this->assertStringContainsString("\$isRecommended ? 'lp-btn--primary' : 'lp-btn--ghost'", $plans);
    }

    // 10 — alt text on public imagery --------------------------------

    public function test_landing_images_have_meaningful_or_explicitly_empty_alt(): void
    {
        $view = $this->read('resources/views/central/landing-prime.blade.php');
        // Neutralise Blade echoes (they contain "->" / ">") before matching tags.
        $flat = preg_replace('/\{\{.*?\}\}|\{!!.*?!!\}/s', 'X', $view);
        preg_match_all('/<img\b[^>]*>/s', $flat, $m);
        $this->assertNotEmpty($m[0]);
        foreach ($m[0] as $img) {
            $this->assertMatchesRegularExpression('/\balt=/', $img, "img without alt: {$img}");
        }
        // logo alt is empty when the wordmark text is shown (decorative), the
        // brand name otherwise; decorative flags are alt="".
        $this->assertStringContainsString('alt="{{ $lpShowSiteName ? \'\' : $appName }}"', $view);
        $this->assertMatchesRegularExpression('/<a href="\{\{ route\(\'central\.welcome\'\) \}\}"[^>]*aria-label="\{\{ \$appName \}\}"/', $view);
        $this->assertStringContainsString("asset('flags/' . \$lang->flag) }}\" alt=\"\"", $view);
    }
}
