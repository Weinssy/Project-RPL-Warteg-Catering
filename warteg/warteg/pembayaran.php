<?php
// =====================================================================
// Halaman pembayaran QRIS pelanggan.
// Mendukung QRIS Statis (gambar diupload admin) dan QRIS Dinamis (gateway).
// Status pembayaran TIDAK pernah ditentukan dari halaman ini.
// =====================================================================
require_once __DIR__ . '/includes/bootstrap.php';
mulai_sesi(); // Pastikan sesi aktif sebelum cek CSRF & menangani upload bukti.

$kode = trim((string)($_GET['kode'] ?? ''));
$token = trim((string)($_GET['t'] ?? ''));
$pesanan = null;
$galat = '';
$galat_bukti = '';
$sukses_bukti = '';

if ($kode === '') {
    $galat = 'Kode pesanan tidak diberikan.';
} else {
    $pesanan = db_row($kon, 'SELECT * FROM pesanan WHERE kode = ?', [$kode]);
    if (!$pesanan) {
        $galat = 'Pesanan tidak ditemukan.';
    } elseif (!empty($pesanan['token_nota']) && !hash_equals((string)$pesanan['token_nota'], $token)) {
        $galat = 'Tautan tidak valid. Gunakan tautan dari nota pesanan Anda.';
        $pesanan = null;
    }
}

// --- Upload bukti pembayaran (POST) ---
if ($pesanan && $_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['aksi_bukti'] ?? '') === 'upload') {
    // Validasi CSRF
    $kirim_csrf = $_POST['csrf'] ?? '';
    if (empty($_SESSION['csrf']) || !is_string($kirim_csrf) || !hash_equals($_SESSION['csrf'], $kirim_csrf)) {
        $galat_bukti = 'Sesi tidak valid. Muat ulang halaman.';
    } else {
        $status_saat_ini = (string)($pesanan['status_bayar'] ?? BAYAR_BELUM);
        // Hanya boleh upload jika belum bayar, menunggu, atau ditolak (kirim ulang)
        if (!in_array($status_saat_ini, [BAYAR_BELUM, BAYAR_MENUNGGU, BAYAR_DITOLAK], true)) {
            $galat_bukti = 'Tidak dapat mengunggah bukti pada status pembayaran saat ini.';
        } else {
            [$nama_bukti, $err_bukti] = simpan_bukti_bayar($_FILES['bukti_bayar'] ?? []);
            if ($err_bukti) {
                $galat_bukti = $err_bukti;
            } elseif ($nama_bukti) {
                // Hapus bukti lama jika ada
                $bukti_lama = $pesanan['bukti_bayar'] ?? null;
                try {
                    db_exec($kon, 'UPDATE pesanan SET bukti_bayar = ?, bukti_bayar_pada = NOW(), status_bayar = ?, alasan_tolak = NULL WHERE id = ?',
                        [$nama_bukti, BAYAR_MENUNGGU_VERIFIKASI, (int)$pesanan['id']]);
                    $sukses_bukti = 'Bukti pembayaran berhasil diunggah. Silakan tunggu verifikasi dari admin.';
                    // Hapus file lama
                    if ($bukti_lama && $bukti_lama !== $nama_bukti) {
                        $path_lama = __DIR__ . '/uploads/bukti/' . $bukti_lama;
                        if (is_file($path_lama)) @unlink($path_lama);
                    }
                    // Refresh data pesanan
                    $pesanan = db_row($kon, 'SELECT * FROM pesanan WHERE id = ?', [(int)$pesanan['id']]);
                } catch (Throwable $e) {
                    error_log('upload bukti gagal: ' . $e->getMessage());
                    $galat_bukti = 'Gagal menyimpan bukti. Coba lagi.';
                    @unlink(__DIR__ . '/uploads/bukti/' . $nama_bukti);
                }
            }
        }
    }
}

$judul = 'Pembayaran QRIS';
require __DIR__ . '/includes/cust_atas.php';

// Ambil konfigurasi QRIS statis
$qris_gambar = null;
$qris_nama = '';
$qris_nmid = '';
$qris_instruksi = '';
$batas_bayar_jam = 24;
try {
    $qris_gambar = konfigurasi_toko($kon, 'qris_gambar');
    $qris_nama = konfigurasi_toko($kon, 'qris_nama_merchant', NAMA_WARTEG);
    $qris_nmid = konfigurasi_toko($kon, 'qris_nmid', '');
    $qris_instruksi = konfigurasi_toko($kon, 'qris_instruksi', 'Scan QRIS di atas dan masukkan nominal secara manual.');
    $batas_bayar_jam = (int)konfigurasi_toko($kon, 'batas_bayar_jam', '24');
} catch (Throwable $e) {}

$ada_qris_statis = !empty($qris_gambar);
$terkonf_dinamis = pembayaran_terkonfigurasi();

function tampil_ringkas(array $p): string {
    $o = '';
    $o .= '<div class="nota-info">';
    $o .= '<div class="item"><div class="label">Kode</div><div>' . e($p['kode']) . '</div></div>';
    $o .= '<div class="item"><div class="label">Pengambilan</div><div>' . e(tanggal_id((string)$p['tanggal_ambil'])) . ', ' . e(jam_pendek((string)$p['jam_ambil'])) . ' WIB</div></div>';
    $o .= '<div class="item"><div class="label">Total box</div><div>' . (int)$p['total_box'] . ' box</div></div>';
    $o .= '</div>';
    return $o;
}

// Form unggah bukti pembayaran
function tampil_form_bukti(array $p, string $token): string {
    $o = '<div style="margin-top:22px;border-top:1px solid var(--c-garis);padding-top:16px;">';
    $o .= '<h3 style="font-size:15px;margin-bottom:8px;">Unggah Bukti Pembayaran</h3>';
    $o .= '<p class="bantuan" style="margin-bottom:12px;">Setelah transfer via QRIS, unggah screenshot/foto bukti pembayaran (JPG, PNG, atau WebP, maks 5 MB). Admin akan memverifikasi pesanan Anda.</p>';
    $o .= '<form method="post" enctype="multipart/form-data" action="">';
    $o .= '<input type="hidden" name="csrf" value="' . e(csrf_token()) . '">';
    $o .= '<input type="hidden" name="aksi_bukti" value="upload">';
    $o .= '<div class="form-baris">';
    $o .= '<label for="bukti_bayar">Pilih file bukti pembayaran <span class="wajib">*</span></label>';
    $o .= '<input type="file" class="input" id="bukti_bayar" name="bukti_bayar" accept="image/jpeg,image/png,image/webp" required>';
    $o .= '</div>';
    $o .= '<button type="submit" class="btn btn-primer btn-blok">Kirim Bukti Pembayaran</button>';
    $o .= '<p class="bantuan" style="text-align:center;margin-top:8px;font-size:11px;">Mengirim bukti TIDAK otomatis menandai pesanan lunas. Admin akan memverifikasi terlebih dahulu.</p>';
    $o .= '</form>';
    $o .= '</div>';
    return $o;
}

// Blok QRIS statis (dipakai untuk status DITOLAK / kirim ulang)
function tampil_blok_qris_statis(array $p, int $nominal, string $gambar, string $nama, string $nmid, string $instruksi, string $batas): string {
    $o = '<div class="qr-kotak" style="margin-top:14px;">';
    $o .= '<div class="bayar-nominal">Total Tagihan: <strong>' . e(rupiah($nominal)) . '</strong></div>';
    $o .= '<div class="qr-gambar"><img src="' . e(url('uploads/qris/' . rawurlencode($gambar))) . '" alt="QRIS Statis" style="max-width:260px;width:100%;height:auto;border-radius:8px;"></div>';
    if ($instruksi !== '') {
        $o .= '<p class="bantuan" style="margin-top:10px;font-size:12px;">' . e($instruksi) . '</p>';
    }
    $o .= '</div>';
    return $o;
}
?>
<section class="seksi">
    <div class="wadah" style="max-width:640px">
        <?php if ($galat !== ''): ?>
            <div class="panel-putih">
                <div class="alert alert-galat"><?= e($galat) ?></div>
                <a href="<?= e(url('index.php')) ?>" class="btn btn-garis">Kembali ke Beranda</a>
            </div>
        <?php else:
            $status = (string)($pesanan['status_bayar'] ?? BAYAR_BELUM);
            $nominal = (int)($pesanan['nominal_bayar'] ?? $pesanan['total_harga']);
            $url_nota = url('nota.php?kode=' . rawurlencode($pesanan['kode']) . '&t=' . rawurlencode((string)$pesanan['token_nota']));
            $batas_ts = !empty($pesanan['bayar_kedaluwarsa_pada']) ? strtotime((string)$pesanan['bayar_kedaluwarsa_pada']) : null;
            $batas_label = $batas_ts ? date('d M Y H:i', $batas_ts) . ' WIB' : ($batas_bayar_jam . ' jam setelah pesanan');
        ?>
        <div class="nota-kartu">
            <div class="nota-atas">
                <h1>Pembayaran QRIS</h1>
                <p style="opacity:.9;font-size:14px">Selesaikan pembayaran untuk memproses pesanan Anda.</p>
                <div class="kode"><?= e($pesanan['kode']) ?></div>
            </div>
            <div class="nota-isi">
                <?php if ($sukses_bukti): ?>
                    <div class="alert alert-sukses" style="margin-bottom:16px">✓ <?= e($sukses_bukti) ?></div>
                <?php endif; ?>
                <?php if ($galat_bukti): ?>
                    <div class="alert alert-galat" style="margin-bottom:16px"><?= e($galat_bukti) ?></div>
                <?php endif; ?>

                <div id="statusBayarWrap" style="text-align:center;margin-bottom:18px">
                    <span class="badge <?= e(kelas_status_bayar($status)) ?>" id="badgeStatusBayar"><?= e(status_bayar_label($status)) ?></span>
                </div>

                <?php if ($status === BAYAR_BERHASIL): ?>
                    <!-- ============ LUNAS ============ -->
                    <div class="alert alert-sukses" style="justify-content:center;text-align:center;">
                        ✓ <strong>Pembayaran LUNAS</strong>. Pembayaran Anda sudah diverifikasi admin.
                    </div>
                    <?php if (!empty($pesanan['diverifikasi_pada'])): ?>
                        <p class="bantuan" style="text-align:center">Diverifikasi pada: <?= e(date('d M Y H:i', strtotime((string)$pesanan['diverifikasi_pada']))) ?> WIB</p>
                    <?php endif; ?>
                    <div style="text-align:center;margin-top:16px">
                        <a href="<?= e(url('invoice.php?kode=' . rawurlencode($pesanan['kode']) . '&t=' . rawurlencode((string)$pesanan['token_nota']))) ?>" class="btn btn-primer">Lihat Invoice LUNAS</a>
                        <a href="<?= e($url_nota) ?>" class="btn btn-garis" style="margin-left:8px">Lihat Nota</a>
                    </div>

                <?php elseif ($status === BAYAR_MENUNGGU_VERIFIKASI): ?>
                    <!-- ============ MENUNGGU VERIFIKASI ============ -->
                    <div class="alert alert-peringatan" style="justify-content:center;text-align:center;flex-direction:column;gap:6px;">
                        <strong>Bukti pembayaran sedang diperiksa admin</strong>
                        <span style="font-size:13px">Pembayaran belum dianggap lunas sampai admin memverifikasi bukti yang Anda unggah.</span>
                    </div>
                    <?php if (!empty($pesanan['bukti_bayar_pada'])): ?>
                        <p class="bantuan" style="text-align:center">Bukti diunggah: <?= e(date('d M Y H:i', strtotime((string)$pesanan['bukti_bayar_pada']))) ?> WIB</p>
                    <?php endif; ?>

                <?php elseif ($status === BAYAR_DITOLAK): ?>
                    <!-- ============ DITOLAK ============ -->
                    <div class="alert alert-galat" style="flex-direction:column;gap:6px;">
                        <strong>Bukti pembayaran ditolak</strong>
                        <?php if (!empty($pesanan['alasan_tolak'])): ?>
                            <span style="font-size:13px"><em>Alasan:</em> <?= e($pesanan['alasan_tolak']) ?></span>
                        <?php endif; ?>
                        <span style="font-size:13px">Silakan unggah ulang bukti pembayaran yang benar.</span>
                    </div>
                    <?php if ($ada_qris_statis): ?>
                        <?= tampil_blok_qris_statis($pesanan, $nominal, $qris_gambar, $qris_nama, $qris_nmid, $qris_instruksi, $batas_label) ?>
                    <?php endif; ?>
                    <?= tampil_form_bukti($pesanan, $token) ?>

                <?php elseif ($status === BAYAR_KEDALUWARSA || $status === BAYAR_GAGAL || $status === BAYAR_BATAL): ?>
                    <!-- ============ KEDALUWARSA / GAGAL / BATAL ============ -->
                    <div class="alert alert-galat">Pembayaran <?= e(strtolower(status_bayar_label($status))) ?>. Silakan buat pesanan baru atau hubungi admin.</div>

                <?php else: ?>
                    <!-- ============ MENUNGGU PEMBAYARAN (belum_bayar / menunggu) ============ -->
                    <?php if ($ada_qris_statis): ?>
                        <div class="alert alert-info" style="font-size:13px">
                            <div>
                                Kode pesanan Anda: <strong><?= e($pesanan['kode']) ?></strong>.
                                Harap simpan kode ini dan gunakan sebagai <em>berita/berita transfer</em> saat membayar.
                            </div>
                        </div>

                        <div class="qr-kotak">
                            <div class="bayar-nominal">Total Tagihan: <strong><?= e(rupiah($nominal)) ?></strong></div>
                            <p class="bantuan" style="margin-bottom:12px">Scan QRIS berikut dengan aplikasi bank / e-wallet Anda:</p>
                            <div class="qr-gambar" id="qrGambar">
                                <?php if ($qris_gambar): ?>
                                    <img src="<?= e(url('uploads/qris/' . rawurlencode($qris_gambar))) ?>" alt="QRIS Statis <?= e($qris_nama) ?>" style="max-width:280px;width:100%;height:auto;border-radius:8px;">
                                    <p style="text-align:center;font-size:12px;color:var(--c-redup);margin-top:8px;">
                                        <?= e($qris_nama) ?><?php if ($qris_nmid): ?><br>NMID: <?= e($qris_nmid) ?><?php endif; ?>
                                    </p>
                                <?php endif; ?>
                            </div>
                            <?php if (!empty($pesanan['bayar_kedaluwarsa_pada'])): ?>
                                <p class="bantuan" style="margin-top:10px;text-align:center">
                                    <strong>Batas pembayaran:</strong> <?= e($batas_label) ?>
                                </p>
                            <?php endif; ?>
                        </div>

                        <div class="alert alert-peringatan" style="margin-top:16px;flex-direction:column;gap:8px;">
                            <strong>⚠ Penting — QRIS ini bersifat STATIS</strong>
                            <div style="font-size:13px;line-height:1.6">
                                <?= e($qris_instruksi) ?>
                            </div>
                            <div style="font-size:13px;line-height:1.6;">
                                <strong>Nominal yang harus dibayar:</strong> <span style="font-size:16px;color:var(--c-oranye-tua);font-weight:700"><?= e(rupiah($nominal)) ?></span>
                            </div>
                        </div>

                        <?= tampil_form_bukti($pesanan, $token) ?>

                    <?php elseif ($terkonf_dinamis): ?>
                        <!-- Fallback ke QRIS dinamis gateway (bila dikonfigurasi) -->
                        <?php
                        $qr = (string)($pesanan['qr_payload'] ?? '');
                        ?>
                        <div class="qr-kotak">
                            <div class="bayar-nominal"><?= e(rupiah($nominal)) ?></div>
                            <p class="bantuan" style="margin-bottom:16px">Scan QRIS berikut dengan aplikasi bank / e-wallet Anda.</p>
                            <div class="qr-gambar" id="qrGambar">
                                <?php
                                if ($qr !== '' && preg_match('#^https?://#', $qr)) {
                                    echo '<img src="' . e($qr) . '" alt="Kode QRIS pembayaran ' . e($pesanan['kode']) . '">';
                                } elseif ($qr !== '' && strpos($qr, 'MOCK-QRIS:') === 0) {
                                    echo '<div style="padding:30px 10px;color:var(--c-redup);font-size:13px">Mode uji lokal (mock).<br><code style="font-size:10px;word-break:break-all">' . e($qr) . '</code></div>';
                                } else {
                                    echo '<div style="padding:30px 10px;color:var(--c-redup);font-size:13px">QR belum tersedia.</div>';
                                }
                                ?>
                            </div>
                            <?php if (!empty($pesanan['bayar_kedaluwarsa_pada'])): ?>
                                <p class="bantuan" style="margin-top:10px">Batas pembayaran: <?= e(date('d M Y H:i', strtotime((string)$pesanan['bayar_kedaluwarsa_pada']))) ?> WIB</p>
                            <?php endif; ?>
                        </div>
                        <p class="bantuan" style="text-align:center;margin-top:16px">
                            Status akan diperbarui otomatis. Halaman ini memeriksa status pembayaran setiap beberapa detik.
                        </p>
                    <?php else: ?>
                        <div class="alert alert-peringatan">
                            <div>
                                <strong>Instruksi pembayaran belum tersedia.</strong><br>
                                Pesanan Anda <strong>sudah tersimpan</strong> dengan kode <strong><?= e($pesanan['kode']) ?></strong>.
                                Silakan hubungi admin untuk instruksi pembayaran.
                            </div>
                        </div>
                    <?php endif; ?>
                <?php endif; ?>

                <div style="margin-top:22px;border-top:1px solid var(--c-garis);padding-top:16px">
                    <h3 style="font-size:13px;text-transform:uppercase;letter-spacing:.04em;color:var(--c-redup);margin-bottom:10px">Ringkasan Pesanan</h3>
                    <?php if ($pesanan): echo tampil_ringkas($pesanan); endif; ?>
                </div>
            </div>
            <div class="nota-aksi">
                <a href="<?= e($url_nota) ?>" class="btn btn-garis btn-kecil">Lihat Nota</a>
                <a href="<?= e(url('index.php')) ?>" class="btn btn-garis btn-kecil">Beranda</a>
            </div>
        </div>
        <?php endif; ?>
    </div>
</section>
<?php
$skrip_halaman = '';
if ($pesanan) {
    // Polling status pembayaran. Status TIDAK diubah oleh halaman; hanya dibaca ulang dari server.
    $ep   = json_encode(url('status_bayar.php') . '?kode=' . rawurlencode($pesanan['kode']) . '&t=' . rawurlencode((string)$pesanan['token_nota']));
    $nota = json_encode(url('nota.php') . '?kode=' . rawurlencode($pesanan['kode']) . '&t=' . rawurlencode((string)$pesanan['token_nota']));
    $skrip_halaman = "(function(){"
        . "var ep={$ep},urlNota={$nota};"
        . "var b=document.getElementById('badgeStatusBayar');if(!b)return;"
        . "function cek(){fetch(ep,{credentials:'same-origin'}).then(function(r){return r.json()}).then(function(j){"
        . "if(!j||!j.ok)return;b.textContent=j.label;b.className='badge '+j.kelas;"
        . "if(j.status==='berhasil'){window.location.href=urlNota;}"
        . "}).catch(function(){});}"
        . "setInterval(cek,5000);setTimeout(cek,1500);"
        . "})();";
}
require __DIR__ . '/includes/cust_bawah.php';
