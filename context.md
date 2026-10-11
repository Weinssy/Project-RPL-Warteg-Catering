# Context & Business Rules: Sistem POS & Katering Warteg

## Tech Stack
- Framework: Laravel (MVC Architecture)
- Database: MySQL
- Frontend: Blade Templates + Tailwind CSS + Alpine.js (via CDN)
- Auth: Laravel Session Auth (Khusus role 'admin' dan 'kasir')

## Aturan Bisnis Kritis (Jangan Dilanggar)
1. Modul Katering Bersifat GUEST (Tanpa Registrasi / Tanpa Login Akun).
2. Sifat Pengambilan: Self-pickup (Ambil Sendiri di Warteg). JANGAN buat modul kurir, ongkir, atau alamat GPS.
3. Batasan Kuantitas: Minimal pesanan 10 box.
4. Batasan Kapasitas Harian: Maksimal akumulasi 120 box per hari pada tanggal yang sama.
5. Konkurensi Kuota: Validasi kuota 120 box wajib dibungkus dalam Database Transaction saat checkout.
6. Status Pesanan di Web: Sederhana ('dimasak' -> 'siap_diambil'). Halaman nota hanya menampilkan status kesiapan dan tombol redirect WhatsApp.
7. Modul Kasir (Fast POS): Transaksi langsung (dine-in/takeaway biasa), tidak memotong kuota katering 120 box.