<?php
require_once __DIR__ . '/../includes/bootstrap.php';
wajib_login();

$hari = date('Y-m-d');

$flash = $_SESSION['flash'] ?? null;
unset($_SESSION['flash']);

// ---------- Data dashboard (hanya ringkasan operasional) ----------
$r = ringkasan_tanggal($kon, $hari);
// Setiap menu: stok awal (NULL = tanpa batas), box terjual, dan sisa stok untuk tanggal ini.
$menu = db_all($kon, "SELECT m.id, m.nama, m.kategori, m.harga, COALESCE(mh.foto, m.foto) AS foto,
        COALESCE(mh.tersedia, 0) AS tersedia, mh.stok, COALESCE(x.jml, 0) AS jumlah
    FROM menu m
    LEFT JOIN menu_harian mh ON mh.menu_id = m.id AND mh.tanggal = ?
    LEFT JOIN (SELECT d.menu_id, SUM(d.jumlah) AS jml FROM detail_pesanan d
               JOIN pesanan p ON p.id = d.pesanan_id WHERE p.tanggal_ambil = ? GROUP BY d.menu_id) x ON x.menu_id = m.id
    WHERE mh.tersedia = 1 OR x.jml > 0
    ORDER BY FIELD(m.kategori,'nasi','lauk','sayur', 'paket a', 'paket b', 'paket c'), m.nama", [$hari, $hari]);
$terbaru = db_all($kon, 'SELECT id, kode, nama_pelanggan, total_box, tanggal_ambil, jam_ambil, status
    FROM pesanan ORDER BY dibuat_pada DESC, id DESC LIMIT 6');

// Total stok awal & box terjual hari ini (hanya menu yang stoknya dibatasi alias tidak NULL).
$totalStok = 0; $totalStokTerjual = 0;
foreach ($menu as $m) {
    if ($m['stok'] === null) continue;
    $totalStok += (int)$m['stok'];
    $totalStokTerjual += (int)$m['jumlah'];
}
// Persentase stok terpakai dari stok awal. Jika stok awal 0, hindari pembagian dengan nol.
$persenStok = $totalStok > 0 ? min(100, (int)round($totalStokTerjual / $totalStok * 100)) : 0;
$sisaStok = max(0, $totalStok - $totalStokTerjual);

$judul = 'Dashboard';
$menu_aktif = 'dashboard';
require __DIR__ . '/../includes/layout_atas.php';

$kartu = [
    ['Box dipesan hari ini', $r['box'] . ' box', ''],
    ['Kapasitas harian', KAPASITAS_HARIAN . ' box', ''],
    ['Sisa kuota', $r['sisa'] . ' box', $r['level'] === 'penuh' ? 'kartu-merah' : ($r['level'] === 'hampir' ? 'kartu-kuning' : '')],
    ['Pesanan hari ini', $r['pesanan'] . ' pesanan', ''],
    ['Total stok hari ini', $totalStok . ' box', ''],
    ['Stok terpakai', $persenStok . '%', $persenStok >= 100 ? 'kartu-merah' : ($persenStok >= 80 ? 'kartu-kuning' : '')],
];
?>
<style>
    .kolom-kiri { display: grid; gap: 20px; }
    .menu-item { display: flex; align-items: center; gap: 12px; }
    .menu-thumb { width: 48px; height: 48px; border-radius: 10px; background: #f1f5f9; display: grid; place-items: center; font-size: 22px; overflow: hidden; flex: none; }
    .menu-thumb img { width: 100%; height: 100%; object-fit: cover; }
    .daftar-menu li { align-items: center; }
    .daftar-menu .menu-hitungan { text-align: right; font-size: 12px; line-height: 1.5; }
    .daftar-menu .menu-hitungan strong { font-size: 14px; }
    .stok-habis { color: var(--merah); font-weight: 700; }
    .stok-tipis { color: var(--kuning); font-weight: 700; }
</style>

<?php if ($flash): ?>
    <div class="alert <?= $flash[0] === 'ok' ? 'alert-sukses' : 'alert-galat' ?>" role="status"
         style="<?= $flash[0] === 'ok' ? 'background:#dcfce7;color:#15803d' : '' ?>"><?= e($flash[1]) ?></div>
<?php endif; ?>

<section class="grid-kartu">
    <?php foreach ($kartu as [$label, $nilai, $kelas]): ?>
        <div class="kartu-stat <?= e($kelas) ?>"><span><?= e($label) ?></span><strong><?= e($nilai) ?></strong></div>
    <?php endforeach; ?>
</section>

<section class="grid-dua">
    <div class="kolom-kiri">
        <div class="content-card">
            <div class="card-header"><h2>Menu hari ini</h2><span class="teks-redup">Pengambilan <?= e(tanggal_id($hari, false)) ?></span></div>
            <div class="card-body">
                <?php if ($r['level'] === 'penuh'): ?>
                    <div class="alert alert-galat">Kuota hari ini sudah penuh. Pesanan baru akan ditolak otomatis.</div>
                <?php elseif ($r['level'] === 'hampir'): ?>
                    <div class="alert alert-peringatan">Kuota hampir penuh, tersisa <?= (int)$r['sisa'] ?> box.</div>
                <?php endif; ?>
                <?php if (!$menu): ?>
                    <p class="kosong">Belum ada menu hari ini. <a href="<?= e(url('admin/tambah_menu.php')) ?>">Tambah menu</a> terlebih dahulu.</p>
                <?php else: ?>
                    <ul class="daftar-menu">
                        <?php foreach ($menu as $m):
                            $stokMenu = $m['stok'] === null ? null : (int)$m['stok'];
                            $sisaMenu = $stokMenu === null ? null : max(0, $stokMenu - (int)$m['jumlah']); ?>
                            <li>
                                <span class="menu-item">
                                    <span class="menu-thumb">
                                        <?php if (!empty($m['foto'])): ?>
                                            <img src="<?= e(url('uploads/menu/' . rawurlencode($m['foto']))) ?>" alt="<?= e($m['nama']) ?>">
                                        <?php else: ?><span aria-hidden="true">🍱</span><?php endif; ?>
                                    </span>
                                    <span>
                                        <?= e($m['nama']) ?><?php if (!$m['tersedia']): ?> <em class="tag-nonaktif">dinonaktifkan</em><?php endif; ?><br>
                                        <small class="teks-redup"><?= e(rupiah($m['harga'])) ?> / box</small>
                                    </span>
                                </span>
                                <span class="menu-hitungan">
                                    <?php if ($stokMenu === null): ?>
                                        <strong><?= (int)$m['jumlah'] ?> box</strong><br>
                                        <small class="teks-redup">tanpa batas stok</small>
                                    <?php elseif ($sisaMenu === 0 && (int)$m['jumlah'] >= $stokMenu): ?>
                                        <span class="stok-habis">Stok habis</span><br>
                                        <small class="teks-redup"><?= (int)$m['jumlah'] ?> / <?= $stokMenu ?> box</small>
                                    <?php else: ?>
                                        <strong>sisa <?= $sisaMenu ?> box</strong><br>
                                        <small class="teks-redup <?= $sisaMenu <= $stokMenu * 0.2 ? 'stok-tipis' : '' ?>">dipesan <?= (int)$m['jumlah'] ?> dari <?= $stokMenu ?></small>
                                    <?php endif; ?>
                                </span>
                            </li>
                        <?php endforeach; ?>
                        <li class="total"><span>Total</span><strong><?= $r['box'] ?> dari <?= KAPASITAS_HARIAN ?> box</strong></li>
                    </ul>
                <?php endif; ?>
                <div class="progress" role="progressbar" aria-valuenow="<?= $r['persen'] ?>" aria-valuemin="0" aria-valuemax="100">
                    <div class="progress-bar level-<?= e($r['level']) ?>" style="width: <?= $r['persen'] ?>%"></div>
                </div>
                <p class="teks-redup">Terpakai <?= $r['persen'] ?>% &middot; sisa kuota <?= $r['sisa'] ?> box</p>
                <p class="teks-redup">Stok menu: <?= $totalStokTerjual ?> dari <?= $totalStok ?> box terpakai (<?= $persenStok ?>%), sisa <?= $sisaStok ?> box.</p>
            </div>
        </div>
    </div>

    <div class="content-card">
        <div class="card-header"><h2>Pesanan terbaru</h2><a class="btn btn-secondary btn-kecil" href="<?= e(url('admin/pemesan.php')) ?>">Lihat Daftar Pemesan</a></div>
        <?php if (!$terbaru): ?>
            <p class="kosong">Belum ada pesanan masuk.</p>
        <?php else: ?>
        <div class="table-wrapper"><table>
            <thead><tr><th>Kode</th><th>Pelanggan</th><th>Box</th><th>Pengambilan</th><th>Status</th></tr></thead>
            <tbody>
            <?php foreach ($terbaru as $p): ?>
                <tr>
                    <td><?= e($p['kode']) ?></td>
                    <td><?= e($p['nama_pelanggan']) ?></td>
                    <td><?= (int)$p['total_box'] ?></td>
                    <td><?= e(tanggal_id($p['tanggal_ambil'], false)) ?>, <?= e(jam_pendek($p['jam_ambil'])) ?></td>
                    <td><?= badge_status($p['status']) ?></td>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table></div>
        <?php endif; ?>
    </div>
</section>
<?php require __DIR__ . '/../includes/layout_bawah.php';
