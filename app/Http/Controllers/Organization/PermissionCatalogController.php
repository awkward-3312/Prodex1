<?php

namespace App\Http\Controllers\Organization;

use App\Http\Controllers\Controller;
use App\Services\PermissionCatalogService;
use Illuminate\Http\Request;

class PermissionCatalogController extends Controller
{
    public function index(Request $request, PermissionCatalogService $catalog)
    {
        $user = $request->user('api');
        abort_unless($user, 401);
        abort_unless(
            (int) $user->role_id === 1
            || $user->hasPermissionName('permissions_view')
            || $user->hasPermissionName('permissions_add')
            || $user->hasPermissionName('permissions_edit'),
            403
        );

        return response()->json($catalog->catalog());
    }
}
