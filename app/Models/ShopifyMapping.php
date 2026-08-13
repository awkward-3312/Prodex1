<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class ShopifyMapping extends Model
{
    public const TYPE_PRODUCT = 'product';
    public const TYPE_VARIANT = 'variant';
    public const TYPE_CUSTOMER = 'customer';
    public const TYPE_ORDER = 'order';

    protected $fillable = [
        'shopify_store_id', 'entity_type', 'local_id', 'shopify_id', 'extra', 'synced_at',
    ];

    protected $casts = [
        'shopify_store_id' => 'integer',
        'local_id' => 'integer',
        'shopify_id' => 'integer',
        'extra' => 'array',
        'synced_at' => 'datetime',
    ];

    public function store()
    {
        return $this->belongsTo(ShopifyStore::class, 'shopify_store_id');
    }

    public static function put(int $storeId, string $type, int $localId, int $shopifyId, ?array $extra = null): self
    {
        $mapping = self::firstOrNew([
            'shopify_store_id' => $storeId,
            'entity_type' => $type,
            'local_id' => $localId,
        ]);
        $mapping->shopify_id = $shopifyId;
        if ($extra !== null) {
            $mapping->extra = array_merge($mapping->extra ?? [], $extra);
        }
        $mapping->synced_at = now();
        $mapping->save();

        return $mapping;
    }

    public static function shopifyId(int $storeId, string $type, int $localId): ?int
    {
        $mapping = self::where('shopify_store_id', $storeId)
            ->where('entity_type', $type)
            ->where('local_id', $localId)
            ->first();

        return $mapping ? (int) $mapping->shopify_id : null;
    }

    public static function localId(int $storeId, string $type, int $shopifyId): ?int
    {
        $mapping = self::where('shopify_store_id', $storeId)
            ->where('entity_type', $type)
            ->where('shopify_id', $shopifyId)
            ->first();

        return $mapping ? (int) $mapping->local_id : null;
    }
}
