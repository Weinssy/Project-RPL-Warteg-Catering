<?php
// =====================================================================
// Hapus akun admin — KHUSUS OWNER.
// Pengamanan (semua dicek di server, bukan hanya menyembunyikan tombol):
//   - wajib_owner(true): wajib login + wajib Owner (role dibaca dari database).
//   - csrf_cek(true): token CSRF wajib valid.
//   - prepared statement untuk semua query.
//   - Tidak boleh menghapus akun sendiri (ID dari sesi, bukan dari klien).
//   - Akun ber-peran Owner tidak boleh dihapus melalui fitur ini.
//   - Aktivitas penghapusan dicatat lewat catat_aktivitas().
// =====================================================================
require_once __DIR__ . '/../../includes/bootstrap.php';

if ($_SERVER['REQUEST_METHOD'] !== 'POST') json_out(['ok' => false, 'pesan' => 'Metode tidak diizinkan.'], 405);
wajib_owner(true);   // login + Owner; non-Owner ditolak 403 (JSON)
csrf_cek(true);      // token CSRF; tidak valid -> 403 (JSON)

$adminId = filter_var($_POST['admin_id'] ?? null, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
if (!$adminId) json_out(['ok' => false, 'pesan' => 'ID akun tidak valid.'], 400);

$sayaId = admin_login_id(); // dari sesi, tidak dapat dimanipulasi klien

try {
    $target = db_row($kon, 'SELECT id, username, nama, role FROM admin WHERE id = ?', [$adminId]);
    if (!$target) json_out(['ok' => false, 'pesan' => 'Akun admin tidak ditemukan.'], 404);

    // Proteksi: jangan pernah menghapus akun yang sedang login, meski ID dimanipulasi.
    if ((int)$target['id'] === $sayaId) {
        json_out(['ok' => false, 'pesan' => 'Anda tidak dapat menghapus akun Anda sendiri.'], 400);
    }

    // Proteksi: akun Owner (termasuk Owner lain) tidak boleh dihapus lewat fitur ini.
    if (($target['role'] ?? 'admin') === 'owner') {
        json_out(['ok' => false, 'pesan' => 'Akun ber-peran Owner tidak dapat dihapus.'], 403);
    }

    // Hapus hanya bila benar-benar ber-peran admin (pertahanan berlapis terhadap kondisi balapan).
    $kon->begin_transaction();
    $n = db_exec($kon, "DELETE FROM admin WHERE id = ? AND role = 'admin'", [$adminId]);
    if ($n !== 1) {
        $kon->rollback();
        json_out(['ok' => false, 'pesan' => 'Akun tidak dapat dihapus. Pastikan akun ber-peran Admin.'], 409);
    }
    $kon->commit();
} catch (Throwable $e) {
    try { $kon->rollback(); } catch (Throwable $x) {}
    error_log('hapus_admin gagal: ' . $e->getMessage());
    json_out(['ok' => false, 'pesan' => 'Akun gagal dihapus. Coba lagi.'], 500);
}

catat_aktivitas($kon, 'hapus_admin', 'Menghapus admin "' . $target['username'] . '" (' . $target['nama'] . ')', $sayaId);

json_out(['ok' => true, 'pesan' => 'Akun admin "' . $target['nama'] . '" berhasil dihapus.', 'admin_id' => (int)$adminId]);
