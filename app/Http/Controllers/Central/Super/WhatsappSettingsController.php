<?php

namespace App\Http\Controllers\Central\Super;

use App\Http\Controllers\Controller;
use App\Models\Central\GeneralSetting;
use App\Models\WhatsappUsage;
use App\Tenant;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;

class WhatsappSettingsController extends Controller
{
    public function index()
    {
        $setting = GeneralSetting::instance();

        return view('central.super.settings.whatsapp', [
            'setting'          => $setting,
            'providers'        => GeneralSetting::WHATSAPP_PROVIDERS,
            'defaultTemplates' => $setting->whatsapp_default_templates ?? $this->starterTemplates(),
            'usage'            => $this->collectUsage(),
            'webhookBase'      => url('/whatsapp/webhook'),
        ]);
    }

    public function update(Request $request)
    {
        $validated = $request->validate([
            'whatsapp_enabled'  => ['nullable', 'boolean'],
            'whatsapp_provider' => ['required', 'string', 'in:' . implode(',', GeneralSetting::WHATSAPP_PROVIDERS)],
            'templates'                  => ['nullable', 'array'],
            'templates.*.key'            => ['required_with:templates', 'string', 'max:100'],
            'templates.*.name'           => ['required_with:templates', 'string', 'max:150'],
            'templates.*.body'           => ['required_with:templates', 'string', 'max:2000'],
        ]);

        $setting = GeneralSetting::instance();

        $templates = collect($validated['templates'] ?? [])
            ->map(fn ($t) => [
                'key'  => \Illuminate\Support\Str::slug($t['key'], '_'),
                'name' => $t['name'],
                'body' => $t['body'],
            ])
            ->values()
            ->all();

        $setting->update([
            'whatsapp_enabled'           => $request->boolean('whatsapp_enabled'),
            'whatsapp_provider'          => $validated['whatsapp_provider'],
            'whatsapp_default_templates' => $templates,
        ]);

        return back()->with('success', 'WhatsApp settings saved successfully.');
    }

    /**
     * Sum the current month's WhatsApp usage across all tenants.
     *
     * @return array<int, array{tenant: string, sent: int}>
     */
    protected function collectUsage(): array
    {
        $rows = [];

        try {
            foreach (Tenant::all() as $tenant) {
                if ($tenant->status !== 'active') {
                    continue;
                }

                $sent = 0;
                try {
                    $tenant->run(function () use (&$sent) {
                        $sent = WhatsappUsage::currentCount();
                    });
                } catch (\Throwable $e) {
                    continue; // tenant DB not reachable / table missing — skip
                }

                if ($sent > 0) {
                    $rows[] = [
                        'tenant' => $tenant->company_name ?: $tenant->id,
                        'sent'   => $sent,
                    ];
                }
            }
        } catch (\Throwable $e) {
            Log::warning('WhatsApp usage collection failed: ' . $e->getMessage());
        }

        usort($rows, fn ($a, $b) => $b['sent'] <=> $a['sent']);

        return $rows;
    }

    /**
     * Starter templates offered when no defaults have been configured yet.
     *
     * @return array<int, array{key: string, name: string, body: string}>
     */
    protected function starterTemplates(): array
    {
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
