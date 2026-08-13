<?php

namespace App\Http\Controllers\Central;

use App\Http\Controllers\Controller;
use App\Models\Central\CentralUser;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Hash;

class CentralLoginController extends Controller
{
    public function showLoginForm()
    {
        if (Auth::guard('central')->check()) {
            return redirect()->route('super.dashboard');
        }

        return view('central.login');
    }

    public function login(Request $request)
    {
        $request->validate([
            'email'    => ['required', 'email'],
            'password' => ['required', 'string'],
        ]);

        $user = CentralUser::where('email', $request->input('email'))->first();

        if (! $user || ! Hash::check($request->input('password'), $user->password)) {
            return back()->withErrors([
                'email' => 'These credentials do not match our records.',
            ])->withInput($request->only('email', 'remember'));
        }

        Auth::guard('central')->login($user, $request->boolean('remember'));

        $request->session()->regenerate();

        return redirect()->route('super.dashboard');
    }

    public function logout(Request $request)
    {
        Auth::guard('central')->logout();

        $request->session()->invalidate();
        $request->session()->regenerateToken();

        return redirect()->route('central.login');
    }
}
