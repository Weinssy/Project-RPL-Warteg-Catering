<?php
require_once __DIR__ . '/../../includes/bootstrap.php';
if ($_SERVER['REQUEST_METHOD'] !== 'GET') json_out(['ok' => false, 'pesan' => 'Metode tidak diizinkan.'], 405);
wajib_login(true);

try {
    if (isset($_GET['bulan'])) {
        $b = (string)$_GET['bulan'];
        if (!preg_match('/^\d{4}-(0[1-9]|1[0-2])$/', $b)) json_out(['ok' => false, 'pesan' => 'Bulan tidak valid.'], 400);
        $awal  = $b . '-01';
        $akhir = (new DateTime($awal))->modify('last day of this month')->format('Y-m-d');
        $hari = [];
        foreach (db_all($kon, 'SELECT tanggal_ambil AS tgl, COUNT(*) AS pesanan, SUM(total_box) AS box
                FROM pesanan WHERE tanggal_ambil BETWEEN ? AND ? GROUP BY tanggal_ambil', [$awal, $akhir]) as $r) {
            $box = (int)$r['box'];
            $hari[$r['tgl']] = ['pesanan' => (int)$r['pesanan'], 'box' => $box, 'sisa' => max(0, KAPASITAS_HARIAN - $box)];
        }
        json_out(['ok' => true, 'bulan' => $b, 'hari' => $hari]);
    }

    $tgl = valid_tanggal($_GET['tanggal'] ?? null);
    if (!$tgl) json_out(['ok' => false, 'pesan' => 'Tanggal tidak valid.'], 400);

    $menu = array_values(array_map(fn($m) => ['nama' => $m['nama'], 'jumlah' => (int)$m['jumlah']],
        array_filter(menu_per_tanggal($kon, $tgl), fn($m) => (int)$m['jumlah'] > 0)));
    $pemesan = db_all($kon, "SELECT p.kode, p.nama_pelanggan AS nama, p.no_wa, p.jam_ambil AS jam, p.total_box AS box, p.status,
            (SELECT GROUP_CONCAT(CONCAT(m.nama, ' x', d.jumlah) ORDER BY m.nama SEPARATOR ', ')
             FROM detail_pesanan d JOIN menu m ON m.id = d.menu_id WHERE d.pesanan_id = p.id) AS rincian
        FROM pesanan p WHERE p.tanggal_ambil = ? ORDER BY p.jam_ambil, p.id", [$tgl]);
    foreach ($pemesan as &$p) { $p['jam'] = jam_pendek($p['jam']); $p['box'] = (int)$p['box']; }
    unset($p);
    json_out(['ok' => true, 'tanggal' => $tgl, 'label' => tanggal_id($tgl),
              'ringkasan' => ringkasan_tanggal($kon, $tgl), 'menu' => $menu, 'pemesan' => $pemesan]);
} catch (Throwable $e) {
    error_log('rekap gagal: ' . $e->getMessage());
    json_out(['ok' => false, 'pesan' => 'Gagal memuat data rekap.'], 500);
}
