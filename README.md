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

DatabaseSeeder hanya membuat admin/profile untuk local/testing. Data iklan demo tidak otomatis disediakan oleh `migrate --seed`. Interest dan Product membaca database lokal; belum ada live Meta Interest API atau scraper Tokopedia.

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

Pastikan `.env` mengarah ke database lokal yang benar. Untuk data demo **hanya local/testing**:

```bash
php artisan db:seed --class=TestDataSeeder
```

Kedua seeder menolak membuat data di production. Admin lokal: `admin@t4jam.local` / `password`; jangan gunakan akun demo di production.

## Environment Penting

```env
APP_URL=http://localhost:8000
DB_CONNECTION=mysql
QUEUE_CONNECTION=database
DB_QUEUE_RETRY_AFTER=900

META_GRAPH_VERSION=v23.0
META_GRAPH_BASE_URL=https://graph.facebook.com
META_GRAPH_TIMEOUT=45
META_ADS_ENABLE_WRITES=false
META_WEBHOOK_VERIFY_TOKEN=buat-token-verifikasi-yang-panjang
META_WEBHOOK_APP_SECRET=app-secret-meta
META_WEBHOOK_CALLBACK_URL=https://domain-aplikasi/meta/webhook/
META_WEBHOOK_FIELDS=campaigns,adsets,ads
```

`META_ADS_ENABLE_WRITES=false` artinya publish setup iklan dan update budget/status automation tidak dikirim ke Meta. UI harus memberi warning atau error yang jelas. Aktifkan hanya saat token, permission, dan ad account sudah siap.

## Meta Ads Sync

User menyimpan token di halaman `Profile`.

Flow sync dibagi menjadi dua jenis:

1. `Sync Meta Ads` di Profile menjalankan full sync Meta Ads.
2. `Reload` di Dashboard hanya menyinkronkan ad account yang sedang dipilih dan daftar campaign milik account tersebut.
3. Quick reload Dashboard membaca `/{ad_account_id}` dan `/{ad_account_id}/campaigns`.
4. Quick reload tidak mengambil ad set maupun insights supaya request tetap ringan dan campaign baru bisa segera muncul.
5. Full sync membaca ad account, campaign, ad set, dan insights.
6. Error Meta terakhir disimpan di `t4jam_profiles.last_meta_error` dan ditampilkan di Profile.

Quick reload sengaja dipisahkan dari full sync untuk mengurangi jumlah request ke Meta Graph API dan menghindari rate limit ketika user hanya membutuhkan daftar campaign terbaru.

Perubahan dari Ads Manager masuk lewat webhook `ad_account`. Endpoint memverifikasi `X-Hub-Signature-256`, lalu menjalankan sync account setelah response (`META_WEBHOOK_SYNC_MODE=after_response`) agar snapshot account/campaign/ad set dan insights tetap ter-update meskipun worker queue belum aktif. Set `META_WEBHOOK_SYNC_MODE=queue` kalau server sudah punya worker `meta` yang stabil. Dashboard dan Automation mem-poll database lokal setiap 5 detik, sehingga tab aktif ikut berubah tanpa refresh dan tanpa menambah request Graph API dari browser.

Setelah deploy, daftar callback app dan subscribe seluruh ad account yang bisa diakses profile:

```bash
php artisan t4jam:configure-meta-webhook --profile_id=1
```

Callback harus HTTPS publik. Jalankan ulang command saat app Meta atau kumpulan ad account berubah.

### Auto Sync

Base URL `/` tidak menjalankan Meta sync. Membuka aplikasi hanya mengarahkan user ke `/dashboard/`.

Full sync terjadwal tersedia melalui command:

```bash
php artisan t4jam:sync-meta-ads
```

Scheduler menjalankan command setiap 5 jam pada timezone Asia/Jakarta.

Pemeriksaan CPR cap berjalan setiap 5 menit melalui command berikut. Command ini mengambil insight target langsung dari Meta Ads, memperbarui metrik lokal, lalu mengevaluasi CPR:

```bash
php artisan t4jam:enforce-automation
```

CPR dihitung sebagai spend / hasil, dibulatkan ke bawah (atau spend jika hasil nol), dari event conversion yang dipilih dan Insights Meta yang segar. Kegagalan membaca Insights tidak dianggap sebagai pemeriksaan berhasil.

Flow automation budget:

- Scale: hasil bertambah dibanding hasil yang sudah dipakai untuk kenaikan, minimal dua hasil, dan `CPR < CPR Cap`. Tidak ada cooldown wajib 72 jam. Hasil yang sama tidak menaikkan budget dua kali.
- Besaran kenaikan: dua level 20%, dengan minimum budget level pertama 100.000; angka ini merupakan inferensi yang dipisahkan di `config/automation.php`, bukan formula backend referensi yang sudah terverifikasi.
- Hold: Default memerlukan spend >= budget; No Hold X3 memakai spend x 3 >= budget; Loss Doll tanpa gerbang spend. Kondisi numerik Hold ini masih inferensi.
- Hybrid: kontrol manual naik/turun dua level; perlindungan CPR tetap otomatis. Perilaku otomatis Hybrid referensi belum diketahui.
- Pause: `CPR >= Pause CPR Cap` dan toggle pause aktif. Budget/spend mencapai batas budget tidak menjadi trigger pause.
- Recovery: jika Aktifkan Lagi aktif dan pause berasal dari automation, CPR harus memenuhi CPR Cap sekaligus aman di bawah ambang pause, dengan minimal satu hasil. Task lama tetap memakai Resume CPR lama sampai diedit melalui form baru.
- Recovery hari berikutnya: tetap memerlukan provenance pause CPR, pergantian hari akun, jam ON/OFF, Insights hari ini yang segar, CPR sehat, dan konfirmasi aktivasi Meta. Pause manual tidak ikut aturan ini.
- Maximum Budget membatasi kenaikan; nol berarti unlimited. Starting Budget menjadi batas bawah kontrol turun Hybrid dan nilai reset budget.
- Penanda hasil baru disimpan setelah Meta mengonfirmasi write. Polling dan refresh metrik tidak mengonsumsi hasil. Perubahan conversion mereset penanda; reset budget manual tidak mengizinkan hasil lama dipakai lagi.

Bukti referensi, asumsi yang belum terverifikasi, kompatibilitas task lama, serta langkah migrasi ada di [audit flow automation](docs/automation-reference-parity.md). Jalankan migrasi sebelum worker memakai kode baru.

### Spend harian dan kesegaran dashboard

`META_GRAPH_AUTOMATION_INSIGHTS_DATE_PRESET=today` adalah default automation, terpisah dari default Insights umum `last_30d`. Tampilan, rekonsiliasi, full sync metrik automation, dan pemeriksaan CPR menggunakan periode automation yang sama. Override periode selain `today` menonaktifkan recovery harian. Budget/spend mencapai batas budget tidak memicu auto-pause; maximum budget hanya membatasi kenaikan.

Rekonsiliasi `t4jam:reconcile-meta-automation` dijadwalkan setiap menit, dengan interval kesegaran default 180 detik, termasuk untuk task paused. Dashboard membaca database setiap 5 detik dan menandai data yang melewati interval itu sebagai stale. Jika cron server tidak berjalan, polling dashboard tidak mengambil data baru dari Meta.

Respons target Insights kosong yang berhasil pada hari baru memulai spend/hasil dari nol. Respons kosong tidak menghapus angka positif yang sudah tersinkron hari ini. Error API tetap mempertahankan data terakhir dan menandainya unavailable. Pergantian hari mengikuti `timezone_name` akun yang disimpan saat sync akun; akun lama tanpa timezone memakai `Asia/Jakarta`. Jam ON/OFF tetap menggunakan `Asia/Jakarta`.

Deploy perubahan recovery harian memerlukan migration `2026_10_02_000001_add_automation_daily_recovery_state` dan konfigurasi automation `today`, lalu refresh config cache sesuai proses deployment. Waktu pause CPR disimpan dalam `cpr_paused_at`, terpisah dari waktu pemeriksaan metrik. Task CPR lama yang tidak memiliki catatan waktu pause mulai menghitung pergantian hari dari waktu migration; migration tidak menyalakan iklan. Pastikan scheduler dan sync akun berjalan untuk mendapatkan timezone akun yang sebenarnya.

Agar scheduler berjalan otomatis di server, aktifkan Laravel scheduler melalui cron:

```cron
* * * * * cd /path/to/t4jam-app && php artisan schedule:run >> /dev/null 2>&1
```

Full sync memakai `withoutOverlapping(295)` dan enforcement CPR memakai lock terpisah agar proses yang sama tidak berjalan bersamaan.

Untuk tes satu profile tertentu:

```bash
php artisan t4jam:sync-meta-ads --profile_id=1
```

## Meta Write Actions

Action Meta dipisah berdasarkan risikonya:

- `Reload` di Dashboard hanya mengambil ulang selected ad account dan campaign-nya.
- `Sync Meta Ads` di Profile menjalankan full sync account, campaign, ad set, dan insights.
- `Create` dan `Update` automation budget mengirim budget ke Meta langsung; setelah Meta sukses, data lokal disimpan.
- Toggle status automation mengirim status ke Meta langsung; setelah Meta sukses, data lokal disimpan.
- Automation enforcement mem-pause campaign/ad set saat CPR mencapai atau melewati cap yang dikonfigurasi.
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
php artisan t4jam:post-deploy-sync --profile_id=1
php artisan queue:restart
```

Pada server `APP_ENV=production`, app juga punya fallback otomatis: request web pertama setelah release berubah akan menjalankan `t4jam:post-deploy-sync` sekali setelah response. Ini menjaga server baru tetap sinkron walaupun post-deploy hook belum dipasang. Gunakan env berikut bila ingin mengatur perilakunya:

```bash
META_AUTO_POST_DEPLOY_SYNC=true
META_AUTO_POST_DEPLOY_PROFILE_ID=1
META_AUTO_POST_DEPLOY_CONFIGURE_WEBHOOK=false
```

Tambahkan `--configure-webhook` jika callback/app/ad account Meta juga perlu diregister ulang setelah deploy:

```bash
php artisan t4jam:post-deploy-sync --profile_id=1 --configure-webhook
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

### Worker permanen Digmartools

Config siap pakai untuk `/var/www/demo-digmartools.prosesin.id` tersedia di
`deploy/supervisor/t4jam-meta-worker.conf`. Config ini memakai `www-data`, antrean
`meta,default`, timeout 700 detik, dan stopwait 750 detik. Pastikan `/usr/bin/php`
adalah PHP CLI project. `DB_QUEUE_RETRY_AFTER=900` harus lebih besar daripada
timeout worker dan seluruh job (job full sync: 650 detik).

Setelah kode baru terpasang, jalankan dari direktori project:

```bash
sudo -u www-data php artisan config:cache
sudo -u www-data php artisan t4jam:queue-status
sudo -u www-data php artisan queue:failed
```

`queue-status` membaca konfigurasi dan antrean tanpa menjalankan job atau
memanggil Meta. Output mencantumkan jenis job, jumlah, attempt, dan waktu
available/reserved; payload dan credential tidak dicetak. Exit code bukan nol
menandakan driver/timing tidak sesuai. Periksa jenis job sebelum menyalakan
worker: `PublishMetaAdSetup` dapat membuat iklan, dan job automation dapat
mengubah budget/status jika writes aktif. Perintah ini tidak membuktikan worker
OS hidup; proses dan Supervisor tetap diperiksa terpisah.

Di `sudo crontab -e`, hapus hanya baris worker Digmartools berikut:

```cron
* * * * * cd /var/www/demo-digmartools.prosesin.id && php artisan queue:work --stop-when-empty >> /dev/null 2>&1
```

Pertahankan baris `schedule:run` Digmartools dan seluruh cron aplikasi lain.
Kemudian hentikan worker manual Digmartools bila masih ada; jangan hentikan
worker Fortindo/Proton. `queue:restart` memberi sinyal worker aplikasi ini
untuk keluar setelah job yang sedang berjalan selesai:

```bash
sudo -u www-data php artisan queue:restart
ps -eo pid,user,args | grep -E '[a]rtisan (queue:work|queue:listen|horizon)'
```

Setelah worker lama Digmartools keluar, pasang program Supervisor ini:

```bash
sudo install -m 0644 deploy/supervisor/t4jam-meta-worker.conf /etc/supervisor/conf.d/t4jam-meta-worker.conf
sudo supervisorctl reread
sudo supervisorctl update t4jam-meta-worker
sudo supervisorctl status t4jam-meta-worker:*
sudo -u www-data php artisan t4jam:queue-status
sudo -u www-data php artisan queue:failed
tail -n 50 storage/logs/queue-worker.log
```

Pada deploy berikutnya, gunakan `php artisan queue:restart`; Supervisor
menjalankan worker kembali setelah job aktif selesai. Config juga merotasi log
dan mengganti proses worker setiap satu jam.

Job yang sudah tersimpan sebelum perubahan retry tetap mengikuti payload lama
sampai operator melakukan retry eksplisit (`queue:retry UUID`). Retry eksplisit
memulai window dan hitungan kegagalan baru tanpa mengubah argumen/ID objek Meta.
Job baru mempunyai batas waktu retry empat jam; penundaan cooldown lokal/lock
tidak menghabiskan jatah tiga kegagalan request Meta. Job gagal lama tidak
otomatis di-retry; periksa penyebab dan hasil Meta, lalu retry hanya UUID yang
telah diverifikasi. Jangan menjalankan `queue:retry all` untuk job publish
dengan hasil create yang belum pasti.

Publish memakai antrean yang dikonfigurasi (`meta` pada koneksi database server).
Job after-response yang tertahan cooldown atau lock menyimpan retry ke antrean
worker; retry tersebut tidak hilang saat request web selesai. Jika worker
menggagalkan publish karena timeout atau batas retry, status lokal menjadi
`failed` sambil mempertahankan ID Meta dan `pending_meta_step` untuk rekonsiliasi.

Reconciliation melaporkan `skip_reason=lock_busy` saat pemeriksaan lain masih
berjalan dan `failed_groups` saat request Meta gagal. Kedua kondisi tersebut
tidak memperbarui timestamp sukses sync profil. Pending pause baru terkonfirmasi
ketika status target dari Meta adalah `PAUSED`; status seperti
`CAMPAIGN_PAUSED` pada ad set tidak menjadi bukti pause target berhasil.

## Rate Limit Meta

Meta dapat mengembalikan rate limit, misalnya:

```text
Meta rate limit hit {"meta_code":17,"meta_type":"OAuthException"}
```

Queue sync, publish, dan update automation membatasi kegagalan request Meta maksimal 3 kali. Job baru boleh menunggu cooldown lokal atau lock hingga batas waktu empat jam sejak dibuat; penantian sebelum request tidak dihitung sebagai kegagalan provider. Rate limit provider, network error, dan 5xx dijeda minimal 60/180/300 detik, mengikuti `Retry-After` sampai 3600 detik. Token invalid/permission gagal permanen. HTTP client tidak melakukan retry cepat tersembunyi. `last_meta_error` menyimpan pesan aman, tanpa raw response/token. Batas retry membutuhkan cache bersama yang persisten; jangan memakai cache `array` untuk worker server.

Publish menyimpan `meta_campaign_id`, `meta_adset_id`, `meta_creative_id`, dan `meta_ad_id` segera setelah setiap sukses, kemudian melanjutkan step yang belum selesai. Status: draft ? publishing ? published, atau failed. Bila create timeout/5xx atau tidak mengembalikan ID, `pending_meta_step` menahan create ulang: periksa Meta Ads Manager, isi ID yang sudah dibuat pada field terkait, lalu kosongkan `pending_meta_step` setelah hasil diverifikasi. Jangan kosongkan marker atau mengulang create sebelum rekonsiliasi.

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

Pastikan worker `meta,default` aktif setelah deploy. Sebelum migration credential, backup database dan simpan `APP_KEY` yang sama; **jangan menjalankan key:generate pada deployment existing**. Hentikan worker lama dan scheduler sementara saat migration agar kode lama tidak membaca ciphertext. Gunakan maintenance window dan Supervisor sesuai setup server.

Migration `2026_09_11_000001` memperlebar app_secret menjadi TEXT lalu mengenkripsi credential existing secara bertahap. Pembacaan plaintext lama tetap kompatibel; penulisan baru selalu terenkripsi menggunakan Laravel Crypt. Rollback tidak mendekripsi credential. Field form kosong mempertahankan credential sebelumnya dan credential tidak dimasukkan ke HTML/session old input.

Migration `2026_09_11_000002` menambah owner automation, snapshot conversion campaign/adset, dan marker publish. Hanya database dengan tepat satu user yang mendapat backfill owner otomatis. Pada database multi-user, task lama tanpa `user_id` **tidak dieksekusi dan tidak tampil pada user lain**. Audit lalu tetapkan owner per task yang telah diverifikasi lewat Tinker, misalnya:

```php
App\Models\AutomationTask::whereNull('user_id')->get(['id', 'campaign_external_id', 'ad_account_name']);
// Ganti TASK_UUID dan OWNER_USER_ID dengan hasil verifikasi, bukan user sembarang.
App\Models\AutomationTask::whereKey('TASK_UUID')->whereNull('user_id')->update(['user_id' => OWNER_USER_ID]);
```

Token setiap user wajib disimpan pada profile sendiri. Schema akun/campaign/adset masih global; tabel pivot `meta_ad_account_profiles` dipakai untuk mengarahkan event webhook ke credential profile yang memang pernah menyinkronkan account tersebut. Isolasi penuh katalog masih merupakan follow-up. Task baru dan job automation/publish sudah memeriksa owner. Tidak ada fallback credential lintas user.

Dashboard menyimpan hasil per conversion dari full sync; jalankan full sync profile setelah migration untuk mengisi hasil Lead/ATC/Checkout/WhatsApp. Data lama hanya punya Purchase generic. Dashboard dan Automation memakai polling database lokal 5 detik; tab hidden/modal terbuka menunda polling. Endpoint daftar Automation selalu membaca database, termasuk initial load dan `local=0`; scheduler reconciliation bertugas memperbarui metrik dari Meta.

### Manual server check

```bash
php artisan migrate:status
php artisan schedule:list
crontab -l
supervisorctl status
php artisan queue:failed
php artisan t4jam:configure-meta-webhook --profile_id=1
php artisan t4jam:sync-meta-ads --profile_id=1
php artisan t4jam:enforce-automation
tail -f storage/logs/laravel.log
```

Command sync/enforce menjalankan Meta request dan dapat mengubah budget/status jika writes aktif; gunakan profile/target yang telah diverifikasi. `schedule:list` harus menampilkan enforcement setiap 5 menit. Log `[T4JAM_META_FLOW] automation evaluation` memuat profile/task/target/conversion/spend/result/CPR/cap/action/reason. `last_checked_at` tidak maju jika insight gagal. Source code tidak mengubah cron OS.

### Authentication

Google demo fallback hanya local/testing. Production memerlukan `GOOGLE_CLIENT_ID`, `GOOGLE_CLIENT_SECRET`, dan redirect URI yang sesuai konfigurasi `services.google`; callback memakai state verification Socialite. Password akun existing tidak diganti saat login Google.

Password reset memakai broker Laravel dan token sekali pakai. Konfigurasikan mail transport pengirim yang nyata serta `MAIL_FROM_ADDRESS`; mail `log/array` menampilkan error konfigurasi. Username tidak digunakan: input dan validation telah dihapus, login tetap email.

## Quality Check

```bash
php artisan test
npm run build
node --test tests/Browser/production-safety.test.mjs
php vendor/bin/pint --test --dirty
git diff --check
```

Catatan lokal Windows: warning PHP tentang `imagick` missing tidak terkait dengan Meta sync selama test tetap hijau.
