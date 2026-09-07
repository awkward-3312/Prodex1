<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;

/**
 * A single SAR authorisation (one CAI) belonging to a fiscal series
 * (sar_points_of_issue). The correlativo counter lives here — `next_number` —
 * never on a cash drawer or a branch.
 *
 * Lifecycle:
 *   draft      registered, not yet in use
 *   active     currently issuing numbers (at most one per series+document_type)
 *   prepared   the NEXT CAI of this series; PRODEX activates it automatically and
 *              atomically when `active` runs out (at most one per series+type)
 *   exhausted  range fully consumed
 *   expired    past its deadline
 *   disabled   manually retired
 */
class SarAuthorization extends Model
{
    protected $fillable = [
        'point_of_issue_id', 'document_type', 'cai', 'range_start',
        'range_end', 'next_number', 'authorization_date', 'deadline', 'status',
        'activated_at', 'exhausted_at', 'superseded_by_id',
    ];

    protected $casts = [
        'authorization_date' => 'date',
        'deadline' => 'date',
        'activated_at' => 'datetime',
        'exhausted_at' => 'datetime',
        'range_start' => 'integer',
        'range_end' => 'integer',
        'next_number' => 'integer',
        'superseded_by_id' => 'integer',
    ];

    public function pointOfIssue()
    {
        return $this->belongsTo(SarPointOfIssue::class, 'point_of_issue_id');
    }

    /** The fiscal series this authorisation belongs to (alias of pointOfIssue). */
    public function series()
    {
        return $this->belongsTo(SarPointOfIssue::class, 'point_of_issue_id');
    }

    public function documents()
    {
        return $this->hasMany(SarFiscalDocument::class, 'authorization_id');
    }

    public function supersededBy()
    {
        return $this->belongsTo(self::class, 'superseded_by_id');
    }

    /** All authorisations of one series + document type (current, next, history). */
    public function scopeForSeries($query, int $pointOfIssueId, string $documentType)
    {
        return $query->where('point_of_issue_id', $pointOfIssueId)
            ->where('document_type', $documentType);
    }

    /**
     * "Usable right now" — the exact contract the UI shows as "Lista para
     * facturar": status active/prepared, deadline in the future, and the next
     * correlativo still inside the authorised range.
     */
    public function isUsableNow(?Carbon $today = null): bool
    {
        $today = $today ?: Carbon::today();

        if (! in_array($this->status, ['active', 'prepared'], true)) {
            return false;
        }
        if ($this->deadline && Carbon::parse($this->deadline)->lt($today)) {
            return false;
        }
        $next = (int) $this->next_number;

        return $next >= (int) $this->range_start && $next <= (int) $this->range_end;
    }

    public function remaining(): int
    {
        return max(0, (int) $this->range_end - max((int) $this->next_number, (int) $this->range_start) + 1);
    }

    public function isExpired(?Carbon $today = null): bool
    {
        $today = $today ?: Carbon::today();

        return $this->deadline && Carbon::parse($this->deadline)->lt($today);
    }

    public function isRangeExhausted(): bool
    {
        return (int) $this->next_number > (int) $this->range_end;
    }

    /**
     * Converge stored status with reality: an `active`/`prepared` row whose
     * deadline has passed becomes `expired`, one whose range is fully consumed
     * becomes `exhausted`. Runs outside any sale transaction (e.g. when the
     * settings screen loads) so reports and the UI see the real state even
     * though a rolled-back failed sale never persists the transition itself.
     */
    public static function reconcileTerminalStatuses(): void
    {
        $today = Carbon::today()->toDateString();

        static::whereIn('status', ['active', 'prepared'])
            ->whereDate('deadline', '<', $today)
            ->update(['status' => 'expired']);

        static::whereIn('status', ['active', 'prepared'])
            ->whereColumn('next_number', '>', 'range_end')
            ->update(['status' => 'exhausted', 'exhausted_at' => now()]);
    }

    /**
     * Health label for the fiscal series screen.
     *   ready | expiring | running_low | exhausted | expired | inactive
     */
    public function healthState(int $lowThreshold = 200, int $expiringDays = 15): string
    {
        if ($this->status === 'exhausted' || $this->isRangeExhausted()) {
            return 'exhausted';
        }
        if ($this->status === 'expired' || $this->isExpired()) {
            return 'expired';
        }
        if (! in_array($this->status, ['active', 'prepared'], true)) {
            return 'inactive';
        }
        if ($this->deadline && Carbon::parse($this->deadline)->lte(Carbon::today()->addDays($expiringDays))) {
            return 'expiring';
        }
        if ($this->remaining() <= $lowThreshold) {
            return 'running_low';
        }

        return 'ready';
    }
}
