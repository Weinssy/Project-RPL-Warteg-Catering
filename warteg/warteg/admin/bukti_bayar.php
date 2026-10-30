<?php
// =====================================================================
// Melayani file bukti pembayaran HANYA untuk admin yang sudah login.
// File bukti TIDAK dapat diakses publik lewat URL langsung karena
// folder uploads/bukti dilindungi & disajikan lewat endpoint ini.
// =====================================================================
require_once __DIR__ . '/../includes/bootstrap.php';
wajib_login();

$kode = trim((string)($_GET['kode'] ?? ''));
$p = db_row($kon, 'SELECT bukti_bayar FROM pesanan WHERE kode = ?', [$kode]);
if (!$p || empty($p['bukti_bayar'])) {
    http_response_code(404);
    exit('Bukti tidak ditemukan.');
}

// Validasi nama file: hanya hex + ekstensi gambar (mencegah path traversal).
$nama = basename($p['bukti_bayar']);
if (!preg_match('/^[a-f0-9]{32}\.(jpg|png|webp)$/', $nama)) {
    http_response_code(403);
    exit('Akses ditolak.');
}

$path = __DIR__ . '/../uploads/bukti/' . $nama;
if (!is_file($path)) {
    http_response_code(404);
    exit('File tidak ada.');
}

$mime = (new finfo(FILEINFO_MIME_TYPE))->file($path);
if (!in_array($mime, ['image/jpeg', 'image/png', 'image/webp'], true)) {
    http_response_code(403);
    exit('Tipe file tidak diizinkan.');
}

header('Content-Type: ' . $mime);
header('Content-Length: ' . filesize($path));
header('Cache-Control: private, no-store');
readfile($path);
