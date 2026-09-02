<?php

namespace Tests\Unit;

use PHPUnit\Framework\TestCase;

/**
 * Regresión de producción: la navegación del riel px-next NUNCA debe depender de
 * las rutas prototipo `/app/shell/*`.
 *
 * Contexto del bug: `SHELL_RAIL`/`SHELL_FOOT` usaban destinos `SHELL_BASE + "/…"`
 * (= `/app/shell/panel`, `/app/shell/ventas`, …) pero esas rutas se registran en
 * `router.js` dentro de `if (process.env.NODE_ENV !== "production")`. En el bundle
 * de producción NO existen → al pulsar "Ventas" el navegador iba a
 * `/app/shell/ventas` y devolvía 404. La QA previa se hizo con build de
 * desarrollo, donde sí existían.
 *
 * Este test fija el contrato para que no vuelva a ocurrir. Lee los archivos
 * fuente como texto (mismo patrón que el resto de tests Architecture).
 */
class ShellProductionNavigationArchitectureTest extends TestCase
{
    private function repo(string $rel): string
    {
        return file_get_contents(dirname(__DIR__, 2).'/'.$rel);
    }

    /** Cuerpo `[ … ]` de `export const NAME = [` hasta el primer `];`. */
    private function sliceArray(string $src, string $name): string
    {
        $start = strpos($src, "export const {$name} = [");
        $this->assertNotFalse($start, "shell-nav.js debe exportar {$name}");
        $end = strpos($src, '];', $start);
        $this->assertNotFalse($end, "{$name} debe cerrar con '];'");

        return substr($src, $start, $end - $start);
    }

    public function test_no_clickable_rail_or_foot_target_depends_on_dev_only_shell_routes(): void
    {
        $nav = $this->repo('resources/src/views/app/_ui/data/shell-nav.js');

        foreach (['SHELL_RAIL', 'SHELL_FOOT'] as $arr) {
            $block = $this->sliceArray($nav, $arr);
            $this->assertStringNotContainsString(
                'SHELL_BASE +',
                $block,
                "{$arr}: ningún destino clickable puede construirse con SHELL_BASE (/app/shell/* es dev-only)"
            );
            $this->assertStringNotContainsString(
                '"/app/shell/',
                $block,
                "{$arr}: ningún destino clickable puede apuntar literalmente a /app/shell/*"
            );
        }

        // SHELL_BASE sólo debe existir como su propia declaración (uso dev).
        $this->assertSame(
            1,
            substr_count($nav, 'SHELL_BASE'),
            'shell-nav.js: SHELL_BASE sólo debe aparecer en su declaración, sin usos en la navegación'
        );
    }

    public function test_core_domains_point_to_real_erp_routes(): void
    {
        $nav = $this->repo('resources/src/views/app/_ui/data/shell-nav.js');
        $rail = $this->sliceArray($nav, 'SHELL_RAIL');

        $expected = [
            'panel'      => '/app/dashboard',
            'ventas'     => '/app/sales/list',
            'inventario' => '/app/products/list',
            'compras'    => '/app/purchases/list',
        ];
        foreach ($expected as $key => $route) {
            $this->assertMatchesRegularExpression(
                '/key:\s*"'.$key.'",[\s\S]{0,200}?to:\s*"'.preg_quote($route, '/').'"/',
                $rail,
                "Dominio '{$key}' debe navegar a la ruta real {$route}"
            );
        }
    }

    public function test_gated_domains_use_data_driven_first_allowed_resolver(): void
    {
        $nav = $this->repo('resources/src/views/app/_ui/data/shell-nav.js');

        // Finanzas / RR. HH. (SHELL_RAIL) y Configuración / Más (SHELL_FOOT) no
        // llevan destino fijo: `resolveEntry: true`.
        foreach (['finanzas', 'rrhh', 'config', 'mas'] as $key) {
            $this->assertMatchesRegularExpression(
                '/key:\s*"'.$key.'",[\s\S]{0,220}?resolveEntry:\s*true/',
                $nav,
                "Dominio gated '{$key}' debe declarar resolveEntry: true (sin destino fijo)"
            );
        }

        // El resolver data-driven existe y se exporta.
        $this->assertStringContainsString('export function firstAllowedRoute', $nav);
        $this->assertMatchesRegularExpression(
            '/firstAllowedRoute\([\s\S]*?if\s*\(!it\.route\)\s*continue;[\s\S]*?if\s*\(it\.plan\s*&&\s*!planFeature\(it\.plan\)\)\s*continue;[\s\S]*?if\s*\(!hasAnyPerm\(it\.anyPerm\)\)\s*continue;/',
            $nav,
            'firstAllowedRoute debe filtrar por route real + plan + permiso'
        );

        // PxShell usa el resolver para el destino del riel.
        $shell = $this->repo('resources/src/components/px-next/PxShell.vue');
        $this->assertStringContainsString('import { SHELL_RAIL, SHELL_FOOT, resolveShellDomain, resolveReportCategory, firstAllowedRoute }', $shell);
        $this->assertStringContainsString(':to="railTarget(m)"', $shell);
        $this->assertMatchesRegularExpression(
            '/railTarget\(m\)\s*\{[\s\S]*?if\s*\(m\.to\)\s*return m\.to;[\s\S]*?firstAllowedRoute\(/',
            $shell,
            'railTarget debe devolver m.to fijo o resolver la primera opción permitida'
        );
        // Nada de m.to crudo en el template del riel.
        $this->assertStringNotContainsString(':to="m.to"', $shell);
    }

    public function test_report_hub_has_a_production_route(): void
    {
        $nav = $this->repo('resources/src/views/app/_ui/data/shell-nav.js');
        $this->assertStringContainsString('export const REPORTS_HUB_ROUTE = "/app/reports/all";', $nav);

        $rail = $this->sliceArray($nav, 'SHELL_RAIL');
        // El dominio Reportes y todos sus items del panel usan REPORTS_HUB_ROUTE.
        $this->assertMatchesRegularExpression('/key:\s*"reportes",[\s\S]{0,240}?to:\s*REPORTS_HUB_ROUTE/', $rail);
        $this->assertStringNotContainsString('"/app/shell/reportes"', $rail);

        // router.js registra /app/reports/all como child REAL (no dentro del
        // bloque dev-only NODE_ENV) apuntando a domain_landing.vue.
        $router = $this->repo('resources/src/router.js');
        $devOnlyAt = strpos($router, 'process.env.NODE_ENV !== "production"');
        $this->assertNotFalse($devOnlyAt);
        $hubAt = strpos($router, 'name: "reports_all_hub"');
        $this->assertNotFalse($hubAt, 'router.js debe registrar la ruta reports_all_hub');
        $this->assertLessThan($devOnlyAt, $hubAt, 'reports_all_hub debe ser una ruta REAL, no dev-only');

        $hubBlock = substr($router, $hubAt, 320);
        $this->assertMatchesRegularExpression('/path:\s*"all"/', $hubBlock);
        $this->assertStringContainsString('views/app/shell/domain_landing.vue', $hubBlock);

        // domain_landing.vue sirve el hub también en la ruta productiva.
        $landing = $this->repo('resources/src/views/app/shell/domain_landing.vue');
        $this->assertStringContainsString('REPORTS_HUB_ROUTE', $landing);
        $this->assertStringContainsString('isReportsHub', $landing);
        $this->assertStringContainsString('v-if="isReportsHub"', $landing);
    }

    public function test_dev_only_shell_routes_remain_out_of_production(): void
    {
        $router = $this->repo('resources/src/router.js');
        $devOnlyAt = strpos($router, 'if (process.env.NODE_ENV !== "production") {');
        $this->assertNotFalse($devOnlyAt, 'router.js debe mantener el bloque dev-only');

        $shellAt = strpos($router, 'path: "/app/shell"');
        $this->assertNotFalse($shellAt, 'la ruta prototipo /app/shell debe seguir existiendo para QA dev');
        $this->assertGreaterThan(
            $devOnlyAt,
            $shellAt,
            '/app/shell debe permanecer DENTRO del bloque process.env.NODE_ENV !== "production"'
        );
    }
}
