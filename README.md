# T4Jam Tools Laravel

T4Jam Tools adalah aplikasi Laravel untuk membawa workflow T4Jam ke aplikasi yang data-driven: dashboard Meta Ads, automation budget, interest tools, riset produk, setup iklan, dan profile token Meta.

Fokus proyek ini bukan mockup statis. Route, form, tabel, modal, AJAX/API contract, dan data utama harus jalan dari database Laravel.

## Stack

- Laravel 13
- PHP 8.3
- MySQL
- Blade, Vite, Tailwind
- Laravel database queue

## Data Utama

Aplikasi memakai data Eloquent untuk:

- `ad_accounts`
- `campaigns`
- `ad_sets`
- `automation_tasks`
- `automation_logs`
- `interests`
- `product_categories`
- `products`
- `t4jam_profiles`
- `ad_setups`

Seeder lokal menyiapkan data awal supaya halaman bisa diuji tanpa bergantung ke Meta.

## Setup Lokal

```bash
composer install
npm install
cp .env.example .env
php artisan key:generate
php artisan migrate --seed
npm run build
php artisan serve
```

Pastikan `.env` mengarah ke database lokal yang benar.

## Environment Penting

```env
APP_URL=http://localhost:8000
DB_CONNECTION=mysql
QUEUE_CONNECTION=database
DB_QUEUE_RETRY_AFTER=900

META_GRAPH_VERSION=v23.0
META_GRAPH_BASE_URL=https://graph.facebook.com
META_GRAPH_TIMEOUT=45
META_GRAPH_RETRY_TIMES=3
META_GRAPH_RETRY_SLEEP_MS=500
META_ADS_ENABLE_WRITES=false
```

`META_ADS_ENABLE_WRITES=false` artinya publish setup iklan dan update budget tidak dikirim ke Meta. Data tetap disimpan di aplikasi dan UI harus memberi warning yang jelas. Aktifkan hanya saat token, permission, dan ad account sudah siap.

## Meta Ads Sync

User menyimpan token di halaman `Profile`, lalu klik `Sync Meta Ads`.

Flow saat ini:

1. Controller memvalidasi profile punya access token.
2. Tombol `Sync Meta Ads` di Profile menjalankan `App\Jobs\SyncMetaAdsProfile` after-response, jadi tidak bergantung pada worker queue.
3. Tombol `Reload` di dashboard menjalankan `App\Services\MetaAdsSyncService` langsung dan mengembalikan data account terbaru.
4. Account utama dibaca dari `/me/adaccounts`, mengikuti flow awal yang sudah cocok dengan dashboard pilihan akun iklan.
5. Jika token punya permission Business Manager, sync juga mencoba `/me/businesses`, `owned_ad_accounts`, dan `client_ad_accounts` untuk menambah akun dari portfolio bisnis.
6. Jika Meta menolak lookup Business Manager dengan error permission seperti `business_management`, lookup tambahan dilewati dan account dari `/me/adaccounts` tetap disimpan.
7. Account dan campaign disimpan bertahap begitu berhasil dibaca.
8. Insight dan ad set ikut disimpan jika Meta tidak menolak request.
9. Error terakhir disimpan di `t4jam_profiles.last_meta_error` dan ditampilkan di Profile.

Sync account sengaja mengikuti flow lama yang tidak membutuhkan queue worker karena client perlu melihat akun iklan terbaru setelah sync/reload. Jika request Meta terlalu lama atau terkena rate limit, data yang sudah terbaca tetap disimpan dan error terakhir ditampilkan di Profile.

## Meta Write Actions

Action yang mengirim perubahan ke Meta juga memakai queue `meta`:

- `Reload` di dashboard dan `Sync Meta Ads` di Profile menjalankan sync baca data akun iklan, bukan write action.
- `Create` dan `Update` automation budget menyimpan data lokal, lalu memasukkan update budget Meta ke queue.
- Toggle status automation menyimpan status lokal, lalu memasukkan update status Meta ke queue.
- `Turun` budget menyimpan budget lokal, lalu memasukkan update budget Meta ke queue.
- `Publish / Prepare Meta` dan tombol `Publish` setup iklan memasukkan publish campaign/ad set/creative/ad ke queue saat write mode aktif.

Tombol yang hanya memfilter, memilih campaign, mencari interest/produk, reset data pilihan, update profile, atau update password tetap berjalan langsung karena tidak melakukan request Meta yang panjang.

Saat `META_ADS_ENABLE_WRITES=false`, automation budget/status tidak dikirim ke Meta dan API memberi error yang jelas. Setup iklan akan masuk status `ready` tanpa publish ke Meta.

## Queue Worker

Database queue sudah dipakai oleh default project. Jalankan migration dulu:

```bash
php artisan migrate --force
```

Untuk menjalankan worker manual di server dev:

```bash
php artisan queue:work database --queue=meta,default --sleep=3 --tries=3 --timeout=650
```

Setelah deploy perubahan kode:

```bash
php artisan optimize:clear
php artisan queue:restart
```

Worker harus tetap hidup di server. Untuk production/dev server yang long-running, gunakan Supervisor atau process manager lain.

Contoh Supervisor:

```ini
[program:t4jam-meta-worker]
process_name=%(program_name)s_%(process_num)02d
command=php /var/www/demo-digmartools.prosesin.id/artisan queue:work database --queue=meta,default --sleep=3 --tries=3 --timeout=650
autostart=true
autorestart=true
stopasgroup=true
killasgroup=true
user=www-data
numprocs=1
redirect_stderr=true
stdout_logfile=/var/www/demo-digmartools.prosesin.id/storage/logs/queue-worker.log
stopwaitsecs=700
```

Reload Supervisor setelah file config dibuat:

```bash
supervisorctl reread
supervisorctl update
supervisorctl restart t4jam-meta-worker:*
```

Sesuaikan `user` dengan user web server di server masing-masing.

## Rate Limit Meta

Meta dapat mengembalikan rate limit, misalnya:

```text
Meta rate limit hit {"meta_code":17,"meta_type":"OAuthException"}
```

Jika rate limit terjadi setelah account/campaign terbaca, data yang sudah berhasil dibaca tetap disimpan dan `last_meta_error` akan memberi pesan agar user sync ulang setelah beberapa menit.

Hal yang perlu dicek saat dashboard masih `0`:

```bash
php artisan tinker --execute="dump(App\Models\AdAccount::count(), App\Models\Campaign::count(), App\Models\T4JamProfile::first()?->last_meta_error);"
php artisan queue:failed
tail -f storage/logs/laravel.log
tail -f storage/logs/queue-worker.log
```

Jika `ad_accounts` tetap `0`, rate limit atau token error kemungkinan terjadi sebelum endpoint `/me/adaccounts` berhasil. Tunggu beberapa menit, pastikan token masih valid, lalu sync ulang sekali.

Jika hanya sebagian account muncul dan log berisi `Requires business_management permission`, berarti token/app belum bisa membaca Business Manager. Account dasar dari `/me/adaccounts` tetap masuk, tetapi account tambahan dari portfolio bisnis baru masuk setelah permission Business Manager aktif dan access token diotorisasi ulang.

## Deployment Checklist

```bash
git pull
composer install --no-dev --optimize-autoloader
npm ci
npm run build
php artisan migrate --force
php artisan optimize:clear
php artisan queue:restart
```

Pastikan worker `meta,default` aktif setelah deploy.

## Quality Check

```bash
php artisan test
npm run build
git diff --check
```

Catatan lokal Windows: warning PHP tentang `imagick` missing tidak terkait dengan Meta sync selama test tetap hijau.
