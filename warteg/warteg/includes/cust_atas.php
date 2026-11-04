<?php
// Layout atas halaman pelanggan. Variabel sebelum include: $judul (string).
// Menampilkan header, logo, navigasi. Keranjang disimpan di localStorage.
$judul = $judul ?? NAMA_WARTEG;
?><!DOCTYPE html>
<html lang="id">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="csrf-token" content="<?= e(csrf_token()) ?>">
    <meta name="description" content="Pesan nasi box & katering <?= e(NAMA_WARTEG) ?> secara online. Minimum <?= (int)MINIMAL_BOX ?> box, ambil sendiri di lokasi.">
    <title><?= e($judul) ?> - <?= e(NAMA_WARTEG) ?></title>
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700;800&display=swap" rel="stylesheet">
    <link href="https://fonts.googleapis.com/css2?family=Playfair+Display:wght@400;500;600;700;800&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="<?= e(url('assets/css/customer.css')) ?>">
</head>
<body class="cust">
<header class="cust-header">
    <div class="wadah">
        <nav class="cust-nav" aria-label="Navigasi utama">
            <a href="<?= e(url('index.php')) ?>" class="cust-logo">
                <img src="<?= e(url('uploads/1.jpeg')) ?>"
                    alt="Logo Warung Mas Alif"
                     class="logo-ikon">
     
                <span><?= e(NAMA_WARTEG) ?><small>Nasi Box &amp; Katering</small></span>
            </a>
            <div class="cust-links" id="custLinks">
                <a href="<?= e(url('index.php')) ?>">Beranda</a>
                <a href="<?= e(url('index.php#katalog')) ?>">Menu</a>
                <a href="<?= e(url('cara-pemesanan.php')) ?>">Cara Pemesanan</a>
                <a href="<?= e(url('cek_status.php')) ?>">Cek Status Pesanan</a>
            </div>
            <div class="cust-nav-kanan">
                <a href="<?= e(url('index.php#katalog')) ?>" class="btn btn-primer btn-kecil">Pesan Sekarang</a>
                <button type="button" class="cust-toggle" id="custToggle" aria-label="Buka menu" aria-expanded="false" aria-controls="custLinks">
                    <svg width="24" height="24" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-width="2" d="M4 6h16M4 12h16M4 18h16"/></svg>
                </button>
            </div>
        </nav>
    </div>
</header>
<main>
