<?php

namespace App\Http\Requests\Mobile;

use Illuminate\Contracts\Validation\Validator;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Http\Exceptions\HttpResponseException;

class MobileCashRegisterOpenRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'operation_uuid' => ['required', 'uuid'],
            'opening_balance' => ['required', 'regex:/^\d+(\.\d{1,2})?$/', 'numeric', 'min:0'],
            'notes' => ['nullable', 'string', 'max:255'],

            'tenant_id' => ['prohibited'],
            'user_id' => ['prohibited'],
            'branch_id' => ['prohibited'],
            'warehouse_id' => ['prohibited'],
            'inventory_location_id' => ['prohibited'],
            'cash_drawer_id' => ['prohibited'],
        ];
    }

    public function prepareForValidation(): void
    {
        if (is_string($this->input('notes'))) {
            $this->merge(['notes' => trim($this->input('notes'))]);
        }
    }

    protected function failedValidation(Validator $validator): void
    {
        throw new HttpResponseException(response()->json([
            'error' => ['code' => 'validation_error', 'details' => $validator->errors()->toArray()],
        ], 422));
    }
}
