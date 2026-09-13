<?php

namespace App\Services;

use Illuminate\Validation\ValidationException;

/** Exact physical count validation against the existing closing denomination configuration. */
class CashDenominationReconciler
{
    public const MAX_QUANTITY = 1000000;

    public function total(array $breakdown, array $configuration): string
    {
        $required = [];
        foreach (array_merge($configuration['bills'], $configuration['coins']) as $value) {
            $required[$this->cents((string) $value)] = true;
        }
        $seen = [];
        $total = 0;
        foreach ($breakdown as $key => $quantity) {
            $cents = $this->cents((string) $key);
            if (! isset($required[$cents]) || isset($seen[$cents])) $this->invalid();
            if (! is_int($quantity) && ! (is_string($quantity) && preg_match('/^\d{1,7}$/D', $quantity))) $this->invalid();
            $quantity = (int) $quantity;
            if ($quantity < 0 || $quantity > self::MAX_QUANTITY) $this->invalid();
            $seen[$cents] = true;
            if ($cents <= 0 || $quantity > intdiv(PHP_INT_MAX - $total, $cents)) $this->invalid();
            $total += $cents * $quantity;
        }
        if (! $required || count($seen) !== count($required)) $this->invalid();

        return intdiv($total, 100).'.'.str_pad((string) ($total % 100), 2, '0', STR_PAD_LEFT);
    }

    private function cents(string $value): int
    {
        if (! preg_match('/^(0|[1-9]\d{0,9})(\.\d{1,2})?$/D', $value)) $this->invalid();
        $parts = explode('.', $value);
        return ((int) $parts[0]) * 100 + (int) str_pad($parts[1] ?? '', 2, '0');
    }

    private function invalid(): never
    {
        throw ValidationException::withMessages([
            'counted_denominations' => 'Completa todas las denominaciones configuradas con cantidades enteras entre 0 y '.self::MAX_QUANTITY.', sin denominaciones adicionales o repetidas.',
        ]);
    }
}
