<?php
require_once __DIR__ . '/../includes/bootstrap.php';
wajib_login();

$q       = trim((string)($_GET['q'] ?? ''));
$tgl     = valid_tanggal($_GET['tanggal'] ?? null);
$status  = (string)($_GET['status'] ?? '');
$status  = in_array($status, [STATUS_MENUNGGU, STATUS_MASAK, STATUS_SIAP], true) ? $status : '';
$hal     = max(1, (int)($_GET['hal'] ?? 1));
$per     = 10;

$where = []; $par = [];
if ($q !== '')      { $like = '%' . addcslashes($q, '%_\\') . '%'; $where[] = '(p.kode LIKE ? OR p.nama_pelanggan LIKE ?)'; array_push($par, $like, $like); }
if ($tgl !== null)  { $where[] = 'p.tanggal_ambil = ?'; $par[] = $tgl; }
if ($status !== '') { $where[] = 'p.status = ?'; $par[] = $status; }
$sqlWhere = $where ? 'WHERE ' . implode(' AND ', $where) : '';

$jumlah = (int)db_val($kon, "SELECT COUNT(*) FROM pesanan p $sqlWhere", $par);
$totalHal = max(1, (int)ceil($jumlah / $per));
$hal = min($hal, $totalHal);
$offset = ($hal - 1) * $per;

$baris = db_all($kon, "SELECT p.* FROM pesanan p $sqlWhere
    ORDER BY p.tanggal_ambil DESC, p.jam_ambil ASC, p.id DESC LIMIT $per OFFSET $offset", $par);

$rincian = [];
if ($baris) {
    $ids = array_map(fn($b) => (int)$b['id'], $baris);
    $ph  = implode(',', array_fill(0, count($ids), '?'));
    foreach (db_all($kon, "SELECT d.pesanan_id, m.nama, d.jumlah, d.harga_satuan, d.subtotal
        FROM detail_pesanan d JOIN menu m ON m.id = d.menu_id WHERE d.pesanan_id IN ($ph) ORDER BY m.nama", $ids) as $d) {
        $rincian[(int)$d['pesanan_id']][] = $d;
    }
}

function url_hal(int $n): string { return '?' . http_build_query(array_merge($_GET, ['hal' => $n])); }

$judul = 'Daftar Pemesan';
$menu_aktif = 'pemesan';
require __DIR__ . '/../includes/layout_atas.php';
?>
<div class="content-card">
    <form class="filter-bar" method="get">
        <input class="form-control" type="search" name="q" value="<?= e($q) ?>" placeholder="Cari kode pesanan atau nama pelanggan">
        <input class="form-control" type="date" name="tanggal" value="<?= e($tgl ?? '') ?>" title="Tanggal pengambilan">
        <select class="form-select" name="status">
            <option value="">Semua status</option>
            <?php foreach ([STATUS_MENUNGGU, STATUS_MASAK, STATUS_SIAP] as $s): ?>
                <option value="<?= e($s) ?>" <?= $s === $status ? 'selected' : '' ?>><?= e($s) ?></option>
            <?php endforeach; ?>
        </select>
        <button class="btn btn-primary" type="submit">Terapkan</button>
        <?php if ($q !== '' || $tgl !== null || $status !== ''): ?><a class="btn btn-secondary" href="pemesan.php">Reset</a><?php endif; ?>
    </form>

    <div class="table-wrapper">
    <table>
        <thead><tr>
            <th>No</th><th>Kode</th><th>Pelanggan</th><th>WhatsApp</th><th>Rincian menu</th><th>Box</th>
            <th>Tanggal ambil</th><th>Jam</th><th>Total harga</th><th>Pembayaran</th><th>Status</th><th>Aksi</th>
        </tr></thead>
        <tbody>
        <?php if (!$baris): ?>
            <tr><td colspan="12" class="kosong">Tidak ada pesanan yang cocok dengan filter.</td></tr>
        <?php endif; ?>
        <?php foreach ($baris as $i => $p):
            $det = $rincian[(int)$p['id']] ?? [];
            $ringkas = implode(', ', array_map(fn($d) => $d['nama'] . ' x' . $d['jumlah'], $det));
            $wa = link_wa($p['no_wa'], $p['nama_pelanggan'], (int)$p['total_box'], $p['kode']);
            $dataDetail = json_encode([
                'kode' => $p['kode'], 'nama' => $p['nama_pelanggan'], 'wa' => $p['no_wa'],
                'tanggal' => tanggal_id($p['tanggal_ambil']), 'jam' => jam_pendek($p['jam_ambil']),
                'status' => $p['status'], 'box' => (int)$p['total_box'], 'total' => rupiah($p['total_harga']),
                'item' => array_map(fn($d) => ['nama' => $d['nama'], 'jumlah' => (int)$d['jumlah'],
                    'harga' => rupiah($d['harga_satuan']), 'subtotal' => rupiah($d['subtotal'])], $det),
            ], JSON_UNESCAPED_UNICODE);
        ?>
            <tr data-id="<?= (int)$p['id'] ?>">
                <td><?= $offset + $i + 1 ?></td>
                <td><strong><?= e($p['kode']) ?></strong></td>
                <td><?= e($p['nama_pelanggan']) ?></td>
                <td><?= e($p['no_wa']) ?></td>
                <td class="sel-rincian"><?= e($ringkas) ?></td>
                <td><?= (int)$p['total_box'] ?></td>
                <td><?= e(tanggal_id($p['tanggal_ambil'], false)) ?></td>
                <td><?= e(jam_pendek($p['jam_ambil'])) ?></td>
                <td><?= e(rupiah($p['total_harga'])) ?></td>
                <?php $sb = (string)($p['status_bayar'] ?? BAYAR_BELUM); ?>
                <td><span class="badge <?= e(kelas_status_bayar($sb)) ?>"><?= e(status_bayar_label($sb)) ?></span></td>
                <td><?= badge_status($p['status']) ?></td>
                <td>
                    <div class="action-buttons">
                        <button type="button" class="btn-action btn-detail" data-detail="<?= e($dataDetail) ?>">Detail</button>
                        <?php if ($p['status'] === STATUS_MENUNGGU): ?>
                            <button type="button" class="btn-action btn-masak" data-status="masak" data-id="<?= (int)$p['id'] ?>" data-kode="<?= e($p['kode']) ?>">Mulai Masak</button>
                        <?php endif; ?>
                        <?php if ($p['status'] !== STATUS_SIAP): ?>
                            <button type="button" class="btn-action btn-siap" data-status="siap" data-id="<?= (int)$p['id'] ?>" data-kode="<?= e($p['kode']) ?>">Siap Diambil</button>
                        <?php elseif ($wa): ?>
                            <a class="btn-action btn-wa" href="<?= e($wa) ?>" target="_blank" rel="noopener">Buka WhatsApp</a>
                        <?php endif; ?>
                    </div>
                </td>
            </tr>
        <?php endforeach; ?>
        </tbody>
    </table>
    </div>

    <div class="pagination">
        <span class="teks-redup"><?= $jumlah ?> pesanan &middot; halaman <?= $hal ?> dari <?= $totalHal ?></span>
        <div>
            <?php if ($hal > 1): ?><a class="btn btn-secondary btn-kecil" href="<?= e(url_hal($hal - 1)) ?>">Sebelumnya</a><?php endif; ?>
            <?php if ($hal < $totalHal): ?><a class="btn btn-secondary btn-kecil" href="<?= e(url_hal($hal + 1)) ?>">Berikutnya</a><?php endif; ?>
        </div>
    </div>
</div>

<div class="modal" id="modalDetail" hidden>
    <div class="modal-kotak" role="dialog" aria-modal="true" aria-labelledby="modalJudul">
        <div class="modal-kepala"><h2 id="modalJudul">Detail pesanan</h2><button type="button" class="btn-tutup" id="modalTutup" aria-label="Tutup">&times;</button></div>
        <div class="modal-isi" id="modalIsi"></div>
    </div>
</div>
<?php require __DIR__ . '/../includes/layout_bawah.php';
