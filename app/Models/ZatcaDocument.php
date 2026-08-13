<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class ZatcaDocument extends Model
{
    protected $fillable = [
        'source_type', 'source_id', 'invoice_number', 'invoice_type_code', 'invoice_subtype',
        'uuid', 'icv', 'invoice_hash', 'pih', 'qr_code', 'status', 'environment',
        'invoice_xml', 'cleared_xml', 'zatca_response', 'warnings', 'errors', 'submitted_at',
    ];

    protected $casts = [
        'source_id' => 'integer',
        'icv' => 'integer',
        'zatca_response' => 'array',
        'warnings' => 'array',
        'errors' => 'array',
        'submitted_at' => 'datetime',
    ];

    public function source()
    {
        return $this->morphTo();
    }

    public function logs()
    {
        return $this->hasMany(ZatcaLog::class);
    }

    public static function forSource(Model $source): ?self
    {
        try {
            return static::query()
                ->where('source_type', get_class($source))
                ->where('source_id', $source->id)
                ->first();
        } catch (\Throwable $e) {
            return null;
        }
    }

    public function isAccepted(): bool
    {
        return in_array($this->status, ['reported', 'cleared'], true);
    }
}
