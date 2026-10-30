<?php
// Variabel yang harus diset sebelum include: $judul, $menu_aktif
// Label navigasi diambil dari includes/i18n.php agar preferensi bahasa benar-benar diterapkan.
// 'Manajemen Admin' hanya tampil untuk Owner (penyembunyian menu bersifat kosmetik;
// otorisasi sebenarnya tetap di server lewat wajib_owner()).
$navigasi = [
    'dashboard' => ['nav.dashboard', 'admin/index.php',       'M3 12l9-9 9 9M5 10v10h5v-6h4v6h5V10'],
    'rekap'     => ['nav.rekap',     'admin/rekap.php',       'M8 7V3m8 4V3M4 11h16M5 5h14a1 1 0 011 1v14a1 1 0 01-1 1H5a1 1 0 01-1-1V6a1 1 0 011-1z'],
    'pemesan'   => ['nav.pemesan',   'admin/pemesan.php',     'M9 5H7a2 2 0 00-2 2v12a2 2 0 002 2h10a2 2 0 002-2V7a2 2 0 00-2-2h-2M9 5a2 2 0 012-2h2a2 2 0 012 2M9 5h6M9 12h6M9 16h4'],
    'verifikasi'=> ['nav.verifikasi','admin/verifikasi.php',  'M9 12l2 2 4-4m6 2a9 9 0 11-18 0 9 9 0 0118 0z'],
    'menu'      => ['nav.menu',      'admin/menu_harian.php', 'M4 6h16M4 12h16M4 18h10'],
    'tambah'    => ['nav.tambah',    'admin/tambah_menu.php', 'M12 4v16m8-8H4'],
    'qris'      => ['nav.qris',      'admin/qris.php',        'M3 3h7v7H3zM14 3h7v7h-7zM14 14h7v7h-7zM3 14h7v7H3z'],
    'admin'     => ['nav.admin',     'admin/admin.php',       'M12 4.354a4 4 0 110 5.292M15 21H3v-1a6 6 0 0112 0v1zm0 0h6v-1a6 6 0 00-9-5.197M13 7a4 4 0 11-8 0 4 4 0 018 0z'],
];
$nama_saya       = nama_admin();
$foto_saya_nama  = foto_admin_sesi();
$url_foto_saya   = url_foto_admin($foto_saya_nama);
$inisial_saya    = inisial_dari($nama_saya);
$profil_saya_url = url('admin/profil.php');
$bahasa_ui       = bahasa_aktif();
$pemilik         = is_owner($kon ?? null);
?><!DOCTYPE html>
<html lang="<?= e($bahasa_ui) ?>">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="csrf-token" content="<?= e(csrf_token()) ?>">
    <title><?= e($judul) ?> - <?= e(NAMA_WARTEG) ?></title>
    <link rel="stylesheet" href="<?= e(url('assets/css/admin.css')) ?>">
</head>
<body>
<div class="sidebar-overlay" id="sidebarOverlay"></div>
<aside class="sidebar" id="sidebar">
    <div class="logo-section"><?= e(NAMA_WARTEG) ?></div>
    <nav class="nav-menu">
        <?php foreach ($navigasi as $kunci => [$kunci_label, $href, $ikon]): ?>
            <?php if ($kunci === 'admin' && !$pemilik) continue; // menu hanya untuk Owner ?>
            <?php if ($kunci === 'qris' && !$pemilik) continue; // konfigurasi pembayaran hanya Owner ?>
            <a href="<?= e(url($href)) ?>" class="nav-item<?= $kunci === $menu_aktif ? ' active' : '' ?>">
                <svg fill="none" stroke="currentColor" viewBox="0 0 24 24" aria-hidden="true"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.5" d="<?= e($ikon) ?>"/></svg>
                <span><?= e(t($kunci_label, $bahasa_ui)) ?></span>
            </a>
        <?php endforeach; ?>
        <a href="<?= e(url('admin/logout.php')) ?>" class="nav-item nav-keluar">
            <svg fill="none" stroke="currentColor" viewBox="0 0 24 24" aria-hidden="true"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.5" d="M17 16l4-4m0 0l-4-4m4 4H7m6 4v1a3 3 0 01-3 3H6a3 3 0 01-3-3V7a3 3 0 013-3h4a3 3 0 013 3v1"/></svg>
            <span><?= e(t('nav.keluar', $bahasa_ui)) ?></span>
        </a>
    </nav>
</aside>
<div class="main-content">
    <header class="topbar">
        <button type="button" class="btn-hamburger" id="btnSidebar" aria-label="<?= e(t('nav.buka_menu', $bahasa_ui)) ?>">
            <svg width="22" height="22" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-width="2" d="M4 6h16M4 12h16M4 18h16"/></svg>
        </button>
        <div class="topbar-judul">
            <h1><?= e($judul) ?></h1>
            <p><?= e(NAMA_WARTEG) ?> &middot; <?= e(tanggal_id(date('Y-m-d'))) ?></p>
        </div>
        <?php // Foto dapat diklik untuk pratinjau besar, nama dapat diklik untuk membuka halaman profil. ?>
        <div class="topbar-admin">
            <span class="topbar-foto" role="button" tabindex="0" title="<?= e(t('topbar.foto', $bahasa_ui)) ?>"
                  <?= $url_foto_saya ? 'data-lightbox="' . e($url_foto_saya) . '"' : '' ?>>
                <?php if ($url_foto_saya): ?>
                    <img src="<?= e($url_foto_saya) ?>" alt="<?= e(t('topbar.foto', $bahasa_ui)) ?> <?= e($nama_saya) ?>" class="avatar">
                <?php else: ?>
                    <span class="avatar avatar-inisial" aria-hidden="true"><?= e($inisial_saya) ?></span>
                    <span class="visually-hidden">Foto profil belum diatur untuk <?= e($nama_saya) ?></span>
                <?php endif; ?>
            </span>
            <a href="<?= e($profil_saya_url) ?>" class="admin-nama" title="<?= e(t('topbar.profil', $bahasa_ui)) ?>"><?= e($nama_saya) ?></a>
        </div>
    </header>
