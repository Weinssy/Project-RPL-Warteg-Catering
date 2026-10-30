<?php
require_once __DIR__ . '/../includes/bootstrap.php';
wajib_login();

$hari = date('Y-m-d');
const MAKS_FOTO = 2 * 1024 * 1024; // 2 MB

// Simpan foto unggahan. Mengembalikan [nama_file|null, pesan_galat|null].
function simpan_foto_menu(array $f): array {
    $err = $f['error'] ?? UPLOAD_ERR_NO_FILE;
    if ($err === UPLOAD_ERR_NO_FILE) return [null, null];
    if ($err !== UPLOAD_ERR_OK) return [null, 'Unggah foto gagal. Coba lagi dengan file lain.'];
    if ($f['size'] > MAKS_FOTO) return [null, 'Ukuran foto maksimal 2 MB.'];
    $mime = (new finfo(FILEINFO_MIME_TYPE))->file($f['tmp_name']);
    $ext = ['image/jpeg' => 'jpg', 'image/png' => 'png', 'image/webp' => 'webp'][$mime] ?? null;
    if (!$ext || !@getimagesize($f['tmp_name'])) return [null, 'Format foto harus JPG, PNG, atau WebP.'];
    $dir = __DIR__ . '/../uploads/menu';
    if (!is_dir($dir)) mkdir($dir, 0755, true);
    $nama = bin2hex(random_bytes(10)) . '.' . $ext;
    if (!move_uploaded_file($f['tmp_name'], $dir . '/' . $nama)) return [null, 'Foto tidak dapat disimpan di server.'];
    return [$nama, null];
}

// ---------- Tambah menu untuk suatu tanggal ----------
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_cek();
    $nama     = trim((string)($_POST['nama'] ?? ''));
    $kategori = (string)($_POST['kategori'] ?? '');
    $harga    = filter_var($_POST['harga'] ?? null, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1, 'max_range' => 1000000]]);
    $tgl      = valid_tanggal($_POST['tanggal'] ?? null);

    
    $stokRaw = trim((string)($_POST['stok'] ?? ''));
    $stok = null; // null = tidak valid (belum lolos)

    $galat = null; $foto = null;
    if ($nama === '' || mb_strlen($nama) > 100)                  $galat = 'Nama menu wajib diisi (maksimal 100 karakter).';
    elseif (!in_array($kategori, ['nasi', 'lauk', 'sayur', 'paket a', 'paket b', 'paket c'], true)) $galat = 'Kategori tidak valid.';
    elseif (!$harga)                                              $galat = 'Harga harus berupa angka antara Rp1 dan Rp1.000.000.';
    elseif ($tgl === null || $tgl < $hari)                        $galat = 'Tanggal tidak valid. Pilih hari ini atau tanggal berikutnya.';
    elseif (!preg_match('/^\d+$/', $stokRaw))                     $galat = 'Stok menu harus berupa bilangan bulat (tidak boleh desimal atau negatif).';
    elseif ((int)$stokRaw > STOK_MAKS)                            $galat = 'Stok menu maksimal ' . STOK_MAKS . ' box per tanggal.';
    else                                                          $stok = (int)$stokRaw;

    if (!$galat) [$foto, $galat] = simpan_foto_menu($_FILES['foto'] ?? []);

    if (!$galat) {
        try {
            $kon->begin_transaction();
            $m = db_row($kon, 'SELECT id FROM menu WHERE nama = ? FOR UPDATE', [$nama]);
            if ($m) { // nama sudah ada di master: pakai ulang, perbarui kategori dan harga
                $mid = (int)$m['id'];
                db_exec($kon, 'UPDATE menu SET kategori = ?, harga = ?, aktif = 1 WHERE id = ?', [$kategori, $harga, $mid]);
            } else {
                db_exec($kon, 'INSERT INTO menu (nama, kategori, harga) VALUES (?, ?, ?)', [$nama, $kategori, $harga]);
                $mid = (int)$kon->insert_id;
            }

            // Stok awal tidak boleh lebih kecil dari box yang sudah dipesan pada tanggal ini.
            $terjual = (int)db_val($kon, "SELECT COALESCE(SUM(d.jumlah),0)
                FROM detail_pesanan d JOIN pesanan p ON p.id = d.pesanan_id
                WHERE p.tanggal_ambil = ? AND d.menu_id = ?", [$tgl, $mid]);
            if ($stok < $terjual) {
                $kon->rollback();
                if ($foto) @unlink(__DIR__ . '/../uploads/menu/' . $foto);
                $_SESSION['flash'] = ['galat', 'Stok tidak boleh lebih kecil dari ' . $terjual
                    . ' box yang sudah dipesan pada ' . tanggal_id($tgl, false) . '.'];
                header('Location: ' . url('admin/tambah_menu.php'));
                exit;
            }

            // Buka menu pada tanggal tsb. Tanpa foto baru, foto hari itu yang sudah ada dipertahankan.
            db_exec($kon, 'INSERT INTO menu_harian (menu_id, tanggal, tersedia, stok, foto) VALUES (?, ?, 1, ?, ?)
                           ON DUPLICATE KEY UPDATE tersedia = 1, stok = VALUES(stok), foto = COALESCE(VALUES(foto), foto)',
                           [$mid, $tgl, $stok, $foto]);
            $kon->commit();
            $_SESSION['flash'] = ['ok', 'Menu "' . $nama . '" ditambahkan untuk ' . tanggal_id($tgl, false) . ' dengan stok ' . $stok . ' box.'];
        } catch (Throwable $e) {
            try { $kon->rollback(); } catch (Throwable $x) {}
            if ($foto) @unlink(__DIR__ . '/../uploads/menu/' . $foto);
            error_log('tambah menu gagal: ' . $e->getMessage());
            $_SESSION['flash'] = ['galat', 'Menu gagal disimpan. Coba lagi.'];
        }
    } else {
        $_SESSION['flash'] = ['galat', $galat];
    }
    header('Location: ' . url('admin/tambah_menu.php'));
    exit;
}

$flash = $_SESSION['flash'] ?? null;
unset($_SESSION['flash']);

$judul = 'Tambah Menu';
$menu_aktif = 'tambah';
$skrip_halaman = <<<'JS'
(function () {
    var input = document.getElementById('fotoMenu'), pratinjau = document.getElementById('pratinjauFoto');
    if (!input) return;
    input.addEventListener('change', function () {
        var f = input.files && input.files[0];
        if (!f) { pratinjau.hidden = true; return; }
        if (f.size > 2 * 1024 * 1024) { alert('Ukuran foto maksimal 2 MB.'); input.value = ''; pratinjau.hidden = true; return; }
        pratinjau.src = URL.createObjectURL(f);
        pratinjau.hidden = false;
    });

    // Validasi stok di frontend (backend tetap memvalidasi ulang).
    var form = document.querySelector('.form-menu');
    if (!form) return;
    var stok = document.getElementById('stokMenu');
    if (!stok) return;
    var MAKS = parseInt(stok.getAttribute('max'), 10) || 120;
    function pesanStok() {
        var v = stok.value.trim();
        if (v === '') return 'Stok menu wajib diisi.';
        if (!/^\d+$/.test(v)) return 'Stok menu harus berupa bilangan bulat (tidak boleh desimal atau negatif).';
        var n = parseInt(v, 10);
        if (n < 0) return 'Stok menu minimal 0 box.';
        if (n > MAKS) return 'Stok menu maksimal ' + MAKS + ' box per tanggal.';
        return '';
    }
    stok.addEventListener('input', function () { stok.setCustomValidity(pesanStok()); });
    form.addEventListener('submit', function (ev) {
        stok.setCustomValidity(pesanStok());
        if (!form.checkValidity()) { ev.preventDefault(); form.reportValidity(); }
    });
})();
JS;
require __DIR__ . '/../includes/layout_atas.php';
?>
<style>
    .form-menu .baris-dua { display: grid; grid-template-columns: 1fr 1fr; gap: 12px; }
    .form-menu .form-label:first-child { margin-top: 0; }
    .form-menu .bantuan { color: var(--redup); font-size: 12px; margin-top: 4px; }
    #pratinjauFoto { width: 96px; height: 96px; object-fit: cover; border-radius: 12px; margin-top: 10px; border: 1px solid var(--garis); }
    .kartu-form { max-width: 640px; }
    @media (max-width: 560px) { .form-menu .baris-dua { grid-template-columns: 1fr; } }
</style>

<?php if ($flash): ?>
    <div class="alert <?= $flash[0] === 'ok' ? 'alert-sukses' : 'alert-galat' ?>" role="status"
         style="<?= $flash[0] === 'ok' ? 'background:#dcfce7;color:#15803d' : '' ?>"><?= e($flash[1]) ?></div>
<?php endif; ?>

<div class="content-card kartu-form">
    <div class="card-header">
        <h2>Tambah menu</h2>
        <span class="teks-redup">Stok maksimal <?= (int)STOK_MAKS ?> box per tanggal</span>
    </div>
    <div class="card-body">
        <form class="form-menu" method="post" enctype="multipart/form-data" autocomplete="off">
            <input type="hidden" name="csrf" value="<?= e(csrf_token()) ?>">

            <label class="form-label" for="tanggalMenu">Tanggal pengambilan</label>
            <input class="form-control" type="date" id="tanggalMenu" name="tanggal" value="<?= e($hari) ?>" min="<?= e($hari) ?>" required>

            <label class="form-label" for="namaMenu">Nama menu</label>
            <input class="form-control" id="namaMenu" name="nama" maxlength="100" placeholder="Contoh: Nasi Ayam" required>

            <div class="baris-dua">
                <div>
                    <label class="form-label" for="kategoriMenu">Kategori</label>
                    <select class="form-select" id="kategoriMenu" name="kategori" required>
                        <option value="nasi">Nasi</option>
                        <option value="lauk">Lauk</option>
                        <option value="sayur">Sayur</option>
                        <option value="paket a">Paket A</option>
                        <option value="paket b">Paket B</option>
                        <option value="paket c">Paket C</option>
                    </select>
                </div>
                <div>
                    <label class="form-label" for="hargaMenu">Harga per box (Rp)</label>
                    <input class="form-control" type="number" id="hargaMenu" name="harga" min="500" max="1000000" step="500" placeholder="15000" required>
                </div>
            </div>

            <label class="form-label" for="stokMenu">Stok menu (box)</label>
            <input class="form-control" type="number" id="stokMenu" name="stok" min="0" max="<?= (int)STOK_MAKS ?>" step="1" placeholder="0" required>
            <p class="bantuan">Bilangan bulat 0 sampai <?= (int)STOK_MAKS ?>. Maksimal <?= (int)STOK_MAKS ?> box untuk setiap menu pada tanggal pengambilan yang dipilih.</p>

            <label class="form-label" for="fotoMenu">Foto menu untuk tanggal ini</label>
            <input class="form-control" type="file" id="fotoMenu" name="foto" accept="image/jpeg,image/png,image/webp">
            <p class="bantuan">JPG, PNG, atau WebP, maksimal 2 MB. Foto hanya berlaku untuk tanggal yang dipilih.</p>
            <img id="pratinjauFoto" alt="Pratinjau foto" hidden>

            <button class="btn btn-primary btn-blok" type="submit">Tambah ke menu</button>
            <p class="bantuan">Jika nama menu sudah ada, harga, kategori, dan stoknya diperbarui dan menu dibuka untuk tanggal ini. Pesanan lama tidak berubah.</p>
        </form>
    </div>
</div>
<?php require __DIR__ . '/../includes/layout_bawah.php';
