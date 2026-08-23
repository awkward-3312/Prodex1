<?php

namespace Tests\Unit;

use PHPUnit\Framework\TestCase;

class TransferImmediateDispatchTest extends TestCase
{
    public function test_new_transfer_is_approved_and_dispatched_before_store_commits(): void
    {
        $root = dirname(__DIR__, 2);
        $controller = file_get_contents($root.'/app/Http/Controllers/FinalTransferController.php');
        $workflow = file_get_contents($root.'/app/Services/TransferWorkflowService.php');
        $incoming = file_get_contents($root.'/app/Http/Controllers/FinalTransferLogisticsController.php');
        $model = file_get_contents($root.'/app/Models/Transfer.php');

        $this->assertStringContainsString('return DB::transaction(function () use ($request, $user)', $controller);
        $this->assertStringContainsString('parent::store($request);', $controller);
        $this->assertStringContainsString('private ?string $createdTransferReference = null;', $controller);
        $this->assertStringContainsString("->where('Ref', \$this->createdTransferReference)", $controller);
        $this->assertStringContainsString("->lockForUpdate()", $controller);
        $this->assertStringNotContainsString("->where('user_id', \$user->id)\n                ->orderByDesc('id')", $controller);
        $this->assertStringContainsString('$transfer = $workflow->approve($transfer, $user);', $controller);
        $this->assertStringContainsString('$transfer = $workflow->dispatch($transfer, $user);', $controller);
        $this->assertStringContainsString("'logistics_status' => \$transfer->logistics_status", $controller);
        $this->assertStringContainsString("'receiving_token' => \$transfer->receiving_token", $controller);

        $this->assertStringContainsString('TransferLocationDispatchService::class', $workflow);
        $this->assertStringContainsString('syncDispatchState($locked, $actor)', $workflow);
        $this->assertStringContainsString("['in_transit', 'partially_received']", $incoming);

        $this->assertStringContainsString("['in_transit', 'partially_received', 'received', 'received_with_issues']", $model);
        $this->assertStringContainsString('No se puede cambiar el origen o destino después de despachar la transferencia.', $model);
        $this->assertStringContainsString('Una transferencia despachada no puede eliminarse', $model);
    }
}
