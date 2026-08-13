@extends('layouts.app', ['title' => $title])

@section('page', 'profile')

@section('content')
@php
    $parts = explode(' ', auth()->user()->name, 2);
    $profile = auth()->user()->t4jamProfile ?? \App\Models\T4JamProfile::firstOrCreate(['user_id' => auth()->id()]);
@endphp

<section class="panel profile-head">
    <div>
        <h2>Profile</h2>
        <p>{{ auth()->user()->email }}</p>
    </div>
    <div class="avatar">{{ strtoupper(substr(auth()->user()->name, 0, 1)) }}</div>
</section>

<section class="grid-2 align-start">
    <div class="panel">
        <div class="panel-head">
            <div>
                <h3>Input Access Token</h3>
                @if ($profile->meta_user_name)
                    <p>Terhubung sebagai {{ $profile->meta_user_name }}</p>
                    @if ($profile->last_meta_sync_at)
                        <p class="muted">Terakhir sync: {{ $profile->last_meta_sync_at->timezone('Asia/Jakarta')->format('d M Y H:i') }}</p>
                    @endif
                    @if ($profile->last_meta_error)
                        <p class="text-danger">Meta error: {{ $profile->last_meta_error }}</p>
                    @endif
                @elseif ($profile->last_meta_error)
                    <p class="text-danger">Meta error: {{ $profile->last_meta_error }}</p>
                    <p class="muted">Terakhir update: {{ $profile->updated_at->timezone('Asia/Jakarta')->format('d M Y H:i') }}</p>
                @endif
                @if (session('status'))
                    <p class="text-success">{{ session('status') }}</p>
                @endif
            </div>
            @if ($profile->access_token)
                <form method="POST" action="{{ route('profile.sync-meta') }}" id="sync-meta-form">
                    @csrf
                    <button class="btn light" type="submit" id="sync-meta-btn">Sync Meta Ads</button>
                </form>
            @endif
        </div>
        @error('meta')<div class="alert danger">{{ $message }}</div>@enderror
        <form method="POST" action="{{ route('profile.token') }}" class="stack-form">
            @csrf
            <label>ID Aplikasi
                <input type="text" id="id_aplikasi" name="id_aplikasi" placeholder="ID Aplikasi" value="{{ old('id_aplikasi', $profile->app_id) }}">
            </label>
            <label>Kunci Rahasia Aplikasi
                <input type="text" id="kunci_rahasia" name="kunci_rahasia" placeholder="Kunci Rahasia Aplikasi" value="{{ old('kunci_rahasia', $profile->app_secret) }}">
            </label>
            <label>Akses Token
                <textarea id="access_token_app" name="access_token_app" placeholder="Akses Token">{{ old('access_token_app', $profile->access_token) }}</textarea>
            </label>
            <button class="btn light-primary" type="submit">Save Token</button>
        </form>
    </div>

    <div class="panel">
        <table class="profile-table">
            <tr><th>Full Name</th><td>{{ auth()->user()->name }}</td><td><button class="btn light" data-open-modal="#profile-modal" type="button">Edit</button></td></tr>
            <tr><th>Password</th><td>******</td><td><button class="btn light" data-open-modal="#password-modal" type="button">Edit</button></td></tr>
        </table>
    </div>
</section>

<div class="modal" id="profile-modal" hidden>
    <div class="modal-panel">
        <button class="modal-close" data-close-modal type="button" aria-label="Close">x</button>
        <h2>Profile Name</h2>
        <form id="profile-form" class="stack-form">
            @csrf
            <label>First Name
                <input name="first_name" value="{{ $parts[0] ?? '' }}">
            </label>
            <label>Last Name
                <input name="last_name" value="{{ $parts[1] ?? '' }}">
            </label>
            <div class="form-actions">
                <button type="button" class="btn light" data-close-modal>Discard</button>
                <button type="submit" class="btn primary">Update</button>
            </div>
        </form>
    </div>
</div>

<div class="modal" id="password-modal" hidden>
    <div class="modal-panel">
        <button class="modal-close" data-close-modal type="button" aria-label="Close">x</button>
        <h2>Update Password</h2>
        <form id="password-form" class="stack-form">
            @csrf
            <label>Current Password
                <input type="password" name="current_password" autocomplete="off">
            </label>
            <label>New Password
                <input type="password" name="new_password" autocomplete="off">
            </label>
            <label>Confirm New Password
                <input type="password" name="confirm_password" autocomplete="off">
            </label>
            <div class="form-actions">
                <button type="button" class="btn light" data-close-modal>Discard</button>
                <button type="submit" class="btn primary">Update</button>
            </div>
        </form>
    </div>
</div>
@endsection
