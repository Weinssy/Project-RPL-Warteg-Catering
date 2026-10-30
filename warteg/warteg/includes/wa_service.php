<?php
// =====================================================================
// Notifikasi WhatsApp otomatis ke admin (WhatsApp Business Cloud API).
//
// Prinsip:
//   * Pengiriman otomatis HANYA dari server (bukan tautan wa.me pelanggan).
//   * Dipicu HANYA setelah pembayaran terverifikasi (lihat pembayaran_service).
//   * Kegagalan kirim TIDAK membatalkan pembayaran; dicatat di wa_log untuk retry.
//   * Token HANYA dari environment/config server, tidak pernah ke frontend.
//   * Idempoten: satu pesanan tidak dikirimi notifikasi sukses berkali-kali.
//   * Bila WA belum dikonfigurasi -> fallback tautan wa.me (butuh tindakan pelanggan).
// =====================================================================

function wa_terkonfigurasi(): bool {
    return WA_TOKEN !== '' && WA_PHONE_ID !== '' && WA_ADMIN !== '';
}

// Susun teks notifikasi pesanan baru untuk admin.
function teks_notif_pesanan(array $p, array $detail): string {
    $lines = [];
    $lines[] = 'PESANAN KATERING BARU - ' . strtoupper(NAMA_WARTEG);
    $lines[] = 'Kode: ' . $p['kode'];
    $lines[] = 'Nama: ' . $p['nama_pelanggan'];
    $lines[] = 'WhatsApp: ' . $p['no_wa'];
    $lines[] = 'Pengambilan: ' . tanggal_id((string)$p['tanggal_ambil']) . ', ' . jam_pendek((string)$p['jam_ambil']) . ' WIB';
    $lines[] = 'Total box: ' . (int)$p['total_box'];
    $lines[] = 'Rincian menu:';
    foreach ($detail as $d) {
        $lines[] = '  - ' . $d['nama'] . ' x' . (int)$d['jumlah'] . ' (' . rupiah($d['harga_satuan']) . '/box)';
    }
    $lines[] = 'Total pembayaran: ' . rupiah((int)$p['total_harga']);
    $lines[] = 'Status pembayaran: ' . strtoupper(status_bayar_label((string)($p['status_bayar'] ?? BAYAR_BERHASIL)));
    $catatan = trim((string)($p['catatan'] ?? ''));
    if ($catatan !== '') $lines[] = 'Catatan: ' . $catatan;
    $lines[] = '';
    $lines[] = 'Silakan periksa pesanan pada dashboard admin.';
    return implode("\n", $lines);
}

/**
 * Kirim notifikasi WA admin untuk pesanan tertentu. Aman dipanggil berulang:
 * bila sudah ada log 'terkirim' untuk pesanan ini, tidak dikirim ulang.
 * Return ['ok'=>bool,'terkirim'=>bool,'pesan'=>?,'fallback_url'=>?]
 */
function kirim_notif_wa_pesanan(mysqli $kon, int $pesanan_id): array {
    $p = db_row($kon, 'SELECT * FROM pesanan WHERE id = ?', [$pesanan_id]);
    if (!$p) return ['ok' => false, 'terkirim' => false, 'pesan' => 'Pesanan tidak ditemukan.'];

    // Cegah pengiriman ganda untuk pesanan yang sama.
    $sudah = db_val($kon, "SELECT COUNT(*) FROM wa_log WHERE pesanan_id = ? AND status = 'terkirim'", [$pesanan_id]);
    if ((int)$sudah > 0) {
        return ['ok' => true, 'terkirim' => false, 'pesan' => 'Notifikasi sudah pernah terkirim (idempoten).'];
    }

    $detail = db_all($kon, 'SELECT d.jumlah, d.harga_satuan, m.nama FROM detail_pesanan d
        JOIN menu m ON m.id = d.menu_id WHERE d.pesanan_id = ? ORDER BY m.nama', [$pesanan_id]);
    $teks = teks_notif_pesanan($p, $detail);
    $tujuan_admin = normalisasi_wa(WA_ADMIN) ?? WA_ADMIN;

    // Fallback: tautan siap kirim (bila API belum dikonfigurasi atau gagal). Pelanggan/operator
    // yang menekan tautan inilah yang mengirim — bukan otomatis dari server.
    $fallback = null;
    if ($tujuan_admin !== '') {
        $fallback = 'https://wa.me/' . preg_replace('/\D/', '', $tujuan_admin) . '?text=' . rawurlencode($teks);
    }

    if (!wa_terkonfigurasi()) {
        // Catat sebagai 'menunggu' (belum dikirim otomatis) agar bisa dibedakan dari sukses.
        db_exec($kon, 'INSERT INTO wa_log (pesanan_id, kode_pesanan, tujuan, jenis, status, respons, percobaan) VALUES (?,?,?,?,?,?,0)',
            [$pesanan_id, $p['kode'], $tujuan_admin !== '' ? $tujuan_admin : '-', 'pesanan_baru', 'menunggu',
             'WA belum dikonfigurasi; pakai fallback tautan.']);
        return ['ok' => false, 'terkirim' => false, 'pesan' => 'WhatsApp API belum dikonfigurasi.', 'fallback_url' => $fallback, 'teks' => $teks];
    }

    // Kirim ke Cloud API. Token hanya di header server-side.
    $url = 'https://graph.facebook.com/' . WA_API_VERSION . '/' . WA_PHONE_ID . '/messages';
    $body = [
        'messaging_product' => 'whatsapp',
        'to' => $tujuan_admin,
        'type' => 'text',
        'text' => ['preview_url' => false, 'body' => $teks],
    ];
    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true, CURLOPT_POST => true, CURLOPT_TIMEOUT => 20,
        CURLOPT_HTTPHEADER => ['Content-Type: application/json', 'Authorization: Bearer ' . WA_TOKEN],
        CURLOPT_POSTFIELDS => json_encode($body),
    ]);
    $out = curl_exec($ch); $code = curl_getinfo($ch, CURLINFO_HTTP_CODE); curl_close($ch);
    $j = json_decode((string)$out, true);
    $sukses = ($code >= 200 && $code < 300 && is_array($j) && !empty($j['messages'][0]['id']));

    if ($sukses) {
        db_exec($kon, 'INSERT INTO wa_log (pesanan_id, kode_pesanan, tujuan, jenis, status, kode_http, respons, percobaan, dikirim_pada)
            VALUES (?,?,?,?,?,?,?,1,NOW())',
            [$pesanan_id, $p['kode'], $tujuan_admin, 'pesanan_baru', 'terkirim', $code, 'OK']);
        return ['ok' => true, 'terkirim' => true, 'pesan' => 'Notifikasi WhatsApp terkirim.', 'fallback_url' => $fallback, 'teks' => $teks];
    }

    // Gagal: catat ringkasan error (tanpa token), kembalikan fallback. Pembayaran TIDAK dibatalkan.
    $ringkas = is_array($j) ? mb_substr((string)($j['error']['message'] ?? json_encode($j)), 0, 240) : mb_substr((string)$out, 0, 240);
    db_exec($kon, 'INSERT INTO wa_log (pesanan_id, kode_pesanan, tujuan, jenis, status, kode_http, respons, percobaan)
        VALUES (?,?,?,?,?,?,?,1)', [$pesanan_id, $p['kode'], $tujuan_admin, 'pesanan_baru', 'gagal', (int)$code, $ringkas]);
    error_log('Kirim WA gagal HTTP ' . $code . ': ' . $ringkas);
    return ['ok' => false, 'terkirim' => false, 'pesan' => 'Notifikasi WhatsApp gagal dikirim.', 'fallback_url' => $fallback, 'teks' => $teks];
}
