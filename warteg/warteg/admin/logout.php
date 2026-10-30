<?php
require_once __DIR__ . '/../includes/bootstrap.php';
mulai_sesi();
// Catat aktivitas keluar SELAMA sesi masih valid (dibutuhkan untuk id & nama admin).
if (!empty($_SESSION['admin_id'])) {
    catat_aktivitas($kon, 'logout', 'Keluar dari sistem');
}
$_SESSION = [];
session_destroy();
header('Location: ' . url('admin/login.php'));
exit;
