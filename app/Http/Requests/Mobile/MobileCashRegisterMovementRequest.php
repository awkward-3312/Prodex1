<?php

namespace App\Http\Requests\Mobile;

use Illuminate\Contracts\Validation\Validator;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Http\Exceptions\HttpResponseException;

class MobileCashRegisterMovementRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'operation_uuid' => ['required', 'uuid'],
            'register_id' => ['required', 'integer', 'min:1'],
            'type' => ['required', 'string', 'in:in,out'],
            'amount' => ['required', 'regex:/^\d+(\.\d{1,2})?$/', 'numeric', 'gt:0'],
            'notes' => ['required', 'string', 'min:3', 'max:191'],
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
