<?php

namespace App\Http\Controllers;

use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Str;

class PasswordResetController extends Controller
{
    /** How long a reset link stays valid (minutes). */
    private const TOKEN_TTL_MINUTES = 60;

    /* ============================================================
       SHOW FORGOT FORM
       ============================================================ */
    public function showForgot()
    {
        if (session('user_id')) {
            return redirect('/');
        }
        return view('auth.forgot-password');
    }

    /* ============================================================
       SEND RESET LINK
       ============================================================ */
    public function sendResetLink(Request $request)
    {
        $request->validate([
            'email' => 'required|email',
        ]);

        $user = User::where('email', $request->email)->first();

        // Always respond the same way (prevents email enumeration)
        if (! $user) {
            return back()->with('success', 'If that email exists, we\'ve sent a reset link.');
        }

        // Generate a secure token
        $token = Str::random(64);

        DB::table('password_reset_tokens')->updateOrInsert(
            ['email' => $user->email],
            [
                'token'      => hash('sha256', $token),
                'created_at' => now(),
            ]
        );

        $resetUrl = url('/reset-password/' . $token . '?email=' . urlencode($user->email));

        try {
            Mail::raw(
                "Hi {$user->name},\n\n"
                . "Click the link below to reset your NPO CRM password. This link expires in "
                . self::TOKEN_TTL_MINUTES . " minutes.\n\n"
                . "{$resetUrl}\n\n"
                . "If you didn't request this, ignore this email.\n\n"
                . "— NPO CRM",
                function ($m) use ($user) {
                    $m->to($user->email)->subject('Reset your NPO CRM password');
                }
            );
        } catch (\Throwable $e) {
            // Log but don't reveal to user
            \Log::error('Password reset email failed: ' . $e->getMessage());

            return back()->withErrors([
                'email' => 'Could not send the reset email. Please contact your admin.',
            ]);
        }

        return back()->with('success', 'If that email exists, we\'ve sent a reset link.');
    }

    /* ============================================================
       SHOW RESET FORM
       ============================================================ */
    public function showReset(Request $request, string $token)
    {
        if (session('user_id')) {
            return redirect('/');
        }

        $email = (string) $request->query('email', '');

        return view('auth.reset-password', compact('token', 'email'));
    }

    /* ============================================================
       APPLY NEW PASSWORD
       ============================================================ */
    public function resetPassword(Request $request)
    {
        $validated = $request->validate([
            'email'                 => 'required|email',
            'token'                 => 'required|string',
            'password'              => 'required|string|min:6|confirmed',
        ]);

        $record = DB::table('password_reset_tokens')
            ->where('email', $validated['email'])
            ->first();

        if (! $record) {
            return back()->withErrors(['email' => 'Invalid or expired reset link.']);
        }

        // Compare hashes
        if (! hash_equals($record->token, hash('sha256', $validated['token']))) {
            return back()->withErrors(['email' => 'Invalid reset link.']);
        }

        // Check expiry
        $age = now()->diffInMinutes(\Carbon\Carbon::parse($record->created_at));
        if ($age > self::TOKEN_TTL_MINUTES) {
            DB::table('password_reset_tokens')->where('email', $validated['email'])->delete();
            return back()->withErrors(['email' => 'Reset link expired. Please request a new one.']);
        }

        $user = User::where('email', $validated['email'])->first();
        if (! $user) {
            return back()->withErrors(['email' => 'No account found.']);
        }

        $user->update([
            'password' => Hash::make($validated['password']),
        ]);

        // Invalidate the token
        DB::table('password_reset_tokens')->where('email', $user->email)->delete();

        return redirect('/login')->with('success', '✅ Password reset! Please sign in with your new password.');
    }
}