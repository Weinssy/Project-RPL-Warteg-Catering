<?php
// =====================================================================
// Endpoint publik (read-only) status pembayaran untuk polling halaman pembayaran.
// Akses dibatasi token_nota. Tidak pernah mengubah status apa pun.
// =====================================================================
require_once __DIR__ . '/includes/bootstrap.php';

$kode  = trim((string)($_GET['kode'] ?? ''));
$token = trim((string)($_GET['t'] ?? ''));

$p = db_row($kon, 'SELECT kode, status_bayar, token_nota FROM pesanan WHERE kode = ?', [$kode]);
if (!$p) json_out(['ok' => false, 'pesan' => 'Pesanan tidak ditemukan.'], 404);

if (!empty($p['token_nota']) && !hash_equals((string)$p['token_nota'], $token)) {
    json_out(['ok' => false, 'pesan' => 'Tautan tidak valid.'], 403);
}

$status = (string)($p['status_bayar'] ?? BAYAR_BELUM);
json_out([
    'ok' => true,
    'status' => $status,
    'label' => status_bayar_label($status),
    'kelas' => kelas_status_bayar($status),
]);
