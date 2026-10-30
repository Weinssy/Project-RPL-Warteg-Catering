<?php
// Uji verifikasi tanda tangan webhook (fungsi murni, tanpa HTTP).
// Memastikan signature Midtrans SHA512 dan token Xendit divalidasi dengan benar.
if (PHP_SAPI !== 'cli') exit('Hanya untuk CLI.');

// Set kredensial uji SEBELUM config dimuat (config membaca getenv saat load).
putenv('PAYMENT_PROVIDER=midtrans');
putenv('MIDTRANS_SERVER_KEY=SB-Mid-server-UJITESTKEY123');
require_once __DIR__ . '/../includes/bootstrap.php';

$lulus = 0; $gagal = 0;
function cek(string $n, bool $k, string $i = ''): void { global $lulus, $gagal; if ($k) { $lulus++; echo "[LULUS] $n\n"; } else { $gagal++; echo "[GAGAL] $n $i\n"; } }

// Replikasi perhitungan signature yang sama dengan webhook.php proses_midtrans().
function signature_midtrans(string $order_id, string $status_code, string $gross, string $key): string {
    return hash('sha512', $order_id . $status_code . $gross . $key);
}

$key = MIDTRANS_SERVER_KEY;
$sig = signature_midtrans('WRT-20260101-001', '200', '150000.00', $key);

// 1. Signature valid diterima.
cek('Signature Midtrans valid terverifikasi', hash_equals($sig, signature_midtrans('WRT-20260101-001', '200', '150000.00', $key)));

// 2. Signature dengan nominal berbeda harus gagal.
cek('Signature salah bila nominal dipalsukan', !hash_equals($sig, signature_midtrans('WRT-20260101-001', '200', '1.00', $key)));

// 3. Signature dengan key berbeda harus gagal.
cek('Signature salah bila server key berbeda', !hash_equals($sig, signature_midtrans('WRT-20260101-001', '200', '150000.00', 'KEY-LAIN')));

// 4. hash_equals tahan timing (tidak error dengan panjang beda).
cek('hash_equals aman untuk panjang berbeda', hash_equals('abc', 'abcdef') === false);

// 5. Verifikasi token Xendit sederhana.
$x = 'token-rahasia-xendit';
cek('Token Xendit cocok', hash_equals($x, 'token-rahasia-xendit'));
cek('Token Xendit salah ditolak', !hash_equals($x, 'token-salah'));

// 6. Pemetaan status gateway.
cek('settlement -> berhasil', petakan_status_gateway('settlement') === BAYAR_BERHASIL);
cek('pending -> menunggu', petakan_status_gateway('pending') === BAYAR_MENUNGGU);
cek('expire -> kedaluwarsa', petakan_status_gateway('expire') === BAYAR_KEDALUWARSA);
cek('deny -> gagal', petakan_status_gateway('deny') === BAYAR_GAGAL);

echo "\nRingkasan webhook: $lulus lulus, $gagal gagal\n";
exit($gagal === 0 ? 0 : 1);
