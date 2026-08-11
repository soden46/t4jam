@extends('layouts.auth', ['title' => 'Register New User'])

@section('content')
<form class="auth-form" method="POST" action="{{ route('register.store') }}">
    @csrf
    <div class="center mb-xl"><h1>Buat Akun Disini</h1></div>
    <div class="split">
        <input name="first_name" placeholder="First Name" value="{{ old('first_name') }}" required>
        <input name="last_name" placeholder="Last Name" value="{{ old('last_name') }}" required>
    </div>
    <input name="username" placeholder="Username" value="{{ old('username') }}" required>
    <input type="email" name="email" placeholder="Email" value="{{ old('email') }}" required>
    <input type="password" name="password" placeholder="Password" required>
    <input type="password" name="password_confirmation" placeholder="Confirm Password" required>
    <label class="check-row"><input type="checkbox" name="toc" value="1" required> I Agree Terms and conditions</label>
    @if ($errors->any())<div class="alert danger">{{ $errors->first() }}</div>@endif
    <button class="btn primary full" type="submit">Sign Up</button>
    <p class="muted center">Already have an Account? <a href="{{ route('login') }}">Sign in</a></p>
</form>
@endsection
