<?php

namespace App\Http\Requests\Mobile;

use Illuminate\Contracts\Validation\Validator;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Http\Exceptions\HttpResponseException;

class MobilePosSaleSubmitRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    protected function prepareForValidation(): void
    {
        $lines = collect((array) $this->input('lines', []))
            ->map(function ($line) {
                if (! is_array($line)) return $line;
                if (isset($line['quantity'])) {
                    $line['quantity'] = trim((string) $line['quantity']);
                }
                return $line;
            })
            ->all();

        $payments = collect((array) $this->input('payments', []))
            ->map(function ($payment) {
                if (! is_array($payment)) return $payment;
                if (isset($payment['amount'])) {
                    $payment['amount'] = trim((string) $payment['amount']);
                }
                return $payment;
            })
            ->all();

        if ($this->has('sale_uuid') && is_string($this->input('sale_uuid'))) {
            $this->merge(['sale_uuid' => trim((string) $this->input('sale_uuid'))]);
        }

        $this->merge([
            'lines' => $lines,
            'payments' => $payments,
        ]);
    }

    public function rules(): array
    {
        return [
            'sale_uuid' => ['required', 'string', 'regex:/^[0-9a-f]{8}-[0-9a-f]{4}-4[0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}$/i'],
            'client_id' => ['required', 'integer'],
            'notes' => ['nullable', 'string', 'max:2000'],

            'Unit_price' => ['prohibited'],
            'unit_price' => ['prohibited'],
            'subtotal' => ['prohibited'],
            'GrandTotal' => ['prohibited'],
            'TaxNet' => ['prohibited'],
            'tax_percent' => ['prohibited'],
            'warehouse_id' => ['prohibited'],
            'branch_id' => ['prohibited'],
            'inventory_location_id' => ['prohibited'],
            'cash_drawer_id' => ['prohibited'],
            'stock' => ['prohibited'],
            'promotion_discount' => ['prohibited'],
            'earned_points' => ['prohibited'],
            'fiscal_number' => ['prohibited'],
            'used_points' => ['prohibited'],
            'discount_from_points' => ['prohibited'],
            'store_credit_vouchers' => ['prohibited'],
            'promotion_code' => ['prohibited'],
            'discount' => ['prohibited'],
            'shipping' => ['prohibited'],

            'lines' => ['required', 'array', 'min:1', 'max:100'],
            'lines.*.product_id' => ['required', 'integer'],
            'lines.*.product_variant_id' => ['nullable', 'integer'],
            'lines.*.quantity' => ['required', 'string', 'regex:/^\d+(\.\d{1,3})?$/'],
            'lines.*.product_pack_id' => ['prohibited'],
            'lines.*.Unit_price' => ['prohibited'],
            'lines.*.unit_price' => ['prohibited'],
            'lines.*.subtotal' => ['prohibited'],
            'lines.*.GrandTotal' => ['prohibited'],
            'lines.*.TaxNet' => ['prohibited'],
            'lines.*.tax_percent' => ['prohibited'],

            'payments' => ['required', 'array', 'min:1', 'max:20'],
            'payments.*.payment_method_id' => ['required', 'integer'],
            'payments.*.amount' => ['required', 'string', 'regex:/^\d+(\.\d{1,2})?$/'],
            'payments.*.account_id' => ['nullable', 'integer'],
            'payments.*.payment_method_id_stripe' => ['prohibited'],
            'payments.*.card_token' => ['prohibited'],
            'payments.*.token' => ['prohibited'],
            'payments.*.pan' => ['prohibited'],
            'payments.*.cvv' => ['prohibited'],
        ];
    }

    protected function failedValidation(Validator $validator): void
    {
        throw new HttpResponseException(response()->json([
            'error' => [
                'code' => $this->errorCode($validator),
                'message' => 'La solicitud no es valida.',
                'details' => $validator->errors()->toArray(),
            ],
        ], 422));
    }

    private function errorCode(Validator $validator): string
    {
        $errors = $validator->errors();

        if ($errors->has('sale_uuid')) {
            return $this->filled('sale_uuid') ? 'sale_uuid_invalid' : 'sale_uuid_required';
        }

        if ($errors->has('client_id')) {
            return 'invalid_client';
        }

        foreach ($errors->keys() as $key) {
            if (str_contains($key, '.quantity')) return 'invalid_quantity';
            if (str_contains($key, 'payments')) return 'payment_total_invalid';
        }

        return 'validation_error';
    }
}
