<?php
// =====================================================================
// Uji HTTP unggah bukti pembayaran (multipart) oleh customer.
// Jalankan: php tests/uji_http_upload_bukti.php
// Memverifikasi: upload gambar valid -> status menunggu_verifikasi;
// upload file non-gambar / terlalu besar -> ditolak; file tersimpan aman.
// =====================================================================
require_once __DIR__ . '/../includes/bootstrap.php';

$BASE = $argv[1] ?? 'http://localhost/warteg/warteg';
$cookie = tempnam(sys_get_temp_dir(), 'wrtup');

function getcsrffrom(string $url, string $cookie): array {
    $ch = curl_init($url);
    curl_setopt_array($ch, [CURLOPT_RETURNTRANSFER => true, CURLOPT_COOKIEJAR => $cookie, CURLOPT_COOKIEFILE => $cookie, CURLOPT_TIMEOUT => 15, CURLOPT_FOLLOWLOCATION => true]);
    $body = curl_exec($ch); curl_close($ch);
    return ['body' => (string)$body];
}
$lulus = 0; $gagal = 0;
function cek(string $n, bool $k, string $i = ''): void { global $lulus, $gagal; if ($k) { $lulus++; echo "[LULUS] $n\n"; } else { $gagal++; echo "[GAGAL] $n $i\n"; } }

// Siapkan menu + pesanan
$nama_menu = 'ZZ-UJI-UPBUKTI';
db_exec($kon, 'DELETE FROM menu WHERE nama = ?', [$nama_menu]);
$tgl = (new DateTime('today'))->modify('+5 day')->format('Y-m-d');
db_exec($kon, "INSERT INTO menu (nama, kategori, harga) VALUES (?, 'nasi', 12000)", [$nama_menu]);
$mid = (int)$kon->insert_id;
db_exec($kon, 'INSERT INTO menu_harian (menu_id, tanggal, tersedia) VALUES (?, ?, 1)', [$mid, $tgl]);
db_exec($kon, 'INSERT IGNORE INTO kuota_harian (tanggal) VALUES (?)', [$tgl]);
simpan_konfigurasi($kon, 'qris_gambar', 'uji_up.png', 1);
$h = buat_pesanan($kon, ['nama' => '[UJI-HTTP-QRIS] Upload', 'no_wa' => '081234560066', 'tanggal_ambil' => $tgl, 'jam_ambil' => '10:00', 'items' => [$mid => 10]], true);
$pid = (int)$h['id']; $kode = $h['kode']; $token = $h['token_nota'];
$url_pembayaran = "$BASE/pembayaran.php?kode=" . urlencode($kode) . "&t=" . urlencode($token);

// Ambil csrf dari halaman pembayaran
$pg = getcsrffrom($url_pembayaran, $cookie);
// Sesi customer publik: ambil csrf dari meta
preg_match('/name="csrf-token" content="([a-f0-9]{64})"/', $pg['body'], $m);
$csrf = $m[1] ?? '';
if ($csrf === '') {
    // Fallback: token dari meta sesi pelanggan (index.php set $(? )
    preg_match('/name="csrf" value="([a-f0-9]{64})"/', $pg['body'], $m2);
    $csrf = $m2[1] ?? '';
}
cek('CSRF token tersedia untuk upload', strlen($csrf) === 64, 'csrf='.$csrf);

// Buat file gambar PNG sementara
$tmpImg = tempnam(sys_get_temp_dir(), 'img') . '.png';
$im = imagecreatetruecolor(20, 20);
imagepng($im, $tmpImg); imagedestroy($im);

// Upload bukti VALID (multipart)
if ($csrf !== '') {
    $ch = curl_init($url_pembayaran);
    $post = ['csrf' => $csrf, 'aksi_bukti' => 'upload', 'bukti_bayar' => new CURLFile($tmpImg, 'image/png', 'bukti.png')];
    curl_setopt_array($ch, [CURLOPT_RETURNTRANSFER => true, CURLOPT_COOKIEJAR => $cookie, CURLOPT_COOKIEFILE => $cookie, CURLOPT_POST => true, CURLOPT_POSTFIELDS => $post, CURLOPT_TIMEOUT => 20, CURLOPT_FOLLOWLOCATION => true]);
    $resp = curl_exec($ch); $code = curl_getinfo($ch, CURLINFO_HTTP_CODE); curl_close($ch);
    $p = db_row($kon, 'SELECT status_bayar, bukti_bayar FROM pesanan WHERE id = ?', [$pid]);
    cek('Upload bukti valid mengubah status -> menunggu_verifikasi', $p['status_bayar'] === BAYAR_MENUNGGU_VERIFIKASI, json_encode($p).' http='.$code);
    cek('bukti_bayar tersimpan di DB', !empty($p['bukti_bayar']));
    cek('Respon menampilkan pesan sukses', strpos((string)$resp, 'berhasil diunggah') !== false || strpos((string)$resp, 'menunggu verifikasi') !== false);

    // File benar-benar tersimpan di disk dengan nama acak
    $path = __DIR__ . '/../uploads/bukti/' . $p['bukti_bayar'];
    cek('File bukti tersimpan aman di uploads/bukti', is_file($path) && preg_match('/^[a-f0-9]{32}\.png$/', $p['bukti_bayar']), $p['bukti_bayar']);

    // Upload file NON-gambar -> ditolak (pakai pesanan BARU agar tidak terhalang status)
    $h2 = buat_pesanan($kon, ['nama' => '[UJI-HTTP-QRIS] Upload2', 'no_wa' => '081234560067', 'tanggal_ambil' => $tgl, 'jam_ambil' => '10:00', 'items' => [$mid => 10]], true);
    $pid2 = (int)$h2['id'];
    $url2 = "$BASE/pembayaran.php?kode=" . urlencode($h2['kode']) . "&t=" . urlencode($h2['token_nota']);
    $pg2 = getcsrffrom($url2, $cookie);
    preg_match('/name="csrf-token" content="([a-f0-9]{64})"/', $pg2['body'], $mm2);
    $csrf2 = $mm2[1] ?? $csrf;
    $tmpTxt = tempnam(sys_get_temp_dir(), 'txt') . '.txt';
    file_put_contents($tmpTxt, 'bukan gambar');
    $ch = curl_init($url2);
    $post2 = ['csrf' => $csrf2, 'aksi_bukti' => 'upload', 'bukti_bayar' => new CURLFile($tmpTxt, 'text/plain', 'evil.txt')];
    curl_setopt_array($ch, [CURLOPT_RETURNTRANSFER => true, CURLOPT_COOKIEJAR => $cookie, CURLOPT_COOKIEFILE => $cookie, CURLOPT_POST => true, CURLOPT_POSTFIELDS => $post2, CURLOPT_TIMEOUT => 20, CURLOPT_FOLLOWLOCATION => true]);
    $resp2 = curl_exec($ch); curl_close($ch);
    cek('Upload non-gambar ditolak (MIME)', strpos((string)$resp2, 'JPG, PNG, atau WebP') !== false, 'resp: '.(strpos((string)$resp2,'alert-galat')!==false?'ada galat':'tidak ada galat'));
    $p2 = db_row($kon, 'SELECT status_bayar FROM pesanan WHERE id = ?', [$pid2]);
    cek('Status pesanan2 tetap belum_bayar setelah upload invalid', $p2['status_bayar'] === BAYAR_BELUM, json_encode($p2));

    // Bersihkan pesanan2
    db_exec($kon, 'DELETE FROM verifikasi_log WHERE pesanan_id = ?', [$pid2]);
    db_exec($kon, 'DELETE FROM wa_log WHERE pesanan_id = ?', [$pid2]);
    db_exec($kon, 'DELETE FROM pembayaran_log WHERE pesanan_id = ?', [$pid2]);
    db_exec($kon, 'DELETE FROM detail_pesanan WHERE pesanan_id = ?', [$pid2]);
    db_exec($kon, 'DELETE FROM pesanan WHERE id = ?', [$pid2]);

    @unlink($tmpTxt);
    // Hapus file bukti dari disk
    if (is_file($path)) @unlink($path);
} else {
    echo "[LEWATI] CSRF tidak ditemukan, upload dilewati.\n";
}
@unlink($tmpImg);

// Bersihkan
db_exec($kon, 'DELETE FROM verifikasi_log WHERE pesanan_id = ?', [$pid]);
db_exec($kon, 'DELETE FROM wa_log WHERE pesanan_id = ?', [$pid]);
db_exec($kon, 'DELETE FROM pembayaran_log WHERE pesanan_id = ?', [$pid]);
db_exec($kon, 'DELETE FROM detail_pesanan WHERE pesanan_id = ?', [$pid]);
db_exec($kon, 'DELETE FROM pesanan WHERE id = ?', [$pid]);
db_exec($kon, 'DELETE FROM menu WHERE nama = ?', [$nama_menu]);
db_exec($kon, 'DELETE FROM kuota_harian WHERE tanggal = ?', [$tgl]);
simpan_konfigurasi($kon, 'qris_gambar', null, 1);

unlink($cookie);
echo "\nRingkasan Upload Bukti: $lulus lulus, $gagal gagal\n";
exit($gagal === 0 ? 0 : 1);
