<?php

namespace App\Services\Reports;

use Illuminate\Support\Carbon;

/**
 * Normaliza y valida el rango de fechas de los reportes de inventario.
 *
 * Reglas (documentadas y coherentes con el UX de PRODEX):
 *  · `from` / `to` deben ser fechas `Y-m-d` válidas → si no, `error`.
 *  · `from <= to` obligatorio → si `from > to`, `error` (no se intercambia
 *    silenciosamente: el usuario debe corregir su selección).
 *  · `to` en el futuro (con `from` pasado/hoy) se acota a HOY (no se puede
 *    calcular stock futuro) y se avisa con `to_clamped`.
 *  · Rango COMPLETAMENTE en el futuro (`from` > hoy) → `error`. No se convierte
 *    silenciosamente en "hoy → hoy".
 *  · `from` == `to` es válido (período de 1 día).
 *  · Sin `from`/`to`: por defecto los últimos 90 días hasta hoy.
 */
class ReportDateRange
{
    public string $from;

    public string $to;

    public int $days;

    public bool $toClamped = false;

    public ?string $error = null;

    private function __construct()
    {
    }

    public static function parse(?string $from, ?string $to): self
    {
        $r = new self;
        $today = Carbon::today();

        $rawFrom = trim((string) ($from ?? ''));
        $rawTo = trim((string) ($to ?? ''));

        $cFrom = $rawFrom === '' ? $today->copy()->subDays(90) : self::tryDate($rawFrom);
        $cTo = $rawTo === '' ? $today->copy() : self::tryDate($rawTo);

        if ($cFrom === null || $cTo === null) {
            $r->error = 'Fecha inválida. Usa el formato AAAA-MM-DD.';
            $r->from = $today->copy()->subDays(90)->toDateString();
            $r->to = $today->toDateString();
            $r->days = 91;

            return $r;
        }

        if ($cFrom->gt($cTo)) {
            $r->error = 'La fecha "desde" no puede ser posterior a "hasta".';
            $r->from = $cFrom->toDateString();
            $r->to = $cTo->toDateString();
            $r->days = 1;

            return $r;
        }

        if ($cTo->gt($today)) {
            $cTo = $today->copy();
            $r->toClamped = true;
        }
        if ($cFrom->gt($cTo)) {
            // Tras acotar `to` a hoy, `from` sigue en el futuro → todo el rango
            // era futuro. No se puede calcular; se rechaza (no "hoy → hoy").
            $r->error = 'La fecha "desde" no puede estar en el futuro.';
            $r->from = $cFrom->toDateString();
            $r->to = $cTo->toDateString();
            $r->days = 1;

            return $r;
        }

        $r->from = $cFrom->toDateString();
        $r->to = $cTo->toDateString();
        $r->days = (int) max(1, $cFrom->diffInDays($cTo) + 1);

        return $r;
    }

    public function isValid(): bool
    {
        return $this->error === null;
    }

    private static function tryDate(string $value): ?Carbon
    {
        if (! preg_match('/^\d{4}-\d{2}-\d{2}$/', $value)) {
            return null;
        }
        try {
            $d = Carbon::createFromFormat('Y-m-d', $value);

            return $d && $d->format('Y-m-d') === $value ? $d->startOfDay() : null;
        } catch (\Throwable) {
            return null;
        }
    }
}
