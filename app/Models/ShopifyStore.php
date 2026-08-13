<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class ShopifyStore extends Model
{
    protected $fillable = [
        'name', 'shop_domain', 'access_token', 'api_secret', 'api_version',
        'location_id', 'location_name', 'warehouse_id',
        'is_active', 'enable_auto_sync', 'sync_interval', 'last_sync_at',
    ];

    protected $casts = [
        'location_id' => 'integer',
        'warehouse_id' => 'integer',
        'is_active' => 'boolean',
        'enable_auto_sync' => 'boolean',
        'last_sync_at' => 'datetime',
    ];

    public function mappings()
    {
        return $this->hasMany(ShopifyMapping::class, 'shopify_store_id');
    }

    public function logs()
    {
        return $this->hasMany(ShopifyLog::class, 'shopify_store_id');
    }

    public function warehouse()
    {
        return $this->belongsTo(Warehouse::class, 'warehouse_id');
    }

    /**
     * Normalized myshopify domain (strips protocol/trailing slash).
     */
    public function normalizedDomain(): string
    {
        $domain = trim((string) $this->shop_domain);
        $domain = preg_replace('#^https?://#i', '', $domain);

        return rtrim(explode('/', $domain)[0] ?? '', '/');
    }
}
