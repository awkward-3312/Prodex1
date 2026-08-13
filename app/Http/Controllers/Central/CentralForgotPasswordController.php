<?php

namespace App\Http\Controllers\Central;

use App\Http\Controllers\Controller;
use App\Models\Central\CentralUser;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Str;

class CentralForgotPasswordController extends Controller
{
    /**
     * GET /super/forgot-password
     */
    public function showForgotForm()
    {
        return view('central.forgot-password');
    }

    /**
     * POST /super/forgot-password
     */
    public function sendResetLink(Request $request)
    {
        $request->validate([
            'email' => ['required', 'email'],
        ]);

        $user = CentralUser::where('email', $request->email)->first();

        if (! $user) {
            return back()->withErrors([
                'email' => 'No account found with that email address.',
            ])->withInput();
        }

        // Delete any existing tokens for this email
        DB::connection('central')
            ->table('central_password_resets')
            ->where('email', $request->email)
            ->delete();

        $token = Str::random(64);

        DB::connection('central')->table('central_password_resets')->insert([
            'email'      => $request->email,
            'token'      => Hash::make($token),
            'created_at' => now(),
        ]);

        $resetUrl = route('central.password.reset', ['token' => $token, 'email' => $request->email]);

        Mail::send([], [], function ($message) use ($user, $resetUrl) {
            $message->to($user->email)
                ->subject('Reset Your Super Admin Password — ' . config('app.name', 'Stocky'))
                ->html($this->resetEmailHtml($user->name ?? 'Admin', $resetUrl));
        });

        return back()->with('status', 'We\'ve sent a password reset link to your email address.');
    }

    /**
     * GET /super/reset-password/{token}
     */
    public function showResetForm(Request $request, string $token)
    {
        return view('central.reset-password', [
            'token' => $token,
            'email' => $request->query('email', ''),
        ]);
    }

    /**
     * POST /super/reset-password
     */
    public function resetPassword(Request $request)
    {
        $request->validate([
            'token'    => ['required', 'string'],
            'email'    => ['required', 'email'],
            'password' => ['required', 'string', 'min:8', 'confirmed'],
        ]);

        $record = DB::connection('central')
            ->table('central_password_resets')
            ->where('email', $request->email)
            ->first();

        if (! $record) {
            return back()->withErrors(['email' => 'No password reset request found for this email.'])->withInput();
        }

        // Check token expiration (60 minutes)
        if (now()->diffInMinutes($record->created_at) > 60) {
            DB::connection('central')
                ->table('central_password_resets')
                ->where('email', $request->email)
                ->delete();

            return back()->withErrors(['email' => 'This reset link has expired. Please request a new one.'])->withInput();
        }

        if (! Hash::check($request->token, $record->token)) {
            return back()->withErrors(['email' => 'Invalid reset token.'])->withInput();
        }

        $user = CentralUser::where('email', $request->email)->first();

        if (! $user) {
            return back()->withErrors(['email' => 'No account found with that email address.'])->withInput();
        }

        $user->update([
            'password' => Hash::make($request->password),
        ]);

        // Clean up used token
        DB::connection('central')
            ->table('central_password_resets')
            ->where('email', $request->email)
            ->delete();

        return redirect()->route('central.login')
            ->with('status', 'Your password has been reset successfully. You can now sign in.');
    }

    /**
     *  HTML email for the reset link.
     */
    private function resetEmailHtml(string $name, string $resetUrl): string
    {
        $appName = e(config('app.name', 'Stocky'));

        return <<<HTML
<!DOCTYPE html>
<html lang="en">
<head><meta charset="UTF-8"><meta name="viewport" content="width=device-width, initial-scale=1.0"></head>
<body style="margin:0; padding:0; background-color:#f4f6f9; font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, Helvetica, Arial, sans-serif;">
<table role="presentation" width="100%" cellpadding="0" cellspacing="0" style="background-color:#f4f6f9;">
<tr><td align="center" style="padding:40px 20px;">
<table role="presentation" width="600" cellpadding="0" cellspacing="0" style="background:#ffffff; border-radius:12px; box-shadow:0 2px 12px rgba(0,0,0,0.08); overflow:hidden;">
<tr>
<td style="background:linear-gradient(135deg, #667eea 0%, #764ba2 100%); padding:32px 40px; text-align:center;">
<h1 style="margin:0; color:#ffffff; font-size:22px; font-weight:700;">{$appName}</h1>
</td>
</tr>
<tr>
<td style="padding:40px;">
<h2 style="margin:0 0 16px; font-size:20px; color:#1e293b;">Reset Your Password</h2>
<p style="margin:0 0 16px; font-size:15px; color:#475569; line-height:1.6;">
Hi <strong>{$name}</strong>,
</p>
<p style="margin:0 0 16px; font-size:15px; color:#475569; line-height:1.6;">
We received a request to reset your super admin password. Click the button below to set a new password. This link will expire in 60 minutes.
</p>
<table role="presentation" cellpadding="0" cellspacing="0" style="margin:24px 0;">
<tr>
<td style="border-radius:8px; background:linear-gradient(135deg, #667eea 0%, #764ba2 100%);">
<a href="{$resetUrl}" style="display:inline-block; padding:14px 32px; color:#ffffff; text-decoration:none; font-weight:600; font-size:15px;">Reset Password</a>
</td>
</tr>
</table>
<p style="margin:0 0 8px; font-size:13px; color:#94a3b8; line-height:1.6;">
If you didn't request this, you can safely ignore this email. Your password will remain unchanged.
</p>
<p style="margin:0; font-size:13px; color:#94a3b8; line-height:1.6;">
If the button doesn't work, copy and paste this link into your browser:<br>
<a href="{$resetUrl}" style="color:#6366f1; word-break:break-all;">{$resetUrl}</a>
</p>
</td>
</tr>
<tr>
<td style="padding:24px 40px; background:#f8fafc; border-top:1px solid #e2e8f0; text-align:center;">
<p style="margin:0; font-size:13px; color:#94a3b8;">This email was sent by <a href="" style="color:#6366f1; text-decoration:none;">{$appName}</a></p>
</td>
</tr>
</table>
</td></tr>
</table>
</body>
</html>
HTML;
    }
}
