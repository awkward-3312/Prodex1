<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class ZatcaLog extends Model
{
    protected $fillable = [
        'zatca_document_id', 'action', 'endpoint', 'http_status', 'request_meta', 'response_body',
    ];

    protected $casts = [
        'zatca_document_id' => 'integer',
        'http_status' => 'integer',
        'request_meta' => 'array',
    ];

    public function document()
    {
        return $this->belongsTo(ZatcaDocument::class, 'zatca_document_id');
    }
}
