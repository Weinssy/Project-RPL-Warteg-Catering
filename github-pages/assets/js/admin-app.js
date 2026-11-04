// =====================================================================
// APLIKASI ADMIN (WARTEG GITHUB PAGES + SUPABASE)
// Layout Sidebar, Topbar, Cek Autentikasi Sesi, & UI Helper
// =====================================================================
(function () {
    'use strict';

    var DB = window.WARTEG_DB;
    var CFG = window.WARTEG_CONFIG || {};

    // -----------------------------------------------------------------
    // 1. Cek Sesi Login Admin
    // -----------------------------------------------------------------
    var isLoginPage = window.location.pathname.endsWith('login.html');
    var sesi = DB ? DB.getAdminSesi() : null;

    if (!isLoginPage && !sesi) {
        window.location.href = 'login.html';
        return;
    }
    if (isLoginPage && sesi) {
        window.location.href = 'index.html';
        return;
    }

    // -----------------------------------------------------------------
    // 2. Render Sidebar & Topbar Otomatis
    // -----------------------------------------------------------------
    function renderAdminLayout(menuAktif, judulHalaman) {
        if (isLoginPage) return;

        var isOwner = (sesi && sesi.role === 'owner');
        var namaAdmin = (sesi && sesi.nama) || 'Admin Warteg';
        var fotoAdmin = (sesi && sesi.foto) ? ('../uploads/admin/' + sesi.foto) : '';
        var inisial = namaAdmin.charAt(0).toUpperCase();

        var navList = [
            { k: 'dashboard', t: 'Dashboard', h: 'index.html', i: 'M3 12l9-9 9 9M5 10v10h5v-6h4v6h5V10' },
            { k: 'rekap', t: 'Rekap Harian', h: 'rekap.html', i: 'M8 7V3m8 4V3M4 11h16M5 5h14a1 1 0 011 1v14a1 1 0 01-1 1H5a1 1 0 01-1-1V6a1 1 0 011-1z' },
            { k: 'pemesan', t: 'Daftar Pemesan', h: 'pemesan.html', i: 'M9 5H7a2 2 0 00-2 2v12a2 2 0 002 2h10a2 2 0 002-2V7a2 2 0 00-2-2h-2M9 5a2 2 0 012-2h2a2 2 0 012 2M9 5h6M9 12h6M9 16h4' },
            { k: 'verifikasi', t: 'Verifikasi Pembayaran', h: 'verifikasi.html', i: 'M9 12l2 2 4-4m6 2a9 9 0 11-18 0 9 9 0 0118 0z' },
            { k: 'menu', t: 'Menu Harian', h: 'menu_harian.html', i: 'M4 6h16M4 12h16M4 18h10' },
            { k: 'tambah', t: 'Tambah Menu', h: 'tambah_menu.html', i: 'M12 4v16m8-8H4' },
            { k: 'qris', t: 'Pengaturan QRIS', h: 'qris.html', i: 'M3 3h7v7H3zM14 3h7v7h-7zM14 14h7v7h-7zM3 14h7v7H3z', owner: true },
            { k: 'admin', t: 'Manajemen Admin', h: 'admin.html', i: 'M12 4.354a4 4 0 110 5.292M15 21H3v-1a6 6 0 0112 0v1zm0 0h6v-1a6 6 0 00-9-5.197M13 7a4 4 0 11-8 0 4 4 0 018 0z', owner: true }
        ];

        // Sidebar HTML
        var aside = document.createElement('aside');
        aside.className = 'sidebar';
        aside.id = 'sidebar';

        var navHtml = '<div class="logo-section">' + CFG.NAMA_WARTEG + '</div><nav class="nav-menu">';
        navList.forEach(function (it) {
            if (it.owner && !isOwner) return;
            var act = (it.k === menuAktif) ? ' active' : '';
            navHtml += '<a href="' + it.h + '" class="nav-item' + act + '">'
                + '<svg fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.5" d="' + it.i + '"/></svg>'
                + '<span>' + it.t + '</span></a>';
        });
        navHtml += '<a href="javascript:void(0)" id="btnKeluarAdmin" class="nav-item nav-keluar">'
            + '<svg fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.5" d="M17 16l4-4m0 0l-4-4m4 4H7m6 4v1a3 3 0 01-3 3H6a3 3 0 01-3-3V7a3 3 0 013-3h4a3 3 0 013 3v1"/></svg>'
            + '<span>Keluar</span></a></nav>';
        aside.innerHTML = navHtml;

        var overlay = document.createElement('div');
        overlay.className = 'sidebar-overlay';
        overlay.id = 'sidebarOverlay';

        // Topbar HTML
        var header = document.createElement('header');
        header.className = 'topbar';
        header.innerHTML = '<button type="button" class="btn-hamburger" id="btnSidebar" aria-label="Buka menu">'
            + '<svg width="22" height="22" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-width="2" d="M4 6h16M4 12h16M4 18h16"/></svg>'
            + '</button>'
            + '<div class="topbar-judul">'
            + '<h1>' + (judulHalaman || 'Dashboard Admin') + '</h1>'
            + '<p>' + CFG.NAMA_WARTEG + ' · ' + DB.tanggalId(new Date().toISOString().slice(0, 10)) + '</p>'
            + '</div>'
            + '<div class="topbar-admin">'
            + '<span class="topbar-foto" role="button" tabindex="0">'
            + (fotoAdmin ? '<img src="' + fotoAdmin + '" alt="' + namaAdmin + '" class="avatar" onerror="this.outerHTML=\'<span class=avatar>' + inisial + '</span>\'">' : '<span class="avatar avatar-inisial">' + inisial + '</span>')
            + '</span>'
            + '<a href="profil.html" class="admin-nama" title="Buka profil">' + namaAdmin + '</a>'
            + '</div>';

        document.body.prepend(aside);
        document.body.prepend(overlay);

        var mainWrap = document.querySelector('.main-content');
        if (mainWrap) {
            mainWrap.prepend(header);
        }

        // Event Toggle Sidebar
        var btnSide = document.getElementById('btnSidebar');
        if (btnSide) {
            btnSide.onclick = function () { document.body.classList.toggle('sidebar-open'); };
        }
        overlay.onclick = function () { document.body.classList.remove('sidebar-open'); };

        // Event Keluar
        var btnKeluar = document.getElementById('btnKeluarAdmin');
        if (btnKeluar) {
            btnKeluar.onclick = function () {
                if (confirm('Keluar dari dashboard admin?')) {
                    DB.logoutAdmin();
                    window.location.href = 'login.html';
                }
            };
        }
    }

    // Lightbox Foto Global
    function initLightbox() {
        var lb = document.getElementById('lightboxFoto');
        if (!lb) {
            lb = document.createElement('div');
            lb.id = 'lightboxFoto';
            lb.hidden = true;
            lb.style.cssText = 'position:fixed;top:0;left:0;right:0;bottom:0;background:rgba(0,0,0,0.85);z-index:9999;display:none;align-items:center;justify-content:center;padding:20px;';
            lb.innerHTML = '<img id="lightboxImg" style="max-width:90%;max-height:90%;border-radius:8px;box-shadow:0 10px 25px rgba(0,0,0,0.5);"><button type="button" style="position:absolute;top:20px;right:20px;color:#fff;background:none;border:none;font-size:32px;cursor:pointer;">&times;</button>';
            document.body.appendChild(lb);
            lb.onclick = function () { lb.style.display = 'none'; };
        }
        document.addEventListener('click', function (e) {
            var target = e.target.closest('[data-lightbox]');
            if (target) {
                var url = target.getAttribute('data-lightbox') || target.src;
                if (url) {
                    var img = document.getElementById('lightboxImg');
                    if (img) img.src = url;
                    lb.style.display = 'flex';
                }
            }
        });
    }

    window.WARTEG_ADMIN_LAYOUT = {
        render: renderAdminLayout,
        initLightbox: initLightbox
    };
})();

