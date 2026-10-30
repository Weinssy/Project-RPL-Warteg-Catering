<?php
// =====================================================================
// Invoice pesanan.
// Akses:
//   - Pemilik pesanan via token_nota (?kode=...&t=...).
//   - Admin berwenang (sudah login) bisa membuka /invoice.php?kode=... tanpa token.
// Invoice LUNAS hanya tampil sebagai "LUNAS" bila status_bayar = berhasil.
// Tidak membuat invoice baru di database (mencegah invoice ganda). Invoice diturunkan
// langsung dari data pesanan yang sudah ada — bila admin memverifikasi ulang,
// tampilan tetap satu invoice tunggal.
// =====================================================================
require_once __DIR__ . '/includes/bootstrap.php';

$kode  = trim((string)($_GET['kode'] ?? ''));
$token = trim((string)($_GET['t'] ?? ''));
$pesanan = null;
$detail = [];
$galat = '';

// Cek apakah ada sesi admin (untuk membuka invoice tanpa token)
$admin_akses = false;
if (admin_login_id() > 0) {
    $admin_akses = true;
}

if ($kode === '') {
    $galat = 'Kode pesanan tidak diberikan.';
} else {
    $pesanan = db_row($kon, 'SELECT * FROM pesanan WHERE kode = ?', [$kode]);
    if (!$pesanan) {
        $galat = 'Pesanan tidak ditemukan.';
    } elseif (!$admin_akses && !empty($pesanan['token_nota'])) {
        // Non-admin wajib punya token yang benar.
        if (!hash_equals((string)$pesanan['token_nota'], $token)) {
            $galat = 'Tautan invoice tidak valid.';
            $pesanan = null;
        }
    } elseif (!$admin_akses && empty($pesanan['token_nota'])) {
        $galat = 'Tautan invoice tidak valid.';
        $pesanan = null;
    }

    if ($pesanan) {
        $detail = db_all($kon, 'SELECT d.jumlah, d.harga_satuan, d.subtotal, m.nama, m.kategori FROM detail_pesanan d
            JOIN menu m ON m.id = d.menu_id WHERE d.pesanan_id = ? ORDER BY m.nama', [(int)$pesanan['id']]);
    }
}

// Ambil info admin verifikator bila ada
$verifikator_nama = '';
if ($pesanan && !empty($pesanan['admin_verifikator_id'])) {
    $v = db_row($kon, 'SELECT nama FROM admin WHERE id = ?', [(int)$pesanan['admin_verifikator_id']]);
    $verifikator_nama = $v['nama'] ?? '';
}

$judul = 'Invoice';
require __DIR__ . '/includes/cust_atas.php';
?>
<section class="seksi">
    <div class="wadah" style="max-width:720px">
        <?php if ($galat !== ''): ?>
            <div class="panel-putih">
                <div class="alert alert-galat"><?= e($galat) ?></div>
                <a href="<?= e(url('index.php')) ?>" class="btn btn-garis">Kembali ke Beranda</a>
            </div>
        <?php else:
            $status = (string)($pesanan['status_bayar'] ?? BAYAR_BELUM);
            $lunas = ($status === BAYAR_BERHASIL);
            $total = (int)$pesanan['total_harga'];
            $subtotal = 0;
            foreach ($detail as $d) { $subtotal += (int)$d['subtotal']; }
            $diskon = max(0, $subtotal - $total); // diskon/penyesuaian
            $ongkir = 0; // self-pickup
        ?>
        <div class="nota-kartu" style="max-width:100%">
            <div class="nota-atas" style="background:<?= $lunas ? '#15803d' : 'var(--c-oranye-tua)' ?>;">
                <h1>INVOICE</h1>
                <p style="opacity:.9;font-size:14px"><?= e(NAMA_WARTEG) ?></p>
                <div class="kode"><?= e($pesanan['kode']) ?></div>
                <div style="margin-top:10px;">
                    <span style="display:inline-block;padding:4px 12px;border-radius:999px;font-size:12px;font-weight:700;background:<?= $lunas ? 'rgba(255,255,255,.95);color:#15803d' : 'rgba(255,255,255,.95);color:#b45309' ?>;">
                        <?= $lunas ? 'LUNAS' : strtoupper(status_bayar_label($status)) ?>
                    </span>
                </div>
            </div>
            <div class="nota-isi">
                <div class="nota-info" style="margin-bottom:20px">
                    <div class="item"><div class="label">No. Invoice</div><div><strong><?= e('INV/' . $pesanan['kode']) ?></strong></div></div>
                    <div class="item"><div class="label">Tanggal Transaksi</div><div><?= e(tanggal_id(date('Y-m-d', strtotime((string)$pesanan['dibuat_pada'])))) ?></div></div>
                    <div class="item"><div class="label">Nama Pelanggan</div><div><?= e($pesanan['nama_pelanggan']) ?></div></div>
                    <div class="item"><div class="label">WhatsApp</div><div><?= e($pesanan['no_wa']) ?></div></div>
                    <div class="item"><div class="label">Metode Pembayaran</div><div>QRIS<?= $lunas ? ' (Terverifikasi)' : ' (Menunggu Verifikasi)' ?></div></div>
                    <?php if (!empty($pesanan['diverifikasi_pada'])): ?>
                        <div class="item"><div class="label">Diverifikasi Pada</div><div><?= e(date('d M Y H:i', strtotime((string)$pesanan['diverifikasi_pada']))) ?> WIB</div></div>
                    <?php endif; ?>
                    <?php if ($verifikator_nama !== ''): ?>
                        <div class="item"><div class="label">Diverifikasi Oleh</div><div><?= e($verifikator_nama) ?></div></div>
                    <?php endif; ?>
                </div>

                <h3 style="font-size:14px;text-transform:uppercase;letter-spacing:.04em;color:var(--c-redup);margin-bottom:10px;">Rincian Barang</h3>
                <table class="nota-rincian-tabel">
                    <thead>
                        <tr>
                            <th style="text-align:left">Menu</th>
                            <th style="text-align:right">Qty</th>
                            <th style="text-align:right">Harga Satuan</th>
                            <th style="text-align:right">Subtotal</th>
                        </tr>
                    </thead>
                    <tbody>
                    <?php foreach ($detail as $d): ?>
                        <tr>
                            <td><?= e($d['nama']) ?></td>
                            <td style="text-align:right"><?= (int)$d['jumlah'] ?></td>
                            <td style="text-align:right"><?= e(rupiah($d['harga_satuan'])) ?></td>
                            <td style="text-align:right"><?= e(rupiah($d['subtotal'])) ?></td>
                        </tr>
                    <?php endforeach; ?>
                    </tbody>
                </table>

                <div class="nota-total" style="margin-top:14px">
                    <div style="display:flex;justify-content:space-between;padding:6px 0;"><span>Subtotal</span><span><?= e(rupiah($subtotal)) ?></span></div>
                    <?php if ($diskon > 0): ?>
                        <div style="display:flex;justify-content:space-between;padding:6px 0;color:#15803d;"><span>Diskon</span><span>-<?= e(rupiah($diskon)) ?></span></div>
                    <?php endif; ?>
                    <div style="display:flex;justify-content:space-between;padding:6px 0;"><span>Ongkir</span><span>Rp0 (Ambil Sendiri)</span></div>
                    <div style="display:flex;justify-content:space-between;padding:8px 0;border-top:1px solid var(--c-garis);font-weight:700;font-size:18px;"><span>TOTAL</span><span><?= e(rupiah($total)) ?></span></div>
                </div>

                <?php if ($lunas): ?>
                    <div class="alert alert-sukses" style="margin-top:16px;justify-content:center;">
                        ✓ Invoice ini sudah LUNAS dan terverifikasi.
                    </div>
                <?php else: ?>
                    <div class="alert alert-peringatan" style="margin-top:16px;justify-content:center;">
                        Invoice ini BELUM LUNAS. Status pembayaran: <strong><?= e(status_bayar_label($status)) ?></strong>.
                    </div>
                <?php endif; ?>

                <div style="margin-top:18px;font-size:13px;color:var(--c-redup);">
                    <p><strong>Detail Pengambilan</strong></p>
                    <p><?= e(tanggal_id((string)$pesanan['tanggal_ambil'])) ?>, <?= e(jam_pendek((string)$pesanan['jam_ambil'])) ?> WIB @ <?= e(LOKASI_AMBIL) ?></p>
                    <?php if (!empty($pesanan['catatan'])): ?>
                        <p><strong>Catatan:</strong> <?= e($pesanan['catatan']) ?></p>
                    <?php endif; ?>
                </div>
            </div>
            <div class="nota-aksi">
                <button type="button" class="btn btn-garis btn-kecil" onclick="window.print()">Cetak / Simpan PDF</button>
                <a href="<?= e(url('nota.php?kode=' . rawurlencode($pesanan['kode']) . '&t=' . rawurlencode((string)($pesanan['token_nota'] ?? $token)))) ?>" class="btn btn-garis btn-kecil">Lihat Nota</a>
                <a href="<?= e(url('index.php')) ?>" class="btn btn-primer btn-kecil">Beranda</a>
            </div>
        </div>
        <?php endif; ?>
    </div>
</section>
<?php require __DIR__ . '/includes/cust_bawah.php';
