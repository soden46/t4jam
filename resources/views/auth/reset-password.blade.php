@extends('layouts.auth', ['title' => 'Riset Password'])

@section('content')
<form class="auth-form" method="POST" action="{{ route('password.email') }}">
    @csrf
    <div class="center mb-xl"><h1>Forgot Password ?</h1></div>
    <input type="email" name="email" placeholder="Email" autocomplete="off" required>
    @error('email')<div class="alert danger">{{ $message }}</div>@enderror
    <div class="form-row">
        <button class="btn primary" type="submit">Submit</button>
        <a class="btn light" href="{{ route('login') }}">Cancel</a>
    </div>
</form>
@endsection
