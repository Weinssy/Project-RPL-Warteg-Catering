# Admin Warteg (proyek baru, mandiri)

PHP Native + MySQLi + MySQL. Tidak memakai database/tabel/file proyek lama.

## Instalasi di Laragon
1. Salin folder `warteg` ke `C:\laragon\www\warteg`, lalu start Apache/Nginx + MySQL.
2. phpMyAdmin -> tab Import -> pilih `database/schema.sql` (membuat database baru `warteg_admin`).
3. (Opsional, data contoh berlabel DEMO) Import `database/seed_demo.sql`.
4. Buka `http://localhost/warteg/` atau `http://warteg.test/`. Login: `admin` / `admin123` (segera ganti).
5. Jika user/password MySQL berbeda, ubah `config/koneksi.php`. Nama warteg & kapasitas: `config/app.php`.

## Pembaruan pada database yang SUDAH berjalan
Jalankan migrasi (cukup sekali) lewat phpMyAdmin -> pilih database `warteg_admin` -> tab **SQL** -> tempel isi file -> **Go**:
- `database/migrasi_stok_menu_harian.sql` (kolom `menu_harian.stok`)
- `database/migrasi_foto_menu_harian.sql` (kolom `menu_harian.foto`)
- `database/migrasi_foto_admin.sql` (**kolom `admin.foto`** — foto profil admin)
- `database/migrasi_pembayaran.sql` (**kolom pembayaran + `token_nota` + `catatan`, serta tabel `pembayaran_log` & `wa_log`** — untuk pemesanan pelanggan, QRIS, dan notifikasi WA).
- `database/migrasi_qris_statis.sql` (**QRIS STATIS**: kolom `bukti_bayar`, `bukti_bayar_pada`, `alasan_tolak`, `admin_verifikator_id`, `diverifikasi_pada` pada `pesanan`; memperluas `status_bayar` dengan `menunggu_verifikasi`, `ditolak`, `dibatalkan`; tabel `konfigurasi_toko` & `verifikasi_log`). **Wajib dijalankan setelah `migrasi_pembayaran.sql`.**

Migrasi `migrasi_pembayaran.sql` dan `migrasi_qris_statis.sql` hanya MENAMBAH kolom/tabel (tidak menghapus data). Aman diulang bila sebagian sudah ada.

Migrasi `migrasi_foto_admin.sql` hanya MENAMBAH kolom `admin.foto VARCHAR(255) NULL`; data admin, username,
dan password lama tidak diubah. Jika muncul galat `Duplicate column name 'foto'`, kolom sudah ada (migrasi tidak perlu diulang).
Folder `uploads/admin/` dibuat otomatis saat foto pertama diunggah (disertai `.htaccess` + `index.html` sebagai pengaman).

## Pemesanan Pelanggan, Pembayaran QRIS, dan Notifikasi WhatsApp
Pelanggan memesan tanpa membuat akun (guest checkout) lewat `index.php`. Alur:
`index.php` (katalog + meal builder + formulir) -> `pesan_aksi.php` (validasi & simpan pesanan) ->
`pembayaran.php` (tampil QRIS + polling status) -> `webhook.php` (verifikasi pembayaran) -> `nota.php` (nota/status).

**Aturan pelanggan (divalidasi di backend, bukan JavaScript):**
- Minimum `MINIMAL_BOX` box, kapasitas harian `KAPASITAS_HARIAN` box per tanggal.
- Tanggal pengambilan H-`MINIMAL_H_MINUS` s.d. `MAKS_HARI_KE` hari ke depan.
- Jam pengambilan `JAM_BUKA`–`JAM_TUTUP` WIB.
- Harga, stok per menu, dan kuota SELALU dihitung ulang di server saat checkout.

**Keamanan pembayaran:**
- Status pembayaran (`status_bayar`) HANYA berubah lewat `webhook.php` yang memverifikasi tanda tangan
  (Midtrans: `signature_key` SHA512; Xendit: header `x-callback-token`) dan **nominal yang cocok** dengan tagihan.
- Idempoten lewat `pembayaran_log.fingerprint` (transaksi yang sama tidak diproses dua kali).
- Status "berhasil" tidak pernah diturunkan oleh callback `expire`/`deny` berikutnya.
- Nota/pembayaran/cek status dilindungi `token_nota` (acak 40 hex), bukan hanya kode berurutan.

**Konfigurasi QRIS (kredensial HANYA via environment/server, jangan di-commit):**
Set kunci lewat environment variable di server (mis. SetEnv/`.htaccess`/panel host), lalu sesuaikan `config/app.php`:
- `PAYMENT_PROVIDER` = `midtrans` | `xendit` | `mock` (mock khusus uji lokal) | kosong (belum dikonfigurasi).
- Midtrans: `MIDTRANS_SERVER_KEY`, `MIDTRANS_CLIENT_KEY`, `MIDTRANS_IS_PRODUCTION` (0/1).
- Xendit: `XENDIT_API_KEY`, `XENDIT_CALLBACK_TOKEN`.
- `APP_URL_PUBLIK` (opsional) untuk URL callback. Webhook/notification URL: `https://domain/pembayaran...` -> daftarkan
  `https://domain/webhook.php` pada dashboard gateway.
- Bila belum dikonfigurasi: sistem **tidak membuat QR palsu**; pesanan tetap tersimpan dengan status `belum_bayar` dan UI
  menampilkan pesan "belum dikonfigurasi".

**Notifikasi WhatsApp otomatis ke admin:**
- Dikirim otomatis dari server SETELAH pembayaran terverifikasi (bukan lewat tautan wa.me pelanggan).
- Konfigurasi: `WA_TOKEN`, `WA_PHONE_ID`, `WA_API_VERSION`, `WA_ADMIN` (format `62xxx`).
- Kegagalan kirim TIDAK membatalkan pembayaran; dicatat di `wa_log` (status `terkirim`/`gagal`/`menunggu`).
- Bila WA belum dikonfigurasi, tersedia fallback tautan wa.me (perlu tindakan operator) tanpa mengaktifkan kirim otomatis.

**Mode QRIS STATIS (verifikasi manual admin):**
Selain gateway dinamis di atas, sistem mendukung **QRIS Statis** tanpa gateway: customer scan gambar QRIS toko,
memasukkan nominal SECARA MANUAL, lalu mengunggah bukti pembayaran. Alur:
`pembayaran.php` (tampil gambar QRIS statis + total tagihan + form unggah bukti) -> status `menunggu_verifikasi` ->
Admin membuka **Verifikasi Pembayaran** (`admin/verifikasi.php`) -> **Setujui** (status jadi `berhasil`/LUNAS) atau **Tolak** (status `ditolak` + alasan; customer boleh kirim ulang bukti).
- Gambar QRIS diatur Owner di **Pengaturan QRIS** (`admin/qris.php`): file disimpan di `uploads/qris/` (nama file aman + `.htaccess`), path-nya di tabel `konfigurasi_toko`.
- Bukti bayar customer disimpan di `uploads/bukti/` (nama file acak hex 32 + ekstensi gambar). Bukti HANYA dapat dilihat admin melalui endpoint terproteksi `admin/bukti_bayar.php?kode=...` (bukan URL publik langsung).
- Setiap keputusan verifikasi dicatat di `verifikasi_log` beserta admin verifikator (`pesanan.admin_verifikator_id`, `diverifikasi_pada`).
- QRIS statis berarti nominal TIDAK otomatis terisi — UI menegaskan customer wajib memasukkan nominal total tagihan secara manual.
- Jika hanya QRIS statis yang dikonfigurasi (tanpa gateway), `pesan_aksi.php` TIDAK memanggil gateway dan langsung mengarahkan customer ke halaman pembayaran.

## Pengujian
- `php tests/uji_kuota.php` (jalankan dari terminal Laragon): uji minimum, 120 box, kuota terlampaui, menu nonaktif, snapshot harga, dan dua pesanan bersamaan.
- `php tests/uji_pemesanan_pelanggan.php`: aturan H-1, jam operasional, pembuatan pesanan, transaksi QRIS (mock), webhook berhasil, idempotensi, nominal salah ditolak, status lunas tidak diturunkan, token nota, log WA.
- `php tests/uji_webhook_signature.php`: verifikasi signature Midtrans (SHA512) & token Xendit, pemetaan status gateway.
- `php tests/uji_qris_statis.php`: alur QRIS STATIS + verifikasi manual admin (checkout snapshot, upload bukti -> menunggu_verifikasi, setuju -> LUNAS, tolak + alasan, kirim ulang, verifikasi ganda ditolak, log verifikasi, konfigurasi toko).
- `php tests/uji_http_qris.php`: halaman pembayaran menampilkan QRIS statis & instruksi nominal manual, akses invoice tanpa token ditolak, invoice setelah LUNAS (jalankan `http://localhost/warteg/warteg`).
- `php tests/uji_http_admin_verifikasi.php`: login admin -> setujui pembayaran -> invoice LUNAS; bukti_bayar terproteksi; verifikasi ganda ditolak.
- `php tests/uji_http_upload_bukti.php`: unggah bukti multipart valid -> status menunggu_verifikasi, file tersimpan aman (nama hex), upload non-gambar ditolak.
- `php tests/uji_http_pelanggan.php` (jalankan `php -S 127.0.0.1:8765 -t .` lebih dulu): CSRF, validasi AJAX, pembuatan pesanan via HTTP, akses nota via token, endpoint status.
- `php tests/uji_stok_menu.php`: uji stok per menu (NULL = tanpa batas, stok habis, transaksi campuran atomik).
- `php tests/uji_isolasi_tanggal.php`: perubahan stok/foto satu tanggal tidak mengubah tanggal lain.
- Uji HTTP (jalankan `php -S 127.0.0.1:8765 -t .` dari folder proyek lebih dulu):
  - `php tests/uji_http_edit.php` (endpoint edit menu)
  - `php tests/uji_http_stok.php` (validasi stok via edit menu: 0..120, tolak negatif/desimal/>120)
  - `php tests/uji_http_tambah_menu.php` (halaman Tambah Menu: simpan stok + validasi backend)
  - `php tests/uji_http_admin.php` (Manajemen Admin: tambah admin, validasi username/password, upload foto, CSRF, tolak file PHP palsu & file > 2 MB)
  - `php tests/uji_smoke_render.php` (semua halaman tampil tanpa error PHP)

## Catatan fitur
- Formulir **Tambah Menu** berada di halaman tersendiri (`admin/tambah_menu.php`), diakses dari sidebar setelah **Menu Harian**. Dashboard hanya menampilkan ringkasan.
- Stok menu disimpan per tanggal pengambilan di `menu_harian.stok` (0..120 box). `NULL` = tanpa batas (kompatibel dengan data lama).
- Jika nama menu sudah ada, formulir Tambah Menu memperbarui harga/kategori/stok untuk tanggal itu (upsert): tidak menggandakan menu, tidak mengubah pesanan, dan tidak mengubah foto tanggal lain.
- **Foto profil admin**: file disimpan di `uploads/admin/` (terpisah dari `uploads/menu/`), sedangkan database hanya menyimpan nama file (`admin.foto`). Foto ditampilkan bundar di header dashboard; bila belum ada foto, tampil avatar huruf awal nama.
- **Manajemen Admin** (`admin/admin.php`, menu sidebar): daftar admin, tambah admin, dan ubah foto profil sendiri. Login wajib. Password disimpan dengan `password_hash()`; username divalidasi unik. Halaman ini sengaja tidak menyediakan hapus/nonaktifkan admin agar tidak berisiko menghilangkan akses admin terakhir.
