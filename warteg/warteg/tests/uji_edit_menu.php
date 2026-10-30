<?php
// Uji fitur Edit Menu (jalankan dari folder proyek): php tests/uji_edit_menu.php
// Bukan bagian aplikasi; hanya skrip verifikasi manual, mengikuti gaya tests/uji_kuota.php.
require_once __DIR__ . '/../includes/bootstrap.php';

$gagal = 0; $lulus = 0;
function cek(string $label, bool $ok, string $info = ''): void {
    global $gagal, $lulus;
    if ($ok) { $lulus++; echo "  [OK]   $label" . ($info ? " -> $info" : '') . PHP_EOL; }
    else     { $gagal++; echo "  [GAGAL] $label" . ($info ? " -> $info" : '') . PHP_EOL; }
}

echo "== Uji Edit Menu ==\n";

// 1. Siapkan satu menu uji
$namaAsal = '[UJI] Menu Edit ' . substr(bin2hex(random_bytes(3)), 0, 5);
db_exec($kon, 'INSERT INTO menu (nama, kategori, harga, aktif) VALUES (?, ?, ?, 1)', [$namaAsal, 'nasi', 15000]);
$mid = (int)$kon->insert_id;
cek('Menu uji dibuat', $mid > 0, "id=$mid");

// 2. Update nama + kategori + harga + aktif (simulasi logika edit_menu.php)
$namaBaru = $namaAsal . ' v2';
db_exec($kon, 'UPDATE menu SET nama = ?, kategori = ?, harga = ?, aktif = ? WHERE id = ?', [$namaBaru, 'lauk', 18000, 0, $mid]);
$m = db_row($kon, 'SELECT nama, kategori, harga, aktif FROM menu WHERE id = ?', [$mid]);
cek('Nama tersimpan', $m['nama'] === $namaBaru, $m['nama']);
cek('Kategori tersimpan', $m['kategori'] === 'lauk', $m['kategori']);
cek('Harga tersimpan', (int)$m['harga'] === 18000, (string)$m['harga']);
cek('Status aktif tersimpan', (int)$m['aktif'] === 0, (string)$m['aktif']);

// 3. Nama duplikat harus terdeteksi (meniru cek bentrok)
$bentrok = db_row($kon, 'SELECT id FROM menu WHERE nama = ? AND id <> ?', [$namaBaru, 0]);
cek('Deteksi nama duplikat', $bentrok !== null);

// 4. menu_detail() mengembalikan data untuk formulir edit
$tgl = date('Y-m-d');
$d = menu_detail($kon, $mid, $tgl);
cek('menu_detail mengembalikan baris', $d !== null);
cek('menu_detail berisi kunci foto', $d !== null && array_key_exists('foto', $d));

// 5. Hapus foto (NULL) tidak error walau tidak ada foto
db_exec($kon, 'UPDATE menu SET foto = NULL WHERE id = ?', [$mid]);
cek('Set foto NULL berhasil', db_val($kon, 'SELECT foto FROM menu WHERE id = ?', [$mid]) === null);

// 6. Harga invalid ditolak oleh filter yang sama dengan endpoint
$bad = filter_var('-500', FILTER_VALIDATE_INT, ['options' => ['min_range' => 1, 'max_range' => 1000000]]);
cek('Harga negatif ditolak', $bad === false);
$bad2 = filter_var('abc', FILTER_VALIDATE_INT, ['options' => ['min_range' => 1, 'max_range' => 1000000]]);
cek('Harga non-angka ditolak', $bad2 === false);

// 7. ID tidak ada -> tidak ditemukan
cek('ID tidak ada -> null', db_row($kon, 'SELECT id FROM menu WHERE id = ?', [999999]) === null);

// Bersihkan
db_exec($kon, 'DELETE FROM menu WHERE id = ?', [$mid]);
cek('Menu uji dihapus', db_val($kon, 'SELECT id FROM menu WHERE id = ?', [$mid]) === null);

echo "\nHasil: $lulus lulus, $gagal gagal.\n";
exit($gagal === 0 ? 0 : 1);
