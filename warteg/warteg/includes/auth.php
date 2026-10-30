<?php
// =====================================================================
// Autentikasi, sesi, dan otorisasi berbasis peran (role).
// Pola lama dipertahankan: semua pemeriksaan dilakukan di server,
// tampilan hanya menyembunyikan menu sebagai pelengkap (bukan pengaman).
// =====================================================================
function mulai_sesi(): void {
    if (session_status() === PHP_SESSION_NONE) {
        session_set_cookie_params(['httponly' => true, 'samesite' => 'Lax', 'path' => '/']);
        session_start();
    }
}

function csrf_token(): string {
    mulai_sesi();
    if (empty($_SESSION['csrf'])) $_SESSION['csrf'] = bin2hex(random_bytes(32));
    return $_SESSION['csrf'];
}

function csrf_cek(bool $json = false): void {
    mulai_sesi();
    $kirim = $_POST['csrf'] ?? ($_SERVER['HTTP_X_CSRF_TOKEN'] ?? '');
    if (empty($_SESSION['csrf']) || !is_string($kirim) || !hash_equals($_SESSION['csrf'], $kirim)) {
        if ($json) json_out(['ok' => false, 'pesan' => 'Sesi tidak valid. Muat ulang halaman lalu coba lagi.'], 403);
        http_response_code(403);
        exit('Token keamanan tidak valid.');
    }
}

// ID admin yang sedang login (0 bila belum login). Nilai ini tidak boleh diubah klien.
function admin_login_id(): int {
    mulai_sesi();
    return (int)($_SESSION['admin_id'] ?? 0);
}

function wajib_login(bool $json = false): void {
    if (admin_login_id() === 0) {
        if ($json) json_out(['ok' => false, 'pesan' => 'Anda belum login.'], 401);
        header('Location: ' . url('admin/login.php'));
        exit;
    }
}

function nama_admin(): string { mulai_sesi(); return $_SESSION['admin_nama'] ?? 'Admin'; }

// Role dari sesi (hanya untuk tampilan). Otorisasi sebenarnya memakai is_owner()/wajib_owner().
function role_sesi(): string { mulai_sesi(); return ($_SESSION['admin_role'] ?? 'admin') === 'owner' ? 'owner' : 'admin'; }

// Nama file foto profil admin yang sedang login (null = tampilkan avatar default).
// Kompatibel dengan sesi lama yang belum punya kunci ini.
function foto_admin_sesi(): ?string {
    mulai_sesi();
    return $_SESSION['admin_foto'] ?? null;
}

// Preferensi bahasa aktif (id/en). Selalu divalidasi, default Indonesia.
function bahasa_aktif(): string { mulai_sesi(); return ($_SESSION['admin_bahasa'] ?? 'id') === 'en' ? 'en' : 'id'; }

// ---------- Otorisasi berbasis peran ----------
// Ambil data admin aktif LANGSUNG dari database. Otorisasi memakai ini sehingga
// perubahan role di database langsung berlaku (bukan hanya mengandalkan sesi).
function akun_aktif(mysqli $kon): ?array {
    $id = admin_login_id();
    if ($id === 0) return null;
    try { return db_row($kon, 'SELECT * FROM admin WHERE id = ?', [$id]); }
    catch (Throwable $e) { error_log('akun_aktif gagal: ' . $e->getMessage()); return null; }
}

// True bila akun aktif ber-peran Owner (role dibaca dari database sebagai sumber kebenaran).
// Sesi ikut disamakan agar menu/ikon konsisten. Akun yang sudah tidak ada -> false.
function is_owner(?mysqli $kon = null): bool {
    global $kon;
    if (!$kon instanceof mysqli) return false;
    $a = akun_aktif($kon);
    if (!$a) return false;
    if (($_SESSION['admin_role'] ?? 'admin') !== ($a['role'] ?? 'admin')) {
        $_SESSION['admin_role'] = $a['role'] ?? 'admin';
    }
    return (($a['role'] ?? 'admin') === 'owner');
}

// Wajib login + wajib Owner. Dipakai di awal setiap halaman/endpoint yang menangani
// data admin. Penolakan terjadi di server, bukan hanya menyembunyikan menu.
function wajib_owner(bool $json = false): void {
    wajib_login($json);
    global $kon;
    if (is_owner($kon)) return;
    if ($json) json_out(['ok' => false, 'pesan' => 'Akses ditolak. Hanya Owner yang dapat mengelola admin.'], 403);
    http_response_code(403);
    $kembali = htmlspecialchars(url('admin/index.php'), ENT_QUOTES, 'UTF-8');
    exit('<!DOCTYPE html><html lang="id"><head><meta charset="UTF-8"><meta name="viewport" content="width=device-width, initial-scale=1">'
        . '<title>Akses ditolak</title></head><body style="font-family:system-ui,sans-serif;padding:40px;text-align:center">'
        . '<h1 style="font-size:22px;margin-bottom:8px">403 - Akses ditolak</h1>'
        . '<p style="color:#64748b;margin-bottom:16px">Halaman Manajemen Admin hanya dapat diakses oleh Owner.</p>'
        . '<a href="' . $kembali . '" style="color:#1e293b;font-weight:600">Kembali ke Dashboard</a>'
        . '</body></html>');
}

// Samakan data identitas di sesi (nama & foto, role, bahasa) dengan baris admin di database.
// Dipakai setelah admin mengubah profilnya sendiri, dan menjaga tampilan header
// tetap benar meski profil diubah dari sesi lain pada perangkat yang sama.
function segarkan_sesi_admin(): void {
    mulai_sesi();
    if (empty($_SESSION['admin_id'])) return;
    global $kon;
    try {
        $a = db_row($kon, 'SELECT id, nama, foto, role, bahasa FROM admin WHERE id = ?', [(int)$_SESSION['admin_id']]);
    } catch (Throwable $e) {
        error_log('segarkan_sesi_admin gagal: ' . $e->getMessage());
        return;
    }
    if (!$a) { // akun sudah tidak ada -> paksa keluar
        $_SESSION = [];
        session_destroy();
        if (!headers_sent()) { header('Location: ' . url('admin/login.php')); exit; }
        return;
    }
    $_SESSION['admin_nama']   = $a['nama'];
    $_SESSION['admin_foto']   = $a['foto'];
    $_SESSION['admin_role']   = $a['role'] ?? 'admin';
    $_SESSION['admin_bahasa'] = $a['bahasa'] ?? 'id';
}

// Catat satu baris log aktivitas. TIDAK pernah menerima/menyimpan kata sandi.
// Kegagalan mencatat log tidak boleh menggagalkan aksi utama pengguna.
function catat_aktivitas(mysqli $kon, string $aksi, string $keterangan = '', ?int $admin_id = null): void {
    mulai_sesi();
    $admin_id = $admin_id ?? (int)($_SESSION['admin_id'] ?? 0);
    $nama     = (string)($_SESSION['admin_nama'] ?? '');
    $ip       = $_SERVER['REMOTE_ADDR'] ?? null;
    try {
        db_exec($kon, 'INSERT INTO aktivitas_admin (admin_id, admin_nama, aksi, keterangan, ip) VALUES (?, ?, ?, ?, ?)',
            [$admin_id > 0 ? $admin_id : null, mb_substr($nama, 0, 100), mb_substr($aksi, 0, 40), mb_substr($keterangan, 0, 255), $ip]);
    } catch (Throwable $e) {
        error_log('catat_aktivitas gagal: ' . $e->getMessage());
    }
}
