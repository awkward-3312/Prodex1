<?php

namespace App\Http\Controllers;

use App\Services\UserOperationalAssignmentService;
use Illuminate\Http\Request;

class HeaderOperationalContextController extends Controller
{
    public function show(Request $request, UserOperationalAssignmentService $assignments)
    {
        $user = $request->user('api');
        abort_unless($user, 401);

        $context = $assignments->effectiveAssignment($user);
        $branch = $context['branch'] ?? null;
        $location = $context['inventory_location'] ?? null;
        $drawer = $context['cash_drawer'] ?? null;
        $warehouse = $context['warehouse'] ?? null;
        $isOwner = (int) $user->role_id === 1;

        if ($branch) {
            $parts = [(string) $branch->name];
            if ($drawer) {
                $parts[] = (string) $drawer->name;
            } elseif ($location) {
                $parts[] = (string) $location->name;
            }
            $label = implode(' · ', array_filter($parts));
        } elseif ($isOwner) {
            $label = 'Todas las sucursales';
        } elseif ($warehouse) {
            $label = 'Histórico · '.(string) $warehouse->name;
        } else {
            $label = null;
        }

        return response()->json([
            'source' => $context['source'] ?? 'default',
            'temporary' => ($context['source'] ?? null) === 'temporary',
            'is_owner' => $isOwner,
            'branch_id' => $context['branch_id'] ?? null,
            'branch_name' => $branch?->name,
            'inventory_location_id' => $context['inventory_location_id'] ?? null,
            'inventory_location_name' => $location?->name,
            'cash_drawer_id' => $context['cash_drawer_id'] ?? null,
            'cash_drawer_name' => $drawer?->name,
            'warehouse_id' => $context['warehouse_id'] ?? null,
            'warehouse_name' => $warehouse?->name,
            'label' => $label,
        ]);
    }
}
