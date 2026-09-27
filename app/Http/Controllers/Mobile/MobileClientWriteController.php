<?php
namespace App\Http\Controllers\Mobile;

use App\Http\Controllers\Controller;
use App\Http\Resources\Mobile\MobileClientResource;
use App\Models\Client;
use App\Services\ClientMaintenanceService;
use App\Services\Mobile\MobileClientWriteService;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;
use App\Exceptions\Mobile\MobilePosPreflightException;

class MobileClientWriteController extends Controller
{
    public function configuration(Request $request, ClientMaintenanceService $maintenance)
    {
        $user = $request->user('api');
        abort_unless($user, 401);
        abort_unless($user->can('create', Client::class) || $user->can('update', Client::class), 403);
        $config = $maintenance->resolveTenantTaxConfig();
        return response()->json(['data' => ['tax_number_label' => $config['customer_tax_id_label'] ?? 'Identificación fiscal', 'country_code' => $config['country_code'] ?? null]]);
    }

    public function edit(Request $request, $id)
    {
        $this->authorizeForUser($request->user('api'), 'update', Client::class);
        $client = Client::whereNull('deleted_at')->find($id);
        if (! $client) return response()->json(['error' => ['code' => 'customer_not_found']], 404);
        return response()->json(['data' => MobileClientResource::data($client)]);
    }

    public function store(Request $request, MobileClientWriteService $service)
    {
        return $this->write($request, $service, null);
    }

    public function update(Request $request, MobileClientWriteService $service, $id)
    {
        return $this->write($request, $service, (int) $id);
    }

    private function write(Request $request, MobileClientWriteService $service, ?int $id)
    {
        $user = $request->user('api');
        abort_unless($user, 401);
        $this->authorizeForUser($user, $id === null ? 'create' : 'update', Client::class);
        try {
            return response()->json($service->write($user, $request, $id));
        } catch (ValidationException $error) {
            return response()->json(['error' => ['code' => 'validation_error', 'details' => $error->errors()]], 422);
        } catch (\Illuminate\Database\QueryException $error) {
            return response()->json(['error' => ['code' => 'server_error']], 500);
        } catch (MobilePosPreflightException $error) {
            return response()->json(['error' => ['code' => $error->errorCode()]], $error->statusCode());
        }
    }
}
