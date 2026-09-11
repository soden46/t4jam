<?php

namespace App\Http\Controllers;

use App\Models\User;
use Illuminate\Auth\Events\PasswordReset;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Password;
use Illuminate\View\View;
use Laravel\Socialite\Facades\Socialite;
use Throwable;

class AuthController extends Controller
{
    public function showLogin(): View|RedirectResponse
    {
        return Auth::check() ? redirect()->route('dashboard') : view('auth.login');
    }

    public function login(Request $request): RedirectResponse
    {
        $credentials = $request->validate([
            'email' => ['required', 'email'],
            'password' => ['required', 'string'],
        ]);

        if (Auth::attempt($credentials)) {
            $request->session()->regenerate();

            return redirect()->intended(route('dashboard'));
        }

        return back()
            ->withErrors(['email' => 'Sorry, Your Account Is Not Valid. Please Try Again'])
            ->onlyInput('email');
    }

    public function showRegister(): View|RedirectResponse
    {
        return Auth::check() ? redirect()->route('dashboard') : view('auth.register');
    }

    public function register(Request $request): RedirectResponse
    {
        $data = $request->validate([
            'first_name' => ['required', 'string', 'max:80'],
            'last_name' => ['required', 'string', 'max:80'],
            'email' => ['required', 'email', 'max:255', 'unique:users,email'],
            'password' => ['required', 'string', 'min:8', 'confirmed'],
            'toc' => ['accepted'],
        ]);

        $user = User::create([
            'name' => trim($data['first_name'].' '.$data['last_name']),
            'email' => $data['email'],
            'password' => $data['password'],
        ]);

        Auth::login($user);
        $request->session()->regenerate();

        return redirect()->route('dashboard')->with('status', 'You have successfully registered!');
    }

    public function showReset(): View|RedirectResponse
    {
        return Auth::check() ? redirect()->route('dashboard') : view('auth.reset-password');
    }

    public function reset(Request $request): RedirectResponse
    {
        $request->validate(['email' => ['required', 'email']]);

        if (in_array(config('mail.default'), ['log', 'array', null], true)) {
            return back()->withErrors(['email' => 'Pengiriman email belum dikonfigurasi. Hubungi administrator untuk pemulihan akun.'])->onlyInput('email');
        }

        try {
            $status = Password::sendResetLink($request->only('email'));
        } catch (Throwable) {
            return back()->withErrors(['email' => 'Email pemulihan belum berhasil dikirim. Hubungi administrator.'])->onlyInput('email');
        }

        if ($status === Password::RESET_THROTTLED) {
            return back()->withErrors(['email' => 'Tunggu sebelum meminta link pemulihan lagi.']);
        }

        return back()->with('status', 'Jika email terdaftar, link pemulihan telah dikirim.');
    }

    public function showNewPassword(Request $request, string $token): View
    {
        return view('auth.new-password', ['token' => $token, 'email' => $request->query('email')]);
    }

    public function updateResetPassword(Request $request): RedirectResponse
    {
        $data = $request->validate([
            'token' => ['required', 'string'], 'email' => ['required', 'email'],
            'password' => ['required', 'string', 'min:8', 'confirmed'],
        ]);
        $status = Password::reset($data, function (User $user, string $password): void {
            $user->forceFill(['password' => $password, 'remember_token' => str()->random(60)])->save();
            event(new PasswordReset($user));
        });

        return $status === Password::PASSWORD_RESET
            ? redirect()->route('login')->with('status', 'Password berhasil direset. Silakan masuk.')
            : back()->withErrors(['email' => 'Link pemulihan tidak valid atau sudah kedaluwarsa.'])->onlyInput('email');
    }

    public function redirectGoogle(): RedirectResponse
    {
        if (! config('services.google.client_id') || ! config('services.google.client_secret')) {
            return $this->loginGoogleFallback();
        }

        return Socialite::driver('google')->redirect();
    }

    public function callbackGoogle(): RedirectResponse
    {
        try {
            $googleUser = Socialite::driver('google')->user();
        } catch (Throwable) {
            return redirect()->route('login')->withErrors([
                'email' => 'Google sign in gagal. Cek konfigurasi Google OAuth.',
            ]);
        }

        if (! $googleUser->getEmail() || ! ($googleUser->user['email_verified'] ?? $googleUser->user['verified_email'] ?? false)) {
            return redirect()->route('login')->withErrors(['email' => 'Google email belum terverifikasi.']);
        }

        $user = User::firstOrCreate(
            ['email' => $googleUser->getEmail()],
            [
                'name' => $googleUser->getName() ?: $googleUser->getNickname() ?: 'Google User',
                'password' => str()->password(32),
            ]
        );

        Auth::login($user);
        request()->session()->regenerate();

        return redirect()->route('dashboard');
    }

    public function logout(Request $request): RedirectResponse
    {
        Auth::logout();
        $request->session()->invalidate();
        $request->session()->regenerateToken();

        return redirect()->route('login');
    }

    private function loginGoogleFallback(): RedirectResponse
    {
        if (! app()->environment(['local', 'testing'])) {
            return redirect()->route('login')->withErrors(['email' => 'Konfigurasi Google OAuth belum diisi.']);
        }

        $user = User::firstOrCreate(
            ['email' => 'google-demo@t4jam.local'],
            ['name' => 'Google Demo User', 'password' => str()->password(32)]
        );

        Auth::login($user);
        request()->session()->regenerate();

        return redirect()->route('dashboard')->with('status', 'Signed in with Google demo mode.');
    }
}
