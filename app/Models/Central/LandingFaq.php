<?php

namespace App\Models\Central;

use App\Traits\HasCentralTranslations;
use Illuminate\Database\Eloquent\Model;

class LandingFaq extends Model
{
    use HasCentralTranslations;

    protected $connection = 'central';

    protected $table = 'landing_faqs';

    protected array $translatable = ['question', 'answer'];

    protected $fillable = [
        'question',
        'answer',
        'sort_order',
        'is_active',
    ];

    protected $casts = [
        'is_active' => 'boolean',
    ];
}
