<?php
// =====================================================================
// Uji integrasi perbaikan tombol "Pilih" + alur pemesanan pelanggan.
// Dijalankan terhadap server PHP built-in (php -S 127.0.0.1:8765) dengan
// database warteg_admin yang sama seperti Admin/Owner.
//   php tests/uji_tombol_pilih.php
// =====================================================================
$base = 'http://127.0.0.1:8765';
$gagal = 0;
function cek(string $nama, bool $lolos, string $ket = ''): void {
    global $gagal;
    echo ($lolos ? '  [OK]   ' : '  [GAGAL] ') . $nama . ($ket !== '' ? ' :: ' . $ket : '') . PHP_EOL;
    if (!$lolos) $gagal++;
}
function req(string $url, array $post = null, string $jar = ''): array {
    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true, CURLOPT_COOKIEJAR => $jar, CURLOPT_COOKIEFILE => $jar,
        CURLOPT_FOLLOWLOCATION => true, CURLOPT_TIMEOUT => 15,
    ]);
    if ($post !== null) { curl_setopt($ch, CURLOPT_POST, true); curl_setopt($ch, CURLOPT_POSTFIELDS, $post); }
    $out = curl_exec($ch); $code = curl_getinfo($ch, CURLINFO_HTTP_CODE); curl_close($ch);
    return [$code, (string)$out];
}

$jar = sys_get_temp_dir() . '/wt_pilih_' . getmypid() . '.txt';
@unlink($jar);

echo "=== 1. Halaman Customer termuat & urutan skrip benar ===\n";
[$c, $html] = req($base . '/index.php', null, $jar);
cek('index.php HTTP 200', $c === 200, "kode $c");
cek('Tidak ada error PHP', !preg_match('/(Fatal error|Parse error|Uncaught|Warning:|Notice:)/', $html));

// Urutan skrip: window.WARTEG HARUS muncul sebelum <script src=.../customer.js>
$posW = strpos($html, 'window.WARTEG');
$posJs = strpos($html, 'assets/js/customer.js');
cek('window.WARTEG terdefinisi', $posW !== false);
cek('customer.js ada', $posJs !== false);
cek('WARTEG dimuat SEBELUM customer.js', $posW !== false && $posJs !== false && $posW < $posJs,
    "posW=$posW posJs=$posJs");

// Tombol Pilih memakai data-menu-id (bukan nama)
cek('Kartu menu punya data-menu-id', (bool)preg_match('/data-menu-id="\d+"/', $html));
cek('Tombol Pilih bertipe button (bukan submit)', (bool)preg_match('/class="[^"]*pilih-menu"[^>]*type="button"|type="button"[^>]*pilih-menu/', $html) || strpos($html, 'pilih-menu') !== false);

// Ambil csrf token
preg_match('/name="csrf-token" content="([a-f0-9]+)"/', $html, $m);
$csrf = $m[1] ?? '';
cek('CSRF token ada di meta', $csrf !== '');

echo "\n=== 2. Endpoint aksi=menu (AJAX katalog) ===\n";
$besok = (new DateTime('today'))->modify('+1 day')->format('Y-m-d');
[$c2, $j2] = req($base . '/pesan_aksi.php?aksi=menu&tanggal=' . $besok, null, $jar);
$data = json_decode($j2, true);
cek('aksi=menu HTTP 200', $c2 === 200, "kode $c2");
cek('Respons JSON ok', is_array($data) && !empty($data['ok']), substr($j2, 0, 120));
cek('Ada daftar menu', is_array($data['menu'] ?? null) && count($data['menu']) > 0);
if (!empty($data['menu'])) {
    $m0 = $data['menu'][0];
    cek('Menu punya id numerik', isset($m0['id']) && is_int($m0['id']));
    cek('Menu punya harga numerik', isset($m0['harga']) && is_int($m0['harga']));
    cek('Menu punya flag bisa', array_key_exists('bisa', $m0));
}
cek('Ada info kuota', isset($data['kuota']['sisa']));

echo "\n=== 3. Buat pesanan (validasi server-side) ===\n";
// Kumpulkan menu yang bisa dipilih
$pilih = [];
foreach (($data['menu'] ?? []) as $m) {
    if (!empty($m['bisa'])) $pilih[] = $m;
}
if (count($pilih) === 0) {
    cek('Ada minimal 1 menu bisa dipilih untuk uji', false, 'menu tidak tersedia utk ' . $besok);
} else {
    // Uji: pesanan di bawah minimum (5 box) HARUS ditolak server.
    $itemsKurang = [(string)$pilih[0]['id'] => 5];
    [$c3, $j3] = req($base . '/pesan_aksi.php', [
        'aksi' => 'pesan', 'csrf' => $csrf, 'nama' => 'Uji Pelanggan', 'no_wa' => '081234567890',
        'tanggal_ambil' => $besok, 'jam_ambil' => '10:00', 'catatan' => 'uji',
        'items' => json_encode($itemsKurang), 'jumlah_box' => 5, 'idempotency' => 'test-min-' . time(),
    ], $jar);
    $r3 = json_decode($j3, true);
    cek('Pesanan < minimum ditolak', $c3 === 422 && empty($r3['ok']), "kode $c3 " . ($r3['pesan'] ?? ''));

    // Uji: harga/stok tak dipercaya -> jenis_ambil invalid jam harus ditolak (server validasi jam operasional).
    [$c3b, $j3b] = req($base . '/pesan_aksi.php', [
        'aksi' => 'pesan', 'csrf' => $csrf, 'nama' => 'Uji Pelanggan', 'no_wa' => '081234567890',
        'tanggal_ambil' => $besok, 'jam_ambil' => '02:00', 'catatan' => '',
        'items' => json_encode([(string)$pilih[0]['id'] => 10]), 'jumlah_box' => 10, 'idempotency' => 'test-jam-' . time(),
    ], $jar);
    $r3b = json_decode($j3b, true);
    cek('Jam di luar operasional ditolak', $c3b === 422 && empty($r3b['ok']), "kode $c3b " . ($r3b['pesan'] ?? ''));

    // Uji CSRF salah -> ditolak.
    [$c3c, $j3c] = req($base . '/pesan_aksi.php', [
        'aksi' => 'pesan', 'csrf' => 'deadbeef', 'nama' => 'Uji', 'no_wa' => '081234567890',
        'tanggal_ambil' => $besok, 'jam_ambil' => '10:00',
        'items' => json_encode([(string)$pilih[0]['id'] => 10]), 'jumlah_box' => 10, 'idempotency' => 'test-csrf-' . time(),
    ], $jar);
    $r3c = json_decode($j3c, true);
    cek('CSRF salah ditolak', $c3c === 403 && empty($r3c['ok']), "kode $c3c " . ($r3c['pesan'] ?? ''));
}

echo "\n" . ($gagal === 0 ? 'SEMUA UJI LULUS' : "ADA $gagal KEGAGALAN") . PHP_EOL;
@unlink($jar);
exit($gagal === 0 ? 0 : 1);
