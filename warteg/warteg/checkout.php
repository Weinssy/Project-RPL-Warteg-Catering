<?php
// =====================================================================
// Halaman checkout terpisah: ringkasan pesanan + formulir data pemesan.
// Data keranjang dibaca dari localStorage oleh JavaScript (customer.js).
// =====================================================================
require_once __DIR__ . '/includes/bootstrap.php';

$hari_ini = date('Y-m-d');
$tgl_min  = (new DateTime('today'))->modify('+' . MINIMAL_H_MINUS . ' day')->format('Y-m-d');
$tgl_maks = (new DateTime('today'))->modify('+' . MAKS_HARI_KE . ' day')->format('Y-m-d');
$tgl_awal = $tgl_min;

$judul = 'Checkout Pesanan';
require __DIR__ . '/includes/cust_atas.php';
?>

<!-- ================= CHECKOUT ================= -->
<section class="seksi checkout-page">
    <div class="wadah">
        <div class="seksi-kepala">
            <h2>Checkout Pesanan</h2>
            <p>Periksa kembali pesanan Anda, lalu lengkapi data pemesan dan jadwal pengambilan.</p>
        </div>
        <div class="checkout-grid">

            <!-- KOLOM KIRI: Ringkasan Pesanan -->
            <div class="panel-putih">
                <h3 style="font-size:16px;margin-bottom:12px">Ringkasan Pesanan</h3>
                <div id="ringkasanCheckoutIsi">
                    <p class="bantuan">Belum ada menu dipilih.</p>
                </div>
                <div class="ringkasan-total" style="background:none;padding:12px 0 0">
                    <div class="total-baris"><span>Total box</span><span id="totalBox">0</span></div>
                    <div class="total-baris"><span>Subtotal</span><span id="subtotal">Rp0</span></div>
                    <div class="total-baris besar"><span>Total</span><span class="nilai" id="totalHarga">Rp0</span></div>
                </div>
                <div style="margin-top:16px">
                    <a href="<?= e(url('index.php#katalog')) ?>" class="btn btn-garis btn-kecil">&larr; Ubah Pilihan Menu</a>
                </div>
            </div>

            <!-- KOLOM KANAN: Formulir Pemesan -->
            <div class="panel-putih">
                <div class="alert alert-info" id="infoMinBox">Minimum pemesanan <?= (int)MINIMAL_BOX ?> box per transaksi.</div>

                <form id="formCheckout" novalidate autocomplete="on">
                    <div class="form-baris">
                        <label for="fNama">Nama pemesan <span class="wajib">*</span></label>
                        <input type="text" class="input" id="fNama" name="nama" maxlength="100" required
                               placeholder="Nama lengkap" autocomplete="name"
                               pattern="[^0-9]*"
                               title="Nama harus mengandung huruf dan tidak boleh berisi angka.">
                        <div class="bantuan">Contoh: Ahmad Fauzi. Nama tidak boleh berisi angka.</div>
                        <div class="pesan-galat" data-untuk="nama"></div>
                    </div>

                    <div class="form-baris">
                        <label for="fWa">Nomor WhatsApp aktif <span class="wajib">*</span></label>
                        <input type="tel" class="input" id="fWa" name="no_wa" maxlength="20" required
                               placeholder="08xxxxxxxxxx" autocomplete="tel" inputmode="tel">
                        <div class="bantuan">Contoh: 0812 3456 7890 atau 6281234567890.</div>
                        <div class="pesan-galat" data-untuk="no_wa"></div>
                    </div>

                    <div class="form-dua">
                        <div class="form-baris">
                            <label for="fTgl">Tanggal pengambilan <span class="wajib">*</span></label>
                            <input type="date" class="input" id="fTgl" name="tanggal_ambil"
                                   min="<?= e($tgl_min) ?>" max="<?= e($tgl_maks) ?>" required value="<?= e($tgl_min) ?>">
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
                        <textarea class="textarea" id="fCatatan" name="catatan" maxlength="255"
                                  placeholder="Mis. tanpa sambal, tambah nasi, alergi, dll."></textarea>
                        <div class="pesan-galat" data-untuk="catatan"></div>
                    </div>

                    <input type="hidden" id="fJumlahBox" name="jumlah_box" value="0">
                    <p class="bantuan">Lokasi pengambilan: <strong><?= e(LOKASI_AMBIL) ?></strong> (self-pickup, tidak ada ongkir).</p>

                    <button type="submit" class="btn btn-primer btn-blok" id="btnBuatPesanan" disabled style="margin-top:16px">
                        <span id="btnBuatTeks">Lanjutkan Pembayaran</span>
                    </button>
                    <div class="alert alert-galat" id="galatCheckout" style="margin-top:14px;display:none"></div>
                </form>
            </div>

        </div>
    </div>
</section>

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
