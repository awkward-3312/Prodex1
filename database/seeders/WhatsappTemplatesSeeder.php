<?php

namespace Database\Seeders;

use App\Models\Central\GeneralSetting;
use App\Models\WhatsappTemplate;
use Illuminate\Database\Seeder;

/**
 * Seeds a freshly provisioned tenant with the default WhatsApp templates
 * configured by the super admin (General Settings → WhatsApp), falling back
 * to a built-in starter set when none are configured.
 */
class WhatsappTemplatesSeeder extends Seeder
{
    public function run()
    {
        $templates = $this->resolveDefaults();

        foreach ($templates as $tpl) {
            if (empty($tpl['key']) || empty($tpl['body'])) {
                continue;
            }

            WhatsappTemplate::updateOrCreate(
                ['key' => $tpl['key']],
                [
                    'name'      => $tpl['name'] ?? ucwords(str_replace('_', ' ', $tpl['key'])),
                    'body'      => $tpl['body'],
                    'language'  => 'en',
                    'is_active' => true,
                ]
            );
        }
    }

    /**
     * @return array<int, array{key: string, name: string, body: string}>
     */
    protected function resolveDefaults(): array
    {
        try {
            $configured = GeneralSetting::instance()->whatsapp_default_templates;
            if (is_array($configured) && ! empty($configured)) {
                return $configured;
            }
        } catch (\Throwable $e) {
            // central settings unreachable during seeding — use starter set
        }

        return [
            [
                'key'  => 'invoice_created',
                'name' => 'Invoice Created',
                'body' => "Hello {{customer_name}}, your invoice {{invoice_number}} from {{company_name}} for {{total_amount}} has been created. Thank you!",
            ],
            [
                'key'  => 'payment_received',
                'name' => 'Payment Received',
                'body' => "Hi {{customer_name}}, we received your payment of {{total_amount}} for {{invoice_number}}. Status: {{payment_status}}. Thank you for your business with {{company_name}}.",
            ],
            [
                'key'  => 'order_created',
                'name' => 'Order Confirmation',
                'body' => "Hello {{customer_name}}, your order {{invoice_number}} with {{company_name}} has been confirmed. Total: {{total_amount}}.",
            ],
            [
                'key'  => 'order_status',
                'name' => 'Order Status Update',
                'body' => "Hi {{customer_name}}, the status of your order {{invoice_number}} is now: {{order_status}}.",
            ],
        ];
    }
}
