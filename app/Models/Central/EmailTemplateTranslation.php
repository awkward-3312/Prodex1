<?php

namespace App\Models\Central;

use Illuminate\Database\Eloquent\Model;

class EmailTemplateTranslation extends Model
{
    protected $connection = 'central';

    protected $table = 'email_template_translations';

    protected $fillable = [
        'email_template_id',
        'locale',
        'subject',
        'body_html',
    ];

    public function template()
    {
        return $this->belongsTo(EmailTemplate::class, 'email_template_id');
    }
}
