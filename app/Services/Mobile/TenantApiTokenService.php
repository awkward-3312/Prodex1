<?php

namespace App\Services\Mobile;

use App\Models\User;
use Illuminate\Support\Facades\Auth;
use Laravel\Passport\PersonalAccessTokenResult;

class TenantApiTokenService
{
    public function attempt(array $credentials): ?User
    {
        $email = trim((string) ($credentials['email'] ?? ''));
        $password = (string) ($credentials['password'] ?? '');

        if ($email === '' || $password === '') {
            return null;
        }

        if (! Auth::attempt(['email' => $email, 'password' => $password])) {
            return null;
        }

        $user = Auth::user();

        return $user instanceof User ? $user : null;
    }

    public function issue(User $user): PersonalAccessTokenResult
    {
        return $user->createToken('Access Token');
    }

    public function expiresAt(PersonalAccessTokenResult $tokenResult): ?string
    {
        $expiresAt = $tokenResult->token->expires_at ?? null;

        return $expiresAt ? $expiresAt->toIso8601String() : null;
    }

    public function idleTimeoutSeconds(): ?int
    {
        $minutes = (int) config('auth.api_token_idle_timeout', 0);

        return $minutes > 0 ? $minutes * 60 : null;
    }
}

