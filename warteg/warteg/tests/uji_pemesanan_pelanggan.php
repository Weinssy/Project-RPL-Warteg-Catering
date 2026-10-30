<?php
// Uji alur pemesanan pelanggan + pembayaran + webhook (mode mock).
// Jalankan: php tests/uji_pemesanan_pelanggan.php
// Memakai tanggal uji di masa depan yang dihitung dari aturan (H-1..MAKS_HARI_KE) dan
// membersihkan seluruh data uji di akhir.
if (PHP_SAPI !== 'cli') exit('Hanya untuk CLI.');

// Paksa provider mock untuk pengujian (sebelum bootstrap memuat config).
putenv('PAYMENT_PROVIDER=mock');
require_once __DIR__ . '/../includes/bootstrap.php';

$lulus = 0; $gagal = 0;
function cek(string $nama, bool $kondisi, string $info = ''): void {
    global $lulus, $gagal;
    if ($kondisi) { $lulus++; echo "[LULUS] $nama\n"; }
    else { $gagal++; echo "[GAGAL] $nama $info\n"; }
}
function bersihkan_uji(mysqli $kon, string $nama_menu): void {
    // Hapus detail & pesanan yang memakai menu uji, lalu log terkait, lalu menu.
    $ids = db_all($kon, 'SELECT id FROM pesanan WHERE nama_pelanggan LIKE "[UJI]%"');
    foreach ($ids as $r) {
        db_exec($kon, 'DELETE FROM wa_log WHERE pesanan_id = ?', [(int)$r['id']]);
        db_exec($kon, 'DELETE FROM pembayaran_log WHERE pesanan_id = ?', [(int)$r['id']]);
        db_exec($kon, 'DELETE FROM detail_pesanan WHERE pesanan_id = ?', [(int)$r['id']]);
        db_exec($kon, 'DELETE FROM pesanan WHERE id = ?', [(int)$r['id']]);
    }
    db_exec($kon, 'DELETE FROM menu WHERE nama = ?', [$nama_menu]);
}

$nama_menu = 'ZZ-UJI-CUST';
bersihkan_uji($kon, $nama_menu);

// Tanggal uji: 5 hari ke depan (valid untuk aturan H-1..30).
$tgl = (new DateTime('today'))->modify('+5 day')->format('Y-m-d');
db_exec($kon, "INSERT INTO menu (nama, kategori, harga) VALUES (?, 'nasi', 15000)", [$nama_menu]);
$mid = (int)$kon->insert_id;
db_exec($kon, 'INSERT INTO menu_harian (menu_id, tanggal, tersedia) VALUES (?, ?, 1)', [$mid, $tgl]);
db_exec($kon, 'INSERT IGNORE INTO kuota_harian (tanggal) VALUES (?)', [$tgl]);

// ---- 1. Aturan pelanggan: H-1 ----
$in = ['nama' => '[UJI] Satu', 'no_wa' => '081234567001', 'tanggal_ambil' => date('Y-m-d'), 'jam_ambil' => '10:00', 'items' => [$mid => 10]];
$h = buat_pesanan($kon, $in, true);
cek('Tanggal hari ini ditolak (H-1)', !$h['ok'] && ($h['field'] ?? '') === 'tanggal_ambil', json_encode($h));

// ---- 2. Jam operasional ----
$in['tanggal_ambil'] = $tgl; $in['jam_ambil'] = '07:00';
$h = buat_pesanan($kon, $in, true);
cek('Jam 07:00 ditolak (di luar 09-19)', !$h['ok'] && ($h['field'] ?? '') === 'jam_ambil', json_encode($h));

// ---- 3. Pesanan valid + token ----
$in['jam_ambil'] = '10:00';
$h = buat_pesanan($kon, $in, true);
cek('Pesanan valid berhasil dibuat', $h['ok'], json_encode($h));
cek('Token nota dihasilkan (40 hex)', isset($h['token_nota']) && strlen($h['token_nota']) === 40);
$kode = $h['kode'] ?? ''; $pid = (int)($h['id'] ?? 0);
$p = db_row($kon, 'SELECT * FROM pesanan WHERE id = ?', [$pid]);
cek('status_bayar awal = belum_bayar', ($p['status_bayar'] ?? '') === BAYAR_BELUM, json_encode($p));
cek('nominal_bayar = total_harga', (int)$p['nominal_bayar'] === (int)$p['total_harga']);

// ---- 4. Aturan ketat TIDAK berlaku saat $ketat=false (kompatibilitas) ----
// Aktifkan menu uji untuk hari ini agar mode longgar bisa memesan (jam bebas).
db_exec($kon, 'INSERT IGNORE INTO menu_harian (menu_id, tanggal, tersedia) VALUES (?, ?, 1)', [$mid, date('Y-m-d')]);
db_exec($kon, 'INSERT IGNORE INTO kuota_harian (tanggal) VALUES (?)', [date('Y-m-d')]);
$longgar = buat_pesanan($kon, ['nama' => '[UJI] Longgar', 'no_wa' => '081234567002', 'tanggal_ambil' => date('Y-m-d'), 'jam_ambil' => '07:00', 'items' => [$mid => 10]], false);
cek('Mode longgar tetap menerima tanggal/jam lama', $longgar['ok'], json_encode($longgar));

// ---- 5. Buat transaksi QRIS (mock) ----
$p = db_row($kon, 'SELECT * FROM pesanan WHERE id = ?', [$pid]);
$bayar = buat_transaksi_qris($kon, $p);
cek('Transaksi QRIS mock dibuat', $bayar['ok'] && $bayar['terkonfigurasi'], json_encode($bayar));
$p = db_row($kon, 'SELECT * FROM pesanan WHERE id = ?', [$pid]);
cek('status_bayar jadi menunggu setelah buat QR', $p['status_bayar'] === BAYAR_MENUNGGU, json_encode($p));
cek('qr_payload tersimpan', !empty($p['qr_payload']));

// ---- 6. Webhook BERHASIL (mock) - menerapkan terapkan_callback_pembayaran ----
$fp = hash('sha256', implode('|', ['mock', $kode, 'settlement', (string)$p['nominal_bayar'], 'x']));
$cb = terapkan_callback_pembayaran($kon, [
    'kode_pesanan' => $kode, 'provider' => 'mock', 'event' => 'test', 'status_masuk' => 'settlement',
    'status_baru' => BAYAR_BERHASIL, 'kode_bayar' => 'MOCK-' . $kode, 'nominal' => (int)$p['nominal_bayar'],
    'ip' => '127.0.0.1', 'fingerprint' => $fp,
]);
cek('Callback BERHASIL diproses', $cb['ok'] && ($cb['baru'] ?? false), json_encode($cb));
$p = db_row($kon, 'SELECT * FROM pesanan WHERE id = ?', [$pid]);
cek('status_bayar jadi berhasil', $p['status_bayar'] === BAYAR_BERHASIL, json_encode($p));
cek('dibayar_pada terisi', !empty($p['dibayar_pada']));

// ---- 7. Idempoten: callback sama diproses ulang ----
$cb2 = terapkan_callback_pembayaran($kon, [
    'kode_pesanan' => $kode, 'provider' => 'mock', 'event' => 'test', 'status_masuk' => 'settlement',
    'status_baru' => BAYAR_BERHASIL, 'kode_bayar' => 'MOCK-' . $kode, 'nominal' => (int)$p['nominal_bayar'],
    'ip' => '127.0.0.1', 'fingerprint' => $fp,
]);
cek('Callback duplikat idempoten (baru=false)', $cb2['ok'] && $cb2['baru'] === false, json_encode($cb2));
$n_log = (int)db_val($kon, 'SELECT COUNT(*) FROM pembayaran_log WHERE kode_pesanan = ?', [$kode]);
cek('pembayaran_log hanya 1 baris untuk fingerprint sama', $n_log === 1, (string)$n_log);

// ---- 8. Nominal tidak cocok ditolak (anti manipulasi) ----
$fp3 = hash('sha256', 'nominal-salah');
$cb3 = terapkan_callback_pembayaran($kon, [
    'kode_pesanan' => $kode, 'provider' => 'mock', 'event' => 'test', 'status_masuk' => 'settlement',
    'status_baru' => BAYAR_BERHASIL, 'kode_bayar' => 'MOCK-' . $kode, 'nominal' => (int)$p['nominal_bayar'] + 1,
    'ip' => '127.0.0.1', 'fingerprint' => $fp3,
]);
cek('Nominal tidak sesuai ditolak', !$cb3['ok'], json_encode($cb3));

// ---- 9. Status berhasil tidak diturunkan oleh 'expire' ----
$fp4 = hash('sha256', 'expire-after-paid');
$cb4 = terapkan_callback_pembayaran($kon, [
    'kode_pesanan' => $kode, 'provider' => 'mock', 'event' => 'test', 'status_masuk' => 'expire',
    'status_baru' => BAYAR_KEDALUWARSA, 'kode_bayar' => 'MOCK-' . $kode, 'nominal' => (int)$p['nominal_bayar'],
    'ip' => '127.0.0.1', 'fingerprint' => $fp4,
]);
$p = db_row($kon, 'SELECT * FROM pesanan WHERE id = ?', [$pid]);
cek('Status berhasil tidak diturunkan menjadi kedaluwarsa', $p['status_bayar'] === BAYAR_BERHASIL, json_encode($p));

// ---- 10. Tautan nota memakai token (token salah tidak boleh cocok) ----
cek('Token nota acak & tidak sama dengan kode', $p['token_nota'] !== $kode && ctype_xdigit((string)$p['token_nota']));

// ---- 11. WA log tercatat (mock tidak terkonfigurasi -> menunggu) ----
$wa_count = (int)db_val($kon, 'SELECT COUNT(*) FROM wa_log WHERE pesanan_id = ?', [$pid]);
cek('wa_log tercatat untuk pesanan berhasil', $wa_count >= 1, (string)$wa_count);

// Bersihkan
bersihkan_uji($kon, $nama_menu);
db_exec($kon, 'DELETE FROM kuota_harian WHERE tanggal = ?', [$tgl]);

echo "\nRingkasan: $lulus lulus, $gagal gagal\n";
exit($gagal === 0 ? 0 : 1);
