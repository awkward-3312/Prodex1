<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class OfficeShift extends Model
{
    use HasFactory;

    protected $dates = ['deleted_at'];

    protected $fillable = [
        'name', 'company_id', 'monday_in', 'monday_out',
        'tuesday_in', 'tuesday_out', 'wednesday_in', 'wednesday_out',
        'thursday_in', 'thursday_out', 'friday_in', 'friday_out',
        'saturday_in', 'saturday_out', 'sunday_in', 'sunday_out',

    ];

    protected $casts = [
        'company_id' => 'integer',
    ];

    public function company()
    {
        return $this->hasOne('App\Models\Company', 'id', 'company_id');
    }

    /**
     * Normalize a `{day}_in`/`{day}_out` value to canonical 24h "H:i", or
     * null if the day has no time set / the value is unparsable.
     *
     * History: OfficeShiftController has only ever written these columns
     * through `DateTime::format('H:iA')` — 'H' is already 24h (00-23), so
     * the digits were ALWAYS the true 24h time; the trailing AM/PM is
     * decorative and, for any hour outside 1-12 (e.g. "18:00" -> "18:00PM"),
     * outright nonsensical as a 12h marker. Because that call site is the
     * only place these columns have ever been written (single controller,
     * single format string since the initial commit, no seeders touch this
     * table), there is no historical ambiguity to resolve: stripping
     * anything after the digits and reusing them as-is recovers the exact
     * original time in every case, including "17:00PM" -> "17:00" (never
     * "05:00PM"/17:00 misread as 06:00, and never a 12h reinterpretation).
     *
     * Also accepts the new canonical "H:i"/"H:i:s" (no suffix) so this same
     * helper is safe to use for both legacy and newly-written values.
     */
    public static function normalizeTime(?string $raw): ?string
    {
        $value = trim((string) $raw);
        if ($value === '') {
            return null;
        }

        if (! preg_match('/^(\d{1,2}):(\d{2})(?::\d{2})?\s*(?:[AaPp][Mm])?$/', $value, $m)) {
            return null;
        }

        $hour = (int) $m[1];
        $minute = (int) $m[2];
        if ($hour > 23 || $minute > 59) {
            return null;
        }

        return sprintf('%02d:%02d', $hour, $minute);
    }
}
