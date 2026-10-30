<?php
// =====================================================================
// Nota / bukti pesanan pelanggan (publik, akses via token_nota).
// Menampilkan rincian menu, total, status pembayaran, dan status proses katering.
// Bila dibuka lewat tautan yang benar, pelanggan dapat memantau status pesanan.
// =====================================================================
require_once __DIR__ . '/includes/bootstrap.php';

$kode  = trim((string)($_GET['kode'] ?? ''));
$token = trim((string)($_GET['t'] ?? ''));
$pesanan = null;
$detail = [];
$galat = '';

if ($kode === '') {
    $galat = 'Kode pesanan tidak diberikan.';
} else {
    $pesanan = db_row($kon, 'SELECT * FROM pesanan WHERE kode = ?', [$kode]);
    if (!$pesanan) {
        $galat = 'Pesanan tidak ditemukan.';
    } elseif (!empty($pesanan['token_nota']) && !hash_equals((string)$pesanan['token_nota'], $token)) {
        $galat = 'Tautan tidak valid. Gunakan tautan dari notifikasi pesanan Anda.';
        $pesanan = null;
    } else {
        $detail = db_all($kon, 'SELECT d.jumlah, d.harga_satuan, d.subtotal, m.nama FROM detail_pesanan d
            JOIN menu m ON m.id = d.menu_id WHERE d.pesanan_id = ? ORDER BY m.nama', [(int)$pesanan['id']]);
    }
}

$judul = 'Nota Pesanan';
require __DIR__ . '/includes/cust_atas.php';
?>
<section class="seksi">
    <div class="wadah" style="max-width:640px">
        <?php if ($galat !== ''): ?>
            <div class="panel-putih">
                <div class="alert alert-galat"><?= e($galat) ?></div>
                <p class="bantuan">Bila Anda pelanggan, buka tautan nota yang diberikan saat selesai memesan, atau hubungi admin.</p>
                <a href="<?= e(url('cek_status.php')) ?>" class="btn btn-garis">Cek Status Pesanan</a>
            </div>
        <?php else:
            $status_bayar = (string)($pesanan['status_bayar'] ?? BAYAR_BELUM);
            $url_pembayaran = url('pembayaran.php?kode=' . rawurlencode($pesanan['kode']) . '&t=' . rawurlencode((string)$pesanan['token_nota']));
            $url_nota = url('nota.php?kode=' . rawurlencode($pesanan['kode']) . '&t=' . rawurlencode((string)$pesanan['token_nota']));
        ?>
        <div class="nota-kartu">
            <div class="nota-atas">
                <h1>Nota Pesanan</h1>
                <p style="opacity:.9;font-size:14px"><?= e(NAMA_WARTEG) ?> &middot; <?= e(tanggal_id(date('Y-m-d'))) ?></p>
                <div class="kode"><?= e($pesanan['kode']) ?></div>
            </div>
            <div class="nota-isi">
                <div class="nota-seksi">
                    <div style="display:flex;gap:10px;flex-wrap:wrap;align-items:center;margin-bottom:14px">
                        <span class="badge <?= e(kelas_status_bayar($status_bayar)) ?>">Pembayaran: <?= e(status_bayar_label($status_bayar)) ?></span>
                        <?= badge_status((string)$pesanan['status']) ?>
                    </div>
                    <div class="nota-info">
                        <div class="item"><div class="label">Nama pemesan</div><div><?= e($pesanan['nama_pelanggan']) ?></div></div>
                        <div class="item"><div class="label">WhatsApp</div><div><?= e($pesanan['no_wa']) ?></div></div>
                        <div class="item"><div class="label">Tanggal ambil</div><div><?= e(tanggal_id((string)$pesanan['tanggal_ambil'])) ?></div></div>
                        <div class="item"><div class="label">Jam ambil</div><div><?= e(jam_pendek((string)$pesanan['jam_ambil'])) ?> WIB</div></div>
                        <div class="item"><div class="label">Lokasi</div><div><?= e(LOKASI_AMBIL) ?></div></div>
                    </div>
                </div>

                <div class="nota-seksi">
                    <h3>Rincian Pesanan</h3>
                    <table class="nota-rincian-tabel">
                        <thead><tr><th>Menu</th><th>Jumlah</th><th>Subtotal</th></tr></thead>
                        <tbody>
                        <?php foreach ($detail as $d): ?>
                            <tr>
                                <td><?= e($d['nama']) ?><br><small style="color:var(--c-redup)"><?= e(rupiah($d['harga_satuan'])) ?> / box</small></td>
                                <td><?= (int)$d['jumlah'] ?> box</td>
                                <td><?= e(rupiah($d['subtotal'])) ?></td>
                            </tr>
                        <?php endforeach; ?>
                        </tbody>
                    </table>
                    <div class="nota-total"><span>Total</span><span class="nilai"><?= e(rupiah((int)$pesanan['total_harga'])) ?></span></div>
                    <?php if (!empty($pesanan['catatan'])): ?>
                        <p class="bantuan" style="margin-top:10px"><strong>Catatan:</strong> <?= e($pesanan['catatan']) ?></p>
                    <?php endif; ?>
                </div>

                <?php if ($status_bayar === BAYAR_BERHASIL): ?>
                    <div class="alert alert-sukses" style="justify-content:center">✓ Pembayaran LUNAS dan sudah terverifikasi.</div>
                    <div style="text-align:center;margin-top:10px">
                        <a href="<?= e(url('invoice.php?kode=' . rawurlencode($pesanan['kode']) . '&t=' . rawurlencode((string)$pesanan['token_nota']))) ?>" class="btn btn-primer btn-kecil">Lihat Invoice LUNAS</a>
                    </div>
                <?php elseif ($status_bayar === BAYAR_MENUNGGU_VERIFIKASI): ?>
                    <div class="alert alert-peringatan" style="justify-content:center">
                        Bukti pembayaran sedang menunggu verifikasi admin.
                    </div>
                <?php elseif ($status_bayar === BAYAR_DITOLAK): ?>
                    <div class="alert alert-galat" style="justify-content:center;flex-direction:column;">
                        <span>Bukti pembayaran ditolak.</span>
                        <a href="<?= e($url_pembayaran) ?>"><strong>Unggah bukti baru &rarr;</strong></a>
                    </div>
                <?php elseif (in_array($status_bayar, [BAYAR_BELUM, BAYAR_MENUNGGU], true)): ?>
                    <div class="alert alert-peringatan" style="justify-content:center">
                        Pembayaran belum selesai. <a href="<?= e($url_pembayaran) ?>"><strong>Lanjutkan pembayaran QRIS &rarr;</strong></a>
                    </div>
                <?php elseif ($status_bayar === BAYAR_KEDALUWARSA || $status_bayar === BAYAR_GAGAL || $status_bayar === BAYAR_BATAL): ?>
                    <div class="alert alert-galat" style="justify-content:center">Pembayaran <?= e(strtolower(status_bayar_label($status_bayar))) ?>. Hubungi admin bila perlu.</div>
                <?php endif; ?>
            </div>
            <div class="nota-aksi">
                <button type="button" class="btn btn-garis btn-kecil" onclick="window.print()">Cetak / Simpan</button>
                <a href="<?= e($url_nota) ?>" class="btn btn-garis btn-kecil">Muat Ulang Status</a>
                <a href="<?= e(url('index.php')) ?>" class="btn btn-primer btn-kecil">Pesan Lagi</a>
            </div>
        </div>
        <p class="bantuan" style="text-align:center;margin-top:16px">Simpan tautan ini untuk memantau status pesanan. <a href="<?= e($url_nota) ?>" style="color:var(--c-oranye-tua);font-weight:600">Tautan nota</a>.</p>
        <?php endif; ?>
    </div>
</section>
<?php require __DIR__ . '/includes/cust_bawah.php';
