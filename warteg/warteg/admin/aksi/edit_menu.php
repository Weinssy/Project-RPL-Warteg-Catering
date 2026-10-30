<?php
require_once __DIR__ . '/../../includes/bootstrap.php';

if ($_SERVER['REQUEST_METHOD'] !== 'POST') json_out(['ok' => false, 'pesan' => 'Metode tidak diizinkan.'], 405);
wajib_login(true);
csrf_cek(true);

const MAKS_FOTO_EDIT = 2 * 1024 * 1024; // 2 MB, sama dengan form tambah menu

$menuId   = filter_var($_POST['menu_id'] ?? null, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
$nama     = trim((string)($_POST['nama'] ?? ''));
$kategori = (string)($_POST['kategori'] ?? '');
$harga    = filter_var($_POST['harga'] ?? null, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1, 'max_range' => 1000000]]);
$aktif    = $_POST['aktif'] ?? '1';
$hapusFoto = ($_POST['hapus_foto'] ?? '0') === '1';
$tgl      = valid_tanggal($_POST['tanggal'] ?? null) ?? date('Y-m-d');

// Stok: kosong = tanpa batas (NULL). Selain itu harus bilangan bulat 0..STOK_MAKS (maks 120 box).
// Divalidasi manual (regex) karena filter_var(FILTER_VALIDATE_INT) menerima awalan spasi/format aneh.
// Validasi frontend tidak cukup: request langsung ke endpoint ini tetap harus melewati aturan yang sama.
$stokRaw = trim((string)($_POST['stok'] ?? ''));
$stok = null;
if ($stokRaw !== '') {
    if (!preg_match('/^\d+$/', $stokRaw)) {
        json_out(['ok' => false, 'pesan' => 'Stok harus berupa bilangan bulat (tidak boleh desimal atau negatif).'], 422);
    }
    $stok = (int)$stokRaw;
    if ($stok > STOK_MAKS) {
        json_out(['ok' => false, 'pesan' => 'Stok maksimal ' . STOK_MAKS . ' box per menu per tanggal.'], 422);
    }
}

// ---------- Validasi input ----------
if (!$menuId)                                                   json_out(['ok' => false, 'pesan' => 'ID menu tidak valid.'], 400);
if ($nama === '' || mb_strlen($nama) > 100)                     json_out(['ok' => false, 'pesan' => 'Nama menu wajib diisi (maksimal 100 karakter).'], 422);
if (!in_array($kategori, ['nasi', 'lauk', 'sayur','paket a', 'paket b', 'paket c'], true))       json_out(['ok' => false, 'pesan' => 'Kategori tidak valid.'], 422);
if (!$harga)                                                     json_out(['ok' => false, 'pesan' => 'Harga harus berupa angka antara Rp1 dan Rp1.000.000.'], 422);
if (!in_array($aktif, ['0', '1'], true))                         json_out(['ok' => false, 'pesan' => 'Status ketersediaan tidak valid.'], 422);

$aktif = (int)$aktif;
$foto_baru = null;

try {
    $lama = db_row($kon, 'SELECT id, nama, foto FROM menu WHERE id = ?', [$menuId]);
    if (!$lama) json_out(['ok' => false, 'pesan' => 'Menu tidak ditemukan.'], 404);

    // Nama harus unik (kolom menu.nama punya UNIQUE). Tolak jika dipakai menu lain.
    $bentrok = db_row($kon, 'SELECT id FROM menu WHERE nama = ? AND id <> ?', [$nama, $menuId]);
    if ($bentrok) json_out(['ok' => false, 'pesan' => 'Nama menu "' . $nama . '" sudah dipakai menu lain.'], 409);

    // Foto: jika ada unggahan baru, simpan dulu. Foto lama tetap aman sampai UPDATE berhasil.
    [$foto_baru, $galat_foto] = simpan_foto_upload($_FILES['foto'] ?? [], MAKS_FOTO_EDIT);
    if ($galat_foto) json_out(['ok' => false, 'pesan' => $galat_foto], 422);

    // Stok tidak boleh lebih kecil dari box yang sudah dipesan pada tanggal ini
    // (kalau dibiarkan, data jadi tidak konsisten dan rekap membingungkan).
    if ($stok !== null) {
        $terjual = (int)db_val($kon, "SELECT COALESCE(SUM(d.jumlah),0)
            FROM detail_pesanan d JOIN pesanan p ON p.id = d.pesanan_id
            WHERE p.tanggal_ambil = ? AND d.menu_id = ?", [$tgl, $menuId]);
        if ($stok < $terjual) {
            json_out(['ok' => false, 'pesan' => 'Stok tidak boleh lebih kecil dari ' . $terjual
                . ' box yang sudah dipesan pada ' . tanggal_id($tgl, false) . '.'], 422);
        }
    }

    $foto_final = $lama['foto'];
    if ($foto_baru !== null)      $foto_final = $foto_baru;      // ganti dengan foto baru
    elseif ($hapusFoto)           $foto_final = null;            // hapus foto tanpa pengganti

    $kon->begin_transaction();

    // Stok disimpan di menu_harian (jatah per tanggal). Baris dibuat bila belum ada,
    // tanpa mengubah status `tersedia` yang sudah diatur lewat toggle.
    db_exec($kon, 'INSERT INTO menu_harian (menu_id, tanggal, tersedia, stok) VALUES (?, ?, 0, ?)
                   ON DUPLICATE KEY UPDATE stok = VALUES(stok)', [$menuId, $tgl, $stok]);

    if ($foto_baru !== null || $hapusFoto) {
        db_exec($kon, 'UPDATE menu SET nama = ?, kategori = ?, harga = ?, aktif = ?, foto = ? WHERE id = ?',
            [$nama, $kategori, $harga, $aktif, $foto_final, $menuId]);
    } else {
        // Tanpa perubahan foto: biarkan kolom foto apa adanya.
        db_exec($kon, 'UPDATE menu SET nama = ?, kategori = ?, harga = ?, aktif = ? WHERE id = ?',
            [$nama, $kategori, $harga, $aktif, $menuId]);
    }

    $kon->commit();
} catch (Throwable $e) {
    try { $kon->rollback(); } catch (Throwable $x) {}
    if ($foto_baru) @unlink(__DIR__ . '/../../uploads/menu/' . $foto_baru); // jangan tinggalkan file yatim
    error_log('edit_menu gagal: ' . $e->getMessage());
    json_out(['ok' => false, 'pesan' => 'Menu gagal diperbarui. Coba lagi.'], 500);
}

// Hapus file foto lama SETELAH update database berhasil, dan hanya jika tidak lagi dipakai
// di kolom menu.foto maupun menu_harian.foto.
if (($foto_baru !== null || $hapusFoto) && !empty($lama['foto']) && $lama['foto'] !== $foto_final) {
    try {
        $dipakai = (int)db_val($kon, 'SELECT COUNT(*) FROM menu_harian WHERE foto = ?', [$lama['foto']]);
        if ($dipakai === 0) @unlink(__DIR__ . '/../../uploads/menu/' . $lama['foto']);
    } catch (Throwable $e) {
        error_log('hapus foto lama gagal: ' . $e->getMessage());
    }
}

json_out(['ok' => true, 'pesan' => 'Menu "' . $nama . '" berhasil diperbarui.', 'menu_id' => $menuId]);
