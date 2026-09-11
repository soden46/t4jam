# Production audit - 11 September 2026

## FIXED / ROOT CAUSES

| Item | Root cause | Perbaikan |
| --- | --- | --- |
| 1 Activation | Checkbox `active` tidak diterima Request::boolean | Frontend `1`; backend tetap menerima legacy `active`; unchecked false |
| 2 Automation metrics | Payload mendahulukan target generic | Spend/result snapshot task; CPR spend/result, nol hasil memakai spend |
| 3 CPR safety | Recovery cap tidak dibatasi dan provenance pause lemah | Resume cap harus di bawah cap pause; hanya counter aktif dan marker pause automation; manual status membersihkan marker |
| 4 Execution | Task global dibaca untuk setiap profile; log belum lengkap | Owner task, lock per profile, fresh due insights, log evaluasi; kegagalan insight tidak memajukan last_checked_at |
| 5 Refresh | Tidak ada polling | Polling 45 detik endpoint lokal, tanpa overlap, skip hidden/modal, error tanpa toast berulang |
| 6 Token isolation | usableForUser mengambil profile user lain | Selalu profile sendiri; owner check endpoint/task/job/publisher |
| 7 Credentials | Plaintext DB dan prefill HTML | Laravel Crypt cast kompatibel plaintext lama, migration ciphertext, hidden serialization dan dontFlash |
| 8 Google OAuth | Demo fallback bebas environment, stateless callback | Demo hanya local/testing; stateful Socialite, verified email, password existing dipertahankan |
| 9 XSS | Interpolasi string innerHTML tanpa escaping | Escape nama/atribut/log/product; link hanya HTTP(S) |
| 10 Dashboard | Conversion dikirim sebagai setting tetapi tidak memengaruhi metrik | Query conversion + session, snapshot conversion_results, reuse mapping service |
| 11 Retry | Exception ditelan, retry HTTP cepat | Job retry transient/rate limit/5xx dengan jeda dan maksimum attempt; auth fail permanen; hentikan sync saat transient |
| 12 Publish | Transaction rollback menghilangkan ID lokal setelah Meta sukses | Persist ID setiap step, skip ID existing, lock; uncertain create ditahan untuk rekonsiliasi |
| 13 Auth cleanup | Reset pretend success, username tidak disimpan | Broker reset Laravel + token sekali pakai; error jika mail tidak mengirim; hapus input username |
| 14 Seed/docs | Dokumentasi mengklaim demo otomatis | Demo seeder terpisah local/testing; seeder production tidak membuat admin demo/data iklan; label Interest/Product jujur DB-backed |

## FILES CHANGED

- `README.md`
- `app/Casts/MetaCredential.php`
- `app/Exceptions/MetaAdsException.php`
- `app/Http/Controllers/AdSetupController.php`
- `app/Http/Controllers/AuthController.php`
- `app/Http/Controllers/T4JamController.php`
- `app/Jobs/Concerns/RetriesMetaRequests.php`
- `app/Jobs/PublishMetaAdSetup.php`
- `app/Jobs/PushMetaAutomationTaskUpdate.php`
- `app/Jobs/SyncMetaAdsProfile.php`
- `app/Models/AdSet.php`
- `app/Models/AdSetup.php`
- `app/Models/AutomationTask.php`
- `app/Models/Campaign.php`
- `app/Models/T4JamProfile.php`
- `app/Services/AutomationBudgetService.php`
- `app/Services/MetaAdSetupPublisher.php`
- `app/Services/MetaAdsClient.php`
- `app/Services/MetaAdsSyncService.php`
- `bootstrap/app.php`
- `database/migrations/2026_09_11_000001_encrypt_meta_credentials.php`
- `database/migrations/2026_09_11_000002_add_automation_ownership_and_conversion_metrics.php`
- `database/seeders/DatabaseSeeder.php`
- `database/seeders/TestDataSeeder.php`
- `resources/js/app.js`
- `resources/views/auth/new-password.blade.php`
- `resources/views/auth/register.blade.php`
- `resources/views/auth/reset-password.blade.php`
- `resources/views/components/automation-modal.blade.php`
- `resources/views/dashboard.blade.php`
- `resources/views/interest.blade.php`
- `resources/views/products.blade.php`
- `resources/views/profile.blade.php`
- `routes/console.php`
- `routes/web.php`
- `tests/Browser/production-safety.test.mjs`
- `tests/Feature/ExampleTest.php`
- `tests/Feature/MetaPublishRecoveryTest.php`
- `tests/Feature/ProductionSafetyTest.php`
- `tests/Feature/TestDataSeeder.php`
- `tests/TestCase.php`
- `docs/production-audit-2026-09-11.md` (laporan ini)

## MIGRATIONS

- `2026_09_11_000001_encrypt_meta_credentials`: app_secret TEXT dan encrypt existing credential; dapat dijalankan lagi tanpa double-encryption. Rollback mempertahankan ciphertext.
- `2026_09_11_000002_add_automation_ownership_and_conversion_metrics`: nullable user_id task, conversion_results campaign/adset, pending_meta_step publish, default recovery 5000. Existing invalid threshold tidak otomatis diaktifkan kembali.
- Existing populated tables diuji dengan SQLite test database, bukan MySQL production. Database project/production tidak dimigrasikan pada audit ini.
- Backfill task hanya bila tepat satu user. Multi-user legacy task tanpa owner harus dipetakan secara eksplisit sebelum enforcement. Panduan ada di README.

## TESTS ADDED

`ProductionSafetyTest` mencakup create/update checked 1 dan active, unchecked/null/false, client purchase dan lead, adset pause, hysteresis, manual pause, invalid threshold, dashboard conversion/session, isolasi profile/task/log, credential HTML/DB/blank form, migration existing/repeat, OAuth production/state verification, password reset token, dan queue retry/exhaustion/network.

`MetaPublishRecoveryTest` mencakup gagal setelah campaign/adset/creative, retry tanpa create campaign kedua, final published, publish ulang tanpa request tambahan, uncertain create, dan profile mismatch.

`tests/Browser/production-safety.test.mjs` menjalankan Chromium terhadap renderer dan polling aktual dengan endpoint palsu terkontrol: XSS, URL berbahaya, overlap, modal, hidden tab, dan error recovery. Ini bukan uji integrasi aplikasi production end-to-end.

Test lama shared-token/generic-metric disesuaikan menjadi regression atas kontrak baru. Test suite melarang HTTP request tanpa fake agar tidak menghubungi Meta sungguhan.

## TEST RESULT

- `php artisan test`: PASS, 72 tests / 400 assertions.
- `npm.cmd run build` (Windows equivalent npm run build): PASS, Vite 7.3.6.
- `node --test tests/Browser/production-safety.test.mjs`: PASS, 3 tests.
- `php vendor/bin/pint --test --dirty`: PASS.
- `node --check resources/js/app.js`: PASS.
- PHP syntax checks untuk file PHP berubah: PASS.
- `git diff --check`: PASS.
- `php artisan schedule:list` dengan CACHE_STORE=array untuk inspeksi lokal: enforcement `*/5` terdaftar. Ini tidak membuktikan cron/worker server aktif.
- PHP lokal memberi startup warning extension imagick tidak tersedia; tidak menggagalkan checks.

## DEPLOY COMMANDS

Backup database dan APP_KEY sebelum deployment. Pertahankan APP_KEY existing. Hentikan worker lama dan jadwal scheduler sementara dalam maintenance window sebelum migration credential; maintenance mode menghentikan default Laravel scheduled tasks, tetapi tunggu proses yang sudah berjalan selesai.

Contoh setelah git pull (sesuaikan nama Supervisor dengan server):

```bash
php artisan down
supervisorctl stop 't4jam-meta-worker:*'
composer install --no-dev --optimize-autoloader
npm ci
npm run build
php artisan optimize:clear
php artisan migrate --force
php artisan config:cache
php artisan view:cache
php artisan queue:restart
supervisorctl start 't4jam-meta-worker:*'
php artisan up
```

Jangan key:generate, migrate:fresh, atau seed demo di production. Tetapkan owner legacy task yang sudah diverifikasi. Jalankan full sync profile untuk mengisi conversion_results sebelum mengandalkan dashboard Lead/ATC/Checkout/WhatsApp.

## MANUAL SERVER CHECK

```bash
php artisan migrate:status
php artisan schedule:list
crontab -l
supervisorctl status
php artisan queue:failed
php artisan t4jam:sync-meta-ads --profile_id=1
php artisan t4jam:enforce-automation
tail -f storage/logs/laravel.log
```

Ganti profile_id dengan profile yang sudah diverifikasi. Sync/enforce dapat mengubah Meta ketika writes aktif. Worker harus memakai queue meta,default dan timeout 650; DB_QUEUE_RETRY_AFTER harus lebih besar (contoh 900). Verifikasi log evaluation, task last_checked_at, metrik dan status target di Meta Ads Manager. Source code tidak mengubah cron OS.

## REMAINING RISKS

- Belum diuji pada Meta live, Google OAuth live, SMTP nyata, MySQL production, atau proses cron/Supervisor server.
- Isolasi penuh katalog ad_accounts/campaigns/ad_sets masih follow-up; owner automation/publish dan token telah dibatasi.
- Task legacy tanpa owner sengaja tidak dijalankan. Jangan menetapkan semua task ke satu user tanpa bukti.
- Remote create sukses tetapi respons hilang tidak bisa dijamin exactly-once oleh transaksi lokal. Marker pending_meta_step menahan duplicate; rekonsiliasi manual wajib sebelum retry.
- Manual pause melalui aplikasi membersihkan marker automation. Perubahan eksternal di Meta sesudah automation pause memerlukan rekonsiliasi; repository tidak menyediakan audit provenance real-time dari Meta.
- Existing invalid recovery threshold perlu diperbaiki pada konfigurasi. Service tidak auto-resume konfigurasi tersebut.
- Interest/Product tetap database-backed sesuai scope.

Tidak ada commit, push, perubahan cron OS, atau deployment production yang dilakukan.
