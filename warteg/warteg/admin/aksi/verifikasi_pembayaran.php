<?php
// =====================================================================
// Endpoint AJAX: verifikasi pembayaran oleh admin berwenang.
//   aksi=setuju : setujui pembayaran -> status LUNAS (berhasil)
//   aksi=tolak  : tolak pembayaran + simpan alasan
//
// Keamanan:
//   - wajib_login + CSRF.
//   - Hanya role 'admin' atau 'owner' yang boleh (semua admin adalah petugas berwenang;
//     bila ingin membatasi lebih lanjut, ubah menjadi wajib_owner()).
//   - Status TIDAK bisa diubah ke LUNAS dari sisi customer (endpoint ini hanya admin).
//   - Logika inti ada di verifikasi_pembayaran() (pembayaran_service.php).
// =====================================================================
require_once __DIR__ . '/../../includes/bootstrap.php';

if ($_SERVER['REQUEST_METHOD'] !== 'POST') json_out(['ok' => false, 'pesan' => 'Metode tidak diizinkan.'], 405);
wajib_login(true);
csrf_cek(true);

$id     = filter_var($_POST['id'] ?? null, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
$aksi   = (string)($_POST['aksi'] ?? '');
$alasan = (string)($_POST['alasan'] ?? '');

if (!$id || !in_array($aksi, ['setuju', 'tolak'], true)) {
    json_out(['ok' => false, 'pesan' => 'Permintaan tidak valid.'], 400);
}

$admin_id = admin_login_id();
$hasil = verifikasi_pembayaran($kon, (int)$id, $aksi, $admin_id, $alasan);

// Catat aktivitas admin
if ($hasil['ok']) {
    $p = db_row($kon, 'SELECT kode FROM pesanan WHERE id = ?', [(int)$id]);
    $ket = ($aksi === 'setuju' ? 'Menyetujui pembayaran ' : 'Menolak pembayaran ') . ($p['kode'] ?? '') . ($aksi === 'tolak' && $alasan !== '' ? ' - ' . mb_substr($alasan, 0, 200) : '');
    catat_aktivitas($kon, $aksi === 'setuju' ? 'verifikasi_setuju' : 'verifikasi_tolak', $ket);
}

json_out($hasil, $hasil['ok'] ? 200 : 422);
