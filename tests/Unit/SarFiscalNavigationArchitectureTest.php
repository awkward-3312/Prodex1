<?php

namespace Tests\Unit;

use PHPUnit\Framework\TestCase;

/**
 * "Cumplimiento fiscal (SAR)" pertenece EXCLUSIVAMENTE a Finanzas.
 *
 * Bug corregido:
 *   · aparecía dos veces en el shell px-next (Finanzas → Cumplimiento fiscal Y
 *     Configuración → Fiscal);
 *   · su ruta canónica colgaba de /app/settings/sar_fiscal, así que al abrirla
 *     el shell resolvía el dominio "config" y cambiaba visualmente a
 *     Configuración.
 *
 * Contrato fijado aquí para que no se vuelva a duplicar ni a re-anclar en
 * Configuración. Lee los fuentes como texto (mismo patrón que el resto de tests
 * *ArchitectureTest).
 */
class SarFiscalNavigationArchitectureTest extends TestCase
{
    private const NAV = 'resources/src/views/app/_ui/data/shell-nav.js';

    private const ROUTER = 'resources/src/router.js';

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

    public function test_sar_appears_exactly_once_in_the_shell_navigation(): void
    {
        $nav = $this->repo(self::NAV);

        // Una sola entrada clickable de SAR en TODO el mapa del shell
        // (SHELL_RAIL + SHELL_FOOT). Se cuentan las rutas *_fiscal para no
        // colisionar con SHELL_ROUTE_DOMAINS ni con comentarios.
        $rail = $this->sliceArray($nav, 'SHELL_RAIL');
        $foot = $this->sliceArray($nav, 'SHELL_FOOT');

        $railHits = preg_match_all('#route:\s*"/app/[a-z]+/sar_fiscal"#', $rail);
        $footHits = preg_match_all('#route:\s*"/app/[a-z]+/sar_fiscal"#', $foot);

        $this->assertSame(1, $railHits, 'SAR debe existir una sola vez en SHELL_RAIL');
        $this->assertSame(0, $footHits, 'SAR NO debe existir en SHELL_FOOT (Configuración)');

        // Nombre visible único.
        $this->assertSame(
            1,
            preg_match_all('/Cumplimiento fiscal \(SAR\)/', $rail),
            'una sola etiqueta "Cumplimiento fiscal (SAR)" en SHELL_RAIL'
        );
        $this->assertStringNotContainsString('Cumplimiento fiscal (SAR)', $foot);
    }

    public function test_sar_belongs_to_the_finanzas_domain(): void
    {
        $rail = $this->sliceArray($this->repo(self::NAV), 'SHELL_RAIL');

        // La entrada vive dentro del bloque del dominio `finanzas`, en un grupo
        // "Cumplimiento fiscal", y apunta a la ruta canónica de Finanzas.
        $this->assertMatchesRegularExpression(
            '/key:\s*"finanzas".*?title:\s*"Cumplimiento fiscal".*?'
            .'label:\s*"Cumplimiento fiscal \(SAR\)".*?route:\s*"\/app\/finance\/sar_fiscal"/s',
            $rail
        );
    }

    public function test_configuracion_no_longer_carries_a_sar_entry(): void
    {
        $foot = $this->sliceArray($this->repo(self::NAV), 'SHELL_FOOT');

        // El grupo "Fiscal" de Configuración ya no lista SAR; ZATCA (condicional
        // por plan) es la única entrada fiscal que permanece.
        $this->assertStringNotContainsString('sar_fiscal', $foot);
        $this->assertStringNotContainsString('/app/settings/sar_fiscal', $foot);
        $this->assertStringContainsString('zatca_settings', $foot, 'ZATCA permanece en Configuración → Fiscal');
    }

    public function test_legacy_settings_route_redirects_to_the_finanzas_route(): void
    {
        $router = $this->repo(self::ROUTER);

        // Ruta canónica nueva: /app/finance/sar_fiscal, mismo componente.
        $this->assertMatchesRegularExpression(
            '#path:\s*"/app/finance".*?children:\s*\[\s*\{\s*name:\s*"sar_fiscal",\s*path:\s*"sar_fiscal",'
            .'\s*component:.*?settings/sar_fiscal"#s',
            $router
        );

        // El enlace legado NO monta un componente: sólo redirige (no 404).
        $this->assertMatchesRegularExpression(
            '#\{\s*path:\s*"sar_fiscal",\s*redirect:\s*"/app/finance/sar_fiscal"\s*\}#',
            $router
        );

        // Sólo un nombre de ruta "sar_fiscal" en todo el router (el canónico).
        $this->assertSame(
            1,
            preg_match_all('/name:\s*"sar_fiscal"/', $router),
            'un único route name "sar_fiscal" (el canónico de Finanzas)'
        );

        // El componente se REUTILIZA, no se duplica.
        $this->assertSame(
            1,
            preg_match_all('#"\./views/app/pages/settings/sar_fiscal"#', $router),
            'sar_fiscal.vue se importa una sola vez'
        );
    }

    public function test_the_sar_route_resolves_to_the_finanzas_shell_domain(): void
    {
        $domains = $this->sliceArray($this->repo(self::NAV), 'SHELL_ROUTE_DOMAINS');

        $pos = fn (string $needle) => strpos($domains, $needle);

        // La regla canónica de Finanzas para SAR.
        $this->assertNotFalse($pos('{ prefix: "/app/finance/sar_fiscal", domain: "finanzas" }'));
        $this->assertNotFalse($pos('{ prefix: "/app/finance", domain: "finanzas" }'));

        // La compatibilidad: /app/settings/sar_fiscal se clasifica como Finanzas
        // ANTES que la regla genérica /app/settings → config, para que ni un
        // instante en la URL vieja active Configuración.
        $legacyRule = $pos('{ prefix: "/app/settings/sar_fiscal", domain: "finanzas" }');
        $genericSettings = $pos('{ prefix: "/app/settings", domain: "config" }');
        $this->assertNotFalse($legacyRule, 'debe existir la regla de compatibilidad para /app/settings/sar_fiscal');
        $this->assertNotFalse($genericSettings);
        $this->assertLessThan(
            $genericSettings,
            $legacyRule,
            'la regla /app/settings/sar_fiscal → finanzas debe ir ANTES que /app/settings → config'
        );
    }

    public function test_no_nav_surface_still_points_at_the_legacy_settings_path(): void
    {
        foreach ([
            self::NAV,
            self::ROUTER,
            'resources/src/containers/layouts/largeSidebar/Sidebar.vue',
            'resources/src/containers/layouts/largeSidebar/VerticalSidebar.vue',
            'resources/src/views/app/_ui/data/module-map.js',
        ] as $file) {
            $src = $this->repo($file);
            // Se permite mencionarlo sólo como regla de compatibilidad en
            // shell-nav.js / router.js (redirect + clasificación defensiva).
            $navLink = preg_match('#(to|route|dest)\s*[:=]\s*"?/app/settings/sar_fiscal#', $src);
            $this->assertSame(
                0,
                $navLink,
                "{$file} no debe navegar a /app/settings/sar_fiscal (usa /app/finance/sar_fiscal)"
            );
        }
    }
}
