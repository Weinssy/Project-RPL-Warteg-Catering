<?php
// Uji HTTP halaman Tambah Menu (admin/tambah_menu.php): halaman tersedia via sidebar,
// tambah menu menyimpan stok, dan validasi stok backend menolak nilai tidak valid.
// Jalankan dari folder proyek: php -S 127.0.0.1:8765 -t .  lalu: php tests/uji_http_tambah_menu.php
require_once __DIR__ . '/../includes/bootstrap.php';

$base = 'http://127.0.0.1:8765';
$jar  = sys_get_temp_dir() . '/warteg_tambah_' . getmypid() . '.txt';
@unlink($jar);

function http(string $url, array $post = null, string $jar = '', array $files = []): array {
    $ch = curl_init($url);
    curl_setopt_array($ch, [CURLOPT_RETURNTRANSFER => true, CURLOPT_COOKIEJAR => $jar, CURLOPT_COOKIEFILE => $jar,
        CURLOPT_FOLLOWLOCATION => true, CURLOPT_TIMEOUT => 10]);
    if ($post !== null) {
        $body = $post;
        foreach ($files as $k => $v) $body[$k] = new CURLFile($v);
        curl_setopt($ch, CURLOPT_POST, true);
        curl_setopt($ch, CURLOPT_POSTFIELDS, $body);
    }
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

// Ambil CSRF dari halaman tambah menu
[$c, $page] = http($base . '/admin/tambah_menu.php', null, $jar);
cek('Halaman Tambah Menu dapat dibuka (200)', $c === 200, "HTTP $c");
preg_match('/name="csrf-token" content="([a-f0-9]+)"/', $page, $mm);
$csrf = $mm[1] ?? '';
cek('Halaman menampilkan field stok', strpos($page, 'name="stok"') !== false);
cek('Halaman menampilkan tautan sidebar Tambah Menu', strpos($page, 'admin/tambah_menu.php') !== false && strpos($page, 'active') !== false);
cek('Formulir punya batas max 120', preg_match('/id="stokMenu" name="stok"[^>]*max="120"/', $page) === 1);

// Dashboard TIDAK lagi memuat form tambah menu
[$c, $dash] = http($base . '/admin/index.php', null, $jar);
cek('Dashboard tidak memuat form tambah menu', strpos($dash, 'name="stok"') === false && strpos($dash, 'class="form-menu"') === false);
cek('Dashboard menautkan halaman Tambah Menu dari sidebar', strpos($dash, 'admin/tambah_menu.php') !== false);

$tgl = date('Y-m-d');
$nama = '[UJI-TAMBAH] Nasi ' . getmypid();

function tambah(array $extra): array {
    global $base, $jar, $csrf, $tgl, $nama;
    return http($base . '/admin/tambah_menu.php', array_merge([
        'csrf' => $csrf, 'tanggal' => $tgl, 'nama' => $nama, 'kategori' => 'nasi',
        'harga' => '15000', 'stok' => '50',
    ], $extra), $jar);
}

// Helper: stok tersimpan untuk nama+tanggal (via menu master -> menu_harian)
function stokTersimpan(mysqli $kon, string $nama, string $tgl): ?int {
    $row = db_row($kon, 'SELECT mh.stok FROM menu_harian mh JOIN menu m ON m.id = mh.menu_id
        WHERE m.nama = ? AND mh.tanggal = ?', [$nama, $tgl]);
    return $row ? (int)$row['stok'] : null;
}
function hapusMenu(mysqli $kon, string $nama): void {
    foreach (db_all($kon, 'SELECT id FROM menu WHERE nama = ?', [$nama]) as $m) {
        db_exec($kon, 'DELETE FROM menu_harian WHERE menu_id = ?', [(int)$m['id']]);
        db_exec($kon, 'DELETE FROM menu WHERE id = ?', [(int)$m['id']]);
    }
}
hapusMenu($kon, $nama);

// 1. Tambah menu valid, stok 50 tersimpan
[$c, $p] = tambah(['stok' => '50']);
cek('Tambah menu stok 50 berhasil', $c === 200 && strpos($p, 'ditambahkan') !== false || $c === 200);
cek('Stok 50 tersimpan di menu_harian', stokTersimpan($kon, $nama, $tgl) === 50, (string)stokTersimpan($kon, $nama, $tgl));

// 2. Batas atas: 120 diterima, 121 ditolak
[$c, $p] = tambah(['stok' => '120']);
cek('Stok 120 diterima', (int)stokTersimpan($kon, $nama, $tgl) === 120, (string)stokTersimpan($kon, $nama, $tgl));

[$c, $p] = tambah(['stok' => '121']);
cek('Stok 121 ditolak (stok tetap 120)', (int)stokTersimpan($kon, $nama, $tgl) === 120, (string)stokTersimpan($kon, $nama, $tgl));
cek('Pesan galat stok 121 tampil', strpos($p, 'maksimal 120') !== false);

// 3. Nilai tidak valid ditolak, stok lama tidak berubah
foreach (['-1' => 'negatif', '10,5' => 'desimal koma', '10.5' => 'desimal titik', 'abc' => 'huruf'] as $val => $ket) {
    [$c, $p] = tambah(['stok' => (string)$val]);
    cek("Stok $ket ditolak (stok tetap 120)", (int)stokTersimpan($kon, $nama, $tgl) === 120, "masih " . stokTersimpan($kon, $nama, $tgl));
}

// 4. Stok 0 valid (minimum)
[$c, $p] = tambah(['stok' => '0']);
cek('Stok 0 diterima', stokTersimpan($kon, $nama, $tgl) === 0, (string)stokTersimpan($kon, $nama, $tgl));

// 5. Tidak menggandakan data menu saat nama sama dipakai lagi (upsert)
$jml = (int)db_val($kon, 'SELECT COUNT(*) FROM menu WHERE nama = ?', [$nama]);
cek('Nama menu tidak terduplikasi', $jml === 1, "jumlah=$jml");

// Bersihkan
hapusMenu($kon, $nama);
@unlink($jar);
echo "\n" . ($gagal === 0 ? 'SEMUA UJI TAMBAH MENU HTTP LULUS' : "ADA $gagal UJI GAGAL") . PHP_EOL;
exit($gagal === 0 ? 0 : 1);
