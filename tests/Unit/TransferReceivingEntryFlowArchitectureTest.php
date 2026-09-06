<?php

namespace Tests\Unit;

use PHPUnit\Framework\TestCase;

/**
 * Una notificación "traslado en camino" y la pantalla de detalle deben llevar a
 * la tarea real (revisar y recibir), no a un callejón sin salida.
 *
 * Contrato: la autoridad es SIEMPRE
 * TransferLogisticsService::userCanReceive() (bindeado a
 * FinalTransferLogisticsService = permiso `transfer_receive` +
 * InventoryLocationScopeService::canReceiveAt sobre la ubicación destino).
 * El frontend nunca calcula autorización; el `receiving_token` sólo se entrega
 * a quien puede recibir.
 */
class TransferReceivingEntryFlowArchitectureTest extends TestCase
{
    private function read(string $rel): string
    {
        return file_get_contents(dirname(__DIR__, 2).'/'.$rel);
    }

    public function test_workflow_payload_exposes_can_receive_via_the_single_authority(): void
    {
        $c = $this->read('app/Http/Controllers/TransferWorkflowController.php');

        $this->assertStringContainsString('use App\Services\TransferLogisticsService;', $c);
        $this->assertStringContainsString("'can_receive' => \$canReceive,", $c);

        // can_receive = en tránsito + hay token + acceso al destino + userCanReceive.
        $this->assertMatchesRegularExpression(
            '/\$canReceive = \$inTransit\s*\n\s*&& ! empty\(\$transfer->receiving_token\)\s*\n\s*&& \$this->canAccessDestination\(\$user, \$transfer\)\s*\n\s*&& app\(TransferLogisticsService::class\)->userCanReceive\(\$user, \$transfer\);/s',
            $c
        );
        // El payload de workflow NO expone ningún receiving_token.
        $this->assertStringNotContainsString("'receiving_token' =>", $c);
        // No se reimplementan reglas: nada de hasPermissionName('transfer_receive') suelto aquí.
        $this->assertStringNotContainsString("hasPermissionName('transfer_receive')", $c);
    }

    public function test_notification_center_routes_incoming_transfer_to_the_receive_task(): void
    {
        $c = $this->read('app/Http/Controllers/NotificationCenterController.php');

        // Ruta condicional por id: bandeja de recepción si procede, si no el detalle.
        $this->assertStringContainsString("? '/app/transfers/receptions/'.\$row->transfer_id", $c);
        $this->assertStringContainsString(": '/app/transfers/detail/'.\$row->transfer_id", $c);
        // No se filtra ningún token en la acción de notificación.
        $this->assertStringNotContainsString('/transfer-receive/', $c);

        // Sólo filas incoming_transfer, en tránsito, con token — y re-verificando
        // userCanReceive (no se confía en que la fila exista).
        $this->assertStringContainsString("\$type === 'incoming_transfer'", $c);
        $this->assertStringContainsString("['in_transit', 'partially_received']", $c);
        $this->assertStringContainsString('->userCanReceive($user, $transfer)', $c);
        $this->assertStringContainsString('app(TransferLogisticsService::class)', $c);
    }

    public function test_transfer_detail_offers_review_and_receive_reusing_the_existing_flow(): void
    {
        $v = $this->read('resources/src/views/app/pages/transfers/next/detail.vue');

        // Acción visible sólo si el backend la habilita.
        $this->assertStringContainsString('v-if="actions.can_receive"', $v);
        $this->assertStringContainsString('>Revisar y recibir</px-button>', $v);
        $this->assertStringContainsString('icon="package-check"', $v);
        $this->assertStringContainsString('a.can_receive', $v); // hasAnyAction

        // Reutiliza la bandeja de recepción px-next existente (ruta SPA por id);
        // NO implementa la recepción aquí, NO maneja tokens.
        $this->assertMatchesRegularExpression(
            '/goReceive\(\)\s*\{.*?this\.\$router\.push\(\{ name: "transfer_reception", params: \{ id: String\(id\) \} \}\)/s',
            $v
        );
        $this->assertStringNotContainsString('/transfer-receive/', $v);
        $this->assertStringNotContainsString('receiving_token', $v);
    }

    public function test_branch_manager_template_gains_transfer_receive(): void
    {
        $c = $this->read('app/Http/Controllers/Organization/RoleTemplateController.php');

        $this->assertSame(
            1,
            preg_match("/'key' => 'branch_manager'.*?'permissions' => (\[[^\]]*\])/s", $c, $m),
            'No se encontró la plantilla branch_manager.'
        );
        $this->assertStringContainsString("'transfer_receive'", $m[1]);
        $this->assertStringContainsString("'transfer_view'", $m[1]);
        // NO se le concede scope de origen / operativo extra por plantilla.
        $this->assertStringNotContainsString("'transfer_add'", $m[1]);
        $this->assertStringNotContainsString("'transfer_edit'", $m[1]);
        $this->assertStringNotContainsString("'Pos_view'", $m[1]);
        $this->assertStringNotContainsString("'adjustment_add'", $m[1]);
    }

    public function test_inventory_location_scope_service_is_untouched(): void
    {
        // El fix NO amplía scopes: la capacidad especial del gerente para la
        // bodega sigue viniendo exclusivamente de receivingLocationIds/canReceiveAt.
        $s = $this->read('app/Services/InventoryLocationScopeService.php');
        $this->assertStringContainsString('public function receivingLocationIds(User $user): array', $s);
        $this->assertStringContainsString('public function canReceiveAt(User $user, int $locationId): bool', $s);
        $this->assertStringContainsString('Receiving is intentionally broader than normal operational scope', $s);
    }
}
