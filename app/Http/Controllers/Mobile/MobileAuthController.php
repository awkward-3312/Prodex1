<?php

namespace App\Http\Controllers\Mobile;

use App\Http\Controllers\Controller;
use App\Services\Mobile\MobileBootstrapService;
use App\Services\Mobile\TenantApiTokenService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;

class MobileAuthController extends Controller
{
    public function login(Request $request, TenantApiTokenService $tokens)
    {
        $request->validate([
            'email' => ['required', 'email'],
            'password' => ['required', 'string'],
        ]);

        $user = $tokens->attempt($request->only('email', 'password'));
        if (! $user) {
            return $this->error('invalid_credentials', 401);
        }

        if ((int) $user->statut === 0) {
            return $this->error('user_inactive', 403);
        }

        $tokenResult = $tokens->issue($user);

        return response()->json([
            'data' => [
                'access_token' => $tokenResult->accessToken,
                'token_type' => 'Bearer',
                'expires_at' => $tokens->expiresAt($tokenResult),
                'session' => [
                    'idle_timeout_seconds' => $tokens->idleTimeoutSeconds(),
                ],
            ],
        ]);
    }

    public function bootstrap(Request $request, MobileBootstrapService $bootstrap)
    {
        $user = $request->user('api');
        abort_unless($user, 401);

        return response()->json([
            'data' => $bootstrap->forUser($user),
        ]);
    }

    public function logout(Request $request)
    {
        $user = $request->user('api');
        abort_unless($user, 401);

        $token = $user->token();
        if ($token) {
            Cache::forget('api_token_activity:'.$token->id);
            $token->revoke();
        }

        return response()->json([
            'data' => [
                'revoked' => true,
            ],
        ]);
    }

    private function error(string $code, int $status)
    {
        return response()->json([
            'error' => [
                'code' => $code,
            ],
        ], $status);
    }
}

