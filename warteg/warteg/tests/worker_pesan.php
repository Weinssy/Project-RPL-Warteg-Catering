<?php
// Pembantu uji konkurensi: php worker_pesan.php <tanggal> <menu_id> <jumlah> <waktu_mulai_unix_float>
require_once __DIR__ . '/../includes/bootstrap.php';
[, $tgl, $menu, $jml, $mulai] = $argv;
while (microtime(true) < (float)$mulai) usleep(500);
$h = buat_pesanan($kon, ['nama' => 'Uji Paralel', 'no_wa' => '081200000000', 'tanggal_ambil' => $tgl,
    'jam_ambil' => '10:00', 'items' => [(int)$menu => (int)$jml]]);
echo json_encode($h, JSON_UNESCAPED_UNICODE), "\n";
