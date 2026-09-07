<?php

namespace App\Http\Controllers;

use App\Models\Branch;
use App\Models\CashDrawer;
use App\Models\Client;
use App\Models\InventoryLocation;
use App\Models\Product;
use App\Models\SarAuthorization;
use App\Models\SarFiscalProfile;
use App\Models\SarPointOfIssue;
use App\Models\Setting;
use App\Models\Warehouse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Carbon;
use Illuminate\Validation\Rule;

class SarFiscalSettingsController extends BaseController
{
    private function authorizeSettings(Request $request, string $ability = 'update'): void
    {
        $this->authorizeForUser($request->user('api'), $ability, Setting::class);
    }

    public function index(Request $request)
    {
        $this->authorizeSettings($request, 'view');

        $profile = SarFiscalProfile::first();
        if ($profile) {
            $profile->invoice_settings = $this->invoiceSettings($profile->invoice_settings);
        }

        $points = SarPointOfIssue::with([
            'authorizations' => fn ($query) => $query->orderByDesc('id'),
            'branch:id,name,code',
            'inventoryLocation:id,branch_id,name,type,is_sellable',
            'cashDrawer:id,branch_id,inventory_location_id,name,code,is_active',
        ])->orderBy('establishment_code')->orderBy('point_code')->get()
            ->map(function (SarPointOfIssue $point) {
                $active = $this->readyAuthorization($point);
                $point->setAttribute('has_active_cai', (bool) $active);
                $point->setAttribute('active_cai', $active ? [
                    'cai' => $active->cai,
                    'range_start' => (int) $active->range_start,
                    'range_end' => (int) $active->range_end,
                    'next_number' => (int) $active->next_number,
                    'remaining' => max(0, (int) $active->range_end - (int) $active->next_number + 1),
                    'deadline' => optional($active->deadline)->toDateString(),
                ] : null);
                $point->setAttribute('fiscal_ready', (bool) ($active && $point->active
                    && $point->branch_id && $point->inventory_location_id && $point->cash_drawer_id));
                return $point;
            });

        return response()->json([
            'profile' => $profile,
            'points' => $points,
            'branches' => Branch::whereNull('deleted_at')
                ->orderBy('name')
                ->get(['id', 'name', 'code', 'is_active']),
            'inventory_locations' => InventoryLocation::query()
                ->whereNotNull('branch_id')
                ->orderBy('branch_id')->orderBy('name')
                ->get(['id', 'branch_id', 'name', 'type', 'is_sellable', 'is_active']),
            'warehouses' => Warehouse::whereNull('deleted_at')->orderBy('name')->get(['id', 'name', 'branch_id']),
            'cash_drawers' => CashDrawer::whereNull('deleted_at')
                ->where('is_active', true)
                ->orderBy('name')
                ->get(['id', 'branch_id', 'inventory_location_id', 'warehouse_id', 'name', 'code']),
            'fiscal_gaps' => $this->fiscalGaps($points),
            'products' => Product::whereNull('deleted_at')
                ->where('is_active', 1)
                ->orderBy('name')
                ->get(['id', 'code', 'name', 'TaxNet', 'tax_method', 'fiscal_tax_category']),
            'clients' => Client::whereNull('deleted_at')
                ->orderBy('name')
                ->get([
                    'id', 'name', 'tax_number', 'identification_type', 'identification_number',
                    'sar_registry_number', 'exoneration_registry_number',
                ]),
            'tax_categories' => [
                ['value' => 'taxed', 'label' => 'Gravado'],
                ['value' => 'exempt', 'label' => 'Exento'],
                ['value' => 'exonerated', 'label' => 'Exonerado'],
                ['value' => 'zero_rate', 'label' => 'Tasa cero'],
            ],
            'tax_rates' => [0, 15, 18],
        ]);
    }

    public function saveProfile(Request $request)
    {
        $this->authorizeSettings($request);

        if ($request->input('action') === 'product_fiscal') {
            $product = Product::whereNull('deleted_at')->findOrFail((int) $request->input('product_id'));
            return $this->updateProductFiscal($request, $product);
        }

        if ($request->input('action') === 'client_fiscal') {
            $client = Client::whereNull('deleted_at')->findOrFail((int) $request->input('client_id'));
            return $this->updateClientFiscal($request, $client);
        }

        $data = $request->validate([
            'enabled' => ['required', 'boolean'],
            'rtn' => ['required', 'string', 'max:20'],
            'legal_name' => ['required', 'string', 'max:191'],
            'trade_name' => ['nullable', 'string', 'max:191'],
            'head_office_address' => ['required', 'string', 'max:1000'],
            'phone' => ['nullable', 'string', 'max:50'],
            'email' => ['nullable', 'email', 'max:191'],
            'invoice_settings' => ['nullable', 'array'],
            'invoice_settings.document_title' => ['nullable', 'string', 'max:80'],
            'invoice_settings.sale_type_label' => ['nullable', 'string', 'max:80'],
            'invoice_settings.website' => ['nullable', 'string', 'max:191'],
            'invoice_settings.footer_message' => ['nullable', 'string', 'max:500'],
            'invoice_settings.original_label' => ['nullable', 'string', 'max:120'],
            'invoice_settings.copy_label' => ['nullable', 'string', 'max:120'],
            'invoice_settings.show_logo' => ['nullable', 'boolean'],
            'invoice_settings.show_internal_reference' => ['nullable', 'boolean'],
            'invoice_settings.show_cashier' => ['nullable', 'boolean'],
            'invoice_settings.show_warehouse' => ['nullable', 'boolean'],
            'invoice_settings.show_payment_summary' => ['nullable', 'boolean'],
            'invoice_settings.show_customer_address' => ['nullable', 'boolean'],
            'invoice_settings.show_item_code' => ['nullable', 'boolean'],
            'invoice_settings.show_total_in_words' => ['nullable', 'boolean'],
            'invoice_settings.show_qr' => ['nullable', 'boolean'],
        ]);

        if ($data['enabled'] && ! SarAuthorization::where('status', 'active')->exists()) {
            return response()->json([
                'message' => 'Debes activar al menos una autorización SAR válida antes de habilitar la facturación fiscal.',
            ], 422);
        }

        $data['invoice_settings'] = $this->invoiceSettings($data['invoice_settings'] ?? []);

        $profile = SarFiscalProfile::first();
        $profile = $profile
            ? tap($profile)->update($data)
            : SarFiscalProfile::create($data);

        return response()->json(['success' => true, 'profile' => $profile->fresh()]);
    }

    public function updateProductFiscal(Request $request, Product $product)
    {
        $data = $request->validate([
            'fiscal_tax_category' => ['required', Rule::in(['taxed', 'exempt', 'exonerated', 'zero_rate'])],
            'TaxNet' => ['required', 'numeric', Rule::in([0, 15, 18])],
            'tax_method' => ['required', Rule::in(['1', '2', 1, 2])],
        ]);

        if ($data['fiscal_tax_category'] !== 'taxed') {
            $data['TaxNet'] = 0;
        } elseif ((float) $data['TaxNet'] <= 0) {
            return response()->json(['message' => 'Un producto gravado debe tener una tasa de ISV válida.'], 422);
        }

        $product->update([
            'fiscal_tax_category' => $data['fiscal_tax_category'],
            'TaxNet' => (float) $data['TaxNet'],
            'tax_method' => (string) $data['tax_method'],
        ]);

        return response()->json(['success' => true, 'product' => $product->fresh()]);
    }

    public function updateClientFiscal(Request $request, Client $client)
    {
        $data = $request->validate([
            'tax_number' => ['nullable', 'string', 'max:50'],
            'identification_type' => ['nullable', 'string', 'max:30'],
            'identification_number' => ['nullable', 'string', 'max:50'],
            'sar_registry_number' => ['nullable', 'string', 'max:100'],
            'exoneration_registry_number' => ['nullable', 'string', 'max:100'],
        ]);

        $client->update($data);

        return response()->json(['success' => true, 'client' => $client->fresh()]);
    }

    public function storePoint(Request $request)
    {
        $this->authorizeSettings($request);

        $data = $request->validate([
            'establishment_code' => ['required', 'regex:/^\d{3}$/'],
            'point_code' => [
                'required',
                'regex:/^\d{3}$/',
                Rule::unique('sar_points_of_issue', 'point_code')
                    ->where(fn ($query) => $query->where('establishment_code', $request->establishment_code)),
            ],
            'name' => ['required', 'string', 'max:191'],
            'address' => ['required', 'string', 'max:1000'],
            'branch_id' => ['required', 'integer', 'exists:branches,id'],
            'inventory_location_id' => ['required', 'integer', 'exists:inventory_locations,id'],
            'cash_drawer_id' => ['required', 'integer', 'exists:cash_drawers,id'],
            'warehouse_id' => ['nullable', 'integer', 'exists:warehouses,id'],
            'active' => ['required', 'boolean'],
        ]);

        $data = $this->validateOperationalChain($data, null);

        return response()->json([
            'success' => true,
            'point' => SarPointOfIssue::create($data)->fresh(),
        ]);
    }

    public function updatePoint(Request $request, SarPointOfIssue $point)
    {
        $this->authorizeSettings($request);

        $data = $request->validate([
            'establishment_code' => ['required', 'regex:/^\d{3}$/'],
            'point_code' => [
                'required',
                'regex:/^\d{3}$/',
                Rule::unique('sar_points_of_issue', 'point_code')
                    ->ignore($point->id)
                    ->where(fn ($query) => $query->where('establishment_code', $request->establishment_code)),
            ],
            'name' => ['required', 'string', 'max:191'],
            'address' => ['required', 'string', 'max:1000'],
            'branch_id' => ['required', 'integer', 'exists:branches,id'],
            'inventory_location_id' => ['required', 'integer', 'exists:inventory_locations,id'],
            'cash_drawer_id' => ['required', 'integer', 'exists:cash_drawers,id'],
            'warehouse_id' => ['nullable', 'integer', 'exists:warehouses,id'],
            'active' => ['required', 'boolean'],
        ]);

        $data = $this->validateOperationalChain($data, $point->id);
        $point->update($data);

        return response()->json(['success' => true, 'point' => $point->fresh()]);
    }

    public function storeAuthorization(Request $request)
    {
        $this->authorizeSettings($request);

        $data = $request->validate([
            'point_of_issue_id' => ['required', 'integer', 'exists:sar_points_of_issue,id'],
            'document_type' => ['required', 'regex:/^\d{2}$/'],
            'cai' => ['required', 'string', 'max:64'],
            'range_start' => ['required', 'integer', 'min:1', 'max:99999999'],
            'range_end' => ['required', 'integer', 'gte:range_start', 'max:99999999'],
            'next_number' => ['required', 'integer', 'gte:range_start', 'lte:range_end'],
            'authorization_date' => ['nullable', 'date'],
            'deadline' => ['required', 'date'],
        ]);

        $overlaps = SarAuthorization::where('point_of_issue_id', $data['point_of_issue_id'])
            ->where('document_type', $data['document_type'])
            ->where(function ($query) use ($data) {
                $query->whereBetween('range_start', [$data['range_start'], $data['range_end']])
                    ->orWhereBetween('range_end', [$data['range_start'], $data['range_end']])
                    ->orWhere(function ($query) use ($data) {
                        $query->where('range_start', '<=', $data['range_start'])
                            ->where('range_end', '>=', $data['range_end']);
                    });
            })->exists();

        if ($overlaps) {
            return response()->json(['message' => 'El rango se superpone con otra autorización registrada.'], 422);
        }

        $data['cai'] = strtoupper(trim($data['cai']));
        $data['status'] = 'draft';

        return response()->json([
            'success' => true,
            'authorization' => SarAuthorization::create($data),
        ]);
    }

    public function activateAuthorization(Request $request, SarAuthorization $authorization)
    {
        $this->authorizeSettings($request);

        if ($authorization->deadline->isBefore(today())) {
            return response()->json(['message' => 'No puedes activar una autorización vencida.'], 422);
        }
        if ($authorization->next_number < $authorization->range_start
            || $authorization->next_number > $authorization->range_end) {
            return response()->json(['message' => 'El siguiente correlativo está fuera del rango autorizado.'], 422);
        }
        if (! $authorization->pointOfIssue || ! $authorization->pointOfIssue->active) {
            return response()->json(['message' => 'El punto de emisión debe estar activo.'], 422);
        }

        DB::transaction(function () use ($authorization) {
            SarAuthorization::where('point_of_issue_id', $authorization->point_of_issue_id)
                ->where('document_type', $authorization->document_type)
                ->where('status', 'active')
                ->where('id', '<>', $authorization->id)
                ->update(['status' => 'disabled']);

            $authorization->update(['status' => 'active']);
        });

        return response()->json(['success' => true, 'authorization' => $authorization->fresh()]);
    }

    private function invoiceSettings($settings): array
    {
        return array_merge([
            'document_title' => 'FACTURA',
            'sale_type_label' => 'CONTADO',
            'website' => '',
            'footer_message' => 'Gracias por su compra.',
            'original_label' => 'Original: Cliente',
            'copy_label' => 'Copia: Obligado Tributario Emisor',
            'show_logo' => true,
            'show_internal_reference' => true,
            'show_cashier' => true,
            'show_warehouse' => true,
            'show_payment_summary' => true,
            'show_customer_address' => true,
            'show_item_code' => true,
            'show_total_in_words' => true,
            'show_qr' => true,
        ], is_array($settings) ? $settings : []);
    }

    /**
     * A SAR point of issue is the fiscal identity of exactly one
     * Branch -> InventoryLocation -> CashDrawer triple. The three must really
     * belong to each other, and a cash drawer can back at most one active point.
     */
    private function validateOperationalChain(array $data, ?int $ignorePointId): array
    {
        $branch = Branch::whereNull('deleted_at')->find((int) $data['branch_id']);
        if (! $branch || ! (int) ($branch->is_active ?? 1)) {
            abort(422, 'La sucursal seleccionada no existe o está inactiva.');
        }

        $location = InventoryLocation::whereNull('deleted_at')->find((int) $data['inventory_location_id']);
        if (! $location) {
            abort(422, 'La ubicación de inventario seleccionada no existe.');
        }
        if ((int) $location->branch_id !== (int) $branch->id) {
            abort(422, 'La ubicación de inventario no pertenece a la sucursal seleccionada.');
        }

        $drawer = CashDrawer::whereNull('deleted_at')->find((int) $data['cash_drawer_id']);
        if (! $drawer) {
            abort(422, 'La caja física seleccionada no existe.');
        }
        if ((int) $drawer->branch_id !== (int) $branch->id) {
            abort(422, 'La caja física no pertenece a la sucursal seleccionada.');
        }
        if ($drawer->inventory_location_id !== null
            && (int) $drawer->inventory_location_id !== (int) $location->id) {
            abort(422, 'La caja física no opera desde la ubicación de inventario seleccionada.');
        }

        if (! empty($data['warehouse_id'])) {
            $warehouse = Warehouse::whereNull('deleted_at')->find((int) $data['warehouse_id']);
            if ($warehouse && $warehouse->branch_id !== null && (int) $warehouse->branch_id !== (int) $branch->id) {
                abort(422, 'El almacén legado indicado no pertenece a la sucursal seleccionada.');
            }
        } else {
            // Keep a legacy warehouse pointer for the fallback resolver when the
            // branch still has one, but it is never the source of truth for POS.
            $data['warehouse_id'] = optional(
                Warehouse::whereNull('deleted_at')->where('branch_id', $branch->id)->first()
            )->id;
        }

        $duplicateActive = SarPointOfIssue::where('cash_drawer_id', (int) $drawer->id)
            ->where('active', true)
            ->when($ignorePointId, fn ($q) => $q->where('id', '<>', $ignorePointId))
            ->when(array_key_exists('active', $data) && ! $data['active'], fn ($q) => $q->whereRaw('1 = 0'))
            ->exists();
        if ($duplicateActive && ! empty($data['active'])) {
            abort(422, 'Ya existe otro punto SAR activo asignado a esta caja física.');
        }

        return $data;
    }

    /**
     * The authorization that is genuinely ready to invoice for a point:
     *   status = active  AND  deadline >= today  AND  next_number in [range_start, range_end].
     * Any failing condition means "not ready" — the UI must reflect that.
     */
    private function readyAuthorization(SarPointOfIssue $point): ?SarAuthorization
    {
        $today = Carbon::today();

        return $point->authorizations
            ->where('document_type', '01')
            ->first(function (SarAuthorization $a) use ($today) {
                if ($a->status !== 'active') {
                    return false;
                }
                if ($a->deadline && Carbon::parse($a->deadline)->lt($today)) {
                    return false;
                }
                $next = (int) $a->next_number;
                return $next >= (int) $a->range_start && $next <= (int) $a->range_end;
            });
    }

    /**
     * Cash drawers that a cashier could pick at the POS but that have no fiscal
     * identity yet: no active SAR point, or a point without a ready CAI.
     */
    private function fiscalGaps($points): array
    {
        $readyByDrawer = collect($points)
            ->filter(fn ($p) => $p->getAttribute('fiscal_ready'))
            ->keyBy('cash_drawer_id');

        $pointByDrawer = collect($points)
            ->filter(fn ($p) => $p->active && $p->cash_drawer_id)
            ->keyBy('cash_drawer_id');

        return CashDrawer::whereNull('deleted_at')
            ->where('is_active', true)
            ->with(['branch:id,name', 'inventoryLocation:id,name'])
            ->orderBy('branch_id')->orderBy('name')
            ->get(['id', 'branch_id', 'inventory_location_id', 'name', 'code'])
            ->reject(fn ($drawer) => $readyByDrawer->has($drawer->id))
            ->map(function ($drawer) use ($pointByDrawer) {
                $point = $pointByDrawer->get($drawer->id);
                return [
                    'cash_drawer_id' => $drawer->id,
                    'cash_drawer_name' => $drawer->name,
                    'cash_drawer_code' => $drawer->code,
                    'branch_name' => optional($drawer->branch)->name,
                    'inventory_location_name' => optional($drawer->inventoryLocation)->name,
                    'reason' => $point ? 'sin_cai_activo' : 'sin_punto_sar',
                ];
            })
            ->values()
            ->all();
    }
}
