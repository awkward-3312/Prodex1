<?php

namespace App\Http\Controllers;

use App\Services\Mobile\PosOperationalContextReadService;
use Illuminate\Http\Request;

class PosOperationalContextController extends BaseController
{
    public function show(Request $request)
    {
        $user = $request->user('api');
        abort_unless($user, 401);

        return response()->json(app(PosOperationalContextReadService::class)->forUser($user));
    }
}
