<?php

namespace App\Http\Controllers\Mobile;

use App\Exceptions\Mobile\MobileProductResolveException;
use App\Http\Controllers\Controller;
use App\Http\Requests\Mobile\ResolveMobileProductRequest;
use App\Models\InventoryLocation;
use App\Models\Sale;
use App\Services\InventoryLocationScopeService;
use App\Services\Mobile\MobileProductCodeResolver;

class MobileProductResolveController extends Controller
{
    public function __invoke(
        ResolveMobileProductRequest $request,
        MobileProductCodeResolver $resolver,
        InventoryLocationScopeService $locationScope
    ) {
        $user = $request->user('api');
        abort_unless($user, 401);
        $this->authorizeForUser($user, 'Sales_pos', Sale::class);

        $locationId = (int) $request->validated('inventory_location_id');

        $location = InventoryLocation::active()
            ->where('is_sellable', true)
            ->find($locationId);

        if (! $location) {
            return $this->error('invalid_location', 422);
        }

        if (! $locationScope->canAccess($user, $locationId)) {
            return $this->error('forbidden_location', 403);
        }

        try {
            $data = $resolver->resolve(
                (string) $request->validated('value'),
                $locationId,
                $request->validated('scanner_type')
            );
        } catch (MobileProductResolveException $e) {
            return $this->error($e->errorCode(), $e->statusCode());
        }

        return response()->json(['data' => $data]);
    }

    private function error(string $code, int $status)
    {
        return response()->json([
            'error' => [
                'code' => $code,
            ],
        ], $status);
    }
}
