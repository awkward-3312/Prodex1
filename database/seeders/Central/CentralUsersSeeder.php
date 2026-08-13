<?php

namespace Database\Seeders\Central;

use App\Models\Central\CentralUser;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Hash;

/**
 * Create first super admin. Run once: php artisan db:seed --class="Database\Seeders\Central\CentralUsersSeeder"
 * Then change password or set APP_SUPER_ADMIN_EMAIL and APP_SUPER_ADMIN_PASSWORD in .env for first run.
 */
class CentralUsersSeeder extends Seeder
{
    public function run(): void
    {
        $email = (string) env('APP_SUPER_ADMIN_EMAIL', '');
        $password = (string) env('APP_SUPER_ADMIN_PASSWORD', '');

        $defaultAvatar = 'images/super/avatars/no_avatar.png';

        // If explicit creds are provided, ensure that account exists (or gets updated).
        if ($email !== '' && $password !== '') {
            CentralUser::updateOrCreate(
                ['email' => $email],
                [
                    'name' => 'Super Admin',
                    'password' => Hash::make($password),
                    'avatar' => $defaultAvatar,
                ]
            );
            return;
        }

        // Otherwise, create a default super admin only on fresh central installs.
        if (! CentralUser::exists()) {
            CentralUser::create([
                'name' => 'Super Admin',
                'email' => 'superadmin@stockysaas.site',
                'password' => Hash::make('password'),
                'avatar' => $defaultAvatar,
            ]);
        }
    }
}
