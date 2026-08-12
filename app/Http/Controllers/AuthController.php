<?php

namespace App\Http\Controllers;

use App\Models\User;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
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
            'username' => ['required', 'string', 'max:80'],
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

        return back()->with('status', 'Reset password link berhasil disiapkan untuk akun tersebut.');
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
            $googleUser = Socialite::driver('google')->stateless()->user();
        } catch (Throwable) {
            return redirect()->route('login')->withErrors([
                'email' => 'Google sign in gagal. Cek konfigurasi Google OAuth.',
            ]);
        }

        $user = User::updateOrCreate(
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
        $user = User::firstOrCreate(
            ['email' => 'google-demo@t4jam.local'],
            ['name' => 'Google Demo User', 'password' => str()->password(32)]
        );

        Auth::login($user);
        request()->session()->regenerate();

        return redirect()->route('dashboard')->with('status', 'Signed in with Google demo mode.');
    }
}
