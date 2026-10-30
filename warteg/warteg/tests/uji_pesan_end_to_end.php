<?php
// =====================================================================
// Uji end-to-end pembuatan pesanan pelanggan -> tersimpan di database yang
// SAMA dengan Admin/Owner (warteg_admin). Memakai server php -S 127.0.0.1:8765.
//   php tests/uji_pesan_end_to_end.php
// Pesanan uji memakai nama "[UJI] ..." agar mudah dikenali/dibersihkan.
// =====================================================================
require __DIR__ . '/../config/koneksi.php';
require __DIR__ . '/../includes/helpers.php';

$base = 'http://127.0.0.1:8765';
$gagal = 0;
function cek(string $nama, bool $lolos, string $ket = ''): void {
    global $gagal;
    echo ($lolos ? '  [OK]   ' : '  [GAGAL] ') . $nama . ($ket !== '' ? ' :: ' . $ket : '') . PHP_EOL;
    if (!$lolos) $gagal++;
}
function req(string $url, array $post = null, string $jar = ''): array {
    $ch = curl_init($url);
    curl_setopt_array($ch, [CURLOPT_RETURNTRANSFER => true, CURLOPT_COOKIEJAR => $jar, CURLOPT_COOKIEFILE => $jar, CURLOPT_FOLLOWLOCATION => true, CURLOPT_TIMEOUT => 15]);
    if ($post !== null) { curl_setopt($ch, CURLOPT_POST, true); curl_setopt($ch, CURLOPT_POSTFIELDS, $post); }
    $out = curl_exec($ch); $code = curl_getinfo($ch, CURLINFO_HTTP_CODE); curl_close($ch);
    return [$code, (string)$out];
}

$jar = sys_get_temp_dir() . '/wt_e2e_' . getmypid() . '.txt';
@unlink($jar);

echo "=== End-to-end: buat pesanan valid ===\n";
// 1. Muat halaman -> dapat cookie sesi + CSRF.
[$c, $html] = req($base . '/index.php', null, $jar);
preg_match('/name="csrf-token" content="([a-f0-9]+)"/', $html, $m);
$csrf = $m[1] ?? '';
cek('Mendapat CSRF token', $csrf !== '');

// 2. Ambil menu yang bisa dipilih untuk H+1.
$besok = (new DateTime('today'))->modify('+1 day')->format('Y-m-d');
[$c2, $j2] = req($base . '/pesan_aksi.php?aksi=menu&tanggal=' . $besok, null, $jar);
$data = json_decode($j2, true);
$pilih = array_values(array_filter($data['menu'] ?? [], fn($x) => !empty($x['bisa'])));
cek('Ada menu bisa dipilih', count($pilih) > 0, "jumlah=" . count($pilih));

if (count($pilih) === 0) { echo "\nTidak bisa lanjut uji.\n"; exit(1); }

// 3. Susun komposisi: nasi 4, lauk 6 (total 10 box) memakai 2 menu berbeda bila ada.
//    Model data = jumlah per menu; total_box = SUM(jumlah).
$items = [];
$sisa = 10;
foreach ($pilih as $i => $m) {
    $jml = ($i === 0) ? 4 : 6;
    if ($i > 1) break;
    $items[(string)$m['id']] = $jml;
    $sisa -= $jml;
    if ($sisa <= 0) break;
}
// Fallback bila hanya 1 menu.
if (count($items) === 1) { $k = array_key_first($items); $items[$k] = 10; }
$total = array_sum($items);
cek('Total box = 10', $total === 10, "total=$total");

$idem = 'e2e-' . time();
[$c3, $j3] = req($base . '/pesan_aksi.php', [
    'aksi' => 'pesan', 'csrf' => $csrf, 'nama' => '[UJI] Pelanggan E2E', 'no_wa' => '081298765432',
    'tanggal_ambil' => $besok, 'jam_ambil' => '11:00', 'catatan' => 'pesanan uji otomatis',
    'items' => json_encode($items), 'jumlah_box' => 10, 'idempotency' => $idem,
], $jar);
$res = json_decode($j3, true);
cek('Pesanan valid diterima (HTTP 200)', $c3 === 200, "kode $c3 " . ($res['pesan'] ?? ''));
cek('Respons ok=true', !empty($res['ok']));
cek('Ada kode pesanan', !empty($res['kode']), $res['kode'] ?? '');
cek('Ada url_nota', !empty($res['url_nota']));
cek('Pembayaran belum terkonfigurasi (jujur, tanpa QR palsu)', ($res['pembayaran']['terkonfigurasi'] ?? true) === false);

// 4. Verifikasi pesanan benar-benar ada di database ADMIN (tabel sama).
$kode = $res['kode'] ?? '';
if ($kode !== '') {
    $p = db_row($kon, 'SELECT * FROM pesanan WHERE kode = ?', [$kode]);
    cek('Pesanan tersimpan di tabel pesanan', $p !== null);
    if ($p) {
        cek('total_box tersimpan = 10', (int)$p['total_box'] === 10, (string)$p['total_box']);
        cek('nama pelanggan tersimpan', $p['nama_pelanggan'] === '[UJI] Pelanggan E2E');
        cek('no_wa ternormalisasi 62...', strpos($p['no_wa'], '62') === 0, $p['no_wa']);
        $det = db_all($kon, 'SELECT * FROM detail_pesanan WHERE pesanan_id = ?', [(int)$p['id']]);
        cek('Detail pesanan tersimpan', count($det) === count($items), 'detail=' . count($det));
        // Harga dihitung server: subtotal detail = harga_satuan * jumlah.
        $okHarga = true;
        foreach ($det as $d) { if ((int)$d['subtotal'] !== (int)$d['harga_satuan'] * (int)$d['jumlah']) $okHarga = false; }
        cek('Subtotal dihitung konsisten di server', $okHarga);
        // total_harga = sum subtotal
        $sum = array_sum(array_map(fn($d) => (int)$d['subtotal'], $det));
        cek('total_harga = jumlah subtotal', (int)$p['total_harga'] === $sum, "db={$p['total_harga']} sum=$sum");
    }

    // 5. Idempotensi: submit ulang dengan idempotency sama -> tidak membuat pesanan ganda.
    $jmlSebelum = (int)db_val($kon, 'SELECT COUNT(*) FROM pesanan WHERE nama_pelanggan = ?', ['[UJI] Pelanggan E2E']);
    [$c4, $j4] = req($base . '/pesan_aksi.php', [
        'aksi' => 'pesan', 'csrf' => $csrf, 'nama' => '[UJI] Pelanggan E2E', 'no_wa' => '081298765432',
        'tanggal_ambil' => $besok, 'jam_ambil' => '11:00', 'catatan' => '', 'items' => json_encode($items),
        'jumlah_box' => 10, 'idempotency' => $idem,
    ], $jar);
    $r4 = json_decode($j4, true);
    $jmlSesudah = (int)db_val($kon, 'SELECT COUNT(*) FROM pesanan WHERE nama_pelanggan = ?', ['[UJI] Pelanggan E2E']);
    cek('Klik ganda tidak membuat pesanan duplikat', $jmlSebelum === $jmlSesudah, "sebelum=$jmlSebelum sesudah=$jmlSesudah");
    cek('Respons duplikat ditandai', !empty($r4['duplikat']) || ($r4['kode'] ?? '') === $kode);

    // 6. Bersihkan data uji (kembalikan DB seperti semula).
    db_exec($kon, 'DELETE FROM pesanan WHERE kode = ?', [$kode]);
    cek('Data uji dibersihkan', db_row($kon, 'SELECT id FROM pesanan WHERE kode = ?', [$kode]) === null);
}

echo "\n" . ($gagal === 0 ? 'SEMUA UJI E2E LULUS' : "ADA $gagal KEGAGALAN") . PHP_EOL;
@unlink($jar);
exit($gagal === 0 ? 0 : 1);
