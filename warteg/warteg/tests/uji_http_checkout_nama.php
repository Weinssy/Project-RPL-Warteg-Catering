<?php
// =====================================================================
// Uji HTTP: (1) halaman index TIDAK lagi memuat formulir checkout inline,
//          (2) halaman checkout.php terpisah memuat formulir,
//          (3) validasi nama pemesan di endpoint pesan_aksi.php (backend).
// Jalankan (server lebih dulu): php -S 127.0.0.1:8765 -t .
//   php tests/uji_http_checkout_nama.php
// =====================================================================
$base = 'http://127.0.0.1:8765';
$gagal = 0;
function cek(string $nama, bool $lolos, string $ket = ''): void {
    global $gagal;
    echo ($lolos ? '  [OK]   ' : '  [GAGAL] ') . $nama . ($ket !== '' ? ' :: ' . $ket : '') . PHP_EOL;
    if (!$lolos) $gagal++;
}
function req(string $url, ?array $post, ?string $cookie): array {
    $ch = curl_init($url);
    curl_setopt_array($ch, [CURLOPT_RETURNTRANSFER => true, CURLOPT_TIMEOUT => 15]);
    if ($cookie !== null) { curl_setopt($ch, CURLOPT_COOKIEJAR, $cookie); curl_setopt($ch, CURLOPT_COOKIEFILE, $cookie); }
    if ($post !== null) { curl_setopt($ch, CURLOPT_POST, true); curl_setopt($ch, CURLOPT_POSTFIELDS, $post); }
    $body = curl_exec($ch); $code = curl_getinfo($ch, CURLINFO_HTTP_CODE); curl_close($ch);
    return ['code' => $code, 'body' => (string)$body];
}

$cookie = sys_get_temp_dir() . '/wt_checkout_nama_' . getmypid() . '.txt';
@unlink($cookie);

// ---------- 1. index.php: tidak ada formulir checkout inline ----------
$home = req($base . '/index.php', null, $cookie);
cek('index.php HTTP 200', $home['code'] === 200, 'kode ' . $home['code']);
cek('index.php TIDAK memuat formulir checkout (id="formCheckout")', strpos($home['body'], 'id="formCheckout"') === false);
cek('index.php TIDAK memuat section #checkout', !preg_match('/id="checkout"/', $home['body']));
cek('index.php menautkan tombol ke checkout.php', strpos($home['body'], 'checkout.php') !== false);
cek('index.php memuat ringkasan (#ringkasanIsi, #btnLanjut)', strpos($home['body'], 'id="ringkasanIsi"') !== false && strpos($home['body'], 'id="btnLanjut"') !== false);

// ---------- 2. checkout.php: formulir terpisah & field nama ----------
$co = req($base . '/checkout.php', null, $cookie);
cek('checkout.php HTTP 200', $co['code'] === 200, 'kode ' . $co['code']);
cek('checkout.php memuat formulir checkout', strpos($co['body'], 'id="formCheckout"') !== false);
cek('checkout.php memuat input nama (name="nama")', strpos($co['body'], 'name="nama"') !== false);

// ---------- 3. Validasi nama di endpoint pesan_aksi.php ----------
preg_match('/name="csrf-token" content="([a-f0-9]+)"/', $home['body'], $m);
$csrf = $m[1] ?? '';
cek('CSRF token ditemukan', $csrf !== '');

// Ambil menu yang bisa dipilih untuk H+1.
$besok = (new DateTime('today'))->modify('+1 day')->format('Y-m-d');
$mn = req($base . '/pesan_aksi.php?aksi=menu&tanggal=' . $besok, null, $cookie);
$data = json_decode($mn['body'], true);
$pilih = array_values(array_filter($data['menu'] ?? [], fn($x) => !empty($x['bisa'])));
cek('Ada menu bisa dipilih', count($pilih) > 0, 'jumlah=' . count($pilih));

if ($pilih) {
    $items = json_encode([(string)$pilih[0]['id'] => 10]);
    $kirim = function (string $nama, string $idem) use ($base, $csrf, $besok, $items, $cookie) {
        return req($base . '/pesan_aksi.php', [
            'aksi' => 'pesan', 'csrf' => $csrf, 'nama' => $nama, 'no_wa' => '081234560123',
            'tanggal_ambil' => $besok, 'jam_ambil' => '10:00', 'catatan' => '',
            'items' => $items, 'jumlah_box' => 10, 'idempotency' => $idem,
        ], $cookie);
    };

    // a) Nama angka saja -> ditolak 422, field=nama.
    $r = $kirim('123456', 'nama-angka-' . time());
    $j = json_decode($r['body'], true);
    cek('Nama "123456" ditolak (422, field=nama)', $r['code'] === 422 && ($j['field'] ?? '') === 'nama', 'kode ' . $r['code'] . ' ' . ($j['pesan'] ?? ''));

    // b) Nama dengan angka di depan -> ditolak.
    $r = $kirim('123 Ahmad', 'nama-mix-' . time());
    $j = json_decode($r['body'], true);
    cek('Nama "123 Ahmad" ditolak (422)', $r['code'] === 422 && ($j['field'] ?? '') === 'nama', 'kode ' . $r['code'] . ' ' . ($j['pesan'] ?? ''));

    // c) Nama valid -> diterima (200/ok).
    $r = $kirim('Ahmad Fauzi', 'nama-valid-' . time());
    $j = json_decode($r['body'], true);
    cek('Nama valid "Ahmad Fauzi" diterima', $r['code'] === 200 && !empty($j['ok']), 'kode ' . $r['code'] . ' ' . ($j['pesan'] ?? ''));
    // Bersihkan pesanan uji bila dibuat.
    if (!empty($j['kode'])) {
        require_once __DIR__ . '/../config/koneksi.php';
        require_once __DIR__ . '/../includes/helpers.php';
        $pid = (int)db_val($kon, 'SELECT id FROM pesanan WHERE kode = ?', [$j['kode']]);
        if ($pid > 0) {
            db_exec($kon, 'DELETE FROM detail_pesanan WHERE pesanan_id = ?', [$pid]);
            db_exec($kon, 'DELETE FROM pesanan WHERE id = ?', [$pid]);
        }
    }
}

@unlink($cookie);
echo "\n" . ($gagal === 0 ? 'SEMUA UJI CHECKOUT & NAMA LULUS' : "ADA $gagal KEGAGALAN") . PHP_EOL;
exit($gagal === 0 ? 0 : 1);
