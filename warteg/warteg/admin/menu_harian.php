<?php
require_once __DIR__ . '/../includes/bootstrap.php';
wajib_login();

$hari = date('Y-m-d');
$tgl  = valid_tanggal($_GET['tanggal'] ?? null) ?? $hari;
$lampau = $tgl < $hari;
$menu = menu_per_tanggal($kon, $tgl);
$r = ringkasan_tanggal($kon, $tgl);
$kemarin = (new DateTime($tgl))->modify('-1 day')->format('Y-m-d');
$besok   = (new DateTime($tgl))->modify('+1 day')->format('Y-m-d');

$judul = 'Menu Harian';
$menu_aktif = 'menu';
require __DIR__ . '/../includes/layout_atas.php';
?>
<div class="content-card">
    <div class="card-header filter-tanggal">
        <div>
            <h2><?= e(tanggal_id($tgl)) ?></h2>
            <p class="teks-redup">Menu yang diaktifkan bisa dipilih untuk pesanan baru pada tanggal ini. Pesanan yang sudah masuk tidak berubah.</p>
        </div>
        <form method="get" class="form-tanggal">
            <a class="btn btn-secondary btn-kecil" href="?tanggal=<?= e($kemarin) ?>">Sebelumnya</a>
            <input class="form-control" type="date" name="tanggal" value="<?= e($tgl) ?>" onchange="this.form.submit()">
            <a class="btn btn-secondary btn-kecil" href="?tanggal=<?= e($besok) ?>">Berikutnya</a>
        </form>
    </div>
    <div class="card-body">
        <?php if ($lampau): ?><div class="alert alert-peringatan">Tanggal ini sudah lewat, ketersediaan menu tidak dapat diubah.</div><?php endif; ?>
        <?php if (!$menu): ?>
            <p class="kosong">Belum ada data menu. Tambahkan menu pada tabel <code>menu</code> (atau impor <code>seed_demo.sql</code> untuk data contoh).</p>
        <?php endif; ?>
        <div class="grid-menu" id="gridMenu" data-tanggal="<?= e($tgl) ?>">
            <?php foreach ($menu as $m):
                $on = (int)$m['tersedia'] === 1; ?>
                <div class="kartu-menu<?= $on ? ' aktif' : '' ?>" data-menu="<?= (int)$m['id'] ?>">
                    <div class="foto-menu">
                        <?php if (!empty($m['foto'])): ?>
                            <img src="<?= e(url('uploads/menu/' . rawurlencode($m['foto']))) ?>" alt="<?= e($m['nama']) ?>">
                        <?php else: ?><span aria-hidden="true">🍱</span><?php endif; ?>
                    </div>
                    <div class="info-menu">
                        <h3><?= e($m['nama']) ?></h3>
                        <p class="teks-redup"><?= e(ucfirst($m['kategori'])) ?> &middot; <?= e(rupiah($m['harga'])) ?> / box</p>
                        <p class="status-menu"><?= $on ? 'Tersedia untuk dipesan' : 'Tidak tersedia' ?></p>
                        <?php
                        $stok = $m['stok'] === null ? null : (int)$m['stok'];
                        $sisaStok = $stok === null ? null : max(0, $stok - (int)$m['jumlah']);
                        $kelasStok = $sisaStok === null ? '' : ($sisaStok === 0 ? ' stok-habis' : ($sisaStok <= $stok * 0.2 ? ' stok-tipis' : ''));
                        ?>
                        <p class="status-stok<?= $kelasStok ?>">
                            <?php if ($stok === null): ?>
                                Stok: tanpa batas
                            <?php elseif ($sisaStok === 0): ?>
                                Stok habis (<?= $stok ?> box terjual)
                            <?php else: ?>
                                Stok: sisa <?= $sisaStok ?> dari <?= $stok ?> box
                            <?php endif; ?>
                        </p>
                        <p class="teks-redup"><?= (int)$m['jumlah'] ?> box sudah dipesan<?= (!$on && $m['jumlah'] > 0) ? ' (tetap harus diproses)' : '' ?></p>
                    </div>
                    <div class="aksi-kartu">
                        <label class="toggle" title="Aktif / Nonaktif">
                            <input type="checkbox" class="js-toggle-menu" <?= $on ? 'checked' : '' ?> <?= $lampau ? 'disabled' : '' ?> aria-label="Aktifkan <?= e($m['nama']) ?>">
                            <span class="toggle-slider"></span>
                        </label>
                        <?php
                        // Data untuk formulir edit (diedit dari master `menu`). Di-escape agar aman di atribut HTML.
                        $dataEdit = [
                            'id'       => (int)$m['id'],
                            'nama'     => $m['nama'],
                            'kategori' => $m['kategori'],
                            'harga'    => (int)$m['harga'],
                            'aktif'    => (int)$m['aktif'],
                            'stok'     => $m['stok'] === null ? '' : (int)$m['stok'],
                            'terjual'  => (int)$m['jumlah'],
                            'foto'     => (string)($m['foto'] ?? ''),
                            'foto_url' => !empty($m['foto']) ? url('uploads/menu/' . rawurlencode($m['foto'])) : '',
                        ];
                        ?>
                        <button type="button" class="btn-action btn-detail js-edit-menu"
                                data-menu="<?= e(json_encode($dataEdit, JSON_UNESCAPED_UNICODE | JSON_HEX_APOS | JSON_HEX_QUOT)) ?>"
                                title="Ubah nama, kategori, harga, foto, atau status menu">Edit</button>
                    </div>
                </div>
            <?php endforeach; ?>
        </div>
        <p class="teks-redup">Kuota <?= e(tanggal_id($tgl, false)) ?>: <?= $r['box'] ?> dari <?= KAPASITAS_HARIAN ?> box terpakai, sisa <?= $r['sisa'] ?> box.</p>
    </div>
</div>

<!-- Modal Edit Menu -->
<div class="modal" id="modalEditMenu" hidden>
    <div class="modal-kotak" role="dialog" aria-modal="true" aria-labelledby="judulEditMenu">
        <div class="modal-kepala">
            <h2 id="judulEditMenu">Edit Menu</h2>
            <button type="button" class="btn-tutup" id="modalEditTutup" aria-label="Tutup">&times;</button>
        </div>
        <div class="modal-isi">
            <form id="formEditMenu" class="form-menu" method="post" enctype="multipart/form-data" autocomplete="off" novalidate>
                <input type="hidden" name="csrf" value="<?= e(csrf_token()) ?>">
                <input type="hidden" name="menu_id" id="editMenuId" value="">
                <input type="hidden" name="tanggal" value="<?= e($tgl) ?>">
                <input type="hidden" name="hapus_foto" id="editHapusFoto" value="0">

                <div class="alert alert-galat" id="editGalat" role="alert" hidden></div>

                <label class="form-label" for="editNama">Nama menu</label>
                <input class="form-control" id="editNama" name="nama" maxlength="100" required>

                <div class="baris-dua">
                    <div>
                        <label class="form-label" for="editKategori">Kategori</label>
                        <select class="form-select" id="editKategori" name="kategori" required>
                            <option value="nasi">Nasi</option>
                            <option value="lauk">Lauk</option>
                            <option value="sayur">Sayur</option>
                            <option value="paket a">Paket A</option>
                            <option value="paket b">Paket B</option>
                            <option value="paket c">Paket C</option>
                        </select>
                    </div>
                    <div>
                        <label class="form-label" for="editHarga">Harga per box (Rp)</label>
                        <input class="form-control" type="number" id="editHarga" name="harga" min="500" max="1000000" step="500" required>
                    </div>
                </div>

                <label class="form-label" for="editAktif">Status menu</label>
                <select class="form-select" id="editAktif" name="aktif">
                    <option value="1">Aktif (masih dijual)</option>
                    <option value="0">Nonaktif (dihentikan)</option>
                </select>

                <label class="form-label" for="editStok">Stok untuk <?= e(tanggal_id($tgl, false)) ?> (box)</label>
                <input class="form-control" type="number" id="editStok" name="stok" min="0" max="<?= (int)STOK_MAKS ?>" step="1" placeholder="Kosongkan untuk tanpa batas">
                <p class="bantuan" id="editStokInfo">Kosongkan bila tidak dibatasi. Maksimal <?= (int)STOK_MAKS ?> box. Stok dihitung per tanggal pengambilan.</p>

                <label class="form-label" for="editFoto">Foto menu</label>
                <div class="foto-edit-baris">
                    <span class="foto-edit-preview" id="editFotoPreview"><span aria-hidden="true">🍱</span></span>
                    <div>
                        <input class="form-control" type="file" id="editFoto" name="foto" accept="image/jpeg,image/png,image/webp">
                        <p class="bantuan">Kosongkan untuk mempertahankan foto lama. JPG, PNG, atau WebP, maksimal 2 MB.</p>
                        <button type="button" class="btn btn-secondary btn-kecil" id="editHapusFotoBtn" hidden>Hapus foto</button>
                    </div>
                </div>

                <div class="aksi-modal">
                    <button type="button" class="btn btn-hapus-menu" id="editHapusMenu">Hapus menu</button>
                    <span class="aksi-modal-kanan">
                        <button type="button" class="btn btn-secondary" id="editBatal">Batal</button>
                        <button type="submit" class="btn btn-primary" id="editSimpan">Simpan Perubahan</button>
                    </span>
                </div>
                <p class="bantuan">Perubahan harga tidak mengubah pesanan yang sudah masuk (harga tercatat saat transaksi).</p>
            </form>
        </div>
    </div>
</div>
<?php require __DIR__ . '/../includes/layout_bawah.php';
