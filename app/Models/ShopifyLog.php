<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class ShopifyLog extends Model
{
    protected $fillable = [
        'shopify_store_id', 'action', 'level', 'message', 'context',
    ];

    protected $casts = [
        'shopify_store_id' => 'integer',
        'context' => 'array',
    ];

    public function store()
    {
        return $this->belongsTo(ShopifyStore::class, 'shopify_store_id');
    }
}
