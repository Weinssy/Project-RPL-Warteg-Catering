<?php
// Uji isolasi stok/foto antar tanggal: perubahan pada satu tanggal tidak boleh
// mengubah stok maupun foto menu pada tanggal lain.
// Jalankan dari folder proyek: php tests/uji_isolasi_tanggal.php
require_once __DIR__ . '/../includes/bootstrap.php';

$gagal = 0;
function cek(string $label, bool $ok, string $info = ''): void {
    global $gagal;
    if ($ok) echo "  [OK]   $label" . ($info ? " -> $info" : '') . PHP_EOL;
    else { $gagal++; echo "  [GAGAL] $label" . ($info ? " -> $info" : '') . PHP_EOL; }
}

$nama = '[ISO] Tanggal Terpisah ' . getmypid();
$d1 = (new DateTime('+200 days'))->format('Y-m-d');
$d2 = (new DateTime('+201 days'))->format('Y-m-d');

foreach (db_all($kon, 'SELECT id FROM menu WHERE nama = ?', [$nama]) as $m) {
    db_exec($kon, 'DELETE FROM menu_harian WHERE menu_id = ?', [(int)$m['id']]);
    db_exec($kon, 'DELETE FROM menu WHERE id = ?', [(int)$m['id']]);
}

db_exec($kon, 'INSERT INTO menu (nama, kategori, harga) VALUES (?, ?, ?)', [$nama, 'nasi', 12000]);
$mid = (int)$kon->insert_id;
db_exec($kon, 'INSERT INTO menu_harian (menu_id, tanggal, tersedia, stok, foto) VALUES (?, ?, 1, 40, ?)', [$mid, $d1, 'fotoD1.jpg']);
db_exec($kon, 'INSERT INTO menu_harian (menu_id, tanggal, tersedia, stok, foto) VALUES (?, ?, 1, 90, ?)', [$mid, $d2, 'fotoD2.jpg']);

// Ubah stok tanggal 1 saja
db_exec($kon, 'UPDATE menu_harian SET stok = 10 WHERE menu_id = ? AND tanggal = ?', [$mid, $d1]);
$a = db_row($kon, 'SELECT stok, foto FROM menu_harian WHERE menu_id = ? AND tanggal = ?', [$mid, $d1]);
$b = db_row($kon, 'SELECT stok, foto FROM menu_harian WHERE menu_id = ? AND tanggal = ?', [$mid, $d2]);
cek('Tanggal 1 ter-update (stok 10, foto D1)', (int)$a['stok'] === 10 && $a['foto'] === 'fotoD1.jpg', "{$a['stok']}/{$a['foto']}");
cek('Tanggal 2 tidak berubah (stok 90, foto D2)', (int)$b['stok'] === 90 && $b['foto'] === 'fotoD2.jpg', "{$b['stok']}/{$b['foto']}");

// Upsert tanggal 1 tanpa foto baru (meniru form tambah menu nama sama): foto dipertahankan, tanggal 2 utuh
db_exec($kon, 'INSERT INTO menu_harian (menu_id, tanggal, tersedia, stok, foto) VALUES (?, ?, 1, ?, NULL)
               ON DUPLICATE KEY UPDATE tersedia = 1, stok = VALUES(stok), foto = COALESCE(VALUES(foto), foto)', [$mid, $d1, 5]);
$a = db_row($kon, 'SELECT stok, foto FROM menu_harian WHERE menu_id = ? AND tanggal = ?', [$mid, $d1]);
$b = db_row($kon, 'SELECT stok, foto FROM menu_harian WHERE menu_id = ? AND tanggal = ?', [$mid, $d2]);
cek('Upsert tgl1 tanpa foto: stok 5, foto D1 dipertahankan', (int)$a['stok'] === 5 && $a['foto'] === 'fotoD1.jpg', "{$a['stok']}/{$a['foto']}");
cek('Tanggal 2 tetap utuh setelah upsert tgl1', (int)$b['stok'] === 90 && $b['foto'] === 'fotoD2.jpg', "{$b['stok']}/{$b['foto']}");

db_exec($kon, 'DELETE FROM menu_harian WHERE menu_id = ?', [$mid]);
db_exec($kon, 'DELETE FROM menu WHERE id = ?', [$mid]);

echo "\n" . ($gagal === 0 ? 'SEMUA UJI ISOLASI TANGGAL LULUS' : "ADA $gagal UJI GAGAL") . PHP_EOL;
exit($gagal === 0 ? 0 : 1);
