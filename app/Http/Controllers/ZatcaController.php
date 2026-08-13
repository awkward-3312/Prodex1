<?php

namespace App\Http\Controllers;

use App\Models\Sale;
use App\Models\SaleReturn;
use App\Models\Setting;
use App\Models\ZatcaDocument;
use App\Models\ZatcaLog;
use App\Models\ZatcaSetting;
use App\Services\Zatca\ZatcaService;
use Illuminate\Http\Request;

class ZatcaController extends Controller
{
    public function __construct(protected ZatcaService $service)
    {
    }

    /**
     * GET api/zatca/settings — settings + onboarding state.
     */
    public function settings(Request $request)
    {
        $this->authorizeForUser($request->user('api'), 'zatca_settings', Sale::class);

        $zatca = ZatcaSetting::current();
        if (! $zatca) {
            return response()->json(['message' => 'ZATCA tables are not migrated for this tenant.'], 409);
        }

        $setting = Setting::where('deleted_at', '=', null)->first();

        return response()->json([
            'settings' => $this->publicSettings($zatca),
            'company' => [
                'name' => $setting->CompanyName ?? null,
                'name_ar' => $setting->company_name_ar ?? null,
                'vat_number' => $setting->vat_number ?? null,
            ],
            'environments' => collect(config('zatca.environments'))
                ->map(fn ($env, $key) => ['value' => $key, 'label' => $env['label']])
                ->values(),
        ]);
    }

    /**
     * POST api/zatca/settings — save configuration.
     */
    public function saveSettings(Request $request)
    {
        $this->authorizeForUser($request->user('api'), 'zatca_settings', Sale::class);

        $zatca = ZatcaSetting::current();
        if (! $zatca) {
            return response()->json(['message' => 'ZATCA tables are not migrated for this tenant.'], 409);
        }

        $data = $request->validate([
            'enabled' => 'boolean',
            'auto_submit' => 'boolean',
            'environment' => 'in:sandbox,simulation,production',
            'common_name' => 'nullable|string|max:190',
            'organization_name' => 'nullable|string|max:190',
            'organization_unit' => 'nullable|string|max:190',
            'solution_name' => 'nullable|string|max:190',
            'solution_version' => 'nullable|string|max:50',
            'business_category' => 'nullable|string|max:190',
            'invoice_types' => 'nullable|regex:/^[01]{4}$/',
            'crn' => 'nullable|string|max:190',
            'street_name' => 'nullable|string|max:190',
            'building_number' => 'nullable|digits:4',
            'plot_identification' => 'nullable|string|max:10',
            'sub_division' => 'nullable|string|max:190',
            'city' => 'nullable|string|max:190',
            'postal_zone' => 'nullable|digits:5',
            'zero_tax_reason_code' => 'nullable|string|max:50',
            'zero_tax_reason' => 'nullable|string|max:190',
        ]);

        // Changing environment invalidates issued credentials.
        if (isset($data['environment']) && $data['environment'] !== $zatca->environment) {
            $zatca->fill([
                'csr' => null, 'private_key' => null, 'compliance_request_id' => null,
                'ccsid_token' => null, 'ccsid_secret' => null,
                'pcsid_token' => null, 'pcsid_secret' => null,
                'onboarding_status' => 'not_started', 'compliance_results' => null,
                'icv' => 0, 'last_invoice_hash' => null,
            ]);
        }

        $zatca->fill($data)->save();

        return response()->json(['success' => true, 'settings' => $this->publicSettings($zatca->fresh())]);
    }

    /**
     * POST api/zatca/onboard {otp} — full onboarding flow.
     */
    public function onboard(Request $request)
    {
        $this->authorizeForUser($request->user('api'), 'zatca_settings', Sale::class);

        $request->validate(['otp' => 'required|string|min:4|max:12']);

        $zatca = ZatcaSetting::current();
        if (! $zatca) {
            return response()->json(['message' => 'ZATCA tables are not migrated for this tenant.'], 409);
        }

        try {
            $result = $this->service->onboard($zatca, $request->otp);
        } catch (\Throwable $e) {
            return response()->json(['success' => false, 'message' => $e->getMessage()], 422);
        }

        return response()->json([
            'success' => $result['status'] === 'ready',
            'status' => $result['status'],
            'compliance_checks' => $result['compliance_checks'],
            'settings' => $this->publicSettings($zatca->fresh()),
        ]);
    }

    /**
     * POST api/zatca/csr/regenerate — new key pair + CSR (restarts onboarding).
     */
    public function regenerateCsr(Request $request)
    {
        $this->authorizeForUser($request->user('api'), 'zatca_settings', Sale::class);

        $zatca = ZatcaSetting::current();
        if (! $zatca) {
            return response()->json(['message' => 'ZATCA tables are not migrated for this tenant.'], 409);
        }

        try {
            $this->service->generateCsr($zatca);
        } catch (\Throwable $e) {
            return response()->json(['success' => false, 'message' => $e->getMessage()], 422);
        }

        return response()->json(['success' => true, 'settings' => $this->publicSettings($zatca->fresh())]);
    }

    /**
     * GET api/zatca/documents — submission history.
     */
    public function documents(Request $request)
    {
        $this->authorizeForUser($request->user('api'), 'zatca_settings', Sale::class);

        $query = ZatcaDocument::query()->orderBy('id', 'desc');
        if ($request->filled('status')) {
            $query->where('status', $request->status);
        }

        $documents = $query->paginate((int) $request->get('limit', 20));
        $documents->getCollection()->transform(function (ZatcaDocument $doc) {
            return [
                'id' => $doc->id,
                'invoice_number' => $doc->invoice_number,
                'source' => class_basename($doc->source_type).'#'.$doc->source_id,
                'source_kind' => class_basename($doc->source_type) === 'SaleReturn' ? 'sale_returns' : 'sales',
                'source_id' => $doc->source_id,
                'type' => $doc->invoice_type_code,
                'subtype' => $doc->invoice_subtype,
                'uuid' => $doc->uuid,
                'icv' => $doc->icv,
                'status' => $doc->status,
                'environment' => $doc->environment,
                'warnings' => $doc->warnings,
                'errors' => $doc->errors,
                'submitted_at' => optional($doc->submitted_at)->toDateTimeString(),
            ];
        });

        return response()->json($documents);
    }

    /**
     * GET api/zatca/documents/{id}/xml — download the (cleared) XML.
     */
    public function downloadXml(Request $request, $id)
    {
        $this->authorizeForUser($request->user('api'), 'zatca_settings', Sale::class);

        $doc = ZatcaDocument::findOrFail($id);
        $xml = $doc->cleared_xml ?: $doc->invoice_xml;

        return response($xml, 200, [
            'Content-Type' => 'application/xml',
            'Content-Disposition' => 'attachment; filename="'.($doc->invoice_number ?: 'invoice').'-'.$doc->uuid.'.xml"',
        ]);
    }

    /**
     * POST api/zatca/sales/{id}/submit — submit or retry a sale.
     */
    public function submitSale(Request $request, $id)
    {
        return $this->submitSource($request, Sale::class, (int) $id);
    }

    /**
     * POST api/zatca/sale_returns/{id}/submit — submit or retry a return
     * (credit note).
     */
    public function submitSaleReturn(Request $request, $id)
    {
        return $this->submitSource($request, SaleReturn::class, (int) $id);
    }

    /**
     * GET api/zatca/logs — recent gateway calls (debugging aid).
     */
    public function logs(Request $request)
    {
        $this->authorizeForUser($request->user('api'), 'zatca_settings', Sale::class);

        return response()->json(
            ZatcaLog::query()->orderBy('id', 'desc')->limit((int) $request->get('limit', 50))->get()
        );
    }

    // ------------------------------------------------------------------

    protected function submitSource(Request $request, string $class, int $id)
    {
        // Submitting to the Fatoora gateway is a state-changing, government-
        // facing action: gate it like the rest of the ZATCA administration.
        $this->authorizeForUser($request->user('api'), 'zatca_settings', Sale::class);

        $source = $class::query()->where('deleted_at', '=', null)->findOrFail($id);

        try {
            $document = $this->service->processDocument($source);
        } catch (\Throwable $e) {
            return response()->json(['success' => false, 'message' => $e->getMessage()], 422);
        }

        return response()->json([
            'success' => $document->isAccepted(),
            'status' => $document->status,
            'qr' => $document->qr_code,
            'warnings' => $document->warnings,
            'errors' => $document->errors,
        ]);
    }

    /**
     * Settings payload without private key / secrets.
     */
    protected function publicSettings(ZatcaSetting $zatca): array
    {
        return [
            'enabled' => (bool) $zatca->enabled,
            'auto_submit' => (bool) $zatca->auto_submit,
            'environment' => $zatca->environment,
            'common_name' => $zatca->common_name,
            'organization_name' => $zatca->organization_name,
            'organization_unit' => $zatca->organization_unit,
            'solution_name' => $zatca->solution_name,
            'solution_version' => $zatca->solution_version,
            'device_serial' => $zatca->device_serial,
            'business_category' => $zatca->business_category,
            'invoice_types' => $zatca->invoice_types,
            'crn' => $zatca->crn,
            'street_name' => $zatca->street_name,
            'building_number' => $zatca->building_number,
            'plot_identification' => $zatca->plot_identification,
            'sub_division' => $zatca->sub_division,
            'city' => $zatca->city,
            'postal_zone' => $zatca->postal_zone,
            'zero_tax_reason_code' => $zatca->zero_tax_reason_code,
            'zero_tax_reason' => $zatca->zero_tax_reason,
            'onboarding_status' => $zatca->onboarding_status,
            'compliance_results' => $zatca->compliance_results,
            'has_csr' => ! empty($zatca->csr),
            'has_production_csid' => ! empty($zatca->pcsid_token),
            'icv' => (int) $zatca->icv,
        ];
    }
}
