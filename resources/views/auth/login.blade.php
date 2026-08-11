@extends('layouts.auth', ['title' => 'Login To Dashboard'])

@section('content')
<form class="auth-form" method="POST" action="{{ route('login.store') }}">
    @csrf
    <div class="center mb-xl">
        <h1>Masuk Dashboard</h1>
    </div>
    <a href="{{ route('google.login') }}" class="google-btn"><img src="https://www.gstatic.com/firebasejs/ui/2.0.0/images/auth/google.svg" alt=""> Sign in with Google</a>
    <div class="separator"><span>Or with email</span></div>
    <input type="email" name="email" value="{{ old('email') }}" placeholder="Email" autocomplete="off" required>
    <input type="password" name="password" placeholder="Password" autocomplete="off" required>
    @error('email')<div class="alert danger">{{ $message }}</div>@enderror
    <div class="form-row end"><a href="{{ route('password.request') }}">Forgot Password ?</a></div>
    <button class="btn primary full" type="submit"><span>Sign In</span></button>
    <p class="muted center">Not a Member Yet? <a href="{{ route('register') }}">Sign up</a></p>
    <p class="terms center">By using t4jam tools, you are agree to our <a href="{{ route('terms') }}">Terms of Use</a> and <a href="{{ route('privacy') }}">Privacy Policy</a>, including Meta's Terms of Service and Advertising Policies</p>
</form>
@endsection
