<?php

namespace App\Http\Requests\Mobile;

use Illuminate\Contracts\Validation\Validator;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Http\Exceptions\HttpResponseException;
use Illuminate\Validation\Rule;

class MobileSalesHistoryRequest extends FormRequest
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
            'search' => ['nullable', 'string', 'max:192'],
            'payment_status' => ['nullable', Rule::in(['paid', 'partial', 'unpaid'])],
            'date_from' => ['nullable', 'date_format:Y-m-d'],
            'date_to' => ['nullable', 'date_format:Y-m-d', 'after_or_equal:date_from'],
            'page' => ['nullable', 'integer', 'min:1'],
            'per_page' => ['nullable', 'integer', 'min:1', 'max:50'],
        ];
    }

    protected function failedValidation(Validator $validator): void
    {
        throw new HttpResponseException(response()->json([
            'error' => [
                'code' => 'validation_error',
            ],
        ], 422));
    }
}
