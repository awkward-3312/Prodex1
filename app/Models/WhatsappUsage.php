<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class WhatsappUsage extends Model
{
    protected $table = 'whatsapp_usages';

    protected $fillable = [
        'period',
        'sent_count',
    ];

    protected $casts = [
        'sent_count' => 'integer',
    ];

    /** Current calendar month period key, e.g. "2026-06". */
    public static function currentPeriod(): string
    {
        return now()->format('Y-m');
    }

    /** Messages sent in the current month (0 if none recorded yet). */
    public static function currentCount(): int
    {
        return (int) (static::where('period', static::currentPeriod())->value('sent_count') ?? 0);
    }

    /** Atomically increment the current month's counter. */
    public static function bump(int $by = 1): void
    {
        $row = static::firstOrCreate(['period' => static::currentPeriod()], ['sent_count' => 0]);
        $row->increment('sent_count', $by);
    }
}
