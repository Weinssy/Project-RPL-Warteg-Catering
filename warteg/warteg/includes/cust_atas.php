<?php
// Layout atas halaman pelanggan. Variabel sebelum include: $judul (string).
// Menampilkan header, logo, navigasi, indikator keranjang. Keranjang disimpan di localStorage.
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
                <a href="<?= e(url('index.php#katalog')) ?>">Katalog Nasi Box</a>
                <a href="<?= e(url('index.php#cara')) ?>">Cara Pemesanan</a>
                <a href="<?= e(url('cek_status.php')) ?>">Cek Status Pesanan</a>
            </div>
            <div class="cust-nav-kanan">
                <a href="<?= e(url('index.php#katalog')) ?>" class="tombol-keranjang" id="tombolKeranjang" aria-label="Lihat keranjang">
                    <svg width="18" height="18" fill="none" stroke="currentColor" viewBox="0 0 24 24" aria-hidden="true"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M3 3h2l.4 2M7 13h10l4-8H5.4M7 13L5.4 5M7 13l-2.293 2.293c-.63.63-.184 1.707.707 1.707H17m0 0a2 2 0 100 4 2 2 0 000-4zm-8 2a2 2 0 11-4 0 2 2 0 014 0z"/></svg>
                    <span class="btn-teks-desk">Keranjang</span>
                    <span class="hitung" id="hitungKeranjang">0</span>
                </a>
                <a href="<?= e(url('index.php#katalog')) ?>" class="btn btn-primer btn-kecil">Pesan Sekarang</a>
                <button type="button" class="cust-toggle" id="custToggle" aria-label="Buka menu" aria-expanded="false" aria-controls="custLinks">
                    <svg width="24" height="24" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-width="2" d="M4 6h16M4 12h16M4 18h16"/></svg>
                </button>
            </div>
        </nav>
    </div>
</header>
<main>
