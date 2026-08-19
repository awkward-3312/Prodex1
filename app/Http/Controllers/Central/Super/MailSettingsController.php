<?php

namespace App\Http\Controllers\Central\Super;

use App\Http\Controllers\Controller;
use App\Models\Central\MailSetting;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Mail;

class MailSettingsController extends Controller
{
    public function index()
    {
        $setting = MailSetting::instance();
        return view('central.super.settings.mail', ['setting' => $setting, 'mailers' => MailSetting::MAILERS, 'encryptions' => MailSetting::ENCRYPTIONS]);
    }

    public function update(Request $request)
    {
        $validated = $request->validate([
            'mail_mailer' => ['required','string','in:' . implode(',', array_keys(MailSetting::MAILERS))],
            'mail_host' => ['nullable','string','max:255'], 'mail_port' => ['nullable','integer','min:1','max:65535'],
            'mail_username' => ['nullable','string','max:255'], 'mail_password' => ['nullable','string','max:255'],
            'mail_encryption' => ['nullable','string','in:,tls,ssl'], 'mail_from_address' => ['nullable','email','max:255'],
            'mail_from_name' => ['nullable','string','max:255'],
        ]);
        $setting = MailSetting::instance();
        $data = $validated;
        if (empty($data['mail_password']) || $data['mail_password'] === '••••••••') unset($data['mail_password']);
        $setting->update($data);
        return back()->with('success', 'Configuración de correo guardada correctamente.');
    }

    public function sendTest(Request $request)
    {
        $request->validate(['test_email' => ['required','email']]);
        $setting = MailSetting::instance();
        try {
            $this->applyMailConfig($setting);
            Mail::raw('Este es un correo de prueba de PRODEX. Si recibiste este mensaje, tu configuración SMTP está funcionando correctamente.', function ($message) use ($request, $setting) {
                $message->to($request->test_email)->subject('Correo de prueba — ' . config('app.name', 'PRODEX'));
                if ($setting->mail_from_address) $message->from($setting->mail_from_address, $setting->mail_from_name ?: config('app.name', 'PRODEX'));
            });
            return back()->with('success', "Correo de prueba enviado a {$request->test_email}.");
        } catch (\Throwable $e) {
            return back()->withErrors(['test_email' => 'No se pudo enviar el correo de prueba: ' . $e->getMessage()]);
        }
    }

    protected function applyMailConfig(MailSetting $setting): void
    {
        config([
            'mail.default' => $setting->mail_mailer, 'mail.mailers.smtp.host' => $setting->mail_host,
            'mail.mailers.smtp.port' => $setting->mail_port, 'mail.mailers.smtp.username' => $setting->mail_username,
            'mail.mailers.smtp.password' => $setting->getDecryptedPassword(), 'mail.mailers.smtp.encryption' => $setting->mail_encryption ?: null,
            'mail.from.address' => $setting->mail_from_address, 'mail.from.name' => $setting->mail_from_name,
        ]);
        app()->forgetInstance('mail.manager'); app()->forgetInstance('mailer');
    }
}
