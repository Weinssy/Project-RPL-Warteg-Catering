<?php
require_once __DIR__ . '/../../includes/bootstrap.php';

if ($_SERVER['REQUEST_METHOD'] !== 'POST') json_out(['ok' => false, 'pesan' => 'Metode tidak diizinkan.'], 405);
wajib_login(true);
csrf_cek(true);

$menuId = filter_var($_POST['menu_id'] ?? null, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
if (!$menuId) json_out(['ok' => false, 'pesan' => 'ID menu tidak valid.'], 400);

try {
    $m = db_row($kon, 'SELECT id, nama, foto FROM menu WHERE id = ?', [$menuId]);
    if (!$m) json_out(['ok' => false, 'pesan' => 'Menu tidak ditemukan.'], 404);

    // Riwayat pesanan tidak boleh rusak (FK detail_pesanan.menu_id = ON DELETE RESTRICT).
    // Menu yang pernah dipesan tidak dihapus permanen; admin diminta menonaktifkannya.
    $dipakai = (int)db_val($kon, 'SELECT COUNT(*) FROM detail_pesanan WHERE menu_id = ?', [$menuId]);
    if ($dipakai > 0) {
        json_out([
            'ok' => false,
            'pesan' => 'Menu "' . $m['nama'] . '" tidak dapat dihapus karena sudah pernah dipesan pada '
                . $dipakai . ' pesanan. Nonaktifkan saja agar riwayat pesanan dan rekap tetap utuh.'
        ], 409);
    }

    $kon->begin_transaction();
    // menu_harian ikut terhapus otomatis (ON DELETE CASCADE). Pesanan/detail tidak tersentuh.
    db_exec($kon, 'DELETE FROM menu WHERE id = ?', [$menuId]);
    $kon->commit();
} catch (Throwable $e) {
    try { $kon->rollback(); } catch (Throwable $x) {}
    error_log('hapus_menu gagal: ' . $e->getMessage());
    json_out(['ok' => false, 'pesan' => 'Menu gagal dihapus. Coba lagi.'], 500);
}

// Bersihkan file foto dari folder upload. Hanya file milik menu ini yang dihapus, dan hanya
// jika namanya tidak dipakai lagi oleh menu lain maupun foto harian.
$fotoKandidat = array_unique(array_filter([$m['foto'] ?? null]));
foreach ($fotoKandidat as $foto) {
    try {
        $masihMenu   = (int)db_val($kon, 'SELECT COUNT(*) FROM menu WHERE foto = ?', [$foto]);
        $masihHarian = (int)db_val($kon, 'SELECT COUNT(*) FROM menu_harian WHERE foto = ?', [$foto]);
        if ($masihMenu === 0 && $masihHarian === 0) @unlink(__DIR__ . '/../../uploads/menu/' . $foto);
    } catch (Throwable $e) {
        error_log('hapus foto menu gagal: ' . $e->getMessage());
    }
}

json_out(['ok' => true, 'pesan' => 'Menu "' . $m['nama'] . '" berhasil dihapus.', 'menu_id' => $menuId]);
