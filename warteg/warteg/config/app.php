<?php
// Konfigurasi umum aplikasi Warteg.
date_default_timezone_set('Asia/Jakarta');

const NAMA_WARTEG      = 'Warung Mas Alif';   // ubah sesuai nama warteg
const KAPASITAS_HARIAN = 120;             // box per tanggal pengambilan
const MINIMAL_BOX      = 10;              // minimum box per transaksi
const STOK_MAKS        = 120;             // batas maksimum stok (box) per menu per tanggal pengambilan

const STATUS_MENUNGGU = 'Menunggu Konfirmasi';
const STATUS_MASAK    = 'Sedang Dimasak';
const STATUS_SIAP     = 'Siap Diambil';

// ---------- Operasional pemesanan pelanggan (self-pickup) ----------
const JAM_BUKA        = '09:00';  // jam pengambilan paling awal (WIB)
const JAM_TUTUP       = '19:00';  // jam pengambilan paling akhir (WIB)
const MINIMAL_H_MINUS = 1;        // tanggal ambil minimal H-1 (hari setelah pemesanan)
const MAKS_HARI_KE    = 30;       // pemesanan paling jauh 30 hari ke depan
const LOKASI_AMBIL    = 'Warung Mas Alif'; // label lokasi pengambilan (self-pickup)

// Alamat & kontak opsional. Biarkan kosong bila belum dikonfirmasi — JANGAN diisi data karangan.
// Jika diisi, informasi ini tampil di halaman customer (footer/hero).
const ALAMAT_WARTEG   = '';
const TELEPON_WARTEG  = '';

// ---------- Pembayaran QRIS ----------
// Penyedia yang didukung: 'mock' (uji lokal), 'midtrans', 'xendit'. Kosong = belum dikonfigurasi.
// Kredensial HANYA boleh dari environment variable / server, bukan dari kode/JS/repo publik.
// Nilai dibaca lewat getenv() saat runtime agar tidak tersimpan di dalam file yang di-commit.
define('PAYMENT_PROVIDER', getenv('PAYMENT_PROVIDER') ?: '');

// Midtrans (Core API QRIS). Server Key bersifat RAHASIA.
define('MIDTRANS_SERVER_KEY', getenv('MIDTRANS_SERVER_KEY') ?: '');
define('MIDTRANS_CLIENT_KEY', getenv('MIDTRANS_CLIENT_KEY') ?: ''); // dikirim ke halaman QRIS (public/client key)
define('MIDTRANS_IS_PRODUCTION', (getenv('MIDTRANS_IS_PRODUCTION') ?: '0') === '1');

// Xendit (QR Codes API). API key bersifat RAHASIA.
define('XENDIT_API_KEY', getenv('XENDIT_API_KEY') ?: '');
define('XENDIT_CALLBACK_TOKEN', getenv('XENDIT_CALLBACK_TOKEN') ?: '');

// Batas waktu pembayaran QRIS (menit) sebelum dianggap kedaluwarsa.
define('BAYAR_KEDALUWARSA_MENIT', 30);

// Base URL publik aplikasi (dipakai sebagai callback/redirect ke payment gateway).
// Kosong = susun otomatis dari BASE_URL + host saat request.
define('APP_URL_PUBLIK', getenv('APP_URL_PUBLIK') ?: '');

// ---------- WhatsApp Business Cloud API (notifikasi admin otomatis) ----------
// Bila token/phone-number-id belum diisi, sistem memakai fallback tautan wa.me (bukan kirim otomatis).
define('WA_TOKEN', getenv('WA_TOKEN') ?: '');             // Bearer token Cloud API (RAHASIA)
define('WA_PHONE_ID', getenv('WA_PHONE_ID') ?: '');       // Phone Number ID pengirim
define('WA_API_VERSION', getenv('WA_API_VERSION') ?: 'v20.0');
define('WA_ADMIN', getenv('WA_ADMIN') ?: '');             // nomor WA admin penerima notifikasi (format 62xxx)

// BASE_URL otomatis (mis. "/warteg" di localhost, "" di warteg.test). Bisa diisi manual.
if (!defined('BASE_URL')) {
    $root   = str_replace('\\', '/', (string)realpath($_SERVER['DOCUMENT_ROOT'] ?? ''));
    $proyek = str_replace('\\', '/', (string)realpath(__DIR__ . '/..'));
    $base   = ($root !== '' && strpos($proyek, $root) === 0) ? substr($proyek, strlen($root)) : '';
    define('BASE_URL', rtrim($base, '/'));
}
