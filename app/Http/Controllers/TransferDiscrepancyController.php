<?php

namespace App\Http\Controllers;

use App\Models\Transfer;
use App\Models\TransferDetail;
use App\Models\TransferReceiptItem;
use App\Models\User;
use App\Services\TransferLogisticsService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

class TransferDiscrepancyController extends Controller
{
    public const MANAGE_PERMISSION = 'transfer_issue_manage';

    public function __construct(private TransferLogisticsService $logistics)
    {
    }

    public function index(Request $request)
    {
        /** @var User|null $user */
        $user = $request->user('api');
        abort_unless($user, 401);

        $canReceive = $user->hasPermissionName(TransferLogisticsService::RECEIVE_PERMISSION);
        $canManage = $user->hasPermissionName(self::MANAGE_PERMISSION);
        abort_unless($canReceive || $canManage, 403);

        $warehouseIds = $this->logistics->warehouseIdsForUser($user);
        if (! $warehouseIds) {
            return response()->json(['issues' => [], 'open_count' => 0, 'can_manage' => $canManage]);
        }

        $query = DB::table('transfer_discrepancies as d')
            ->join('transfers as t', 't.id', '=', 'd.transfer_id')
            ->join('transfer_details as td', 'td.id', '=', 'd.transfer_detail_id')
            ->join('products as p', 'p.id', '=', 'td.product_id')
            ->leftJoin('product_variants as pv', 'pv.id', '=', 'td.product_variant_id')
            ->leftJoin('warehouses as fw', 'fw.id', '=', 't.from_warehouse_id')
            ->leftJoin('warehouses as tw', 'tw.id', '=', 't.to_warehouse_id')
            ->leftJoin('users as reporter', 'reporter.id', '=', 'd.reported_by_user_id')
            ->leftJoin('users as resolver', 'resolver.id', '=', 'd.resolved_by_user_id')
            ->whereNull('t.deleted_at');

        if ($canManage) {
            $query->where(function ($q) use ($warehouseIds) {
                $q->whereIn('t.to_warehouse_id', $warehouseIds)
                    ->orWhereIn('t.from_warehouse_id', $warehouseIds);
            });
        } else {
            $query->whereIn('t.to_warehouse_id', $warehouseIds);
        }

        if ($request->filled('status')) {
            $query->where('d.resolution_status', $request->string('status')->toString());
        }

        $issues = $query
            ->orderByRaw("CASE WHEN d.resolution_status = 'open' THEN 0 ELSE 1 END")
            ->orderByDesc('d.reported_at')
            ->limit(250)
            ->get([
                'd.id', 'd.transfer_id', 'd.transfer_detail_id', 'd.warehouse_id',
                'd.type', 'd.quantity', 'd.resolution_status', 'd.resolution_code',
                'd.resolution_reference', 'd.resolution_notes', 'd.notes',
                'd.reported_at', 'd.resolved_at',
                't.Ref as reference', 't.from_warehouse_id', 't.to_warehouse_id',
                'fw.name as from_warehouse', 'tw.name as to_warehouse',
                'p.name as product_name', 'p.code as product_code', 'pv.name as variant_name',
                DB::raw("TRIM(CONCAT(COALESCE(reporter.firstname, ''), ' ', COALESCE(reporter.lastname, ''))) as reported_by"),
                DB::raw("TRIM(CONCAT(COALESCE(resolver.firstname, ''), ' ', COALESCE(resolver.lastname, ''))) as resolved_by"),
            ]);

        return response()->json([
            'issues' => $issues,
            'open_count' => $issues->where('resolution_status', 'open')->count(),
            'can_manage' => $canManage,
            'resolutions' => [
                'missing' => [
                    ['value' => 'received_later', 'label' => 'Recibido posteriormente'],
                    ['value' => 'confirmed_loss', 'label' => 'Pérdida confirmada'],
                    ['value' => 'reconciled_by_adjustment', 'label' => 'Conciliado mediante ajuste de inventario'],
                ],
                'defective' => [
                    ['value' => 'released_to_stock', 'label' => 'Liberado a inventario vendible'],
                    ['value' => 'written_off', 'label' => 'Dado de baja'],
                    ['value' => 'returned_to_origin', 'label' => 'Devuelto a bodega origen'],
                    ['value' => 'reconciled_by_adjustment', 'label' => 'Conciliado mediante ajuste de inventario'],
                ],
            ],
        ]);
    }

    public function resolve(Request $request, int $id)
    {
        /** @var User|null $user */
        $user = $request->user('api');
        abort_unless($user && $user->hasPermissionName(self::MANAGE_PERMISSION), 403);

        $validated = $request->validate([
            'resolution_code' => ['required', 'string', Rule::in([
                'received_later', 'confirmed_loss', 'reconciled_by_adjustment',
                'released_to_stock', 'written_off', 'returned_to_origin',
            ])],
            'resolution_reference' => ['nullable', 'string', 'max:120'],
            'resolution_notes' => ['required', 'string', 'max:3000'],
        ]);

        return DB::transaction(function () use ($id, $user, $validated) {
            $issue = DB::table('transfer_discrepancies')->where('id', $id)->lockForUpdate()->first();
            abort_unless($issue, 404);

            if ($issue->resolution_status !== 'open') {
                throw ValidationException::withMessages([
                    'issue' => 'Esta incidencia ya fue resuelta y no puede cerrarse nuevamente.',
                ]);
            }

            $transfer = Transfer::whereNull('deleted_at')->findOrFail($issue->transfer_id);
            $warehouseIds = $this->logistics->warehouseIdsForUser($user);
            $allowedWarehouse = in_array((int) $transfer->to_warehouse_id, $warehouseIds, true)
                || in_array((int) $transfer->from_warehouse_id, $warehouseIds, true);
            abort_unless($allowedWarehouse, 403);

            $allowedByType = $issue->type === 'missing'
                ? ['received_later', 'confirmed_loss', 'reconciled_by_adjustment']
                : ['released_to_stock', 'written_off', 'returned_to_origin', 'reconciled_by_adjustment'];

            if (! in_array($validated['resolution_code'], $allowedByType, true)) {
                throw ValidationException::withMessages([
                    'resolution_code' => 'La resolución seleccionada no corresponde al tipo de incidencia.',
                ]);
            }

            if ($validated['resolution_code'] === 'reconciled_by_adjustment' && empty($validated['resolution_reference'])) {
                throw ValidationException::withMessages([
                    'resolution_reference' => 'Debes indicar la referencia del ajuste de inventario utilizado para conciliar la incidencia.',
                ]);
            }

            if ($issue->type === 'defective') {
                $this->resolveQuarantineQuantity($issue, $transfer, $validated);
            }

            // A late physical arrival or a quarantined item that is ultimately
            // accepted as sellable is a RECLASSIFICATION, not a second shipment.
            // Move the original receipt counters from missing/defective -> good and
            // credit stock once. This keeps sent = good + defective + missing exact.
            if ($issue->type === 'missing' && $validated['resolution_code'] === 'received_later') {
                $this->reclassifyReceiptIssueToGood($issue, $transfer, 'quantity_missing');
            }
            if ($issue->type === 'defective' && $validated['resolution_code'] === 'released_to_stock') {
                $this->reclassifyReceiptIssueToGood($issue, $transfer, 'quantity_defective');
            }

            DB::table('transfer_discrepancies')->where('id', $id)->update([
                'resolution_status' => 'resolved',
                'resolution_code' => $validated['resolution_code'],
                'resolution_reference' => $validated['resolution_reference'] ?? null,
                'resolution_notes' => $validated['resolution_notes'],
                'resolved_at' => now(),
                'resolved_by_user_id' => $user->id,
                'updated_at' => now(),
            ]);

            $this->logistics->recordEvent(
                (int) $issue->transfer_id,
                'discrepancy_resolved',
                $user->id,
                (int) $issue->warehouse_id,
                [
                    'discrepancy_id' => (int) $issue->id,
                    'type' => $issue->type,
                    'quantity' => (float) $issue->quantity,
                    'resolution_code' => $validated['resolution_code'],
                    'resolution_reference' => $validated['resolution_reference'] ?? null,
                    'credited_to_sellable_stock' => in_array($validated['resolution_code'], ['received_later', 'released_to_stock'], true),
                ]
            );

            return response()->json(['success' => true]);
        }, 5);
    }

    private function reclassifyReceiptIssueToGood(object $issue, Transfer $transfer, string $issueColumn): void
    {
        $detail = TransferDetail::findOrFail($issue->transfer_detail_id);
        $remaining = (float) $issue->quantity;
        $epsilon = 0.000001;

        if (! in_array($issueColumn, ['quantity_missing', 'quantity_defective'], true)) {
            throw ValidationException::withMessages(['issue' => 'Tipo de reclasificación inválido.']);
        }

        $receiptIds = DB::table('transfer_receipts')
            ->where('transfer_id', $transfer->id)
            ->select('id');

        $items = TransferReceiptItem::where('transfer_detail_id', $detail->id)
            ->whereIn('transfer_receipt_id', $receiptIds)
            ->where($issueColumn, '>', 0)
            ->orderBy('id')
            ->lockForUpdate()
            ->get();

        foreach ($items as $item) {
            if ($remaining <= $epsilon) {
                break;
            }

            $available = (float) $item->{$issueColumn};
            $take = min($available, $remaining);
            if ($take <= 0) {
                continue;
            }

            $item->{$issueColumn} = $available - $take;
            $item->quantity_good = (float) $item->quantity_good + $take;
            $item->save();

            if (! method_exists($this->logistics, 'creditIssueResolution')) {
                throw ValidationException::withMessages([
                    'issue' => 'El servicio de inventario no soporta la reclasificación segura de esta incidencia.',
                ]);
            }

            $this->logistics->creditIssueResolution($transfer, $detail, $take, $item);
            $remaining -= $take;
        }

        if ($remaining > $epsilon) {
            throw ValidationException::withMessages([
                'issue' => 'La cantidad registrada en la incidencia no coincide con los comprobantes de recepción. No se realizó ningún cambio.',
            ]);
        }
    }

    /**
     * Resolve exactly the defective quantity represented by one discrepancy.
     * A transfer can have multiple partial receipts for the same product, so a
     * blanket UPDATE by transfer/detail would accidentally close unrelated
     * quarantine stock. Rows are consumed FIFO and split when necessary.
     */
    private function resolveQuarantineQuantity(object $issue, Transfer $transfer, array $validated): void
    {
        $remaining = (float) $issue->quantity;
        $epsilon = 0.000001;
        $targetStatus = match ($validated['resolution_code']) {
            'released_to_stock' => 'released_to_stock',
            'written_off' => 'written_off',
            'returned_to_origin' => 'returned_to_origin',
            'reconciled_by_adjustment' => 'reconciled',
            default => 'quarantined',
        };
        $targetWarehouseId = $validated['resolution_code'] === 'returned_to_origin'
            ? (int) $transfer->from_warehouse_id
            : (int) $issue->warehouse_id;

        $rows = DB::table('transfer_quarantine_stock')
            ->where('transfer_id', $issue->transfer_id)
            ->where('transfer_detail_id', $issue->transfer_detail_id)
            ->where('warehouse_id', $issue->warehouse_id)
            ->where('status', 'quarantined')
            ->orderBy('id')
            ->lockForUpdate()
            ->get();

        foreach ($rows as $row) {
            if ($remaining <= $epsilon) {
                break;
            }

            $rowQty = (float) $row->quantity;
            if ($rowQty <= 0) {
                continue;
            }

            if ($rowQty <= $remaining + $epsilon) {
                DB::table('transfer_quarantine_stock')->where('id', $row->id)->update([
                    'warehouse_id' => $targetWarehouseId,
                    'status' => $targetStatus,
                    'notes' => $validated['resolution_notes'],
                    'updated_at' => now(),
                ]);
                $remaining -= $rowQty;
                continue;
            }

            // Only part of this quarantine row belongs to the discrepancy. Keep the
            // unresolved balance quarantined and split the resolved quantity out.
            DB::table('transfer_quarantine_stock')->where('id', $row->id)->update([
                'quantity' => $rowQty - $remaining,
                'updated_at' => now(),
            ]);

            DB::table('transfer_quarantine_stock')->insert([
                'transfer_id' => $row->transfer_id,
                'transfer_detail_id' => $row->transfer_detail_id,
                'warehouse_id' => $targetWarehouseId,
                'product_id' => $row->product_id,
                'product_variant_id' => $row->product_variant_id,
                'quantity' => $remaining,
                'status' => $targetStatus,
                'notes' => $validated['resolution_notes'],
                'created_by_user_id' => $row->created_by_user_id,
                'created_at' => now(),
                'updated_at' => now(),
            ]);
            $remaining = 0;
        }

        if ($remaining > $epsilon) {
            throw ValidationException::withMessages([
                'issue' => 'La cantidad defectuosa en cuarentena no coincide con la incidencia. No se realizó ningún cambio.',
            ]);
        }
    }
}
