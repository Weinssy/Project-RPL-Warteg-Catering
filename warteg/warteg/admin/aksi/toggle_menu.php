<?php
require_once __DIR__ . '/../../includes/bootstrap.php';

if ($_SERVER['REQUEST_METHOD'] !== 'POST') json_out(['ok' => false, 'pesan' => 'Metode tidak diizinkan.'], 405);
wajib_login(true);
csrf_cek(true);

$menuId = filter_var($_POST['menu_id'] ?? null, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
$tgl    = valid_tanggal($_POST['tanggal'] ?? null);
$nilai  = $_POST['tersedia'] ?? null;
if (!$menuId || !$tgl || !in_array($nilai, ['0', '1'], true)) json_out(['ok' => false, 'pesan' => 'Permintaan tidak valid.'], 400);
if ($tgl < date('Y-m-d')) json_out(['ok' => false, 'pesan' => 'Ketersediaan menu pada tanggal yang sudah lewat tidak dapat diubah.'], 422);

try {
    $m = db_row($kon, 'SELECT id, nama, aktif FROM menu WHERE id = ?', [$menuId]);
    if (!$m || !$m['aktif']) json_out(['ok' => false, 'pesan' => 'Menu tidak ditemukan atau sudah dihentikan.'], 404);
    $tersedia = (int)$nilai;
    // Hanya mengubah menu_harian: tabel pesanan/detail_pesanan tidak disentuh.
    db_exec($kon, 'INSERT INTO menu_harian (menu_id, tanggal, tersedia) VALUES (?, ?, ?)
                   ON DUPLICATE KEY UPDATE tersedia = VALUES(tersedia)', [$menuId, $tgl, $tersedia]);
    $kini = (int)db_val($kon, 'SELECT tersedia FROM menu_harian WHERE menu_id = ? AND tanggal = ?', [$menuId, $tgl]);
} catch (Throwable $e) {
    error_log('toggle_menu gagal: ' . $e->getMessage());
    json_out(['ok' => false, 'pesan' => 'Gagal menyimpan perubahan menu.'], 500);
}
json_out(['ok' => true, 'tersedia' => $kini,
    'pesan' => $m['nama'] . ($kini ? ' diaktifkan' : ' dinonaktifkan') . ' untuk ' . tanggal_id($tgl, false) . '.']);
