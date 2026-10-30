<?php
// Uji aturan kuota. Jalankan dari terminal:  php tests/uji_kuota.php
// Memakai tanggal uji jauh di depan dan menu sementara "ZZ-UJI"; semuanya dibersihkan setelah selesai.
if (PHP_SAPI !== 'cli') exit('Hanya untuk CLI.');
require_once __DIR__ . '/../includes/bootstrap.php';

$tgl = (new DateTime('+400 days'))->format('Y-m-d');
$lulus = 0; $gagal = 0;
function cek(string $nama, bool $kondisi, string $info = ''): void {
    global $lulus, $gagal;
    if ($kondisi) { $lulus++; echo "[LULUS] $nama\n"; } else { $gagal++; echo "[GAGAL] $nama $info\n"; }
}
function bersihkan(mysqli $kon, string $tgl): void {
    db_exec($kon, 'DELETE FROM pesanan WHERE tanggal_ambil = ?', [$tgl]);
    db_exec($kon, 'DELETE FROM menu_harian WHERE tanggal = ?', [$tgl]);
    db_exec($kon, 'DELETE FROM kuota_harian WHERE tanggal = ?', [$tgl]);
    db_exec($kon, "DELETE FROM menu WHERE nama = 'ZZ-UJI'");
}
function pesan(mysqli $kon, string $tgl, int $menu, int $jml): array {
    return buat_pesanan($kon, ['nama' => 'Uji', 'no_wa' => '08123456789', 'tanggal_ambil' => $tgl, 'jam_ambil' => '10:00', 'items' => [$menu => $jml]]);
}

bersihkan($kon, $tgl);
db_exec($kon, "INSERT INTO menu (nama, kategori, harga) VALUES ('ZZ-UJI', 'nasi', 10000)");
$m = (int)$kon->insert_id;
db_exec($kon, 'INSERT INTO menu_harian (menu_id, tanggal, tersedia) VALUES (?, ?, 1)', [$m, $tgl]);

// 1. Minimum
$h = pesan($kon, $tgl, $m, 9);
cek('9 box ditolak (minimum 10)', !$h['ok']);
$h = pesan($kon, $tgl, $m, 10);
cek('10 box diterima', $h['ok'] && $h['sisa'] === 110, json_encode($h));

// 2. Tepat 120
$h = pesan($kon, $tgl, $m, 110);
cek('Total tepat 120 box diterima, sisa 0', $h['ok'] && $h['sisa'] === 0, json_encode($h));
$h = pesan($kon, $tgl, $m, 10);
cek('Pesanan setelah penuh ditolak', !$h['ok'] && ($h['sisa'] ?? -1) === 0, json_encode($h));

// 3. Melampaui sisa kuota + pesan menyebut sisa
bersihkan($kon, $tgl);
db_exec($kon, "INSERT INTO menu (nama, kategori, harga) VALUES ('ZZ-UJI', 'nasi', 10000)");
$m = (int)$kon->insert_id;
db_exec($kon, 'INSERT INTO menu_harian (menu_id, tanggal, tersedia) VALUES (?, ?, 1)', [$m, $tgl]);
pesan($kon, $tgl, $m, 100);
$h = pesan($kon, $tgl, $m, 25);
cek('25 box saat sisa 20 ditolak dan menyebut sisa 20 box', !$h['ok'] && $h['sisa'] === 20 && strpos($h['pesan'], '20 box') !== false, json_encode($h));
$total = (int)db_val($kon, 'SELECT SUM(total_box) FROM pesanan WHERE tanggal_ambil = ?', [$tgl]);
cek('Total box tidak berubah setelah penolakan (100)', $total === 100);

// 4. Menu nonaktif tidak bisa dipesan, pesanan lama tetap ada
$id_lama = (int)db_val($kon, 'SELECT id FROM pesanan WHERE tanggal_ambil = ?', [$tgl]);
db_exec($kon, 'UPDATE menu_harian SET tersedia = 0 WHERE menu_id = ? AND tanggal = ?', [$m, $tgl]);
$h = pesan($kon, $tgl, $m, 10);
cek('Menu dinonaktifkan: pesanan baru ditolak', !$h['ok']);
cek('Pesanan lama tetap tercatat setelah menu dinonaktifkan',
    (int)db_val($kon, 'SELECT COUNT(*) FROM pesanan WHERE id = ?', [$id_lama]) === 1
    && (int)db_val($kon, 'SELECT COUNT(*) FROM detail_pesanan WHERE pesanan_id = ?', [$id_lama]) === 1);
$mp = array_values(array_filter(menu_per_tanggal($kon, $tgl), fn($x) => (int)$x['id'] === $m));
cek('Rekap tetap menampilkan menu nonaktif yang sudah dipesan (100 box)', $mp && (int)$mp[0]['jumlah'] === 100 && (int)$mp[0]['tersedia'] === 0);

// 5. Status Siap Diambil tetap dihitung kuota
db_exec($kon, "UPDATE pesanan SET status = 'Siap Diambil' WHERE id = ?", [$id_lama]);
$r = ringkasan_tanggal($kon, $tgl);
cek("Pesanan 'Siap Diambil' tetap dihitung (box 100, sisa 20)", $r['box'] === 100 && $r['sisa'] === 20);

// 6. Snapshot harga
db_exec($kon, 'UPDATE menu SET harga = 99000 WHERE id = ?', [$m]);
cek('Harga pesanan lama tidak berubah saat harga menu diubah',
    (int)db_val($kon, 'SELECT harga_satuan FROM detail_pesanan WHERE pesanan_id = ?', [$id_lama]) === 10000);

// 7. Dua pesanan bersamaan (proses terpisah) mendekati batas
bersihkan($kon, $tgl);
db_exec($kon, "INSERT INTO menu (nama, kategori, harga) VALUES ('ZZ-UJI', 'nasi', 10000)");
$m = (int)$kon->insert_id;
db_exec($kon, 'INSERT INTO menu_harian (menu_id, tanggal, tersedia) VALUES (?, ?, 1)', [$m, $tgl]);
pesan($kon, $tgl, $m, 50); // sisa 70
$mulai = microtime(true) + 1.5;
$proses = [];
foreach ([0, 1] as $i) {
    $proses[$i] = proc_open([PHP_BINARY, __DIR__ . '/worker_pesan.php', $tgl, (string)$m, '60', (string)$mulai],
        [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipa[$i]);
}
$hasil = [];
foreach ($proses as $i => $p) { $hasil[$i] = json_decode(trim(stream_get_contents($pipa[$i][1])), true); proc_close($p); }
$ok = count(array_filter($hasil, fn($x) => $x && $x['ok']));
$total = (int)db_val($kon, 'SELECT SUM(total_box) FROM pesanan WHERE tanggal_ambil = ?', [$tgl]);
cek('Dua pesanan 60 box bersamaan (sisa 70): hanya satu berhasil', $ok === 1, json_encode($hasil));
cek('Total box tanggal tsb = 110 (tidak melewati 120)', $total === 110, "total=$total");

bersihkan($kon, $tgl);
echo "\nRingkasan: $lulus lulus, $gagal gagal\n";
exit($gagal ? 1 : 0);
