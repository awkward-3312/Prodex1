<?php

namespace App\Models\Central;

use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;

class CentralUser extends Authenticatable
{
    use Notifiable;

    protected $connection = 'central';

    protected $table = 'central_users';

    public const ROLE_SUPER_ADMIN = 'super_admin';
    public const ROLE_ADMIN       = 'admin';

    /**
     * All available permission sections.
     * key => label (used in forms).
     */
    public const PERMISSIONS = [
        'dashboard'       => 'Dashboard',
        'tenants'         => 'Tenants',
        'plans'           => 'Plans',
        'subscriptions'   => 'Subscriptions',
        'reminders'       => 'Subscription Reminders',
        'admins'          => 'Super Admins',
        'payments'        => 'Payments',
        'support'         => 'Support Tickets',
        'reports'         => 'Reports',
        'knowledge_base'  => 'Knowledge Base',
        'how_it_works'    => 'How It Works',
        'cms'             => 'CMS / Landing Page',
        'email_templates' => 'Email Templates',
        'sms_templates'   => 'SMS Templates',
        'logs_health'     => 'Logs & System Health',
        'settings'        => 'Settings',
        'whatsapp'        => 'WhatsApp Settings',
    ];

    protected $fillable = ['name', 'email', 'password', 'avatar', 'role', 'permissions'];

    protected $hidden = ['password', 'remember_token'];

    protected $casts = [
        'permissions' => 'array',
    ];

    public function isSuperAdmin(): bool
    {
        return $this->role === self::ROLE_SUPER_ADMIN;
    }

    public function hasPermission(string $section): bool
    {
        if ($this->isSuperAdmin()) {
            return true;
        }

        return in_array($section, $this->permissions ?? [], true);
    }

    public function hasAnyPermission(array $sections): bool
    {
        if ($this->isSuperAdmin()) {
            return true;
        }

        return !empty(array_intersect($sections, $this->permissions ?? []));
    }
}
