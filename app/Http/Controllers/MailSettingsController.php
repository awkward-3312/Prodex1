<?php

namespace App\Http\Controllers;

use App\Models\Server;
use App\Models\Setting;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Mail;

class MailSettingsController extends BaseController
{
    public function get_config_mail(Request $request)
    {
        $this->authorizeForUser($request->user('api'), 'mail_settings', Setting::class);
        $server = Server::where('deleted_at', '=', null)->first();
        return $server ? response()->json(['server' => $server], 200) : response()->json(['statut' => 'error'], 500);
    }

    public function update_config_mail(Request $request, $id)
    {
        $this->authorizeForUser($request->user('api'), 'mail_settings', Setting::class);
        Server::whereId($id)->update([
            'mail_mailer' => $request['mail_mailer'], 'host' => $request['host'], 'port' => $request['port'],
            'sender_name' => $request['sender_name'], 'sender_email' => $request['sender_email'],
            'username' => $request['username'], 'password' => $request['password'], 'encryption' => $request['encryption'],
        ]);
        return response()->json(['success' => true]);
    }

    public function test_config_mail(Request $request)
    {
        $this->authorizeForUser($request->user('api'), 'mail_settings', Setting::class);
        $this->Set_config_mail();
        $user = $request->user('api');
        $settings = Setting::where('deleted_at', '=', null)->first();
        $server = Server::where('deleted_at', '=', null)->first();
        $to = $request->input('email') ?: ($user && $user->email ? $user->email : null) ?: ($settings && $settings->email ? $settings->email : null);
        if (! $to) return $this->sendError('No hay una dirección de correo disponible para realizar la prueba.');

        try {
            Mail::raw('Este es un correo de prueba para verificar la configuración de correo electrónico de PRODEX.', function ($message) use ($to, $settings, $server) {
                $message->to($to)->subject('Prueba de configuración de correo');
                $fromEmail = ($server && $server->sender_email) ? $server->sender_email : ($settings && $settings->email ? $settings->email : null);
                if ($fromEmail) {
                    $fromName = ($server && $server->sender_name) ? $server->sender_name : ($settings && $settings->CompanyName ? $settings->CompanyName : 'PRODEX');
                    $message->from($fromEmail, $fromName);
                }
            });
            return $this->sendResponse(null, 'Correo de prueba enviado correctamente a '.$to);
        } catch (\Exception $e) {
            return $this->sendError('No se pudo enviar el correo de prueba.', $e->getMessage());
        }
    }
}
