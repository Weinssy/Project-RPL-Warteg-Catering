<?php
// Uji HTTP fitur foto profil admin & manajemen admin (Tambah Admin).
// Prasyarat: database/schema.sql atau database/migrasi_profil_admin.sql sudah dijalankan
// (memberi kolom role, bahasa, diubah_pada, dan tabel aktivitas_admin).
// Jalankan server uji:  php -d upload_max_filesize=8M -d post_max_size=10M -S 127.0.0.1:8765 -t .
// lalu:                 php tests/uji_http_admin.php
//
// CATATAN MODEL OTORISASI TERBARU:
//   - Halaman Manajemen Admin (admin/admin.php) KHUSUS Owner. Admin biasa mendapat 403.
//   - Semua admin (termasuk admin biasa) tetap dapat mengubah profil/foto/kata sandinya
//     sendiri lewat admin/profil.php.
require_once __DIR__ . '/../includes/bootstrap.php';

$base = 'http://127.0.0.1:8765';
$jar  = sys_get_temp_dir() . '/warteg_admin_' . getmypid() . '.txt';
@unlink($jar);

$gagal = 0;
function cek(string $label, bool $ok, string $info = ''): void {
    global $gagal;
    if ($ok) echo "  [OK]    $label" . ($info !== '' ? " -> $info" : '') . PHP_EOL;
    else { $gagal++; echo "  [GAGAL] $label" . ($info !== '' ? " -> $info" : '') . PHP_EOL; }
}

function req(string $url, ?array $post = null, bool $ikuti = true): array {
    global $jar;
    $ch = curl_init($url);
    curl_setopt_array($ch, [CURLOPT_RETURNTRANSFER => true, CURLOPT_COOKIEJAR => $jar, CURLOPT_COOKIEFILE => $jar,
        CURLOPT_FOLLOWLOCATION => $ikuti, CURLOPT_TIMEOUT => 20, CURLOPT_HEADER => false]);
    if ($post !== null) { curl_setopt($ch, CURLOPT_POST, true); curl_setopt($ch, CURLOPT_POSTFIELDS, $post); }
    $out = curl_exec($ch); $code = curl_getinfo($ch, CURLINFO_HTTP_CODE); curl_close($ch);
    return [$code, (string)$out];
}

function csrf_dari(string $html): string { return preg_match('/name="csrf" value="([a-f0-9]+)"/', $html, $m) ? $m[1] : ''; }
function csrf_meta(string $html): string { return preg_match('/name="csrf-token" content="([a-f0-9]+)"/', $html, $m) ? $m[1] : ''; }

// Buat file gambar uji di temp dir.
function buat_png(int $w, int $h): string {
    $im = imagecreatetruecolor($w, $h); imagefill($im, 0, 0, imagecolorallocate($im, 30, 120, 200));
    $p = tempnam(sys_get_temp_dir(), 'png') . '.png'; imagepng($im, $p); imagedestroy($im); return $p;
}
$tmp = [];
$fotoPng  = buat_png(120, 120); $tmp[] = $fotoPng;
$fotoPng2 = buat_png(90, 90);   $tmp[] = $fotoPng2;
// PNG valid berisi data acak (tak terkompresi) agar ukurannya dipastikan > 2 MB.
$fotoBesar = tempnam(sys_get_temp_dir(), 'bsr') . '.png'; $tmp[] = $fotoBesar;
file_put_contents($fotoBesar, "\x89PNG\r\n\x1a\n" . random_bytes(2 * 1024 * 1024 + 1000));
// PHP disamarkan sebagai .png: header PNG valid + kode PHP di ekor file.
$palsu = tempnam(sys_get_temp_dir(), 'palsu') . '.png'; $tmp[] = $palsu;
file_put_contents($palsu, "\x89PNG\r\n\x1a\n" . random_bytes(200));
file_put_contents($palsu, "<?php echo 'pwned'; ?>", FILE_APPEND);

echo "Ukuran: PNG valid=" . filesize($fotoPng) . "B, besar=" . round(filesize($fotoBesar) / 1024 / 1024, 2) . "MB" . PHP_EOL;

// ================= 1. Akses tanpa login ditolak =================
echo PHP_EOL . "== Akses tanpa login ==";
@unlink($jar);
[$c, $o] = req($base . '/admin/admin.php', null, false);
cek('admin/admin.php tanpa login -> ditolak/redirect', in_array($c, [301,302,303,307,308], true) || strpos($o, 'Masuk') !== false, "HTTP $c");
[$c2, $o2] = req($base . '/admin/admin.php');
cek('Ikuti redirect -> halaman login', strpos($o2, 'type="password"') !== false && strpos($o2, 'Konfirmasi password') === false, "HTTP $c2");

// ================= 2. Login admin lama (Owner) tetap bisa =================
echo PHP_EOL . "== Login admin lama ==";
[$c, $lp] = req($base . '/admin/login.php');
$tok = csrf_dari($lp);
[$c, $dash] = req($base . '/admin/login.php', ['username' => 'admin', 'password' => 'admin123', 'csrf' => $tok]);
cek('Admin lama (admin/admin123) dapat login', strpos($dash, 'Manajemen Admin') !== false && strpos($dash, 'Dashboard') !== false, "HTTP $c");
$csrf = csrf_meta($dash);
cek('CSRF meta tersedia di dashboard', $csrf !== '');
cek('Avatar default tampil (belum ada foto)', strpos($dash, 'avatar-inisial') !== false);

// Password salah ditolak
@unlink($jar);
[$c, $lp] = req($base . '/admin/login.php'); $tok = csrf_dari($lp);
[$c, $o] = req($base . '/admin/login.php', ['username' => 'admin', 'password' => 'salahbanget', 'csrf' => $tok]);
cek('Password salah ditolak', strpos($o, 'Username atau password salah') !== false, "HTTP $c");
// Login ulang untuk sesi yang sah
[$c, $lp] = req($base . '/admin/login.php'); $tok = csrf_dari($lp);
[$c, $dash] = req($base . '/admin/login.php', ['username' => 'admin', 'password' => 'admin123', 'csrf' => $tok]);
$csrf = csrf_meta($dash);

// ================= 3. Tambah admin =================
echo PHP_EOL . "== Tambah admin ==";
$userBaru = 'uji_' . substr(bin2hex(random_bytes(4)), 0, 8);
$passBaru = 'Rahasia#2026';

// 3a. Tanpa CSRF ditolak
[$c, $o] = req($base . '/admin/admin.php', ['aksi' => 'tambah_admin', 'nama' => 'Uji X', 'username' => 'ujicsrf', 'password' => 'abcdefgh', 'konfirmasi' => 'abcdefgh', 'csrf' => 'token-palsu']);
cek('Tambah admin tanpa CSRF valid -> 403', $c === 403, "HTTP $c");
cek('Akun ujicsrf TIDAK terbuat', db_row($kon, 'SELECT id FROM admin WHERE username = ?', ['ujicsrf']) === null);

// 3b. Username duplikat ditolak
[$c, $o] = req($base . '/admin/admin.php', ['aksi' => 'tambah_admin', 'nama' => 'Duplikat', 'username' => 'admin', 'password' => 'abcdefgh', 'konfirmasi' => 'abcdefgh', 'csrf' => $csrf]);
cek('Username duplikat (admin) ditolak', strpos($o, 'Username sudah dipakai') !== false, "HTTP $c");

// 3c. Password tidak cocok ditolak
[$c, $o] = req($base . '/admin/admin.php', ['aksi' => 'tambah_admin', 'nama' => 'Beda', 'username' => $userBaru, 'password' => 'abcdefgh', 'konfirmasi' => 'abcdefgX', 'csrf' => $csrf]);
cek('Konfirmasi password tidak cocok ditolak', strpos($o, 'Konfirmasi password tidak cocok') !== false);

// 3d. Password terlalu pendek ditolak
[$c, $o] = req($base . '/admin/admin.php', ['aksi' => 'tambah_admin', 'nama' => 'Pendek', 'username' => $userBaru, 'password' => 'abc', 'konfirmasi' => 'abc', 'csrf' => $csrf]);
cek('Password < 8 karakter ditolak', strpos($o, 'Password minimal 8 karakter') !== false);

// 3e. Username tidak valid ditolak
[$c, $o] = req($base . '/admin/admin.php', ['aksi' => 'tambah_admin', 'nama' => 'Aneh', 'username' => 'ha ha><', 'password' => 'abcdefgh', 'konfirmasi' => 'abcdefgh', 'csrf' => $csrf]);
cek('Username tidak valid ditolak', strpos($o, 'Username 3-50 karakter') !== false);

// 3f. Tambah admin valid + foto. Dibuat sebagai peran 'admin' (default) agar sekaligus
//     menguji bahwa admin biasa TIDAK mendapat akses Manajemen Admin.
[$c, $o] = req($base . '/admin/admin.php', ['aksi' => 'tambah_admin', 'nama' => 'Admin Uji', 'username' => $userBaru,
    'password' => $passBaru, 'konfirmasi' => $passBaru, 'csrf' => $csrf, 'role' => 'admin',
    'foto' => new CURLFile($fotoPng, 'image/png', 'foto.png')]);
$baru = db_row($kon, 'SELECT id, username, nama, foto, role, password_hash FROM admin WHERE username = ?', [$userBaru]);
cek('Admin baru tersimpan', $baru !== null, "HTTP $c");
cek('Pesan sukses tampil', strpos($o, 'berhasil ditambahkan') !== false);
cek('Password disimpan sebagai hash (bukan teks biasa)', $baru && $baru['password_hash'] !== $passBaru && strpos($baru['password_hash'], '$2y$') === 0);
cek('password_verify() cocok dengan password asli', $baru && password_verify($passBaru, $baru['password_hash']));
cek('Foto tersimpan dengan nama unik sistem', $baru && foto_admin_valid($baru['foto']), $baru['foto'] ?? '');
cek('File foto ada di uploads/admin/', $baru && is_file(folder_foto_admin() . '/' . $baru['foto']));
cek('Peran default akun baru = admin', $baru && $baru['role'] === 'admin');

// 3g. Username duplikat (akun baru) ditolak
[$c, $o] = req($base . '/admin/admin.php', ['aksi' => 'tambah_admin', 'nama' => 'Kembar', 'username' => $userBaru, 'password' => 'abcdefgh', 'konfirmasi' => 'abcdefgh', 'csrf' => $csrf]);
cek('Username duplikat (akun baru) ditolak', strpos($o, 'Username sudah dipakai') !== false);

// 3h. File PHP disamarkan sebagai .png ditolak
$userPalsu = 'uji_' . substr(bin2hex(random_bytes(4)), 0, 8);
[$c, $o] = req($base . '/admin/admin.php', ['aksi' => 'tambah_admin', 'nama' => 'Palsu', 'username' => $userPalsu,
    'password' => 'abcdefgh', 'konfirmasi' => 'abcdefgh', 'csrf' => $csrf,
    'foto' => new CURLFile($palsu, 'image/png', 'jahat.png')]);
cek('File PHP disamarkan .png ditolak', strpos($o, 'bukan gambar yang valid') !== false || strpos($o, 'Format foto harus') !== false,
    'akun terbuat? ' . (db_row($kon, 'SELECT id FROM admin WHERE username = ?', [$userPalsu]) ? 'YA-BOCOR' : 'tidak'));
cek('Akun palsu tidak terbuat', db_row($kon, 'SELECT id FROM admin WHERE username = ?', [$userPalsu]) === null);

// 3i. Gambar > 2 MB ditolak
$userBesar = 'uji_' . substr(bin2hex(random_bytes(4)), 0, 8);
[$c, $o] = req($base . '/admin/admin.php', ['aksi' => 'tambah_admin', 'nama' => 'Besar', 'username' => $userBesar,
    'password' => 'abcdefgh', 'konfirmasi' => 'abcdefgh', 'csrf' => $csrf,
    'foto' => new CURLFile($fotoBesar, 'image/png', 'besar.png')]);
cek('Gambar > 2 MB ditolak', strpos($o, 'maksimal 2 MB') !== false, 'akun? ' . (db_row($kon, 'SELECT id FROM admin WHERE username = ?', [$userBesar]) ? 'YA-BOCOR' : 'tidak'));

// ================= 4. Admin baru dapat login, tetapi BUKAN Owner =================
echo PHP_EOL . "== Login admin baru (peran admin) ==";
@unlink($jar);
[$c, $lp] = req($base . '/admin/login.php'); $tok = csrf_dari($lp);
[$c, $dash2] = req($base . '/admin/login.php', ['username' => $userBaru, 'password' => $passBaru, 'csrf' => $tok]);
cek('Admin baru dapat login', strpos($dash2, 'Dashboard') !== false, "HTTP $c");
cek('Header menampilkan nama admin baru', strpos($dash2, 'Admin Uji') !== false);
cek('Header menampilkan foto profil (bukan avatar inisial)', strpos($dash2, 'uploads/admin/') !== false && strpos($dash2, 'avatar-inisial') === false);
cek('Menu Manajemen Admin disembunyikan untuk admin biasa', strpos($dash2, 'admin/admin.php') === false);
[$c, $o403] = req($base . '/admin/admin.php', null, false);
cek('Admin biasa ditolak (403) saat membuka admin.php', $c === 403, "HTTP $c");
$csrfBaru = csrf_meta($dash2);

// ================= 5. Ganti foto profil LEWAT HALAMAN PROFIL =================
echo PHP_EOL . "== Ganti foto profil (via profil.php) ==";
[$c, $prof] = req($base . '/admin/profil.php');
cek('profil.php dapat dibuka admin biasa', $c === 200, "HTTP $c");
$csrfProf = csrf_dari($prof);
[$c, $o] = req($base . '/admin/profil.php', ['aksi' => 'foto', 'csrf' => $csrfProf,
    'foto' => new CURLFile($fotoPng2, 'image/png', 'baru.png')]);
$baru2 = db_row($kon, 'SELECT foto, diubah_pada FROM admin WHERE username = ?', [$userBaru]);
cek('Foto profil diganti', strpos($o, 'Foto profil berhasil diperbarui') !== false, $baru2['foto'] ?? '');
cek('Nama file berubah', $baru2 && $baru2['foto'] !== $baru['foto']);
cek('Foto lama sudah dihapus dari disk', !is_file(folder_foto_admin() . '/' . $baru['foto']));
cek('Foto baru ada di disk', $baru2 && is_file(folder_foto_admin() . '/' . $baru2['foto']));

// Ganti foto via CSRF salah ditolak
[$c, $o] = req($base . '/admin/profil.php', ['aksi' => 'foto', 'csrf' => 'salah',
    'foto' => new CURLFile($fotoPng, 'image/png', 'x.png')]);
cek('Ganti foto tanpa CSRF valid -> 403', $c === 403);

// ================= 5b. Halaman Manajemen Admin sebagai Owner =================
echo PHP_EOL . "== Manajemen Admin (Owner) ==";
@unlink($jar);
[$c, $lp] = req($base . '/admin/login.php'); $tok = csrf_dari($lp);
req($base . '/admin/login.php', ['username' => 'admin', 'password' => 'admin123', 'csrf' => $tok]);
[$c, $hal] = req($base . '/admin/admin.php');
cek('Owner dapat membuka Halaman admin', $c === 200, "HTTP $c");
cek('Halaman admin menampilkan daftar admin', strpos($hal, 'Daftar admin') !== false);
cek('Akun sendiri ditandai "Anda"', strpos($hal, 'badge-saya') !== false);
cek('Daftar admin tidak menampilkan hash/password', stripos($hal, 'password_hash') === false && strpos($hal, '$2y$') === false);
cek('Daftar admin memuat akun uji', strpos($hal, $userBaru) !== false);

// ================= 6. Fitur lain tetap berfungsi =================
echo PHP_EOL . "== Regresi halaman lain ==";
foreach (['admin/index.php', 'admin/pemesan.php', 'admin/menu_harian.php', 'admin/tambah_menu.php', 'admin/rekap.php', 'admin/profil.php'] as $h) {
    [$c, $o] = req($base . '/' . $h);
    $ada_fatal = stripos($o, 'Fatal error') !== false || stripos($o, 'Warning:') !== false;
    cek("$h tampil tanpa error", $c === 200 && !$ada_fatal, "HTTP $c");
}

// ================= Bersihkan data uji =================
echo PHP_EOL . "== Bersihkan ==";
$fotoUji = array_filter([$baru['foto'] ?? null, $baru2['foto'] ?? null]);
$hapus = db_all($kon, 'SELECT id, foto FROM admin WHERE username IN (?, ?, ?)', [$userBaru, $userPalsu, $userBesar]);
foreach ($hapus as $h) {
    if ($h['foto']) $fotoUji[] = $h['foto'];
    db_exec($kon, 'DELETE FROM aktivitas_admin WHERE admin_id = ?', [(int)$h['id']]);
    db_exec($kon, 'DELETE FROM admin WHERE id = ?', [(int)$h['id']]);
}
cek('Data uji admin dibersihkan', db_row($kon, 'SELECT id FROM admin WHERE username IN (?, ?, ?)', [$userBaru, $userPalsu, $userBesar]) === null);
foreach ($fotoUji as $ff) hapus_foto_admin_file($kon, $ff);
$sisaFile = array_filter($fotoUji, fn($ff) => is_file(folder_foto_admin() . '/' . $ff));
cek('Tidak ada file uji tertinggal', !$sisaFile, implode(', ', $sisaFile));
foreach ($tmp as $t) @unlink($t);
@unlink($jar);

echo PHP_EOL . ($gagal === 0 ? 'SEMUA UJI ADMIN LULUS' : "ADA $gagal UJI GAGAL") . PHP_EOL;
exit($gagal === 0 ? 0 : 1);
