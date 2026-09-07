<?php

namespace Tests\Unit;

use PHPUnit\Framework\TestCase;

/**
 * "Reportes del módulo" (panel.reportsInline de Ventas / Inventario / Compras)
 * debe ser navegación REAL, nunca ítems estáticos con badge "pendiente".
 *
 * Causa del bug original: `reportsInline` era `["Ventas por sucursal", …]`
 * (strings sueltos) y `PxShell.vue` los pintaba como `<li class="panel-static">`
 * con un `<span class="panel-cond">pendiente</span>` fijo — 13 accesos que
 * parecían un roadmap y no navegaban a ninguna parte.
 *
 * Contrato que fija este test:
 *  1. `PxShell.vue` no renderiza la palabra "pendiente" ni la clase is-pending.
 *  2. Cada entrada de `reportsInline` es una referencia (`route` string u
 *     objeto `{ route, label }`) a una ruta catalogada en `SHELL_REPORTS`.
 *  3. Todas esas rutas están registradas en `router.js` como children reales
 *     de `/app/reports` (fuera del bloque dev-only NODE_ENV).
 *  4. No se duplica metadata: `reportsInline` no define label/anyPerm/plan
 *     propios salvo el override opcional de `label`.
 *  5. Los 13 accesos (4 Ventas + 5 Inventario + 4 Compras) están presentes.
 *  6. PxShell filtra los inline con el mismo gate de permiso + plan del panel.
 */
class ShellReportsInlineArchitectureTest extends TestCase
{
    private function repo(string $rel): string
    {
        return file_get_contents(dirname(__DIR__, 2).'/'.$rel);
    }

    private function navSrc(): string
    {
        return $this->repo('resources/src/views/app/_ui/data/shell-nav.js');
    }

    private function shellSrc(): string
    {
        return $this->repo('resources/src/components/px-next/PxShell.vue');
    }

    /** Rutas catalogadas en SHELL_REPORTS (route: "…"). */
    private function catalogRoutes(): array
    {
        preg_match_all('/route:\s*"(\/app\/reports\/[a-z0-9_\-]+)"/i', $this->navSrc(), $m);

        return array_values(array_unique($m[1]));
    }

    /** Todas las referencias `reportsInline` como lista de rutas. */
    private function inlineRoutes(): array
    {
        $nav = $this->navSrc();
        preg_match_all('/reportsInline:\s*\[(.*?)\]/s', $nav, $blocks);
        $this->assertNotEmpty($blocks[1], 'shell-nav.js debe declarar reportsInline en los dominios de panel');

        $routes = [];
        foreach ($blocks[1] as $block) {
            preg_match_all('/"(\/app\/reports\/[a-z0-9_\-]+)"/i', $block, $m);
            foreach ($m[1] as $r) {
                $routes[] = $r;
            }
        }

        return $routes;
    }

    public function test_pxshell_no_longer_renders_a_pending_badge_or_static_report_item(): void
    {
        $shell = $this->shellSrc();

        $this->assertStringNotContainsString('pendiente', $shell, 'PxShell.vue no debe contener la palabra "pendiente"');
        $this->assertStringNotContainsString('is-pending', $shell, 'PxShell.vue no debe conservar la clase is-pending');
        $this->assertStringNotContainsString('pxn-shell__panel-static', $shell, 'PxShell.vue no debe pintar ítems de reporte estáticos');
        $this->assertStringNotContainsString('pxn-shell__panel-cond', $shell);
    }

    public function test_pxshell_renders_inline_reports_as_real_router_links_gated_by_permission(): void
    {
        $shell = $this->shellSrc();

        // Materializa las referencias vía el resolver canónico de shell-nav.
        $this->assertStringContainsString('resolveInlineReports', $shell);
        $this->assertMatchesRegularExpression(
            '/inlineReports\(\)\s*\{[\s\S]*?resolveInlineReports\([\s\S]*?planFeature\(it\.plan\)[\s\S]*?hasAnyPerm\(it\.anyPerm\)/',
            $shell,
            'inlineReports debe filtrar por plan + permiso igual que el resto del panel'
        );
        $this->assertMatchesRegularExpression(
            '/v-for="\(r, i\) in inlineReports"[\s\S]*?<router-link[\s\S]*?:to="r\.route"/',
            $shell,
            'cada reporte inline debe ser un <router-link> a su ruta real'
        );
    }

    public function test_shell_nav_exposes_the_canonical_inline_resolver(): void
    {
        $nav = $this->navSrc();
        $this->assertStringContainsString('export function reportByRoute', $nav);
        $this->assertStringContainsString('export function resolveInlineReports', $nav);
        // El resolver toma label/icon/anyPerm/plan del catálogo, no de la referencia.
        $this->assertMatchesRegularExpression(
            '/resolveInlineReports\([\s\S]*?_reportByRoute\[route\][\s\S]*?icon:\s*base\.icon[\s\S]*?anyPerm:\s*base\.anyPerm[\s\S]*?plan:\s*base\.plan/',
            $nav,
            'resolveInlineReports debe reutilizar la metadata canónica de SHELL_REPORTS'
        );
    }

    public function test_every_inline_reference_points_to_a_catalogued_report(): void
    {
        $catalog = $this->catalogRoutes();
        foreach ($this->inlineRoutes() as $route) {
            $this->assertContains(
                $route,
                $catalog,
                "reportsInline referencia {$route} pero esa ruta no está en SHELL_REPORTS (fuente única)"
            );
        }
    }

    public function test_every_inline_reference_has_a_real_router_route(): void
    {
        $router = $this->repo('resources/src/router.js');
        $devOnlyAt = strpos($router, 'process.env.NODE_ENV !== "production"');
        $this->assertNotFalse($devOnlyAt);

        foreach (array_unique($this->inlineRoutes()) as $route) {
            $path = substr($route, strlen('/app/reports/'));
            $pos = strpos($router, 'path: "'.$path.'"');
            $this->assertNotFalse($pos, "router.js debe registrar el child de reportes '{$path}'");
            $this->assertLessThan($devOnlyAt, $pos, "la ruta '{$path}' debe ser REAL (no dev-only)");
        }
    }

    public function test_the_thirteen_module_report_shortcuts_are_present(): void
    {
        $nav = $this->navSrc();

        $expected = [
            // Ventas
            '/app/reports/warehouse_report', '/app/reports/top_customers',
            '/app/reports/discount_summary_report', '/app/reports/return_ratio_report',
            // Inventario
            '/app/reports/valued_kardex', '/app/reports/inventory_turnover',
            '/app/reports/stock_aging_report', '/app/reports/negative_stock_report',
            '/app/reports/expiry_report',
            // Compras
            '/app/reports/providers_report', '/app/reports/top_suppliers_report',
            '/app/reports/payments_purchase', '/app/reports/quantity_alerts',
        ];

        $inline = $this->inlineRoutes();
        $this->assertCount(13, $inline, 'deben existir exactamente 13 accesos de "Reportes del módulo"');
        foreach ($expected as $route) {
            $this->assertContains($route, $inline, "falta el acceso inline a {$route}");
        }
    }

    public function test_new_inventory_reports_are_catalogued_with_their_own_permissions(): void
    {
        $nav = $this->navSrc();

        $this->assertMatchesRegularExpression(
            '/label:\s*"Kardex valorizado",\s*icon:\s*"[a-z\-]+",\s*route:\s*"\/app\/reports\/valued_kardex",\s*anyPerm:\s*\["valued_kardex_report"\]/',
            $nav
        );
        $this->assertMatchesRegularExpression(
            '/label:\s*"Rotación de inventario",\s*icon:\s*"[a-z\-]+",\s*route:\s*"\/app\/reports\/inventory_turnover",\s*anyPerm:\s*\["inventory_turnover_report"\]/',
            $nav
        );
    }

    public function test_inline_label_override_is_the_only_permitted_local_metadata(): void
    {
        $nav = $this->navSrc();
        preg_match_all('/reportsInline:\s*\[(.*?)\]/s', $nav, $blocks);

        foreach ($blocks[1] as $block) {
            // Objetos permitidos: sólo { route, label }. Nada de anyPerm/plan/icon locales.
            $this->assertDoesNotMatchRegularExpression('/reportsInline[\s\S]*?anyPerm/', 'reportsInline:['.$block.']');
            $this->assertStringNotContainsString('anyPerm', $block, 'reportsInline no debe redefinir permisos');
            $this->assertStringNotContainsString('plan:', $block, 'reportsInline no debe redefinir plan');
            $this->assertStringNotContainsString('icon:', $block, 'reportsInline no debe redefinir icon');
        }
    }
}
