<?php
namespace App\Http\Resources\Mobile;

use App\Models\Client;
use App\Services\ClientMaintenanceService;

class MobileClientResource
{
    public static function data(Client $client): array
    {
        $result = ['id' => (int) $client->id, 'code' => (string) $client->code];
        foreach (ClientMaintenanceService::FIELDS as $field) $result[$field] = $client->{$field};
        return $result;
    }
}
