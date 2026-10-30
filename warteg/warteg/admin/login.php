<?php
require_once __DIR__ . '/../includes/bootstrap.php';
mulai_sesi();
if (!empty($_SESSION['admin_id'])) { header('Location: ' . url('admin/index.php')); exit; }

// Pesan dari halaman profil setelah mengubah kata sandi (wajib login ulang).
$info_login = $_SESSION['flash_login'] ?? '';
unset($_SESSION['flash_login']);

$galat = '';
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_cek();
    $username = trim((string)($_POST['username'] ?? ''));
    $password = (string)($_POST['password'] ?? '');
    $adm = $username !== '' ? db_row($kon, 'SELECT id, nama, foto, role, bahasa, password_hash FROM admin WHERE username = ?', [$username]) : null;
    if ($adm && password_verify($password, $adm['password_hash'])) {
        session_regenerate_id(true);
        $_SESSION['admin_id']     = (int)$adm['id'];
        $_SESSION['admin_nama']   = $adm['nama'];
        $_SESSION['admin_foto']   = $adm['foto'] ?? null;
        $_SESSION['admin_role']   = in_array($adm['role'] ?? 'admin', ['owner', 'admin'], true) ? $adm['role'] : 'admin';
        $_SESSION['admin_bahasa'] = ($adm['bahasa'] ?? 'id') === 'en' ? 'en' : 'id';
        // Catat aktivitas login (tanpa menyimpan kata sandi apa pun).
        catat_aktivitas($kon, 'login', 'Masuk ke sistem');
        header('Location: ' . url('admin/index.php'));
        exit;
    }
    usleep(400000);
    $galat = 'Username atau password salah.';
}
?><!DOCTYPE html>
<html lang="id">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Masuk - <?= e(NAMA_WARTEG) ?></title>
    <link rel="stylesheet" href="<?= e(url('assets/css/admin.css')) ?>">
</head>
<body class="login-body">
    <form class="login-card" method="post" autocomplete="off">
        <h1><?= e(NAMA_WARTEG) ?></h1>
        <p class="teks-redup">Masuk untuk mengelola pesanan nasi box.</p>
        <?php if ($info_login): ?><div class="alert alert-sukses" role="status" style="background:#dcfce7;color:#15803d"><?= e($info_login) ?></div><?php endif; ?>
        <?php if ($galat): ?><div class="alert alert-galat"><?= e($galat) ?></div><?php endif; ?>
        <input type="hidden" name="csrf" value="<?= e(csrf_token()) ?>">
        <label class="form-label" for="username">Username</label>
        <input class="form-control" id="username" name="username" required autofocus>
        <label class="form-label" for="password">Password</label>
        <input class="form-control" id="password" name="password" type="password" required>
        <button class="btn btn-primary btn-blok" type="submit">Masuk</button>
    </form>
</body>
</html>
