<?php
// =====================================================================
// Halaman Profil Admin (akun yang sedang login saja).
// Dibuka dari nama/foto admin di pojok kanan atas dashboard.
// SENGAJA tidak ada di sidebar.
//
// Keamanan:
//   * Semua data & perubahan dibatasi pada $_SESSION['admin_id'] (bukan input klien).
//   * Role TIDAK dapat diubah dari halaman ini (tidak ada field role di POST).
//   * Kata sandi selalu di-hash (password_hash) dan diverifikasi (password_verify).
//   * Token CSRF wajib untuk setiap POST.
// =====================================================================
require_once __DIR__ . '/../includes/bootstrap.php';
wajib_login();

$saya_id = (int)$_SESSION['admin_id'];

// Data akun langsung dari database (sumber kebenaran, bukan sesi / hardcode).
$saya = db_row($kon, 'SELECT * FROM admin WHERE id = ?', [$saya_id]);
if (!$saya) { // akun sudah dihapus saat sesi masih hidup
    $_SESSION = []; session_destroy();
    header('Location: ' . url('admin/login.php')); exit;
}

$galat = '';
$galat_bagian = ''; // bagian accordion yang perlu dibuka jika ada galat
$form = [];         // nilai isian terakhir agar tidak hilang saat validasi gagal

// ---------- Proses POST ----------
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_cek();
    $aksi = (string)($_POST['aksi'] ?? '');

    // ===== A. Informasi pribadi (nama, tanggal lahir, jenis kelamin) =====
    if ($aksi === 'pribadi') {
        $nama   = trim((string)($_POST['nama'] ?? ''));
        $tglRaw = trim((string)($_POST['tanggal_lahir'] ?? ''));
        $gender = (string)($_POST['jenis_kelamin'] ?? '');
        $form   = ['nama' => $nama, 'tanggal_lahir' => $tglRaw, 'jenis_kelamin' => $gender];
        $galat_bagian = 'pribadi';

        $tgl = null;
        if ($tglRaw !== '') {
            $tgl = valid_tanggal($tglRaw);
            if ($tgl === null)                                                       $galat = 'Format tanggal lahir tidak valid (YYYY-MM-DD).';
            elseif ($tgl > date('Y-m-d'))                                            $galat = 'Tanggal lahir tidak boleh di masa depan.';
            elseif ((new DateTime($tgl))->diff(new DateTime(date('Y-m-d')))->y > 120) $galat = 'Tanggal lahir tidak wajar (lebih dari 120 tahun).';
        }
        if ($galat === '' && ($nama === '' || mb_strlen($nama) > 100))               $galat = 'Nama lengkap wajib diisi (maksimal 100 karakter).';
        if ($galat === '' && $gender !== '' && !in_array($gender, ['L', 'P'], true)) $galat = 'Jenis kelamin tidak valid.';

        if ($galat === '') {
            try {
                db_exec($kon, 'UPDATE admin SET nama = ?, tanggal_lahir = ?, jenis_kelamin = ?, diubah_pada = NOW() WHERE id = ?',
                    [$nama, $tgl, $gender === '' ? null : $gender, $saya_id]);
                segarkan_sesi_admin();
                catat_aktivitas($kon, 'ubah_profil', 'Memperbarui informasi pribadi');
                $_SESSION['flash'] = ['ok', 'Informasi pribadi berhasil disimpan.'];
                header('Location: ' . url('admin/profil.php')); exit;
            } catch (Throwable $e) {
                error_log('ubah pribadi gagal: ' . $e->getMessage());
                $galat = 'Perubahan gagal disimpan. Coba lagi.';
            }
        }
    }

    // ===== A2. Foto profil =====
    if ($aksi === 'foto') {
        $galat_bagian = 'pribadi';
        [$foto_baru, $galat] = simpan_foto_admin($_FILES['foto'] ?? []);
        if ($galat === null) $galat = '';
        if ($galat === '' && $foto_baru === null) $galat = 'Pilih file foto terlebih dahulu.';

        if ($galat === '') {
            $lama = $saya['foto'] ?? null;
            try {
                $kon->begin_transaction();
                db_exec($kon, 'UPDATE admin SET foto = ?, diubah_pada = NOW() WHERE id = ?', [$foto_baru, $saya_id]);
                $kon->commit();
            } catch (Throwable $e) {
                try { $kon->rollback(); } catch (Throwable $x) {}
                if ($foto_baru) @unlink(folder_foto_admin() . '/' . $foto_baru); // jangan tinggalkan file yatim
                error_log('ubah foto profil gagal: ' . $e->getMessage());
                $galat = 'Foto profil gagal disimpan. Foto lama tetap dipakai.';
            }
            if ($galat === '') {
                segarkan_sesi_admin(); // header & lightbox langsung memakai foto baru
                // Hapus file lama SETELAH database berhasil, hanya bila tidak dipakai akun lain.
                if ($lama && $lama !== $foto_baru) hapus_foto_admin_file($kon, $lama);
                catat_aktivitas($kon, 'ubah_foto', 'Mengganti foto profil');
                $_SESSION['flash'] = ['ok', 'Foto profil berhasil diperbarui.'];
                header('Location: ' . url('admin/profil.php')); exit;
            }
        }
    }

    // ===== B. Informasi kontak =====
    if ($aksi === 'kontak') {
        $email  = trim((string)($_POST['email'] ?? ''));
        $telp   = trim((string)($_POST['telepon'] ?? ''));
        $alamat = trim((string)($_POST['alamat'] ?? ''));
        $form   = ['email' => $email, 'telepon' => $telp, 'alamat' => $alamat];
        $galat_bagian = 'kontak';

        if ($email !== '' && !filter_var($email, FILTER_VALIDATE_EMAIL)) $galat = 'Format email tidak valid.';
        elseif ($email !== '' && mb_strlen($email) > 150)               $galat = 'Email maksimal 150 karakter.';
        elseif ($telp !== '' && !telepon_valid($telp))                  $galat = 'Nomor telepon tidak valid (8-15 digit angka).';
        elseif ($alamat !== '' && mb_strlen($alamat) > 255)             $galat = 'Alamat maksimal 255 karakter.';

        if ($galat === '') {
            $telp_simpan = $telp === '' ? null : preg_replace('/[^\d+]/', '', $telp);
            try {
                db_exec($kon, 'UPDATE admin SET email = ?, telepon = ?, alamat = ?, diubah_pada = NOW() WHERE id = ?',
                    [$email === '' ? null : $email, $telp_simpan, $alamat === '' ? null : $alamat, $saya_id]);
                catat_aktivitas($kon, 'ubah_kontak', 'Memperbarui informasi kontak');
                $_SESSION['flash'] = ['ok', 'Informasi kontak berhasil disimpan.'];
                header('Location: ' . url('admin/profil.php')); exit;
            } catch (Throwable $e) {
                error_log('ubah kontak gagal: ' . $e->getMessage());
                $galat = 'Perubahan gagal disimpan. Coba lagi.';
            }
        }
    }

    // ===== C. Ubah kata sandi =====
    if ($aksi === 'sandi') {
        $galat_bagian = 'kredensial';
        $lama = (string)($_POST['sandi_lama'] ?? '');
        $baru = (string)($_POST['sandi_baru'] ?? '');
        $konf = (string)($_POST['sandi_konfirmasi'] ?? '');

        // Ambil hash segar dari database (bukan dari sesi) untuk verifikasi.
        $cek = db_row($kon, 'SELECT password_hash FROM admin WHERE id = ?', [$saya_id]);
        if (!$cek)                                                             $galat = 'Akun tidak ditemukan.';
        elseif ($lama === '' || !password_verify($lama, $cek['password_hash'])) $galat = 'Kata sandi lama salah.';
        elseif (strlen($baru) < 8)                                             $galat = 'Kata sandi baru minimal 8 karakter.';
        elseif (strlen($baru) > 72)                                            $galat = 'Kata sandi baru maksimal 72 karakter.';
        elseif ($baru !== $konf)                                               $galat = 'Konfirmasi kata sandi tidak cocok.';
        elseif ($baru === $lama)                                               $galat = 'Kata sandi baru harus berbeda dari kata sandi lama.';

        if ($galat === '') {
            try {
                $hash = password_hash($baru, PASSWORD_DEFAULT);
                // Bandingkan dengan hash lama agar tidak menimpa perubahan bersamaan.
                $n = db_exec($kon, 'UPDATE admin SET password_hash = ?, diubah_pada = NOW() WHERE id = ? AND password_hash = ?',
                    [$hash, $saya_id, $cek['password_hash']]);
                if ($n === 0) {
                    $galat = 'Kata sandi sudah berubah di sesi lain. Silakan coba lagi.';
                } else {
                    // Kebijakan sesi aman: catat aktivitas, hancurkan sesi,
                    // lalu minta login ulang dengan kata sandi baru.
                    catat_aktivitas($kon, 'ubah_sandi', 'Kata sandi akun diubah');
                    $_SESSION = [];
                    session_destroy();
                    mulai_sesi();
                    session_regenerate_id(true);
                    $_SESSION['flash_login'] = 'Kata sandi berhasil diubah. Silakan masuk kembali dengan kata sandi baru.';
                    header('Location: ' . url('admin/login.php')); exit;
                }
            } catch (Throwable $e) {
                error_log('ubah sandi gagal: ' . $e->getMessage());
                $galat = 'Kata sandi gagal diubah. Coba lagi.';
            }
        }
    }

    // ===== D. Preferensi (bahasa + notifikasi) =====
    if ($aksi === 'preferensi') {
        $galat_bagian = 'preferensi';
        $bahasa = (string)($_POST['bahasa'] ?? 'id');
        $notifP = isset($_POST['notif_pesanan']) ? 1 : 0;
        $notifS = isset($_POST['notif_stok']) ? 1 : 0;

        if (!in_array($bahasa, ['id', 'en'], true)) $galat = 'Pilihan bahasa tidak valid.';

        if ($galat === '') {
            try {
                db_exec($kon, 'UPDATE admin SET bahasa = ?, notif_pesanan = ?, notif_stok = ?, diubah_pada = NOW() WHERE id = ?',
                    [$bahasa, $notifP, $notifS, $saya_id]);
                segarkan_sesi_admin(); // bahasa antarmuka langsung diterapkan
                catat_aktivitas($kon, 'ubah_preferensi',
                    'Bahasa: ' . ($bahasa === 'en' ? 'English' : 'Indonesia')
                    . '; notifikasi pesanan: ' . ($notifP ? 'aktif' : 'nonaktif')
                    . '; notifikasi stok: ' . ($notifS ? 'aktif' : 'nonaktif'));
                $_SESSION['flash'] = ['ok', 'Preferensi berhasil disimpan.'];
                header('Location: ' . url('admin/profil.php')); exit;
            } catch (Throwable $e) {
                error_log('ubah preferensi gagal: ' . $e->getMessage());
                $galat = 'Preferensi gagal disimpan. Coba lagi.';
            }
        }
    }
}

$flash = $_SESSION['flash'] ?? null;
unset($_SESSION['flash']);

// Nilai tampil: prioritaskan isian POST yang gagal validasi, lalu data database.
function nilai_form(array $form, array $row, string $kunci, $default = '') {
    if (array_key_exists($kunci, $form)) return $form[$kunci];
    return $row[$kunci] ?? $default;
}

// Label jenis aktivitas untuk riwayat (hanya data yang benar-benar tercatat).
function label_aksi_aktivitas(string $aksi, string $bhs): string {
    $peta = [
        'login'           => ['Masuk ke sistem', 'Signed in'],
        'logout'          => ['Keluar dari sistem', 'Signed out'],
        'ubah_profil'     => ['Memperbarui informasi pribadi', 'Updated personal information'],
        'ubah_kontak'     => ['Memperbarui informasi kontak', 'Updated contact information'],
        'ubah_foto'       => ['Mengganti foto profil', 'Changed profile photo'],
        'ubah_sandi'      => ['Mengubah kata sandi', 'Changed password'],
        'ubah_preferensi' => ['Mengubah preferensi', 'Updated preferences'],
        'tambah_admin'    => ['Menambahkan admin baru', 'Added a new admin'],
        'ubah_menu'       => ['Mengubah menu', 'Updated a menu'],
        'hapus_menu'      => ['Menghapus menu', 'Deleted a menu'],
    ];
    return ($peta[$aksi] ?? [$aksi, $aksi])[$bhs === 'en' ? 1 : 0];
}

$bhs         = bahasa_aktif();
$url_foto    = url_foto_admin($saya['foto'], versi_foto_admin($saya['foto'], $saya['diubah_pada'] ?? null));
$pemilik     = (($saya['role'] ?? 'admin') === 'owner');
$label_peran = label_role($saya['role'] ?? 'admin', $bhs);

// Riwayat aktivitas (hanya data yang benar-benar tercatat — tidak ada contoh/mock).
$riwayat = [];
try {
    $riwayat = db_all($kon, 'SELECT aksi, keterangan, dibuat_pada FROM aktivitas_admin
        WHERE admin_id = ? ORDER BY dibuat_pada DESC, id DESC LIMIT 25', [$saya_id]);
} catch (Throwable $e) {
    error_log('muat riwayat gagal: ' . $e->getMessage());
}

$judul      = t('profil.judul', $bhs);
$menu_aktif = 'profil'; // tidak ada di sidebar
require __DIR__ . '/../includes/layout_atas.php';

// Helper merender kepala accordion (judul + ikon indikator buka/tutup).
function kepala_accordion(string $id, string $label, string $ikon, bool $buka): void { ?>
    <button type="button" class="accordion-kepala" aria-expanded="<?= $buka ? 'true' : 'false' ?>" aria-controls="isi-<?= e($id) ?>">
        <span class="kartu-kepala-teks">
            <svg width="20" height="20" fill="none" stroke="currentColor" viewBox="0 0 24 24" aria-hidden="true" style="color:var(--redup)"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.6" d="<?= e($ikon) ?>"/></svg>
            <span><?= e($label) ?></span>
        </span>
        <svg class="accordion-ikon" fill="none" stroke="currentColor" viewBox="0 0 24 24" aria-hidden="true"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M19 9l-7 7-7-7"/></svg>
    </button>
<?php }
?>

<?php if ($flash): ?>
    <div class="alert <?= $flash[0] === 'ok' ? 'alert-sukses' : 'alert-galat' ?>" role="status"
         style="<?= $flash[0] === 'ok' ? 'background:#dcfce7;color:#15803d' : '' ?>"><?= e($flash[1]) ?></div>
<?php endif; ?>
<?php if ($galat): ?>
    <div class="alert alert-galat" role="alert" id="galatAtas"><?= e($galat) ?></div>
<?php endif; ?>

<section class="content-card" style="margin-bottom:20px">
    <div class="card-body">
        <div class="profil-head">
            <?php // Foto di halaman profil dapat diklik untuk memperbesar (data-lightbox). ?>
            <?php if ($url_foto): ?>
                <img src="<?= e($url_foto) ?>" alt="Foto profil <?= e($saya['nama']) ?>"
                     class="avatar avatar-besar" data-lightbox="<?= e($url_foto) ?>" tabindex="0" role="button"
                     title="<?= e(t('topbar.foto', $bhs)) ?>">
            <?php else: ?>
                <span class="avatar avatar-inisial avatar-besar" aria-hidden="true"><?= e(inisial_dari($saya['nama'], $saya['username'])) ?></span>
            <?php endif; ?>
            <div class="profil-head-info">
                <h2><?= e($saya['nama']) ?></h2>
                <p class="teks-redup">@<?= e($saya['username']) ?></p>
                <div class="profil-meta">
                    <span class="role-badge<?= $pemilik ? ' owner' : '' ?>"><?= e($label_peran) ?></span>
                    <span class="teks-redup"><?= e(t('profil.akun', $bhs)) ?></span>
                </div>
            </div>
            <a class="btn btn-secondary btn-kecil" href="<?= e(url('admin/index.php')) ?>">
                <svg width="16" height="16" fill="none" stroke="currentColor" viewBox="0 0 24 24" aria-hidden="true"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M10 19l-7-7m0 0l7-7m-7 7h18"/></svg>
                <?= e(t('common.kembali', $bhs)) ?>
            </a>
        </div>
        <p class="teks-redup" style="margin-top:14px"><?= e(t('profil.subjudul', $bhs)) ?></p>
    </div>
</section>

<!-- ===== A. Informasi Pribadi ===== -->
<section class="accordion" data-accordion="pribadi" data-terbuka="1">
    <?php kepala_accordion('pribadi', t('profil.pribadi', $bhs), 'M16 7a4 4 0 11-8 0 4 4 0 018 0zM12 14a7 7 0 00-7 7h14a7 7 0 00-7-7', true); ?>
    <div class="accordion-isi" id="isi-pribadi">
        <form method="post" action="<?= e(url('admin/profil.php')) ?>" autocomplete="off">
            <input type="hidden" name="csrf" value="<?= e(csrf_token()) ?>">
            <input type="hidden" name="aksi" value="pribadi">
            <div class="form-grid-dua">
                <div>
                    <label class="form-label" for="pfNama"><?= e(t('profil.nama', $bhs)) ?></label>
                    <input class="form-control" id="pfNama" name="nama" maxlength="100" required
                           value="<?= e(nilai_form($form, $saya, 'nama')) ?>">
                </div>
                <div>
                    <label class="form-label" for="pfJk"><?= e(t('profil.jk', $bhs)) ?></label>
                    <select class="form-select" id="pfJk" name="jenis_kelamin">
                        <option value="">&mdash; <?= e(t('common.belum_diisi', $bhs)) ?> &mdash;</option>
                        <option value="L" <?= nilai_form($form, $saya, 'jenis_kelamin') === 'L' ? 'selected' : '' ?>><?= e(t('profil.jk_l', $bhs)) ?></option>
                        <option value="P" <?= nilai_form($form, $saya, 'jenis_kelamin') === 'P' ? 'selected' : '' ?>><?= e(t('profil.jk_p', $bhs)) ?></option>
                    </select>
                </div>
                <div>
                    <label class="form-label" for="pfTgl"><?= e(t('profil.tgl_lahir', $bhs)) ?></label>
                    <input class="form-control" id="pfTgl" name="tanggal_lahir" type="date" max="<?= e(date('Y-m-d')) ?>"
                           value="<?= e(nilai_form($form, $saya, 'tanggal_lahir')) ?>">
                </div>
            </div>
            <div class="form-aksi">
                <a class="btn btn-secondary" href="<?= e(url('admin/profil.php')) ?>"><?= e(t('common.batal', $bhs)) ?></a>
                <button class="btn btn-primary" type="submit"><?= e(t('common.simpan', $bhs)) ?></button>
            </div>
        </form>

        <hr style="border:0;border-top:1px solid #f1f5f9;margin:22px 0">

        <form method="post" action="<?= e(url('admin/profil.php')) ?>" enctype="multipart/form-data" autocomplete="off">
            <input type="hidden" name="csrf" value="<?= e(csrf_token()) ?>">
            <input type="hidden" name="aksi" value="foto">
            <label class="form-label" style="margin-top:0"><?= e(t('profil.foto', $bhs)) ?></label>
            <div class="foto-sunting">
                <?php if ($url_foto): ?>
                    <img id="pratinjauFotoProfil" class="admin-foto-preview" src="<?= e($url_foto) ?>"
                         alt="Foto profil saat ini" data-lightbox="<?= e($url_foto) ?>" tabindex="0" role="button" title="<?= e(t('topbar.foto', $bhs)) ?>">
                <?php else: ?>
                    <span class="admin-foto-preview avatar-inisial" id="pratinjauFotoProfil" aria-hidden="true"><?= e(inisial_dari($saya['nama'], $saya['username'])) ?></span>
                <?php endif; ?>
                <div style="flex:1;min-width:200px">
                    <input class="form-control" type="file" id="pfFoto" name="foto" accept="image/jpeg,image/png,image/webp" required>
                    <p class="teks-redup" style="margin-top:6px">JPG, PNG, atau WebP &middot; maksimal 2 MB. Pratinjau tampil sebelum disimpan.</p>
                </div>
            </div>
            <div class="form-aksi">
                <a class="btn btn-secondary" href="<?= e(url('admin/profil.php')) ?>"><?= e(t('common.batal', $bhs)) ?></a>
                <button class="btn btn-primary" type="submit"><?= e(t('common.simpan', $bhs)) ?></button>
            </div>
        </form>
    </div>
</section>

<!-- ===== B. Informasi Kontak ===== -->
<section class="accordion" data-accordion="kontak">
    <?php kepala_accordion('kontak', t('profil.kontak', $bhs), 'M3 5a2 2 0 012-2h3.28a1 1 0 01.948.684l1.498 4.493a1 1 0 01-.502 1.21l-2.257 1.13a11.042 11.042 0 005.516 5.516l1.13-2.257a1 1 0 011.21-.502l4.493 1.498a1 1 0 01.684.949V19a2 2 0 01-2 2h-1C9.716 21 3 14.284 3 6V5z', false); ?>
    <div class="accordion-isi" id="isi-kontak">
        <?php // Data tersimpan ditampilkan; yang belum ada ditandai "Belum diisi" secara jelas (bukan nilai palsu). ?>
        <dl class="fakta-grid" aria-label="Data kontak tersimpan">
            <dt><?= e(t('profil.email', $bhs)) ?></dt>
            <dd><?= !empty($saya['email']) ? e($saya['email']) : '<span class="nilai-kosong">' . e(t('common.belum_diisi', $bhs)) . '</span>' ?></dd>
            <dt><?= e(t('profil.telepon', $bhs)) ?></dt>
            <dd><?= !empty($saya['telepon']) ? e($saya['telepon']) : '<span class="nilai-kosong">' . e(t('common.belum_diisi', $bhs)) . '</span>' ?></dd>
            <dt><?= e(t('profil.alamat', $bhs)) ?></dt>
            <dd><?= !empty($saya['alamat']) ? nl2br(e($saya['alamat'])) : '<span class="nilai-kosong">' . e(t('common.belum_diisi', $bhs)) . '</span>' ?></dd>
        </dl>
        <form method="post" action="<?= e(url('admin/profil.php')) ?>" autocomplete="off">
            <input type="hidden" name="csrf" value="<?= e(csrf_token()) ?>">
            <input type="hidden" name="aksi" value="kontak">
            <div class="form-grid-dua">
                <div>
                    <label class="form-label" for="pfEmail"><?= e(t('profil.email', $bhs)) ?></label>
                    <input class="form-control" id="pfEmail" name="email" type="email" maxlength="150"
                           placeholder="nama@contoh.com" value="<?= e(nilai_form($form, $saya, 'email')) ?>">
                </div>
                <div>
                    <label class="form-label" for="pfTelp"><?= e(t('profil.telepon', $bhs)) ?></label>
                    <input class="form-control" id="pfTelp" name="telepon" type="tel" maxlength="20"
                           placeholder="08xxxxxxxxxx" value="<?= e(nilai_form($form, $saya, 'telepon')) ?>">
                </div>
            </div>
            <label class="form-label" for="pfAlamat"><?= e(t('profil.alamat', $bhs)) ?></label>
            <textarea class="form-control" id="pfAlamat" name="alamat" rows="3" maxlength="255"><?= e(nilai_form($form, $saya, 'alamat')) ?></textarea>
            <div class="form-aksi">
                <a class="btn btn-secondary" href="<?= e(url('admin/profil.php')) ?>"><?= e(t('common.batal', $bhs)) ?></a>
                <button class="btn btn-primary" type="submit"><?= e(t('common.simpan', $bhs)) ?></button>
            </div>
        </form>
    </div>
</section>

<!-- ===== C. Kredensial Akun ===== -->
<section class="accordion" data-accordion="kredensial">
    <?php kepala_accordion('kredensial', t('profil.kredensial', $bhs), 'M15 7a2 2 0 012 2m4 0a6 6 0 01-7.743 5.743L11 17H9v2H7v2H4a1 1 0 01-1-1v-2.586a1 1 0 01.293-.707l5.964-5.964A6 6 0 1121 9z', false); ?>
    <div class="accordion-isi" id="isi-kredensial">
        <dl class="fakta-grid">
            <dt><?= e(t('profil.username', $bhs)) ?></dt>
            <dd><code><?= e($saya['username']) ?></code></dd>
            <dt><?= e(t('profil.role', $bhs)) ?></dt>
            <dd><span class="role-badge<?= $pemilik ? ' owner' : '' ?>"><?= e($label_peran) ?></span></dd>
            <dt><?= e(t('profil.sandi_baru', $bhs)) ?></dt>
            <dd class="teks-redup">&#8226;&#8226;&#8226;&#8226;&#8226;&#8226;&#8226;&#8226; &mdash; <?= $bhs === 'en' ? 'stored as a secure hash' : 'disimpan sebagai hash aman' ?></dd>
        </dl>
        <p class="teks-redup" style="margin:6px 0 2px">
            <?= $bhs === 'en'
                ? 'Username and role are informational only. A regular admin cannot upgrade their own role &mdash; role changes are not available on this page.'
                : 'Username dan peran hanya bersifat informasi. Admin biasa tidak dapat menaikkan hak aksesnya sendiri &mdash; perubahan role tidak tersedia di halaman ini.' ?>
        </p>

        <hr style="border:0;border-top:1px solid #f1f5f9;margin:18px 0">

        <form method="post" action="<?= e(url('admin/profil.php')) ?>" autocomplete="off" id="formSandi">
            <input type="hidden" name="csrf" value="<?= e(csrf_token()) ?>">
            <input type="hidden" name="aksi" value="sandi">
            <label class="form-label" for="pfSandiLama" style="margin-top:0"><?= e(t('profil.sandi_lama', $bhs)) ?></label>
            <input class="form-control" id="pfSandiLama" name="sandi_lama" type="password" required autocomplete="current-password">
            <div class="form-grid-dua">
                <div>
                    <label class="form-label" for="pfSandiBaru"><?= e(t('profil.sandi_baru', $bhs)) ?></label>
                    <input class="form-control" id="pfSandiBaru" name="sandi_baru" type="password" minlength="8" maxlength="72" required autocomplete="new-password">
                </div>
                <div>
                    <label class="form-label" for="pfSandiKonf"><?= e(t('profil.sandi_konf', $bhs)) ?></label>
                    <input class="form-control" id="pfSandiKonf" name="sandi_konfirmasi" type="password" minlength="8" maxlength="72" required autocomplete="new-password">
                </div>
            </div>
            <p class="teks-redup" style="margin-top:6px">
                <?= $bhs === 'en'
                    ? 'Minimum 8 characters. After the password changes you will be signed out and asked to sign in again.'
                    : 'Minimal 8 karakter. Setelah kata sandi diubah, Anda akan diminta masuk kembali.' ?>
            </p>
            <div class="form-aksi">
                <a class="btn btn-secondary" href="<?= e(url('admin/profil.php')) ?>"><?= e(t('common.batal', $bhs)) ?></a>
                <button class="btn btn-primary" type="submit"><?= e(t('profil.ubah_sandi', $bhs)) ?></button>
            </div>
        </form>
    </div>
</section>

<!-- ===== D. Preferensi dan Aktivitas ===== -->
<section class="accordion" data-accordion="preferensi">
    <?php kepala_accordion('preferensi', t('profil.preferensi', $bhs), 'M10.325 4.317c.426-1.756 2.924-1.756 3.35 0a1.724 1.724 0 002.573 1.066c1.543-.94 3.31.826 2.37 2.37a1.724 1.724 0 001.065 2.572c1.756.426 1.756 2.924 0 3.35a1.724 1.724 0 00-1.066 2.573c.94 1.543-.826 3.31-2.37 2.37a1.724 1.724 0 00-2.572 1.065c-.426 1.756-2.924 1.756-3.35 0a1.724 1.724 0 00-2.573-1.066c-1.543.94-3.31-.826-2.37-2.37a1.724 1.724 0 00-1.065-2.572c-1.756-.426-1.756-2.924 0-3.35a1.724 1.724 0 001.066-2.573c-.94-1.543.826-3.31 2.37-2.37.996.608 2.296.07 2.572-1.065z', false); ?>
    <div class="accordion-isi" id="isi-preferensi">
        <form method="post" action="<?= e(url('admin/profil.php')) ?>" autocomplete="off">
            <input type="hidden" name="csrf" value="<?= e(csrf_token()) ?>">
            <input type="hidden" name="aksi" value="preferensi">
            <label class="form-label" for="pfBahasa" style="margin-top:0"><?= e(t('profil.bahasa', $bhs)) ?></label>
            <select class="form-select" id="pfBahasa" name="bahasa">
                <option value="id" <?= $saya['bahasa'] === 'id' ? 'selected' : '' ?>>Bahasa Indonesia (default)</option>
                <option value="en" <?= $saya['bahasa'] === 'en' ? 'selected' : '' ?>>English</option>
            </select>
            <p class="teks-redup" style="margin-top:6px">
                <?= $bhs === 'en'
                    ? 'Applies to the sidebar navigation, the top bar, and this profile page. Other admin pages remain in Indonesian.'
                    : 'Diterapkan pada navigasi sidebar, bilah atas, dan halaman profil ini. Halaman admin lain tetap berbahasa Indonesia.' ?>
            </p>

            <label class="form-label"><?= e(t('profil.notif', $bhs)) ?></label>
            <label style="display:flex;align-items:center;gap:10px;font-size:14px;margin-bottom:8px">
                <input type="checkbox" name="notif_pesanan" value="1" <?= !empty($saya['notif_pesanan']) ? 'checked' : '' ?>>
                <span><?= e(t('profil.notif_pesan', $bhs)) ?></span>
            </label>
            <label style="display:flex;align-items:center;gap:10px;font-size:14px">
                <input type="checkbox" name="notif_stok" value="1" <?= !empty($saya['notif_stok']) ? 'checked' : '' ?>>
                <span><?= e(t('profil.notif_stok', $bhs)) ?></span>
            </label>
            <p class="teks-redup" style="margin-top:6px">
                <?= $bhs === 'en'
                    ? 'Preferences are saved and used by the system as the basis for order and stock notifications.'
                    : 'Preferensi disimpan dan digunakan sistem sebagai dasar notifikasi pesanan serta stok menu.' ?>
            </p>

            <div class="form-aksi">
                <a class="btn btn-secondary" href="<?= e(url('admin/profil.php')) ?>"><?= e(t('common.batal', $bhs)) ?></a>
                <button class="btn btn-primary" type="submit"><?= e(t('common.simpan', $bhs)) ?></button>
            </div>
        </form>

        <hr style="border:0;border-top:1px solid #f1f5f9;margin:20px 0">

        <h3 style="font-size:15px;margin-bottom:10px"><?= e(t('profil.aktivitas', $bhs)) ?></h3>
        <?php if (!$riwayat): ?>
            <p class="teks-redup"><?= e(t('profil.aktivitas_kosong', $bhs)) ?></p>
        <?php else: ?>
        <div class="table-wrapper">
            <table class="tabel-aktivitas">
                <thead><tr>
                    <th><?= e(t('profil.waktu', $bhs)) ?></th>
                    <th><?= e(t('profil.jenis', $bhs)) ?></th>
                    <th><?= e(t('profil.info', $bhs)) ?></th>
                </tr></thead>
                <tbody>
                <?php foreach ($riwayat as $r): ?>
                    <tr>
                        <td style="white-space:nowrap"><?= e(date('d/m/Y H:i', strtotime((string)$r['dibuat_pada']))) ?></td>
                        <td class="aktivitas-aksi"><?= e(label_aksi_aktivitas((string)$r['aksi'], $bhs)) ?></td>
                        <td class="teks-redup"><?= $r['keterangan'] !== '' ? e($r['keterangan']) : '&mdash;' ?></td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
        </div>
        <?php endif; ?>
    </div>
</section>
<?php
$skrip_halaman = <<<'JS'
(function () {
    // Pratinjau foto profil sebelum disimpan. Pemeriksaan di browser hanyalah pelengkap;
    // server tetap memvalidasi ulang MIME, ukuran, dan isi gambar.
    var input = document.getElementById('pfFoto');
    var preview = document.getElementById('pratinjauFotoProfil');
    if (input && preview) {
        input.addEventListener('change', function () {
            var f = input.files && input.files[0];
            if (!f) return;
            if (f.size > 2 * 1024 * 1024) { alert('Ukuran foto maksimal 2 MB.'); input.value = ''; return; }
            if (['image/jpeg', 'image/png', 'image/webp'].indexOf(f.type) === -1) {
                alert('Format foto harus JPG, PNG, atau WebP.'); input.value = ''; return;
            }
            if (preview.tagName === 'IMG') {
                var url = URL.createObjectURL(f);
                preview.src = url;
                preview.setAttribute('data-lightbox', url); // klik pratinjau -> lihat ukuran penuh
            }
        });
    }

    // Konfirmasi kata sandi diperiksa di browser; server memvalidasi ulang.
    var baru = document.getElementById('pfSandiBaru'), konf = document.getElementById('pfSandiKonf');
    if (baru && konf) {
        var cocok = function () {
            konf.setCustomValidity(konf.value && konf.value !== baru.value ? 'Konfirmasi kata sandi tidak cocok.' : '');
        };
        baru.addEventListener('input', cocok);
        konf.addEventListener('input', cocok);
    }
})();
JS;
require __DIR__ . '/../includes/layout_bawah.php';
