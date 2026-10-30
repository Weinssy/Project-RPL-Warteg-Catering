<?php
// =====================================================================
// Manajemen Admin — KHUSUS OWNER.
// Berisi: ubah foto profil sendiri (aksi cepat), tambah admin, dan daftar admin.
// Profil lengkap akun sendiri (nama, kontak, kata sandi, preferensi) ada di
// halaman terpisah admin/profil.php yang dibuka dari nama di pojok kanan atas.
//
// Otorisasi dilakukan di server lewat wajib_owner(): admin biasa yang membuka
// URL ini atau mencoba POST ditolak (HTTP 403) meski menebak URL-nya. Menu di
// sidebar juga disembunyikan untuk non-Owner, tetapi penyembunyian itu hanya kosmetik.
// =====================================================================
require_once __DIR__ . '/../includes/bootstrap.php';
wajib_owner(); // wajib login + wajib Owner (cek role di database)

segarkan_sesi_admin();
$saya_id = (int)$_SESSION['admin_id'];

$galat = '';
$form  = ['nama' => '', 'username' => '', 'email' => '', 'telepon' => '', 'role' => 'admin']; // isian lama saat ada galat

// ---------- Proses POST ----------
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_cek();
    // Cek ulang peran tepat sebelum aksi administratif dijalankan (pertahanan berlapis).
    if (!is_owner($kon)) {
        http_response_code(403);
        exit('Akses ditolak.');
    }
    $aksi = (string)($_POST['aksi'] ?? '');

    // ===== Ubah foto profil sendiri =====
    if ($aksi === 'foto_profil') {
        [$foto_baru, $galat] = simpan_foto_admin($_FILES['foto'] ?? []);
        if ($galat === null) $galat = '';
        if ($galat === '' && $foto_baru === null) $galat = 'Pilih file foto terlebih dahulu.';

        if ($galat === '') {
            $lama = db_row($kon, 'SELECT foto FROM admin WHERE id = ?', [$saya_id]);
            try {
                $kon->begin_transaction();
                db_exec($kon, 'UPDATE admin SET foto = ?, diubah_pada = NOW() WHERE id = ?', [$foto_baru, $saya_id]);
                $kon->commit();
            } catch (Throwable $e) {
                try { $kon->rollback(); } catch (Throwable $x) {}
                if ($foto_baru) @unlink(folder_foto_admin() . '/' . $foto_baru);
                error_log('ubah foto admin gagal: ' . $e->getMessage());
                $galat = 'Foto profil gagal disimpan. Coba lagi.';
            }
            if ($galat === '') {
                segarkan_sesi_admin(); // header langsung memakai foto baru
                // Hapus file lama SETELAH DB berhasil, dan hanya bila tidak dipakai admin lain.
                if (!empty($lama['foto']) && $lama['foto'] !== $foto_baru) {
                    hapus_foto_admin_file($kon, $lama['foto']);
                }
                catat_aktivitas($kon, 'ubah_foto', 'Mengganti foto profil');
                $_SESSION['flash'] = ['ok', 'Foto profil berhasil diperbarui.'];
                header('Location: ' . url('admin/admin.php'));
                exit;
            }
        }
    }

    // ===== Tambah admin baru =====
    if ($aksi === 'tambah_admin') {
        $nama     = trim((string)($_POST['nama'] ?? ''));
        $username = trim((string)($_POST['username'] ?? ''));
        $email    = trim((string)($_POST['email'] ?? ''));
        $telepon  = trim((string)($_POST['telepon'] ?? ''));
        $password = (string)($_POST['password'] ?? '');
        $konfirm  = (string)($_POST['konfirmasi'] ?? '');
        $role     = (string)($_POST['role'] ?? 'admin');
        $form     = ['nama' => $nama, 'username' => $username, 'email' => $email, 'telepon' => $telepon, 'role' => $role];

        if ($nama === '' || mb_strlen($nama) > 100)                          $galat = 'Nama lengkap wajib diisi (maksimal 100 karakter).';
        elseif (!username_valid($username))                                  $galat = 'Username 3-50 karakter, hanya huruf, angka, titik, garis bawah, atau garis hubung.';
        elseif ($email !== '' && !filter_var($email, FILTER_VALIDATE_EMAIL)) $galat = 'Format email tidak valid.';
        elseif ($email !== '' && mb_strlen($email) > 150)                    $galat = 'Email maksimal 150 karakter.';
        elseif ($telepon !== '' && !telepon_valid($telepon))                 $galat = 'Nomor telepon tidak valid (8-15 digit angka).';
        elseif (strlen($password) < 8)                                       $galat = 'Password minimal 8 karakter.';
        elseif (strlen($password) > 72)                                      $galat = 'Password maksimal 72 karakter.'; // batas bcrypt
        elseif ($password !== $konfirm)                                      $galat = 'Konfirmasi password tidak cocok.';
        elseif (!in_array($role, ['admin', 'owner'], true))                  $galat = 'Peran tidak valid.';
        else {
            [$foto_baru, $galat] = simpan_foto_admin($_FILES['foto'] ?? []);
            if ($galat === null) $galat = ''; // samakan null -> string kosong
            // Validasi lengkap dulu; akun hanya dibuat bila semuanya valid (tidak ada akun setengah jadi).
            if ($galat === '') {
                try {
                    $sudah = db_row($kon, 'SELECT id FROM admin WHERE username = ?', [$username]);
                    if ($sudah) {
                        $galat = 'Username sudah dipakai. Pilih username lain.';
                        if ($foto_baru) @unlink(folder_foto_admin() . '/' . $foto_baru);
                    } else {
                        $hash = password_hash($password, PASSWORD_DEFAULT);
                        db_exec($kon, 'INSERT INTO admin (username, password_hash, nama, foto, email, telepon, role) VALUES (?, ?, ?, ?, ?, ?, ?)',
                            [$username, $hash, $nama, $foto_baru, $email === '' ? null : $email, $telepon === '' ? null : $telepon, $role]);
                        catat_aktivitas($kon, 'tambah_admin', 'Menambahkan admin "' . $username . '" (' . $role . ')');
                        $_SESSION['flash'] = ['ok', 'Admin "' . $username . '" berhasil ditambahkan.'];
                        header('Location: ' . url('admin/admin.php'));
                        exit;
                    }
                } catch (mysqli_sql_exception $e) {
                    // 1062 = pelanggaran UNIQUE; bisa terjadi bila dua permintaan nyaris bersamaan.
                    if ((int)$e->getCode() === 1062) $galat = 'Username sudah dipakai. Pilih username lain.';
                    else { error_log('tambah admin gagal: ' . $e->getMessage()); $galat = 'Admin gagal ditambahkan. Coba lagi.'; }
                    if ($foto_baru) @unlink(folder_foto_admin() . '/' . $foto_baru);
                } catch (Throwable $e) {
                    error_log('tambah admin gagal: ' . $e->getMessage());
                    $galat = 'Admin gagal ditambahkan. Coba lagi.';
                    if ($foto_baru) @unlink(folder_foto_admin() . '/' . $foto_baru);
                }
            }
        }
    }
}

$flash = $_SESSION['flash'] ?? null;
unset($_SESSION['flash']);

// ---------- Data daftar admin (tanpa password/hash) ----------
$daftar = db_all($kon, 'SELECT id, nama, username, foto, email, role, diubah_pada, dibuat_pada FROM admin ORDER BY id ASC');

$bhs        = bahasa_aktif();
$judul      = t('nav.admin', $bhs);
$menu_aktif = 'admin';
require __DIR__ . '/../includes/layout_atas.php';
?>

<?php if ($flash): ?>
    <div class="alert <?= $flash[0] === 'ok' ? 'alert-sukses' : 'alert-galat' ?>" role="status"
         style="<?= $flash[0] === 'ok' ? 'background:#dcfce7;color:#15803d' : '' ?>"><?= e($flash[1]) ?></div>
<?php endif; ?>
<?php if ($galat): ?>
    <div class="alert alert-galat" role="alert"><?= e($galat) ?></div>
<?php endif; ?>

<section class="grid-dua">
    <!-- Profil saya + alat ganti foto cepat -->
    <div class="content-card">
        <div class="card-header"><h2>Tambah admin</h2><span class="teks-redup">Akun baru dapat langsung masuk</span></div>
        <div class="card-body">
            <form class="form-admin" method="post" enctype="multipart/form-data" action="<?= e(url('admin/admin.php')) ?>" autocomplete="off">
                <input type="hidden" name="csrf" value="<?= e(csrf_token()) ?>">
                <input type="hidden" name="aksi" value="tambah_admin">

                <label class="form-label" for="namaAdmin">Nama lengkap</label>
                <input class="form-control" id="namaAdmin" name="nama" maxlength="100" value="<?= e($form['nama']) ?>" placeholder="Contoh: Siti Aminah" required>

                <label class="form-label" for="usernameAdmin">Username</label>
                <input class="form-control" id="usernameAdmin" name="username" maxlength="50" value="<?= e($form['username']) ?>" pattern="[A-Za-z0-9._\-]{3,50}" title="3-50 karakter: huruf, angka, titik, garis bawah, garis hubung" placeholder="contoh: sitiaminah" required>
                <p class="bantuan">3-50 karakter. Huruf, angka, titik, garis bawah, atau garis hubung.</p>

                <div class="form-grid-dua">
                    <div>
                        <label class="form-label" for="emailAdmin">Email <span class="teks-redup">(opsional)</span></label>
                        <input class="form-control" id="emailAdmin" name="email" type="email" maxlength="150" value="<?= e($form['email']) ?>" placeholder="nama@contoh.com">
                    </div>
                    <div>
                        <label class="form-label" for="teleponAdmin">Telepon <span class="teks-redup">(opsional)</span></label>
                        <input class="form-control" id="teleponAdmin" name="telepon" type="tel" maxlength="20" value="<?= e($form['telepon']) ?>" placeholder="08xxxxxxxxxx">
                    </div>
                </div>

                <label class="form-label" for="passwordAdmin">Password</label>
                <input class="form-control" id="passwordAdmin" name="password" type="password" minlength="8" maxlength="72" required>
                <p class="bantuan">Minimal 8 karakter. Disimpan sebagai hash, tidak pernah dalam teks biasa.</p>

                <label class="form-label" for="konfirmasiAdmin">Konfirmasi password</label>
                <input class="form-control" id="konfirmasiAdmin" name="konfirmasi" type="password" minlength="8" maxlength="72" required>

                <label class="form-label" for="roleAdmin">Peran (role)</label>
                <select class="form-select" id="roleAdmin" name="role">
                    <option value="admin" <?= $form['role'] === 'admin' ? 'selected' : '' ?>>Admin</option>
                    <option value="owner" <?= $form['role'] === 'owner' ? 'selected' : '' ?>>Owner</option>
                </select>

                <label class="form-label" for="fotoAdminBaru">Foto profil (opsional)</label>
                <input class="form-control" type="file" id="fotoAdminBaru" name="foto" accept="image/jpeg,image/png,image/webp">
                <p class="bantuan">JPG, PNG, atau WebP, maksimal 2 MB. Kosongkan untuk memakai avatar huruf awal nama.</p>

                <button class="btn btn-primary btn-blok" type="submit">Tambah admin</button>
            </form>
        </div>
    </div>

    <!-- Tambah admin -->
    <div class="content-card">
        <div class="card-header"><h2>Daftar admin</h2><span class="teks-redup"><?= count($daftar) ?> akun</span></div>
    <?php if (!$daftar): ?>
        <p class="kosong">Belum ada akun admin.</p>
    <?php else: ?>
    <div class="table-wrapper"><table>
        <thead><tr><th>Admin</th><th>Username</th><th>Email</th><th>Peran</th><th>Status</th></tr></thead>
        <tbody>
        <?php foreach ($daftar as $a):
            $url_foto = url_foto_admin($a['foto'], versi_foto_admin($a['foto'], $a['diubah_pada'] ?? $a['dibuat_pada']));
            $inisial  = inisial_dari($a['nama'], $a['username']);
            $saya     = ((int)$a['id'] === $saya_id);
            $owner_a  = (($a['role'] ?? 'admin') === 'owner'); ?>
            <tr>
                <td>
                    <span class="sel-admin">
                        <?php if ($url_foto): ?>
                            <img src="<?= e($url_foto) ?>" alt="Foto profil <?= e($a['nama']) ?>" class="avatar" data-lightbox="<?= e($url_foto) ?>" tabindex="0" role="button">
                        <?php else: ?>
                            <span class="avatar avatar-inisial" aria-hidden="true"><?= e($inisial) ?></span>
                        <?php endif; ?>
                        <span>
                            <strong><?= e($a['nama']) ?></strong>
                            <?php if ($saya): ?><span class="badge-saya">Anda</span><?php endif; ?>
                            <?php if (!$a['foto']): ?><small>Belum ada foto</small><?php endif; ?>
                        </span>
                    </span>
                </td>
                <td><?= e($a['username']) ?></td>
                <td><?= !empty($a['email']) ? e($a['email']) : '<span class="nilai-kosong">' . e(t('common.belum_diisi', $bhs)) . '</span>' ?></td>
                <td><span class="role-badge<?= $owner_a ? ' owner' : '' ?>"><?= e(label_role($a['role'] ?? 'admin', $bhs)) ?></span></td>
                <td><?= $saya ? '<span class="tag-saya">Sesi saat ini</span>' : '<span class="teks-redup">Aktif</span>' ?></td>
            </tr>
        <?php endforeach; ?>
        </tbody>
    </table></div>
    <p class="teks-redup" style="padding:0 24px 20px">Demi keamanan, sistem belum menyediakan hapus/nonaktifkan admin dari halaman ini agar tidak ada risiko kehilangan akses Owner terakhir. Identitas dan kata sandi akun sendiri diubah melalui halaman Profil (klik nama di kanan atas).</p>
    <?php endif; ?>
    </div>
</section>


<?php
$skrip_halaman = <<<'JS'
(function () {
    // Pratinjau foto profil sebelum diunggah. Validasi di server tetap yang menentukan.
    var input = document.getElementById('fotoProfil'), pratinjau = document.getElementById('pratinjauFotoAdmin');
    if (input && pratinjau) {
        input.addEventListener('change', function () {
            var f = input.files && input.files[0];
            if (!f) { pratinjau.hidden = true; return; }
            if (f.size > 2 * 1024 * 1024) { alert('Ukuran foto maksimal 2 MB.'); input.value = ''; pratinjau.hidden = true; return; }
            if (['image/jpeg', 'image/png', 'image/webp'].indexOf(f.type) === -1) { alert('Format foto harus JPG, PNG, atau WebP.'); input.value = ''; pratinjau.hidden = true; return; }
            pratinjau.src = URL.createObjectURL(f);
            pratinjau.hidden = false;
        });
    }

    // Konfirmasi password dicek di browser agar cepat; server tetap memvalidasi ulang.
    var pw = document.getElementById('passwordAdmin'), kf = document.getElementById('konfirmasiAdmin');
    if (pw && kf) {
        var cocok = function () {
            kf.setCustomValidity(kf.value && kf.value !== pw.value ? 'Konfirmasi password tidak cocok.' : '');
        };
        pw.addEventListener('input', cocok);
        kf.addEventListener('input', cocok);
    }
})();
JS;
require __DIR__ . '/../includes/layout_bawah.php';
