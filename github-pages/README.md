# Warteg Kharisma — Versi GitHub Pages + Supabase

Modul mandiri ini merupakan versi aplikasi **Sistem Katering & POS Warung Mas Alif** yang dirancang agar dapat di-hosting secara langsung di **GitHub Pages** dengan backend cloud **Supabase (PostgreSQL Database, Storage & RPC)**.

Semua kebutuhan aset, style, dan script terisolasi di dalam folder `github-pages/` agar repositori tetap rapi.

---

## 🚀 Fitur Utama

1. **Kompatibel 100% dengan GitHub Pages**:
   * Dibangun murni menggunakan **HTML5, CSS3, dan Vanilla JavaScript (ES6)** tanpa membutuhkan server backend PHP atau Apache/Nginx.
   * Supabase dihubungkan langsung dari sisi browser menggunakan library resmi `@supabase/supabase-js` melalui CDN.

2. **Database Cloud Nyata & Real-Time (Supabase PostgreSQL)**:
   * Pesanan yang dibuat oleh pelanggan melalui HP langsung tersimpan di cloud database PostgreSQL Supabase.
   * Admin yang membuka dashboard dari laptop/perangkat lain langsung melihat data pesanan baru dan bukti pembayaran secara real-time.

3. **Aturan Bisnis Katering Terjaga (Database Transaction)**:
   * **Guest Checkout**: Pelanggan memesan tanpa registrasi/login akun.
   * **Batas Kuantitas**: Minimum pesanan **10 box**.
   * **Batas Kuota Harian**: Maksimal **120 box** per hari pada tanggal pengambilan yang sama.
   * **Anti-Race Condition**: Diproteksi dengan PostgreSQL Function / RPC `buat_pesanan_katering` yang mengunci kuota harian dengan `SELECT ... FOR UPDATE` saat checkout.

4. **Mode Hybrid (Auto-Fallback LocalStorage)**:
   * Jika kredensial Supabase di `assets/js/config.js` **belum diisi**, sistem otomatis beralih ke **LocalStorage** lengkap dengan *auto-seed* data demo awal. Halaman web tetap berfungsi 100% dan bisa langsung didemokan di GitHub Pages tanpa error.

---

## 🛠️ Panduan Menghubungkan Supabase

Ikuti 3 langkah mudah berikut saat Anda siap menghubungkan Supabase:

### Langkah 1: Buat Project di Supabase
1. Masuk atau daftar akun gratis di [https://supabase.com](https://supabase.com).
2. Buat project baru (misalnya dengan nama `warteg-catering`).

### Langkah 2: Jalankan Skrip SQL
1. Pada dashboard project Supabase Anda, buka menu **SQL Editor** (ikon terminal di sidebar kiri).
2. Klik tombol **New query**.
3. Buka file `github-pages/supabase/supabase_setup.sql`, salin (**copy**) seluruh isinya, lalu tempel (**paste**) ke SQL Editor Supabase.
4. Klik tombol **Run** (atau tekan `Ctrl + Enter`).  
   *(Skrip ini akan otomatis membuat semua tabel, indeks, RLS policy, fungsi transaksi kuota 120 box, dan data demo awal).*

### Langkah 3: Isi Kunci API di `config.js`
1. Di dashboard Supabase, buka menu **Project Settings** (ikon gerigi) $\rightarrow$ **API**.
2. Salin nilai **Project URL** dan **Project API Keys (`anon` `public`)**.
3. Buka file `github-pages/assets/js/config.js` di editor Anda, lalu masukkan kredensial tersebut:
   ```javascript
   window.WARTEG_CONFIG = {
       SUPABASE_URL: "https://your-project.supabase.co",
       SUPABASE_ANON_KEY: "eyJhbGciOiJIUzI1NiIsInR5cCI6IkpXVCJ9...",
       // ... konfigurasi lainnya
   };
   ```
4. Simpan file dan commit/push ke GitHub. Aplikasi Anda kini resmi terhubung ke Supabase Cloud!

---

## 📂 Struktur Halaman & Alur Penggunaan

### 1. Sisi Pelanggan (Customer)
* **`index.html`** : Halaman utama (Hero, Cara Pesan, Cek Kuota 120 Box, Katalog/Meal Builder, Form Checkout).
* **`cek_status.html`** : Cek status pesanan secara mandiri menggunakan **Kode Pesanan + No. WhatsApp**.
* **`pembayaran.html`** : Halaman pembayaran QRIS Statis, menampilkan nominal, petunjuk transfer, dan form unggah bukti pembayaran.
* **`nota.html`** : Nota pesanan digital interaktif untuk memantau status masak dan status bayar.
* **`invoice.html`** : Halaman invoice resmi (format cetak PDF / print view).

### 2. Sisi Pengelola (Admin Dashboard)
* **Kredensial Default:** Username: `admin` | Password: `admin123`
* **`admin/login.html`** : Halaman autentikasi admin/owner.
* **`admin/index.html`** : Dashboard utama ringkasan statistik (Box dipesan, Sisa kuota, Total stok, Pesanan terbaru).
* **`admin/rekap.html`** : Kalender bulanan dan rekap pesanan harian.
* **`admin/pemesan.html`** : Manajemen daftar pemesan, filter tanggal/status, modal detail, ubah status *Mulai Masak*, *Siap Diambil*, serta tombol pintas kirim notifikasi WhatsApp.
* **`admin/verifikasi.html`** : Verifikasi bukti pembayaran QRIS pelanggan (*Setujui Lunas* atau *Tolak dengan Alasan*).
* **`admin/menu_harian.html`** : Kelola ketersediaan menu per tanggal (toggle aktif/nonaktif, edit harga, edit stok).
* **`admin/tambah_menu.html`** : Tambah menu makanan baru beserta foto dan jatah stok harian.
* **`admin/qris.html`** : Pengaturan gambar QRIS toko dan data merchant (khusus Owner).
* **`admin/admin.html`** : Tambah dan kelola akun staf admin (khusus Owner).
* **`admin/profil.html`** : Edit profil pribadi, ganti foto avatar, ubah kata sandi, dan preferensi akun.

---

## 🎨 Aset Gambar & Media
Semua gambar bawaan demo (foto menu, foto admin, gambar QRIS statis, dan contoh bukti bayar) tersimpan mandiri di subfolder:
* `uploads/1.jpeg`
* `uploads/admin/`
* `uploads/bukti/`
* `uploads/menu/`
* `uploads/qris/`
