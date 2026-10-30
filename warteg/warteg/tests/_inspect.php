<?php
require __DIR__ . '/../config/koneksi.php';
echo "DB ok\n";
foreach (['menu','menu_harian','kuota_harian','pesanan','detail_pesanan'] as $t) {
    $r = $kon->query("SELECT COUNT(*) n FROM `$t`")->fetch_assoc();
    echo "$t: {$r['n']} rows\n";
}
echo "\npesanan columns: ";
$c = $kon->query("SHOW COLUMNS FROM pesanan");
$cols = [];
while ($x = $c->fetch_assoc()) $cols[] = $x['Field'];
echo implode(', ', $cols), "\n";
echo "\nmenu categories: ";
$r = $kon->query("SELECT DISTINCT kategori FROM menu");
$kat = [];
while ($x = $r->fetch_assoc()) $kat[] = $x['kategori'];
echo implode(', ', $kat) ?: '(none)', "\n";
