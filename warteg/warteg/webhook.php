<?php
// =====================================================================
// Webhook / callback pembayaran dari payment gateway.
// SATU-SATUNYA jalur yang boleh menandai pembayaran sebagai BERHASIL.
//
// Prinsip keamanan:
//   * Verifikasi tanda tangan / token callback sesuai penyedia (JANGAN percaya body mentah).
//   * Verifikasi nominal tepat sama dengan tagihan tersimpan.
//   * Idempoten: fingerprint unik mencegah transaksi yang sama diproses dua kali.
//   * Tidak pernah mengeluarkan output yang membantu penyerang memetakan data pesanan.
// =====================================================================
require_once __DIR__ . '/includes/bootstrap.php';

// Hanya menerima POST.
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    exit('Method Not Allowed');
}

$raw = file_get_contents('php://input');
if ($raw === false || $raw === '') $raw = json_encode($_POST);
$body = json_decode((string)$raw, true);
if (!is_array($body)) $body = $_POST;

$ip = $_SERVER['REMOTE_ADDR'] ?? null;

// ---------- Midtrans (HTTP notification) ----------
// Signature key = SHA512(order_id + status_code + gross_amount + ServerKey)
function proses_midtrans(mysqli $kon, array $b, ?string $ip): array {
    $order_id     = (string)($b['order_id'] ?? '');
    $status_code  = (string)($b['status_code'] ?? '');
    $gross_amount = (string)($b['gross_amount'] ?? '0');
    $signature    = (string)($b['signature_key'] ?? '');
    $trans_status = (string)($b['transaction_status'] ?? (
        isset($b['status']) ? (string)$b['status'] : ''
    ));
    $fraud        = (string)($b['fraud_status'] ?? '');
    $trx_id       = (string)($b['transaction_id'] ?? '');

    if (MIDTRANS_SERVER_KEY === '' || $signature === '') {
        return ['ok' => false, 'pesan' => 'Signature tidak tersedia.'];
    }
    $expected = hash('sha512', $order_id . $status_code . $gross_amount . MIDTRANS_SERVER_KEY);
    if (!hash_equals($expected, $signature)) {
        return ['ok' => false, 'pesan' => 'Signature tidak valid.'];
    }

    // Status internal.
    if ($trans_status === 'capture' && $fraud === 'challenge') {
        $internal = BAYAR_MENUNGGU;
    } else {
        $internal = petakan_status_gateway($trans_status);
    }

    $nominal = (int)round((float)$gross_amount);
    $fingerprint = hash('sha256', implode('|', ['midtrans', $order_id, $trx_id, $trans_status, $gross_amount]));

    return terapkan_callback_pembayaran($kon, [
        'kode_pesanan' => $order_id,
        'provider' => 'midtrans',
        'event' => 'notification',
        'status_masuk' => $trans_status,
        'status_baru' => $internal,
        'kode_bayar' => $trx_id !== '' ? $trx_id : null,
        'nominal' => $nominal,
        'ip' => $ip,
        'fingerprint' => $fingerprint,
    ]);
}

// ---------- Xendit (QR Code callback) ----------
// Verifikasi dengan callback token di header 'x-callback-token'.
function proses_xendit(mysqli $kon, array $b, ?string $ip): array {
    $token = (string)($_SERVER['HTTP_X_CALLBACK_TOKEN'] ?? '');
    if (XENDIT_CALLBACK_TOKEN === '' || $token === '' || !hash_equals(XENDIT_CALLBACK_TOKEN, $token)) {
        return ['ok' => false, 'pesan' => 'Callback token tidak valid.'];
    }
    // Xendit mengirim: event, data.{reference_id, id, status, amount}
    $event = (string)($b['event'] ?? ($b['status'] ?? ''));
    $data  = is_array($b['data'] ?? null) ? $b['data'] : $b;
    $reference_id = (string)($data['reference_id'] ?? ($data['order_id'] ?? ''));
    $trx_id = (string)($data['id'] ?? '');
    $status = (string)($data['status'] ?? $event);
    $amount = (int)($data['amount'] ?? ($data['total_amount'] ?? 0));

    $internal = petakan_status_gateway($status);
    $fingerprint = hash('sha256', implode('|', ['xendit', $reference_id, $trx_id, $status, (string)$amount]));

    return terapkan_callback_pembayaran($kon, [
        'kode_pesanan' => $reference_id,
        'provider' => 'xendit',
        'event' => $event,
        'status_masuk' => $status,
        'status_baru' => $internal,
        'kode_bayar' => $trx_id !== '' ? $trx_id : null,
        'nominal' => $amount,
        'ip' => $ip,
        'fingerprint' => $fingerprint,
    ]);
}

// ---------- Mock (uji lokal) ----------
// Hanya aktif bila PAYMENT_PROVIDER=mock. Membutuhkan token uji di header/body.
function proses_mock(mysqli $kon, array $b, ?string $ip): array {
    if (PAYMENT_PROVIDER !== 'mock') return ['ok' => false, 'pesan' => 'Mock tidak aktif.'];
    // Token uji: gunakan CSRF-token server? Tidak ada sesi. Gunakan secret dari env opsional.
    $secret = getenv('MOCK_WEBHOOK_SECRET') ?: '';
    $kirim  = (string)($_SERVER['HTTP_X_MOCK_SECRET'] ?? ($b['secret'] ?? ''));
    if ($secret !== '' && !hash_equals($secret, $kirim)) return ['ok' => false, 'pesan' => 'Secret mock tidak valid.'];

    $kode    = (string)($b['kode_pesanan'] ?? '');
    $status  = (string)($b['status'] ?? 'settlement');
    $nominal = (int)($b['nominal'] ?? 0);
    $internal = petakan_status_gateway($status);
    $fingerprint = hash('sha256', implode('|', ['mock', $kode, $status, (string)$nominal, (string)$kirim]));
    return terapkan_callback_pembayaran($kon, [
        'kode_pesanan' => $kode, 'provider' => 'mock', 'event' => 'test',
        'status_masuk' => $status, 'status_baru' => $internal,
        'kode_bayar' => 'MOCK-' . $kode, 'nominal' => $nominal, 'ip' => $ip, 'fingerprint' => $fingerprint,
    ]);
}

// ---------- Router berdasarkan penyedia ----------
$hasil = ['ok' => false, 'pesan' => 'Penyedia pembayaran tidak dikenali.'];
try {
    switch (PAYMENT_PROVIDER) {
        case 'midtrans': $hasil = proses_midtrans($kon, $body, $ip); break;
        case 'xendit':   $hasil = proses_xendit($kon, $body, $ip); break;
        case 'mock':     $hasil = proses_mock($kon, $body, $ip); break;
        default:
            // Bila belum dikonfigurasi, tetap balas 501 agar gateway tidak retry tanpa henti.
            http_response_code(501);
            header('Content-Type: application/json; charset=utf-8');
            echo json_encode(['ok' => false, 'pesan' => 'Pembayaran belum dikonfigurasi.']);
            exit;
    }
} catch (Throwable $e) {
    error_log('webhook error: ' . $e->getMessage());
    $hasil = ['ok' => false, 'pesan' => 'Terjadi kesalahan server.'];
}

// Balas ringkas. Untuk sukses/idempoten -> 200. Untuk validasi -> 4xx.
$kode_http = $hasil['ok'] ? 200 : 400;
http_response_code($kode_http);
header('Content-Type: application/json; charset=utf-8');
echo json_encode(['ok' => (bool)$hasil['ok'], 'pesan' => $hasil['pesan'] ?? ''], JSON_UNESCAPED_UNICODE);
