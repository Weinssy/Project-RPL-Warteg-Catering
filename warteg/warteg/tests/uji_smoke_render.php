<?php
// Smoke test render: pastikan halaman tidak memunculkan warning/notice PHP.
$base = 'http://127.0.0.1:8765';
$jar  = sys_get_temp_dir() . '/wt_smoke_' . getmypid() . '.txt';
@unlink($jar);
function req(string $url, array $post = null, string $jar = ''): array {
    $ch = curl_init($url);
    curl_setopt_array($ch, [CURLOPT_RETURNTRANSFER => true, CURLOPT_COOKIEJAR => $jar, CURLOPT_COOKIEFILE => $jar, CURLOPT_FOLLOWLOCATION => true, CURLOPT_TIMEOUT => 10]);
    if ($post !== null) { curl_setopt($ch, CURLOPT_POST, true); curl_setopt($ch, CURLOPT_POSTFIELDS, $post); }
    $out = curl_exec($ch); $code = curl_getinfo($ch, CURLINFO_HTTP_CODE); curl_close($ch);
    return [$code, (string)$out];
}
[, $lp] = req($base . '/admin/login.php', null, $jar);
preg_match('/name="csrf" value="([a-f0-9]+)"/', $lp, $m);
req($base . '/admin/login.php', ['username' => 'admin', 'password' => 'admin123', 'csrf' => $m[1] ?? ''], $jar);
$gagal = 0;
foreach (['admin/index.php', 'admin/tambah_menu.php', 'admin/menu_harian.php', 'admin/pemesan.php', 'admin/rekap.php', 'admin/admin.php'] as $u) {
    [$c, $h] = req($base . '/' . $u, null, $jar);
    $err = preg_match('/(Fatal error|Warning|Notice|Deprecated|Parse error|Uncaught)/', $h);
    echo (($c === 200 && !$err) ? '  [OK]   ' : '  [GAGAL] ') . "$u -> HTTP $c, len=" . strlen($h) . ', php_errors=' . ($err ? 'YA' : 'tidak') . PHP_EOL;
    if ($c !== 200 || $err) $gagal++;
}
@unlink($jar);
echo "\n" . ($gagal === 0 ? 'SMOKE RENDER LULUS' : "ADA $gagal MASALAH") . PHP_EOL;
exit($gagal === 0 ? 0 : 1);
