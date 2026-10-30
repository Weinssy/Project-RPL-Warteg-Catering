<?php
// Logika bisnis pesanan: dipakai dashboard dan halaman pelanggan.

// Status 'belum_bayar' dipakai saat membuat pesanan. Nilai konstanta lengkap pembayaran
// ada di includes/pembayaran_service.php; di sini disediakan fallback agar aman bila belum dimuat.
if (!defined('BAYAR_BELUM')) define('BAYAR_BELUM', 'belum_bayar');

// Apakah kolom pembayaran (migrasi_pembayaran.sql) sudah ada di tabel pesanan?
// Hasil di-cache per proses agar tidak query berulang.
function kolom_pesanan_baru(mysqli $kon): bool {
    static $cache = null;
    if ($cache !== null) return $cache;
    try {
        $r = db_row($kon, "SHOW COLUMNS FROM pesanan LIKE 'status_bayar'");
        $cache = ($r !== null);
    } catch (Throwable $e) { $cache = false; }
    return $cache;
}

function ringkasan_tanggal(mysqli $kon, string $tgl): array {
    $r = db_row($kon, "SELECT COALESCE(SUM(total_box),0) AS box, COUNT(*) AS pesanan,
            COALESCE(SUM(status='" . STATUS_MENUNGGU . "'),0) AS menunggu,
            COALESCE(SUM(status='" . STATUS_MASAK . "'),0) AS masak,
            COALESCE(SUM(status='" . STATUS_SIAP . "'),0) AS siap
        FROM pesanan WHERE tanggal_ambil = ?", [$tgl]);
    $box = (int)$r['box'];
    return [
        'box' => $box, 'pesanan' => (int)$r['pesanan'], 'menunggu' => (int)$r['menunggu'],
        'masak' => (int)$r['masak'], 'siap' => (int)$r['siap'],
        'sisa' => max(0, KAPASITAS_HARIAN - $box),
        'persen' => min(100, (int)round($box / KAPASITAS_HARIAN * 100)),
        'level' => $box >= KAPASITAS_HARIAN ? 'penuh' : ($box >= KAPASITAS_HARIAN * 0.8 ? 'hampir' : 'aman'),
    ];
}

// Menu + jumlah box dipesan pada tanggal pengambilan + status ketersediaan harian.
// Menu yang sudah dipesan tetap tampil walau kemudian dinonaktifkan.
function menu_per_tanggal(mysqli $kon, string $tgl): array {
    // stok NULL = tanpa batas. x.jml = box yang sudah dipesan pada tanggal tsb.
    return db_all($kon, "SELECT m.id, m.nama, m.kategori, m.harga, COALESCE(mh.foto, m.foto) AS foto, m.aktif,
            COALESCE(mh.tersedia,0) AS tersedia, mh.stok, COALESCE(x.jml,0) AS jumlah
        FROM menu m
        LEFT JOIN menu_harian mh ON mh.menu_id = m.id AND mh.tanggal = ?
        LEFT JOIN (SELECT d.menu_id, SUM(d.jumlah) AS jml
                   FROM detail_pesanan d JOIN pesanan p ON p.id = d.pesanan_id
                   WHERE p.tanggal_ambil = ? GROUP BY d.menu_id) x ON x.menu_id = m.id
        WHERE m.aktif = 1 OR x.jml > 0
        ORDER BY FIELD(m.kategori,'nasi','lauk','sayur', 'paket a', 'paket b', 'paket c'), m.nama", [$tgl, $tgl]);
}

// Satu menu lengkap dengan foto yang efektif pada tanggal tertentu (dipakai formulir edit menu).
function menu_detail(mysqli $kon, int $id, string $tgl): ?array {
    return db_row($kon, "SELECT m.id, m.nama, m.kategori, m.harga, m.aktif,
            m.foto AS foto_master, mh.foto AS foto_harian, COALESCE(mh.foto, m.foto) AS foto,
            COALESCE(mh.tersedia,0) AS tersedia, mh.stok
        FROM menu m
        LEFT JOIN menu_harian mh ON mh.menu_id = m.id AND mh.tanggal = ?
        WHERE m.id = ?", [$tgl, $id]);
}

function hasil_gagal(string $pesan, array $extra = []): array { return ['ok' => false, 'pesan' => $pesan] + $extra; }

/**
 * Membuat pesanan baru. Semua aturan divalidasi di sini (backend), bukan di JavaScript.
 * $in: nama, no_wa, tanggal_ambil (Y-m-d), jam_ambil (HH:MM), items [menu_id => jumlah], catatan (opsional)
 * $ketat: true = aturan pelanggan (H-1 & jam operasional). false = kompatibel dengan pemanggil lama
 *         (dashboard/tests yang memakai tanggal jauh ke depan).
 * Konkurensi: baris kuota_harian untuk tanggal tsb dikunci (FOR UPDATE) selama transaksi,
 * sehingga dua pesanan bersamaan pada tanggal yang sama diproses bergantian.
 */
function buat_pesanan(mysqli $kon, array $in, bool $ketat = false): array {
    $nama  = trim((string)($in['nama'] ?? ''));
    $wa    = normalisasi_wa((string)($in['no_wa'] ?? ''));
    $tgl   = valid_tanggal($in['tanggal_ambil'] ?? null);
    $jam   = (string)($in['jam_ambil'] ?? '');
    $catatan = trim((string)($in['catatan'] ?? ''));

    if ($nama === '' || mb_strlen($nama) > 100)          return hasil_gagal('Nama pelanggan wajib diisi (maksimal 100 karakter).');
    if ($wa === null)                                     return hasil_gagal('Nomor WhatsApp tidak valid.');
    if ($tgl === null || $tgl < date('Y-m-d'))            return hasil_gagal('Tanggal pengambilan tidak valid.');
    if (!preg_match('/^([01]\d|2[0-3]):[0-5]\d$/', $jam)) return hasil_gagal('Jam pengambilan tidak valid.');
    if (mb_strlen($catatan) > 255)                        return hasil_gagal('Catatan maksimal 255 karakter.');

    if ($ketat) {
        // Aturan pelanggan: minimal H-1, maksimal MAKS_HARI_KE hari ke depan, jam operasional.
        $minimal = (new DateTime('today'))->modify('+' . MINIMAL_H_MINUS . ' day')->format('Y-m-d');
        $maks    = (new DateTime('today'))->modify('+' . MAKS_HARI_KE . ' day')->format('Y-m-d');
        if ($tgl < $minimal) return hasil_gagal('Tanggal pengambilan minimal H-' . MINIMAL_H_MINUS . ' (sehari setelah hari ini).', ['field' => 'tanggal_ambil']);
        if ($tgl > $maks)    return hasil_gagal('Tanggal pengambilan paling jauh ' . MAKS_HARI_KE . ' hari ke depan.', ['field' => 'tanggal_ambil']);
        if ($jam < JAM_BUKA || $jam > JAM_TUTUP) {
            return hasil_gagal('Jam pengambilan harus di antara ' . JAM_BUKA . '–' . JAM_TUTUP . ' WIB.', ['field' => 'jam_ambil']);
        }
    }

    $item = [];
    foreach ((array)($in['items'] ?? []) as $mid => $jml) {
        $mid = (int)$mid; $jml = (int)$jml;
        if ($mid > 0 && $jml > 0) $item[$mid] = ($item[$mid] ?? 0) + $jml;
    }
    if (!$item) return hasil_gagal('Pilih minimal satu menu.');
    $total = array_sum($item);
    if ($total < MINIMAL_BOX) return hasil_gagal('Minimum pemesanan ' . MINIMAL_BOX . ' box per transaksi.');
    if ($total > KAPASITAS_HARIAN) return hasil_gagal('Jumlah melebihi kapasitas harian ' . KAPASITAS_HARIAN . ' box.');

    try {
        $kon->query('SET TRANSACTION ISOLATION LEVEL READ COMMITTED');
        $kon->begin_transaction();

        db_exec($kon, 'INSERT IGNORE INTO kuota_harian (tanggal) VALUES (?)', [$tgl]);
        db_val($kon, 'SELECT tanggal FROM kuota_harian WHERE tanggal = ? FOR UPDATE', [$tgl]); // kunci tanggal

        $terpakai = (int)db_val($kon, 'SELECT COALESCE(SUM(total_box),0) FROM pesanan WHERE tanggal_ambil = ?', [$tgl]);
        $sisa = KAPASITAS_HARIAN - $terpakai;
        if ($total > $sisa) {
            $kon->rollback();
            return hasil_gagal('Kuota tidak mencukupi. Sisa kuota untuk ' . tanggal_id($tgl) . ' tinggal ' . max(0, $sisa) . ' box.', ['sisa' => max(0, $sisa)]);
        }

        $ids = array_keys($item);
        $ph  = implode(',', array_fill(0, count($ids), '?'));
        // stok diambil sekaligus; validasinya di bawah, masih di dalam transaksi yang sama.
        $menu = db_all($kon, "SELECT m.id, m.nama, m.harga, mh.stok FROM menu m
            JOIN menu_harian mh ON mh.menu_id = m.id AND mh.tanggal = ? AND mh.tersedia = 1
            WHERE m.aktif = 1 AND m.id IN ($ph)", array_merge([$tgl], $ids));
        if (count($menu) !== count($ids)) {
            $kon->rollback();
            return hasil_gagal('Ada menu yang tidak tersedia pada tanggal tersebut.');
        }

        // ---------- Validasi stok per menu (NULL = tidak dibatasi) ----------
        // Dihitung dari detail_pesanan pada tanggal tsb, konsisten dengan menu_per_tanggal().
        $kurang = [];
        foreach ($menu as $m) {
            if ($m['stok'] === null) continue;
            $mid = (int)$m['id'];
            $terjual = (int)db_val($kon, "SELECT COALESCE(SUM(d.jumlah),0)
                FROM detail_pesanan d JOIN pesanan p ON p.id = d.pesanan_id
                WHERE p.tanggal_ambil = ? AND d.menu_id = ?", [$tgl, $mid]);
            $sisaStok = max(0, (int)$m['stok'] - $terjual);
            if ($item[$mid] > $sisaStok) {
                $kurang[] = $m['nama'] . ' (sisa ' . $sisaStok . ' dari stok ' . (int)$m['stok'] . ' box)';
            }
        }
        if ($kurang) {
            $kon->rollback();
            return hasil_gagal('Stok tidak mencukupi untuk: ' . implode('; ', $kurang) . '.');
        }

        $harga = [];
        $total_harga = 0;
        foreach ($menu as $m) { $harga[(int)$m['id']] = (int)$m['harga']; $total_harga += (int)$m['harga'] * $item[(int)$m['id']]; }

        $urut = (int)db_val($kon, 'SELECT COUNT(*) FROM pesanan WHERE tanggal_ambil = ?', [$tgl]) + 1;
        $kode = sprintf('WRT-%s-%03d', str_replace('-', '', $tgl), $urut);
        $token = bin2hex(random_bytes(20)); // 40 hex: membuka nota tanpa login; tidak berurutan

        // Kolom pembayaran/catatan hanya ada bila migrasi_pembayaran.sql sudah dijalankan.
        // Tanpa migrasi, insert tetap berjalan (kompatibel) tanpa kolom tersebut.
        $ada = kolom_pesanan_baru($kon);
        $token_val = substr($token, 0, 40);
        if ($ada) {
            db_exec($kon, 'INSERT INTO pesanan (kode, nama_pelanggan, no_wa, tanggal_ambil, jam_ambil, total_box, total_harga,
                        nominal_bayar, status_bayar, metode_bayar, token_nota, catatan)
                        VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)',
                [$kode, $nama, $wa, $tgl, $jam . ':00', $total, $total_harga,
                 $total_harga, BAYAR_BELUM, 'qris', $token_val, $catatan === '' ? null : $catatan]);
        } else {
            db_exec($kon, 'INSERT INTO pesanan (kode, nama_pelanggan, no_wa, tanggal_ambil, jam_ambil, total_box, total_harga)
                           VALUES (?, ?, ?, ?, ?, ?, ?)', [$kode, $nama, $wa, $tgl, $jam . ':00', $total, $total_harga]);
        }
        $pid = (int)$kon->insert_id;
        foreach ($item as $mid => $jml) {
            db_exec($kon, 'INSERT INTO detail_pesanan (pesanan_id, menu_id, jumlah, harga_satuan, subtotal) VALUES (?, ?, ?, ?, ?)',
                [$pid, $mid, $jml, $harga[$mid], $harga[$mid] * $jml]);
        }
        $kon->commit();
        return ['ok' => true, 'pesan' => 'Pesanan tersimpan.', 'kode' => $kode, 'id' => $pid,
            'sisa' => $sisa - $total, 'token_nota' => $token_val, 'ada_kolom_bayar' => $ada];
    } catch (Throwable $e) {
        try { $kon->rollback(); } catch (Throwable $x) {}
        error_log('buat_pesanan gagal: ' . $e->getMessage());
        return hasil_gagal('Terjadi kesalahan pada server. Pesanan tidak disimpan.');
    }
}
