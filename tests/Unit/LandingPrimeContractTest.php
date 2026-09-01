<?php

namespace Tests\Unit;

use PHPUnit\Framework\TestCase;

/**
 * Contrato de landing-prime (rediseño de la web pública).
 *
 * Verifica por contenido de archivo (sin BD) que:
 *  - la plantilla queda registrada como preview, SIN cambiar el default de prod;
 *  - la calculadora usa el endpoint / servicio único y trata clientes y
 *    proveedores como límites independientes;
 *  - se preserva el SEO existente (head, seo-head/JSON-LD, páginas estáticas);
 *  - no hay cifras comerciales inventadas en los mockups;
 *  - i18n ES/EN en paridad para las claves nuevas.
 */
class LandingPrimeContractTest extends TestCase
{
    private function read(string $rel): string
    {
        $path = dirname(__DIR__, 2) . '/' . $rel;
        $this->assertFileExists($path, $rel);

        return file_get_contents($path);
    }

    // ── Registro de la plantilla (preview, no default) ────────────────────

    public function test_template_is_registered_but_not_the_default(): void
    {
        $tpl = $this->read('app/Support/LandingPageTemplate.php');
        $this->assertStringContainsString("'landing-prime' => [", $tpl);
        $this->assertStringContainsString("'view'            => 'central.landing-prime'", $tpl);
        $this->assertStringContainsString("'super.landing_templates.prime_label'", $tpl);
        // El default en código NO cambia: sigue siendo landing-two.
        $this->assertStringContainsString("public const DEFAULT = 'landing-two';", $tpl);

        // Ninguna migración fuerza landing-prime como plantilla activa.
        $migrations = glob(dirname(__DIR__, 2) . '/database/migrations/*.php') ?: [];
        foreach ($migrations as $m) {
            $this->assertStringNotContainsString("'landing-prime'", file_get_contents($m), basename($m));
        }
    }

    public function test_super_translations_have_prime_label_and_desc_in_es_and_en(): void
    {
        foreach (['es', 'en'] as $loc) {
            $src = $this->read("resources/lang/$loc/super.php");
            $this->assertStringContainsString("'prime_label'", $src, $loc);
            $this->assertStringContainsString("'prime_desc'", $src, $loc);
        }
    }

    // ── Rutas / controlador de la calculadora ────────────────────────────

    public function test_recommend_route_registered_public_readonly(): void
    {
        $routes = $this->read('routes/central.php');
        $this->assertStringContainsString("Route::get('/pricing/recommend'", $routes);
        $this->assertStringContainsString('PricingCalculatorController::class', $routes);
        $this->assertStringContainsString("->name('central.pricing.recommend')", $routes);
        $this->assertStringContainsString("throttle:60,1", $routes);
    }

    public function test_calculator_endpoint_only_reads_plans(): void
    {
        $ctrl = $this->read('app/Http/Controllers/Central/PricingCalculatorController.php');
        $this->assertStringContainsString('PlanRecommendationService', $ctrl);
        // No escribe nada: sin save/update/create/DB::table(...)->insert.
        $this->assertStringNotContainsString('->save(', $ctrl);
        $this->assertStringNotContainsString('->update(', $ctrl);
        $this->assertStringNotContainsString('->insert(', $ctrl);
        $this->assertStringNotContainsString('TenantSubscription', $ctrl);
        // Topes defensivos de entrada.
        $this->assertStringContainsString("'max_customers'  => 100000", $ctrl);
        $this->assertStringContainsString("'max_suppliers'  => 100000", $ctrl);
    }

    // ── Servicio: fuente única + correcciones del brief ──────────────────

    public function test_service_is_single_source_and_keeps_customers_suppliers_separate(): void
    {
        $svc = $this->read('app/Services/PlanRecommendationService.php');

        // Las 5 dimensiones existen como límites independientes.
        foreach (['max_users', 'max_warehouses', 'max_customers', 'max_suppliers', 'max_products'] as $dim) {
            $this->assertStringContainsString("'$dim'", $svc);
        }
        // Clientes + proveedores NUNCA se suman.
        $this->assertStringNotContainsString('max_customers + max_suppliers', $svc);
        $this->assertStringNotContainsString("max_customers'] + ", $svc);

        // Estados de recomendación (fail-safe + custom).
        $this->assertStringContainsString("STATUS_OK       = 'ok'", $svc);
        $this->assertStringContainsString("STATUS_CUSTOM   = 'custom'", $svc);
        $this->assertStringContainsString("STATUS_NO_DATA  = 'insufficient_plan_data'", $svc);

        // Fail-safe: sin datos de límites => no se fabrica recomendación.
        $this->assertStringContainsString('catalogHasLimitData', $svc);

        // Contrato de límites documentado (ausente / -1 => sin tope) citando el runtime.
        $this->assertStringContainsString('TenantLimitsService::hasReachedLimit', $svc);

        // Protección de yearly_price inconsistente.
        $this->assertStringContainsString('public function yearlyInfo(', $svc);
        $this->assertStringContainsString('$yearly <= $monthly || $yearly >= $full', $svc);
    }

    public function test_service_does_not_touch_billing(): void
    {
        $svc = $this->read('app/Services/PlanRecommendationService.php');
        $this->assertStringNotContainsString('TenantSubscription', $svc);
        $this->assertStringNotContainsString('->save(', $svc);
        $this->assertStringNotContainsString('addon', $svc);
        $this->assertStringNotContainsString('booster', $svc);
    }

    public function test_controller_injects_calculator_without_breaking_payload(): void
    {
        $ctrl = $this->read('app/Http/Controllers/Central/LandingPageController.php');
        $this->assertStringContainsString('PlanRecommendationService', $ctrl);
        $this->assertStringContainsString("'pricingCalculator'", $ctrl);
        $this->assertStringContainsString('calculatorDefaults()', $ctrl);
        // El resto del contrato de datos del CMS se conserva.
        foreach (['hero', 'features', 'pricing', 'howItWorks', 'testimonials', 'faqs', 'stats', 'cta', 'footer', 'seo', 'plans'] as $key) {
            $this->assertStringContainsString("'$key'", $ctrl);
        }
    }

    // ── Plantilla Blade ─────────────────────────────────────────────────

    public function test_blade_has_all_sections_and_includes_partials(): void
    {
        $b = $this->read('resources/views/central/landing-prime.blade.php');

        $this->assertStringContainsString("@include('central.partials.prime.calculator')", $b);
        $this->assertStringContainsString("@include('central.partials.prime.plans')", $b);
        $this->assertStringContainsString("@include('central.partials.prime.showcase')", $b);

        foreach ([
            'id="lpNav"', 'id="lpDrawer"',              // navbar + móvil
            'id="lp-main"',
            'id="solutions"',                           // módulos
            'id="how-it-works"',
            'multibranch_title',                        // multisucursal
            'reports_title',                            // reportes
            'id="contact-sales"',                       // footer
        ] as $needle) {
            $this->assertStringContainsString($needle, $b, $needle);
        }

        // Anclas que viven dentro de los partials incluidos.
        $this->assertStringContainsString('id="pricing"', $this->read('resources/views/central/partials/prime/calculator.blade.php'));
        $this->assertStringContainsString('id="product"', $this->read('resources/views/central/partials/prime/showcase.blade.php'));

        // Testimonios y FAQ SOLO si existen datos reales del CMS.
        $this->assertStringContainsString('$lpTestimonials->isNotEmpty()', $b);
        $this->assertStringContainsString('$lpFaqs->isNotEmpty()', $b);
    }

    public function test_blade_preserves_existing_seo_head(): void
    {
        $b = $this->read('resources/views/central/landing-prime.blade.php');

        // Mismo head de SEO que landing-three: title/description/keywords/OG/favicon del CMS.
        $this->assertStringContainsString('$seo->meta_title ?? $appName', $b);
        $this->assertStringContainsString('name="description" content="{{ $seo->meta_description', $b);
        $this->assertStringContainsString('property="og:title"', $b);
        $this->assertStringContainsString('property="og:description"', $b);
        // seo-head (canonical + geo + JSON-LD Organization/WebSite/SoftwareApplication)
        // se incluye vía el partial landing-font, igual que el resto de plantillas.
        $this->assertStringContainsString("@include('central.partials.landing-font')", $b);

        // Enriquecimiento acotado: FAQPage SOLO si las FAQ del CMS se renderizan.
        $this->assertMatchesRegularExpression('/@if\(\$lpFaqs->isNotEmpty\(\)\)\s*<script type="application\/ld\+json">\s*\{!!\s*json_encode\(\[\s*\'@context\'/s', $b);
        $this->assertStringContainsString("'@type' => 'FAQPage'", $b);
        // Sin Product/Offer en este PR (ambigüedad de datos + bug yearly_price).
        $this->assertStringNotContainsString("'@type' => 'Product'", $b);
        $this->assertStringNotContainsString("'@type' => 'Offer'", $b);
    }

    public function test_blade_does_not_invent_commercial_metrics(): void
    {
        foreach ([
            'resources/views/central/landing-prime.blade.php',
            'resources/views/central/partials/prime/calculator.blade.php',
            'resources/views/central/partials/prime/showcase.blade.php',
        ] as $file) {
            $src = $this->read($file);
            // Nada de "$24,580", "L 12,345", "+18.4%", "1,284 productos" hardcodeados.
            $this->assertDoesNotMatchRegularExpression('/[\$L]\s?\d{1,3}[.,]\d{3}\b/', $src, "$file: importe inventado");
            $this->assertDoesNotMatchRegularExpression('/[+\-]\s?\d+(?:\.\d+)?\s?%/', $src, "$file: variación % inventada");
            $this->assertDoesNotMatchRegularExpression('/\b\d{1,3}[.,]\d{3}\s+(productos|ventas|clientes|items)\b/i', $src, "$file: conteo inventado");
        }
    }

    public function test_blade_ctas_point_to_real_routes(): void
    {
        $b = $this->read('resources/views/central/landing-prime.blade.php');
        $this->assertStringContainsString("route('central.register')", $b);
        $this->assertStringContainsString("route('central.login')", $b);
        $this->assertStringContainsString("route('central.welcome')", $b);
        // "Hablar con ventas" desde el CMS (WhatsApp/email), fuente única.
        $this->assertStringContainsString('LandingContact::salesUrl($footer, $cta)', $b);
        // Enlaces a las páginas SEO comerciales estáticas (refuerzo interno, no las rompe).
        $this->assertStringContainsString("url('/sistema-pos-honduras')", $b);
        $this->assertStringContainsString("url('/erp-honduras')", $b);
    }

    public function test_blade_keeps_conversions_and_cookie_banner(): void
    {
        $b = $this->read('resources/views/central/landing-prime.blade.php');
        $this->assertStringContainsString('id="lpCookie"', $b);
        $this->assertStringContainsString("route('central.locale'", $b);           // switch de idioma
        $this->assertStringContainsString('show_sales_floating_button', $b);        // botón flotante WhatsApp
        $this->assertStringContainsString("route('central.privacy-policy')", $b);
        $this->assertStringContainsString("route('central.terms-conditions')", $b);
    }

    // ── Calculadora (partial) ───────────────────────────────────────────

    public function test_calculator_partial_wires_endpoint_and_separate_contacts(): void
    {
        $c = $this->read('resources/views/central/partials/prime/calculator.blade.php');

        $this->assertStringContainsString('id="lpCalc"', $c);
        $this->assertStringContainsString("data-endpoint=\"{{ route('central.pricing.recommend') }}\"", $c);

        // Controles INDEPENDIENTES para clientes y proveedores (nunca un solo slider de "contactos").
        $this->assertStringContainsString("'dim' => 'max_customers'", $c);
        $this->assertStringContainsString("'dim' => 'max_suppliers'", $c);
        $this->assertStringContainsString('calc_contacts_group', $c);   // agrupación SOLO visual
        $this->assertStringNotContainsString('max_contacts', $c);

        // Los 3 estados del resumen.
        $this->assertStringContainsString('lp-calc__state--ok', $c);
        $this->assertStringContainsString('lp-calc__state--custom', $c);
        $this->assertStringContainsString('lp-calc__state--nodata', $c);
        // La comparación de planes YA NO vive dentro de la calculadora: sección propia.
        $this->assertStringNotContainsString('lp-calc__plans', $c);
        $this->assertStringContainsString('href="#plans"', $c);

        // Render server-side inicial desde $pricingCalculator (no depende de JS).
        $this->assertStringContainsString('$pricingCalculator', $c);
        $this->assertStringContainsString("data-status=\"{{ \$status }}\"", $c);
    }

    public function test_calculator_js_does_not_reimplement_recommendation_logic(): void
    {
        $js = $this->read('public/assets_super/js/landing-prime.js');
        // El JS consulta el endpoint; no decide el plan por su cuenta.
        $this->assertStringContainsString('/pricing/recommend', $js);
        $this->assertStringNotContainsString('max_customers + max_suppliers', $js);
        $this->assertStringNotContainsString('planSatisfies', $js);
        // Respeta prefers-reduced-motion (en CSS) y no mete librerías.
        $css = $this->read('public/assets_super/css/landing-prime.css');
        $this->assertStringContainsString('prefers-reduced-motion', $css);
    }

    // ── i18n ES/EN en paridad ───────────────────────────────────────────

    public function test_landing_prime_translations_parity_es_en(): void
    {
        $es = require dirname(__DIR__, 2) . '/resources/lang/es/landing_prime.php';
        $en = require dirname(__DIR__, 2) . '/resources/lang/en/landing_prime.php';

        $this->assertIsArray($es);
        $this->assertIsArray($en);
        $this->assertNotEmpty($es);
        $this->assertSame(array_keys($es), array_keys($en), 'las claves de landing_prime deben coincidir ES/EN');

        // Claves imprescindibles para la calculadora.
        foreach (['calc_recommended', 'calc_custom_title', 'calc_nodata_title', 'calc_customers', 'calc_suppliers', 'calc_unlimited'] as $k) {
            $this->assertArrayHasKey($k, $es, $k);
        }
    }

    public function test_register_form_reads_billing_cycle_query(): void
    {
        $reg = $this->read('resources/views/central/register.blade.php');
        $this->assertStringContainsString("request('billing_cycle')", $reg);
        // El POST /register no cambia (mismo contrato de plan_id + billing_cycle).
        $this->assertStringContainsString('name="billing_cycle"', $reg);
    }

    // ── SEO estático intacto ────────────────────────────────────────────

    public function test_static_seo_assets_are_untouched_by_this_work(): void
    {
        // Estos archivos existen y NO son tocados por landing-prime (solo se verifican).
        $robots = $this->read('public/robots.txt');
        $this->assertStringContainsString('Sitemap: https://prodexhub.cloud/sitemap.xml', $robots);

        $sitemap = $this->read('public/sitemap.xml');
        $this->assertStringContainsString('<loc>https://prodexhub.cloud/</loc>', $sitemap);
        $this->assertStringContainsString('/sistema-pos-honduras/', $sitemap);

        foreach (['sistema-pos-honduras', 'erp-honduras', 'software-inventario-honduras'] as $slug) {
            $this->assertFileExists(dirname(__DIR__, 2) . "/public/$slug/index.html", $slug);
        }

        // landing-three (plantilla de producción) sigue presente e intacta.
        $this->assertFileExists(dirname(__DIR__, 2) . '/resources/views/central/landing-three.blade.php');
    }

    // ── Pase de pulido (Taste / Impeccable / motion) ────────────────────

    public function test_polish_accessibility_and_mobile(): void
    {
        $b = $this->read('resources/views/central/landing-prime.blade.php');
        $css = $this->read('public/assets_super/css/landing-prime.css');
        $js = $this->read('public/assets_super/js/landing-prime.js');

        // Selector de idioma también DENTRO del drawer móvil (mismo POST /locale).
        $drawer = substr($b, strpos($b, 'id="lpDrawer"'), 2200);
        $this->assertStringContainsString("route('central.locale', \$lang->locale)", $drawer, 'idioma en el drawer');
        $this->assertStringContainsString('__(\'landing.language\')', $drawer);

        // Drawer accesible: role dialog + backdrop con id + manejo de foco/Escape/Tab.
        $this->assertStringContainsString('role="dialog" aria-modal="true"', $b);
        $this->assertStringContainsString('id="lpDrawerBackdrop"', $b);
        $this->assertStringContainsString('backdrop.addEventListener("click", closeDrawer)', $js);
        $this->assertStringContainsString('lastFocus', $js);              // devuelve el foco al abridor
        $this->assertStringContainsString('e.shiftKey && document.activeElement === first', $js); // trampa de foco

        // Foco visible coherente en todo lo interactivo.
        $this->assertStringContainsString(':focus-visible', $css);
        $this->assertStringContainsString('.lp-btn:focus-visible', $css);
        $this->assertStringContainsString('--lp-ring', $css);

        // aria-live para anunciar el cambio de recomendación.
        $cCalc = $this->read('resources/views/central/partials/prime/calculator.blade.php');
        $this->assertStringContainsString('aria-live="polite" data-calc-live', $cCalc);
        $this->assertStringContainsString('data-i18n-live-ok', $cCalc);
    }

    public function test_polish_motion_is_functional_only(): void
    {
        $css = $this->read('public/assets_super/css/landing-prime.css');
        $js = $this->read('public/assets_super/js/landing-prime.js');
        $b = $this->read('resources/views/central/landing-prime.blade.php');

        // Sin animaciones infinitas / constantes (se retiró lp-float).
        $this->assertStringNotContainsString('@keyframes lp-float', $css);
        $this->assertStringNotContainsString('lp-float', $b);
        $this->assertStringNotContainsString('infinite', $css);

        // Aparición: rápida, ease-out, desde un estado ya visible (no oculta sin JS).
        $this->assertStringContainsString('--lp-ease-out', $css);
        $this->assertStringContainsString('.lp-reveal { opacity: 1; transform: none; }', $css);
        $this->assertStringContainsString('.lp-js .lp-reveal {', $css);
        $this->assertStringContainsString("classList.add('lp-js')", $b);

        // reduced-motion respetado, incluido scroll-behavior.
        $this->assertStringContainsString('html.scroll-smooth { scroll-behavior: auto; }', $css);

        // El cambio de estado de la calculadora NO produce salto de layout:
        // los tres estados se apilan en la misma celda de grid.
        $this->assertStringContainsString('.lp-calc.lp-js-calc .lp-calc__card { display: grid; }', $css);
        $this->assertStringContainsString('grid-area: 1 / 1;', $css);
        $this->assertStringContainsString('data-swapping', $js);
        $this->assertStringContainsString('lp-calc__figure', $css);

        // Anclas: scroll-margin para no quedar bajo el navbar fijo.
        $this->assertStringContainsString('scroll-margin-top', $css);
    }

    public function test_polish_taste_removed_kicker_spam_and_card_wall(): void
    {
        foreach ([
            'resources/views/central/landing-prime.blade.php',
            'resources/views/central/partials/prime/calculator.blade.php',
            'resources/views/central/partials/prime/showcase.blade.php',
        ] as $f) {
            $src = $this->read($f);
            // Ya no hay eyebrow/kicker uppercase encima de cada H2 de sección.
            $this->assertDoesNotMatchRegularExpression(
                "/uppercase tracking-\[0\.12em\] text-indigo-600[^>]*>\s*\{\{ __\('landing_prime\.[a-z_]*eyebrow'\)/",
                $src,
                "$f: kicker de sección"
            );
        }
        // El fallback de módulos ya no es un muro de tarjetas idénticas.
        $b = $this->read('resources/views/central/landing-prime.blade.php');
        $modules = substr($b, strpos($b, 'MÓDULOS / SOLUCIONES'), 2600);
        $this->assertStringContainsString('índice de capacidades, no muro de tarjetas', $modules);
        $this->assertStringNotContainsString('lp-card lp-card--hover rounded-2xl border border-slate-200 bg-white p-6 lp-reveal', $modules);
    }

    // ── Corrección de bugs de revisión visual (iteración 2) ─────────────

    /** BUG 1: el panel derecho de la calculadora quedaba en blanco. */
    public function test_calc_state_selectors_target_same_element(): void
    {
        $css = $this->read('public/assets_super/css/landing-prime.css');
        $js = $this->read('public/assets_super/js/landing-prime.js');

        // JS añade lp-js-calc AL MISMO nodo que .lp-calc.
        $this->assertStringContainsString('root.classList.add("lp-js-calc")', $js);

        // Las reglas que MUESTRAN cada estado se compoundan en el mismo elemento.
        $this->assertStringContainsString('.lp-calc.lp-js-calc[data-status="ok"]     .lp-calc__state--ok', $css);
        $this->assertStringContainsString('.lp-calc.lp-js-calc[data-status="custom"] .lp-calc__state--custom', $css);
        $this->assertStringContainsString('.lp-calc.lp-js-calc[data-status="insufficient_plan_data"] .lp-calc__state--nodata', $css);

        // NUNCA como descendiente (bug: buscaba un .lp-calc dentro de .lp-js-calc).
        $this->assertDoesNotMatchRegularExpression('/\.lp-js-calc\s+\.lp-calc\[data-status=/', $css);
    }

    /** BUG 2/3: los planes de pago SIEMPRE visibles, sección propia, cards neutrales. */
    public function test_plans_section_is_always_visible_and_neutral(): void
    {
        $b = $this->read('resources/views/central/landing-prime.blade.php');
        $p = $this->read('resources/views/central/partials/prime/plans.blade.php');
        $css = $this->read('public/assets_super/css/landing-prime.css');

        // Sección propia, incluida tras la calculadora, independiente del status.
        $this->assertStringContainsString("@include('central.partials.prime.plans')", $b);
        $this->assertStringContainsString('id="plans"', $p);
        // Ya no se oculta la comparación cuando la recomendación es "ok".
        $this->assertDoesNotMatchRegularExpression('/data-status="ok"\]\s*\.lp-calc__plans\s*\{\s*display:\s*none/', $css);

        // Renderiza lo que venga de Plan::public() vía el servicio (sin re-consultar).
        $this->assertStringContainsString("\$c['plans']", $p);
        $this->assertStringNotContainsString('Plan::public', $p);
        $this->assertStringNotContainsString('Plan::', $p);

        // Datos REALES condicionados: anual/ahorro/prueba/límites/features sólo si existen.
        $this->assertStringContainsString("\$p['yearly_available']", $p);
        $this->assertStringContainsString("\$p['is_trial']", $p);
        $this->assertStringContainsString("\$p['included']", $p);
        $this->assertStringContainsString("\$p['features']", $p);

        // Sin "más popular" ni plan destacado arbitrario.
        $this->assertStringNotContainsString('most_popular', $p);
        $this->assertStringNotContainsString('Más popular', $p);
        $this->assertStringNotContainsString('is_featured', $p);
        $this->assertStringNotContainsString('$i === 1', $p);

        // El realce es por plan_id real (server-side + JS), discreto, sin scroll.
        $this->assertStringContainsString('data-plan-id="{{ $p[\'id\'] }}"', $p);
        $this->assertStringContainsString('$recommendedId', $p);
        $js = $this->read('public/assets_super/js/landing-prime.js');
        $this->assertStringContainsString('highlightPlan', $js);
        $this->assertStringNotContainsString('scrollIntoView', $js);

        // Toda clave landing_prime.* usada por el partial existe en ES y EN
        // (regresión: `plans_no_limits` faltaba y renderizaba el string crudo).
        $es = require dirname(__DIR__, 2) . '/resources/lang/es/landing_prime.php';
        $en = require dirname(__DIR__, 2) . '/resources/lang/en/landing_prime.php';
        preg_match_all('/landing_prime\.([a-z0-9_]+)/', $p, $m);
        $this->assertNotEmpty($m[1]);
        foreach (array_unique($m[1]) as $k) {
            $this->assertArrayHasKey($k, $es, "ES landing_prime.$k (usada en plans.blade.php)");
            $this->assertArrayHasKey($k, $en, "EN landing_prime.$k (usada en plans.blade.php)");
        }
    }

    /** BUG 7: encabezado e items de una sección nunca en idiomas distintos. */
    public function test_how_it_works_is_locale_coherent_from_translation_deck(): void
    {
        $b = $this->read('resources/views/central/landing-prime.blade.php');
        $this->assertNotFalse(strpos($b, 'CÓMO FUNCIONA'));
        $how = substr($b, strpos($b, 'CÓMO FUNCIONA'), 1800);

        // "Cómo funciona" NO lee el CMS de una sola lengua (landing_how_it_works_*):
        // título, lead y pasos salen del deck traducido landing_prime.hiw_* => la
        // sección entera se resuelve en el locale activo, sin mezclar idiomas.
        $this->assertMatchesRegularExpression("/<h2[^>]*>\{\{ __\('landing_prime\.hiw_title'\) \}\}<\/h2>/", $how);
        $this->assertMatchesRegularExpression("/<p[^>]*>\{\{ __\('landing_prime\.hiw_lead'\) \}\}<\/p>/", $how);
        $this->assertStringContainsString("__('landing_prime.hiw_step' . \$n . '_title')", $how);
        $this->assertStringNotContainsString('$howItWorks', $how);
        $this->assertStringNotContainsString('optional($howItWorks', $how);

        // El deck existe y está REALMENTE traducido (no el mismo string ES/EN).
        $es = require dirname(__DIR__, 2) . '/resources/lang/es/landing_prime.php';
        $en = require dirname(__DIR__, 2) . '/resources/lang/en/landing_prime.php';
        foreach ([
            'hiw_title', 'hiw_lead',
            'hiw_step1_title', 'hiw_step1_desc',
            'hiw_step2_title', 'hiw_step2_desc',
            'hiw_step3_title', 'hiw_step3_desc',
        ] as $k) {
            $this->assertArrayHasKey($k, $es, "ES landing_prime.$k");
            $this->assertArrayHasKey($k, $en, "EN landing_prime.$k");
            $this->assertNotSame($es[$k], $en[$k], "landing_prime.$k debe estar traducido, no compartido ES/EN");
        }

        // "Módulos": patrón atómico (todo-CMS o todo-deck).
        $this->assertStringContainsString('$lpModCms = ! empty($features[\'is_active\']) && $features[\'items\']->isNotEmpty();', $b);
        $this->assertMatchesRegularExpression('/<h2[^>]*>\{\{ \$lpModTitle \}\}<\/h2>/', $b);
        $this->assertDoesNotMatchRegularExpression('/<h2[^>]*>\{\{ optional\(\$features/', $b);
        $this->assertStringContainsString('@if($lpModCms)', $b);
    }

    // ── Dirección visual: firma PRODEX + mobile (iteración 3) ───────────

    /** La identidad "aurora" existe y se usa SÓLO en los tres picos. */
    public function test_prodex_visual_identity_layer(): void
    {
        $css = $this->read('public/assets_super/css/landing-prime.css');
        $b   = $this->read('resources/views/central/landing-prime.blade.php');
        $c   = $this->read('resources/views/central/partials/prime/calculator.blade.php');

        // Tokens y primitivos de marca.
        foreach (['--lp-aurora-1', '--lp-aurora-2', '--lp-aurora-3', '--lp-ink-950'] as $tok) {
            $this->assertStringContainsString($tok, $css, $tok);
        }
        $this->assertStringContainsString('.lp-aurora::before', $css);
        $this->assertStringContainsString('.lp-deep::before', $css);
        $this->assertStringContainsString('.lp-mark {', $css);

        // Picos: hero + calculadora llevan aurora; el cierre lleva superficie profunda.
        $this->assertMatchesRegularExpression('/<section[^>]*class="lp-soft lp-aurora[^"]*"[^>]*>\s*<div class="max-w-7xl/s', $b, 'hero con aurora');
        $this->assertStringContainsString('lp-soft lp-aurora', $c, 'calculadora con aurora');
        $this->assertStringContainsString('lp-deep max-w-5xl', $b, 'CTA final con superficie profunda');

        // Motivo gráfico, NO un eyebrow de texto: aparece en los picos, no en cada H2.
        $this->assertSame(2, substr_count($b, 'class="lp-mark'), 'lp-mark sólo en hero y CTA del blade principal');
        $this->assertStringContainsString('lp-mark lp-mark--center', $c, 'lp-mark en la calculadora');
    }

    /** La calculadora se presenta como "Tu negocio → Tu PRODEX". */
    public function test_calculator_is_framed_as_configuration(): void
    {
        $c = $this->read('resources/views/central/partials/prime/calculator.blade.php');
        $this->assertStringContainsString("__('landing_prime.calc_col_business')", $c);
        $this->assertStringContainsString("__('landing_prime.calc_col_prodex')", $c);

        // Sigue siendo un recomendador: no promete precio a medida / plan modular.
        foreach (['paga solo por', 'paga sólo por', 'construye tu plan', 'precio personalizado', 'build your plan', 'pay only for'] as $banned) {
            $this->assertStringNotContainsStringIgnoringCase($banned, $c, $banned);
        }

        $es = require dirname(__DIR__, 2) . '/resources/lang/es/landing_prime.php';
        $en = require dirname(__DIR__, 2) . '/resources/lang/en/landing_prime.php';
        foreach (['calc_col_business', 'calc_col_prodex', 'hero_mock_st_ok', 'hero_mock_st_sync', 'hero_mock_st_low'] as $k) {
            $this->assertArrayHasKey($k, $es, "ES $k");
            $this->assertArrayHasKey($k, $en, "EN $k");
        }
    }

    /** El overflow horizontal móvil se corrige en el origen (no sólo se oculta). */
    public function test_mobile_has_no_horizontal_overflow_guards(): void
    {
        $css = $this->read('public/assets_super/css/landing-prime.css');

        // Guarda global para adornos que sangran (aurora/glow) sin romper sticky.
        $this->assertStringContainsString('.landing-prime { overflow-x: clip; }', $css);

        // Corrección real del origen conocido: fila etiqueta + stepper de #pricing.
        $this->assertStringContainsString('.lp-field > .flex { flex-wrap: wrap; }', $css);
        $this->assertMatchesRegularExpression('/#lpCalc[^{}]*\{\s*min-width:\s*0/', $css);

        // Sin animaciones constantes introducidas por esta ronda.
        $this->assertStringNotContainsString('infinite', $css);
        $this->assertStringNotContainsString('@keyframes lp-float', $css);
    }
}
