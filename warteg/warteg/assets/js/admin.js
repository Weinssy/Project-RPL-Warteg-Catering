// Interaksi antarmuka Admin Warteg. Semua keputusan data (kuota, status, menu) tetap divalidasi di PHP.
(function () {
    'use strict';
    var csrf = (document.querySelector('meta[name="csrf-token"]') || {}).content || '';

    function el(tag, attr, anak) {
        var n = document.createElement(tag);
        Object.keys(attr || {}).forEach(function (k) { if (k === 'class') n.className = attr[k]; else n.setAttribute(k, attr[k]); });
        [].concat(anak == null ? [] : anak).forEach(function (a) { n.appendChild(typeof a === 'string' ? document.createTextNode(a) : a); });
        return n;
    }
    function toast(pesan, tipe) {
        var t = el('div', { 'class': 'toast toast-' + (tipe || 'ok'), role: 'status' }, pesan);
        document.getElementById('toastArea').appendChild(t);
        setTimeout(function () { t.remove(); }, 4500);
    }
    function kirim(url, data) {
        var f = new FormData();
        Object.keys(data).forEach(function (k) { f.append(k, data[k]); });
        f.append('csrf', csrf);
        return fetch(url, { method: 'POST', body: f, credentials: 'same-origin', headers: { 'X-CSRF-Token': csrf } })
            .then(function (r) { return r.json().catch(function () { return { ok: false, pesan: 'Respons server tidak valid.' }; }); })
            .catch(function () { return { ok: false, pesan: 'Tidak dapat menghubungi server.' }; });
    }
    var BASE = (function () { var s = document.querySelector('script[src*="assets/js/admin.js"]'); return s ? s.src.replace(/assets\/js\/admin\.js.*$/, '') : ''; })();

    // ---- Sidebar (layar kecil) ----
    var tombol = document.getElementById('btnSidebar'), lapis = document.getElementById('sidebarOverlay');
    if (tombol) tombol.addEventListener('click', function () { document.body.classList.toggle('sidebar-open'); });
    if (lapis) lapis.addEventListener('click', function () { document.body.classList.remove('sidebar-open'); });

    // ---- Lightbox foto profil ----
    // Elemen dengan atribut data-lightbox="URL_FOTO_ASLI" dapat diklik/di-Enter untuk
    // memperbesar foto. Gambar yang ditampilkan adalah berkas asli (bukan thumbnail),
    // dipusatkan dengan object-fit: contain agar rasio tidak rusak.
    var lightbox = document.getElementById('lightboxFoto');
    if (lightbox) {
        var lbGambar = document.getElementById('lightboxGambar');
        var lbTutup = document.getElementById('lightboxTutup');
        var lbPemicu = null;

        function bukaLightbox(url, alt, pemicu) {
            if (!url) return;
            lbGambar.src = url;
            lbGambar.alt = alt || 'Foto profil';
            lbPemicu = pemicu || null;
            lightbox.hidden = false;
            document.body.classList.add('lightbox-terbuka');
            (lbTutup || lbGambar).focus();
        }
        function tutupLightbox() {
            if (lightbox.hidden) return;
            lightbox.hidden = true;
            document.body.classList.remove('lightbox-terbuka');
            lbGambar.removeAttribute('src');
            if (lbPemicu && typeof lbPemicu.focus === 'function') lbPemicu.focus();
            lbPemicu = null;
        }

        document.addEventListener('click', function (ev) {
            var t = ev.target.closest('[data-lightbox]');
            if (!t) return;
            ev.preventDefault();
            bukaLightbox(t.getAttribute('data-lightbox'), (t.querySelector('img') || {}).alt, t);
        });
        // Dukungan keyboard: Enter/Spasi pada elemen ber-data-lightbox (role button).
        document.addEventListener('keydown', function (ev) {
            if (ev.key !== 'Enter' && ev.key !== ' ') return;
            var t = ev.target.closest && ev.target.closest('[data-lightbox]');
            if (!t) return;
            ev.preventDefault();
            bukaLightbox(t.getAttribute('data-lightbox'), (t.querySelector('img') || {}).alt, t);
        });

        if (lbTutup) lbTutup.addEventListener('click', tutupLightbox);
        // Klik area luar foto (latar gelap) menutup lightbox.
        lightbox.addEventListener('click', function (ev) { if (ev.target === lightbox) tutupLightbox(); });
        document.addEventListener('keydown', function (ev) { if (ev.key === 'Escape') tutupLightbox(); });

        // Buka programatik (dipakai pratinjau foto sebelum disimpan di halaman profil).
        window.bukaLightboxFoto = bukaLightbox;
        window.tutupLightboxFoto = tutupLightbox;
    }

    // ---- Accordion halaman profil ----
    // Status buka/tutup disimpan di localStorage. Formulir TIDAK di-reset saat ditutup,
    // sehingga isian yang belum disimpan tetap ada ketika bagian dibuka kembali.
    var simpanKunci = 'warteg_accordion_';
    document.querySelectorAll('[data-accordion]').forEach(function (acc) {
        var tombol = acc.querySelector('.accordion-kepala');
        var badan = acc.querySelector('.accordion-isi');
        if (!tombol || !badan) return;
        var id = acc.getAttribute('data-accordion');
        var kunci = simpanKunci + id;
        var terbuka = false;
        try {
            var tersimpan = window.localStorage.getItem(kunci);
            terbuka = tersimpan === null ? !!acc.dataset.terbuka : tersimpan === '1';
        } catch (x) { terbuka = !!acc.dataset.terbuka; }

        function terapkan(buka) {
            acc.classList.toggle('terbuka', buka);
            tombol.setAttribute('aria-expanded', buka ? 'true' : 'false');
            badan.hidden = !buka;
        }
        terapkan(terbuka);
        tombol.addEventListener('click', function () {
            var buka = !acc.classList.contains('terbuka');
            terapkan(buka);
            try { window.localStorage.setItem(kunci, buka ? '1' : '0'); } catch (x) {}
        });
    });

    // ---- Ubah status pesanan ----
    document.addEventListener('click', function (ev) {
        var b = ev.target.closest('[data-status]');
        if (!b) return;
        var aksi = b.dataset.status, kode = b.dataset.kode;
        var tanya = aksi === 'siap'
            ? 'Tandai pesanan ' + kode + ' sebagai Siap Diambil dan buka WhatsApp pelanggan?'
            : 'Mulai memasak pesanan ' + kode + '?';
        if (!window.confirm(tanya)) return;
        var jendelaWA = aksi === 'siap' ? window.open('', '_blank') : null; // dibuka sekarang agar tidak diblokir browser
        b.disabled = true;
        kirim(BASE + 'admin/aksi/status_pesanan.php', { id: b.dataset.id, aksi: aksi }).then(function (h) {
            if (!h.ok) {
                if (jendelaWA) jendelaWA.close();
                b.disabled = false;
                toast(h.pesan || 'Gagal memperbarui status.', 'galat');
                return;
            }
            toast(h.pesan, 'ok');
            if (jendelaWA) { if (h.wa_url) jendelaWA.location.href = h.wa_url; else jendelaWA.close(); }
            setTimeout(function () { window.location.reload(); }, 1400);
        });
    });

    // ---- Modal detail pesanan ----
    var modal = document.getElementById('modalDetail');
    if (modal) {
        var isi = document.getElementById('modalIsi');
        var tutup = function () { modal.hidden = true; };
        document.getElementById('modalTutup').addEventListener('click', tutup);
        modal.addEventListener('click', function (e) { if (e.target === modal) tutup(); });
        document.addEventListener('keydown', function (e) { if (e.key === 'Escape') tutup(); });
        document.addEventListener('click', function (ev) {
            var b = ev.target.closest('[data-detail]');
            if (!b) return;
            var d = JSON.parse(b.dataset.detail);
            isi.textContent = '';
            var dl = el('dl');
            [['Kode', d.kode], ['Pelanggan', d.nama], ['WhatsApp', d.wa], ['Tanggal ambil', d.tanggal], ['Jam ambil', d.jam], ['Status', d.status]]
                .forEach(function (p) { dl.appendChild(el('dt', {}, p[0])); dl.appendChild(el('dd', {}, String(p[1]))); });
            var tb = el('tbody');
            d.item.forEach(function (i) {
                tb.appendChild(el('tr', {}, [el('td', {}, i.nama), el('td', {}, String(i.jumlah)), el('td', {}, i.harga), el('td', {}, i.subtotal)]));
            });
            tb.appendChild(el('tr', {}, [el('td', {}, 'Total'), el('td', {}, String(d.box)), el('td', {}), el('td', {}, d.total)]));
            isi.appendChild(dl);
            isi.appendChild(el('div', { 'class': 'table-wrapper' }, el('table', {}, [
                el('thead', {}, el('tr', {}, ['Menu', 'Box', 'Harga', 'Subtotal'].map(function (h) { return el('th', {}, h); }))), tb])));
            modal.hidden = false;
        });
    }

    // ---- Toggle menu harian ----
    var grid = document.getElementById('gridMenu');
    if (grid) {
        grid.addEventListener('change', function (ev) {
            var cb = ev.target;
            if (!cb.classList.contains('js-toggle-menu')) return;
            var kartu = cb.closest('.kartu-menu'), mau = cb.checked;
            cb.disabled = true;
            kirim(BASE + 'admin/aksi/toggle_menu.php', { menu_id: kartu.dataset.menu, tanggal: grid.dataset.tanggal, tersedia: mau ? '1' : '0' }).then(function (h) {
                cb.disabled = false;
                if (!h.ok) { cb.checked = !mau; toast(h.pesan || 'Gagal menyimpan.', 'galat'); return; }
                var on = h.tersedia === 1;
                cb.checked = on;
                kartu.classList.toggle('aktif', on);
                kartu.querySelector('.status-menu').textContent = on ? 'Tersedia untuk dipesan' : 'Tidak tersedia';
                toast(h.pesan, 'ok');
            });
        });
    }

    // ---- Modal edit menu ----
    var modalEdit = document.getElementById('modalEditMenu');
    if (modalEdit) {
        var formEdit = document.getElementById('formEditMenu');
        var inputNama = document.getElementById('editNama');
        var inputKategori = document.getElementById('editKategori');
        var inputHarga = document.getElementById('editHarga');
        var inputAktif = document.getElementById('editAktif');
        var inputStok = document.getElementById('editStok');
        var infoStok = document.getElementById('editStokInfo');
        var inputId = document.getElementById('editMenuId');
        var inputHapus = document.getElementById('editHapusFoto');
        var inputFile = document.getElementById('editFoto');
        var preview = document.getElementById('editFotoPreview');
        var btnHapusFoto = document.getElementById('editHapusFotoBtn');
        var kotakGalat = document.getElementById('editGalat');
        var btnSimpan = document.getElementById('editSimpan');
        var fotoLama = '';

        function tutupEdit() { modalEdit.hidden = true; }
        function setPreview(url) {
            preview.textContent = '';
            if (url) { var img = el('img', { alt: 'Foto menu' }); img.src = url; preview.appendChild(img); }
            else preview.appendChild(el('span', { 'aria-hidden': 'true' }, '🍱'));
            btnHapusFoto.hidden = !url || imgBaru();
        }
        function imgBaru() { return !!(inputFile.files && inputFile.files[0]); }
        function fotoLamaUrl() { return fotoLama ? (BASE + 'uploads/menu/' + encodeURIComponent(fotoLama)) : ''; }
        function tampilGalat(pesan) {
            if (!pesan) { kotakGalat.hidden = true; kotakGalat.textContent = ''; return; }
            kotakGalat.hidden = false; kotakGalat.textContent = pesan;
        }

        function bukaEdit(d) {
            formEdit.reset();
            tampilGalat('');
            inputId.value = d.id;
            inputNama.value = d.nama;
            inputKategori.value = d.kategori;
            inputHarga.value = d.harga;
            inputAktif.value = String(d.aktif);
            inputStok.value = (d.stok === '' || d.stok == null) ? '' : String(d.stok);
            infoStok.textContent = (d.terjual > 0)
                ? d.terjual + ' box sudah dipesan pada tanggal ini. Stok tidak boleh lebih kecil dari jumlah itu.'
                : 'Kosongkan bila tidak dibatasi. Stok dihitung per tanggal pengambilan.';
            inputHapus.value = '0';
            fotoLama = d.foto || '';
            setPreview(d.foto_url || '');
            document.getElementById('judulEditMenu').textContent = 'Edit Menu: ' + d.nama;
            modalEdit.hidden = false;
            inputNama.focus();
        }

        document.addEventListener('click', function (ev) {
            var b = ev.target.closest('.js-edit-menu');
            if (!b) return;
            try { bukaEdit(JSON.parse(b.dataset.menu)); } catch (x) { toast('Data menu tidak dapat dibaca.', 'galat'); }
        });

        document.getElementById('modalEditTutup').addEventListener('click', tutupEdit);
        document.getElementById('editBatal').addEventListener('click', tutupEdit);
        modalEdit.addEventListener('click', function (e) { if (e.target === modalEdit) tutupEdit(); });
        document.addEventListener('keydown', function (e) { if (e.key === 'Escape') tutupEdit(); });

        inputFile.addEventListener('change', function () {
            var f = inputFile.files && inputFile.files[0];
            if (!f) { setPreview(fotoLamaUrl()); return; }
            if (f.size > 2 * 1024 * 1024) { tampilGalat('Ukuran foto maksimal 2 MB.'); inputFile.value = ''; return; }
            tampilGalat('');
            inputHapus.value = '0';
            setPreview(URL.createObjectURL(f));
        });
        btnHapusFoto.addEventListener('click', function () {
            inputFile.value = '';
            inputHapus.value = '1';
            setPreview('');
            btnHapusFoto.hidden = true;
        });

        // Hapus menu. Server tetap penentu akhir: menu yang sudah pernah dipesan ditolak (409).
        var btnHapusMenu = document.getElementById('editHapusMenu');
        btnHapusMenu.addEventListener('click', function () {
            var nama = inputNama.value.trim();
            if (!window.confirm('Hapus menu "' + nama + '" secara permanen?\n\n'
                + 'Menu yang sudah pernah dipesan tidak dapat dihapus (nonaktifkan saja). '
                + 'Tindakan ini tidak dapat dibatalkan.')) return;
            btnHapusMenu.disabled = true;
            btnSimpan.disabled = true;
            tampilGalat('');
            kirim(BASE + 'admin/aksi/hapus_menu.php', { menu_id: inputId.value }).then(function (h) {
                btnHapusMenu.disabled = false;
                btnSimpan.disabled = false;
                if (!h.ok) { tampilGalat(h.pesan || 'Gagal menghapus menu.'); return; }
                tutupEdit();
                toast(h.pesan || 'Menu berhasil dihapus.', 'ok');
                setTimeout(function () { window.location.reload(); }, 1200);
            });
        });

        formEdit.addEventListener('submit', function (ev) {
            ev.preventDefault();
            tampilGalat('');
            var nama = inputNama.value.trim(), harga = parseInt(inputHarga.value, 10);
            if (!nama) { tampilGalat('Nama menu wajib diisi.'); inputNama.focus(); return; }
            if (!harga || harga < 1 || harga > 1000000) { tampilGalat('Harga harus antara Rp1 dan Rp1.000.000.'); inputHarga.focus(); return; }
            var stokRaw = inputStok.value.trim();
            if (stokRaw !== '') {
                var stokMaks = parseInt(inputStok.getAttribute('max'), 10) || 120;
                if (!/^\d+$/.test(stokRaw)) {
                    tampilGalat('Stok harus berupa bilangan bulat (tidak boleh desimal atau negatif).'); inputStok.focus(); return;
                }
                var stokNilai = parseInt(stokRaw, 10);
                if (stokNilai < 0) {
                    tampilGalat('Stok minimal 0 box.'); inputStok.focus(); return;
                }
                if (stokNilai > stokMaks) {
                    tampilGalat('Stok maksimal ' + stokMaks + ' box per tanggal.'); inputStok.focus(); return;
                }
            }

            btnSimpan.disabled = true;
            var fd = new FormData(formEdit);
            fd.set('csrf', csrf);
            fetch(BASE + 'admin/aksi/edit_menu.php', { method: 'POST', body: fd, credentials: 'same-origin', headers: { 'X-CSRF-Token': csrf } })
                .then(function (r) { return r.json().catch(function () { return { ok: false, pesan: 'Respons server tidak valid.' }; }); })
                .then(function (h) {
                    btnSimpan.disabled = false;
                    if (!h.ok) { tampilGalat(h.pesan || 'Gagal menyimpan perubahan.'); return; }
                    tutupEdit();
                    toast(h.pesan || 'Menu berhasil diperbarui.', 'ok');
                    setTimeout(function () { window.location.reload(); }, 1200);
                })
                .catch(function () { btnSimpan.disabled = false; tampilGalat('Tidak dapat menghubungi server.'); });
        });
    }

    // ---- Kalender rekap ----
    var app = document.getElementById('rekapApp');
    if (app) {
        var kap = parseInt(app.dataset.kapasitas, 10), endpoint = app.dataset.endpoint;
        var namaBulan = ['Januari','Februari','Maret','April','Mei','Juni','Juli','Agustus','September','Oktober','November','Desember'];
        var awal = app.dataset.awal, bulan = awal.slice(0, 7), terpilih = awal, dataBulan = {};
        var pad = function (n) { return (n < 10 ? '0' : '') + n; };
        var hariIni = (function () { var d = new Date(); return d.getFullYear() + '-' + pad(d.getMonth() + 1) + '-' + pad(d.getDate()); })();

        function muatBulan() {
            fetch(endpoint + '?bulan=' + bulan, { credentials: 'same-origin' }).then(function (r) { return r.json(); }).then(function (h) {
                dataBulan = h.ok ? h.hari : {};
                if (!h.ok) toast(h.pesan, 'galat');
                gambarKalender();
            }).catch(function () { toast('Tidak dapat memuat kalender.', 'galat'); });
        }
        function gambarKalender() {
            var p = bulan.split('-'), th = parseInt(p[0], 10), bl = parseInt(p[1], 10);
            document.getElementById('judulBulan').textContent = namaBulan[bl - 1] + ' ' + th;
            var grid = document.getElementById('kalender'); grid.textContent = '';
            var offset = (new Date(th, bl - 1, 1).getDay() + 6) % 7, jml = new Date(th, bl, 0).getDate();
            for (var i = 0; i < offset; i++) grid.appendChild(el('div', { 'class': 'sel-hari sel-kosong' }));
            for (var d = 1; d <= jml; d++) {
                var tgl = bulan + '-' + pad(d), info = dataBulan[tgl];
                var kelas = 'sel-hari' + (info ? (info.sisa === 0 ? ' penuh' : ' ada') : '') + (tgl === hariIni ? ' hari-ini' : '') + (tgl === terpilih ? ' dipilih' : '');
                var anak = [el('span', { 'class': 'angka' }, String(d))];
                if (info) anak.push(el('span', { 'class': 'kecil' }, info.pesanan + ' pesanan'), el('span', {}, info.box + ' box'), el('span', { 'class': 'kecil' }, 'sisa ' + info.sisa));
                var b = el('button', { type: 'button', 'class': kelas, 'data-tgl': tgl, 'aria-label': d + ' ' + namaBulan[bl - 1] + (info ? ', ' + info.box + ' box' : ', tidak ada pesanan') }, anak);
                grid.appendChild(b);
            }
        }
        function geserBulan(n) {
            var p = bulan.split('-'), d = new Date(parseInt(p[0], 10), parseInt(p[1], 10) - 1 + n, 1);
            bulan = d.getFullYear() + '-' + pad(d.getMonth() + 1);
            muatBulan();
        }
        function muatRekap(tgl) {
            terpilih = tgl;
            document.querySelectorAll('.sel-hari.dipilih').forEach(function (n) { n.classList.remove('dipilih'); });
            var s = document.querySelector('.sel-hari[data-tgl="' + tgl + '"]'); if (s) s.classList.add('dipilih');
            fetch(endpoint + '?tanggal=' + tgl, { credentials: 'same-origin' }).then(function (r) { return r.json(); }).then(function (h) {
                var isi = document.getElementById('isiRekap'); isi.textContent = '';
                if (!h.ok) { isi.appendChild(el('p', { 'class': 'kosong' }, h.pesan || 'Gagal memuat.')); return; }
                document.getElementById('judulRekap').textContent = h.label;
                var r = h.ringkasan;
                if (!h.pemesan.length) { isi.appendChild(el('p', { 'class': 'kosong' }, 'Belum ada pesanan untuk tanggal ini.')); return; }
                var ul = el('ul', { 'class': 'rekap-menu' });
                h.menu.forEach(function (m) { ul.appendChild(el('li', {}, [el('span', {}, m.nama), el('strong', {}, m.jumlah + ' box')])); });
                ul.appendChild(el('li', { 'class': 'total' }, [el('span', {}, 'Total'), el('strong', {}, r.box + ' dari ' + kap + ' box')]));
                isi.appendChild(ul);
                isi.appendChild(el('div', { 'class': 'progress' }, el('div', { 'class': 'progress-bar level-' + r.level, style: 'width:' + r.persen + '%' })));
                isi.appendChild(el('p', { 'class': 'teks-redup' }, 'Sisa kuota ' + r.sisa + ' box' + (r.sisa === 0 ? ' (penuh)' : '') + ' · ' + r.pesanan + ' pesanan'));
                var daftar = el('div', { 'class': 'rekap-pemesan' });
                h.pemesan.forEach(function (p) {
                    var kls = p.status === 'Siap Diambil' ? 'badge-siap' : (p.status === 'Sedang Dimasak' ? 'badge-masak' : 'badge-menunggu');
                    daftar.appendChild(el('div', { 'class': 'item-pemesan' }, [
                        el('div', { 'class': 'baris' }, [el('strong', {}, p.nama), el('span', { 'class': 'badge ' + kls }, p.status)]),
                        el('div', { 'class': 'teks-redup' }, p.kode + ' · ' + p.jam + ' · ' + p.box + ' box'),
                        el('div', {}, p.rincian || '')
                    ]));
                });
                isi.appendChild(daftar);
            }).catch(function () { toast('Tidak dapat memuat rekap.', 'galat'); });
        }
        document.getElementById('bulanSebelum').addEventListener('click', function () { geserBulan(-1); });
        document.getElementById('bulanBerikut').addEventListener('click', function () { geserBulan(1); });
        document.getElementById('kalender').addEventListener('click', function (e) {
            var b = e.target.closest('[data-tgl]'); if (b) muatRekap(b.dataset.tgl);
        });
        muatBulan(); muatRekap(terpilih);
    }
})();
