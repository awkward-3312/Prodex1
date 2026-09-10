<?php

namespace App\Http\Requests\Mobile;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Contracts\Validation\Validator;
use Illuminate\Http\Exceptions\HttpResponseException;

class ResolveMobileProductRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    protected function prepareForValidation(): void
    {
        if ($this->has('value') && is_string($this->input('value'))) {
            $this->merge(['value' => trim($this->input('value'))]);
        }

        if ($this->has('scanner_type') && is_string($this->input('scanner_type'))) {
            $this->merge(['scanner_type' => trim($this->input('scanner_type'))]);
        }
    }

    public function rules(): array
    {
        return [
            'value' => ['required', 'string', 'min:1', 'max:192'],
            'inventory_location_id' => ['required', 'integer'],
            'scanner_type' => ['nullable', 'string', 'max:64'],
        ];
    }

    protected function failedValidation(Validator $validator): void
    {
        $code = $validator->errors()->has('inventory_location_id')
            ? 'invalid_location'
            : 'invalid_scan_value';

        throw new HttpResponseException(response()->json([
            'error' => [
                'code' => $code,
            ],
        ], 422));
    }
}
