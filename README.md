# Sistem Informasi Pemesanan Katering Warteg Berbasis Web

Proyek tugas akhir mata kuliah **Rekayasa Perangkat Lunak (RPL)** dengan tema **Bisnis & UMKM**. Sistem membantu pelanggan memesan katering nasi box untuk diambil langsung di warteg, serta membantu pengelola mengatur menu, kapasitas dapur, pembayaran, dan status pesanan.

Proyek menerapkan tahapan **Software Development Life Cycle (SDLC)**: analisis kebutuhan, desain, implementasi, pengujian, dan evaluasi. Model pengembangan yang dicatat dalam dokumen proyek adalah **Waterfall**.

[Buka demo web](https://weinssy.github.io/Project-RPL-Warteg-Catering/) · [Panduan pelanggan](https://weinssy.github.io/Project-RPL-Warteg-Catering/github-pages/cara-pemesanan.html)

> Status: prototipe akademik. Keberadaan kode tidak menyatakan seluruh fitur telah lolos pengujian. Status dokumen pendukung dicatat pada bagian dokumentasi tugas akhir.

## Latar belakang dan tujuan

Pemesanan katering membutuhkan pencatatan menu, jumlah box, jadwal pengambilan, dan pembayaran yang saling terhubung. Pencatatan yang terpisah menyulitkan pengelola mengetahui kapasitas dapur dan perkembangan pesanan.

Tujuan pengembangan:

- Menyediakan pemesanan katering melalui web tanpa registrasi pelanggan.
- Membantu pengelola memantau kuota dan ketersediaan menu per tanggal.
- Menyediakan alur unggah serta verifikasi bukti pembayaran QRIS.
- Menyediakan status pesanan, nota, dan invoice bagi pelanggan.
- Mendokumentasikan pengembangan dan pengujian sesuai ketentuan tugas akhir RPL.

## Aktor dan ruang lingkup

| Aktor | Kegiatan utama |
| --- | --- |
| Pelanggan | Memilih menu, mengisi data pemesanan, membayar, mengunggah bukti, dan memeriksa status. |
| Admin | Mengelola menu harian, memeriksa pesanan, memverifikasi pembayaran, memperbarui status, dan melihat rekap. |
| Owner | Mengelola operasional serta pengaturan QRIS dan akun staf pada halaman yang disediakan. |

Ruang lingkup utama adalah **pemesanan katering dengan pengambilan mandiri (self-pickup)**. Pengiriman, kurir, dan ongkos kirim tidak termasuk ruang lingkup. Pembatasan akses setiap peran tetap perlu diverifikasi pada backend/database.

## Fitur yang tersedia dalam kode

### Pelanggan

- Katalog menu dan pemilihan jumlah pesanan.
- Informasi kuota berdasarkan tanggal pengambilan.
- Checkout: nama, nomor WhatsApp, tanggal, jam, dan catatan.
- Pembayaran QRIS statis dan unggah bukti pembayaran.
- Pengecekan status pembayaran dan persiapan pesanan.
- Nota digital, invoice dengan tampilan cetak, dan tautan WhatsApp.

### Pengelola

- Halaman login dan dashboard ringkasan operasional.
- Pengelolaan menu, foto, harga, stok, dan ketersediaan harian.
- Daftar pemesan serta rekap kebutuhan menu.
- Persetujuan atau penolakan bukti pembayaran.
- Pembaruan status persiapan pesanan.
- Halaman pengaturan QRIS, akun staf, dan profil.

## Aturan bisnis proyek

Aturan berikut merupakan spesifikasi yang dipilih tim, bukan ketentuan fitur wajib dari dosen:

| Aturan | Ketentuan |
| --- | --- |
| Akun pelanggan | Pemesanan tanpa registrasi atau login. |
| Minimum pesanan | 10 box per transaksi. |
| Kapasitas katering | Maksimum 120 box per tanggal pengambilan. |
| Jadwal pengambilan | Mulai besok sampai 30 hari ke depan, pukul 09.00–19.00 WIB. |
| Pengambilan | Pelanggan mengambil pesanan langsung di warteg. |
| Pembayaran utama | QRIS statis; nominal dimasukkan pelanggan sesuai tagihan, lalu bukti diunggah. |
| Verifikasi | Admin menyetujui atau menolak bukti pembayaran. |
| Promo versi web | Potongan 5% untuk pesanan minimal 80 box, sesuai konfigurasi GitHub Pages. |

Kode transaksi memuat penguncian kuota per tanggal untuk menangani pesanan bersamaan. Efektivitasnya perlu dibuktikan melalui pengujian konkurensi pada database yang digunakan.

## Teknologi dan struktur repositori

| Varian | Frontend | Backend dan penyimpanan |
| --- | --- | --- |
| GitHub Pages | HTML, CSS, JavaScript | Supabase PostgreSQL melalui library JavaScript dan RPC; tersedia demo localStorage ketika konfigurasi cloud kosong. |
| Lokal | HTML, CSS, JavaScript | PHP Native, MySQLi, MySQL. |

Kedua varian memiliki konfigurasi dan penyimpanan berbeda. Data localStorage hanya berada pada browser terkait dan tidak tersinkron ke perangkat lain.

```text
.
├── .github/workflows/       # Deployment GitHub Pages
├── index.html               # Pengarah ke aplikasi GitHub Pages
├── github-pages/
│   ├── admin/               # Halaman pengelola
│   ├── assets/              # CSS, JavaScript, konfigurasi, layanan data
│   ├── supabase/            # Skema PostgreSQL dan fungsi pemesanan
│   ├── uploads/             # Aset gambar
│   └── README.md            # Panduan varian GitHub Pages
├── warteg/warteg/
│   ├── admin/               # Halaman dan aksi pengelola PHP
│   ├── config/              # Konfigurasi aplikasi dan MySQL
│   ├── database/            # Skema, migrasi, dan seed
│   ├── includes/            # Autentikasi, helper, layanan pesanan/pembayaran
│   ├── tests/               # Skrip pengujian PHP dan HTTP
│   └── README.md            # Panduan varian lokal
├── docs/
│   ├── designs/             # Mockup HTML dan gambar rancangan UI
│   └── audits/              # Catatan audit teknis
└── context.md               # Catatan kebutuhan dan arsitektur proyek
```

## Menjalankan aplikasi

### GitHub Pages dan Supabase

1. Buka [demo web](https://weinssy.github.io/Project-RPL-Warteg-Catering/).
2. Untuk instalasi sendiri, ikuti [panduan GitHub Pages dan Supabase](github-pages/README.md).
3. Konfigurasi koneksi berada di `github-pages/assets/js/config.js`; skema awal berada di `github-pages/supabase/supabase_setup.sql`.
4. Jika konfigurasi cloud dikosongkan, aplikasi menyediakan demo melalui localStorage. Gunakan data fiktif untuk demonstrasi.

Skema yang disertakan masih memiliki kebijakan akses publik yang luas. Autentikasi admin versi web juga masih ditangani di browser. Sebelum penggunaan operasional, perbaiki autentikasi, pembatasan akses database, dan perlindungan data pelanggan. Jangan menaruh service-role key atau rahasia server pada frontend.

### PHP dan MySQL lokal

1. Siapkan PHP dengan ekstensi MySQLi, MySQL, dan web server, misalnya Laragon.
2. Salin isi `warteg/warteg/` ke direktori web server, misalnya `C:\laragon\www\warteg`.
3. Impor `database/schema.sql`, lalu jalankan migrasi yang diperlukan sesuai [panduan lokal](warteg/warteg/README.md). Seed demo bersifat opsional.
4. Sesuaikan koneksi pada `config/koneksi.php` dan identitas toko pada `config/app.php`.
5. Buka `http://localhost/warteg/`.

Identitas toko pada kedua varian belum sepenuhnya seragam: versi GitHub Pages menggunakan Warteg Pak Sanam, sedangkan konfigurasi PHP masih memuat Warung Mas Alif. Samakan konfigurasi sebelum mengambil screenshot laporan atau melakukan presentasi.

## Pengujian sistem

Pengujian black-box membandingkan masukan pengguna dengan keluaran yang diharapkan. Skrip tersedia pada [folder pengujian lokal](warteg/warteg/tests/), antara lain:

| Area | Contoh skrip |
| --- | --- |
| Kuota dan minimum pesanan | `uji_kuota.php` |
| Stok menu | `uji_stok_menu.php` |
| Validasi nama | `uji_validasi_nama.php` |
| Alur pemesanan pelanggan | `uji_pemesanan_pelanggan.php` |
| QRIS dan verifikasi manual | `uji_qris_statis.php`, `uji_http_admin_verifikasi.php` |
| Unggah bukti | `uji_http_upload_bukti.php` |
| Tampilan halaman PHP | `uji_smoke_render.php` |

Jalankan pada database uji setelah membaca prasyarat masing-masing skrip. Sebagian skrip membuat atau menghapus data uji; tes HTTP membutuhkan server aktif. Tes PHP tidak membuktikan perilaku varian Supabase.

Laporan pengujian perlu mencatat ID kasus, fitur, input, hasil yang diharapkan, hasil aktual, status lulus/gagal, tanggal, lingkungan, dan bukti. Keberadaan skrip atau status dalam catatan proyek belum menggantikan bukti eksekusi terkini.

## Dokumentasi tugas akhir RPL

README ini merupakan pengantar repositori dan tidak menggantikan laporan akhir PDF. Mengacu pada ketentuan tugas akhir, laporan disusun dengan struktur berikut:

1. Cover dan identitas kelompok; kata pengantar opsional; daftar isi.
2. **Bab I – Pendahuluan:** latar belakang, rumusan masalah, tujuan, dan manfaat.
3. **Bab II – Analisis Kebutuhan Sistem:** deskripsi sistem, kebutuhan fungsional/nonfungsional, spesifikasi aktor; jelaskan ruang lingkup dan model SDLC yang dipilih.
4. **Bab III – Desain Sistem:** DFD atau UML, ERD, dan desain antarmuka.
5. **Bab IV – Implementasi:** teknologi, cuplikan kode yang relevan, dan tampilan sistem.
6. **Bab V – Pengujian Sistem:** metode, skenario, hasil aktual, dan evaluasi.
7. **Bab VI – Kesimpulan dan Saran.**
8. Daftar pustaka dan lampiran: user manual, log aktivitas, screenshot, serta bukti revisi.

### Status kelengkapan di repositori

| Komponen | Status |
| --- | --- |
| Kode aplikasi dan skema database | Tersedia dalam dua varian. |
| Catatan kebutuhan dan arsitektur | Tersedia di [context.md](context.md); perlu dirapikan ke laporan. |
| Prototipe antarmuka | Tersedia di [docs/designs](docs/designs/). |
| DFD/UML dan ERD formal | Belum ditemukan sebagai dokumen terpisah. |
| Skrip dan catatan pengujian | Tersedia; hasil eksekusi terkini perlu dilampirkan. |
| Laporan akhir PDF Bab I–VI | Belum ditemukan di repositori. |
| Video demo 3–5 menit atau user manual | Panduan pelanggan dan instalasi tersedia; panduan seluruh peran atau video perlu dilengkapi. |
| Pembagian peran, NIM, dan log aktivitas anggota | Perlu dilengkapi. |
| Bukti evaluasi dosen dan revisi | Perlu dilampirkan. |

### Tahapan presentasi dan pengumpulan

| Pertemuan | Materi yang disiapkan |
| --- | --- |
| 5 | Analisis kebutuhan dan SDLC, deskripsi sistem, kebutuhan fungsional/nonfungsional, aktor, dan ruang lingkup. |
| 11 | DFD atau UML, ERD, serta wireframe/prototipe antarmuka. |
| 13–15 | Demo sistem berjalan dan hasil pengujian black-box. |
| 16 / UAS | Laporan akhir, video demo atau user manual, dokumentasi pengujian, dan bukti revisi dari evaluasi sebelumnya. |

Laporan dan video demo/user manual dikumpulkan sebelum UAS melalui kanal yang ditentukan dosen. Presentasi wajib dilaksanakan. Persetujuan judul dan pelaksanaan presentasi belum dibuktikan oleh isi repositori ini.

## Tim pengembang

| Nama | NIM | Peran utama dan tugas |
| --- | --- | --- |
| Rifa Zam Zami | Belum dicantumkan | Belum dicantumkan |
| Muhammad Arman Kurniawan | Belum dicantumkan | Belum dicantumkan |
| Alif Arya | Belum dicantumkan | Belum dicantumkan |

Nama tim dan pembagian peran perlu diisi sesuai kesepakatan kelompok. Setiap anggota harus memiliki peran berbeda, dicantumkan pada laporan serta presentasi, dan memahami keseluruhan sistem. Contoh peran pada ketentuan tugas adalah System Analyst, UI/UX Designer, Programmer, serta Tester & Documentator; jumlah anggota mengikuti pembagian dosen.

Kontribusi individu perlu didukung log aktivitas dan penjelasan saat presentasi. Riwayat commit dapat menjadi salah satu bukti pendukung. Catat juga referensi kode, aset, dan pustaka yang digunakan agar sumbernya dapat ditelusuri.
