<?php

namespace App\Models\Central;

use App\Traits\HasCentralTranslations;
use Illuminate\Database\Eloquent\Model;

class LandingSeo extends Model
{
    use HasCentralTranslations;

    protected $connection = 'central';

    protected $table = 'landing_seo';

    protected array $translatable = ['meta_title', 'meta_description', 'meta_keywords'];

    protected $fillable = [
        'meta_title',
        'meta_description',
        'meta_keywords',
        'og_image',
        'favicon',
    ];
}
