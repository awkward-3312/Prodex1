<?php
namespace App\Services\Mobile;

use App\Exceptions\Mobile\MobilePosPreflightException;
use App\Http\Resources\Mobile\MobileClientResource;
use App\Models\Client;
use App\Models\EcommerceClient;
use App\Models\User;
use App\Services\ClientMaintenanceService;
use Illuminate\Database\QueryException;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class MobileClientWriteService
{
    public function __construct(private ClientMaintenanceService $maintenance) {}

    public function write(User $user, Request $input, ?int $id): array
    {
        $allowed = array_merge(ClientMaintenanceService::FIELDS, $id === null ? ['operation_uuid'] : []);
        $unknown = array_diff(array_keys($input->all()), $allowed);
        if ($unknown) throw ValidationException::withMessages(array_fill_keys($unknown, 'Este campo no se puede modificar desde Mobile.'));
        // Boundary types/lengths before country normalization; domain validation stays shared with web.
        $rules = [];
        foreach (ClientMaintenanceService::FIELDS as $field) $rules[$field] = ['nullable', 'string', 'max:255'];
        $rules['name'] = ['required', 'string', 'max:255'];
        if ($id === null) $rules['operation_uuid'] = ['required', 'uuid'];
        $input->validate($rules);
        $payload = [];
        foreach (ClientMaintenanceService::FIELDS as $field) $payload[$field] = $input->input($field) === null ? null : trim($input->input($field));
        $request = new Request($payload);
        $uuid = strtolower((string) $input->input('operation_uuid'));
        $fingerprint = hash('sha256', json_encode($request->only(ClientMaintenanceService::FIELDS)));

        try {
            return DB::transaction(function () use ($user, $request, $id, $uuid, $fingerprint) {
                if ($id === null) {
                    // Serialize same-operator creates; unique UUID also protects cross-operator races.
                    User::whereKey($user->id)->lockForUpdate()->firstOrFail();
                    if ($result = $this->existing($uuid, $fingerprint, $user)) return $result;
                }
                $client = $id === null ? null : Client::whereNull('deleted_at')->lockForUpdate()->find($id);
                if ($id !== null && ! $client) throw new MobilePosPreflightException('customer_not_found', 404);
                $this->maintenance->normalizeClientFiscalInput($request, $this->maintenance->resolveTenantTaxConfig());
                $this->maintenance->validateClientFiscalInput($request, $this->maintenance->resolveTenantTaxConfig());
                if ($id === null) {
                    $this->maintenance->validateCreate($request);
                    // Same maintenance limits as web edit, in addition to web creation uniqueness.
                    $this->maintenance->validateUpdate($request, null);
                    $client = $this->maintenance->create($request);
                    DB::table('mobile_customer_operations')->insert([
                        'operation_uuid' => $uuid, 'user_id' => $user->id, 'client_id' => $client->id,
                        'payload_fingerprint' => $fingerprint, 'created_at' => now(), 'updated_at' => now(),
                    ]);
                } else {
                    $this->maintenance->validateUpdate($request, $id);
                    $this->maintenance->updateMaintenance($client, $request);
                    // Keep existing linked web account email synchronized; never create credentials here.
                    EcommerceClient::where('client_id', $id)->whereNull('deleted_at')->update(['email' => $client->email]);
                }
                return ['data' => MobileClientResource::data($client->refresh()), 'idempotent' => false];
            }, 3);
        } catch (QueryException $error) {
            if ($id === null && in_array((string) $error->getCode(), ['23000', '23505'], true)) {
                if ($result = $this->existing($uuid, $fingerprint, $user)) return $result;
            }
            throw $error;
        }
    }

    private function existing(string $uuid, string $fingerprint, User $user): ?array
    {
        $operation = DB::table('mobile_customer_operations')->where('operation_uuid', $uuid)->first();
        if (! $operation) return null;
        if ((int) $operation->user_id !== (int) $user->id || ! hash_equals($operation->payload_fingerprint, $fingerprint)) throw new MobilePosPreflightException('idempotency_conflict', 409);
        $client = Client::whereNull('deleted_at')->find($operation->client_id);
        if (! $client) throw new MobilePosPreflightException('customer_not_found', 404);
        return ['data' => MobileClientResource::data($client), 'idempotent' => true];
    }
}
