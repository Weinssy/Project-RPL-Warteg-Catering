# PROJECT MEMORY & ARSITEKTUR BASELINE
**Dokumen Acuan Analisis & Audit Repositori Pengembangan**

Dokumen ini memuat seluruh keputusan arsitektur, batasan aturan bisnis (*business rules*), profil mitra, skema basis data, pemetaan rute, serta matriks pengujian sistem untuk memverifikasi apakah kode di dalam repositori `Project-RPL-Warteg-Catering-Prototype` telah berjalan sesuai spesifikasi atau mengalami deviasi.

---

### 1. Identitas & Batasan Kontekstual Sistem

* **Judul Proyek:** Sistem Informasi Point of Sale (POS) dan Pre-Order Katering Harian Warteg Berbasis Web
* **Mata Kuliah:** Rekayasa Perangkat Lunak (RPL) – Projek Besar Ujian Akhir Semester (UAS)[cite: 1]
* **Institusi:** Institut Teknologi dan Bisnis Ahmad Dahlan Jakarta[cite: 1]
* **Platform:** Sistem Informasi Berbasis Web[cite: 1]
* **Model SDLC:** Waterfall (Sekuensial Linier)[cite: 1]
* **Profil Mitra:** 
  * **Nama Mitra:** Warteg Pak Sanam
  * **Alamat Operasional:** Jalan BDN 1 Cilandak Barat Jakarta Selatan
  * **Kontak Layanan / WhatsApp:** 0857-8342-3626 (Internasional: `6285783423626`)
  * **Karakteristik Usaha:** Warteg skala mikro-kecil dengan kapasitas dapur maksimal 120 box per hari dan melayani pesanan katering berbasis sistem ambil mandiri (*self-pickup*).

---

### 2. Aturan Bisnis Mutlak (Non-Negotiable Business Rules)

Setiap implementasi kode di repositori **wajib** lolos validasi aturan berikut:

1. **Guest Checkout (Tanpa Autentikasi Pengguna Publik):**
   * Pelanggan katering tidak melalui proses registrasi maupun login akun.
   * Identitas pemesan murni divalidasi melalui 4 parameter input utama: `nama_pemesan` (bebas angka), `no_whatsapp` (minimal 10 digit angka), `tanggal_ambil`, dan `jam_ambil`.
2. **Sistem Ambil Mandiri (Self-Pickup Only):**
   * Sistem **tidak menyediakan** perhitungan kurir, integrasi peta/GPS, pemilihan alamat pengantaran, maupun kalkulasi ongkir.
   * Seluruh pesanan katering wajib diambil langsung oleh pemesan di lokasi Warteg Pak Sanam (Jalan BDN 1 Cilandak Barat Jakarta Selatan) pada rentang jam operasional (09.00 – 19.00 WIB).
3. **Batas Kuantitas Minimum:**
   * Validasi menolak pemesanan jika `jumlah_box < 10`.
4. **Batas Kapasitas Maksimum Harian (120 Box/Hari):**
   * Dapur Warteg Pak Sanam memiliki kuota harian flat maksimal 120 box untuk akumulasi seluruh pesanan katering pada tanggal yang sama.
   * Formula: Sisa Kuota = 120 - Total Box Pesanan Aktif pada tanggal tersebut.
   * Jika pesanan baru membuat akumulasi > 120, transaksi **wajib digagalkan** dan memunculkan sisa kuota yang tersedia.
5. **Validasi Waktu Pemesanan (Aturan H-1):**
   * Pemesanan tidak dapat dilakukan untuk hari H. Tanggal pengambilan minimal H-1 dari tanggal pemesanan (`after_or_equal:tomorrow`) hingga maksimal H+30.
6. **Mekanisme Notifikasi WhatsApp & Nota Fisik di Warteg:**
   * Tidak ada modul live tracking berbasis timeline rumit dan tidak menggunakan halaman nota digital terpisah.
   * Halaman publik `cek_status.html` dan `pembayaran.html` menampilkan status pembayaran (`belum_bayar`, `menunggu_verifikasi`, `berhasil`, `ditolak`) serta status dapur (`Menunggu Konfirmasi`, `Sedang Dimasak`, `Siap Diambil`, `Selesai`).
   * Konfirmasi pesanan (Pelanggan -> Warteg) dan notifikasi progres pesanan (Admin -> Pelanggan saat verifikasi LUNAS, `Sedang Dimasak`, dan `Siap Diambil`) menggunakan tautan click-to-chat WhatsApp otomatis (`https://wa.me/...`) berisi kode pesanan, rincian item, jadwal ambil, total tagihan setelah diskon, dan arahan bahwa **nota fisik asli diserahkan saat pengambilan pesanan di warteg**.
7. **Isolasi Total Modul Kasir (Fast POS):**
   * Modul transaksi kasir harian (dine-in dan bungkus langsung) berdiri terpisah dan **sama sekali tidak memotong/mengurangi kuota katering 120 box**.
8. **Skema Perhitungan Harga & Voucher Diskon Box:**
   * Formula Subtotal: $\text{Subtotal} = \sum (\text{Harga Komponen Menu} \times \text{Jumlah Box})$
   * **Aturan Voucher Diskon 5% (>= 80 Box):**
     * Pemesanan dalam satu transaksi yang mencapai **80 box atau lebih** berhak mendapatkan potongan voucher diskon sebesar **5% dari subtotal pesanan**.
     * Formula Diskon:
       * Jika $\text{Total Box} < 80$: $\text{Diskon} = \text{Rp } 0$
       * Jika $\text{Total Box} \ge 80$: $\text{Diskon} = \text{round}(\text{Subtotal} \times 0.05)$
     * Total Tagihan Bersih: $\text{Total Bayar} = \max(0, \text{Subtotal} - \text{Diskon})$.
     * Rincian potongan diskon wajib dicantumkan transparan pada ringkasan keranjang belanja, formulir checkout, template konfirmasi WhatsApp, cek status, dan invoice pembayaran.
9. **Perilaku Menu Tidak Tersedia (is_ready = false):**
   * Pada Builder Katering Publik: Menu yang tidak aktif disembunyikan dari pilihan paket.
   * Pada Kasir POS: Menu tetap muncul pada grid tombol kasir, namun dalam status dinonaktifkan (*disabled*) dengan label abu-abu "Habis".
10. **Metode Pembayaran QRIS Statis & Verifikasi:**
    * Sistem katering mendukung pembayaran non-tunai via QRIS Statis merchant **Warteg Pak Sanam**.
    * Pelanggan mengunggah bukti transfer, dan status pesanan diverifikasi oleh admin sebelum pesanan dimasak.
11. **Output Struk POS, Nota Fisik, & Invoice:**
    * Nota transaksi katering diserahkan dalam bentuk **nota fisik asli** saat pengambilan di warteg, didukung ringkasan otomatis melalui WhatsApp (`wa.me`) dan halaman opsional `invoice.html` dengan fitur cetak berbasis CSS media print (`window.print()`).

---

### 3. Konfigurasi Lingkungan & Tech Stack

Repositori mendukung dua lingkungan deployment:

#### Lingkungan 1: Prototype PHP Native + MySQL (Folder `warteg/warteg/`)
* **Backend:** PHP Native (PHP 8.2+) dengan arsitektur modular (`bootstrap.php`, `koneksi.php`, service layers).
* **Basis Data:** MySQL (`warteg_katering` via XAMPP lokal: `127.0.0.1:3306`, user: `root`).
* **Frontend:** HTML5, CSS kustom, JavaScript murni + Alpine.js.
* **Autentikasi:** PHP Session Auth minimalis untuk peran internal `admin` dan `owner`.
* **Timezone:** `Asia/Jakarta`.

#### Lingkungan 2: Production Serverless SPA (Folder `github-pages/`)
* **Hosting Platform:** GitHub Pages (Static Hosting kompatibel 100% tanpa runtime server backend).
* **Cloud Database & Backend:** Supabase (PostgreSQL 15+ dengan Database Function/RPC `buat_pesanan_katering`, Row Level Security / RLS, dan REST API via `@supabase/supabase-js`).
* **Mekanisme Transaksi & Concurrency Guard:** PostgreSQL Function ber-keamanan `SECURITY DEFINER` dengan penguncian baris `PERFORM 1 FROM public.kuota_harian WHERE tanggal = p_tanggal FOR UPDATE`.
* **Offline/Demo Engine:** LocalStorage Fallback otomatis aktif jika koneksi Supabase belum terkonfigurasi.

---

### 4. Skema Basis Data (Database Architecture)

```
[admin] (owner & admin)
   │
   ├── (1:N) ──> [aktivitas_admin]
   └── (1:N) ──> [verifikasi_log]
                     │
[pesanan] ───────────┴── (1:N) ──> [detail_pesanan] ── (N:1) ──> [menu]
   │                                                                │
   └── (N:1) ──> [kuota_harian]                                     │
                                                                    │
[menu_harian] ──────────────────────────────────────────────────────┘
```

#### Struktur Kolom Tabel Inti (`public.pesanan` & Relasi):

* **`admin`**
  * `id` (PK, BigInt, Identity)
  * `username` (Text, Unique)
  * `password_hash` (Text)
  * `nama` (Text)
  * `role` (Enum/Text: `'owner'`, `'admin'`)
  * `dibuat_pada` (Timestamptz)
* **`menu`**
  * `id` (PK, BigInt, Identity)
  * `nama` (Text, Unique)
  * `kategori` (Text: `'nasi'`, `'lauk'`, `'sayur'`, `'paket a'`, `'paket b'`, `'paket c'`)
  * `harga` (Integer, Check > 0)
  * `foto` (Text, Nullable)
  * `aktif` (Boolean, Default: `true`)
* **`menu_harian`**
  * `id` (PK, BigInt, Identity)
  * `menu_id` (FK cascade ke `menu.id`)
  * `tanggal` (Date)
  * `tersedia` (Boolean, Default: `false`)
  * `stok` (Integer, Max: 120)
* **`kuota_harian`**
  * `tanggal` (PK, Date - Row Locking Target)
* **`pesanan`**
  * `id` (PK, BigInt, Identity)
  * `kode` (Text, Unique, Format: `WRT-YYYYMMDD-XXX`)
  * `nama_pelanggan` (Text)
  * `no_wa` (Text)
  * `tanggal_ambil` (Date, Index)
  * `jam_ambil` (Time)
  * `total_box` (Integer, Min: 10)
  * `total_harga` (BigInt, Subtotal kotor sebelum diskon)
  * `diskon` (Integer/BigInt, Potongan voucher diskon 5% untuk pemesanan 80 box atau lebih)
  * `nominal_bayar` (BigInt, Total tagihan bersih setelah diskon)
  * `status` (Text: `'Menunggu Konfirmasi'`, `'Sedang Dimasak'`, `'Siap Diambil'`)
  * `status_bayar` (Text: `'belum_bayar'`, `'menunggu_verifikasi'`, `'berhasil'`, `'ditolak'`)
  * `token_nota` (Text, Index untuk keamanan akses nota publik)
  * `bukti_bayar` (Text, Nullable)
  * `catatan` (Text, Nullable)
  * `dibuat_pada` (Timestamptz)
* **`detail_pesanan`**
  * `id` (PK, BigInt, Identity)
  * `pesanan_id` (FK cascade ke `pesanan.id`)
  * `menu_id` (FK ke `menu.id`)
  * `jumlah` (Integer)
  * `harga_satuan` (Integer)
  * `subtotal` (BigInt)

---

### 5. Arsitektur Rute & Hak Akses (Routing Map)

| Method / Akses | URI (GitHub Pages) | Komponen / Handler | Deskripsi & Fungsi |
| :--- | :--- | :--- | :--- |
| `GET` | `index.html` | `customer-app.js` | Halaman beranda, banner promo diskon 5% (>= 80 box), live kuota gauge, meal builder. |
| `GET` | `checkout.html` | `customer-app.js` | Rincian keranjang, kalkulasi diskon otomatis, form pemesan & jam ambil. |
| `POST` | `RPC: buat_pesanan_katering` | `db-service.js` | Eksekusi transaksi kuota dapur 120 box & pemotongan voucher diskon. |
| `GET` | `pembayaran.html` | `pembayaran.html` | Informasi tagihan bersih, QRIS Statis Warteg Pak Sanam, upload bukti transfer, tautan WA konfirmasi otomatis. |
| `GET` | `invoice.html` | `invoice.html` | Lembar invoice opsional status LUNAS siap cetak/simpan PDF (nota fisik diserahkan di warteg). |
| `GET` | `cek_status.html` | `cek_status.html` | Portal pengecekan status pesanan publik cukup via nomor WhatsApp (menampilkan daftar pesanan terkait urut terbaru) dan tombol konfirmasi WA. |
| `GET` | `admin/login.html` | `admin-app.js` | Form autentikasi staf pengelola Warteg Pak Sanam. |
| `GET` | `admin/index.html` | `admin-app.js` | Dashboard kapasitas kuota harian & antrean dapur. |
| `GET` | `admin/verifikasi.html` | `admin-app.js` | Verifikasi bukti bayar QRIS pelanggan (Setujui Lunas / Tolak). |
| `GET` | `admin/menu_harian.html` | `admin-app.js` | Penjadwalan ketersediaan & kuota stok menu per tanggal. |

---

### 6. Algoritma & Logika Kritis

#### A. Transaksi Kuota & Voucher Diskon (Supabase PL/pgSQL RPC)
```sql
-- Kunci baris kuota harian agar konkuren sinkron
INSERT INTO public.kuota_harian (tanggal) VALUES (p_tanggal) ON CONFLICT DO NOTHING;
PERFORM 1 FROM public.kuota_harian WHERE tanggal = p_tanggal FOR UPDATE;

-- Validasi batas maksimal 120 box/hari
SELECT COALESCE(SUM(total_box), 0) INTO v_kuota_terpakai
FROM public.pesanan WHERE tanggal_ambil = p_tanggal AND status_bayar != 'dibatalkan';

IF (v_kuota_terpakai + v_total_box) > 120 THEN
    RAISE EXCEPTION 'Kapasitas dapur penuh! Sisa kuota hanya % box.', (120 - v_kuota_terpakai);
END IF;

-- Hitung voucher diskon 5% untuk pemesanan 80 box atau lebih
IF v_total_box >= 80 THEN
    v_diskon := ROUND(v_total_harga * 0.05)::INTEGER;
ELSE
    v_diskon := 0;
END IF;
v_nominal_bayar := GREATEST(0, v_total_harga - v_diskon);
```

#### B. Generator Format Tautan WhatsApp (`db-service.js`)
* **Pelanggan -> Warteg (`DB.urlWaWartegPesanan`):** `https://wa.me/6285783423626?text=...` memuat kode pesanan, nama pemesan, no WA, jadwal & lokasi ambil, rincian menu, subtotal/diskon/total tagihan, status pembayaran, dan keterangan penerimaan nota fisik saat pengambilan.
* **Admin -> Pelanggan (`DB.urlWaPelangganStatus`):** `https://wa.me/{NO_WA_PELANGGAN}?text=...` dipakai pada tombol verifikasi LUNAS (`admin/verifikasi.html`), perubahan status `Sedang Dimasak` / `Siap Diambil` (`admin/pemesan.html`), dan detail rekap (`admin/rekap.html`) dengan mencantumkan rincian menu, jadwal, serta arahan menunjukkan kode pesanan untuk menerima nota fisik di warteg.

---

### 7. Matriks Pengujian Black-Box (Kriteria Kelulusan Sistem)

| Test ID | Modul / Fitur | Kondisi Masukan (Input) | Hasil yang Diharapkan | Status Kelulusan |
| :--- | :--- | :--- | :--- | :--- |
| **TC-01** | Validasi Min. Box | Input `jumlah_box = 9` | Validasi gagal, sistem menolak order: "Minimal pemesanan katering adalah 10 box". | Lolos |
| **TC-02** | Validasi Min. Box | Input `jumlah_box = 10` | Lolos validasi kuantitas minimum. | Lolos |
| **TC-03** | Validasi Tanggal | Input `tanggal_ambil = Hari Ini` | Validasi gagal, sistem menolak order mendadak (wajib minimal H-1). | Lolos |
| **TC-04** | Validasi Kuota (Over) | Kuota terisi 115 box. Pesanan baru 10 box pada tanggal sama. | Sistem menolak transaksi dan menampilkan notifikasi: "Sisa kuota: 5 box". | Lolos |
| **TC-05** | Validasi Kuota (Pas) | Kuota terisi 110 box. Pesanan baru 10 box pada tanggal sama. | Transaksi berhasil. Kuota tanggal tersebut menjadi 120/120 (Penuh). | Lolos |
| **TC-06** | Diskon 80 Box (5%) | Input `jumlah_box = 80`, Subtotal Rp 1.840.000 | Sistem memotong voucher diskon 5% sebesar Rp 92.000, Total Tagihan Akhir = Rp 1.748.000. | Lolos |
| **TC-07** | Diskon 100 Box (5%) | Input `jumlah_box = 100`, Subtotal Rp 2.300.000 | Sistem memotong voucher diskon 5% sebesar Rp 115.000, Total Tagihan Akhir = Rp 2.185.000. | Lolos |
| **TC-08** | Non-Diskon (< 80 Box) | Input `jumlah_box = 79`, Subtotal Rp 1.817.000 | Voucher Diskon = Rp 0, Total Tagihan Akhir = Rp 1.817.000 (tidak ada potongan). | Lolos |
| **TC-09** | Validasi Nama | Input `nama_pemesan = "Budi 123"` | Form memunculkan peringatan galat: "Nama tidak boleh mengandung angka". | Lolos |
| **TC-10** | Verifikasi Pembayaran | Admin menyetujui bukti transfer QRIS di menu Verifikasi. | Status bayar pesanan berubah menjadi `berhasil` (LUNAS), tombol WhatsApp info LUNAS tersedia, dan invoice menampilkan cap LUNAS. | Lolos |

---

### 8. Penyelarasan Deliverable dengan Timeline RPL

* **Pertemuan 5 (SDLC & Analisis Kebutuhan - Bobot 10%):** Dokumen Bab I & II (Latar Belakang Warteg Pak Sanam, Functional & Non-Functional Requirements, Model SDLC Waterfall)[cite: 2, 3, 4].
* **Pertemuan 11 (Desain Sistem - Bobot 30%):** Dokumen Bab III (Use Case, Activity, Sequence Diagram, ERD, Mockup Figma, Skema Supabase)[cite: 2, 3, 4].
* **Pertemuan 13–15 (Demo & Pengujian Program - Bobot 35% Implementasi + 15% Pengujian + 15% Presentasi):** Dokumen Bab IV & V (Implementasi Live GitHub Pages & Supabase, laporan matriks Black-Box testing)[cite: 2, 3, 4].
* **Pertemuan 16 (UAS / Final Submission):** Laporan Lengkap PDF, Video Demo 3–5 menit, Dokumentasi repositori GitHub, dan user guide[cite: 2, 4].

---

### 9. Checklist Audit Repositori (Panduan Verifikasi Mandiri)

- [x] Identitas mitra terkonfigurasi konsisten sebagai **Warteg Pak Sanam**, Jalan BDN 1 Cilandak Barat Jakarta Selatan.
- [x] Nomor WhatsApp resmi terkonfigurasi ke **0857-8342-3626** (`6285783423626`).
- [x] Aturan voucher diskon 5% untuk pemesanan 80 box atau lebih (`box >= 80 ? Math.round(subtotal * 0.05) : 0`) terpasang pada `config.js`, `customer-app.js`, `checkout.html`, `db-service.js`, dan `supabase_setup.sql`.
- [x] Validasi nama pemesan menolak angka sama sekali (`namaValid`).
- [x] Kuota harian flat 120 box/hari dan aturan pemesanan minimal H-1 terkunci.
- [x] Alur checkout, pembayaran QRIS, upload bukti bayar, template konfirmasi WhatsApp (`wa.me`), invoice opsional, dan cek status berjalan baik dengan sistem nota fisik saat pengambilan pada mode Supabase maupun fallback LocalStorage.
