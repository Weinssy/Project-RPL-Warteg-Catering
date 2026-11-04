<?php
// =====================================================================
// Halaman "Cara Pemesanan": panduan lengkap 7 langkah memesan nasi box.
// =====================================================================
require_once __DIR__ . '/includes/bootstrap.php';

$judul = 'Cara Pemesanan';
require __DIR__ . '/includes/cust_atas.php';
?>

<!-- ================= HERO CARA PEMESANAN ================= -->
<section class="seksi" style="background:var(--c-primer);color:#fff;text-align:center;padding:48px 0 40px">
    <div class="wadah">
        <h1 style="font-size:2rem;margin-bottom:8px">Cara Pemesanan</h1>
        <p class="lead" style="color:rgba(255,255,255,.85);max-width:600px;margin:0 auto">
            Panduan lengkap memesan nasi box &amp; katering di <?= e(NAMA_WARTEG) ?>.
            Tanpa perlu membuat akun — cukup ikuti langkah-langkah berikut.
        </p>
    </div>
</section>

<!-- ================= LANGKAH-LANGKAH ================= -->
<section class="seksi">
    <div class="wadah">
        <div class="seksi-kepala">
            <h2>7 Langkah Mudah</h2>
            <p>Dari memilih menu hingga mengambil pesanan, semuanya bisa dilakukan secara online.</p>
        </div>
        <div class="cara-pemesanan-grid">

            <!-- Langkah 1 -->
            <div class="cara-langkah">
                <div class="nomor-langkah" aria-hidden="true">1</div>
                <div class="ikon-langkah" aria-hidden="true">📋</div>
                <h3>Pilih Menu</h3>
                <p>Pilih nasi, lauk, sayur, atau paket yang tersedia dari katalog menu kami.</p>
            </div>

            <!-- Langkah 2 -->
            <div class="cara-langkah">
                <div class="nomor-langkah" aria-hidden="true">2</div>
                <div class="ikon-langkah" aria-hidden="true">📦</div>
                <h3>Tentukan Jumlah Pesanan</h3>
                <p>Tentukan jumlah box yang diinginkan. Minimum pemesanan <?= (int)MINIMAL_BOX ?> box per transaksi.</p>
            </div>

            <!-- Langkah 3 -->
            <div class="cara-langkah">
                <div class="nomor-langkah" aria-hidden="true">3</div>
                <div class="ikon-langkah" aria-hidden="true">📅</div>
                <h3>Tentukan Jadwal Pengambilan</h3>
                <p>Pilih tanggal dan jam pengambilan sesuai ketersediaan kuota. Pemesanan minimal H-<?= (int)MINIMAL_H_MINUS ?> sebelum pengambilan.</p>
            </div>

            <!-- Langkah 4 -->
            <div class="cara-langkah">
                <div class="nomor-langkah" aria-hidden="true">4</div>
                <div class="ikon-langkah" aria-hidden="true">✏️</div>
                <h3>Isi Data Pemesan</h3>
                <p>Isi nama lengkap dan nomor WhatsApp aktif yang akan digunakan untuk konfirmasi pesanan.</p>
            </div>

            <!-- Langkah 5 -->
            <div class="cara-langkah">
                <div class="nomor-langkah" aria-hidden="true">5</div>
                <div class="ikon-langkah" aria-hidden="true">💳</div>
                <h3>Lakukan Pembayaran</h3>
                <p>Lakukan pembayaran menggunakan QRIS sesuai instruksi yang ditampilkan setelah konfirmasi pesanan.</p>
            </div>

            <!-- Langkah 6 -->
            <div class="cara-langkah">
                <div class="nomor-langkah" aria-hidden="true">6</div>
                <div class="ikon-langkah" aria-hidden="true">🧾</div>
                <h3>Simpan Invoice</h3>
                <p>Simpan invoice atau kode pesanan sebagai bukti pemesanan. Anda dapat mengecek status pesanan kapan saja.</p>
            </div>

            <!-- Langkah 7 -->
            <div class="cara-langkah">
                <div class="nomor-langkah" aria-hidden="true">7</div>
                <div class="ikon-langkah" aria-hidden="true">🎉</div>
                <h3>Ambil Pesanan</h3>
                <p>Ambil pesanan Anda di <?= e(LOKASI_AMBIL) ?> sesuai jadwal yang telah ditentukan. Jam operasional: <?= e(JAM_BUKA) ?>–<?= e(JAM_TUTUP) ?> WIB.</p>
            </div>

        </div>
    </div>
</section>

<!-- ================= INFORMASI TAMBAHAN ================= -->
<section class="seksi" style="background:#fff;border-top:1px solid var(--c-garis)">
    <div class="wadah" style="max-width:640px;text-align:center">
        <div class="seksi-kepala">
            <h2>Informasi Operasional</h2>
            <p>Pastikan Anda memahami ketentuan berikut sebelum memesan.</p>
        </div>
        <div class="panel-putih" style="text-align:left;margin-bottom:24px">
            <ul style="list-style:none;padding:0;margin:0;display:grid;gap:12px">
                <li><strong>⏰ Jam Operasional:</strong> <?= e(JAM_BUKA) ?>–<?= e(JAM_TUTUP) ?> WIB</li>
                <li><strong>📍 Lokasi Pengambilan:</strong> <?= e(LOKASI_AMBIL) ?><?php if (ALAMAT_WARTEG !== ''): ?> — <?= e(ALAMAT_WARTEG) ?><?php endif; ?></li>
                <li><strong>📦 Minimum Pemesanan:</strong> <?= (int)MINIMAL_BOX ?> box per transaksi</li>
                <li><strong>📅 Waktu Pesan:</strong> Minimal H-<?= (int)MINIMAL_H_MINUS ?> sebelum pengambilan</li>
                <li><strong>🏷️ Kapasitas Harian:</strong> Maksimal <?= (int)KAPASITAS_HARIAN ?> box per hari</li>
            </ul>
        </div>
        <a href="<?= e(url('index.php#katalog')) ?>" class="btn btn-primer">Mulai Pesan Sekarang</a>
    </div>
</section>

<?php
$skrip_halaman = '';
require __DIR__ . '/includes/cust_bawah.php';
