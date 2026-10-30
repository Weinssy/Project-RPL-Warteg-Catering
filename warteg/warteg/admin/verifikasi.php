<?php
// =====================================================================
// Verifikasi Pembayaran — daftar pesanan yang menunggu verifikasi bukti bayar.
// Hanya dapat diakses admin yang login. Menampilkan detail pesanan, total tagihan,
// dan bukti pembayaran. Admin dapat menyetujui (LUNAS) atau menolak pembayaran.
// =====================================================================
require_once __DIR__ . '/../includes/bootstrap.php';
wajib_login();

// Daftar pesanan yang menunggu verifikasi + yang sudah ditolak (bisa kirim ulang).
$menunggu = db_all($kon, "SELECT p.*, a.nama AS verifikator
    FROM pesanan p LEFT JOIN admin a ON a.id = p.admin_verifikator_id
    WHERE p.status_bayar = ? ORDER BY p.bukti_bayar_pada ASC, p.id ASC", [BAYAR_MENUNGGU_VERIFIKASI]);

$ditolak = db_all($kon, "SELECT p.*, a.nama AS verifikator
    FROM pesanan p LEFT JOIN admin a ON a.id = p.admin_verifikator_id
    WHERE p.status_bayar = ? ORDER BY p.diverifikasi_pada DESC, p.id DESC LIMIT 30", [BAYAR_DITOLAK]);

// Hitung jumlah untuk badge
$jumlah_menunggu = count($menunggu);

// Rincian item
function ambil_rincian(mysqli $kon, int $pid): array {
    return db_all($kon, 'SELECT m.nama, d.jumlah, d.harga_satuan, d.subtotal
        FROM detail_pesanan d JOIN menu m ON m.id = d.menu_id WHERE d.pesanan_id = ? ORDER BY m.nama', [$pid]);
}

$judul = 'Verifikasi Pembayaran';
$menu_aktif = 'verifikasi';
require __DIR__ . '/../includes/layout_atas.php';
?>
<style>
    .kartu-verif { border: 1px solid var(--c-garis); border-radius: 12px; padding: 16px; margin-bottom: 16px; background: #fff; }
    .verif-aksi { display: flex; gap: 8px; flex-wrap: wrap; margin-top: 12px; }
    .bukti-thumb { max-width: 100%; max-height: 360px; border-radius: 8px; border: 1px solid #e5e7eb; cursor: zoom-in; }
    .verif-grid { display: grid; grid-template-columns: 1fr 1fr; gap: 16px; }
    @media (max-width: 720px) { .verif-grid { grid-template-columns: 1fr; } }
</style>

<div class="content-card">
    <div class="card-header">
        <h2>Pesanan Menunggu Verifikasi (<?= $jumlah_menunggu ?>)</h2>
        <span class="teks-redup">Periksa bukti bayar lalu setujui / tolak</span>
    </div>
    <div class="card-body">
        <?php if (!$menunggu): ?>
            <p class="kosong">Tidak ada pembayaran yang menunggu verifikasi saat ini.</p>
        <?php else: foreach ($menunggu as $p):
            $det = ambil_rincian($kon, (int)$p['id']);
            $nominal = (int)($p['nominal_bayar'] ?? $p['total_harga']);
            // URL bukti via endpoint terproteksi
            $url_bukti = $p['bukti_bayar'] ? url('admin/bukti_bayar.php?kode=' . rawurlencode($p['kode'])) : null;
            $url_invoice = url('invoice.php?kode=' . rawurlencode($p['kode']));
        ?>
            <div class="kartu-verif" data-pid="<?= (int)$p['id'] ?>">
                <div style="display:flex;justify-content:space-between;flex-wrap:wrap;gap:8px;align-items:center;margin-bottom:12px;">
                    <div>
                        <strong style="font-size:16px;"><?= e($p['kode']) ?></strong>
                        <span class="badge bayar-oranye" style="margin-left:8px;"><?= e(status_bayar_label((string)$p['status_bayar'])) ?></span>
                    </div>
                    <div style="font-size:20px;font-weight:700;color:var(--c-oranye-tua);"><?= e(rupiah($nominal)) ?></div>
                </div>
                <div class="verif-grid">
                    <div>
                        <p class="teks-redup" style="font-size:13px;margin-bottom:6px;">Pelanggan</p>
                        <p style="margin-bottom:10px;"><strong><?= e($p['nama_pelanggan']) ?></strong><br>
                            <span class="teks-redup"><?= e($p['no_wa']) ?></span></p>
                        <p class="teks-redup" style="font-size:13px;margin-bottom:6px;">Pengambilan</p>
                        <p style="margin-bottom:10px;"><?= e(tanggal_id((string)$p['tanggal_ambil'])) ?>, <?= e(jam_pendek((string)$p['jam_ambil'])) ?> WIB</p>
                        <?php if ($p['bukti_bayar_pada']): ?>
                            <p class="teks-redup" style="font-size:13px;">Bukti diunggah: <?= e(date('d M Y H:i', strtotime((string)$p['bukti_bayar_pada']))) ?> WIB</p>
                        <?php endif; ?>
                        <table style="width:100%;margin-top:10px;font-size:13px;">
                            <thead><tr><th style="text-align:left">Menu</th><th style="text-align:right">Qty</th><th style="text-align:right">Subtotal</th></tr></thead>
                            <tbody>
                            <?php foreach ($det as $d): ?>
                                <tr><td><?= e($d['nama']) ?></td><td style="text-align:right"><?= (int)$d['jumlah'] ?></td><td style="text-align:right"><?= e(rupiah($d['subtotal'])) ?></td></tr>
                            <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>
                    <div>
                        <?php if ($url_bukti): ?>
                            <p class="teks-redup" style="font-size:13px;margin-bottom:6px;">Bukti Pembayaran</p>
                            <img src="<?= e($url_bukti) ?>" alt="Bukti bayar <?= e($p['kode']) ?>" class="bukti-thumb" data-lightbox="<?= e($url_bukti) ?>">
                            <p style="margin-top:6px;"><a href="<?= e($url_bukti) ?>" target="_blank" rel="noopener" class="btn btn-secondary btn-kecil">Buka di tab baru</a></p>
                        <?php else: ?>
                            <p class="alert alert-galat">Bukti tidak ditemukan pada pesanan ini.</p>
                        <?php endif; ?>
                    </div>
                </div>
                <div class="verif-aksi">
                    <button type="button" class="btn btn-primary js-verif" data-id="<?= (int)$p['id'] ?>" data-aksi="setuju" data-kode="<?= e($p['kode']) ?>">✓ Setujui (LUNAS)</button>
                    <button type="button" class="btn btn-secondary js-verif" data-id="<?= (int)$p['id'] ?>" data-aksi="tolak" data-kode="<?= e($p['kode']) ?>">✗ Tolak</button>
                    <a href="<?= e($url_invoice) ?>" class="btn btn-secondary" target="_blank">Lihat Invoice</a>
                </div>
            </div>
        <?php endforeach; endif; ?>
    </div>
</div>

<?php if ($ditolak): ?>
<div class="content-card" style="margin-top:20px;">
    <div class="card-header"><h2>Pembayaran Ditolak</h2><span class="teks-redup">30 terbaru</span></div>
    <div class="table-wrapper">
    <table>
        <thead><tr><th>Kode</th><th>Pelanggan</th><th>Total</th><th>Alasan</th><th>Ditolak pada</th><th>Verifikator</th><th>Aksi</th></tr></thead>
        <tbody>
        <?php foreach ($ditolak as $p): ?>
            <tr>
                <td><strong><?= e($p['kode']) ?></strong></td>
                <td><?= e($p['nama_pelanggan']) ?></td>
                <td><?= e(rupiah((int)$p['total_harga'])) ?></td>
                <td style="max-width:240px;"><?= e((string)($p['alasan_tolak'] ?? '')) ?></td>
                <td><?= $p['diverifikasi_pada'] ? e(date('d M Y H:i', strtotime((string)$p['diverifikasi_pada']))) : '-' ?></td>
                <td><?= e((string)($p['verifikator'] ?? '-')) ?></td>
                <td>
                    <button type="button" class="btn btn-primary btn-kecil js-verif" data-id="<?= (int)$p['id'] ?>" data-aksi="setuju" data-kode="<?= e($p['kode']) ?>">Setujui sekarang</button>
                </td>
            </tr>
        <?php endforeach; ?>
        </tbody>
    </table>
    </div>
</div>
<?php endif; ?>

<div class="modal" id="modalTolak" hidden>
    <div class="modal-kotak" role="dialog" aria-modal="true">
        <div class="modal-kepala"><h2>Tolak Bukti Pembayaran</h2><button type="button" class="btn-tutup" id="modalTolakTutup" aria-label="Tutup">&times;</button></div>
        <div class="modal-isi">
            <p class="teks-redup" style="margin-bottom:10px;">Pelanggan <strong id="tolakKode"></strong> akan menerima alasan ini dan dapat mengunggah bukti baru.</p>
            <label class="form-label" for="alasanTolak">Alasan penolakan <span class="wajib">*</span></label>
            <textarea class="form-control" id="alasanTolak" rows="3" maxlength="500" placeholder="Mis. nominal transfer tidak sesuai / bukti tidak jelas / bukan bukti transfer"></textarea>
            <div style="display:flex;gap:8px;margin-top:14px;">
                <button type="button" class="btn btn-secondary" id="batalTolak">Batal</button>
                <button type="button" class="btn btn-primary" id="konfirmasiTolak">Tolak Pembayaran</button>
            </div>
        </div>
    </div>
</div>

<?php
$skrip_halaman = <<<'JS'
(function () {
    var csrf = (document.querySelector('meta[name="csrf-token"]') || {}).content || '';
    var BASE = (function () { var s = document.querySelector('script[src*="assets/js/admin.js"]'); return s ? s.src.replace(/assets\/js\/admin\.js.*$/, '') : ''; })();
    function kirim(data) {
        var f = new FormData();
        Object.keys(data).forEach(function (k) { f.append(k, data[k]); });
        f.append('csrf', csrf);
        return fetch(BASE + 'admin/aksi/verifikasi_pembayaran.php', { method: 'POST', body: f, credentials: 'same-origin', headers: { 'X-CSRF-Token': csrf } })
            .then(function (r) { return r.json().catch(function () { return { ok: false, pesan: 'Respons tidak valid.' }; }); });
    }

    var modal = document.getElementById('modalTolak');
    var pidTolak = null;

    function bukaTolak(id, kode) {
        pidTolak = id;
        document.getElementById('tolakKode').textContent = kode;
        document.getElementById('alasanTolak').value = '';
        modal.hidden = false;
        document.getElementById('alasanTolak').focus();
    }
    function tutupTolak() { modal.hidden = true; pidTolak = null; }
    document.getElementById('modalTolakTutup').addEventListener('click', tutupTolak);
    document.getElementById('batalTolak').addEventListener('click', tutupTolak);
    if (modal) modal.addEventListener('click', function (e) { if (e.target === modal) tutupTolak(); });

    document.getElementById('konfirmasiTolak').addEventListener('click', function () {
        var alasan = document.getElementById('alasanTolak').value.trim();
        if (alasan.length < 4) { alert('Alasan penolakan wajib diisi (min. 4 karakter).'); return; }
        var btn = this; btn.disabled = true;
        kirim({ id: pidTolak, aksi: 'tolak', alasan: alasan }).then(function (h) {
            btn.disabled = false;
            if (!h.ok) { alert(h.pesan || 'Gagal menolak.'); return; }
            tutupTolak();
            alert(h.pesan);
            window.location.reload();
        });
    });

    document.addEventListener('click', function (ev) {
        var b = ev.target.closest('.js-verif');
        if (!b) return;
        var id = b.dataset.id, aksi = b.dataset.aksi, kode = b.dataset.kode;
        if (aksi === 'tolak') { bukaTolak(id, kode); return; }
        if (!confirm('Setujui pembayaran ' + kode + ' sebagai LUNAS? Pastikan bukti sudah sesuai.')) return;
        b.disabled = true;
        kirim({ id: id, aksi: 'setuju' }).then(function (h) {
            b.disabled = false;
            if (!h.ok) { alert(h.pesan || 'Gagal menyetujui.'); return; }
            alert(h.pesan);
            window.location.reload();
        });
    });
})();
JS;
require __DIR__ . '/../includes/layout_bawah.php';
