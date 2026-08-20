<?php

namespace App\Http\Controllers;

use App\Models\CashDrawer;
use App\Models\Client;
use App\Models\Product;
use App\Models\SarAuthorization;
use App\Models\SarFiscalProfile;
use App\Models\SarPointOfIssue;
use App\Models\Setting;
use App\Models\Warehouse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
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

        return response()->json([
            'profile' => $profile,
            'points' => SarPointOfIssue::with([
                'authorizations' => fn ($query) => $query->orderByDesc('id'),
            ])->orderBy('establishment_code')->orderBy('point_code')->get(),
            'warehouses' => Warehouse::whereNull('deleted_at')->orderBy('name')->get(['id', 'name']),
            'cash_drawers' => CashDrawer::whereNull('deleted_at')
                ->where('is_active', true)
                ->orderBy('name')
                ->get(['id', 'warehouse_id', 'name', 'code']),
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
            'warehouse_id' => ['nullable', 'integer', 'exists:warehouses,id'],
            'cash_drawer_id' => ['nullable', 'integer', 'exists:cash_drawers,id'],
            'active' => ['required', 'boolean'],
        ]);

        $this->validateDrawerWarehouse($data);

        return response()->json([
            'success' => true,
            'point' => SarPointOfIssue::create($data),
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
            'warehouse_id' => ['nullable', 'integer', 'exists:warehouses,id'],
            'cash_drawer_id' => ['nullable', 'integer', 'exists:cash_drawers,id'],
            'active' => ['required', 'boolean'],
        ]);

        $this->validateDrawerWarehouse($data);
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

    private function validateDrawerWarehouse(array $data): void
    {
        if (empty($data['cash_drawer_id'])) {
            return;
        }

        $drawer = CashDrawer::whereNull('deleted_at')->findOrFail($data['cash_drawer_id']);
        if (! empty($data['warehouse_id']) && (int) $drawer->warehouse_id !== (int) $data['warehouse_id']) {
            abort(422, 'La caja seleccionada no pertenece al almacén indicado.');
        }
    }
}
