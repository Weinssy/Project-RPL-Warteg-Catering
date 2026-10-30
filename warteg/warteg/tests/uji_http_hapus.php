<?php
// Uji HTTP endpoint admin/aksi/hapus_menu.php.
// Jalankan: php -S 127.0.0.1:8765  (dari folder proyek) lalu: php tests/uji_http_hapus.php
require_once __DIR__ . '/../includes/bootstrap.php';

$base = 'http://127.0.0.1:8765';
$jar  = sys_get_temp_dir() . '/warteg_hapus_' . getmypid() . '.txt';
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

// A. Menu baru tanpa pesanan + foto -> hapus sukses, menu_harian cascade, file foto terhapus
$png = base64_decode('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mP8z8BQDwAEhQGAhKmMIQAAAABJRU5ErkJggg==');
$tmp = sys_get_temp_dir() . '/hapus_foto.png'; file_put_contents($tmp, $png);
db_exec($kon, 'INSERT INTO menu (nama, kategori, harga) VALUES (?, ?, ?)', ['[UJI-HAPUS] Tanpa Pesanan', 'nasi', 10000]);
$mid = (int)$kon->insert_id;
$foto = bin2hex(random_bytes(8)) . '.png';
copy($tmp, __DIR__ . '/../uploads/menu/' . $foto);
db_exec($kon, 'UPDATE menu SET foto = ? WHERE id = ?', [$foto, $mid]);
db_exec($kon, 'INSERT INTO menu_harian (menu_id, tanggal, tersedia) VALUES (?, ?, 1)', [$mid, date('Y-m-d')]);

[$c, $o] = http($base . '/admin/aksi/hapus_menu.php', ['csrf' => $csrf, 'menu_id' => $mid], $jar);
$j = json_decode($o, true);
cek('Hapus menu tanpa pesanan', $c === 200 && !empty($j['ok']), $o);
cek('Baris menu hilang', db_row($kon, 'SELECT id FROM menu WHERE id = ?', [$mid]) === null);
cek('menu_harian ikut terhapus (cascade)', (int)db_val($kon, 'SELECT COUNT(*) FROM menu_harian WHERE menu_id = ?', [$mid]) === 0);
cek('File foto terhapus', !file_exists(__DIR__ . '/../uploads/menu/' . $foto));

// B. Menu yang SUDAH PERNAH DIPESAN -> harus ditolak 409 dan data tetap utuh
// Buat fixture: menu uji + pesanan + detail_pesanan yang mereferensikannya.
$tglUji = date('Y-m-d');
db_exec($kon, 'INSERT INTO menu (nama, kategori, harga) VALUES (?, ?, ?)', ['[UJI-HAPUS] Sudah Dipesan', 'nasi', 12000]);
$midPesan = (int)$kon->insert_id;
db_exec($kon, "INSERT INTO pesanan (kode, nama_pelanggan, no_wa, tanggal_ambil, jam_ambil, total_box, total_harga, status)
    VALUES (?, ?, ?, ?, '10:00:00', 1, 12000, ?)", ['UJI-HAPUS-' . getmypid(), '[UJI-HAPUS] Pel', '6281234500000', $tglUji, STATUS_MENUNGGU]);
$pidUji = (int)$kon->insert_id;
db_exec($kon, 'INSERT INTO detail_pesanan (pesanan_id, menu_id, jumlah, harga_satuan, subtotal) VALUES (?, ?, 1, 12000, 12000)', [$pidUji, $midPesan]);

$pesanAda = db_row($kon, 'SELECT m.id, m.nama FROM menu m JOIN detail_pesanan d ON d.menu_id = m.id WHERE m.id = ? LIMIT 1', [$midPesan]);
if ($pesanAda) {
    [$c, $o] = http($base . '/admin/aksi/hapus_menu.php', ['csrf' => $csrf, 'menu_id' => $pesanAda['id']], $jar);
    $j = json_decode($o, true);
    cek('Menu pernah dipesan -> 409', $c === 409, "HTTP $c");
    cek('Pesan menyarankan nonaktifkan', isset($j['pesan']) && stripos($j['pesan'], 'Nonaktifkan') !== false, $j['pesan'] ?? '');
    cek('Menu tetap ada', db_row($kon, 'SELECT id FROM menu WHERE id = ?', [$pesanAda['id']]) !== null);
} else {
    echo "  [LEWATI] tidak ada menu terpesan pada data uji\n";
}

// C. Validasi
[$c, $o] = http($base . '/admin/aksi/hapus_menu.php', ['csrf' => $csrf, 'menu_id' => 'abc'], $jar);
cek('ID tidak valid -> 400', $c === 400, "HTTP $c");
[$c, $o] = http($base . '/admin/aksi/hapus_menu.php', ['csrf' => $csrf, 'menu_id' => 999999], $jar);
cek('ID tak ada -> 404', $c === 404, "HTTP $c");
[$c, $o] = http($base . '/admin/aksi/hapus_menu.php', ['menu_id' => 1], $jar);
cek('CSRF salah -> 403', $c === 403, "HTTP $c");
[$c, $o] = http($base . '/admin/aksi/hapus_menu.php', ['csrf' => $csrf, 'menu_id' => 1], $jar);
// hapus_menu hanya terima POST; metode GET:
$ch = curl_init($base . '/admin/aksi/hapus_menu.php'); curl_setopt_array($ch, [CURLOPT_RETURNTRANSFER => true,
    CURLOPT_COOKIEFILE => $jar, CURLOPT_COOKIEJAR => $jar]); curl_exec($ch);
$code = curl_getinfo($ch, CURLINFO_HTTP_CODE); curl_close($ch);
cek('GET ditolak -> 405', $code === 405, "HTTP $code");

@unlink($tmp); @unlink($jar);
// Bersihkan fixture pesanan uji lebih dulu (detail ikut cascade), lalu menu uji.
foreach (db_all($kon, 'SELECT id FROM pesanan WHERE kode LIKE ?', ['UJI-HAPUS-%']) as $p) {
    db_exec($kon, 'DELETE FROM pesanan WHERE id = ?', [(int)$p['id']]);
}
db_exec($kon, 'DELETE FROM menu WHERE nama LIKE ?', ['[UJI-HAPUS]%']);
echo "\n" . ($gagal === 0 ? 'SEMUA UJI HAPUS LULUS' : "ADA $gagal UJI GAGAL") . PHP_EOL;
exit($gagal === 0 ? 0 : 1);
