<?php

namespace Tests\Unit;

use PHPUnit\Framework\TestCase;

/**
 * Milestone 3 — cobertura del mapping ruta real → dominio del shell px-next.
 *
 * El shell px-next puede envolver /app/* como layout persistente (opt-in). Cada
 * familia de rutas administrativas del SPA debe estar CLASIFICADA en
 * resources/src/views/app/_ui/data/shell-nav.js como:
 *
 *   A. dominio conocido  → prefijo/regex en SHELL_ROUTE_DOMAINS
 *   B. fullscreen/excluida → SHELL_EXCLUDED_ROUTES
 *   C. especial neutra    → SHELL_NEUTRAL_ROUTES (documentada)
 *
 * Este test lee las listas reales de shell-nav.js y las familias reales de
 * router.js + main.js, y falla si una familia /app/* queda sin clasificar —
 * para que una ruta futura no herede "panel" en silencio.
 *
 * Ignora deliberadamente lo que NO usa el shell normal:
 *   · /app/_ui, /app/shell  → prototipos dev-only (resolveShellDomain maneja
 *     /app/shell/<seg> por segmento; /app/_ui es el playground del design system)
 *   · POS y rutas públicas/top-level  → /app/pos ya está en SHELL_EXCLUDED_ROUTES
 */
class ShellDomainCoverageArchitectureTest extends TestCase
{
    private const DEV_ONLY_IGNORE = ['/app/_ui', '/app/shell'];

    private function repoPath(string $rel): string
    {
        return dirname(__DIR__, 2).'/'.$rel;
    }

    private function navJs(): string
    {
        return file_get_contents($this->repoPath('resources/src/views/app/_ui/data/shell-nav.js'));
    }

    /** Extrae el cuerpo `[ ... ]` de `export const NAME = [` hasta el primer `];`. */
    private function sliceArray(string $src, string $name): string
    {
        $start = strpos($src, "export const {$name} = [");
        $this->assertNotFalse($start, "shell-nav.js debe exportar {$name}");
        $end = strpos($src, '];', $start);
        $this->assertNotFalse($end, "{$name} debe cerrar con '];'");

        return substr($src, $start, $end - $start);
    }

    public function test_shell_nav_exposes_the_route_classification_api(): void
    {
        $js = $this->navJs();

        foreach ([
            'export const SHELL_ROUTE_DOMAINS',
            'export const SHELL_EXCLUDED_ROUTES',
            'export const SHELL_NEUTRAL_ROUTES',
            'export function resolveShellDomain',
            'export function isShellExcluded',
            'export function resolveReportCategory',
        ] as $needle) {
            $this->assertStringContainsString($needle, $js, "shell-nav.js debe declarar {$needle}");
        }
    }

    public function test_resolve_shell_domain_fails_safe_to_null_never_panel(): void
    {
        $js = $this->navJs();

        // Fallback fail-safe: ruta desconocida → null (estado neutro), nunca "panel".
        $this->assertStringContainsString('return hit ? hit.domain : null;', $js);
        $this->assertStringNotContainsString('return hit ? hit.domain : "panel"', $js);
        $this->assertStringNotContainsString("return hit ? hit.domain : 'panel'", $js);
    }

    public function test_every_admin_app_route_family_is_classified(): void
    {
        $js = $this->navJs();

        // ---- Reglas de clasificación (leídas de shell-nav.js) -----------------
        $domainsBlock = $this->sliceArray($js, 'SHELL_ROUTE_DOMAINS');
        preg_match_all('/prefix:\s*"([^"]+)"/', $domainsBlock, $pm);
        $prefixes = $pm[1];

        preg_match_all('#test:\s*/(.+?)/([a-z]*)\s*,#', $domainsBlock, $tm, PREG_SET_ORDER);
        $tests = array_map(fn ($t) => ['pattern' => $t[1], 'flags' => $t[2]], $tm);

        preg_match_all('/"([^"]+)"/', $this->sliceArray($js, 'SHELL_EXCLUDED_ROUTES'), $em);
        $excluded = $em[1];

        preg_match_all('/"([^"]+)"/', $this->sliceArray($js, 'SHELL_NEUTRAL_ROUTES'), $nm);
        $neutral = $nm[1];

        $this->assertNotEmpty($prefixes, 'SHELL_ROUTE_DOMAINS debe tener prefijos');
        $this->assertNotEmpty($excluded, 'SHELL_EXCLUDED_ROUTES no debe estar vacío');

        // ---- Familias reales de /app/* (router.js + main.js) -----------------
        $router = file_get_contents($this->repoPath('resources/src/router.js'));
        $main = file_get_contents($this->repoPath('resources/src/main.js'));
        preg_match_all('/path:\s*"(\/app\/[^"]+)"/', $router.$main, $rm);

        $families = [];
        foreach ($rm[1] as $path) {
            if (preg_match('#^(/app/[^/]+)#', $path, $fm)) {
                $families[$fm[1]] = true;
            }
        }
        $families = array_keys($families);
        sort($families);
        $this->assertNotEmpty($families, 'Debe haber familias /app/* en el router');

        // ---- Clasificación -------------------------------------------------
        $unclassified = [];
        foreach ($families as $fam) {
            if (in_array($fam, self::DEV_ONLY_IGNORE, true)) {
                continue;
            }
            if (! $this->isClassified($fam, $prefixes, $tests, $excluded, $neutral)) {
                $unclassified[] = $fam;
            }
        }

        $this->assertSame(
            [],
            $unclassified,
            "Familias /app/* SIN clasificar en shell-nav.js (añade prefijo/regex a ".
            "SHELL_ROUTE_DOMAINS, o a SHELL_EXCLUDED_ROUTES / SHELL_NEUTRAL_ROUTES):\n  ".
            implode("\n  ", $unclassified)
        );
    }

    private function isClassified(string $fam, array $prefixes, array $tests, array $excluded, array $neutral): bool
    {
        $probe = $fam.'/__probe__';

        // A. dominio conocido — prefijo ancestro/exacto de la familia
        foreach ($prefixes as $p) {
            if (strpos($fam.'/', rtrim($p, '/').'/') === 0) {
                return true;
            }
        }
        // A'. dominio conocido — regex `test`
        foreach ($tests as $t) {
            $delim = '#';
            $pattern = str_replace($delim, '\\'.$delim, $t['pattern']);
            if (@preg_match($delim.$pattern.$delim.$t['flags'], $fam) === 1
                || @preg_match($delim.$pattern.$delim.$t['flags'], $probe) === 1) {
                return true;
            }
        }
        // B. excluida (prefijo)
        foreach ($excluded as $x) {
            if (strpos($fam, $x) === 0 || strpos($probe, $x) === 0) {
                return true;
            }
        }
        // C. neutra documentada
        foreach ($neutral as $x) {
            if ($fam === $x || strpos($probe, $x.'/') === 0) {
                return true;
            }
        }

        return false;
    }

    public function test_report_category_mapping_covers_the_six_real_categories(): void
    {
        $js = $this->navJs();
        $catalog = $this->sliceArray($js, 'SHELL_REPORTS');

        foreach (['generales', 'ventas', 'compras', 'inventario', 'finanzas', 'personas'] as $key) {
            $this->assertMatchesRegularExpression(
                '/key:\s*"'.$key.'"/',
                $catalog,
                "SHELL_REPORTS debe definir la categoría '{$key}'"
            );
        }

        // Anclas: una ruta real representativa por categoría vive en su bloque.
        $anchors = [
            'ventas'     => '/app/reports/sales_report',
            'compras'    => '/app/reports/purchase_report',
            'inventario' => '/app/reports/stock_report',
            'finanzas'   => '/app/reports/cash_flow_report',
            'personas'   => '/app/reports/users_report',
        ];
        foreach ($anchors as $cat => $route) {
            $this->assertStringContainsString(
                $route,
                $catalog,
                "SHELL_REPORTS debe listar {$route} (categoría {$cat})"
            );
        }

        // La categoría activa en rutas reales se resuelve desde SHELL_REPORTS,
        // sin casos hardcodeados en PxShell.vue.
        $shell = file_get_contents($this->repoPath('resources/src/components/px-next/PxShell.vue'));
        $this->assertStringContainsString('resolveReportCategory(path)', $shell);
    }
}
