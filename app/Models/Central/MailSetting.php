<?php

namespace App\Models\Central;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Crypt;

class MailSetting extends Model
{
    protected $connection = 'central';

    protected $table = 'mail_settings';

    protected $fillable = [
        'mail_mailer',
        'mail_host',
        'mail_port',
        'mail_username',
        'mail_password',
        'mail_encryption',
        'mail_from_address',
        'mail_from_name',
    ];

    protected $casts = [
        'mail_port' => 'integer',
    ];

    public const MAILERS = [
        'smtp'     => 'SMTP',
        'sendmail' => 'Sendmail',
        'log'      => 'Log (testing)',
    ];

    public const ENCRYPTIONS = [
        ''    => 'None',
        'tls' => 'TLS',
        'ssl' => 'SSL',
    ];

    public function setMailPasswordAttribute($value): void
    {
        if ($value !== null && $value !== '' && $value !== '••••••••') {
            $this->attributes['mail_password'] = Crypt::encryptString($value);
        }
    }

    public function getDecryptedPassword(): ?string
    {
        if (empty($this->attributes['mail_password'])) {
            return null;
        }

        try {
            return Crypt::decryptString($this->attributes['mail_password']);
        } catch (\Throwable $e) {
            return $this->attributes['mail_password'];
        }
    }

    /**
     * Get the single settings row (create with defaults if missing).
     */
    public static function instance(): self
    {
        $setting = static::first();

        if (! $setting) {
            $setting = static::create([
                'mail_mailer'       => config('mail.default', 'smtp'),
                'mail_host'         => config('mail.mailers.smtp.host'),
                'mail_port'         => config('mail.mailers.smtp.port', 587),
                'mail_username'     => config('mail.mailers.smtp.username'),
                'mail_encryption'   => config('mail.mailers.smtp.encryption', 'tls'),
                'mail_from_address' => config('mail.from.address'),
                'mail_from_name'    => config('mail.from.name'),
            ]);
        }

        return $setting;
    }
}
