<?php
// Layout bawah halaman pelanggan. Variabel opsional: $skrip_halaman (string JS mentah).
?>
</main>
<footer class="cust-footer">
    <div class="wadah">
        <div class="kisi">
            <div>
                <h4><?= e(NAMA_WARTEG) ?></h4>
                <p>Melayani pemesanan nasi box dan katering dengan menu harian segar. Pesanan diambil sendiri (self-pickup) di lokasi warteg.</p>
            </div>
            <div>
                <h4>Navigasi</h4>
                <ul>
                    <li><a href="<?= e(url('index.php')) ?>">Beranda</a></li>
                    <li><a href="<?= e(url('index.php#katalog')) ?>">Menu</a></li>
                    <li><a href="<?= e(url('cara-pemesanan.php')) ?>">Cara Pemesanan</a></li>
                    <li><a href="<?= e(url('cek_status.php')) ?>">Cek Status Pesanan</a></li>
                </ul>
            </div>
            <div>
                <h4>Informasi</h4>
                <ul>
                    <li>Minimum <?= (int)MINIMAL_BOX ?> box per pesanan</li>
                    <li>Pesan minimal H-<?= (int)MINIMAL_H_MINUS ?> sebelum pengambilan</li>
                    <li>Jam pengambilan <?= e(JAM_BUKA) ?>–<?= e(JAM_TUTUP) ?> WIB</li>
                    <li>Kapasitas <?= (int)KAPASITAS_HARIAN ?> box per hari</li>
                </ul>
            </div>
            <div>
                <h4>Lokasi Pengambilan</h4>
                <p><?= e(LOKASI_AMBIL) ?><?php if (ALAMAT_WARTEG !== ''): ?><br><?= e(ALAMAT_WARTEG) ?><?php endif; ?></p>
                <?php if (TELEPON_WARTEG !== ''): ?><p>Telp/WA: <?= e(TELEPON_WARTEG) ?></p><?php endif; ?>
            </div>
        </div>
        <div class="copy">&copy; <?= date('Y') ?> <?= e(NAMA_WARTEG) ?>. Semua harga dalam Rupiah (IDR).</div>
    </div>
</footer>
<div class="cust-toast-area" id="custToast" aria-live="polite"></div>
<?php /* PENTING: definisi window.WARTEG HARUS dimuat SEBELUM customer.js.
        customer.js adalah IIFE yang langsung membaca window.WARTEG saat dieksekusi,
        sehingga bila urutannya terbalik, W menjadi {} dan base URL, CSRF token,
        minimal box, serta jam operasional hilang (tombol Lanjut & submit gagal). */ ?>
<?php if (!empty($skrip_halaman)): ?><script><?= $skrip_halaman ?></script><?php endif; ?>
<script src="<?= e(url('assets/js/customer.js')) ?>"></script>
</body>
</html>
