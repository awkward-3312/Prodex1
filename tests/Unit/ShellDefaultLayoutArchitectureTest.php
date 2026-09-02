<?php

namespace Tests\Unit;

use PHPUnit\Framework\TestCase;

/**
 * Cutover local: px-next es el layout PREDETERMINADO de /app/*, con
 * large-sidebar disponible sólo como rollback explícito.
 *
 * Este test fija el contrato del cambio de default para que no se revierta
 * por accidente:
 *   1. px-next se monta salvo override explícito 'legacy'.
 *   2. legacy sigue registrado y accesible como fallback (no se borró nada).
 *   3. el modelo opt-in anterior (booleano en `pxnShellLayout`) no puede
 *      dejar a nadie en legacy: se ignora y se limpia.
 *   4. las rutas fullscreen excluidas siguen intactas.
 *
 * Lee los archivos fuente como texto (mismo patrón que el resto de tests
 * Architecture); no monta el runtime de Vue.
 */
class ShellDefaultLayoutArchitectureTest extends TestCase
{
    private function repo(string $rel): string
    {
        return file_get_contents(dirname(__DIR__, 2).'/'.$rel);
    }

    public function test_px_next_is_the_default_layout(): void
    {
        $config = $this->repo('resources/src/store/modules/config.js');

        // El estado del layout se deriva de readStoredShellLayout(), que es
        // px-next salvo override 'legacy'.
        $this->assertStringContainsString(
            "return readLayoutOverride() !== 'legacy';",
            $config,
            'config.js: px-next debe estar activo salvo override explícito legacy'
        );
        $this->assertStringContainsString('pxShellLayout: readStoredShellLayout()', $config);

        // views/app/index.vue monta px-shell-layout cuando getPxShellLayout es true.
        $host = $this->repo('resources/src/views/app/index.vue');
        $this->assertStringContainsString(
            'this.getPxShellLayout ? "px-shell-layout" : this.getThemeMode.layout',
            $host,
            'views/app/index.vue: px-shell-layout es el resultado por defecto'
        );
    }

    public function test_legacy_layout_remains_available_as_rollback(): void
    {
        $config = $this->repo('resources/src/store/modules/config.js');

        // Rollback explícito por URL y por valor persistido.
        $this->assertStringContainsString("persistLayoutOverride('legacy')", $config);
        $this->assertMatchesRegularExpression(
            "/q === '0'.*persistLayoutOverride\('legacy'\)/s",
            $config,
            'config.js: ?pxshell=0 debe fijar el override legacy'
        );

        // El layout legacy sigue registrado como componente global.
        $kit = $this->repo('resources/src/plugins/stocky.kit.js');
        $this->assertStringContainsString('Vue.component("large-sidebar"', $kit);
        $this->assertStringContainsString('Vue.component("px-shell-layout"', $kit);

        // Y sigue siendo la salida cuando getPxShellLayout es false.
        $host = $this->repo('resources/src/views/app/index.vue');
        $this->assertStringContainsString(': this.getThemeMode.layout', $host);

        // Los componentes legacy no se han borrado.
        foreach ([
            'resources/src/containers/layouts/largeSidebar/index.vue',
            'resources/src/containers/layouts/largeSidebar/Sidebar.vue',
            'resources/src/containers/layouts/largeSidebar/TopNav.vue',
            'resources/src/containers/layouts/largeSidebar/VerticalSidebar.vue',
        ] as $legacy) {
            $this->assertFileExists(dirname(__DIR__, 2).'/'.$legacy, "legacy no se debe borrar: {$legacy}");
        }
    }

    public function test_old_optin_boolean_cannot_pin_legacy(): void
    {
        $config = $this->repo('resources/src/store/modules/config.js');

        // La clave booleana del opt-in se elimina y no se lee para decidir layout.
        $this->assertStringContainsString("const PXN_LEGACY_OPTIN_KEY = 'pxnShellLayout';", $config);
        $this->assertStringContainsString('localStorage.removeItem(PXN_LEGACY_OPTIN_KEY)', $config);
        $this->assertStringNotContainsString(
            "localStorage.getItem(PXN_LEGACY_OPTIN_KEY)",
            $config,
            'config.js: la clave del opt-in ya no debe leerse'
        );
        // El nuevo override sólo entiende dos valores explícitos.
        $this->assertStringContainsString("const PXN_LAYOUT_KEY = 'pxnLayoutOverride';", $config);
    }

    public function test_excluded_fullscreen_routes_are_unchanged(): void
    {
        $nav = $this->repo('resources/src/views/app/_ui/data/shell-nav.js');
        $start = strpos($nav, 'export const SHELL_EXCLUDED_ROUTES = [');
        $this->assertNotFalse($start);
        $block = substr($nav, $start, strpos($nav, '];', $start) - $start);

        foreach ([
            '/app/pos',
            '/app/kitchen-display',
            '/app/customer-display',
            '/app/real-time-sales-counter',
            '/app/reports/sales-3d-dashboard',
        ] as $route) {
            $this->assertStringContainsString(
                '"'.$route.'"',
                $block,
                "SHELL_EXCLUDED_ROUTES debe seguir excluyendo {$route}"
            );
        }

        // PxShellLayout sigue renderizando bare cuando la ruta está excluida.
        $layout = $this->repo('resources/src/containers/layouts/PxShellLayout.vue');
        $this->assertStringContainsString('isShellExcluded', $layout);
        $this->assertStringContainsString('<router-view v-if="excluded" />', $layout);
    }
}
