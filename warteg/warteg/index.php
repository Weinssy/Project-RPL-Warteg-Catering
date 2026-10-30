<?php
// =====================================================================
// Halaman utama pelanggan: hero + kapasitas + katalog/meal builder + formulir pemesanan.
// Guest checkout: tidak perlu login. Semua harga/kuota dihitung ulang oleh backend saat checkout.
// =====================================================================
require_once __DIR__ . '/includes/bootstrap.php';

$hari_ini = date('Y-m-d');
$tgl_min  = (new DateTime('today'))->modify('+' . MINIMAL_H_MINUS . ' day')->format('Y-m-d');
$tgl_maks = (new DateTime('today'))->modify('+' . MAKS_HARI_KE . ' day')->format('Y-m-d');
$tgl_awal = $tgl_min; // default tanggal pilih = H-1

// Data awal untuk render server-side (katalog awal pada tanggal default).
$menu_awal = [];
$kapasitas_awal = ['box' => 0, 'sisa' => KAPASITAS_HARIAN, 'persen' => 0, 'level' => 'aman'];
try {
    $menu_awal = menu_per_tanggal($kon, $tgl_awal);
    $kapasitas_awal = ringkasan_tanggal($kon, $tgl_awal);
} catch (Throwable $e) {
    error_log('index pelanggan muat data gagal: ' . $e->getMessage());
}

// Kelompokkan menu per kategori.
function kelompok_menu(array $menu): array {
    $g = [];
    foreach ($menu as $m) $g[$m['kategori']][] = $m;
    return $g;
}
$kelompok = kelompok_menu($menu_awal);

// Label & aturan kategori.
$label_kategori = [
    'nasi'    => ['Nasi', 'Pilih satu jenis nasi untuk setiap box.'],
    'lauk'    => ['Lauk Utama', 'Pilih satu lauk utama.'],
    'sayur'   => ['Sayur / Pendamping', 'Pilih sayur atau pendamping.'],
    'paket a' => ['Paket A', 'Paket siap pilih dengan komposisi tersendiri.'],
    'paket b' => ['Paket B', 'Paket siap pilih dengan komposisi tersendiri.'],
    'paket c' => ['Paket C', 'Paket siap pilih dengan komposisi tersendiri.'],
];

$judul = 'Pesan Nasi Box & Katering';
require __DIR__ . '/includes/cust_atas.php';
?>

<!-- ================= HERO ================= -->
<section class="hero">
    <div class="wadah">
        <div class="hero-grid">
            <div>
                <span class="hero-label">Katering &amp; Nasi Box Harian</span>
                <h1>Nasi Box Lezat untuk <span>Acara &amp; Kantor</span> Anda</h1>
                <p class="lead">Rakit nasi box sesuai selera, pilih tanggal pengambilan, dan bayar mudah dengan QRIS. Pesanan diambil sendiri di <?= e(LOKASI_AMBIL) ?>.</p>
                <div class="hero-cta">
                    <a href="#katalog" class="btn btn-primer">Mulai Pesan</a>
                    <a href="#cara" class="btn btn-garis">Cara Pemesanan</a>
                </div>
                <div class="hero-fakta">
                    <div><span class="tanda">✓</span> Minimum <?= (int)MINIMAL_BOX ?> box</div>
                    <div><span class="tanda">✓</span> Ambil sendiri di lokasi</div>
                    <div><span class="tanda">✓</span> Pesan minimal H-<?= (int)MINIMAL_H_MINUS ?></div>
                </div>
            </div>
            <div class="hero-gambar">
                <img
                    src="<?= e(url('uploads/1.jpeg')) ?>"
                    alt="Nasi box dan menu katering Warung Mas Alif"
                    loading="eager" >
               
            </div>
        </div>
    </div>
</section>

<!-- ================= CARA PESAN ================= -->
<section class="seksi" id="cara" style="padding-bottom:20px">
    <div class="wadah">
        <div class="seksi-kepala">
            <h2>Cara Pemesanan</h2>
            <p>Empat langkah mudah memesan nasi box, tanpa perlu membuat akun.</p>
        </div>
        <div class="langkah-grid">
            <div class="langkah"><div class="nomor">1</div><h3>Pilih Menu</h3><p>Rakit nasi box dari kategori nasi, lauk, dan sayur atau pilih paket.</p></div>
            <div class="langkah"><div class="nomor">2</div><h3>Tentukan Jumlah</h3><p>Atur jumlah box (minimal <?= (int)MINIMAL_BOX ?>), tanggal, dan jam pengambilan.</p></div>
            <div class="langkah"><div class="nomor">3</div><h3>Isi Data &amp; Bayar</h3><p>Lengkapi nama &amp; WhatsApp, lalu bayar via QRIS.</p></div>
            <div class="langkah"><div class="nomor">4</div><h3>Ambil Pesanan</h3><p>Simpan kode pesanan, ambil di lokasi sesuai jadwal.</p></div>
        </div>
    </div>
</section>

<!-- ================= KAPASITAS & TANGGAL ================= -->
<section class="seksi" id="kapasitas" style="padding-top:20px">
    <div class="wadah">
        <div class="seksi-kepala">
            <h2>Cek Ketersediaan &amp; Kuota</h2>
            <p>Kuota dihitung langsung dari data pesanan. Kapasitas maksimal <?= (int)KAPASITAS_HARIAN ?> box per hari pengambilan.</p>
        </div>
        <div class="panel-kapasitas">
            <div class="pilih-tanggal">
                <div class="grup">
                    <label for="cekTanggal">Tanggal pengambilan</label>
                    <input type="date" class="input" id="cekTanggal" min="<?= e($tgl_min) ?>" max="<?= e($tgl_maks) ?>" value="<?= e($tgl_awal) ?>">
                </div>
                <button type="button" class="btn btn-garis btn-kecil" id="btnCekTanggal">Tampilkan Kuota</button>
            </div>
            <div class="kapasitas-info" id="kapasitasInfo">
                <div class="kapasitas-kotak">
                    <div class="angka"><?= (int)KAPASITAS_HARIAN ?></div>
                    <div class="label">Kapasitas maksimal / hari</div>
                </div>
                <div class="kapasitas-kotak">
                    <div class="angka" id="kapTerpakai"><?= (int)$kapasitas_awal['box'] ?></div>
                    <div class="label">Pesanan masuk (box)</div>
                </div>
                <div class="kapasitas-kotak sisa <?= e($kapasitas_awal['level']) ?>" id="kotakSisa">
                    <div class="angka" id="kapSisa"><?= (int)$kapasitas_awal['sisa'] ?></div>
                    <div class="label">Sisa kuota (box)</div>
                </div>
            </div>
            <div class="bar-kapasitas"><span id="barKapasitas" style="width:<?= (int)$kapasitas_awal['persen'] ?>%"></span></div>
            <p class="bantuan" id="teksTanggal"><?= e(tanggal_id($tgl_awal)) ?> &middot; jam pengambilan <?= e(JAM_BUKA) ?>–<?= e(JAM_TUTUP) ?> WIB</p>
        </div>
    </div>
</section>

<!-- ================= KATALOG / MEAL BUILDER ================= -->
<section class="seksi" id="katalog">
    <div class="wadah">
        <div class="seksi-kepala">
            <h2>Rakit Nasi Box Anda</h2>
            <p>Klik kartu menu untuk menambahkannya ke ringkasan. Menu yang habis atau belum dibuka pada tanggal terpilih tidak dapat dipilih.</p>
        </div>
        <div class="builder-grid">
            <div id="katalogMenu">
                <?php if (!$kelompok): ?>
                    <div class="kosong"><div class="ikon">🍽️</div><p>Belum ada menu yang dibuka untuk tanggal ini. Pilih tanggal lain di atas.</p></div>
                <?php else: ?>
                    <?php foreach ($kelompok as $kat => $daftar): ?>
                        <?php [$lab, $aturan] = $label_kategori[$kat] ?? [ucfirst($kat), '']; ?>
                        <div class="kategori-blok" data-kategori="<?= e($kat) ?>">
                            <h3><?= e($lab) ?></h3>
                            <p class="aturan"><?= e($aturan) ?></p>
                            <div class="grid-menu-cust">
                                <?php foreach ($daftar as $m):
                                    $tersedia = ((int)$m['tersedia'] === 1) && !empty($m['aktif']);
                                    $sisa_stok = $m['stok'] === null ? null : max(0, (int)$m['stok'] - (int)$m['jumlah']);
                                    $stok_habis = ($sisa_stok !== null && $sisa_stok <= 0);
                                    $bisa = $tersedia && !$stok_habis;
                                    $kelas_stok = '';
                                    if ($sisa_stok !== null) $kelas_stok = $sisa_stok <= 0 ? 'habis' : ($sisa_stok <= (int)$m['stok'] * 0.2 ? 'tipis' : 'aman');
                                ?>
                                <div class="kartu-cust<?= $bisa ? '' : ' habis' ?>"
                                     data-menu-id="<?= (int)$m['id'] ?>"
                                     data-nama="<?= e($m['nama']) ?>"
                                     data-harga="<?= (int)$m['harga'] ?>"
                                     data-kategori="<?= e($m['kategori']) ?>"
                                     data-sisa-stok="<?= $sisa_stok === null ? '' : (int)$sisa_stok ?>">
                                    <span class="cek" aria-hidden="true">✓</span>
                                    <div class="foto">
                                        <?php if (!empty($m['foto'])): ?>
                                            <img src="<?= e(url('uploads/menu/' . rawurlencode($m['foto']))) ?>" alt="<?= e($m['nama']) ?>" loading="lazy">
                                        <?php else: ?><span aria-hidden="true">🍱</span><?php endif; ?>
                                    </div>
                                    <div class="isi">
                                        <span class="badge-kat"><?= e($label_kategori[$m['kategori']][0] ?? ucfirst($m['kategori'])) ?></span>
                                        <div class="nama"><?= e($m['nama']) ?></div>
                                        <div class="harga"><?= e(rupiah($m['harga'])) ?> <small>/ box</small></div>
                                        <div class="badge-stok <?= e($kelas_stok) ?>">
                                            <?php if (!$tersedia): ?>
                                                Tidak tersedia
                                            <?php elseif ($sisa_stok === null): ?>
                                                Stok tersedia
                                            <?php elseif ($stok_habis): ?>
                                                Stok habis
                                            <?php else: ?>
                                                Sisa <?= (int)$sisa_stok ?> box
                                            <?php endif; ?>
                                        </div>
                                        <div class="pilih">
                                            <?php if ($bisa): ?>
                                                <button type="button" class="btn btn-garis btn-kecil btn-blok pilih-menu">Pilih</button>
                                            <?php else: ?>
                                                <button type="button" class="btn btn-garis btn-kecil btn-blok" disabled>Tidak dapat dipilih</button>
                                            <?php endif; ?>
                                        </div>
                                    </div>
                                </div>
                                <?php endforeach; ?>
                            </div>
                        </div>
                    <?php endforeach; ?>
                <?php endif; ?>
            </div>

            <!-- Ringkasan sticky -->
            <aside class="ringkasan" id="ringkasan" aria-label="Ringkasan pesanan">
                <div class="ringkasan-kepala">
                    <span>Ringkasan Keranjang</span>
                    <button type="button" class="baris-menu hapus" id="kosongkanKeranjang" title="Kosongkan keranjang" style="font-size:12px;color:var(--c-redup);background:none;border:0;cursor:pointer" hidden>Kosongkan</button>
                </div>
                <div class="ringkasan-isi" id="ringkasanIsi">
                    <div class="ringkasan-kosong">Keranjang masih kosong.<br>Pilih menu di samping.</div>
                </div>
                <div class="ringkasan-total">
                    <div class="total-baris"><span>Total box</span><span id="totalBox">0</span></div>
                    <div class="total-baris"><span>Subtotal</span><span id="subtotal">Rp0</span></div>
                    <div class="total-baris besar"><span>Total</span><span class="nilai" id="totalHarga">Rp0</span></div>
                </div>
                <div class="ringkasan-aksi">
                    <button type="button" class="btn btn-primer btn-blok" id="btnLanjut" disabled>Lanjutkan ke Data Pemesan</button>
                    <p class="bantuan" id="pesanMin" style="text-align:center;margin-top:8px">Minimum <?= (int)MINIMAL_BOX ?> box</p>
                </div>
            </aside>
        </div>
    </div>
</section>

<!-- ================= CHECKOUT ================= -->
<section class="seksi" id="checkout" style="background:#fff;border-top:1px solid var(--c-garis)">
    <div class="wadah">
        <div class="seksi-kepala">
            <h2>Data Pemesan &amp; Pengambilan</h2>
            <p>Isi data dengan benar. Nomor WhatsApp aktif dipakai admin untuk konfirmasi pesanan.</p>
        </div>
        <form id="formCheckout" novalidate autocomplete="on">
            <div class="checkout-grid">
                <div class="panel-putih">
                    <div class="alert alert-info" id="infoMinBox">Minimum pemesanan <?= (int)MINIMAL_BOX ?> box per transaksi.</div>

                    <div class="form-baris">
                        <label for="fNama">Nama pemesan <span class="wajib">*</span></label>
                        <input type="text" class="input" id="fNama" name="nama" maxlength="100" required placeholder="Nama lengkap" autocomplete="name">
                        <div class="pesan-galat" data-untuk="nama"></div>
                    </div>
                    <div class="form-baris">
                        <label for="fWa">Nomor WhatsApp aktif <span class="wajib">*</span></label>
                        <input type="tel" class="input" id="fWa" name="no_wa" maxlength="20" required placeholder="08xxxxxxxxxx" autocomplete="tel" inputmode="tel">
                        <div class="bantuan">Contoh: 0812 3456 7890 atau 6281234567890.</div>
                        <div class="pesan-galat" data-untuk="no_wa"></div>
                    </div>
                    <div class="form-dua">
                        <div class="form-baris">
                            <label for="fTgl">Tanggal pengambilan <span class="wajib">*</span></label>
                            <input type="date" class="input" id="fTgl" name="tanggal_ambil" min="<?= e($tgl_min) ?>" max="<?= e($tgl_maks) ?>" required value="<?= e($tgl_min) ?>">
                            <div class="pesan-galat" data-untuk="tanggal_ambil"></div>
                        </div>
                        <div class="form-baris">
                            <label for="fJam">Jam pengambilan <span class="wajib">*</span></label>
                            <select class="select" id="fJam" name="jam_ambil" required></select>
                            <div class="pesan-galat" data-untuk="jam_ambil"></div>
                        </div>
                    </div>
                    <div class="form-baris">
                        <label for="fCatatan">Catatan pesanan (opsional)</label>
                        <textarea class="textarea" id="fCatatan" name="catatan" maxlength="255" placeholder="Mis. tanpa sambal, tambah nasi, alergi, dll."></textarea>
                        <div class="pesan-galat" data-untuk="catatan"></div>
                    </div>
                    <input type="hidden" id="fJumlahBox" name="jumlah_box" value="0">
                    <p class="bantuan">Lokasi pengambilan: <strong><?= e(LOKASI_AMBIL) ?></strong> (self-pickup, tidak ada ongkir).</p>
                </div>

                <div>
                    <div class="panel-putih" id="ringkasanCheckout" style="margin-bottom:16px">
                        <h3 style="font-size:16px;margin-bottom:12px">Pesanan Anda</h3>
                        <div id="ringkasanCheckoutIsi"><p class="bantuan">Belum ada menu dipilih.</p></div>
                        <div class="ringkasan-total" style="background:none;padding:12px 0 0">
                            <div class="total-baris besar"><span>Total</span><span class="nilai" id="totalCheckout">Rp0</span></div>
                        </div>
                    </div>
                    <button type="submit" class="btn btn-primer btn-blok" id="btnBuatPesanan" disabled>
                        <span id="btnBuatTeks">Lanjutkan Pembayaran</span>
                    </button>
                    <div class="alert alert-galat" id="galatCheckout" style="margin-top:14px;display:none"></div>
                </div>
            </div>
        </form>
    </div>
</section>

<!-- Bottom cart bar (mobile) -->
<div class="cart-bar" id="cartBar">
    <div class="info"><b id="cartBarBox">0 box</b><span id="cartBarTotal">Rp0</span></div>
    <a href="#checkout" class="btn btn-primer btn-kecil">Lanjutkan</a>
</div>

<?php
$skrip_halaman = <<<'JS'
window.WARTEG = {
    base: CONTEXT_BASE,
    minimal: MINIMAL_VAL,
    kapasitas: KAPASITAS_VAL,
    jamBuka: JAM_BUKA_VAL,
    jamTutup: JAM_TUTUP_VAL,
    csrf: document.querySelector('meta[name="csrf-token"]').content,
    konfigurasiBayar: BAYAR_KONF_VAL
};
JS;
// Ganti placeholder dengan nilai PHP yang sudah di-escape.
$skrip_halaman = strtr($skrip_halaman, [
    'CONTEXT_BASE'    => json_encode(url('')),
    'MINIMAL_VAL'     => (string)(int)MINIMAL_BOX,
    'KAPASITAS_VAL'   => (string)(int)KAPASITAS_HARIAN,
    'JAM_BUKA_VAL'    => json_encode(JAM_BUKA),
    'JAM_TUTUP_VAL'   => json_encode(JAM_TUTUP),
    'BAYAR_KONF_VAL'  => json_encode(pembayaran_terkonfigurasi()),
]);
require __DIR__ . '/includes/cust_bawah.php';
