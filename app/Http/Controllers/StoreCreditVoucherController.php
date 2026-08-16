<?php

namespace App\Http\Controllers;

use App\Models\Sale;
use App\Models\StoreCreditVoucher;
use App\utils\helpers;
use Carbon\Carbon;
use Illuminate\Http\Request;

class StoreCreditVoucherController extends BaseController
{
    public function validateForPos(Request $request)
    {
        $this->authorizeForUser($request->user('api'), 'Sales_pos', Sale::class);

        $request->validate([
            'code' => 'required|string',
            'client_id' => 'nullable|integer',
        ]);

        $voucher = StoreCreditVoucher::with('client:id,name,code')
            ->where('code', strtoupper(trim($request->code)))
            ->whereNull('deleted_at')
            ->first();

        if (! $voucher) {
            return response()->json(['success' => false, 'message' => 'Vale no encontrado.'], 404);
        }

        $error = $this->validationError($voucher, $request->input('client_id'));
        if ($error) {
            return response()->json(['success' => false, 'message' => $error], 422);
        }

        return response()->json([
            'success' => true,
            'voucher' => [
                'id' => $voucher->id,
                'code' => $voucher->code,
                'client_id' => $voucher->client_id,
                'client_name' => optional($voucher->client)->name,
                'remaining_balance' => number_format((float) $voucher->remaining_balance, helpers::price_decimals(), '.', ''),
                'original_amount' => number_format((float) $voucher->original_amount, helpers::price_decimals(), '.', ''),
                'issued_at' => optional($voucher->issued_at)->format('Y-m-d H:i:s'),
                'expires_at' => optional($voucher->expires_at)->format('Y-m-d H:i:s'),
                'status' => $voucher->status,
            ],
        ]);
    }

    private function validationError(StoreCreditVoucher $voucher, $clientId): ?string
    {
        if (in_array($voucher->status, ['cancelled', 'expired', 'redeemed'], true)) {
            return 'El vale no está disponible para uso.';
        }

        if ($voucher->expires_at && Carbon::parse($voucher->expires_at)->isPast()) {
            if ($voucher->status !== 'expired') {
                $voucher->status = 'expired';
                $voucher->save();
            }
            return 'El vale está vencido.';
        }

        if ((float) $voucher->remaining_balance <= 0) {
            return 'El vale no tiene saldo disponible.';
        }

        if ($voucher->client_id && $clientId && (int) $voucher->client_id !== (int) $clientId) {
            return 'El vale pertenece a otro cliente.';
        }

        return null;
    }
}
