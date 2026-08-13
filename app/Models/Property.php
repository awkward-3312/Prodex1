<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;

class Property extends Model
{
    use SoftDeletes;

    protected $table = 'properties';

    protected $fillable = [
        'title', 'slug', 'property_category_id', 'description',
        'purpose', 'status', 'featured',
        'price', 'area', 'area_unit', 'bedrooms', 'bathrooms', 'garage',
        'address', 'city', 'region', 'latitude', 'longitude',
        'featured_image', 'gallery', 'amenities',
        'agent_name', 'agent_phone', 'agent_email', 'agent_whatsapp',
        'seo_title', 'seo_description', 'seo_keywords',
        'views', 'created_by',
    ];

    protected $casts = [
        'featured'  => 'boolean',
        'price'     => 'decimal:2',
        'area'      => 'decimal:2',
        'latitude'  => 'decimal:7',
        'longitude' => 'decimal:7',
        'gallery'   => 'array',
        'amenities' => 'array',
        'views'     => 'integer',
    ];

    public function category()
    {
        return $this->belongsTo(PropertyCategory::class, 'property_category_id');
    }

    public function inquiries()
    {
        return $this->hasMany(PropertyInquiry::class, 'property_id');
    }
}
