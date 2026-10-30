<?php
// =====================================================================
// Cek status pesanan publik tanpa menyimpan login.
// Keamanan privacy: pencarian butuh KODE pesanan + nomor WhatsApp yang dipakai.
// Ini mencegah orang lain menebak/curi data hanya dari kode berurutan.
// =====================================================================
require_once __DIR__ . '/includes/bootstrap.php';

$kode_in = trim((string)($_POST['kode'] ?? ''));
$wa_in   = trim((string)($_POST['no_wa'] ?? ''));
$hasil   = null;   // ['pesanan'=>?, 'detail'=>[], 'url'=>?]
$galat   = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if ($kode_in === '' || $wa_in === '') {
        $galat = 'Isi kode pesanan dan nomor WhatsApp.';
    } else {
        $wa_norm = normalisasi_wa($wa_in);
        $p = db_row($kon, 'SELECT * FROM pesanan WHERE kode = ?', [$kode_in]);
        // Cocokkan nomor WA yang tersimpan (format internasional) dengan input ternormalisasi.
        if (!$p || $wa_norm === null || $p['no_wa'] !== $wa_norm) {
            $galat = 'Pesanan tidak ditemukan. Periksa kembali kode dan nomor WhatsApp.';
        } else {
            $detail = db_all($kon, 'SELECT d.jumlah, d.harga_satuan, d.subtotal, m.nama FROM detail_pesanan d
                JOIN menu m ON m.id = d.menu_id WHERE d.pesanan_id = ? ORDER BY m.nama', [(int)$p['id']]);
            $token = (string)($p['token_nota'] ?? '');
            $hasil = [
                'pesanan' => $p,
                'detail' => $detail,
                'url_nota' => url('nota.php?kode=' . rawurlencode($p['kode']) . ($token ? '&t=' . rawurlencode($token) : '')),
                'url_bayar' => url('pembayaran.php?kode=' . rawurlencode($p['kode']) . ($token ? '&t=' . rawurlencode($token) : '')),
            ];
        }
    }
}

$judul = 'Cek Status Pesanan';
require __DIR__ . '/includes/cust_atas.php';
?>
<section class="seksi">
    <div class="wadah" style="max-width:640px">
        <div class="seksi-kepala">
            <h2>Cek Status Pesanan</h2>
            <p>Masukkan kode pesanan dan nomor WhatsApp yang Anda gunakan saat memesan.</p>
        </div>

        <div class="panel-putih" style="margin-bottom:20px">
            <form method="post" autocomplete="off">
                <div class="form-baris">
                    <label for="kode">Kode pesanan</label>
                    <input type="text" class="input" id="kode" name="kode" required placeholder="Contoh: WRT-20250101-001" value="<?= e($kode_in) ?>">
                </div>
                <div class="form-baris">
                    <label for="no_wa">Nomor WhatsApp</label>
                    <input type="tel" class="input" id="no_wa" name="no_wa" required placeholder="08xxxxxxxxxx" value="<?= e($wa_in) ?>">
                </div>
                <?php if ($galat !== ''): ?><div class="alert alert-galat"><?= e($galat) ?></div><?php endif; ?>
                <button type="submit" class="btn btn-primer btn-blok">Cek Status</button>
            </form>
        </div>

        <?php if ($hasil):
            $p = $hasil['pesanan'];
            $sb = (string)($p['status_bayar'] ?? BAYAR_BELUM);
        ?>
        <div class="nota-kartu">
            <div class="nota-atas">
                <h1>Status Pesanan</h1>
                <div class="kode"><?= e($p['kode']) ?></div>
            </div>
            <div class="nota-isi">
                <div style="display:flex;gap:10px;flex-wrap:wrap;margin-bottom:16px">
                    <span class="badge <?= e(kelas_status_bayar($sb)) ?>">Pembayaran: <?= e(status_bayar_label($sb)) ?></span>
                    <?= badge_status((string)$p['status']) ?>
                </div>
                <div class="nota-info" style="margin-bottom:16px">
                    <div class="item"><div class="label">Nama</div><div><?= e($p['nama_pelanggan']) ?></div></div>
                    <div class="item"><div class="label">Pengambilan</div><div><?= e(tanggal_id((string)$p['tanggal_ambil'])) ?>, <?= e(jam_pendek((string)$p['jam_ambil'])) ?> WIB</div></div>
                    <div class="item"><div class="label">Total box</div><div><?= (int)$p['total_box'] ?> box</div></div>
                    <div class="item"><div class="label">Total</div><div><?= e(rupiah((int)$p['total_harga'])) ?></div></div>
                </div>
                <div style="display:flex;gap:10px;flex-wrap:wrap">
                    <a href="<?= e($hasil['url_nota']) ?>" class="btn btn-primer btn-kecil">Lihat Nota Lengkap</a>
                    <?php if (pembayaran_terkonfigurasi() && in_array($sb, [BAYAR_BELUM, BAYAR_MENUNGGU], true)): ?>
                        <a href="<?= e($hasil['url_bayar']) ?>" class="btn btn-garis btn-kecil">Lanjutkan Pembayaran</a>
                    <?php endif; ?>
                </div>
            </div>
        </div>
        <?php endif; ?>
    </div>
</section>
<?php require __DIR__ . '/includes/cust_bawah.php';
