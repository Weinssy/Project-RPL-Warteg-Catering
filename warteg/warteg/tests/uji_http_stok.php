<?php
// Uji HTTP penyimpanan stok lewat admin/aksi/edit_menu.php.
// Jalankan: php -S 127.0.0.1:8765  (dari folder proyek) lalu: php tests/uji_http_stok.php
require_once __DIR__ . '/../includes/bootstrap.php';

$base = 'http://127.0.0.1:8765';
$jar  = sys_get_temp_dir() . '/warteg_stok_' . getmypid() . '.txt';
@unlink($jar);

function http(string $url, array $post = null, string $jar = ''): array {
    $ch = curl_init($url);
    curl_setopt_array($ch, [CURLOPT_RETURNTRANSFER => true, CURLOPT_COOKIEJAR => $jar, CURLOPT_COOKIEFILE => $jar,
        CURLOPT_FOLLOWLOCATION => true, CURLOPT_TIMEOUT => 10]);
    if ($post !== null) { curl_setopt($ch, CURLOPT_POST, true); curl_setopt($ch, CURLOPT_POSTFIELDS, $post); }
    $out = curl_exec($ch); $code = curl_getinfo($ch, CURLINFO_HTTP_CODE); curl_close($ch);
    return [$code, (string)$out];
}
$gagal = 0;
function cek(string $label, bool $ok, string $info = ''): void {
    global $gagal;
    if ($ok) echo "  [OK]   $label" . ($info ? " -> $info" : '') . PHP_EOL;
    else { $gagal++; echo "  [GAGAL] $label" . ($info ? " -> $info" : '') . PHP_EOL; }
}

// Login
[$c, $lp] = http($base . '/admin/login.php', null, $jar);
preg_match('/name="csrf" value="([a-f0-9]+)"/', $lp, $lm);
http($base . '/admin/login.php', ['username' => 'admin', 'password' => 'admin123', 'csrf' => $lm[1] ?? ''], $jar);
[$c, $dash] = http($base . '/admin/index.php', null, $jar);
preg_match('/name="csrf-token" content="([a-f0-9]+)"/', $dash, $mm);
$csrf = $mm[1] ?? '';
cek('Login + CSRF', $csrf !== '');

$tgl = date('Y-m-d');
db_exec($kon, "INSERT INTO menu (nama, kategori, harga) VALUES ('[UJI-STOK] Menu', 'nasi', 15000)");
$mid = (int)$kon->insert_id;

function simpan(array $extra): array {
    global $base, $jar, $csrf, $mid, $tgl;
    return http($base . '/admin/aksi/edit_menu.php', array_merge([
        'csrf' => $csrf, 'menu_id' => $mid, 'nama' => '[UJI-STOK] Menu', 'kategori' => 'nasi',
        'harga' => '15000', 'aktif' => '1', 'tanggal' => $tgl, 'hapus_foto' => '0',
    ], $extra), $jar);
}

// 1. Set stok 40 -> tersimpan di menu_harian
[$c, $o] = simpan(['stok' => '40']);
$j = json_decode($o, true);
cek('Simpan stok 40', $c === 200 && !empty($j['ok']), $o);
cek('stok tersimpan di menu_harian', (int)db_val($kon, 'SELECT stok FROM menu_harian WHERE menu_id = ? AND tanggal = ?', [$mid, $tgl]) === 40);

// 2. Stok kosong -> NULL (tanpa batas)
[$c, $o] = simpan(['stok' => '']);
cek('Stok kosong diterima', $c === 200, "HTTP $c");
cek('stok jadi NULL', db_val($kon, 'SELECT stok FROM menu_harian WHERE menu_id = ? AND tanggal = ?', [$mid, $tgl]) === null);

// 3. Validasi nilai tidak valid (negatif, huruf, desimal, dan di atas 120)
foreach (['-5' => 'negatif', 'abc' => 'huruf', '1.5' => 'desimal', '10,5' => 'desimal koma', '121' => 'di atas 120'] as $val => $ket) {
    [$c, $o] = simpan(['stok' => (string)$val]);
    cek("Stok $ket ditolak (422)", $c === 422, "HTTP $c");
}

// 3b. Batas atas: 120 diterima, 121 ditolak
[$c, $o] = simpan(['stok' => '120']);
cek('Stok 120 diterima', $c === 200, "HTTP $c " . $o);
cek('stok tersimpan 120', (int)db_val($kon, 'SELECT stok FROM menu_harian WHERE menu_id = ? AND tanggal = ?', [$mid, $tgl]) === 120);

// 3c. Stok 0 diterima (minimum)
[$c, $o] = simpan(['stok' => '0']);
cek('Stok 0 diterima', $c === 200, "HTTP $c " . $o);
cek('stok tersimpan 0', (int)db_val($kon, 'SELECT stok FROM menu_harian WHERE menu_id = ? AND tanggal = ?', [$mid, $tgl]) === 0);

// 4. Stok tidak boleh < jumlah terjual
simpan(['stok' => '50']);
db_exec($kon, "INSERT INTO pesanan (kode, nama_pelanggan, no_wa, tanggal_ambil, jam_ambil, total_box, total_harga, status)
    VALUES (?, 'Pel Uji', '6281234500000', ?, '10:00:00', 20, 300000, 'Menunggu Konfirmasi')", ['UJI-STOK-' . getmypid(), $tgl]);
$pid = (int)$kon->insert_id;
db_exec($kon, 'INSERT INTO detail_pesanan (pesanan_id, menu_id, jumlah, harga_satuan, subtotal) VALUES (?, ?, 20, 15000, 300000)', [$pid, $mid]);

[$c, $o] = simpan(['stok' => '10']);
$j = json_decode($o, true);
cek('Stok 10 < 20 terjual ditolak', $c === 422, "HTTP $c ".$o);
cek('Pesan menyebut jumlah terjual', stripos($j['pesan'] ?? '', '20 box yang sudah dipesan') !== false, $j['pesan'] ?? '');
cek('Stok tetap 50 di DB', (int)db_val($kon, 'SELECT stok FROM menu_harian WHERE menu_id = ? AND tanggal = ?', [$mid, $tgl]) === 50);

[$c, $o] = simpan(['stok' => '20']);
cek('Stok 20 (sama dengan terjual) diterima', $c === 200, "HTTP $c");

// Bersihkan
db_exec($kon, 'DELETE FROM pesanan WHERE id = ?', [$pid]);
db_exec($kon, 'DELETE FROM menu_harian WHERE menu_id = ?', [$mid]);
db_exec($kon, 'DELETE FROM menu WHERE id = ?', [$mid]);
@unlink($jar);
echo "\n" . ($gagal === 0 ? 'SEMUA UJI STOK HTTP LULUS' : "ADA $gagal UJI GAGAL") . PHP_EOL;
exit($gagal === 0 ? 0 : 1);
