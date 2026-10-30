<?php
require_once __DIR__ . '/../../includes/bootstrap.php';

if ($_SERVER['REQUEST_METHOD'] !== 'POST') json_out(['ok' => false, 'pesan' => 'Metode tidak diizinkan.'], 405);
wajib_login(true);
csrf_cek(true);

$id     = filter_var($_POST['id'] ?? null, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
$target = ['masak' => STATUS_MASAK, 'siap' => STATUS_SIAP][$_POST['aksi'] ?? ''] ?? null;
if (!$id || $target === null) json_out(['ok' => false, 'pesan' => 'Permintaan tidak valid.'], 400);

// Alur yang diizinkan. 'Siap Diambil' bersifat akhir.
$alur = [STATUS_MENUNGGU => [STATUS_MASAK, STATUS_SIAP], STATUS_MASAK => [STATUS_SIAP]];

try {
    $kon->begin_transaction();
    $p = db_row($kon, 'SELECT id, kode, nama_pelanggan, no_wa, total_box, status FROM pesanan WHERE id = ? FOR UPDATE', [$id]);
    if (!$p) { $kon->rollback(); json_out(['ok' => false, 'pesan' => 'Pesanan tidak ditemukan.'], 404); }
    if (!in_array($target, $alur[$p['status']] ?? [], true)) {
        $kon->rollback();
        json_out(['ok' => false, 'pesan' => "Status tidak dapat diubah dari \"{$p['status']}\" ke \"{$target}\"."], 409);
    }
    db_exec($kon, 'UPDATE pesanan SET status = ? WHERE id = ?', [$target, $id]);
    $kon->commit();
} catch (Throwable $e) {
    try { $kon->rollback(); } catch (Throwable $x) {}
    error_log('status_pesanan gagal: ' . $e->getMessage());
    json_out(['ok' => false, 'pesan' => 'Gagal menyimpan status. Coba lagi.'], 500);
}

$hasil = ['ok' => true, 'status' => $target, 'pesan' => "Status pesanan {$p['kode']} diperbarui menjadi \"{$target}\"."];
if ($target === STATUS_SIAP) {
    $wa = link_wa($p['no_wa'], $p['nama_pelanggan'], (int)$p['total_box'], $p['kode']);
    if ($wa) $hasil['wa_url'] = $wa;
    else $hasil['pesan'] .= ' Nomor WhatsApp pelanggan tidak valid, pesan tidak dapat disiapkan.';
}
json_out($hasil);
