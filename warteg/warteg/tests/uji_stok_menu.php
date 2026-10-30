
<?php

// Uji fitur stok menu per tanggal. Jalankan dari folder proyek: php tests/uji_stok_menu.php
if (PHP_SAPI !== 'cli') exit('Hanya untuk CLI.');
require_once __DIR__ . '/../includes/bootstrap.php';

$gagal = 0; $lulus = 0;
function cek(string $label, bool $ok, string $info = ''): void {
    global $gagal, $lulus;
    if ($ok) { $lulus++; echo "  [OK]   $label" . ($info ? " -> $info" : '') . PHP_EOL; }
    else     { $gagal++; echo "  [GAGAL] $label" . ($info ? " -> $info" : '') . PHP_EOL; }
}
function pesan(mysqli $kon, string $tgl, int $menuId, int $box): array {
    return buat_pesanan($kon, ['nama' => 'ZZ Pelanggan Stok', 'no_wa' => '081234567890',
        'tanggal_ambil' => $tgl, 'jam_ambil' => '10:00', 'items' => [$menuId => $box]]);
}
function bersihkan(mysqli $kon, string $tgl): void {
    db_exec($kon, 'DELETE FROM pesanan WHERE tanggal_ambil = ?', [$tgl]);
    db_exec($kon, 'DELETE FROM menu_harian WHERE tanggal = ?', [$tgl]);
    db_exec($kon, 'DELETE FROM kuota_harian WHERE tanggal = ?', [$tgl]);
    db_exec($kon, "DELETE FROM menu WHERE nama LIKE 'ZZ-STOK%'");
}

$tgl = (new DateTime('+120 days'))->format('Y-m-d');
echo "== Uji Stok Menu (tanggal $tgl) ==\n";
bersihkan($kon, $tgl);

try {
    // Menu dengan stok 50
    db_exec($kon, "INSERT INTO menu (nama, kategori, harga) VALUES ('ZZ-STOK A', 'nasi', 10000)");
    $mA = (int)$kon->insert_id;
    db_exec($kon, 'INSERT INTO menu_harian (menu_id, tanggal, tersedia, stok) VALUES (?, ?, 1, 50)', [$mA, $tgl]);

    // Menu tanpa batas stok (NULL)
    db_exec($kon, "INSERT INTO menu (nama, kategori, harga) VALUES ('ZZ-STOK B', 'lauk', 8000)");
    $mB = (int)$kon->insert_id;
    db_exec($kon, 'INSERT INTO menu_harian (menu_id, tanggal, tersedia, stok) VALUES (?, ?, 1, NULL)', [$mB, $tgl]);

    // 1. Stok terlihat di menu_per_tanggal
    $mp = array_column(menu_per_tanggal($kon, $tgl), null, 'id');
    cek('stok terbaca 50', (int)$mp[$mA]['stok'] === 50, (string)$mp[$mA]['stok']);
    cek('stok NULL = tanpa batas', $mp[$mB]['stok'] === null);

    // 2. Pesan melebihi stok -> ditolak, pesan menyebut sisa
    $h = pesan($kon, $tgl, $mA, 60);
    cek('Pesan 60 > stok 50 ditolak', !$h['ok'], $h['pesan'] ?? '');
    cek('Pesan menyebut sisa stok 50', strpos($h['pesan'] ?? '', 'sisa 50') !== false, $h['pesan'] ?? '');
    cek('Tidak ada pesanan tersimpan', (int)db_val($kon, 'SELECT COUNT(*) FROM pesanan WHERE tanggal_ambil = ?', [$tgl]) === 0);

    // 3. Pesan tepat sebesar stok -> diterima
    $h = pesan($kon, $tgl, $mA, 50);
    cek('Pesan 50 = stok diterima', $h['ok'], json_encode($h));

    // 4. Stok habis: pesanan berikutnya untuk menu itu ditolak
    $h = pesan($kon, $tgl, $mA, 10);
    cek('Stok habis -> pesanan berikutnya ditolak', !$h['ok'], $h['pesan'] ?? '');

    // 5. Menu tanpa batas tetap bisa dipesan
    $h = pesan($kon, $tgl, $mB, 30);
    cek('Menu tanpa batas stok tetap bisa dipesan', $h['ok'], json_encode($h));

    // 6. Sisa stok di menu_per_tanggal mencerminkan penjualan
    $mp = array_column(menu_per_tanggal($kon, $tgl), null, 'id');
    cek('jumlah terjual menu A = 50', (int)$mp[$mA]['jumlah'] === 50, (string)$mp[$mA]['jumlah']);
    cek('jumlah terjual menu B = 30', (int)$mp[$mB]['jumlah'] === 30, (string)$mp[$mB]['jumlah']);

    // 7. Cap kuota harian tetap berlaku lebih dulu/bersama stok
    bersihkan($kon, $tgl);
    db_exec($kon, "INSERT INTO menu (nama, kategori, harga) VALUES ('ZZ-STOK C', 'nasi', 10000)");
    $mC = (int)$kon->insert_id;
    db_exec($kon, 'INSERT INTO menu_harian (menu_id, tanggal, tersedia, stok) VALUES (?, ?, 1, 500)', [$mC, $tgl]);
    $h = pesan($kon, $tgl, $mC, 121);
    cek('Stok besar tapi kuota 120 tetap menolak 121', !$h['ok'], $h['pesan'] ?? '');

    // 8. Transaksi campuran: A cukup, B kurang -> seluruh pesanan ditolak (atomik)
    bersihkan($kon, $tgl);
    db_exec($kon, "INSERT INTO menu (nama, kategori, harga) VALUES ('ZZ-STOK X', 'nasi', 10000)");
    $mX = (int)$kon->insert_id;
    db_exec($kon, "INSERT INTO menu (nama, kategori, harga) VALUES ('ZZ-STOK Y', 'lauk', 9000)");
    $mY = (int)$kon->insert_id;
    db_exec($kon, 'INSERT INTO menu_harian (menu_id, tanggal, tersedia, stok) VALUES (?, ?, 1, 40)', [$mX, $tgl]);
    db_exec($kon, 'INSERT INTO menu_harian (menu_id, tanggal, tersedia, stok) VALUES (?, ?, 1, 5)', [$mY, $tgl]);
    $h = buat_pesanan($kon, ['nama' => 'ZZ Campur', 'no_wa' => '081234567890', 'tanggal_ambil' => $tgl,
        'jam_ambil' => '10:00', 'items' => [$mX => 20, $mY => 10]]);
    cek('Salah satu menu kurang stok -> seluruh pesanan ditolak', !$h['ok'], $h['pesan'] ?? '');
    cek('Pesan menyebut menu yang kurang (ZZ-STOK Y)', strpos($h['pesan'] ?? '', 'ZZ-STOK Y') !== false, $h['pesan'] ?? '');
    cek('Tidak ada detail tersimpan', (int)db_val($kon, 'SELECT COUNT(*) FROM detail_pesanan') === 0 || (int)db_val($kon, "SELECT COUNT(*) FROM pesanan WHERE tanggal_ambil = ?", [$tgl]) === 0);
} finally {
    bersihkan($kon, $tgl);
}

echo "\nHasil: $lulus lulus, $gagal gagal.\n";
exit($gagal === 0 ? 0 : 1);
