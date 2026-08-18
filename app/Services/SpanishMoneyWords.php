<?php

namespace App\Services;

class SpanishMoneyWords
{
    public function lempiras(float $amount): string
    {
        $integer = (int) floor(abs($amount));
        $cents = (int) round((abs($amount) - $integer) * 100);
        if ($cents === 100) {
            $integer++;
            $cents = 0;
        }

        $words = $this->integer($integer);
        $currency = $integer === 1 ? 'LEMPIRA' : 'LEMPIRAS';

        return trim($words.' '.$currency.' CON '.str_pad((string) $cents, 2, '0', STR_PAD_LEFT).'/100');
    }

    private function integer(int $number): string
    {
        if ($number === 0) {
            return 'CERO';
        }
        if ($number < 0 || $number > 999999999) {
            return (string) $number;
        }

        $parts = [];
        $millions = intdiv($number, 1000000);
        $number %= 1000000;
        $thousands = intdiv($number, 1000);
        $remainder = $number % 1000;

        if ($millions > 0) {
            $parts[] = $millions === 1 ? 'UN MILLÓN' : $this->underThousand($millions).' MILLONES';
        }
        if ($thousands > 0) {
            $parts[] = $thousands === 1 ? 'MIL' : $this->underThousand($thousands).' MIL';
        }
        if ($remainder > 0) {
            $parts[] = $this->underThousand($remainder);
        }

        return implode(' ', $parts);
    }

    private function underThousand(int $number): string
    {
        $hundreds = [
            1 => 'CIENTO', 2 => 'DOSCIENTOS', 3 => 'TRESCIENTOS', 4 => 'CUATROCIENTOS',
            5 => 'QUINIENTOS', 6 => 'SEISCIENTOS', 7 => 'SETECIENTOS',
            8 => 'OCHOCIENTOS', 9 => 'NOVECIENTOS',
        ];

        if ($number === 100) {
            return 'CIEN';
        }

        $parts = [];
        if ($number >= 100) {
            $parts[] = $hundreds[intdiv($number, 100)];
            $number %= 100;
        }
        if ($number > 0) {
            $parts[] = $this->underHundred($number);
        }

        return implode(' ', $parts);
    }

    private function underHundred(int $number): string
    {
        $special = [
            1 => 'UNO', 2 => 'DOS', 3 => 'TRES', 4 => 'CUATRO', 5 => 'CINCO',
            6 => 'SEIS', 7 => 'SIETE', 8 => 'OCHO', 9 => 'NUEVE', 10 => 'DIEZ',
            11 => 'ONCE', 12 => 'DOCE', 13 => 'TRECE', 14 => 'CATORCE', 15 => 'QUINCE',
            16 => 'DIECISÉIS', 17 => 'DIECISIETE', 18 => 'DIECIOCHO', 19 => 'DIECINUEVE',
            20 => 'VEINTE', 21 => 'VEINTIUNO', 22 => 'VEINTIDÓS', 23 => 'VEINTITRÉS',
            24 => 'VEINTICUATRO', 25 => 'VEINTICINCO', 26 => 'VEINTISÉIS',
            27 => 'VEINTISIETE', 28 => 'VEINTIOCHO', 29 => 'VEINTINUEVE',
        ];
        if (isset($special[$number])) {
            return $special[$number];
        }

        $tens = [3 => 'TREINTA', 4 => 'CUARENTA', 5 => 'CINCUENTA', 6 => 'SESENTA', 7 => 'SETENTA', 8 => 'OCHENTA', 9 => 'NOVENTA'];
        $ten = intdiv($number, 10);
        $unit = $number % 10;

        return $tens[$ten].($unit ? ' Y '.$special[$unit] : '');
    }
}
