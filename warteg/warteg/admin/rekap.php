<?php
require_once __DIR__ . '/../includes/bootstrap.php';
wajib_login();

$judul = 'Rekap Harian';
$menu_aktif = 'rekap';
$awal = valid_tanggal($_GET['tanggal'] ?? null) ?? date('Y-m-d');
require __DIR__ . '/../includes/layout_atas.php';
?>
<section class="grid-kalender" id="rekapApp"
         data-endpoint="<?= e(url('admin/aksi/rekap.php')) ?>"
         data-awal="<?= e($awal) ?>" data-kapasitas="<?= KAPASITAS_HARIAN ?>">
    <div class="content-card">
        <div class="card-header">
            <button type="button" class="btn btn-secondary btn-kecil" id="bulanSebelum">Sebelumnya</button>
            <h2 id="judulBulan"></h2>
            <button type="button" class="btn btn-secondary btn-kecil" id="bulanBerikut">Berikutnya</button>
        </div>
        <div class="card-body">
            <div class="kalender-nama-hari"><span>Sen</span><span>Sel</span><span>Rab</span><span>Kam</span><span>Jum</span><span>Sab</span><span>Min</span></div>
            <div class="kalender-grid" id="kalender"></div>
            <p class="teks-redup legenda">Setiap tanggal menampilkan jumlah pesanan, total box, dan sisa kuota berdasarkan tanggal pengambilan.</p>
        </div>
    </div>
    <div class="content-card">
        <div class="card-header"><h2 id="judulRekap">Rekap tanggal</h2></div>
        <div class="card-body" id="isiRekap"><p class="kosong">Memuat data...</p></div>
    </div>
</section>
<?php require __DIR__ . '/../includes/layout_bawah.php';
