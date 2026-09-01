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

`META_ADS_ENABLE_WRITES=false` artinya publish setup iklan dan update budget/status automation tidak dikirim ke Meta. UI harus memberi warning atau error yang jelas. Aktifkan hanya saat token, permission, dan ad account sudah siap.

## Meta Ads Sync

User menyimpan token di halaman `Profile`, lalu klik `Sync Meta Ads`.

Flow saat ini:

1. Controller memvalidasi profile punya access token.
2. Tombol `Sync Meta Ads` di Profile menjalankan sync Meta langsung dan redirect setelah database selesai diperbarui.
3. Tombol `Reload` di dashboard juga menjalankan sync Meta langsung, lalu mengembalikan data dashboard terbaru dari database.
4. Account dibaca dari `/me/adaccounts`, lalu dilengkapi dari Business Manager `/me/businesses` melalui edge `owned_ad_accounts` dan `client_ad_accounts` jika token punya akses.
5. Campaign dan ad set dibaca dari masing-masing ad account/campaign.
6. Campaign/ad set insight ikut dibaca jika Meta tidak menolak request.
7. Error terakhir disimpan di `t4jam_profiles.last_meta_error` dan ditampilkan di Profile.

`MetaAdsSyncService` mengambil struktur account/campaign/ad set dari Meta, menyimpannya ke database, lalu mengambil insight sebagai best-effort. Jika insight kena rate limit, nama campaign/ad set yang sudah terbaca tetap tersimpan dan error terakhir dicatat di `t4jam_profiles.last_meta_error`.

### Auto Sync

Base URL `/` menjalankan auto sync Meta sendiri sebelum redirect ke dashboard. Jika user sudah login, sudah masuk slot 5 jam, dan slot itu belum pernah diproses, aplikasi akan sync Meta lalu redirect ke `/dashboard/`. Tidak perlu cron agar fitur ini aktif saat base URL dibuka.

Command manual tetap tersedia:

```bash
php artisan t4jam:sync-meta-ads
```

Interval 5 jam dipakai supaya data dashboard tetap terbarui tanpa terlalu sering menabrak rate limit Meta seperti `Application request limit reached`. Jadwal slot berjalan pada 00:00, 05:00, 10:00, 15:00, dan 20:00 WIB.

Scheduler Laravel juga disiapkan sebagai opsi tambahan jika server perlu sync tetap berjalan walau dashboard tidak sedang dibuka. Aktifkan dengan cron:

```cron
* * * * * cd /path/to/t4jam-app && php artisan schedule:run >> /dev/null 2>&1
```

Scheduler command memakai `withoutOverlapping(295)`, jadi run berikutnya tidak masuk jika run sebelumnya masih berjalan.

Untuk tes satu profile tertentu:

```bash
php artisan t4jam:sync-meta-ads --profile_id=1
```

## Meta Write Actions

Action Meta dipisah berdasarkan risikonya:

- `Reload` di dashboard dan `Sync Meta Ads` di Profile menjalankan sync baca data akun iklan langsung dari request browser.
- `Create` dan `Update` automation budget mengirim budget ke Meta langsung; setelah Meta sukses, data lokal disimpan.
- Toggle status automation mengirim status ke Meta langsung; setelah Meta sukses, data lokal disimpan.
- `Turun` budget mengirim budget ke Meta langsung; setelah Meta sukses, data lokal disimpan.
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

Worker harus tetap hidup di server untuk action yang masih berjalan di background, seperti publish setup iklan atau sync yang dipicu saat token disimpan. Untuk production/dev server yang long-running, gunakan Supervisor atau process manager lain.

Contoh Supervisor:

```ini
[program:t4jam-meta-worker]
process_name=%(program_name)s_%(process_num)02d
command=php /path/to/t4jam-app/artisan queue:work database --queue=meta,default --sleep=3 --tries=3 --timeout=650
autostart=true
autorestart=true
stopasgroup=true
killasgroup=true
user=www-data
numprocs=1
redirect_stderr=true
stdout_logfile=/path/to/t4jam-app/storage/logs/queue-worker.log
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

Jika rate limit terjadi, `last_meta_error` akan menyimpan pesan dari Meta agar user bisa sync ulang setelah beberapa menit.

Hal yang perlu dicek saat dashboard masih `0`:

```bash
php artisan tinker --execute="dump(App\Models\AdAccount::count(), App\Models\Campaign::count(), App\Models\T4JamProfile::first()?->last_meta_error);"
php artisan queue:failed
tail -f storage/logs/laravel.log
tail -f storage/logs/queue-worker.log
```

Jika `ad_accounts` tetap `0`, rate limit atau token error kemungkinan terjadi sebelum endpoint `/me/adaccounts` berhasil. Tunggu beberapa menit, pastikan token masih valid, lalu sync ulang sekali.

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
