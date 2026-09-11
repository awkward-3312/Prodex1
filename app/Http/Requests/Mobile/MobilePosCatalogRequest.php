<?php

namespace App\Http\Requests\Mobile;

use Illuminate\Contracts\Validation\Validator;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Http\Exceptions\HttpResponseException;

class MobilePosCatalogRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    protected function prepareForValidation(): void
    {
        if ($this->has('search') && is_string($this->input('search'))) {
            $search = trim($this->input('search'));
            $this->merge(['search' => $search === '' ? null : $search]);
        }
    }

    public function rules(): array
    {
        return [
            'inventory_location_id' => ['required', 'integer'],
            'search' => ['nullable', 'string', 'max:192'],
            'category_id' => ['nullable', 'integer'],
            'page' => ['nullable', 'integer', 'min:1'],
            'per_page' => ['nullable', 'integer', 'min:1', 'max:50'],
        ];
    }

    protected function failedValidation(Validator $validator): void
    {
        $code = $validator->errors()->has('inventory_location_id')
            ? 'invalid_location'
            : 'validation_error';

        throw new HttpResponseException(response()->json([
            'error' => [
                'code' => $code,
            ],
        ], 422));
    }
}
