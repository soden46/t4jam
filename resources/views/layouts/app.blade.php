<!DOCTYPE html>
<html lang="id">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="csrf-token" content="{{ csrf_token() }}">
    <title>{{ $title ?? 'T4Jam Tools' }}</title>
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link href="https://fonts.googleapis.com/css?family=Inter:300,400,500,600,700" rel="stylesheet">
    @vite(['resources/css/app.css', 'resources/js/app.js'])
</head>
<body class="app-body" data-page="@yield('page')">
    <header class="app-header">
        <div class="header-inner">
            <a class="app-logo" href="{{ route('dashboard') }}">T4Jam <span>Tools</span></a>
            <nav class="top-menu" aria-label="Main navigation">
                <a class="menu-link {{ request()->routeIs('dashboard') ? 'active' : '' }}" href="{{ route('dashboard') }}">Report</a>
                <a class="menu-link {{ request()->routeIs('automation') ? 'active' : '' }}" href="{{ route('automation') }}">Automation Budget</a>
                <a class="menu-link {{ request()->routeIs('interest') ? 'active' : '' }}" href="{{ route('interest') }}">Interest</a>
                <a class="menu-link {{ request()->routeIs('products') ? 'active' : '' }}" href="{{ route('products') }}">Riset Produk</a>
                <a class="menu-link {{ request()->routeIs('ad-setups.*') ? 'active' : '' }}" href="{{ route('ad-setups.index') }}">Setup Iklan</a>
            </nav>
            <div class="header-actions">
                <button class="utility-btn compact" type="button" data-theme-toggle title="Theme">
                    <span class="theme-mark" aria-hidden="true"></span>
                    <span class="sr-only">Theme</span>
                </button>
                <a class="utility-btn" href="{{ route('profile') }}">Profile</a>
                <a class="utility-btn" href="{{ route('logout') }}">Logout</a>
            </div>
        </div>
    </header>

    <main class="app-main">
        <div class="page-shell">
            @if (session('status'))
                <div class="alert success">{{ session('status') }}</div>
            @endif
            @if (session('warning'))
                <div class="alert warning">{{ session('warning') }}</div>
            @endif
            @yield('content')
        </div>
    </main>

    <footer class="app-footer">
        <div class="footer-inner">
            <span>T4Jam Tools</span>
            <div>
                <a href="{{ route('privacy') }}">Privacy Policy</a>
                <a href="{{ route('terms') }}">Term of Service</a>
            </div>
        </div>
    </footer>
</body>
</html>
