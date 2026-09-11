<?php

namespace App\Http\Controllers\Mobile;

use App\Http\Controllers\Controller;
use App\Http\Requests\Mobile\MobilePosCatalogRequest;
use App\Models\InventoryLocation;
use App\Models\Sale;
use App\Services\InventoryLocationScopeService;
use App\Services\Mobile\MobilePosCatalogService;

class MobilePosCatalogController extends Controller
{
    public function __invoke(
        MobilePosCatalogRequest $request,
        MobilePosCatalogService $catalog,
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

        return response()->json([
            'data' => $catalog->catalog(
                $locationId,
                $request->validated('search'),
                $request->validated('category_id'),
                $request->validated('page'),
                $request->validated('per_page')
            ),
        ]);
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
