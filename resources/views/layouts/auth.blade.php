<!DOCTYPE html>
<html lang="id">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>{{ $title ?? 'Login To Dashboard' }}</title>
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link href="https://fonts.googleapis.com/css?family=Inter:300,400,500,600,700" rel="stylesheet">
    @vite(['resources/css/app.css', 'resources/js/app.js'])
</head>
<body class="auth-body">
    <main class="auth-shell">
        <section class="auth-brand">
            <a href="{{ route('dashboard') }}" class="brand-logo">T4Jam <span>Tools</span></a>
            <h2>Mahir Facebook Ads Hanya Dalam Waktu 4 Jam Saja!</h2>
        </section>

        <section class="auth-card">
            @if (session('status'))
                <div class="alert success">{{ session('status') }}</div>
            @endif
            @yield('content')
        </section>
    </main>
</body>
</html>
