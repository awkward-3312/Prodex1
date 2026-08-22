<?php

namespace Tests\Unit;

use PHPUnit\Framework\TestCase;

class TransferWorkflowAuditTest extends TestCase
{
    public function test_explicit_workflow_separates_approval_dispatch_and_audit(): void
    {
        $root = dirname(__DIR__, 2);
        $service = file_get_contents($root.'/app/Services/TransferWorkflowService.php');
        $controller = file_get_contents($root.'/app/Http/Controllers/TransferWorkflowController.php');
        $model = file_get_contents($root.'/app/Models/Transfer.php');
        $routes = file_get_contents($root.'/routes/tenant_transfer_logistics.php');
        $ui = file_get_contents($root.'/resources/static/prodex-transfer-workflow.js');

        $this->assertStringContainsString('public function approve(Transfer $transfer, User $actor)', $service);
        $this->assertStringContainsString("\$locked->approval_status = 'approved'", $service);
        $this->assertStringContainsString('public function dispatch(Transfer $transfer, User $actor)', $service);
        $this->assertStringContainsString('TransferLocationDispatchService::class', $service);
        $this->assertStringContainsString('syncDispatchState($locked, $actor)', $service);

        $this->assertStringContainsString("'events' => \$events", $controller);
        $this->assertStringContainsString("'actor_name'", $controller);
        $this->assertStringContainsString("'created_at'", $controller);

        $this->assertStringContainsString('$legacyApproval', $model);
        $this->assertStringContainsString('TransferWorkflowController', $model);

        $this->assertStringContainsString('/transfer-workflow/{id}/approve', $routes);
        $this->assertStringContainsString('/transfer-workflow/{id}/dispatch', $routes);
        $this->assertStringContainsString('Historial de la transferencia', $ui);
        $this->assertStringContainsString('Despachar ahora', $ui);
    }
}
