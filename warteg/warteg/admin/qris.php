<?php
// =====================================================================
// Pengaturan QRIS — unggah/ganti gambar QRIS STATIS milik toko.
// KHUSUS OWNER (konfigurasi pembayaran bersifat sensitif).
// Gambar QRIS ini bersifat STATIS: nominal TIDAK otomatis terisi,
// customer wajib memasukkan nominal manual. Disimpan sebagai file di
// uploads/qris/ dan path nama file di tabel konfigurasi_toko.
// =====================================================================
require_once __DIR__ . '/../includes/bootstrap.php';
wajib_owner();

$galat = '';
$info  = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_cek();
    if (!is_owner($kon)) { http_response_code(403); exit('Akses ditolak.'); }

    // Hapus QRIS
    if (($_POST['aksi'] ?? '') === 'hapus') {
        $lama = konfigurasi_toko($kon, 'qris_gambar');
        simpan_konfigurasi($kon, 'qris_gambar', null, admin_login_id());
        if ($lama) { $p = __DIR__ . '/../uploads/qris/' . basename($lama); if (is_file($p)) @unlink($p); }
        catat_aktivitas($kon, 'hapus_qris', 'Menghapus gambar QRIS statis');
        $_SESSION['flash'] = ['ok', 'Gambar QRIS berhasil dihapus.'];
        header('Location: ' . url('admin/qris.php')); exit;
    }

    // Unggah / ganti QRIS
    if (!empty($_FILES['qris_gambar']['name'])) {
        [$nama, $err] = simpan_qris_gambar($_FILES['qris_gambar']);
        if ($err) {
            $galat = $err;
        } else {
            $lama = konfigurasi_toko($kon, 'qris_gambar');
            simpan_konfigurasi($kon, 'qris_gambar', $nama, admin_login_id());
            // Hapus file lama SETELAH DB sukses.
            if ($lama && $lama !== $nama) { $p = __DIR__ . '/../uploads/qris/' . basename($lama); if (is_file($p)) @unlink($p); }
            catat_aktivitas($kon, 'ubah_qris', 'Memperbarui gambar QRIS statis');
            $_SESSION['flash'] = ['ok', 'Gambar QRIS berhasil diperbarui.'];
            header('Location: ' . url('admin/qris.php')); exit;
        }
    } else {
        $galat = 'Pilih file gambar QRIS terlebih dahulu.';
    }
}

// Simpan teks info merchant
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['aksi'] ?? '') === 'simpan_info') {
    csrf_cek();
    $nama = trim((string)($_POST['qris_nama_merchant'] ?? ''));
    $nmid = trim((string)($_POST['qris_nmid'] ?? ''));
    $inst = trim((string)($_POST['qris_instruksi'] ?? ''));
    $batas = (int)($_POST['batas_bayar_jam'] ?? 24);
    if ($batas < 1 || $batas > 720) $batas = 24;
    simpan_konfigurasi($kon, 'qris_nama_merchant', mb_substr($nama, 0, 100), admin_login_id());
    simpan_konfigurasi($kon, 'qris_nmid', mb_substr($nmid, 0, 50), admin_login_id());
    simpan_konfigurasi($kon, 'qris_instruksi', mb_substr($inst, 0, 500), admin_login_id());
    simpan_konfigurasi($kon, 'batas_bayar_jam', (string)$batas, admin_login_id());
    catat_aktivitas($kon, 'ubah_info_qris', 'Memperbarui info QRIS');
    $_SESSION['flash'] = ['ok', 'Informasi QRIS berhasil disimpan.'];
    header('Location: ' . url('admin/qris.php')); exit;
}

$flash = $_SESSION['flash'] ?? null;
unset($_SESSION['flash']);

$gambar = konfigurasi_toko($kon, 'qris_gambar');
$nama_merchant = konfigurasi_toko($kon, 'qris_nama_merchant', NAMA_WARTEG);
$nmid = konfigurasi_toko($kon, 'qris_nmid', '');
$instruksi = konfigurasi_toko($kon, 'qris_instruksi', '');
$batas_jam = konfigurasi_toko($kon, 'batas_bayar_jam', '24');

$judul = 'Pengaturan QRIS';
$menu_aktif = 'qris';
require __DIR__ . '/../includes/layout_atas.php';
?>
<?php if ($flash): ?>
    <div class="alert <?= $flash[0] === 'ok' ? 'alert-sukses' : 'alert-galat' ?>" role="status"
         style="<?= $flash[0] === 'ok' ? 'background:#dcfce7;color:#15803d' : '' ?>"><?= e($flash[1]) ?></div>
<?php endif; ?>
<?php if ($galat): ?><div class="alert alert-galat" role="alert"><?= e($galat) ?></div><?php endif; ?>

<section class="grid-dua">
    <div class="content-card">
        <div class="card-header"><h2>Gambar QRIS Statis</h2><span class="teks-redup">Nominal diisi manual oleh customer</span></div>
        <div class="card-body">
            <div class="alert alert-info" style="font-size:13px;margin-bottom:16px;">
                <div>
                    <strong>QRIS Statis</strong> — gambar ini ditampilkan ke semua customer untuk pembayaran.
                    Customer akan memasukkan <em>nominal pembayaran secara manual</em> di aplikasi bank/e-wallet.
                    Sistem TIDAK mengubah gambar ini menjadi QRIS dinamis.
                </div>
            </div>

            <?php if ($gambar): ?>
                <div style="text-align:center;margin-bottom:16px;">
                    <img src="<?= e(url('uploads/qris/' . rawurlencode($gambar))) ?>" alt="QRIS Statis" style="max-width:280px;width:100%;height:auto;border-radius:10px;border:1px solid #e5e7eb;">
                    <p class="bantuan" style="margin-top:8px;">Gambar QRIS aktif</p>
                </div>
                <form method="post" onsubmit="return confirm('Hapus gambar QRIS saat ini? Customer tidak akan bisa melihat QRIS sampai gambar baru diunggah.');" style="margin-bottom:12px;">
                    <input type="hidden" name="csrf" value="<?= e(csrf_token()) ?>">
                    <input type="hidden" name="aksi" value="hapus">
                    <button type="submit" class="btn btn-secondary btn-blok" style="color:#b91c1c;border-color:#fca5a5;">Hapus Gambar QRIS</button>
                </form>
            <?php else: ?>
                <div class="alert alert-peringatan" style="margin-bottom:16px;">Belum ada gambar QRIS. Customer akan melihat "instruksi pembayaran belum tersedia" sampai Anda mengunggah gambar.</div>
            <?php endif; ?>

            <form method="post" enctype="multipart/form-data" action="<?= e(url('admin/qris.php')) ?>">
                <input type="hidden" name="csrf" value="<?= e(csrf_token()) ?>">
                <label class="form-label" for="qris_gambar"><?= $gambar ? 'Ganti gambar QRIS' : 'Unggah gambar QRIS' ?></label>
                <input class="form-control" type="file" id="qris_gambar" name="qris_gambar" accept="image/jpeg,image/png,image/webp" required>
                <p class="bantuan">JPG, PNG, atau WebP. Maksimal 5 MB. Gunakan gambar QRIS resmi dari penyedia (bukan hasil rekayasa).</p>
                <button class="btn btn-primary btn-blok" type="submit"><?= $gambar ? 'Perbarui Gambar QRIS' : 'Unggah Gambar QRIS' ?></button>
            </form>
        </div>
    </div>

    <div class="content-card">
        <div class="card-header"><h2>Informasi QRIS</h2></div>
        <div class="card-body">
            <form method="post" action="<?= e(url('admin/qris.php')) ?>">
                <input type="hidden" name="csrf" value="<?= e(csrf_token()) ?>">
                <input type="hidden" name="aksi" value="simpan_info">
                <label class="form-label" for="qris_nama_merchant">Nama merchant</label>
                <input class="form-control" id="qris_nama_merchant" name="qris_nama_merchant" maxlength="100" value="<?= e((string)$nama_merchant) ?>">

                <label class="form-label" for="qris_nmid">NMID (opsional)</label>
                <input class="form-control" id="qris_nmid" name="qris_nmid" maxlength="50" value="<?= e((string)$nmid) ?>" placeholder="IDxxxxxxxxxxxx">

                <label class="form-label" for="qris_instruksi">Instruksi pembayaran</label>
                <textarea class="form-control" id="qris_instruksi" name="qris_instruksi" rows="4" maxlength="500"><?= e((string)$instruksi) ?></textarea>
                <p class="bantuan">Tampil di halaman pembayaran. Ingatkan customer memasukkan nominal manual.</p>

                <label class="form-label" for="batas_bayar_jam">Batas waktu pembayaran (jam)</label>
                <input class="form-control" type="number" id="batas_bayar_jam" name="batas_bayar_jam" min="1" max="720" value="<?= e((string)$batas_jam) ?>">

                <button class="btn btn-primary btn-blok" type="submit">Simpan Informasi</button>
            </form>
        </div>
    </div>
</section>
<?php require __DIR__ . '/../includes/layout_bawah.php';
