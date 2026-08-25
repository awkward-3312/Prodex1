<?php

namespace App\Models;

use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Auth;

class role_user extends Model
{
    protected $table = 'role_user';
    protected $fillable = ['user_id', 'role_id'];
    protected $casts = ['user_id' => 'integer', 'role_id' => 'integer'];

    protected static function booted(): void
    {
        static::saving(function (role_user $assignment) {
            $actor = Auth::guard('api')->user() ?: Auth::user();
            if (! $actor || (int) $actor->role_id === 1) return;

            $originalRoleId = (int) $assignment->getOriginal('role_id');
            if (($assignment->exists && $originalRoleId === 1) || (int) $assignment->role_id === 1) {
                throw new AuthorizationException('Solo el propietario puede asignar el rol propietario.');
            }
        });
    }
}
