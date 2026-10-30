<?php
// Uji HTTP end-to-end via server bawaan PHP (127.0.0.1:8765).
// Pastikan server berjalan: php -S 127.0.0.1:8765 -t .
// Fokus: CSRF, validasi, pembuatan pesanan lewat AJAX, dan akses nota via token.
$BASE = 'http://127.0.0.1:8765';
$cookie = tempnam(sys_get_temp_dir(), 'wrtcookie');

function req(string $url, array $post = null, ?string $csrf = null, string $cookie): array {
    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true, CURLOPT_COOKIEJAR => $cookie, CURLOPT_COOKIEFILE => $cookie,
        CURLOPT_TIMEOUT => 15,
    ]);
    if ($post !== null) {
        $post['csrf'] = $csrf;
        curl_setopt($ch, CURLOPT_POST, true);
        curl_setopt($ch, CURLOPT_POSTFIELDS, http_build_query($post));
        curl_setopt($ch, CURLOPT_HTTPHEADER, ['X-CSRF-Token: ' . $csrf]);
    }
    $body = curl_exec($ch); $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $ctype = curl_getinfo($ch, CURLINFO_CONTENT_TYPE);
    curl_close($ch);
    return ['code' => $code, 'body' => $body, 'type' => $ctype];
}

$lulus = 0; $gagal = 0;
function cek(string $n, bool $k, string $i = ''): void { global $lulus, $gagal; if ($k) { $lulus++; echo "[LULUS] $n\n"; } else { $gagal++; echo "[GAGAL] $n $i\n"; } }

// 1) Ambil halaman index (set sesi + csrf meta).
$home = req($BASE . '/index.php', null, null, $cookie);
cek('Halaman index 200', $home['code'] === 200);
preg_match('/name="csrf-token" content="([a-f0-9]{64})"/', $home['body'], $m);
$csrf = $m[1] ?? '';
cek('CSRF token ditemukan', strlen($csrf) === 64);

// 2) Cek aksi menu (GET). Cari tanggal yang boleh dipesan & punya menu tersedia.
// Tanggal dicari dari server (hindari selisih zona waktu antara proses CLI & server).
$mj = null; $tgl = null; $ada_menu = [];
for ($i = 1; $i <= 10; $i++) {
    $cand = (new DateTime('today'))->modify("+$i day")->format('Y-m-d');
    $menu = req($BASE . '/pesan_aksi.php?aksi=menu&tanggal=' . urlencode($cand), null, null, $cookie);
    $j = json_decode($menu['body'], true);
    if (!is_array($j) || !($j['ok'] ?? false)) continue;
    $mj = $j; $tgl = $cand;
    foreach (($j['menu'] ?? []) as $mm) if (!empty($mm['bisa'])) $ada_menu[] = $mm['id'];
    if (!empty($ada_menu) && ($j['boleh_pesan'] ?? false)) break;
}
cek('Aksi menu mengembalikan JSON ok', is_array($mj) && ($mj['ok'] ?? false), substr((string)($mj ? json_encode($mj) : ''), 0, 200));

// 3) POST pesan tanpa CSRF -> ditolak.
$noCsrf = req($BASE . '/pesan_aksi.php?aksi=pesan', ['aksi' => 'pesan', 'nama' => 'X', 'no_wa' => '081234567111', 'tanggal_ambil' => $tgl, 'jam_ambil' => '10:00', 'items' => '{}'], '', $cookie);
cek('Pesan tanpa CSRF ditolak (403)', $noCsrf['code'] === 403, (string)$noCsrf['code']);

// 4) POST pesan items kosong -> 422 validasi.
$kosong = req($BASE . '/pesan_aksi.php?aksi=pesan', ['nama' => 'Tes', 'no_wa' => '081234567111', 'tanggal_ambil' => $tgl, 'jam_ambil' => '10:00', 'items' => '{}'], $csrf, $cookie);
cek('Items kosong ditolak 422', $kosong['code'] === 422, $kosong['body']);

// 5) POST pesan dengan menu (jika ada) atau lewati.
if ($ada_menu) {
    // Pesan 10 box menu pertama yang tersedia.
    $items = json_encode([(string)$ada_menu[0] => 10]);
    $ok = req($BASE . '/pesan_aksi.php?aksi=pesan', [
        'nama' => '[UJI HTTP] Pemesan', 'no_wa' => '081234567112', 'tanggal_ambil' => $tgl,
        'jam_ambil' => '11:00', 'items' => $items, 'catatan' => 'tes', 'idempotency' => 'ujitest' . time(),
    ], $csrf, $cookie);
    $oj = json_decode($ok['body'], true);
    cek('Pesan valid berhasil (ok)', is_array($oj) && ($oj['ok'] ?? false), substr($ok['body'], 0, 300));

    if (is_array($oj) && ($oj['ok'] ?? false)) {
        // 6) Buka nota via token -> 200 dan menampilkan kode.
        $nota = req($BASE . '/nota.php?kode=' . urlencode($oj['kode']) . '&t=' . urlencode($oj['token']), null, null, $cookie);
        cek('Nota dengan token 200', $nota['code'] === 200);
        cek('Nota memuat kode pesanan', strpos((string)$nota['body'], $oj['kode']) !== false);

        // 7) Nota dengan token SALAH -> tidak menampilkan data (200 tetapi galat).
        $notaSalah = req($BASE . '/nota.php?kode=' . urlencode($oj['kode']) . '&t=salahtoken', null, null, $cookie);
        cek('Nota token salah tidak menampilkan rincian', strpos((string)$notaSalah['body'], 'Tautan tidak valid') !== false);

        // 8) status_bayar endpoint dengan token benar.
        $st = req($BASE . '/status_bayar.php?kode=' . urlencode($oj['kode']) . '&t=' . urlencode($oj['token']), null, null, $cookie);
        $stj = json_decode($st['body'], true);
        cek('status_bayar endpoint ok', is_array($stj) && ($stj['ok'] ?? false), $st['body']);
    }
} else {
    echo "[LEWATI] Tidak ada menu tersedia untuk tanggal uji; bagian pesan dilewati.\n";
}

// 9) Webhook GET -> 405.
$wh = req($BASE . '/webhook.php', null, null, $cookie);
cek('Webhook GET ditolak 405', $wh['code'] === 405, (string)$wh['code']);

unlink($cookie);
echo "\nRingkasan HTTP: $lulus lulus, $gagal gagal\n";
