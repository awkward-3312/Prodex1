<?php

namespace Tests\Unit;

use PHPUnit\Framework\TestCase;

/**
 * Hotfix — los widgets globales de Traslados no pueden vivir sobre páginas de
 * error/públicas, y un 403 de sonda de capacidad no debe llevar la SPA a
 * not_authorize.
 *
 * Estas comprobaciones son de contrato (mismo estilo que TransferWorkflowAuditTest):
 * garantizan que el guard compartido está cableado y que cada pieza lo consulta.
 */
class TransferWidgetGuardTest extends TestCase
{
    private function root(): string
    {
        return dirname(__DIR__, 2);
    }

    private function read(string $rel): string
    {
        $path = $this->root().'/'.$rel;
        $this->assertFileExists($path, $rel.' debe existir');
        return (string) file_get_contents($path);
    }

    public function test_shared_guard_script_exists_and_detects_error_and_public_contexts(): void
    {
        $guard = $this->read('resources/static/prodex-transfer-ui-guard.js');

        $this->assertStringContainsString('window.__pxTransferUiSuppressed', $guard);
        // Marcador DOM de NotAuthorize.vue / notFound.vue.
        $this->assertStringContainsString(".not-found-wrap", $guard);
        // Ruta top-level de not_authorize + páginas Blade públicas.
        $this->assertStringContainsString('not_authorize', $guard);
        $this->assertStringContainsString('login|logout|password|register|setup', $guard);
        // Hace teardown de los nodos conocidos.
        $this->assertStringContainsString('px-transfer-logistics-btn', $guard);
        $this->assertStringContainsString('px-transfer-workflow-overlay', $guard);
        $this->assertStringContainsString('px-transfer-issues-btn', $guard);
        $this->assertStringContainsString('function teardown', $guard);
        // No intercepta axios ni hace polling de negocio.
        $this->assertStringNotContainsString('axios', $guard);
        $this->assertStringNotContainsString('/api/transfer', $guard);
    }

    public function test_master_blade_loads_guard_before_the_transfer_widget_scripts(): void
    {
        $blade = $this->read('resources/views/layouts/master.blade.php');

        $this->assertStringContainsString('prodex-transfer-ui-guard.js', $blade);

        $guardPos = strpos($blade, 'prodex-transfer-ui-guard.js');
        foreach (['prodex-transfer-logistics.js', 'prodex-transfer-workflow.js', 'prodex-transfer-issues.js'] as $script) {
            $pos = strpos($blade, $script);
            $this->assertIsInt($pos, $script.' debe estar en el layout');
            $this->assertLessThan($pos, $guardPos, 'El guard debe cargarse antes de '.$script);
        }
    }

    public function test_webpack_mix_copies_the_guard_script(): void
    {
        $mix = $this->read('webpack.mix.js');
        $this->assertStringContainsString("resources/static/prodex-transfer-ui-guard.js", $mix);
        $this->assertStringContainsString("public/js/prodex-transfer-ui-guard.js", $mix);
    }

    public function test_each_transfer_widget_script_consults_the_shared_guard(): void
    {
        foreach ([
            'resources/static/prodex-transfer-logistics.js',
            'resources/static/prodex-transfer-issues.js',
            'resources/static/prodex-transfer-workflow.js',
        ] as $rel) {
            $src = $this->read($rel);
            $this->assertStringContainsString('window.__pxTransferUiSuppressed', $src, $rel.' debe consultar el guard');
            $this->assertStringContainsString('function uiSuppressed', $src, $rel.' debe tener el helper uiSuppressed');
        }
    }

    public function test_logistics_header_button_and_panels_bail_when_suppressed(): void
    {
        $src = $this->read('resources/static/prodex-transfer-logistics.js');
        // ensureHeaderButton se auto-limpia; renderPanel/renderReceiving/showToast salen.
        $this->assertMatchesRegularExpression('/function ensureHeaderButton\(\)\s*\{\s*if \(uiSuppressed\(\)\)/', $src);
        $this->assertMatchesRegularExpression('/function renderPanel\(\)\s*\{\s*if \(uiSuppressed\(\)\) return;/', $src);
        $this->assertMatchesRegularExpression('/function renderReceiving\(payload\)\s*\{\s*if \(uiSuppressed\(\)\) \{ closeOverlay\(\); return; \}/', $src);
        $this->assertMatchesRegularExpression('/function showToast\(\)\s*\{\s*if \(uiSuppressed\(\)\) return;/', $src);
    }

    public function test_issues_probe_carries_skip_error_redirect_and_button_bails(): void
    {
        $src = $this->read('resources/static/prodex-transfer-issues.js');
        $this->assertStringContainsString("meta: { skipErrorRedirect: true, skipInitialLoader: true }", $src);
        $this->assertMatchesRegularExpression('/function ensureButton\(\)\s*\{\s*if \(uiSuppressed\(\)\)/', $src);
        // El modal no se monta en contexto suprimido.
        $this->assertStringContainsString('if (uiSuppressed()) return null;', $src);
    }

    public function test_workflow_enhance_bails_on_error_context_and_on_px_next_list(): void
    {
        $src = $this->read('resources/static/prodex-transfer-workflow.js');
        $this->assertMatchesRegularExpression('/function enhance\(\)\s*\{\s*if \(uiSuppressed\(\)\) \{ close\(\); return; \}/', $src);
        // No superponer el overlay vanilla sobre el listado px-next (.pxtrl).
        $this->assertStringContainsString(".pxtrl", $src);
        $this->assertMatchesRegularExpression('/function openByReference\(ref\)\s*\{\s*if \(uiSuppressed\(\)\) return;/', $src);
        $this->assertMatchesRegularExpression('/function render\(data\)\s*\{\s*close\(\);\s*if \(uiSuppressed\(\)\) return;/', $src);
    }

    public function test_axios_interceptor_excludes_background_capability_probes_from_navigation(): void
    {
        $src = $this->read('resources/src/main.js');
        $this->assertStringContainsString('isBackgroundCapabilityRequest', $src);
        $this->assertStringContainsString('transfer-workflow|transfer-location|notification-center|operational-context|business-audit|inventory-visibility', $src);
        $this->assertStringContainsString('&& !isBackgroundCapabilityRequest', $src);
        // El comportamiento de límite de plan y la navegación por 404 se conservan.
        $this->assertStringContainsString("data.status === 'limit_reached'", $src);
        $this->assertStringContainsString("router.push({ name: 'not_authorize' })", $src);
    }

    public function test_px_next_transfer_views_gate_their_initial_fetch_on_permission(): void
    {
        $list = $this->read('resources/src/views/app/pages/transfers/next/list.vue');
        $this->assertStringContainsString('if (this.can("transfer_view")) this.fetch(true);', $list);
        $this->assertStringContainsString('.get(qs, { meta: { skipErrorRedirect: true } })', $list);

        $detail = $this->read('resources/src/views/app/pages/transfers/next/detail.vue');
        $this->assertStringContainsString('if (this.can("transfer_view")) this.fetch();', $detail);
        $this->assertStringContainsString('meta: { skipErrorRedirect: true }', $detail);

        $receive = $this->read('resources/src/views/app/pages/transfers/next/receive.vue');
        $this->assertStringContainsString('if (!this.canReceiveOrView) return;', $receive);

        $form = $this->read('resources/src/views/app/pages/transfers/next/form.vue');
        $this->assertStringContainsString('if (this.allowed) this.loadElements();', $form);
    }

    public function test_approval_authority_still_requires_transfer_edit_backend_side(): void
    {
        // El overlay vanilla y el detalle px-next sólo muestran "Aprobar" si el
        // backend lo autoriza; la autoridad sigue siendo transfer_edit.
        $controller = $this->read('app/Http/Controllers/TransferWorkflowController.php');
        $this->assertStringContainsString("'can_approve' => \$pending && \$canOperateSource && \$user->hasPermissionName('transfer_edit')", $controller);
        $overlay = $this->read('resources/static/prodex-transfer-workflow.js');
        $this->assertStringContainsString("actions.can_approve ? '<button", $overlay);
    }
}
