<?php
// =====================================================================
// Uji HTTP halaman pembayaran & invoice QRIS STATIS (server Laragon :80).
// Jalankan: php tests/uji_http_qris.php
// Memakai BASE dari argumen atau default http://localhost/warteg/warteg
//
// Yang diuji:
//  - Halaman pembayaran menampilkan QRIS statis + instruksi nominal manual
//  - Form unggah bukti ada
//  - Akses invoice TANPA token -> ditolak
//  - Akses invoice DENGAN token -> 200
//  - Invoice LUNAS menampilkan label LUNAS hanya bila status berhasil
// Membersihkan data uji.
// =====================================================================
require_once __DIR__ . '/../includes/bootstrap.php';

$BASE = $argv[1] ?? 'http://localhost/warteg/warteg';
$cookie = tempnam(sys_get_temp_dir(), 'wrtqris');

function req(string $url, string $cookie, ?array $post = null): array {
    $ch = curl_init($url);
    curl_setopt_array($ch, [CURLOPT_RETURNTRANSFER => true, CURLOPT_COOKIEJAR => $cookie, CURLOPT_COOKIEFILE => $cookie, CURLOPT_TIMEOUT => 15, CURLOPT_FOLLOWLOCATION => true]);
    if ($post !== null) {
        curl_setopt($ch, CURLOPT_POST, true);
        curl_setopt($ch, CURLOPT_POSTFIELDS, http_build_query($post));
    }
    $body = curl_exec($ch); $code = curl_getinfo($ch, CURLINFO_HTTP_CODE); curl_close($ch);
    return ['code' => $code, 'body' => (string)$body];
}
$lulus = 0; $gagal = 0;
function cek(string $n, bool $k, string $i = ''): void { global $lulus, $gagal; if ($k) { $lulus++; echo "[LULUS] $n\n"; } else { $gagal++; echo "[GAGAL] $n $i\n"; } }

$nama_menu = 'ZZ-UJI-HTTP-QRIS';
// Bersihkan lama
foreach (db_all($kon, 'SELECT id FROM pesanan WHERE nama_pelanggan LIKE "[UJI-HTTP-QRIS]%"') as $r) {
    db_exec($kon, 'DELETE FROM verifikasi_log WHERE pesanan_id = ?', [(int)$r['id']]);
    db_exec($kon, 'DELETE FROM wa_log WHERE pesanan_id = ?', [(int)$r['id']]);
    db_exec($kon, 'DELETE FROM pembayaran_log WHERE pesanan_id = ?', [(int)$r['id']]);
    db_exec($kon, 'DELETE FROM detail_pesanan WHERE pesanan_id = ?', [(int)$r['id']]);
    db_exec($kon, 'DELETE FROM pesanan WHERE id = ?', [(int)$r['id']]);
}
db_exec($kon, 'DELETE FROM menu WHERE nama = ?', [$nama_menu]);

// Siapkan menu + set QRIS statis aktif (gambar dummy)
$tgl = (new DateTime('today'))->modify('+5 day')->format('Y-m-d');
db_exec($kon, "INSERT INTO menu (nama, kategori, harga) VALUES (?, 'nasi', 14000)", [$nama_menu]);
$mid = (int)$kon->insert_id;
db_exec($kon, 'INSERT INTO menu_harian (menu_id, tanggal, tersedia) VALUES (?, ?, 1)', [$mid, $tgl]);
db_exec($kon, 'INSERT IGNORE INTO kuota_harian (tanggal) VALUES (?)', [$tgl]);
simpan_konfigurasi($kon, 'qris_gambar', 'uji_dummy.png', 1);

// Buat pesanan langsung lewat service (backend)
$h = buat_pesanan($kon, ['nama' => '[UJI-HTTP-QRIS] Pemesan', 'no_wa' => '081234560099', 'tanggal_ambil' => $tgl, 'jam_ambil' => '10:00', 'items' => [$mid => 10]], true);
cek('Pesanan uji dibuat', !empty($h['ok']), json_encode($h));
$kode = $h['kode']; $token = $h['token_nota']; $pid = (int)$h['id'];

// 1) Halaman pembayaran menampilkan QRIS statis + instruksi manual
$bayar = req("$BASE/pembayaran.php?kode=" . urlencode($kode) . "&t=" . urlencode($token), $cookie);
cek('Halaman pembayaran 200', $bayar['code'] === 200, (string)$bayar['code']);
cek('Menampilkan QRIS statis (alt QRIS Statis)', strpos($bayar['body'], 'QRIS Statis') !== false || strpos($bayar['body'], 'qris statis') !== false);
cek('Menampilkan instruksi nominal manual', strpos($bayar['body'], 'manual') !== false || strpos($bayar['body'], 'MANUAL') !== false);
cek('Ada form unggah bukti', strpos($bayar['body'], 'bukti_bayar') !== false && strpos($bayar['body'], 'aksi_bukti') !== false);
cek('Menampilkan total tagihan', strpos($bayar['body'], '140.000') !== false || strpos($bayar['body'], 'Total Tagihan') !== false);

// 2) Halaman pembayaran tanpa token valid -> ditolak
$bayarSalah = req("$BASE/pembayaran.php?kode=" . urlencode($kode) . "&t=salah", $cookie);
cek('Pembayaran token salah -> pesan tidak valid', strpos($bayarSalah['body'], 'Tautan tidak valid') !== false);

// 3) Invoice TANPA token -> ditolak
$invNo = req("$BASE/invoice.php?kode=" . urlencode($kode), $cookie);
cek('Invoice tanpa token -> ditolak', strpos($invNo['body'], 'Tautan invoice tidak valid') !== false, substr($invNo['body'], 0, 200));

// 4) Invoice DENGAN token -> 200 (bukan LUNAS karena belum bayar)
$inv = req("$BASE/invoice.php?kode=" . urlencode($kode) . "&t=" . urlencode($token), $cookie);
cek('Invoice dengan token 200', $inv['code'] === 200, (string)$inv['code']);
cek('Invoice menampilkan nomor', strpos($inv['body'], 'INV/' . $kode) !== false);
cek('Invoice BELUM LUNAS menampilkan status belum lunas', strpos($inv['body'], 'BELUM LUNAS') !== false);
cek('Invoice belum tampil label LUNAS hijau', strpos($inv['body'], 'Invoice ini sudah LUNAS') === false);

// 5) Verifikasi admin -> LUNAS, lalu invoice menampilkan LUNAS
db_exec($kon, 'UPDATE pesanan SET bukti_bayar = ?, bukti_bayar_pada = NOW(), status_bayar = ? WHERE id = ?', [str_repeat('d', 32) . '.jpg', BAYAR_MENUNGGU_VERIFIKASI, $pid]);
$rv = verifikasi_pembayaran($kon, $pid, 'setuju', 1);
cek('Pembayaran disetujui -> LUNAS', $rv['ok'] && $rv['status'] === BAYAR_BERHASIL, json_encode($rv));
$inv2 = req("$BASE/invoice.php?kode=" . urlencode($kode) . "&t=" . urlencode($token), $cookie);
cek('Invoice setelah LUNAS menampilkan "LUNAS"', strpos($inv2['body'], 'Invoice ini sudah LUNAS') !== false);

// Bersihkan
foreach (db_all($kon, 'SELECT id FROM pesanan WHERE nama_pelanggan LIKE "[UJI-HTTP-QRIS]%"') as $r) {
    db_exec($kon, 'DELETE FROM verifikasi_log WHERE pesanan_id = ?', [(int)$r['id']]);
    db_exec($kon, 'DELETE FROM wa_log WHERE pesanan_id = ?', [(int)$r['id']]);
    db_exec($kon, 'DELETE FROM pembayaran_log WHERE pesanan_id = ?', [(int)$r['id']]);
    db_exec($kon, 'DELETE FROM detail_pesanan WHERE pesanan_id = ?', [(int)$r['id']]);
    db_exec($kon, 'DELETE FROM pesanan WHERE id = ?', [(int)$r['id']]);
}
db_exec($kon, 'DELETE FROM menu WHERE nama = ?', [$nama_menu]);
db_exec($kon, 'DELETE FROM kuota_harian WHERE tanggal = ?', [$tgl]);
simpan_konfigurasi($kon, 'qris_gambar', null, 1);

unlink($cookie);
echo "\nRingkasan HTTP QRIS: $lulus lulus, $gagal gagal\n";
exit($gagal === 0 ? 0 : 1);
