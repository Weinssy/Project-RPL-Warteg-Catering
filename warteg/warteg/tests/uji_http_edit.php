<?php
// Uji HTTP endpoint admin/aksi/edit_menu.php memakai server PHP bawaan.
// Jalankan: php -S 127.0.0.1:8765   (dari folder proyek) lalu: php tests/uji_http_edit.php
require_once __DIR__ . '/../includes/bootstrap.php';

$base = 'http://127.0.0.1:8765';
$jar  = sys_get_temp_dir() . '/warteg_cookie_' . getmypid() . '.txt';
@unlink($jar);

function http(string $url, array $post = null, string $jar = '', array $files = []): array {
    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true, CURLOPT_COOKIEJAR => $jar, CURLOPT_COOKIEFILE => $jar,
        CURLOPT_FOLLOWLOCATION => true, CURLOPT_TIMEOUT => 10,
    ]);
    if ($post !== null) {
        $body = $post;
        foreach ($files as $k => $v) $body[$k] = new CURLFile($v);
        curl_setopt($ch, CURLOPT_POST, true);
        curl_setopt($ch, CURLOPT_POSTFIELDS, $body);
    }
    $out = curl_exec($ch);
    $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);
    return [$code, (string)$out];
}

$gagal = 0;
function cek(string $label, bool $ok, string $info = ''): void {
    global $gagal;
    if ($ok) echo "  [OK]   $label" . ($info ? " -> $info" : '') . PHP_EOL;
    else { $gagal++; echo "  [GAGAL] $label" . ($info ? " -> $info" : '') . PHP_EOL; }
}

// 0. Tanpa login -> 401 JSON
[$c, $o] = http($base . '/admin/aksi/edit_menu.php', ['menu_id' => '1'], $jar);
cek('Tanpa login ditolak', $c === 401, "HTTP $c");

// 1. Login (ambil CSRF dari halaman login dulu)
[$c, $loginPage] = http($base . '/admin/login.php', null, $jar);
preg_match('/name="csrf" value="([a-f0-9]+)"/', $loginPage, $lm);
http($base . '/admin/login.php', ['username' => 'admin', 'password' => 'admin123', 'csrf' => $lm[1] ?? ''], $jar);
// Ambil CSRF dari dashboard
[$c, $dash] = http($base . '/admin/index.php', null, $jar);
preg_match('/name="csrf-token" content="([a-f0-9]+)"/', $dash, $mm);
$csrf = $mm[1] ?? '';
cek('Login sukses + dapat CSRF', $csrf !== '', substr($csrf, 0, 12) . '...');

// Siapkan menu uji
db_exec($kon, 'INSERT INTO menu (nama, kategori, harga, aktif) VALUES (?, ?, ?, 1)', ['[UJI-HTTP] Menu', 'nasi', 15000]);
$mid = (int)$kon->insert_id;

// 2. Tanpa CSRF -> 403
[$c, $o] = http($base . '/admin/aksi/edit_menu.php', ['menu_id' => $mid, 'nama' => 'X', 'kategori' => 'nasi', 'harga' => '16000', 'aktif' => '1'], $jar);
cek('CSRF salah ditolak', $c === 403, "HTTP $c");

// 3. Validasi: nama kosong -> 422
[$c, $o] = http($base . '/admin/aksi/edit_menu.php', ['csrf' => $csrf, 'menu_id' => $mid, 'nama' => '  ', 'kategori' => 'nasi', 'harga' => '16000', 'aktif' => '1'], $jar);
cek('Nama kosong -> 422', $c === 422, "HTTP $c " . $o);

// 4. Validasi: harga 0 -> 422
[$c, $o] = http($base . '/admin/aksi/edit_menu.php', ['csrf' => $csrf, 'menu_id' => $mid, 'nama' => 'Baru', 'kategori' => 'nasi', 'harga' => '0', 'aktif' => '1'], $jar);
cek('Harga 0 -> 422', $c === 422, "HTTP $c");

// 5. ID tidak ada -> 404
[$c, $o] = http($base . '/admin/aksi/edit_menu.php', ['csrf' => $csrf, 'menu_id' => 999999, 'nama' => 'Baru', 'kategori' => 'nasi', 'harga' => '16000', 'aktif' => '1'], $jar);
cek('ID tidak ada -> 404', $c === 404, "HTTP $c");

// 6. Sukses: ubah nama, kategori, harga, aktif
[$c, $o] = http($base . '/admin/aksi/edit_menu.php', ['csrf' => $csrf, 'menu_id' => $mid, 'nama' => '[UJI-HTTP] Menu Baru', 'kategori' => 'sayur', 'harga' => '21000', 'aktif' => '0'], $jar);
$j = json_decode($o, true);
cek('Edit sukses', $c === 200 && !empty($j['ok']), $o);
$m = db_row($kon, 'SELECT nama, kategori, harga, aktif FROM menu WHERE id = ?', [$mid]);
cek('DB ter-update', $m['nama'] === '[UJI-HTTP] Menu Baru' && $m['kategori'] === 'sayur' && (int)$m['harga'] === 21000 && (int)$m['aktif'] === 0, json_encode($m));

// 7. Nama duplikat -> 409
db_exec($kon, 'INSERT INTO menu (nama, kategori, harga) VALUES (?, ?, ?)', ['[UJI-HTTP] Duplikat', 'nasi', 10000]);
[$c, $o] = http($base . '/admin/aksi/edit_menu.php', ['csrf' => $csrf, 'menu_id' => $mid, 'nama' => '[UJI-HTTP] Duplikat', 'kategori' => 'sayur', 'harga' => '21000', 'aktif' => '1'], $jar);
cek('Nama duplikat -> 409', $c === 409, "HTTP $c");

// 8. Upload foto baru valid (pakai PNG 1x1 minimal, tanpa butuh ekstensi GD)
$png1x1 = base64_decode('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mP8z8BQDwAEhQGAhKmMIQAAAABJRU5ErkJggg==');
$tmp = sys_get_temp_dir() . '/uji_foto.png';
file_put_contents($tmp, $png1x1);
[$c, $o] = http($base . '/admin/aksi/edit_menu.php',
    ['csrf' => $csrf, 'menu_id' => $mid, 'nama' => '[UJI-HTTP] Menu Baru', 'kategori' => 'sayur', 'harga' => '21000', 'aktif' => '1'],
    $jar, ['foto' => $tmp]);
$j = json_decode($o, true);
$m = db_row($kon, 'SELECT foto FROM menu WHERE id = ?', [$mid]);
cek('Upload foto sukses', $c === 200 && !empty($j['ok']) && !empty($m['foto']), (string)($m['foto'] ?? ''));
$fotoBaru = $m['foto'] ?? '';

// 9. Foto lama TIDAK dihapus sebelum sukses: sekarang ganti lagi ke file lain (JPG 1x1 minimal)
$jpg1x1 = base64_decode('/9j/4AAQSkZJRgABAQEAYABgAAD/2wBDAAgGBgcGBQgHBwcJCQgKDBQNDAsLDBkSEw8UHRofHh0aHBwgJC4nICIsIxwcKDcpLDAxNDQ0Hyc5PTgyPC4zNDL/wAALCAABAAEBAREA/8QAFAABAAAAAAAAAAAAAAAAAAAACf/EABQQAQAAAAAAAAAAAAAAAAAAAAD/2gAIAQEAAD8AKp//2Q==');
$tmp2 = sys_get_temp_dir() . '/uji_foto2.jpg';
file_put_contents($tmp2, $jpg1x1);
[$c, $o] = http($base . '/admin/aksi/edit_menu.php',
    ['csrf' => $csrf, 'menu_id' => $mid, 'nama' => '[UJI-HTTP] Menu Baru', 'kategori' => 'sayur', 'harga' => '21000', 'aktif' => '1'],
    $jar, ['foto' => $tmp2]);
$m2 = db_row($kon, 'SELECT foto FROM menu WHERE id = ?', [$mid]);
cek('Foto diganti', $m2['foto'] !== $fotoBaru && !empty($m2['foto']), (string)$m2['foto']);

// 10. File tidak valid -> 422 dan DB tidak berubah
$tmpTxt = sys_get_temp_dir() . '/uji_bukan.txt';
file_put_contents($tmpTxt, 'ini bukan gambar');
$sebelum = db_row($kon, 'SELECT nama, foto FROM menu WHERE id = ?', [$mid]);
[$c, $o] = http($base . '/admin/aksi/edit_menu.php',
    ['csrf' => $csrf, 'menu_id' => $mid, 'nama' => 'SEHARUSNYA TIDAK TERSIMPAN', 'kategori' => 'sayur', 'harga' => '21000', 'aktif' => '1'],
    $jar, ['foto' => $tmpTxt]);
$sesudah = db_row($kon, 'SELECT nama, foto FROM menu WHERE id = ?', [$mid]);
cek('File non-gambar -> 422', $c === 422, "HTTP $c $o");
cek('Data tidak berubah saat gagal', $sebelum['nama'] === $sesudah['nama'] && $sebelum['foto'] === $sesudah['foto']);

// Bersihkan (menu_harian lebih dulu: FK menu_harian.menu_id -> menu.id tanpa CASCADE saat DELETE manual)
foreach ([$tmp, $tmp2, $tmpTxt] as $f) @unlink($f);
foreach (db_all($kon, 'SELECT id FROM menu WHERE nama LIKE ?', ['[UJI-HTTP]%']) as $u) {
    db_exec($kon, 'DELETE FROM menu_harian WHERE menu_id = ?', [(int)$u['id']]);
}
db_exec($kon, 'DELETE FROM menu WHERE nama LIKE ?', ['[UJI-HTTP]%']);
@unlink(sys_get_temp_dir() . '/' . basename($jar));
// hapus file foto uji dari uploads
foreach ([$fotoBaru, $m2['foto'] ?? ''] as $f) if ($f) @unlink(__DIR__ . '/../uploads/menu/' . $f);
echo "\n" . ($gagal === 0 ? 'SEMUA UJI HTTP LULUS' : "ADA $gagal UJI GAGAL") . PHP_EOL;
exit($gagal === 0 ? 0 : 1);
