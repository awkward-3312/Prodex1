<?php

namespace App\Http\Controllers\Mobile;

use App\Exceptions\Mobile\MobilePosPreflightException;
use App\Http\Controllers\Controller;
use App\Http\Requests\Mobile\MobileCashRegisterCloseRequest;
use App\Models\Sale;
use App\Services\Mobile\MobileCashRegisterCloseService;

class MobileCashRegisterCloseController extends Controller
{
    public function __invoke(MobileCashRegisterCloseRequest $request, MobileCashRegisterCloseService $service)
    {
        $user = $request->user('api');
        abort_unless($user, 401);
        $this->authorizeForUser($user, 'Sales_pos', Sale::class);
        try {
            return response()->json($service->close($user, $request->validated()));
        } catch (\Illuminate\Validation\ValidationException $error) {
            return response()->json(['error' => ['code' => 'validation_error', 'details' => $error->errors()]], 422);
        } catch (MobilePosPreflightException $error) {
            return response()->json(['error' => ['code' => $error->errorCode(), 'message' => $error->getMessage(), 'details' => $error->details()]], $error->statusCode());
        }
    }
}
