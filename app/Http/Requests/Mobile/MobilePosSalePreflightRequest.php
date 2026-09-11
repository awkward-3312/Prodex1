<?php

namespace App\Http\Requests\Mobile;

use Illuminate\Contracts\Validation\Validator;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Http\Exceptions\HttpResponseException;

class MobilePosSalePreflightRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    protected function prepareForValidation(): void
    {
        $lines = collect((array) $this->input('lines', []))
            ->map(function ($line) {
                if (! is_array($line)) {
                    return $line;
                }

                if (isset($line['quantity'])) {
                    $line['quantity'] = trim((string) $line['quantity']);
                }

                return $line;
            })
            ->all();

        $payments = collect((array) $this->input('payment_intent', []))
            ->map(function ($payment) {
                if (! is_array($payment)) {
                    return $payment;
                }

                if (isset($payment['amount'])) {
                    $payment['amount'] = trim((string) $payment['amount']);
                }

                return $payment;
            })
            ->all();

        $this->merge([
            'lines' => $lines,
            'payment_intent' => $payments,
        ]);
    }

    public function rules(): array
    {
        return [
            'client_id' => ['required', 'integer'],
            'Unit_price' => ['prohibited'],
            'unit_price' => ['prohibited'],
            'subtotal' => ['prohibited'],
            'GrandTotal' => ['prohibited'],
            'TaxNet' => ['prohibited'],
            'warehouse_id' => ['prohibited'],
            'stock' => ['prohibited'],
            'promotion_discount' => ['prohibited'],
            'fiscal_number' => ['prohibited'],
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
            'payment_intent' => ['nullable', 'array', 'max:20'],
            'payment_intent.*.payment_method_id' => ['required_with:payment_intent', 'integer'],
            'payment_intent.*.amount' => ['required_with:payment_intent', 'string', 'regex:/^\d+(\.\d{1,2})?$/'],
            'payment_intent.*.account_id' => ['nullable', 'integer'],
            'payment_intent.*.payment_method_id_stripe' => ['prohibited'],
            'payment_intent.*.card_token' => ['prohibited'],
            'payment_intent.*.pan' => ['prohibited'],
            'payment_intent.*.cvv' => ['prohibited'],
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

        if ($errors->has('client_id')) {
            return 'invalid_client';
        }

        foreach ($errors->keys() as $key) {
            if (str_contains($key, '.quantity')) {
                return 'invalid_quantity';
            }

            if (str_contains($key, 'payment_intent')) {
                return 'payment_total_invalid';
            }
        }

        return 'validation_error';
    }
}
