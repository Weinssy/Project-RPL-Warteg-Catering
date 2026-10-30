<?php
// =====================================================================
// Layanan pembayaran QRIS.
//
// Prinsip keamanan:
//   * Status pembayaran HANYA berubah karena hasil verifikasi SERVER (webhook/callback),
//     tidak pernah karena klik tombol atau nilai dari browser.
//   * Setiap callback disimpan pada pembayaran_log dengan fingerprint unik -> idempoten.
//   * Status pembayaran (status_bayar) dipisah dari status proses katering (status).
//   * Bila gateway belum dikonfigurasi, sistem TIDAK membuat QR palsu: pesanan tetap
//     tersimpan dengan status_bayar 'belum_bayar' dan UI menampilkan "belum dikonfigurasi".
//
// Nilai status_bayar yang dipakai: belum_bayar, menunggu, menunggu_verifikasi, berhasil, ditolak, gagal, kedaluwarsa.
// =====================================================================

// Memakai define() yang dijaga, bukan const, agar aman bila modul ini dimuat bersama
// pesanan_service.php (yang menyediakan fallback 'belum_bayar').
if (!defined('BAYAR_BELUM'))                define('BAYAR_BELUM', 'belum_bayar');
if (!defined('BAYAR_MENUNGGU'))             define('BAYAR_MENUNGGU', 'menunggu');
if (!defined('BAYAR_MENUNGGU_VERIFIKASI'))  define('BAYAR_MENUNGGU_VERIFIKASI', 'menunggu_verifikasi');
if (!defined('BAYAR_BERHASIL'))             define('BAYAR_BERHASIL', 'berhasil');
if (!defined('BAYAR_DITOLAK'))              define('BAYAR_DITOLAK', 'ditolak');
if (!defined('BAYAR_GAGAL'))                define('BAYAR_GAGAL', 'gagal');
if (!defined('BAYAR_KEDALUWARSA'))          define('BAYAR_KEDALUWARSA', 'kedaluwarsa');
if (!defined('BAYAR_BATAL'))                define('BAYAR_BATAL', 'dibatalkan');

function status_bayar_label(string $s, string $bhs = 'id'): string {
    $peta = [
        BAYAR_BELUM                => ['Belum Dibayar', 'Unpaid'],
        BAYAR_MENUNGGU             => ['Menunggu Pembayaran', 'Awaiting Payment'],
        BAYAR_MENUNGGU_VERIFIKASI  => ['Menunggu Verifikasi', 'Awaiting Verification'],
        BAYAR_BERHASIL             => ['Lunas', 'Paid'],
        BAYAR_DITOLAK              => ['Ditolak', 'Rejected'],
        BAYAR_GAGAL                => ['Gagal', 'Failed'],
        BAYAR_KEDALUWARSA          => ['Kedaluwarsa', 'Expired'],
        BAYAR_BATAL                => ['Dibatalkan', 'Cancelled'],
    ];
    return ($peta[$s] ?? ['Menunggu Pembayaran', 'Awaiting Payment'])[$bhs === 'en' ? 1 : 0];
}

function kelas_status_bayar(string $s): string {
    // kelas CSS untuk pewarnaan indikator
    return [
        BAYAR_BELUM                => 'bayar-abu',
        BAYAR_MENUNGGU             => 'bayar-kuning',
        BAYAR_MENUNGGU_VERIFIKASI  => 'bayar-oranye',
        BAYAR_BERHASIL             => 'bayar-hijau',
        BAYAR_DITOLAK              => 'bayar-merah',
        BAYAR_GAGAL                => 'bayar-merah',
        BAYAR_KEDALUWARSA          => 'bayar-abu',
        BAYAR_BATAL                => 'bayar-abu',
    ][$s] ?? 'bayar-abu';
}

// Base URL publik aplikasi (untuk callback/redirect ke gateway).
function app_url_publik(): string {
    if (APP_URL_PUBLIK !== '') return rtrim(APP_URL_PUBLIK, '/');
    $skema = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https' : 'http';
    $host  = $_SERVER['HTTP_HOST'] ?? 'localhost';
    return $skema . '://' . $host . BASE_URL; // tanpa slash akhir
}

// Apakah integrasi QRIS dinamis siap dipakai (kredensial provider tersedia)?
function pembayaran_terkonfigurasi(): bool {
    switch (PAYMENT_PROVIDER) {
        case 'mock':     return true; // khusus pengujian lokal
        case 'midtrans': return MIDTRANS_SERVER_KEY !== '';
        case 'xendit':   return XENDIT_API_KEY !== '';
        default:         return false;
    }
}

// Apakah QRIS statis tersedia (gambar sudah diupload admin)?
function qris_statis_tersedia(mysqli $kon): bool {
    try {
        $r = db_row($kon, "SELECT nilai FROM konfigurasi_toko WHERE kunci = 'qris_gambar'");
        return ($r && !empty($r['nilai']));
    } catch (Throwable $e) {
        return false;
    }
}

// Ambil konfigurasi toko berdasarkan kunci
function konfigurasi_toko(mysqli $kon, string $kunci, ?string $default = null): ?string {
    try {
        $r = db_row($kon, 'SELECT nilai FROM konfigurasi_toko WHERE kunci = ?', [$kunci]);
        return $r ? ($r['nilai'] ?? $default) : $default;
    } catch (Throwable $e) {
        return $default;
    }
}

// Simpan konfigurasi toko
function simpan_konfigurasi(mysqli $kon, string $kunci, ?string $nilai, int $admin_id): void {
    db_exec($kon, 'INSERT INTO konfigurasi_toko (kunci, nilai, diubah_pada, diubah_oleh)
        VALUES (?, ?, NOW(), ?) ON DUPLICATE KEY UPDATE nilai = VALUES(nilai), diubah_pada = NOW(), diubah_oleh = VALUES(diubah_oleh)',
        [$kunci, $nilai, $admin_id]);
}

// Simpan file bukti pembayaran
function simpan_bukti_bayar(array $f): array {
    $err = $f['error'] ?? UPLOAD_ERR_NO_FILE;
    if ($err === UPLOAD_ERR_NO_FILE) return [null, 'Pilih file bukti pembayaran.'];
    if ($err === UPLOAD_ERR_INI_SIZE || $err === UPLOAD_ERR_FORM_SIZE) return [null, 'Ukuran file maksimal 5 MB.'];
    if ($err !== UPLOAD_ERR_OK) return [null, 'Unggah bukti pembayaran gagal. Coba lagi.'];
    if (empty($f['tmp_name']) || !is_uploaded_file($f['tmp_name'])) return [null, 'File tidak valid.'];
    $maks = 5 * 1024 * 1024; // 5 MB
    if (($f['size'] ?? 0) <= 0 || $f['size'] > $maks) return [null, 'Ukuran file maksimal 5 MB.'];
    $mime = (new finfo(FILEINFO_MIME_TYPE))->file($f['tmp_name']);
    $peta = ['image/jpeg' => 'jpg', 'image/png' => 'png', 'image/webp' => 'webp'];
    if (!isset($peta[$mime])) return [null, 'Format file harus JPG, PNG, atau WebP.'];
    $info = @getimagesize($f['tmp_name']);
    if (!$info || empty($info[2]) || !in_array($info[2], [IMAGETYPE_JPEG, IMAGETYPE_PNG, IMAGETYPE_WEBP], true)) {
        return [null, 'File yang diunggah bukan gambar yang valid.'];
    }
    $dir = __DIR__ . '/../uploads/bukti';
    if (!is_dir($dir) && !@mkdir($dir, 0755, true) && !is_dir($dir)) return [null, 'Folder bukti tidak dapat dibuat.'];
    $nama = bin2hex(random_bytes(16)) . '.' . $peta[$mime];
    if (!move_uploaded_file($f['tmp_name'], $dir . '/' . $nama)) return [null, 'Bukti tidak dapat disimpan di server.'];
    @chmod($dir . '/' . $nama, 0644);
    return [$nama, null];
}

// Simpan file gambar QRIS statis
function simpan_qris_gambar(array $f): array {
    $err = $f['error'] ?? UPLOAD_ERR_NO_FILE;
    if ($err === UPLOAD_ERR_NO_FILE) return [null, 'Pilih file gambar QRIS.'];
    if ($err !== UPLOAD_ERR_OK) return [null, 'Unggah gambar QRIS gagal.'];
    $maks = 5 * 1024 * 1024;
    if (($f['size'] ?? 0) <= 0 || $f['size'] > $maks) return [null, 'Ukuran file maksimal 5 MB.'];
    $mime = (new finfo(FILEINFO_MIME_TYPE))->file($f['tmp_name']);
    $peta = ['image/jpeg' => 'jpg', 'image/png' => 'png', 'image/webp' => 'webp'];
    if (!isset($peta[$mime])) return [null, 'Format harus JPG, PNG, atau WebP.'];
    if (!@getimagesize($f['tmp_name'])) return [null, 'File bukan gambar valid.'];
    $dir = __DIR__ . '/../uploads/qris';
    if (!is_dir($dir) && !@mkdir($dir, 0755, true) && !is_dir($dir)) return [null, 'Folder QRIS tidak dapat dibuat.'];
    $nama = 'qris_' . bin2hex(random_bytes(10)) . '.' . $peta[$mime];
    if (!move_uploaded_file($f['tmp_name'], $dir . '/' . $nama)) return [null, 'Gambar QRIS tidak dapat disimpan.'];
    @chmod($dir . '/' . $nama, 0644);
    return [$nama, null];
}

/**
 * Verifikasi pembayaran oleh admin (setuju/tolak).
 * Fungsi ini TERPISAH dari webhook sehingga nanti integrasi QRIS dinamis
 * bisa ditambahkan tanpa mengubah logika verifikasi manual.
 */
function verifikasi_pembayaran(mysqli $kon, int $pesanan_id, string $aksi, int $admin_id, ?string $alasan = null): array {
    if (!in_array($aksi, ['setuju', 'tolak'], true)) {
        return ['ok' => false, 'pesan' => 'Aksi verifikasi tidak valid.'];
    }
    try {
        $kon->begin_transaction();
        $p = db_row($kon, 'SELECT * FROM pesanan WHERE id = ? FOR UPDATE', [$pesanan_id]);
        if (!$p) { $kon->rollback(); return ['ok' => false, 'pesan' => 'Pesanan tidak ditemukan.']; }

        // Hanya bisa verifikasi yang menunggu verifikasi atau ditolak (kirim ulang)
        if (!in_array($p['status_bayar'], [BAYAR_MENUNGGU_VERIFIKASI, BAYAR_DITOLAK], true)) {
            $kon->rollback();
            return ['ok' => false, 'pesan' => 'Pesanan tidak dalam status menunggu verifikasi.'];
        }

        // Cegah verifikasi ganda: jika sudah berhasil, jangan ubah
        if ($p['status_bayar'] === BAYAR_BERHASIL) {
            $kon->rollback();
            return ['ok' => false, 'pesan' => 'Pembayaran sudah diverifikasi sebelumnya.'];
        }

        $admin = db_row($kon, 'SELECT id, nama FROM admin WHERE id = ?', [$admin_id]);
        $admin_nama = $admin ? $admin['nama'] : 'Admin';
        $status_lama = $p['status_bayar'];

        if ($aksi === 'setuju') {
            $status_baru = BAYAR_BERHASIL;
            db_exec($kon, 'UPDATE pesanan SET status_bayar = ?, dibayar_pada = NOW(), admin_verifikator_id = ?, diverifikasi_pada = NOW(), alasan_tolak = NULL WHERE id = ?',
                [$status_baru, $admin_id, $pesanan_id]);
        } else {
            $status_baru = BAYAR_DITOLAK;
            $alasan = trim((string)$alasan);
            if ($alasan === '') $alasan = 'Bukti pembayaran tidak valid.';
            db_exec($kon, 'UPDATE pesanan SET status_bayar = ?, alasan_tolak = ?, admin_verifikator_id = ?, diverifikasi_pada = NOW() WHERE id = ?',
                [$status_baru, mb_substr($alasan, 0, 500), $admin_id, $pesanan_id]);
        }

        // Log verifikasi
        db_exec($kon, 'INSERT INTO verifikasi_log (pesanan_id, kode_pesanan, admin_id, admin_nama, aksi, alasan, status_lama, status_baru)
            VALUES (?, ?, ?, ?, ?, ?, ?, ?)',
            [$pesanan_id, $p['kode'], $admin_id, $admin_nama, $aksi, $alasan, $status_lama, $status_baru]);

        $kon->commit();

        // Notifikasi WA setelah pembayaran disetujui (di luar transaksi)
        if ($aksi === 'setuju') {
            try { kirim_notif_wa_pesanan($kon, $pesanan_id); }
            catch (Throwable $e) { error_log('notif WA setelah verifikasi gagal: ' . $e->getMessage()); }
        }

        return ['ok' => true, 'pesan' => $aksi === 'setuju' ? 'Pembayaran disetujui. Invoice LUNAS diterbitkan.' : 'Pembayaran ditolak. Customer dapat mengirim bukti baru.', 'status' => $status_baru];
    } catch (Throwable $e) {
        try { $kon->rollback(); } catch (Throwable $x) {}
        error_log('verifikasi_pembayaran gagal: ' . $e->getMessage());
        return ['ok' => false, 'pesan' => 'Gagal memproses verifikasi.'];
    }
}

/**
 * Buat transaksi QRIS untuk sebuah pesanan.
 * Mengembalikan array:
 *   ['ok'=>bool, 'terkonfigurasi'=>bool, 'kode_bayar'=>?, 'qr'=>?, 'pesan'=>?]
 * Bila belum terkonfigurasi: tidak mengubah DB selain menandai 'belum_bayar', tidak ada QR palsu.
 */
function buat_transaksi_qris(mysqli $kon, array $pesanan): array {
    if (!pembayaran_terkonfigurasi()) {
        return ['ok' => false, 'terkonfigurasi' => false,
            'pesan' => 'Integrasi pembayaran belum dikonfigurasi.'];
    }

    $nominal = (int)($pesanan['nominal_bayar'] ?? $pesanan['total_harga']);
    $kode    = (string)$pesanan['kode'];
    $id      = (int)$pesanan['id'];
    $expire  = (new DateTime('now'))->modify('+' . BAYAR_KEDALUWARSA_MENIT . ' minutes')->format('Y-m-d H:i:s');

    try {
        switch (PAYMENT_PROVIDER) {
            case 'mock':
                // Mode uji lokal: kode bayar & payload deterministik, TIDAK menyamar sebagai QRIS asli.
                $kode_bayar = 'MOCK-' . $kode;
                $qr         = 'MOCK-QRIS:' . $kode . ':' . $nominal;
                break;
            case 'midtrans':
                $res = midtrans_buat_qris($kode, $nominal);
                if (!$res['ok']) return $res + ['terkonfigurasi' => true];
                $kode_bayar = $res['kode_bayar'];
                $qr         = $res['qr'];
                break;
            case 'xendit':
                $res = xendit_buat_qris($kode, $nominal, $expire);
                if (!$res['ok']) return $res + ['terkonfigurasi' => true];
                $kode_bayar = $res['kode_bayar'];
                $qr         = $res['qr'];
                break;
            default:
                return ['ok' => false, 'terkonfigurasi' => false, 'pesan' => 'Penyedia pembayaran tidak dikenal.'];
        }

        db_exec($kon, 'UPDATE pesanan SET status_bayar = ?, metode_bayar = ?, kode_bayar = ?, qr_payload = ?,
                    nominal_bayar = ?, bayar_kedaluwarsa_pada = ? WHERE id = ?',
            [BAYAR_MENUNGGU, 'qris', $kode_bayar, $qr, $nominal, $expire, $id]);

        return ['ok' => true, 'terkonfigurasi' => true, 'kode_bayar' => $kode_bayar, 'qr' => $qr,
            'kode_pesanan' => $kode, 'nominal' => $nominal, 'kedaluwarsa' => $expire];
    } catch (Throwable $e) {
        error_log('buat_transaksi_qris gagal: ' . $e->getMessage());
        return ['ok' => false, 'terkonfigurasi' => true, 'pesan' => 'Gagal membuat transaksi QRIS. Coba lagi nanti.'];
    }
}

// ---------------- Midtrans Core API (QRIS) ----------------
function midtrans_buat_qris(string $kode, int $nominal): array {
    $payload = [
        'payment_type' => 'qris',
        'transaction_details' => ['order_id' => $kode, 'gross_amount' => $nominal],
        'qris' => ['acquirer' => 'gopay'],
    ];
    $url = (MIDTRANS_IS_PRODUCTION ? 'https://api.midtrans.com' : 'https://api.sandbox.midtrans.com') . '/v2/charge';
    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true, CURLOPT_POST => true,
        CURLOPT_HTTPHEADER => ['Content-Type: application/json', 'Accept: application/json',
            'Authorization: Basic ' . base64_encode(MIDTRANS_SERVER_KEY . ':')],
        CURLOPT_POSTFIELDS => json_encode($payload), CURLOPT_TIMEOUT => 20,
    ]);
    $out = curl_exec($ch); $code = curl_getinfo($ch, CURLINFO_HTTP_CODE); curl_close($ch);
    $j = json_decode((string)$out, true);
    if ($code >= 200 && $code < 300 && is_array($j) && !empty($j['transaction_id'])) {
        $qr = '';
        foreach (($j['actions'] ?? []) as $a) if (($a['name'] ?? '') === 'generate-qr-code') $qr = $a['url'] ?? '';
        return ['ok' => true, 'kode_bayar' => (string)$j['transaction_id'], 'qr' => $qr];
    }
    error_log('midtrans charge gagal HTTP ' . $code . ': ' . substr((string)$out, 0, 300));
    return ['ok' => false, 'pesan' => 'Gagal membuat QRIS Midtrans.'];
}

// ---------------- Xendit QR Codes API ----------------
function xendit_buat_qris(string $kode, int $nominal, string $expire): array {
    $payload = [
        'reference_id' => $kode,
        'type' => 'DYNAMIC',
        'currency' => 'IDR',
        'amount' => $nominal,
        'expires_at' => (new DateTime($expire))->format('c'),
    ];
    $ch = curl_init('https://api.xendit.co/qr_codes');
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true, CURLOPT_POST => true,
        CURLOPT_HTTPHEADER => ['Content-Type: application/json'],
        CURLOPT_USERPWD => XENDIT_API_KEY . ':',
        CURLOPT_POSTFIELDS => json_encode($payload), CURLOPT_TIMEOUT => 20,
    ]);
    $out = curl_exec($ch); $code = curl_getinfo($ch, CURLINFO_HTTP_CODE); curl_close($ch);
    $j = json_decode((string)$out, true);
    if ($code >= 200 && $code < 300 && is_array($j) && !empty($j['id'])) {
        return ['ok' => true, 'kode_bayar' => (string)$j['id'], 'qr' => (string)($j['qr_string'] ?? '')];
    }
    error_log('xendit qr gagal HTTP ' . $code . ': ' . substr((string)$out, 0, 300));
    return ['ok' => false, 'pesan' => 'Gagal membuat QRIS Xendit.'];
}

/**
 * Terapkan hasil callback pembayaran secara IDEMPOTEN.
 * $data: kode_pesanan, provider, event, status_masuk, status_baru (internal), kode_bayar, nominal, ip, fingerprint
 * Return: ['ok'=>bool,'pesan'=>?, 'baru'=>bool]  ('baru'=true bila pertama kali diproses)
 */
function terapkan_callback_pembayaran(mysqli $kon, array $d): array {
    $fingerprint = (string)($d['fingerprint'] ?? '');
    if (strlen($fingerprint) !== 64) return ['ok' => false, 'pesan' => 'Fingerprint tidak valid.'];

    $pesanan = db_row($kon, 'SELECT * FROM pesanan WHERE kode = ?', [(string)($d['kode_pesanan'] ?? '')]);
    if (!$pesanan) return ['ok' => false, 'pesan' => 'Pesanan tidak ditemukan.'];

    // Nominal harus cocok dengan yang ditagih (anti manipulasi nominal).
    $nominal = (int)($d['nominal'] ?? 0);
    $tagih   = (int)($pesanan['nominal_bayar'] ?? $pesanan['total_harga']);
    if ($nominal !== $tagih) {
        return ['ok' => false, 'pesan' => 'Nominal callback tidak sesuai tagihan.'];
    }

    try {
        $kon->begin_transaction();

        // Idempotensi: catat fingerprint; bila sudah ada -> callback pernah diproses.
        $ada = db_row($kon, 'SELECT id FROM pembayaran_log WHERE fingerprint = ?', [$fingerprint]);
        if ($ada) { $kon->rollback(); return ['ok' => true, 'pesan' => 'Callback sudah diproses (idempoten).', 'baru' => false]; }

        db_exec($kon, 'INSERT INTO pembayaran_log (pesanan_id, kode_pesanan, kode_bayar, provider, event, status_masuk, status_hasil, nominal, fingerprint, ip)
            VALUES (?,?,?,?,?,?,?,?,?,?)', [
            (int)$pesanan['id'], (string)$pesanan['kode'], $d['kode_bayar'] ?? null,
            (string)($d['provider'] ?? ''), (string)($d['event'] ?? ''), (string)($d['status_masuk'] ?? ''),
            (string)($d['status_baru'] ?? ''), $nominal, $fingerprint, $d['ip'] ?? null,
        ]);

        $status_baru = (string)($d['status_baru'] ?? '');
        // Jangan turunkan status yang sudah final berhasil.
        if ($status_baru === BAYAR_BERHASIL) {
            if ($pesanan['status_bayar'] !== BAYAR_BERHASIL) {
                db_exec($kon, 'UPDATE pesanan SET status_bayar = ?, dibayar_pada = NOW() WHERE id = ?',
                    [BAYAR_BERHASIL, (int)$pesanan['id']]);
            }
        } elseif (in_array($status_baru, [BAYAR_GAGAL, BAYAR_KEDALUWARSA], true)) {
            // Hanya ubah bila belum berhasil (pembayaran sah yang sudah lunas tidak dibatalkan).
            if ($pesanan['status_bayar'] !== BAYAR_BERHASIL) {
                db_exec($kon, 'UPDATE pesanan SET status_bayar = ? WHERE id = ?', [$status_baru, (int)$pesanan['id']]);
            }
        }

        $kon->commit();
    } catch (Throwable $e) {
        try { $kon->rollback(); } catch (Throwable $x) {}
        // Pelanggaran UNIQUE pada fingerprint = duplikat yang nyaris bersamaan.
        if (isset($e) && (int)$e->getCode() === 1062) return ['ok' => true, 'pesan' => 'Callback sudah diproses (idempoten).', 'baru' => false];
        error_log('terapkan_callback_pembayaran gagal: ' . $e->getMessage());
        return ['ok' => false, 'pesan' => 'Gagal memproses callback.'];
    }

    // Setelah commit & pembayaran berhasil -> picu notifikasi WA (di luar transaksi, gagal tidak membatalkan bayar).
    $baru_berhasil = ($status_baru === BAYAR_BERHASIL && $pesanan['status_bayar'] !== BAYAR_BERHASIL);
    if ($baru_berhasil) {
        require_once __DIR__ . '/wa_service.php';
        try { kirim_notif_wa_pesanan($kon, (int)$pesanan['id']); }
        catch (Throwable $e) { error_log('notif WA setelah bayar gagal: ' . $e->getMessage()); }
    }

    return ['ok' => true, 'pesan' => 'Callback diproses.', 'baru' => true, 'status' => $status_baru];
}

// Petakan status/notifikasi gateway -> status internal.
function petakan_status_gateway(string $status): string {
    $s = strtolower(trim($status));
    if (in_array($s, ['settlement', 'capture', 'paid', 'success', 'succeeded', 'completed', 'succeeded'], true)) return BAYAR_BERHASIL;
    if (in_array($s, ['pending', 'processing', 'waiting', 'authorize'], true)) return BAYAR_MENUNGGU;
    if (in_array($s, ['expire', 'expired', 'kedaluwarsa'], true)) return BAYAR_KEDALUWARSA;
    if (in_array($s, ['deny', 'denied', 'failure', 'failed', 'cancel', 'cancelled', 'gagal'], true)) return BAYAR_GAGAL;
    return BAYAR_MENUNGGU;
}
