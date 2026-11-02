# Sistem POS & Pemesanan Katering Warteg (Warung Mas Alif)

Proyek Rekayasa Perangkat Lunak (RPL) untuk digitalisasi operasional pemesanan katering nasi box dan manajemen dapur harian warteg berbasis self-pickup.

🌐 **Live Demo GitHub Pages:** [https://weinssy.github.io/Project-RPL-Warteg-Catering/](https://weinssy.github.io/Project-RPL-Warteg-Catering/)

---

## 📌 Aturan Bisnis Kritis (SDLC)

1. **Guest Checkout**: Pelanggan memesan katering tanpa perlu registrasi atau login akun.
2. **Self-Pickup**: Pengambilan pesanan dilakukan langsung di warteg oleh pelanggan (tidak menggunakan modul kurir/ongkir).
3. **Batasan Kuantitas**: Minimal pemesanan adalah **10 box** per transaksi.
4. **Kapasitas Dapur Harian**: Maksimum akumulasi pemesanan adalah **120 box** per hari pada tanggal pengambilan yang sama.
5. **Konkurensi Kuota**: Validasi kuota 120 box dan sisa stok menu dibungkus dalam *Database Transaction* untuk mencegah bentrok pemesanan bersamaan.
6. **Pembayaran QRIS Statis**: Pelanggan memindai QRIS warteg, memasukkan nominal manual sesuai tagihan, lalu mengunggah bukti pembayaran untuk diverifikasi oleh admin.
7. **Status Pesanan di Web**: Alur status operasional (`Menunggu Konfirmasi` $\rightarrow$ `Sedang Dimasak` $\rightarrow$ `Siap Diambil`) dilengkapi tautan otomatis notifikasi WhatsApp.

---

## 📁 Struktur Repositori

Repositori ini menyediakan 2 varian implementasi untuk kemudahan evaluasi dan demonstrasi:

```text
Project-RPL-Warteg-Catering/
├── index.html                  # Redirect otomatis ke ./github-pages/index.html
├── .github/workflows/          # CI/CD otomatis deployment GitHub Pages
├── github-pages/               # [BARU] Versi Cloud GitHub Pages + Supabase (Bisa Diakses Online)
│   ├── index.html              # Halaman Utama Pelanggan (Katalog & Meal Builder)
│   ├── cek_status.html         # Cek Status Pesanan Mandiri
│   ├── pembayaran.html         # Pembayaran QRIS & Upload Bukti Bayar
│   ├── nota.html               # Nota Digital Pelanggan
│   ├── invoice.html            # Cetak Invoice Resmi (PDF)
│   ├── admin/                  # Dashboard Lengkap Admin & Owner
│   ├── supabase/               # Skrip SQL Setup PostgreSQL + RPC Transaction
│   └── assets/js/config.js     # Tempat Konfigurasi SUPABASE_URL & ANON_KEY
├── warteg/                     # Versi Backend PHP Native + MySQL (Untuk Laragon / Server Lokal)
│   └── warteg/                 # Kode sumber PHP, migrasi database MySQL, & script tes PHP
└── docs/                       # Dokumentasi & Prototipe Desain Antarmuka Awal
    └── designs/                # Mockup statis HTML (katalog, kuota, nota)
```

---

## 🚀 Panduan Menjalankan

### Opsi A: Menjalankan Versi Online (GitHub Pages + Supabase)
1. Buka tautan GitHub Pages: [https://weinssy.github.io/Project-RPL-Warteg-Catering/](https://weinssy.github.io/Project-RPL-Warteg-Catering/).
2. Aplikasi langsung dapat digunakan dalam mode **Live Demo** (menggunakan browser engine & *auto-seed* data).
3. Untuk menghubungkan ke database cloud pribadi Anda di Supabase, ikuti panduan pada [github-pages/README.md](github-pages/README.md).
4. **Login Admin:**
   * URL: `github-pages/admin/login.html`
   * Username: `admin` | Password: `admin123`

### Opsi B: Menjalankan Versi Lokal (PHP Native + Laragon)
1. Salin folder `warteg/warteg/` ke direktori web server lokal (misalnya `C:\laragon\www\warteg`).
2. Buat database baru di phpMyAdmin bernama `warteg_admin` lalu impor `warteg/warteg/database/schema.sql` dan file migrasi terkait.
3. Buka `http://localhost/warteg/` di browser.
4. Panduan lengkap instalasi lokal tersedia di [warteg/warteg/README.md](warteg/warteg/README.md).

---

## 👥 Tim Pengembang
* Rifa Zam Zami
* Tim RPL Warteg Catering
