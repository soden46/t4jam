# Tata Cara Setup Webhook T4Jam

Dokumen ini menjelaskan cara mengaktifkan webhook Meta Ads untuk T4Jam agar perubahan dari Meta Ads Manager bisa masuk otomatis ke database Laravel.

Webhook ini bersifat event-driven: Meta mengirim notifikasi perubahan ad account, lalu aplikasi T4Jam menjalankan job queue untuk mengambil snapshot terbaru dari Meta Graph API. Jadi ini bukan payload data lengkap dari Meta, tetapi trigger sync otomatis.

## 1. Prasyarat

Pastikan hal berikut sudah siap:

- Aplikasi T4Jam sudah bisa diakses lewat domain HTTPS publik.
- URL callback tidak memakai `localhost`, IP private, atau self-signed SSL.
- Database production sudah dimigrate.
- Queue worker Laravel berjalan.
- Profile Meta di T4Jam sudah berisi App ID, App Secret, dan Access Token.
- Access token punya akses ke ad account yang akan disinkronkan.
- Ad account sudah pernah tersimpan di database lewat `Sync Meta Ads` atau command sync.

Endpoint webhook yang dipakai project ini:

```text
https://domain-aplikasi/meta/webhook/
```

Route endpoint ada di:

```text
GET  /meta/webhook/
POST /meta/webhook/
```

## 2. Environment

Set env berikut di server production:

```env
APP_URL=https://domain-aplikasi

QUEUE_CONNECTION=database
DB_QUEUE_RETRY_AFTER=900

META_GRAPH_VERSION=v23.0
META_GRAPH_BASE_URL=https://graph.facebook.com
META_GRAPH_TIMEOUT=45

META_ADS_ENABLE_WRITES=true

META_WEBHOOK_VERIFY_TOKEN=isi-token-random-panjang
META_WEBHOOK_APP_SECRET=app-secret-dari-meta-developer
META_WEBHOOK_CALLBACK_URL=https://domain-aplikasi/meta/webhook/
META_WEBHOOK_FIELDS=campaigns,adsets,ads
```

Catatan:

- `META_WEBHOOK_VERIFY_TOKEN` bebas dibuat sendiri, tetapi harus sama persis dengan verify token di Meta Developer Dashboard.
- `META_WEBHOOK_APP_SECRET` harus sama dengan App Secret dari Meta App.
- `META_WEBHOOK_CALLBACK_URL` wajib HTTPS publik.
- `META_ADS_ENABLE_WRITES=true` dibutuhkan jika aplikasi juga harus mengirim update budget/status/publish ke Meta.

Setelah env berubah, clear cache config:

```bash
php artisan optimize:clear
```

## 3. Jalankan Migration dan Worker

Pastikan migration sudah jalan:

```bash
php artisan migrate --force
```

Jalankan worker queue `meta`:

```bash
php artisan queue:work database --queue=meta,default --sleep=3 --tries=3 --timeout=650
```

Untuk production, jalankan worker lewat Supervisor/system service, bukan terminal manual.

## 4. Sync Profile dan Ad Account Awal

Sebelum register webhook, pastikan profile dan ad account sudah ada di database.

Dari UI:

1. Login ke T4Jam.
2. Buka halaman `Profile`.
3. Isi App ID, App Secret, dan Access Token.
4. Jalankan `Sync Meta Ads`.

Atau lewat command:

```bash
php artisan t4jam:sync-meta-ads --profile_id=1
```

Ganti `1` dengan ID profile yang benar.

## 5. Register Webhook Otomatis dari Laravel

Project ini sudah punya command untuk:

- Mendaftarkan callback webhook ke Meta App.
- Subscribe object `ad_account`.
- Subscribe ad account yang dapat diakses profile.
- Menyimpan relasi profile dan ad account lokal.

Jalankan:

```bash
php artisan t4jam:configure-meta-webhook --profile_id=1
```

Jika callback URL ingin dioverride saat command:

```bash
php artisan t4jam:configure-meta-webhook --profile_id=1 --callback_url=https://domain-aplikasi/meta/webhook/
```

Output sukses:

```text
Webhook Meta aktif untuk N ad account.
```

Jika ada ad account yang ditolak Meta saat subscribe webhook, command tetap lanjut untuk account lain dan menampilkan warning:

```text
Ad account act_xxx dilewati: Permission Meta tidak mencukupi.
N ad account dilewati karena Meta menolak subscribe webhook.
```

Kasus ini biasanya terjadi saat user/token punya akses ke banyak ad account, tetapi sebagian account tidak memberi permission yang cukup untuk subscribe webhook. Account yang sukses tetap disimpan ke relasi profile, sedangkan account yang gagal bisa dibereskan permission-nya belakangan lalu command dijalankan ulang.

Jalankan ulang command ini jika:

- App Meta berubah.
- App Secret berubah.
- Callback domain berubah.
- Ada ad account baru yang perlu ikut menerima webhook.

## 6. Setup Manual di Meta Developer Dashboard

Jika ingin cek atau setup manual lewat halaman Meta Developer:

1. Buka `https://developers.facebook.com/apps/`.
2. Pilih Meta App yang dipakai T4Jam.
3. Cocokkan App ID di dashboard Meta dengan App ID yang tersimpan di profile T4Jam.
4. Buka product `Webhooks`.
5. Pada daftar object/produk webhook, pilih `Ad Account`.

Jangan pilih `User` untuk kebutuhan sync campaign/ad set/ad T4Jam. Object yang dibutuhkan adalah `Ad Account`.

Setelah `Ad Account` terpilih, isi form konfigurasi webhook:

**URL Callback**

```text
https://domain-aplikasi/meta/webhook/
```

Contoh production:

```text
https://demo-digmarttools.prosesin.id/meta/webhook/
```

**Verifikasi token**

```text
META_WEBHOOK_VERIFY_TOKEN
```

Contoh jika env berisi `META_WEBHOOK_VERIFY_TOKEN=t4jam_webhook`:

```text
t4jam_webhook
```

Lalu klik `Verifikasi dan simpan`.

Jika berhasil, subscribe field secara bertahap. Mulai dari:

```text
campaigns
```

Jika `campaigns` sukses, lanjutkan dengan:

```text
adsets
```

Jika `adsets` sukses, lanjutkan dengan:

```text
ads
```

Jika Meta menolak field sejak `campaigns`, biasanya masalahnya ada di permission Meta App, bukan di Laravel. Cek permission/app review untuk `ads_read` dan `ads_management`, pastikan user token berasal dari app yang sama, dan pastikan user tersebut punya role atau akses yang benar ke app/ad account.

Terakhir, pastikan ad account juga tersubscribe ke app. Command `t4jam:configure-meta-webhook` akan melakukan bagian ini otomatis untuk ad account yang dapat diakses profile.

Command `t4jam:configure-meta-webhook` biasanya lebih aman karena melakukan konfigurasi callback dan subscribe ad account lewat Graph API.

## 7. Cara Kerja Saat Ada Perubahan di Meta

Alur dari Meta Ads Manager ke T4Jam:

```text
Meta Ads Manager CRUD
-> Meta mengirim POST /meta/webhook/
-> Laravel validasi X-Hub-Signature-256
-> Laravel dispatch SyncMetaAdsAccount ke queue meta
-> Worker mengambil data terbaru dari Meta Graph API
-> Database lokal diperbarui
-> Dashboard/Automation membaca DB lokal
```

Data yang disync dari webhook account:

- Ad account
- Campaign
- Ad set
- Campaign insight
- Ad set insight
- Rekonsiliasi automation task terhadap status/budget terbaru dari Meta

## 8. Cara Kerja dari T4Jam ke Meta

Beberapa aksi dari T4Jam sudah langsung mengirim request ke Meta Graph API:

- Create automation task: push budget ke Meta.
- Update automation task: push budget/status ke Meta.
- Toggle status automation: push status ke Meta.
- Turun budget manual: push budget ke Meta.
- Setup iklan publish: create campaign, ad set, creative, dan ad lewat queue.
- Enforcement automation: pause/resume target saat aturan CPR atau jadwal terpenuhi.

Untuk write action, pastikan:

```env
META_ADS_ENABLE_WRITES=true
```

Jika `META_ADS_ENABLE_WRITES=false`, aplikasi tidak akan mengirim update budget/status/publish ke Meta.

## 9. Verifikasi

Cek route callback:

```bash
php artisan route:list | findstr meta/webhook
```

Cek scheduler:

```bash
php artisan schedule:list
```

Cek queue failed:

```bash
php artisan queue:failed
```

Pantau log:

```bash
tail -f storage/logs/laravel.log
```

Log yang perlu dicari:

```text
meta webhook account sync queued
webhook account sync started
webhook account sync finished
meta webhook rejected invalid signature
```

Tes dari sisi Meta:

1. Ubah status/budget campaign atau ad set di Meta Ads Manager.
2. Tunggu webhook masuk dan worker memproses job.
3. Cek data di T4Jam.
4. Cek `last_meta_sync_at` di profile.
5. Cek log `[T4JAM_META_FLOW]`.

## 10. Troubleshooting

### Callback gagal diverifikasi Meta

Cek:

- URL harus HTTPS publik.
- SSL harus valid, bukan self-signed.
- `META_WEBHOOK_VERIFY_TOKEN` sama persis dengan token di Meta Dashboard.
- `php artisan optimize:clear` sudah dijalankan setelah env berubah.
- Route `GET /meta/webhook/` tidak diblokir server/proxy.

### Webhook POST 401 invalid signature

Cek:

- `META_WEBHOOK_APP_SECRET` harus sama dengan App Secret Meta App.
- Jangan isi dengan verify token.
- Clear config cache setelah env berubah.

### Webhook received tapi data tidak berubah

Cek:

- Queue worker `meta` berjalan.
- `QUEUE_CONNECTION=database`.
- Migration queue sudah ada.
- Tidak ada job failed di `php artisan queue:failed`.
- Access token profile masih valid.
- Ad account sudah terkait dengan profile.

### Perubahan dari Meta lambat masuk

Webhook hanya men-trigger job. Kecepatan update tergantung:

- Kecepatan Meta mengirim event.
- Antrian queue.
- Worker aktif atau tidak.
- Rate limit Meta Graph API.
- Jumlah campaign/ad set/insight yang harus disync.

### Browser belum berubah walau DB sudah update

Dashboard dan Automation membaca database lokal secara polling. Tunggu polling berikutnya atau reload halaman.

## 11. Batasan Saat Ini

Webhook membuat perubahan dari Meta masuk otomatis ke Laravel, tetapi ini belum berarti CRUD penuh dua arah untuk semua entity.

Yang sudah didukung:

- Event webhook ad account.
- Sync campaign, ad set, dan insight setelah event.
- Rekonsiliasi status/budget automation task dari data Meta.
- Push beberapa aksi T4Jam ke Meta, terutama budget/status automation dan publish setup iklan.

Yang perlu diperhatikan:

- Meta webhook tidak mengirim semua detail data lengkap; aplikasi tetap mengambil data terbaru lewat Graph API.
- Delete automation task di T4Jam hanya menghapus task lokal, bukan menghapus campaign/ad set di Meta.
- Entity ads belum dimodelkan penuh sebagai tabel lokal seperti campaign/ad set.
- Untuk pengalaman benar-benar push realtime ke browser, perlu WebSocket atau SSE. Saat ini UI memakai polling lokal.

## 12. Runbook Cepat Production

Urutan aman setelah deploy:

```bash
php artisan migrate --force
php artisan optimize:clear
php artisan t4jam:sync-meta-ads --profile_id=1
php artisan t4jam:configure-meta-webhook --profile_id=1
php artisan queue:work database --queue=meta,default --sleep=3 --tries=3 --timeout=650
```

Lalu cek:

```bash
php artisan queue:failed
tail -f storage/logs/laravel.log
```

Jika semua normal, perubahan campaign/ad set/ad dari Meta Ads Manager akan memicu webhook, lalu T4Jam akan menyinkronkan data terbaru lewat queue.
