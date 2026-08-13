<?php

namespace App\Models\Central;

use App\Traits\HasCentralTranslations;
use Illuminate\Database\Eloquent\Model;

class LandingStat extends Model
{
    use HasCentralTranslations;

    protected $connection = 'central';

    protected $table = 'landing_stats';

    protected array $translatable = ['value', 'label'];

    protected $fillable = [
        'value',
        'label',
        'icon',
        'sort_order',
        'is_active',
    ];

    protected $casts = [
        'is_active' => 'boolean',
    ];
}
