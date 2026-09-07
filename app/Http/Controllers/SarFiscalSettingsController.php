<?php

namespace App\Http\Controllers;

use App\Models\Branch;
use App\Models\CashDrawer;
use App\Models\Client;
use App\Models\InventoryLocation;
use App\Models\Product;
use App\Models\SarAuthorization;
use App\Models\SarBranchSetting;
use App\Models\SarFiscalProfile;
use App\Models\SarPointOfIssue;
use App\Models\Setting;
use App\Models\Warehouse;
use App\Services\SarBranchFiscalService;
use App\Services\SarFiscalNumberService;
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

        if ($profile && $profile->enabled) {
            app(SarBranchFiscalService::class)->syncAllActiveBranches();
            SarAuthorization::reconcileTerminalStatuses();
        }

        $points = SarPointOfIssue::with([
            'authorizations' => fn ($query) => $query->orderByDesc('id'),
            'branch:id,name,code',
            'inventoryLocation:id,branch_id,name,type,is_sellable',
            'cashDrawer:id,branch_id,inventory_location_id,name,code,is_active',
            'cashDrawers:id,branch_id,inventory_location_id,name,code',
        ])->orderBy('establishment_code')->orderBy('point_code')->get()
            ->map(function (SarPointOfIssue $point) {
                $active = $this->readyAuthorization($point);
                // A PRODEX-managed point covers its drawers through the pivot; a
                // legacy point still through the cash_drawer_id column.
                $coversAnyDrawer = $point->cash_drawer_id || $point->cashDrawers->isNotEmpty();
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
                    && $point->branch_id && $coversAnyDrawer));
                return $point;
            });

        return response()->json([
            'profile' => $profile,
            'branch_cards' => $this->branchCards(),
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

        // Enabling the fiscal profile makes every active branch appear in
        // Facturación SAR (disabled until the admin enables + configures each).
        if ($profile->enabled) {
            app(SarBranchFiscalService::class)->syncAllActiveBranches();
        }

        return response()->json([
            'success' => true,
            'profile' => $profile->fresh(),
            'branch_cards' => $this->branchCards(),
        ]);
    }

    // ---------------------------------------------------------------------------
    //  Per-branch fiscal configuration (PRODEX manages sar_points_of_issue)
    // ---------------------------------------------------------------------------

    public function toggleBranch(Request $request, int $branch)
    {
        $this->authorizeSettings($request);
        $data = $request->validate(['enabled' => ['required', 'boolean']]);

        $branchModel = Branch::whereNull('deleted_at')->findOrFail($branch);
        if ($data['enabled'] && ! (int) ($branchModel->is_active ?? 1)) {
            return response()->json(['message' => 'No puedes habilitar la facturación SAR de una sucursal inactiva.'], 422);
        }

        app(SarBranchFiscalService::class)->setBranchEnabled($branch, (bool) $data['enabled']);

        return response()->json(['success' => true, 'branch_cards' => $this->branchCards()]);
    }

    public function saveBranchPoint(Request $request, int $branch)
    {
        $this->authorizeSettings($request);

        $data = $request->validate([
            'establishment_code' => ['required', 'regex:/^\d{3}$/'],
            'point_code' => ['required', 'regex:/^\d{3}$/'],
        ]);

        $branchModel = Branch::whereNull('deleted_at')->findOrFail($branch);
        $point = app(SarBranchFiscalService::class)->syncBranch($branchModel);

        // establishment+point codes are unique across the tenant.
        $clash = SarPointOfIssue::where('establishment_code', $data['establishment_code'])
            ->where('point_code', $data['point_code'])
            ->where('id', '<>', $point->id)
            ->exists();
        if ($clash) {
            return response()->json(['message' => 'Ese establecimiento y punto ya están en uso por otra sucursal.'], 422);
        }

        $point->establishment_code = $data['establishment_code'];
        $point->point_code = $data['point_code'];
        $point->save();

        // Re-run sync so "active" flips once codes + drawers are present.
        app(SarBranchFiscalService::class)->syncBranch($branchModel);

        return response()->json(['success' => true, 'branch_cards' => $this->branchCards()]);
    }

    public function saveBranchDrawers(Request $request, int $branch)
    {
        $this->authorizeSettings($request);

        $data = $request->validate([
            'cash_drawer_ids' => ['present', 'array'],
            'cash_drawer_ids.*' => ['integer'],
        ]);

        $branchModel = Branch::whereNull('deleted_at')->findOrFail($branch);
        $point = app(SarBranchFiscalService::class)->syncBranch($branchModel);

        $branchDrawerIds = CashDrawer::whereNull('deleted_at')
            ->where('is_active', true)
            ->where('branch_id', $branch)
            ->pluck('id')
            ->all();

        $selected = array_values(array_intersect(
            array_map('intval', $data['cash_drawer_ids']),
            $branchDrawerIds
        ));

        DB::transaction(function () use ($point, $selected, $branchDrawerIds) {
            // drop coverage for this branch's drawers that were unchecked
            DB::table('sar_point_cash_drawers')
                ->where('sar_point_of_issue_id', $point->id)
                ->whereIn('cash_drawer_id', array_diff($branchDrawerIds, $selected) ?: [0])
                ->delete();

            foreach ($selected as $drawerId) {
                $claimedElsewhere = DB::table('sar_point_cash_drawers')
                    ->where('cash_drawer_id', $drawerId)
                    ->where('sar_point_of_issue_id', '<>', $point->id)
                    ->exists();
                if ($claimedElsewhere) {
                    continue;
                }
                DB::table('sar_point_cash_drawers')->updateOrInsert(
                    ['cash_drawer_id' => $drawerId],
                    ['sar_point_of_issue_id' => $point->id, 'updated_at' => now(), 'created_at' => now()]
                );
            }
        });

        app(SarBranchFiscalService::class)->syncBranch($branchModel);

        return response()->json(['success' => true, 'branch_cards' => $this->branchCards()]);
    }

    /**
     * One card per active branch. The tenant never sees "sar_points_of_issue" —
     * only its own establishment/point codes, CAI data and status.
     */
    private function branchCards(): array
    {
        $profileEnabled = (bool) optional(SarFiscalProfile::first())->enabled;

        $settings = SarBranchSetting::get()->keyBy('branch_id');

        $points = SarPointOfIssue::with([
            'authorizations' => fn ($q) => $q->orderByDesc('id'),
            'cashDrawers:id,branch_id,inventory_location_id,name,code',
        ])->whereNotNull('branch_id')->get()->groupBy('branch_id');

        $drawersByBranch = CashDrawer::whereNull('deleted_at')
            ->where('is_active', true)
            ->orderBy('name')
            ->get(['id', 'branch_id', 'inventory_location_id', 'name', 'code'])
            ->groupBy('branch_id');

        return Branch::whereNull('deleted_at')
            ->where('is_active', true)
            ->orderBy('name')
            ->get(['id', 'name', 'code'])
            ->map(function (Branch $branch) use ($profileEnabled, $settings, $points, $drawersByBranch) {
                $enabled = $profileEnabled && (bool) optional($settings->get($branch->id))->enabled;

                /** @var SarPointOfIssue|null $point */
                $branchPoints = $points->get($branch->id);
                $point = $branchPoints
                    ? $branchPoints->sortByDesc('is_auto_managed')->first()
                    : null;

                $series = $this->seriesPayload($point);
                $current = $series['current'];
                $next = $series['next'];
                $latestAuth = $point ? $point->authorizations->where('document_type', '01')->sortByDesc('id')->first() : null;

                $availableDrawers = ($drawersByBranch->get($branch->id) ?? collect())
                    ->map(fn ($d) => ['id' => $d->id, 'name' => $d->name, 'code' => $d->code])
                    ->values()->all();
                $coveredDrawerIds = $point
                    ? $point->cashDrawers->pluck('id')->map('intval')->all()
                    : [];

                $errors = [];
                if ($enabled) {
                    if (! $point || ! $point->hasCodes()) {
                        $errors[] = 'Falta el código de establecimiento y de punto de emisión autorizados por el SAR.';
                    }
                    if (empty($coveredDrawerIds)) {
                        $errors[] = empty($availableDrawers)
                            ? 'La sucursal no tiene cajas físicas activas.'
                            : 'Ninguna caja física está asignada a la serie fiscal.';
                    }
                    if ($point && $point->hasCodes() && ! $latestAuth) {
                        $errors[] = 'Falta registrar el CAI y el rango autorizados.';
                    }
                    if ($latestAuth && ! ($current && $current['is_ready'])) {
                        if ($next && $next['status'] === 'prepared') {
                            // The active CAI is spent but the next one is ready to take over.
                        } else {
                            $errors[] = $this->authIssue($latestAuth);
                        }
                    }
                }

                $ready = empty($errors)
                    && (($current && $current['is_ready'])
                        || ($next && $next['status'] === 'prepared' && $next['is_ready'] ?? false));

                $status = ! $enabled ? 'disabled' : ($ready ? 'ready' : 'pending');

                return [
                    'branch_id' => $branch->id,
                    'branch_name' => $branch->name,
                    'branch_code' => $branch->code,
                    'sar_enabled' => $enabled,
                    'establishment_code' => $series['establishment_code'],
                    'point_code' => $series['point_code'],
                    'serie_label' => $series['serie_label'],
                    'has_codes' => $series['has_codes'],
                    'available_drawers' => $availableDrawers,
                    'covered_drawer_ids' => $coveredDrawerIds,
                    'series' => $series,
                    // Back-compat alias: the current authorisation, flat.
                    'authorization' => $current,
                    'status' => $status,
                    'errors' => $errors,
                ];
            })
            ->values()
            ->all();
    }

    private function authIssue(SarAuthorization $a): string
    {
        if ($a->status !== 'active') {
            return 'El CAI registrado todavía no está activado.';
        }
        if ($a->deadline && Carbon::parse($a->deadline)->lt(Carbon::today())) {
            return 'El CAI está vencido: registra una autorización vigente.';
        }
        return 'El rango del CAI está agotado: registra un nuevo rango.';
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
            'point_of_issue_id' => ['nullable', 'integer', 'exists:sar_points_of_issue,id'],
            'branch_id' => ['nullable', 'integer', 'exists:branches,id'],
            'document_type' => ['nullable', 'regex:/^\d{2}$/'],
            'role' => ['nullable', Rule::in(['current', 'next'])],
            'cai' => ['required', 'string', 'max:64'],
            'range_start' => ['required', 'integer', 'min:1', 'max:99999999'],
            'range_end' => ['required', 'integer', 'gte:range_start', 'max:99999999'],
            'next_number' => ['required', 'integer', 'gte:range_start', 'lte:range_end'],
            'authorization_date' => ['nullable', 'date'],
            'deadline' => ['required', 'date'],
        ]);

        $role = $data['role'] ?? 'current';
        unset($data['role']);

        // The tenant works per branch; PRODEX maps it to the managed series.
        if (empty($data['point_of_issue_id'])) {
            if (empty($data['branch_id'])) {
                return response()->json(['message' => 'Indica la sucursal para la que registras el CAI.'], 422);
            }
            $branchModel = Branch::whereNull('deleted_at')->findOrFail((int) $data['branch_id']);
            $point = app(SarBranchFiscalService::class)->syncBranch($branchModel);
            $data['point_of_issue_id'] = $point->id;
        }
        unset($data['branch_id']);
        $data['document_type'] = $data['document_type'] ?? '01';

        $overlaps = SarAuthorization::where('point_of_issue_id', $data['point_of_issue_id'])
            ->where('document_type', $data['document_type'])
            ->whereNotIn('status', ['disabled', 'expired'])
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

        if (Carbon::parse($data['deadline'])->lt(Carbon::today())) {
            return response()->json(['message' => 'La fecha límite ya venció.'], 422);
        }

        $data['cai'] = strtoupper(trim($data['cai']));

        if ($role === 'next') {
            // A prepared "next" authorisation: PRODEX switches to it automatically
            // and atomically when the current one runs out.
            $existingPrepared = SarAuthorization::where('point_of_issue_id', $data['point_of_issue_id'])
                ->where('document_type', $data['document_type'])
                ->where('status', 'prepared')
                ->first();
            if ($existingPrepared) {
                return response()->json([
                    'message' => 'Esta serie ya tiene una autorización siguiente preparada. Elimínala o edítala antes de registrar otra.',
                ], 422);
            }

            $active = SarAuthorization::where('point_of_issue_id', $data['point_of_issue_id'])
                ->where('document_type', $data['document_type'])
                ->where('status', 'active')
                ->first();
            if ($active && (int) $data['range_start'] <= (int) $active->range_end) {
                return response()->json([
                    'message' => 'El rango de la autorización siguiente debe empezar después del rango actual ('
                        .number_format((int) $active->range_end).').',
                ], 422);
            }

            // A not-yet-used range: force the counter to its start.
            $data['next_number'] = $data['range_start'];
            $data['status'] = 'prepared';
        } else {
            $data['status'] = 'draft';
        }

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
            return response()->json(['message' => 'La serie fiscal debe estar activa.'], 422);
        }

        DB::transaction(function () use ($authorization) {
            SarAuthorization::where('point_of_issue_id', $authorization->point_of_issue_id)
                ->where('document_type', $authorization->document_type)
                ->where('status', 'active')
                ->where('id', '<>', $authorization->id)
                ->update(['status' => 'disabled', 'superseded_by_id' => $authorization->id]);

            $authorization->update(['status' => 'active', 'activated_at' => now()]);
        });

        return response()->json(['success' => true, 'authorization' => $authorization->fresh()]);
    }

    /**
     * Remove a not-yet-used authorisation (draft or prepared "next"). An
     * authorisation that has already issued a fiscal number can never be deleted.
     */
    public function destroyAuthorization(Request $request, SarAuthorization $authorization)
    {
        $this->authorizeSettings($request);

        if (! in_array($authorization->status, ['draft', 'prepared'], true)) {
            return response()->json([
                'message' => 'Solo puedes eliminar una autorización en borrador o preparada que aún no ha facturado.',
            ], 422);
        }
        if ($authorization->documents()->exists()) {
            return response()->json(['message' => 'Esta autorización ya emitió documentos fiscales y no puede eliminarse.'], 422);
        }

        $authorization->delete();

        return response()->json(['success' => true]);
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
     * The authorization that is genuinely ready to invoice for a series:
     *   status = active  AND  deadline >= today  AND  next_number in [range_start, range_end].
     * Any failing condition means "not ready" — the UI must reflect that.
     */
    private function readyAuthorization(SarPointOfIssue $point): ?SarAuthorization
    {
        return $point->authorizations
            ->where('document_type', '01')
            ->first(fn (SarAuthorization $a) => $a->status === 'active' && $a->isUsableNow());
    }

    /** The registered "next" authorization of a series, if any (draft or prepared). */
    private function nextAuthorization(SarPointOfIssue $point): ?SarAuthorization
    {
        return $point->authorizations
            ->where('document_type', '01')
            ->whereIn('status', ['prepared', 'draft'])
            ->sortBy('range_start')
            ->first();
    }

    /**
     * The full fiscal-series card payload: the tenant reads authorisations and
     * series, never "technical points".
     */
    private function seriesPayload(?SarPointOfIssue $point): array
    {
        if (! $point) {
            return [
                'has_codes' => false,
                'serie_label' => null,
                'establishment_code' => null,
                'point_code' => null,
                'document_type' => '01',
                'current' => null,
                'next' => null,
                'health' => 'unconfigured',
            ];
        }

        $current = $this->readyAuthorization($point)
            ?? $point->authorizations->where('document_type', '01')->firstWhere('status', 'active')
            ?? $point->authorizations->where('document_type', '01')->sortByDesc('id')->first();
        $next = $this->nextAuthorization($point);

        $shape = function (?SarAuthorization $a) {
            if (! $a) {
                return null;
            }

            return [
                'id' => $a->id,
                'cai' => $a->cai,
                'status' => $a->status,
                'document_type' => $a->document_type,
                'range_start' => (int) $a->range_start,
                'range_end' => (int) $a->range_end,
                'next_number' => (int) $a->next_number,
                'last_used' => max(0, (int) $a->next_number - 1) >= (int) $a->range_start
                    ? (int) $a->next_number - 1
                    : null,
                'remaining' => $a->remaining(),
                'total_range' => (int) $a->range_end - (int) $a->range_start + 1,
                'deadline' => optional($a->deadline)->toDateString(),
                'authorization_date' => optional($a->authorization_date)->toDateString(),
                'is_ready' => $a->status === 'active' && $a->isUsableNow(),
                'health' => $a->healthState(SarFiscalNumberService::LOW_RANGE_THRESHOLD),
            ];
        };

        $currentShape = $shape($current);
        $health = $currentShape['health'] ?? ($point->hasCodes() ? 'no_authorization' : 'unconfigured');
        if (($health === 'exhausted' || $health === 'expired') && $next && $next->isUsableNow()) {
            $health = 'next_ready';
        }

        return [
            'has_codes' => $point->hasCodes(),
            'serie_label' => $point->hasCodes()
                ? $point->establishment_code.'-'.$point->point_code.'-01'
                : null,
            'establishment_code' => $point->establishment_code,
            'point_code' => $point->point_code,
            'document_type' => '01',
            'current' => $currentShape,
            'next' => $shape($next),
            'health' => $health,
        ];
    }

    /**
     * Cash drawers that a cashier could pick at the POS but that have no fiscal
     * identity yet: no active SAR point, or a point without a ready CAI.
     */
    private function fiscalGaps($points): array
    {
        $readyByDrawer = collect();
        $pointByDrawer = collect();
        foreach ($points as $p) {
            $drawerIds = collect([$p->cash_drawer_id])
                ->merge($p->relationLoaded('cashDrawers') ? $p->cashDrawers->pluck('id') : [])
                ->filter()->unique();
            foreach ($drawerIds as $id) {
                if ($p->getAttribute('fiscal_ready')) {
                    $readyByDrawer->put($id, $p);
                }
                if ($p->active) {
                    $pointByDrawer->put($id, $p);
                }
            }
        }

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
