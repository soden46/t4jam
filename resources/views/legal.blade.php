@extends('layouts.app', ['title' => $heading])

@section('page', 'legal')

@section('content')
<section class="panel legal">
    <h1>{{ $heading }}</h1>
    <p>T4Jam Tools membantu pengelolaan laporan iklan, riset interest, riset produk, dan automation budget. Halaman ini menyalin struktur legal page target untuk kebutuhan clone lokal Laravel.</p>
    <p>Pengguna bertanggung jawab atas token, data akun iklan, dan pemakaian integrasi pihak ketiga seperti Meta dan marketplace. Data yang tersimpan di aplikasi lokal mengikuti konfigurasi database Laravel ini.</p>
    <p>Dengan menggunakan T4Jam Tools, pengguna menyetujui bahwa fitur riset dan automation harus mengikuti Terms of Service serta Advertising Policies dari platform terkait.</p>
</section>
@endsection
