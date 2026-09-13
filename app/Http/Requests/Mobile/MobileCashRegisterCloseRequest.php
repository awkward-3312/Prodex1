<?php

namespace App\Http\Requests\Mobile;

class MobileCashRegisterCloseRequest extends MobileCashRegisterMovementRequest
{
    public function rules(): array
    {
        $rules = [
            'operation_uuid' => ['required', 'uuid'],
            'register_id' => ['required', 'integer', 'min:1'],
            'counted_denominations' => ['nullable', 'array', 'max:30'],
            'counted_denominations.*' => ['integer', 'min:0', 'max:1000000'],
            'transfers_verified' => ['nullable', 'boolean'],
            'tenant_id' => ['prohibited'],
            'user_id' => ['prohibited'],
        ];
        foreach (['counted_cash', 'closing_balance', 'cash_withdrawn_at_close', 'next_opening_float', 'card_terminal_total'] as $field) {
            $rules[$field] = [$field === 'counted_cash' ? 'required' : 'nullable', 'string', 'regex:/^(0|[1-9]\d{0,9})(\.\d{1,2})?$/'];
        }
        foreach (['card_batch_number', 'card_reference', 'card_notes', 'transfer_notes', 'notes'] as $field) {
            $rules[$field] = ['nullable', 'string', 'max:'.(in_array($field, ['card_batch_number', 'card_reference']) ? 191 : 4000)];
        }
        return $rules;
    }
}
