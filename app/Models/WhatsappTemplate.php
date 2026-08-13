<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;

class WhatsappTemplate extends Model
{
    use SoftDeletes;

    protected $table = 'whatsapp_templates';

    protected $fillable = [
        'name',
        'key',
        'body',
        'language',
        'category',
        'is_active',
    ];

    protected $casts = [
        'is_active' => 'boolean',
    ];

    /**
     * Variables tenants can use inside template bodies. Mirrors the
     * EmailTemplate placeholder convention ({{var}}).
     */
    public const AVAILABLE_VARIABLES = [
        '{{customer_name}}'  => 'Customer / recipient name',
        '{{invoice_number}}' => 'Invoice or order reference',
        '{{total_amount}}'   => 'Order / invoice total',
        '{{company_name}}'   => 'Tenant company name',
        '{{payment_status}}' => 'Payment status',
        '{{order_status}}'   => 'Order status',
        '{{date}}'           => 'Current date',
    ];

    /**
     * Replace {{placeholders}} with the provided values and return the
     * rendered message body. Same substitution approach as
     * EmailTemplate::render().
     */
    public function render(array $variables): string
    {
        $body = $this->body;

        foreach ($variables as $key => $value) {
            $body = str_replace($key, (string) $value, $body);
        }

        return $body;
    }
}
