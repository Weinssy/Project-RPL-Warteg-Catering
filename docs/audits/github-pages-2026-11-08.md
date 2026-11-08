# Analisis alur penggunaan github-pages

Tanggal: 8 November 2026, Asia/Jakarta.

**Kesimpulan**

Struktur fitur sudah mencakup perjalanan pelanggan, admin operasional, dan owner. Guest checkout, ringkasan biaya, pemilihan jadwal, pembayaran QRIS, nota, kalender, dan pengelolaan menu sudah tersedia. Namun, implementasi belum layak dipakai untuk transaksi nyata sebelum autentikasi, izin database, sinkronisasi data, dan verifikasi pembayaran diperbaiki.

Fokus perbaikan pertama adalah memastikan setiap tindakan menghasilkan data yang benar dan terlihat sama di perangkat pelanggan serta admin. Perubahan tampilan sebaiknya menyusul perbaikan tersebut.

**Cakupan dan metode**

- Menelaah tujuh halaman pelanggan, sepuluh halaman admin, tiga halaman indeks folder upload, empat berkas JavaScript, dua stylesheet, skema SQL, README, dan workflow deployment.
- Menguji alur utama melalui browser pada pratinjau localhost. Konfigurasi Supabase dikosongkan hanya pada respons server pratinjau untuk mengaktifkan demo lokal; berkas konfigurasi proyek tidak diubah.
- Menguji perilaku layanan data dengan penyimpanan dalam memori dan klien Supabase tiruan. Hasil simulasi menunjukkan perilaku kode, bukan hasil pengujian database produksi.
- Memeriksa sintaks 18 skrip JavaScript, termasuk skrip inline, dan referensi aset/halaman statis pada 20 HTML.
- Memeriksa katalog pada lebar 390, 768, dan 1280 piksel, serta navigasi pembayaran pada lebar 390 piksel.
- Tidak mengubah pesanan, pembayaran, akun, atau konfigurasi pada Supabase produksi. Tidak menguji transfer uang, pengiriman WhatsApp, konkurensi database nyata, atau semua kombinasi interaksi.

Rujukan berkas di bawah relatif terhadap akar repositori yang berisi folder `github-pages`. Nomor baris merujuk keadaan kode saat audit.

**Alur yang tersedia saat ini**

| Peran | Urutan penggunaan | Titik keputusan |
| --- | --- | --- |
| Pelanggan baru | Beranda → pilih tanggal dan cek kuota → pilih menu/jumlah → checkout → isi nama, WhatsApp, tanggal, jam, catatan → pembayaran QRIS → unggah bukti | Minimum 10 box; kuota 120 per tanggal; diskon kelipatan 50 |
| Pelanggan kembali | Cek Status → kode pesanan + WhatsApp → nota/pembayaran/invoice | Bayar, menunggu verifikasi, ditolak dan unggah ulang, atau lunas |
| Admin operasional | Login → dashboard/rekap → verifikasi bukti → daftar pemesan → mulai masak → siap diambil → buka tautan WhatsApp | Status pembayaran dan status produksi masih dapat berjalan tanpa hubungan yang diwajibkan |
| Admin menu | Menu Harian → pilih tanggal → aktif/nonaktif → edit harga/stok; Tambah Menu untuk menu baru | Sebagian tindakan hanya menulis localStorage |
| Owner | Fitur admin + pengaturan QRIS + manajemen akun | Perbedaan peran terutama ditampilkan melalui navigasi; belum ditegakkan oleh autentikasi backend |
| Semua staf | Profil → nama/foto/kontak/kata sandi | Perubahan profil dan kata sandi masih lokal |

**Hal yang sudah baik**

- Pelanggan dapat memesan tanpa membuat akun.
- Lokasi self-pickup, minimum pesanan, jam pengambilan, dan rincian harga sudah ditampilkan.
- Pilihan menu tersimpan saat berpindah halaman dan dapat diedit dengan stepper maupun input angka.
- Status pembayaran dipisahkan dari status produksi dan ada jalur penolakan bukti beserta alasannya.
- RPC checkout menghitung harga dari master menu dan mengunci baris kuota per tanggal. Ini dasar yang baik untuk transaksi, tetapi izin tulis langsung ke tabel harus ditutup agar aturan RPC tidak dapat dilewati.
- Rekap harian sudah mengagregasi kebutuhan menu untuk membantu dapur.
- Tidak ditemukan referensi berkas lokal statis yang hilang pada pemeriksaan 20 HTML. Tautan dinamis tetap memiliki masalah yang dijelaskan pada temuan 11.
- Katalog tidak menunjukkan luapan horizontal pada tiga lebar yang diperiksa. Ini tidak menyatakan seluruh halaman atau seluruh kondisi sudah lolos pengujian responsif.

**Temuan dan rekomendasi**

Prioritas P0 berarti perlu diselesaikan sebelum menerima data/transaksi nyata. P1 berarti memengaruhi ketepatan pesanan atau kelancaran operasional. P2 berarti peningkatan pengalaman dan pemeliharaan.

1. **P0: kebijakan database membuka akses penuh kepada pengguna anonim.**

   `supabase/supabase_setup.sql:299` menerapkan `FOR ALL USING (true) WITH CHECK (true)` pada tabel pesanan, detail, admin, konfigurasi, dan lainnya. Baris 311–317 memberi izin luas kepada `anon` dan `authenticated`. Jika skrip ini diterapkan apa adanya, orang tanpa login dapat membaca serta memodifikasi data melalui API, termasuk mengubah status bayar dan QRIS. Izin ini juga memungkinkan penulisan pesanan di luar validasi RPC.

   Perbaikan: katalog hanya boleh dibaca publik; perubahan operasional wajib memakai identitas admin terautentikasi; pengaturan sensitif wajib memeriksa peran owner. Akses pesanan tamu melalui endpoint/RPC terbatas yang memverifikasi token dan hanya mengembalikan kolom yang diperlukan. Frontend GitHub Pages tetap dapat dipertahankan. Rujukan: [Supabase RLS](https://supabase.com/docs/guides/database/postgres/row-level-security).

2. **P0: login memeriksa kata sandi di browser dan memiliki jalur penerimaan yang keliru.**

   `assets/js/db-service.js:665` mengambil data admin, termasuk `password_hash`, lalu membandingkannya di browser. Pada baris 683 dan 710, kata sandi `admin123` diterima untuk hash yang berawalan bcrypt tertentu, tanpa verifikasi hash sebenarnya. Simulasi dengan hash bcrypt yang tidak terkait tetap menghasilkan login berhasil. Sesi dan peran disimpan sebagai JSON dalam sessionStorage.

   Perbaikan: gunakan Supabase Auth atau autentikasi server yang memverifikasi kata sandi secara benar; jangan kirim hash ke browser. Tegakkan role di database/server. Hilangkan jalur kompatibilitas tersebut dan kredensial default pada lingkungan nyata. Label login demo harus hanya muncul dalam mode demo. Rujukan: [Supabase Auth](https://supabase.com/docs/guides/auth).

3. **P0: token akses nota bersifat opsional dan validasi WhatsApp terjadi setelah data diambil.**

   `assets/js/db-service.js:529` hanya menambahkan filter token jika token diisi. Kode pesanan saja dapat membuka nota pada pengujian lokal. `cariPesanan` pada baris 580 mengambil baris pesanan berdasarkan kode, lalu membandingkan nomor WhatsApp di browser. Kode berurutan tidak cocok menjadi rahasia akses.

   Perbaikan: token acak wajib diverifikasi di backend pada akses nota, invoice, dan pembayaran. Pencarian kode + WhatsApp harus memeriksa keduanya di backend, membatasi percobaan, dan mengembalikan pesan kegagalan yang tidak membocorkan keberadaan pesanan. Batasi kolom respons; token jangan ikut dibuka melalui daftar pesanan publik.

4. **P1: isi pesanan dan konfigurasi dirangkai langsung menjadi HTML.**

   Contoh: `nota.html:142`, catatan pada `nota.html:179`, data kartu pada `assets/js/customer-app.js:295`, serta instruksi dan alasan penolakan dalam `pembayaran.html`. Nilai yang berasal dari pengguna/database dapat masuk ke `innerHTML` tanpa escaping. Ini jalur potensial stored XSS; audit tidak menjalankan payload terhadap situs produksi.

   Perbaikan: gunakan `textContent` dan atribut DOM untuk teks biasa. Jika suatu fitur benar-benar membutuhkan rich text, terapkan sanitasi yang sesuai. Pertimbangkan Content Security Policy setelah skrip inline dirapikan.

5. **P1: mode cloud dan lokal dapat bercampur tanpa diketahui pengguna.**

   Pembacaan kuota/menu/daftar pesanan dapat jatuh ke data demo ketika query cloud gagal. Sebaliknya, `toggleMenuHarian`, `simpanMenu`, dan `hapusMenu` pada `assets/js/db-service.js:963`–1042 selalu bekerja di localStorage. Simulasi mode Supabase mengonfirmasi nol panggilan cloud untuk ketiga tindakan itu. `admin/profil.html:109`–189 juga membaca/menulis profil dan kata sandi secara lokal.

   Dampak: admin dapat melihat pesan berhasil, sementara pelanggan di perangkat lain tetap melihat menu atau data lama. Perbaikan: tentukan mode secara eksplisit pada awal aplikasi. Mode produksi harus menampilkan kegagalan cloud dan menyediakan coba lagi; mode demo harus diberi label jelas. Lengkapi seluruh operasi cloud dan gunakan satu kontrak data untuk kedua mode. Perubahan kata sandi wajib melalui sistem autentikasi.

6. **P1: perubahan status memasak tidak cocok dengan skema SQL bawaan.**

   `assets/js/db-service.js:880` mengirim `diubah_pada` ke tabel pesanan. Kolom itu tidak didefinisikan dalam `supabase/supabase_setup.sql:74`. Kegagalan query kemudian beralih ke localStorage. Hasilnya bisa gagal untuk pesanan cloud atau hanya berubah lokal jika kebetulan ada ID yang sama. Handler UI juga tidak selalu memeriksa hasil `ok`.

   Perbaikan: selaraskan migrasi dan payload, periksa error serta jumlah baris yang berubah, dan tampilkan keberhasilan hanya setelah server mengonfirmasi. Hindari fallback penulisan ke data demo. Kondisi database produksi mungkin berbeda; temuan ini membandingkan kode dengan skema yang disertakan.

7. **P1: halaman verifikasi dapat menampilkan bukti pembayaran contoh.**

   `getDaftarPemesan` pada `assets/js/db-service.js:778` dan 850 menghilangkan `bukti_bayar`, `bukti_bayar_pada`, `alasan_tolak`, `diskon`, `nominal_bayar`, dan `catatan` dari objek hasil. `admin/verifikasi.html:93` memerlukan `bukti_bayar`, lalu menggantinya dengan gambar contoh jika kosong. Simulasi mengonfirmasi kolom bukti tidak diteruskan meskipun respons cloud memilikinya.

   Perbaikan: sediakan query/kontrak khusus verifikasi yang membawa bukti asli, nominal bersih, waktu unggah, dan alasan. Jika bukti tidak tersedia, tampilkan keadaan tersebut dan blok persetujuan berdasarkan gambar pengganti. Jangan tampilkan bukti contoh seolah milik transaksi yang sedang diperiksa.

8. **P1: hasil verifikasi cloud masih bergantung pada pesanan lokal.**

   `assets/js/db-service.js:907` menjalankan update cloud lalu tetap mencari ID pesanan pada localStorage. Pada simulasi, update cloud dilakukan tetapi fungsi mengembalikan `Pesanan tidak ditemukan` karena pesanan tersebut tidak ada dalam data lokal. Respons error update juga tidak diperiksa secara memadai. Log verifikasi dan identitas verifikator lengkap hanya ditulis ke state lokal.

   Perbaikan: jalankan perubahan status dan pencatatan audit secara atomik di server. Kembalikan hasil transaksi cloud langsung, periksa status awal yang diizinkan, dan cegah dua admin menyetujui/menolak secara bertabrakan. UI harus memeriksa hasil sebelum menutup dialog atau memuat ulang daftar.

9. **P1: tanggal yang dipilih di katalog hilang saat checkout.**

   `assets/js/customer-app.js:41` memulai tanggal dari H+1 setiap kali halaman dimuat, sedangkan localStorage hanya menyimpan item. Pada baris 671, tanggal form diisi ulang dari nilai awal tersebut. Pengujian browser: memilih 12 November 2026 di katalog lalu membuka checkout menghasilkan 9 November 2026.

   Perbaikan: simpan tanggal/jam bersama draft keranjang, pulihkan saat checkout/kembali ke katalog, dan perlihatkan tanggal pada ringkasan. Mengubah tanggal harus memvalidasi ulang menu, harga, stok, serta kuota sebelum pelanggan melanjutkan.

10. **P1: validasi waktu, stok, dan ketersediaan belum konsisten.**

    Checkout memakai `novalidate` (`checkout.html:96`), tetapi validasi submit hanya mencakup nama, WhatsApp, dan minimum jumlah. Tanggal lampau berhasil membuat pesanan dalam pengujian browser lokal. RPC pada `supabase/supabase_setup.sql:169` juga belum membatasi rentang tanggal H+1 sampai H+30 dan jam operasional. Pada layanan lokal, menu nonaktif harian dengan stok nol tetap diterima oleh `buatPesanan`; ini dikonfirmasi melalui simulasi.

    Tombol tambah juga membatasi jumlah ke 120 per item, bukan sisa stok aktual atau sisa kuota keseluruhan. Data keranjang lama tidak selalu diperbarui terhadap harga/menu terbaru. Menu tanpa entri harian dianggap tersedia dari status master (`assets/js/db-service.js:353`), sehingga kalender operasional perlu aturan yang jelas.

    Perbaikan: validasi authoritative di server untuk tanggal, zona waktu Asia/Jakarta, jam, menu aktif/tersedia, jumlah bulat positif, stok, kuota, serta harga. Gunakan pemeriksaan yang sama pada demo. Tambahkan pemeriksaan ulang keranjang sebelum konfirmasi; tampilkan perubahan harga atau item habis secara jelas. Putuskan apakah tanggal tanpa jadwal harus tertutup atau mengikuti jadwal baku.

11. **P1: tautan nota dari detail admin salah parameter.**

    `admin/pemesan.html:191` membuat `nota.html?token=...`, sedangkan nota membaca `kode` dan `t` pada `nota.html:100`. Pengujian browser menghasilkan pesan `Kode pesanan tidak diberikan`.

    Perbaikan: gunakan pembuat URL bersama untuk nota, invoice, dan pembayaran, dengan format parameter yang konsisten.

12. **P1: QRIS yang baru diunggah tidak dirender dengan benar di halaman pembayaran.**

    `admin/qris.html:101` menyimpan gambar sebagai data URL. Preview admin mengenali `data:`, tetapi `pembayaran.html:185` selalu menambahkan awalan `uploads/qris/`. Gambar baru berpotensi rusak pada sisi pelanggan dan diganti oleh gambar fallback.

    Perbaikan: samakan penanganan sumber gambar dan simpan upload ke object storage. Gunakan bucket privat untuk bukti pelanggan dengan akses terbatas, serta akses yang sesuai untuk gambar menu/QRIS. Validasi tipe, ukuran, dan hasil unggah sebelum menampilkan berhasil. Pada sampel yang diamati, teks merchant di dalam gambar QRIS menyebut `Waroeng Mas Ripa`, sementara halaman menyebut `Warteg Pak Sanam`; pastikan data merchant dan gambar pembayaran memang sesuai sebelum digunakan nyata. Rujukan: [kontrol akses Supabase Storage](https://supabase.com/docs/guides/storage/security/access-control).

13. **P1: admin melihat subtotal sebagai tagihan, tanpa diskon.**

    `admin/verifikasi.html:101`, `admin/pemesan.html:176`, dan rekap menggunakan `total_harga`, sedangkan pembayaran menggunakan `nominal_bayar`. Pesanan 50 paket seharga Rp25.000 menghasilkan subtotal Rp1.250.000 dan tagihan Rp1.200.000; admin bisa mengira pembayaran benar itu kurang Rp50.000.

    Perbaikan: tampilkan subtotal, diskon, dan nominal bersih pada setiap halaman terkait. Simpan nominal final saat pesanan dibuat dan gunakan nilai itu sebagai rujukan verifikasi. Pertahankan snapshot nama, unit, dan harga item agar nota historis tidak berubah saat master menu diedit.

14. **P1: jumlah porsi tambahan dihitung sebagai jumlah box.**

    Katalog memberi label `Porsi` untuk lauk dan sayur, tetapi `assets/js/customer-app.js:134` menjumlahkan seluruh kuantitas menjadi box. RPC SQL juga menjumlahkan semua item pada baris 184. Simulasi menunjukkan sepuluh porsi sayur saja dianggap sepuluh box. Ini memengaruhi minimum order, kuota dapur, dan diskon. Pilihan nasi + lauk + sayur juga belum membentuk satu komposisi box yang sebenarnya.

    Perbaikan: pilih model produk yang tegas. Untuk tahap awal, jual paket nasi box lengkap dengan tambahan terpisah; hitung minimum/kuota/diskon hanya dari box utama. Jika ingin fitur racik box, modelkan komposisi satu box dan jumlah box, beserta pemakaian bahan/menu di dalamnya.

15. **P1: batas pembayaran dan pelepasan kuota belum dijalankan.**

    Owner dapat mengatur `batas_bayar_jam`, dan SQL memiliki `bayar_kedaluwarsa_pada`, tetapi keduanya belum dipakai untuk kedaluwarsa otomatis. Kuota tetap menghitung semua status selain `dibatalkan`. Pesanan tidak dibayar dapat menahan kuota tanpa batas. Halaman pembayaran memasukkan status gagal/kedaluwarsa/dibatalkan ke cabang umum yang masih menampilkan pembayaran dan unggah bukti.

    Perbaikan: buat masa reservasi yang ditetapkan server, tampilkan batas waktu dan konsekuensinya, kemudian lepaskan kuota/stok saat reservasi kedaluwarsa sesuai kebijakan. Tentukan perlakuan bukti yang sudah diunggah sebelum tenggat. Pembayaran terlambat, penolakan bukti, pembatalan, dan pengembalian dana membutuhkan jalur penanganan yang jelas.

16. **P1: siklus operasional berhenti pada Siap Diambil.**

    Status SQL hanya berisi Menunggu Konfirmasi, Sedang Dimasak, dan Siap Diambil (`supabase/supabase_setup.sql:84`). Tombol mulai memasak bergantung pada status produksi, tanpa mensyaratkan pembayaran lunas. Belum ada bukti serah terima atau status selesai, sehingga staf sulit membedakan pesanan menunggu pengambilan dari yang sudah diserahkan.

    Perbaikan: gunakan transisi terkontrol: Menunggu Pembayaran → Menunggu Verifikasi → Dikonfirmasi → Sedang Dimasak → Siap Diambil → Sudah Diambil/Selesai. Status bayar tetap terpisah. Wajibkan lunas sebelum memasak, kecuali owner memberi pengecualian tercatat. Tambahkan kode pengambilan, waktu serah terima, serta staf penyerah; sediakan pembatalan/no-show sesuai aturan toko.

17. **P1: navigasi ponsel pada beberapa halaman tidak berfungsi.**

    Pembayaran, nota, dan invoice memiliki tombol `custToggle`, tetapi tidak memuat `customer-app.js` atau handler toggle setara. Pada pengujian pembayaran selebar 390 piksel, tombol diklik tetapi navigasi tidak terbuka dan `aria-expanded` tetap `false`. Halaman cek status memiliki handler sendiri.

    Perbaikan: pindahkan perilaku header bersama ke modul kecil yang dimuat semua halaman pelanggan. Jangan mewajibkan pemuatan seluruh logika keranjang untuk membuka navigasi. Referensi aturan audit: R-26, R-32, R-39.

18. **P2: pembaruan status dan komunikasi belum menuntaskan langkah berikutnya.**

    `pembayaran.html:261` melakukan polling lima detik, tetapi hanya mengganti badge. Setelah disetujui, panel menunggu dan tombol invoice belum otomatis berubah. Setelah ditolak, alasan serta form unggah ulang belum otomatis dirender. Nota dan halaman admin tidak memakai subscription realtime; data dibaca saat dimuat. WhatsApp berupa tautan yang perlu dibuka dan dikirim manual, bukan notifikasi otomatis.

    Perbaikan: perbarui komponen sesuai perubahan status, tampilkan waktu pembaruan terakhir, dan sediakan coba lagi jika koneksi gagal. Gunakan polling terukur atau subscription dengan penanganan koneksi. Jelaskan perilaku WhatsApp di UI dan dokumentasi. Di pembayaran ponsel, tambahkan salin nominal, simpan QRIS, dan petunjuk impor QR dari galeri aplikasi pembayaran agar pelanggan tidak harus memakai ponsel kedua.

19. **P2: informasi utama dan kemudahan interaksi perlu dirapikan.**

    Label header `Sisa Kuota Hari Ini` menampilkan kuota tanggal pilihan, yang secara default justru besok. Teks rentang `H-1 s/d H+30` berpotensi membingungkan karena input memakai H+1 sampai H+30. Promo disebut tanpa batas, padahal kuota harian 120 membatasi diskon per pesanan menurut aturan sekarang. Nama Pak Sanam, Mas Alif, dan Kharisma masih bercampur pada judul, invoice, dan README. Foto paket yang diamati belum selalu menunjukkan komposisi yang dijanjikan.

    Tombol stepper terukur 34 × 34 piksel pada tiga lebar layar; area sentuh lebih besar akan membantu pemesanan banyak box dari ponsel. Form error perlu dihubungkan dengan input dan diumumkan, modal perlu pengelolaan fokus/Escape yang konsisten, serta gerakan halus perlu menghormati preferensi reduced motion. Audit ini tidak mengklaim seluruh pasangan warna atau kepatuhan aksesibilitas sudah diperiksa.

    Perbaikan: tampilkan tanggal spesifik pada label kuota; gunakan `Pengambilan mulai besok, maksimal 30 hari`; jelaskan bahwa ini potongan otomatis; pusatkan identitas toko dan gunakan foto menu yang benar. Tambahkan indikator tahap Menu → Data Pemesan → Pembayaran → Status, tautan lokasi peta, tombol salin kode, dan simpan tautan pesanan. Referensi aturan audit: R-03, R-26, R-27, R-32, R-39.

20. **P2: pemeliharaan dan rekam perubahan belum lengkap.**

    `hapusAdmin` memanggil `this.catatAktivitas` pada `assets/js/db-service.js:1178` dan 1194, tetapi fungsi itu tidak diekspor/didefinisikan pada layanan. Simulasi mengonfirmasi nilainya `undefined`; proses dapat melempar error setelah penghapusan selesai. Skema dan frontend juga menunjukkan perbedaan seperti kolom `deskripsi` yang dipakai UI tetapi tidak tersedia dalam tabel menu bawaan. Daftar pesanan mengambil hasil cloud lebih dahulu lalu membagi halaman di browser; verifikasi mengambil 100 pesanan baru kemudian menyaring status, sehingga antrean lama berpotensi terlewat. Urutan pesanan terbaru memakai tanggal pengambilan, bukan waktu pesanan dibuat.

    Perbaikan: gunakan migrasi versi dan kontrak respons yang jelas, audit log server untuk tindakan penting, filter/paginasi di database, dan urutkan antrean berdasarkan kebutuhan operasional. Pisahkan JavaScript inline dan komponen berulang untuk mengurangi perbedaan antarhalaman. Workflow saat ini memublikasikan akar repositori; pertimbangkan hanya direktori distribusi `github-pages`, serta hindari menyimpan bukti pembayaran nyata dalam aset publik/repositori. Tambahkan pemeriksaan sintaks, tautan dinamis, alur pembayaran, dan konkurensi kuota di CI.

**Alur yang disarankan**

Pelanggan: pilih tanggal dan slot pengambilan → lihat menu yang benar-benar tersedia → pilih paket dan tambahan → validasi stok/kuota/harga → isi data → tinjau ringkasan final → buat reservasi dengan batas pembayaran → bayar dan unggah bukti → pantau verifikasi → pantau produksi → serahkan kode saat mengambil → pesanan selesai.

Admin: buka antrean hari/tanggal yang dipilih → periksa bukti asli dan nominal bersih → setujui/tolak dengan alasan → masukkan pesanan terkonfirmasi ke antrean dapur → mulai masak → siap diambil → buka/kirim pemberitahuan sesuai mekanisme yang digunakan → cocokkan kode pengambilan → tandai selesai. Semua perubahan dicatat dengan waktu dan identitas staf.

Owner: atur kalender buka/tutup, kuota serta slot → kelola menu dan stok per tanggal → kelola QRIS yang valid → kelola akses staf → pantau pesanan, penerimaan bersih, pembatalan, dan jejak perubahan.

**Urutan implementasi**

| Tahap | Pekerjaan | Kriteria selesai |
| --- | --- | --- |
| 1. Keamanan dan satu sumber data | Temuan 1–5; Auth, RLS, token, penanganan teks, mode demo eksplisit | Anon tidak dapat membaca pesanan/admin atau mengubah data operasional; dua perangkat melihat data yang sama; cloud gagal terlihat sebagai error |
| 2. Ketepatan transaksi | Temuan 6–15; skema, bukti asli, hasil verifikasi, tanggal, stok, QRIS, nominal, unit, masa reservasi | Pesanan yang ditagihkan sesuai tanggal/menu/harga; admin memeriksa bukti sebenarnya; kuota/stok tetap benar |
| 3. Operasional sampai selesai | Temuan 16 dan 18; antrean dapur, aturan transisi, serah terima, pembaruan status | Satu pesanan dapat dilacak sampai selesai, termasuk penolakan bukti dan kegagalan koneksi |
| 4. Pengalaman dan pemeliharaan | Temuan 17–20; navigasi bersama, aksesibilitas, konten, laporan, CI | Alur ponsel dapat dituntaskan; pesan dan identitas konsisten; masalah utama terlindungi pemeriksaan otomatis |

**Hasil verifikasi dan batas kesimpulan**

| Pemeriksaan | Hasil |
| --- | --- |
| Sintaks JavaScript | 18 skrip berhasil diparse; bukan bukti bebas bug runtime |
| Referensi lokal statis | 20 HTML diperiksa; tidak ada berkas tujuan statis yang hilang |
| Katalog responsif | Tidak ada luapan horizontal terukur pada 390/768/1280; stepper 34 × 34 |
| Tanggal katalog → checkout | Bug direproduksi: 12 November berubah menjadi 9 November |
| Tanggal lampau | Demo lokal menerima 1 November pada audit 8 November |
| Menu nonaktif/stok nol | Layanan demo tetap menerima pesanan pada simulasi |
| Akses tanpa token | Nota lokal dapat dibuka dengan kode saja |
| Nota dari detail admin | Bug direproduksi: parameter salah menghasilkan kode tidak diberikan |
| Menu ponsel pembayaran | Bug direproduksi: tidak terbuka setelah klik |
| Login bcrypt | Klien tiruan mengonfirmasi jalur `admin123` menerima hash yang tidak terkait |
| Data verifikasi | Simulasi mengonfirmasi bukti/nominal/diskon/alasan hilang dari pemetaan daftar |
| Operasi menu cloud | Simulasi mengonfirmasi toggle/simpan/hapus tidak memanggil cloud |
| Verifikasi cloud | Simulasi mengonfirmasi update cloud masih dilanjutkan pencarian lokal dan hasil dapat kontradiktif |
| Status terhadap SQL bawaan | Ketidakcocokan kolom ditemukan; tidak menjalankan migrasi atau update pada produksi |
| Konkurensi 120 box | Mekanisme lock ditelaah; uji beban/transaksi bersamaan belum dilakukan |

Tindak lanjut yang paling bernilai adalah menyelesaikan tahap 1 dan 2 sebelum menambah fitur baru. Laporan ini menambahkan dokumentasi audit; kode aplikasi dan konfigurasi produksi tidak diubah.
