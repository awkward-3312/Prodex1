<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use App\Services\Mobile\TenantApiTokenService;

class AuthController extends BaseController
{
    // --------------- Function Login ----------------\\

    public function getAccessToken(Request $request, TenantApiTokenService $tokens)
    {
        $request->validate([
            'email' => 'required',
            'password' => 'required',
        ]);

        $user = $tokens->attempt($request->only('email', 'password'));
        if (! $user) {
            return response()->json([
                'message' => 'Correo electrónico o contraseña incorrectos',
                'status' => false,
            ]);
        }

        if ((int) $user->statut === 0) {
            return response()->json([
                'message' => 'Este usuario no está activo',
                'status' => 'NotActive',
            ]);
        }

        $tokenResult = $tokens->issue($user);
        $this->setCookie('Stocky_token', $tokenResult->accessToken);

        return response()->json([
            'Stocky_token' => $tokenResult->accessToken,
            'username' => Auth::User()->username,
            'status' => true,
        ]);
    }

    // --------------- Function Logout ----------------\\

    public function logout()
    {
        if (Auth::check()) {
            $user = Auth::user()->token();
            $user->revoke();
            $this->destroyCookie('Stocky_token');

            return response()->json('success');
        }

    }
}
