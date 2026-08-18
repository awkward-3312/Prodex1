<?php

namespace Database\Seeders;

use App\Models\Central\MailSetting;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;

class ServerSeeder extends Seeder
{
    /**
     * Seed the tenant SMTP server using the platform-wide mail settings.
     */
    public function run()
    {
        try {
            $mail = MailSetting::instance();

            $values = [
                'mail_mailer' => $mail->mail_mailer ?: 'smtp',
                'host' => $mail->mail_host ?: config('mail.mailers.smtp.host'),
                'port' => $mail->mail_port ?: config('mail.mailers.smtp.port', 587),
                'username' => $mail->mail_username ?: config('mail.mailers.smtp.username'),
                'password' => $mail->getDecryptedPassword() ?: config('mail.mailers.smtp.password'),
                'encryption' => $mail->mail_encryption ?: config('mail.mailers.smtp.encryption', 'tls'),
                'sender_email' => $mail->mail_from_address ?: config('mail.from.address'),
                'sender_name' => $mail->mail_from_name ?: config('mail.from.name', 'PRODEX'),
            ];
        } catch (\Throwable) {
            $values = [
                'mail_mailer' => config('mail.default', 'smtp'),
                'host' => config('mail.mailers.smtp.host'),
                'port' => config('mail.mailers.smtp.port', 587),
                'username' => config('mail.mailers.smtp.username'),
                'password' => config('mail.mailers.smtp.password'),
                'encryption' => config('mail.mailers.smtp.encryption', 'tls'),
                'sender_email' => config('mail.from.address'),
                'sender_name' => config('mail.from.name', 'PRODEX'),
            ];
        }

        DB::table('servers')->insert(array_merge([
            'id' => 1,
        ], $values));
    }
}
