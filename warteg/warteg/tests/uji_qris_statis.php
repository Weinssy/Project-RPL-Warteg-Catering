<?php
// =====================================================================
// Uji alur QRIS STATIS + verifikasi manual admin.
// Jalankan: php tests/uji_qris_statis.php
//
// Menguji:
//  - checkout menghitung subtotal & total, snapshot nominal
//  - upload bukti (simulasi file) -> status menunggu_verifikasi
//  - admin setuju -> berhasil (LUNAS) + log verifikasi + idempoten
//  - admin tolak -> ditolak + alasan, customer boleh kirim ulang
//  - verifikasi ganda tidak membuat invoice/status ganda
//  - konfigurasi toko (qris_gambar)
// Membersihkan seluruh data uji di akhir.
// =====================================================================
if (PHP_SAPI !== 'cli') exit('Hanya untuk CLI.');
putenv('PAYMENT_PROVIDER=mock');
require_once __DIR__ . '/../includes/bootstrap.php';

$lulus = 0; $gagal = 0;
function cek(string $nama, bool $kondisi, string $info = ''): void {
    global $lulus, $gagal;
    if ($kondisi) { $lulus++; echo "[LULUS] $nama\n"; }
    else { $gagal++; echo "[GAGAL] $nama $info\n"; }
}

$nama_menu = 'ZZ-UJI-QRIS';

function bersihkan(mysqli $kon, string $nama_menu): void {
    $ids = db_all($kon, 'SELECT id FROM pesanan WHERE nama_pelanggan LIKE "[UJI-QRIS]%"');
    foreach ($ids as $r) {
        db_exec($kon, 'DELETE FROM verifikasi_log WHERE pesanan_id = ?', [(int)$r['id']]);
        db_exec($kon, 'DELETE FROM wa_log WHERE pesanan_id = ?', [(int)$r['id']]);
        db_exec($kon, 'DELETE FROM pembayaran_log WHERE pesanan_id = ?', [(int)$r['id']]);
        db_exec($kon, 'DELETE FROM detail_pesanan WHERE pesanan_id = ?', [(int)$r['id']]);
        db_exec($kon, 'DELETE FROM pesanan WHERE id = ?', [(int)$r['id']]);
    }
    db_exec($kon, 'DELETE FROM menu WHERE nama = ?', [$nama_menu]);
}

bersihkan($kon, $nama_menu);

// Siapkan menu uji
$tgl = (new DateTime('today'))->modify('+5 day')->format('Y-m-d');
db_exec($kon, "INSERT INTO menu (nama, kategori, harga) VALUES (?, 'nasi', 15000)", [$nama_menu]);
$mid = (int)$kon->insert_id;
db_exec($kon, 'INSERT INTO menu_harian (menu_id, tanggal, tersedia) VALUES (?, ?, 1)', [$mid, $tgl]);
db_exec($kon, 'INSERT IGNORE INTO kuota_harian (tanggal) VALUES (?)', [$tgl]);

// ---- 1. Checkout: subtotal & total dihitung backend ----
$in = ['nama' => '[UJI-QRIS] Satu', 'no_wa' => '081234567099', 'tanggal_ambil' => $tgl, 'jam_ambil' => '10:00', 'items' => [$mid => 10]];
$h = buat_pesanan($kon, $in, true);
cek('Pesanan dibuat', $h['ok'], json_encode($h));
$pid = (int)($h['id'] ?? 0);
$p = db_row($kon, 'SELECT * FROM pesanan WHERE id = ?', [$pid]);
cek('Snapshot total_harga = 10 x 15000 = 150000', (int)$p['total_harga'] === 150000, json_encode($p));
cek('nominal_bayar = total_harga', (int)$p['nominal_bayar'] === 150000);
cek('status_bayar awal belum_bayar', $p['status_bayar'] === BAYAR_BELUM);

// ---- 2. Verifikasi tanpa bukti ditolak (status belum menunggu_verifikasi) ----
$r = verifikasi_pembayaran($kon, $pid, 'setuju', 1);
cek('Verifikasi ditolak bila belum ada bukti', !$r['ok'], json_encode($r));

// ---- 3. Simulasi upload bukti: ubah status ke menunggu_verifikasi ----
db_exec($kon, 'UPDATE pesanan SET bukti_bayar = ?, bukti_bayar_pada = NOW(), status_bayar = ? WHERE id = ?',
    [str_repeat('a', 32) . '.jpg', BAYAR_MENUNGGU_VERIFIKASI, $pid]);
$p = db_row($kon, 'SELECT * FROM pesanan WHERE id = ?', [$pid]);
cek('Status menjadi menunggu_verifikasi setelah upload bukti', $p['status_bayar'] === BAYAR_MENUNGGU_VERIFIKASI, json_encode($p));

// ---- 4. Admin SETUJU -> LUNAS ----
$r = verifikasi_pembayaran($kon, $pid, 'setuju', 1);
cek('Verifikasi setuju berhasil', $r['ok'] && $r['status'] === BAYAR_BERHASIL, json_encode($r));
$p = db_row($kon, 'SELECT * FROM pesanan WHERE id = ?', [$pid]);
cek('Status menjadi berhasil (LUNAS)', $p['status_bayar'] === BAYAR_BERHASIL, json_encode($p));
cek('diverifikasi_pada terisi', !empty($p['diverifikasi_pada']));
cek('dibayar_pada terisi', !empty($p['dibayar_pada']));
cek('admin_verifikator_id terisi', !empty($p['admin_verifikator_id']));

// ---- 5. Log verifikasi tercatat ----
$n = (int)db_val($kon, 'SELECT COUNT(*) FROM verifikasi_log WHERE pesanan_id = ? AND aksi = ?', [$pid, 'setuju']);
cek('verifikasi_log tercatat 1 baris setuju', $n === 1, (string)$n);

// ---- 6. Verifikasi ulang setelah LUNAS -> ditolak (idempoten, no invoice ganda) ----
$r2 = verifikasi_pembayaran($kon, $pid, 'setuju', 1);
cek('Verifikasi ulang setelah LUNAS ditolak', !$r2['ok'], json_encode($r2));
$n2 = (int)db_val($kon, 'SELECT COUNT(*) FROM verifikasi_log WHERE pesanan_id = ?', [$pid]);
cek('Tidak ada log verifikasi tambahan (tetap 1)', $n2 === 1, (string)$n2);

// ---- 7. Skenario TOLAK: pesanan baru ----
$in2 = ['nama' => '[UJI-QRIS] Dua', 'no_wa' => '081234567098', 'tanggal_ambil' => $tgl, 'jam_ambil' => '10:00', 'items' => [$mid => 10]];
$h2 = buat_pesanan($kon, $in2, true);
$pid2 = (int)($h2['id'] ?? 0);
db_exec($kon, 'UPDATE pesanan SET bukti_bayar = ?, bukti_bayar_pada = NOW(), status_bayar = ? WHERE id = ?',
    [str_repeat('b', 32) . '.jpg', BAYAR_MENUNGGU_VERIFIKASI, $pid2]);
$rt = verifikasi_pembayaran($kon, $pid2, 'tolak', 1, 'Nominal transfer tidak sesuai');
cek('Verifikasi tolak berhasil', $rt['ok'] && $rt['status'] === BAYAR_DITOLAK, json_encode($rt));
$p2 = db_row($kon, 'SELECT * FROM pesanan WHERE id = ?', [$pid2]);
cek('Status ditolak', $p2['status_bayar'] === BAYAR_DITOLAK, json_encode($p2));
cek('alasan_tolak tersimpan', $p2['alasan_tolak'] === 'Nominal transfer tidak sesuai', (string)$p2['alasan_tolak']);

// ---- 8. Customer kirim ulang setelah ditolak -> boleh, status kembali menunggu_verifikasi ----
db_exec($kon, 'UPDATE pesanan SET bukti_bayar = ?, bukti_bayar_pada = NOW(), status_bayar = ?, alasan_tolak = NULL WHERE id = ?',
    [str_repeat('c', 32) . '.jpg', BAYAR_MENUNGGU_VERIFIKASI, $pid2]);
$p2 = db_row($kon, 'SELECT * FROM pesanan WHERE id = ?', [$pid2]);
cek('Setelah kirim ulang status menunggu_verifikasi lagi', $p2['status_bayar'] === BAYAR_MENUNGGU_VERIFIKASI);
$r3 = verifikasi_pembayaran($kon, $pid2, 'setuju', 1);
cek('Bisa disetujui setelah kirim ulang', $r3['ok'] && $r3['status'] === BAYAR_BERHASIL, json_encode($r3));

// ---- 9. Konfigurasi toko QRIS ----
simpan_konfigurasi($kon, 'qris_gambar', 'test_qris.png', 1);
cek('qris_statis_tersedia true setelah set gambar', qris_statis_tersedia($kon));
simpan_konfigurasi($kon, 'qris_gambar', null, 1);
cek('qris_statis_tersedia false setelah dihapus', !qris_statis_tersedia($kon));

// ---- 10. Aksi tidak valid ditolak ----
$rx = verifikasi_pembayaran($kon, $pid, 'hapus', 1);
cek('Aksi verifikasi invalid ditolak', !$rx['ok'], json_encode($rx));

// Bersihkan
bersihkan($kon, $nama_menu);
db_exec($kon, 'DELETE FROM kuota_harian WHERE tanggal = ?', [$tgl]);

echo "\nRingkasan: $lulus lulus, $gagal gagal\n";
exit($gagal === 0 ? 0 : 1);
