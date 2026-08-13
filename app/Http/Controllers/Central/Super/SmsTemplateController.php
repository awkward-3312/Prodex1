<?php

namespace App\Http\Controllers\Central\Super;

use App\Http\Controllers\Controller;
use App\Models\Central\CentralLanguage;
use App\Models\Central\SmsSetting;
use App\Models\Central\SmsTemplate;
use App\Models\Central\SmsTemplateTranslation;
use App\Services\SmsOtpSender;
use Illuminate\Http\Request;

class SmsTemplateController extends Controller
{
    public function index()
    {
        // Self-heal missing defaults so the page is usable without a manual seed.
        (new \Database\Seeders\Central\SmsTemplatesSeeder)->run();

        $templates = SmsTemplate::with('translations')
            ->orderByRaw("FIELD(trigger_key, 'expiring_soon', 'trial_ending')")
            ->get();

        $triggerLabels = [
            SmsTemplate::TRIGGER_EXPIRING_SOON => 'Expiring Soon',
            SmsTemplate::TRIGGER_TRIAL_ENDING  => 'Trial Ending',
        ];

        $triggerIcons = [
            SmsTemplate::TRIGGER_EXPIRING_SOON => 'bi-clock-history',
            SmsTemplate::TRIGGER_TRIAL_ENDING  => 'bi-hourglass-split',
        ];

        $triggerColors = [
            SmsTemplate::TRIGGER_EXPIRING_SOON => '#f59e0b',
            SmsTemplate::TRIGGER_TRIAL_ENDING  => '#6366f1',
        ];

        $languages = CentralLanguage::active();

        return view('central.super.sms-templates.index', compact(
            'templates', 'triggerLabels', 'triggerIcons', 'triggerColors', 'languages'
        ));
    }

    public function edit(SmsTemplate $template, Request $request)
    {
        $template->load('translations');
        $variables = SmsTemplate::AVAILABLE_VARIABLES;
        $languages = CentralLanguage::active();
        $currentLocale = $request->query('locale');

        $translation = null;
        if ($currentLocale) {
            $translation = $template->getTranslation($currentLocale);
        }

        return view('central.super.sms-templates.edit', compact(
            'template', 'variables', 'languages', 'currentLocale', 'translation'
        ));
    }

    public function update(Request $request, SmsTemplate $template)
    {
        $locale = $request->input('locale');

        if ($locale) {
            // Saving a translation
            $validated = $request->validate([
                'body' => ['required', 'string', 'max:480'],
            ]);

            SmsTemplateTranslation::updateOrCreate(
                [
                    'sms_template_id' => $template->id,
                    'locale'          => $locale,
                ],
                [
                    'body' => $validated['body'],
                ]
            );

            return redirect()
                ->route('super.sms-templates.edit', ['template' => $template->id, 'locale' => $locale])
                ->with('success', "Translation for \"{$locale}\" saved successfully.");
        }

        // Saving the default template
        $validated = $request->validate([
            'body'      => ['required', 'string', 'max:480'],
            'is_active' => ['nullable'],
        ]);

        $template->update([
            'body'      => $validated['body'],
            'is_active' => $request->has('is_active'),
        ]);

        return redirect()
            ->route('super.sms-templates.edit', $template)
            ->with('success', "Template \"{$template->name}\" updated successfully.");
    }

    /**
     * Delete a specific translation.
     */
    public function destroyTranslation(SmsTemplate $template, string $locale)
    {
        SmsTemplateTranslation::where('sms_template_id', $template->id)
            ->where('locale', $locale)
            ->delete();

        return redirect()
            ->route('super.sms-templates.edit', $template)
            ->with('success', "Translation for \"{$locale}\" removed.");
    }

    public function sendTest(Request $request, SmsTemplate $template)
    {
        $request->validate([
            'test_phone' => ['required', 'string', 'max:30', 'regex:/^\+?[0-9\s\-()]{6,}$/'],
        ]);

        $setting = SmsSetting::instance();

        if (! $setting->sms_gateway) {
            return back()->withErrors(['test_phone' => 'Configure and save an SMS gateway in Settings > SMS Settings first.']);
        }

        $template->load('translations');
        $locale = $request->input('locale');
        $localeSuffix = $locale ? " [{$locale}]" : '';

        $message = '[TEST]' . $localeSuffix . ' ' . $template->render($this->getSampleVariables(), $locale);

        try {
            app(SmsOtpSender::class)->sendVia(
                $setting->sms_gateway,
                trim($request->test_phone),
                $message,
                $setting->credentials()
            );

            return back()->with('success', "Test SMS{$localeSuffix} sent to {$request->test_phone}.");
        } catch (\Throwable $e) {
            return back()->withErrors(['test_phone' => 'Failed to send test SMS: ' . $e->getMessage()]);
        }
    }

    protected function getSampleVariables(): array
    {
        return [
            '{company}' => 'Demo Company',
            '{plan}'    => 'Professional',
            '{date}'    => now()->addDays(7)->format('M d, Y'),
            '{days}'    => '7',
            '{app}'     => config('app.name', 'Stocky'),
        ];
    }
}
