<?php

namespace Tests\Unit;

use PHPUnit\Framework\TestCase;

/**
 * El selector de sucursal del shell px-next debe representar exactamente el
 * alcance real del usuario (el que ya devuelve `dashboard_data`):
 *
 *   - 0 sucursales  → sin selector (el Panel muestra su propio estado vacío).
 *   - 1 sucursal    → chip estático con el nombre; sin caret, sin menú, no es
 *     un control.
 *   - > 1 sucursal  → selector; opción "todas" = "Todas las sucursales" para el
 *     propietario, "Todas mis sucursales" para gerente / operativo multi-sucursal.
 *
 * Es SÓLO presentación: no toca `DashboardScopeService`, `allowedBranchIds`,
 * `selectedBranchId`, `effectiveWarehouseIds`, autorización, `user_branches`,
 * `record_view`, permisos ni la validación de `branch_id`.
 */
class PxShellBranchSelectorScopeTest extends TestCase
{
    private function read(string $relativePath): string
    {
        return file_get_contents(dirname(__DIR__, 2).'/'.$relativePath);
    }

    // --- shellScope (Vuex): fuente de la etiqueta -------------------------

    public function test_shellscope_label_reflects_single_branch_and_ownership(): void
    {
        $store = $this->read('resources/src/store/modules/shellScope.js');

        // Una sola sucursal → su nombre (nada que elegir).
        $this->assertStringContainsString('if (s.branches.length === 1) return s.branches[0].name;', $store);

        // "todas": del negocio para el owner, "mis sucursales" para el resto.
        $this->assertStringContainsString(
            'shellAllScopeLabel: s => (s.isOwner ? "Todas las sucursales" : "Todas mis sucursales")',
            $store
        );

        // `isOwner` llega desde dashboard_data vía syncBranches; el label no
        // reconstruye el rol.
        $this->assertStringContainsString('syncBranches({ commit, state }, { branches, isOwner, userId })', $store);
        $this->assertStringContainsString('commit("setIsOwner", isOwner)', $store);
        $this->assertStringNotContainsString("=== 'Cashier'", $store);
        $this->assertStringNotContainsString("=== 'Manager'", $store);
    }

    public function test_dashboard_page_feeds_is_owner_from_the_existing_payload(): void
    {
        $index = $this->read('resources/src/views/app/dashboard/next/index.vue');

        $this->assertMatchesRegularExpression(
            '/shellScope\/syncBranches.*?isOwner: !!\(data && data\.scope && data\.scope\.is_owner\)/s',
            $index
        );
    }

    // --- PxShell: chip estático vs selector -----------------------------

    public function test_pxshell_renders_a_static_chip_for_a_single_branch_scope(): void
    {
        $shell = $this->read('resources/src/components/px-next/PxShell.vue');

        $this->assertStringContainsString('isSingleBranchScope() {', $shell);
        $this->assertStringContainsString('return this.scopeBranches.length === 1;', $shell);

        // Con una sucursal: <div> estático (no <button>), sin caret, sin foco.
        $this->assertMatchesRegularExpression(
            '/v-if="isSingleBranchScope"\s*\n\s*class="pxn-scopechip__btn pxn-scopechip__btn--static"/s',
            $shell
        );
        $this->assertMatchesRegularExpression(
            '/pxn-scopechip__btn--static"[^>]*>\s*<lucide-icon name="building-2"[^>]*\/>\s*<span class="pxn-scopechip__label">\{\{ scopeLabel \}\}<\/span>\s*<\/div>/s',
            $shell
        );

        // Con varias: el <button> del selector y su menú viven en el v-else.
        $this->assertStringContainsString('<template v-else>', $shell);
        $this->assertMatchesRegularExpression(
            '/<template v-else>.*?class="pxn-scopechip__btn pxn-ring".*?pxn-scopechip__caret.*?pxn-scopechip__menu.*?<\/template>/s',
            $shell
        );

        // La opción "todas" del menú usa la etiqueta según propiedad.
        $this->assertStringContainsString('<span>{{ allScopeLabel }}</span>', $shell);

        // El chip estático no reacciona como control.
        $this->assertStringContainsString('.pxn-scopechip__btn--static { cursor: default; }', $shell);
        $this->assertStringContainsString(
            '.pxn-scopechip__btn--static:hover { background: var(--pxn-surface); border-color: var(--pxn-border); color: var(--pxn-ink-2); }',
            $shell
        );
    }

    public function test_no_branch_scope_shows_no_selector(): void
    {
        $shell = $this->read('resources/src/components/px-next/PxShell.vue');

        // Se sigue exigiendo ≥1 sucursal para pintar el chip.
        $this->assertStringContainsString('return this.scopeBranches.length > 0 && this.activeDomain === "panel";', $shell);
    }

    // --- cero cambios de backend / scope --------------------------------

    public function test_backend_scope_and_authorization_are_untouched(): void
    {
        $root = dirname(__DIR__, 2);
        foreach ([
            'app/Services/DashboardScopeService.php',
            'app/Support/DashboardScope.php',
            'app/Http/Controllers/DashboardController.php',
            'app/Http/Controllers/OperationalDashboardController.php',
        ] as $rel) {
            $this->assertFileExists($root.'/'.$rel);
        }

        $svc = $this->read('app/Services/DashboardScopeService.php');
        $this->assertStringContainsString('public function resolve(User $user, ?int $requestedBranchId = null): DashboardScope', $svc);
        $this->assertStringContainsString('OWNER_ROLE_ID', $svc);

        // El selector del shell no reimplementa el gate de rol ni toca record_view.
        $shell = $this->read('resources/src/components/px-next/PxShell.vue');
        $this->assertStringNotContainsString('record_view', $shell);
        $this->assertStringNotContainsString('role_id === 1', $shell);
    }
}
