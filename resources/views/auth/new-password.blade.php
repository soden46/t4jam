@extends('layouts.auth', ['title' => 'Reset Password'])

@section('content')
<form class="auth-form" method="POST" action="{{ route('password.update') }}">
    @csrf
    <h1>Reset Password</h1>
    <input type="hidden" name="token" value="{{ $token }}">
    <input type="email" name="email" value="{{ old('email', $email) }}" placeholder="Email" required>
    <input type="password" name="password" placeholder="Password baru" autocomplete="new-password" required>
    <input type="password" name="password_confirmation" placeholder="Konfirmasi password" autocomplete="new-password" required>
    @if ($errors->any())<div class="alert danger">{{ $errors->first() }}</div>@endif
    <button class="btn primary" type="submit">Reset Password</button>
</form>
@endsection
