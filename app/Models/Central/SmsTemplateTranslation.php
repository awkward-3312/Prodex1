<?php

namespace App\Models\Central;

use Illuminate\Database\Eloquent\Model;

class SmsTemplateTranslation extends Model
{
    protected $connection = 'central';

    protected $table = 'sms_template_translations';

    protected $fillable = [
        'sms_template_id',
        'locale',
        'body',
    ];

    public function template()
    {
        return $this->belongsTo(SmsTemplate::class, 'sms_template_id');
    }
}
