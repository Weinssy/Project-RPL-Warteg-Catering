<?php
// =====================================================================
// Uji HTTP end-to-end ADMIN: login -> verifikasi pembayaran -> invoice LUNAS.
// Jalankan: php tests/uji_http_admin_verifikasi.php
// Membuat admin sementara, pesanan uji, lalu menghapusnya.
// =====================================================================
require_once __DIR__ . '/../includes/bootstrap.php';

$BASE = $argv[1] ?? 'http://localhost/warteg/warteg';
$cookie = tempnam(sys_get_temp_dir(), 'wrtadm');

function curlreq(string $url, string $cookie, ?array $post = null, bool $json = false): array {
    $ch = curl_init($url);
    curl_setopt_array($ch, [CURLOPT_RETURNTRANSFER => true, CURLOPT_COOKIEJAR => $cookie, CURLOPT_COOKIEFILE => $cookie, CURLOPT_TIMEOUT => 20, CURLOPT_FOLLOWLOCATION => true]);
    if ($post !== null) {
        curl_setopt($ch, CURLOPT_POST, true);
        if ($json) {
            curl_setopt($ch, CURLOPT_POSTFIELDS, http_build_query($post));
            if (!empty($post['csrf'])) curl_setopt($ch, CURLOPT_HTTPHEADER, ['X-CSRF-Token: ' . $post['csrf']]);
        } else {
            curl_setopt($ch, CURLOPT_POSTFIELDS, http_build_query($post));
        }
    }
    $body = curl_exec($ch); $code = curl_getinfo($ch, CURLINFO_HTTP_CODE); curl_close($ch);
    return ['code' => $code, 'body' => (string)$body];
}
$lulus = 0; $gagal = 0;
function cek(string $n, bool $k, string $i = ''): void { global $lulus, $gagal; if ($k) { $lulus++; echo "[LULUS] $n\n"; } else { $gagal++; echo "[GAGAL] $n $i\n"; } }

// --- Siapkan admin sementara ---
$u = 'uji_http_adm';
db_exec($kon, 'DELETE FROM admin WHERE username = ?', [$u]);
db_exec($kon, 'INSERT INTO admin (username, password_hash, nama, role) VALUES (?, ?, ?, ?)', [$u, password_hash('ujites123', PASSWORD_DEFAULT), 'Uji HTTP Admin', 'owner']);
$admin_id = (int)$kon->insert_id;

$nama_menu = 'ZZ-UJI-ADMQRIS';
db_exec($kon, 'DELETE FROM menu WHERE nama = ?', [$nama_menu]);
$tgl = (new DateTime('today'))->modify('+5 day')->format('Y-m-d');
db_exec($kon, "INSERT INTO menu (nama, kategori, harga) VALUES (?, 'nasi', 13000)", [$nama_menu]);
$mid = (int)$kon->insert_id;
db_exec($kon, 'INSERT INTO menu_harian (menu_id, tanggal, tersedia) VALUES (?, ?, 1)', [$mid, $tgl]);
db_exec($kon, 'INSERT IGNORE INTO kuota_harian (tanggal) VALUES (?)', [$tgl]);

// Pesanan menunggu verifikasi
$h = buat_pesanan($kon, ['nama' => '[UJI-HTTP-QRIS] Adm', 'no_wa' => '081234560077', 'tanggal_ambil' => $tgl, 'jam_ambil' => '10:00', 'items' => [$mid => 10]], true);
$pid = (int)$h['id']; $kode = $h['kode']; $token = $h['token_nota'];
db_exec($kon, 'UPDATE pesanan SET bukti_bayar = ?, bukti_bayar_pada = NOW(), status_bayar = ? WHERE id = ?', [str_repeat('e', 32) . '.jpg', BAYAR_MENUNGGU_VERIFIKASI, $pid]);

// --- Login via HTTP ---
$lp = curlreq("$BASE/admin/login.php", $cookie);
preg_match('/name="csrf" value="([a-f0-9]{64})"/', $lp['body'], $m);
$csrf = $m[1] ?? '';
cek('CSRF login ditemukan', strlen($csrf) === 64);
$login = curlreq("$BASE/admin/login.php", $cookie, ['csrf' => $csrf, 'username' => $u, 'password' => 'ujites123']);
cek('Login admin berhasil (masuk dashboard)', strpos($login['body'], 'Dashboard') !== false || strpos($login['body'], 'dashboard') !== false, substr($login['body'], 0, 120));

// Ambil meta csrf dari halaman verifikasi
$ver = curlreq("$BASE/admin/verifikasi.php", $cookie);
cek('Halaman verifikasi 200 & memuat pesanan uji', $ver['code'] === 200 && strpos($ver['body'], $kode) !== false, substr($ver['body'], 0, 120));
preg_match('/name="csrf-token" content="([a-f0-9]{64})"/', $ver['body'], $cm);
$csrf2 = $cm[1] ?? '';

// Bukti bayar terproteksi: buka sebagai admin login -> tidak 404 login redirect
$bukti = curlreq("$BASE/admin/bukti_bayar.php?kode=" . urlencode($kode), $cookie);
// File dummy tidak ada -> 404 (bukan redirect ke login). Itu menandakan endpoint terproteksi tapi login valid.
cek('bukti_bayar.php terproteksi tapi dapat diakses admin (bukan redirect login)', $bukti['code'] === 404, (string)$bukti['code']);

// Verifikasi SETUJU via endpoint AJAX
$aksi = curlreq("$BASE/admin/aksi/verifikasi_pembayaran.php", $cookie, ['id' => $pid, 'aksi' => 'setuju', 'csrf' => $csrf2], true);
$aj = json_decode($aksi['body'], true);
cek('Endpoint verifikasi setuju ok', is_array($aj) && ($aj['ok'] ?? false), $aksi['body']);
$p = db_row($kon, 'SELECT status_bayar, admin_verifikator_id FROM pesanan WHERE id = ?', [$pid]);
cek('DB status jadi berhasil', $p['status_bayar'] === BAYAR_BERHASIL, json_encode($p));
cek('Verifikator tercatat = admin uji', (int)$p['admin_verifikator_id'] === $admin_id, json_encode($p));

// Verifikasi ulang via endpoint -> ditolak
$aksi2 = curlreq("$BASE/admin/aksi/verifikasi_pembayaran.php", $cookie, ['id' => $pid, 'aksi' => 'setuju', 'csrf' => $csrf2], true);
$aj2 = json_decode($aksi2['body'], true);
cek('Verifikasi ulang ditolak (tidak ganda)', is_array($aj2) && !($aj2['ok'] ?? false), $aksi2['body']);

// Invoice admin (tanpa token, karena login admin) menampilkan LUNAS
$inv = curlreq("$BASE/invoice.php?kode=" . urlencode($kode), $cookie);
cek('Invoice (akses admin) menampilkan LUNAS', strpos($inv['body'], 'Invoice ini sudah LUNAS') !== false);

// --- Bersihkan ---
db_exec($kon, 'DELETE FROM verifikasi_log WHERE pesanan_id = ?', [$pid]);
db_exec($kon, 'DELETE FROM wa_log WHERE pesanan_id = ?', [$pid]);
db_exec($kon, 'DELETE FROM pembayaran_log WHERE pesanan_id = ?', [$pid]);
db_exec($kon, 'DELETE FROM detail_pesanan WHERE pesanan_id = ?', [$pid]);
db_exec($kon, 'DELETE FROM pesanan WHERE id = ?', [$pid]);
db_exec($kon, 'DELETE FROM menu WHERE nama = ?', [$nama_menu]);
db_exec($kon, 'DELETE FROM kuota_harian WHERE tanggal = ?', [$tgl]);
db_exec($kon, 'DELETE FROM admin WHERE id = ?', [$admin_id]);

unlink($cookie);
echo "\nRingkasan HTTP Admin: $lulus lulus, $gagal gagal\n";
exit($gagal === 0 ? 0 : 1);
