<?php
// =====================================================================
// Uji validasi NAMA PEMESAN (halaman pelanggan) — LEVEL BACKEND.
// Aturan yang diuji:
//   * Nama wajib diisi.
//   * Harus mengandung minimal satu huruf (tidak boleh angka/simbol saja).
//   * TIDAK boleh mengandung angka sama sekali ("123", "123 Ahmad", "Ahmad1" ditolak).
//   * Validasi dipanggil oleh buat_pesanan() sehingga nama tidak valid TIDAK membuat pesanan.
//
// Jalankan (CLI):  php tests/uji_validasi_nama.php
// =====================================================================
if (PHP_SAPI !== 'cli') exit('Hanya untuk CLI.');
putenv('PAYMENT_PROVIDER=mock');
require_once __DIR__ . '/../includes/bootstrap.php';

$lulus = 0; $gagal = 0;
function cek(string $nama, bool $kondisi, string $info = ''): void {
    global $lulus, $gagal;
    if ($kondisi) { $lulus++; echo "[LULUS] $nama\n"; }
    else { $gagal++; echo "[GAGAL] $nama $info\n"; }
}

// ---------- 1. Validator murni ----------
$valid = ['Ahmad', 'Ahmad Fauzi', 'Muhammad Rizky', 'Dwi Putra', "O'Connor", 'Siti Nur-aini', 'É', 'Muh. Rizky'];
foreach ($valid as $n) {
    cek("Nama valid diterima: '$n'", validasi_nama_pemesan($n) === null, (string)validasi_nama_pemesan($n));
}

$tidak_valid = ['123456', '99999', '123 Ahmad', 'Ahmad1', '', '   ', '!!!', '@#$', '...'];
foreach ($tidak_valid as $n) {
    cek("Nama tidak valid ditolak: '$n'", validasi_nama_pemesan($n) !== null, 'seharusnya ditolak');
}

// Pesan galat spesifik untuk kasus utama.
cek('Angka saja -> pesan "harus mengandung huruf"',
    strpos((string)validasi_nama_pemesan('123456'), 'huruf') !== false);
cek('Mengandung angka -> pesan "tidak boleh mengandung angka"',
    strpos((string)validasi_nama_pemesan('123 Ahmad'), 'angka') !== false);

// ---------- 2. Integrasi buat_pesanan(): nama tidak valid tidak membuat pesanan ----------
$nama_menu = 'ZZ-UJI-NAMA';
$tgl = (new DateTime('today'))->modify('+5 day')->format('Y-m-d');
// Bersihkan sisa run sebelumnya (bila ada) agar idempoten.
$lama = db_row($kon, 'SELECT id FROM menu WHERE nama = ?', [$nama_menu]);
if ($lama) {
    $lid = (int)$lama['id'];
    foreach (db_all($kon, 'SELECT p.id FROM pesanan p JOIN detail_pesanan d ON d.pesanan_id=p.id WHERE d.menu_id = ?', [$lid]) as $r) {
        db_exec($kon, 'DELETE FROM detail_pesanan WHERE pesanan_id = ?', [(int)$r['id']]);
        db_exec($kon, 'DELETE FROM pesanan WHERE id = ?', [(int)$r['id']]);
    }
    db_exec($kon, 'DELETE FROM menu_harian WHERE menu_id = ?', [$lid]);
    db_exec($kon, 'DELETE FROM menu WHERE id = ?', [$lid]);
}
db_exec($kon, "INSERT INTO menu (nama, kategori, harga) VALUES (?, 'nasi', 15000)", [$nama_menu]);
$mid = (int)$kon->insert_id;
db_exec($kon, 'INSERT INTO menu_harian (menu_id, tanggal, tersedia) VALUES (?, ?, 1)', [$mid, $tgl]);
db_exec($kon, 'INSERT IGNORE INTO kuota_harian (tanggal) VALUES (?)', [$tgl]);

$base_in = ['no_wa' => '081234567777', 'tanggal_ambil' => $tgl, 'jam_ambil' => '10:00', 'items' => [$mid => 10]];

$h = buat_pesanan($kon, $base_in + ['nama' => '999'], true);
cek('buat_pesanan menolak nama angka saja', !$h['ok'] && ($h['field'] ?? '') === 'nama', json_encode($h));

$h2 = buat_pesanan($kon, $base_in + ['nama' => 'Ahmad 123'], true);
cek('buat_pesanan menolak nama mengandung angka', !$h2['ok'] && ($h2['field'] ?? '') === 'nama', json_encode($h2));

$cek_ada = (int)db_val($kon, 'SELECT COUNT(*) FROM detail_pesanan d JOIN pesanan p ON p.id=d.pesanan_id WHERE d.menu_id = ?', [$mid]);
cek('Tidak ada pesanan tersimpan untuk nama tidak valid', $cek_ada === 0, (string)$cek_ada);

$h3 = buat_pesanan($kon, $base_in + ['nama' => 'Ahmad Fauzi'], true);
cek('buat_pesanan menerima nama valid', $h3['ok'], json_encode($h3));

// Bersihkan data uji.
foreach (db_all($kon, 'SELECT p.id FROM pesanan p JOIN detail_pesanan d ON d.pesanan_id=p.id WHERE d.menu_id = ?', [$mid]) as $r) {
    db_exec($kon, 'DELETE FROM detail_pesanan WHERE pesanan_id = ?', [(int)$r['id']]);
    db_exec($kon, 'DELETE FROM pesanan WHERE id = ?', [(int)$r['id']]);
}
db_exec($kon, 'DELETE FROM menu_harian WHERE menu_id = ?', [$mid]);
db_exec($kon, 'DELETE FROM menu WHERE id = ?', [$mid]);

echo "\nRingkasan: $lulus lulus, $gagal gagal\n";
exit($gagal === 0 ? 0 : 1);
