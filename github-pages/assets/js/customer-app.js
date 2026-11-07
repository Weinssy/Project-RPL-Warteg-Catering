// =====================================================================
// APLIKASI PELANGGAN (WARTEG PAK SANAM - GITHUB PAGES + SUPABASE)
// Mengatur UI: Keranjang, Meal Builder, Cek Kuota, Filter Kategori, & Form Checkout
// =====================================================================
(function () {
    'use strict';

    var DB = window.WARTEG_DB;
    var CFG = window.WARTEG_CONFIG || {};

    function $(s, r) { return (r || document).querySelector(s); }
    function $$(s, r) { return [].slice.call((r || document).querySelectorAll(s)); }

    function toast(msg, tipe) {
        var area = $('#custToast');
        if (!area) return;
        var t = document.createElement('div');
        t.className = 'cust-toast ' + (tipe || '');
        t.setAttribute('role', 'status');
        t.textContent = msg;
        area.appendChild(t);
        setTimeout(function () { t.remove(); }, 3500);
    }

    function formatRupiah(n) { return DB ? DB.rupiah(n) : 'Rp' + n; }

    // -----------------------------------------------------------------
    // Format Tanggal Minimal (H-1) & Maksimal (H+30)
    // -----------------------------------------------------------------
    function getOffsetDate(dOffset) {
        var d = new Date();
        d.setDate(d.getDate() + dOffset);
        var yyyy = d.getFullYear();
        var mm = String(d.getMonth() + 1).padStart(2, '0');
        var dd = String(d.getDate()).padStart(2, '0');
        return yyyy + '-' + mm + '-' + dd;
    }

    var tglMin = getOffsetDate(CFG.MINIMAL_H_MINUS || 1);
    var tglMaks = getOffsetDate(CFG.MAKS_HARI_KE || 30);
    var tglPilih = tglMin;
    var katalogMenusCache = [];

    // -----------------------------------------------------------------
    // Validasi Nama Pemesan (Tanpa Angka)
    // -----------------------------------------------------------------
    function adaHuruf(t) { return /[A-Za-z\u00C0-\u024F\u1E00-\u1EFF]/.test(t); }
    function namaValid(s) {
        var t = String(s == null ? '' : s).trim();
        if (!t) return false;
        if (/\d/.test(t)) return false;   // Tolak angka sama sekali
        return adaHuruf(t);               // Wajib ada minimal satu huruf
    }

    function pesanGalatNama(s) {
        var t = String(s == null ? '' : s).trim();
        if (!t) return 'Nama pemesan wajib diisi.';
        if (/\d/.test(t)) return 'Nama tidak boleh mengandung angka.';
        if (!adaHuruf(t)) return 'Nama harus mengandung huruf, tidak boleh berupa angka saja.';
        return '';
    }

    function waValid(v) {
        var d = String(v || '').replace(/[^\d]/g, '');
        if (d.charAt(0) === '0') d = '62' + d.slice(1);
        return d.length >= 10 && d.length <= 16;
    }

    function tampilGalat(field, pesan) {
        var el = $('.pesan-galat[data-untuk="' + field + '"]');
        if (el) {
            el.textContent = pesan || '';
            el.style.display = pesan ? 'block' : 'none';
        }
    }

    // -----------------------------------------------------------------
    // Deskripsi Menu Otomatis (Untuk Menu Supabase / LocalStorage)
    // -----------------------------------------------------------------
    var deskripsiDefaultMap = {
        'paket hemat komplit': 'Nasi Putih Wangi + Ayam Goreng Lengkuas Rempah + Tempe Orek Basah + Tumis Buncis Jagung + Sambal Terasi & Lalapan',
        'paket selera nusantara a': 'Nasi Putih Wangi + Ayam Goreng Lengkuas Rempah + Tempe Orek Basah + Tumis Buncis Jagung + Sambal Terasi & Lalapan',
        'paket b - rendang daging sapi empuk': 'Nasi Putih Wangi + Rendang Empuk + Sayur Daun Singkong + Telur Dadar + Sambal Ijo',
        'paket c - ikan tongkol suwir balado': 'Nasi Putih + Tongkol Suwir Pedas Gurih + Sayur Urap Kelapa + Bakwan Jagung + Sambal',
        'nasi ayam': 'Nasi putih pulen hangat + Ayam goreng bumbu lengkuas rempah + Tempe orek + Sambal terasi & lalapan segar',
        'nasi ayam lengkuas spesial': 'Nasi putih pulen hangat + Ayam goreng bumbu lengkuas rempah + Tempe orek + Sambal terasi & lalapan segar',
        'nasi ikan': 'Nasi putih pulen + Ikan goreng bumbu kuning gurih + Sayur harian + Sambal khas Warteg Pak Sanam',
        'nasi ikan bumbu kuning': 'Nasi putih pulen + Ikan goreng bumbu kuning gurih + Sayur harian + Sambal khas Warteg Pak Sanam',
        'nasi udang': 'Nasi putih pulen + Udang masak balado merah pedas gurih + Tumis sayur segar + Sambal',
        'nasi udang balado pete': 'Nasi putih pulen + Udang masak balado merah pedas gurih + Tumis sayur segar + Sambal',
        'ayam lengkuas': 'Ayam potong goreng bumbu rempah lengkuas gurih khas Warteg Pak Sanam.',
        'ayam bakar bumbu madu khas pak sanam': 'Ayam potong bakar dengan olesan kecap madu dan rempah khas warteg.',
        'cumi asin tumis cabe ijo': 'Cumi asin empuk tidak alot dimasak dengan cabai hijau besar dan tomat ijo segar.',
        'sayur asem segar': 'Sayur asem kuah segar lengkap dengan jagung manis, melinjo, labu siam, dan kacang panjang.',
        'sayur asem segar jakarta': 'Sayur asem kuah segar lengkap dengan jagung manis, melinjo, labu siam, dan kacang panjang.'
    };

    function getDeskripsiMenu(m) {
        if (m.deskripsi && m.deskripsi.trim()) return m.deskripsi;
        var key = String(m.nama || '').toLowerCase().trim();
        if (deskripsiDefaultMap[key]) return deskripsiDefaultMap[key];
        var kat = String(m.kategori || '').toLowerCase();
        if (kat.indexOf('paket') === 0) {
            return 'Nasi Putih Wangi + Lauk Utama Pilihan + Sayur Tumis Segar + Tempe Orek + Sambal Khas & Alat Makan Higienis';
        }
        if (kat === 'nasi') {
            return 'Nasi box lengkap dengan lauk utama pilihan, sayur harian segar, sambal khas Pak Sanam, dan lalapan.';
        }
        if (kat === 'lauk') {
            return 'Lauk masakan rumahan segar bumbu rempah otentik khas dapur Warteg Pak Sanam.';
        }
        return 'Sayur & pelengkap masakan harian segar dimasak setiap pagi.';
    }

    function getUnitMenu(kat) {
        var k = String(kat || '').toLowerCase();
        if (k === 'lauk' || k === 'sayur') return 'Porsi';
        return 'Box';
    }

    // -----------------------------------------------------------------
    // Keranjang Belanja (LocalStorage)
    // -----------------------------------------------------------------
    var CART_KEY = 'warteg_cart_items';
    var keranjang = {};
    try {
        keranjang = JSON.parse(localStorage.getItem(CART_KEY) || '{}') || {};
    } catch (e) { keranjang = {}; }

    function simpanKeranjang() {
        try { localStorage.setItem(CART_KEY, JSON.stringify(keranjang)); } catch (e) { }
    }

    function totalBox() {
        var t = 0;
        Object.keys(keranjang).forEach(function (k) { t += (keranjang[k].jumlah || 0); });
        return t;
    }

    function subtotal() {
        var t = 0;
        Object.keys(keranjang).forEach(function (k) { t += ((keranjang[k].harga || 0) * (keranjang[k].jumlah || 0)); });
        return t;
    }

    // -----------------------------------------------------------------
    // Pilihan Jam Pengambilan
    // -----------------------------------------------------------------
    function isiPilihanJam() {
        var sel = $('#fJam');
        if (!sel) return;
        var buka = CFG.JAM_BUKA || '09:00', tutup = CFG.JAM_TUTUP || '19:00';
        var b = parseInt(buka.slice(0, 2), 10), t = parseInt(tutup.slice(0, 2), 10);
        sel.innerHTML = '';
        for (var h = b; h <= t; h++) {
            for (var mi = 0; mi < 60; mi += 30) {
                if (h === t && mi > 0) break;
                var hh = String(h).padStart(2, '0'), mm = String(mi).padStart(2, '0');
                var opt = document.createElement('option');
                opt.value = hh + ':' + mm;
                opt.textContent = hh + ':' + mm + ' WIB';
                sel.appendChild(opt);
            }
        }
    }

    async function perbaruiKapasitas(tanggal) {
        if (!DB) return;
        var kap = await DB.getKapasitasTanggal(tanggal);
        var maks = kap.kapasitas || 120;
        if ($('#kapTerpakai')) $('#kapTerpakai').textContent = kap.box;
        if ($('#kapSisa')) $('#kapSisa').textContent = kap.sisa;
        if ($('#navSisaKuota')) $('#navSisaKuota').textContent = kap.sisa;

        // Progress bar menunjukkan proporsi ketersediaan kuota dapur
        var persenSisa = maks > 0 ? Math.max(6, Math.min(100, Math.round((kap.sisa / maks) * 100))) : 100;
        if ($('#barKapasitas')) {
            $('#barKapasitas').style.width = (kap.sisa === 0 ? 0 : persenSisa) + '%';
        }
        if ($('#kotakSisa')) {
            $('#kotakSisa').className = 'kapasitas-kotak sisa ' + kap.level;
        }
        if ($('#badgeKuotaAman')) {
            if (kap.level === 'penuh') {
                $('#badgeKuotaAman').textContent = '✕ KUOTA PENUH';
                if ($('#badgeKuotaSub')) $('#badgeKuotaSub').textContent = 'Pilih tanggal lain';
            } else if (kap.level === 'hampir') {
                $('#badgeKuotaAman').textContent = '⚠ HAMPIR PENUH';
                if ($('#badgeKuotaSub')) $('#badgeKuotaSub').textContent = 'Sisa ' + kap.sisa + ' box lagi';
            } else {
                $('#badgeKuotaAman').textContent = '✓ KUOTA AMAN';
                if ($('#badgeKuotaSub')) $('#badgeKuotaSub').textContent = 'Pesanan siap diterima';
            }
        }
        if ($('#teksTanggal')) {
            $('#teksTanggal').textContent = 'Jadwal: ' + DB.tanggalId(tanggal) + ' (' + CFG.JAM_BUKA + '–' + CFG.JAM_TUTUP + ' WIB) · Batas 120 box/hari menjaga masakan tetap segar prima';
        }
    }

    var labelKategori = {
        'paket a': ['Paket Nasi Box A', 'Paket komplit favorit siap santap lengkap dengan dus sekat & alat makan.'],
        'paket b': ['Paket Nasi Box B', 'Paket spesial menu daging & lauk pilihan untuk acara penting.'],
        'paket c': ['Paket Nasi Box C', 'Paket hemat praktis dengan komposisi lauk Nusantara.'],
        'nasi': ['Pilihan Nasi Box Harian', 'Nasi putih pulen hangat dengan lauk utama dan sayur harian.'],
        'lauk': ['Lauk Ayam, Daging & Seafood', 'Pilihan lauk tambahan atau racikan porsi katering.'],
        'sayur': ['Sayuran & Pelengkap', 'Sayur segar harian dan pelengkap makan siang.']
    };

    function perbaruiLabelFilter(menus) {
        var wrap = $('#kategoriFilter');
        if (!wrap || !menus) return;
        var counts = {
            semua: menus.length,
            paket: 0,
            nasi: 0,
            lauk: 0,
            sayur: 0
        };
        menus.forEach(function (m) {
            var k = String(m.kategori || 'nasi').toLowerCase();
            if (k.indexOf('paket') === 0) counts.paket++;
            else if (counts[k] !== undefined) counts[k]++;
        });

        var labelMap = {
            semua: 'Semua Menu (' + counts.semua + ')',
            paket: 'Paket Nasi Box (' + counts.paket + ')',
            nasi: 'Nasi Pilihan (' + counts.nasi + ')',
            lauk: 'Lauk Ayam & Daging (' + counts.lauk + ')',
            sayur: 'Sayuran & Pelengkap (' + counts.sayur + ')'
        };

        $$('.filter-btn', wrap).forEach(function (btn) {
            var f = btn.getAttribute('data-filter');
            if (labelMap[f]) btn.textContent = labelMap[f];
        });
    }

    async function muatKatalog(tanggal) {
        var wadah = $('#katalogMenu');
        if (!wadah || !DB) return;
        wadah.innerHTML = '<div class="kosong"><p>Memuat menu untuk tanggal ' + DB.tanggalId(tanggal, false) + '...</p></div>';

        var menus = await DB.getMenuTanggal(tanggal);
        katalogMenusCache = menus || [];
        if (!menus || !menus.length) {
            wadah.innerHTML = '<div class="kosong"><div class="ikon">🍽️</div><p>Belum ada menu yang dibuka untuk tanggal ini. Silakan pilih tanggal lain di atas.</p></div>';
            return;
        }

        perbaruiLabelFilter(menus);

        // Kelompokkan per kategori (Dahulukan Paket Nasi Box, lalu Nasi, Lauk, Sayur)
        var grup = {};
        menus.forEach(function (m) {
            var k = String(m.kategori || 'nasi').toLowerCase();
            grup[k] = grup[k] || [];
            grup[k].push(m);
        });

        var html = '';
        var urutan = ['paket a', 'paket b', 'paket c', 'nasi', 'lauk', 'sayur'];
        urutan.forEach(function (kat) {
            var items = grup[kat];
            if (!items || !items.length) return;
            var infoKat = labelKategori[kat] || [kat.toUpperCase(), ''];
            var grupFilter = kat.indexOf('paket') === 0 ? 'paket' : kat;

            html += '<div class="kategori-blok" data-kategori="' + kat + '" data-grup-filter="' + grupFilter + '">';
            html += '<div class="kategori-blok-head">';
            html += '<h3>' + infoKat[0] + '</h3>';
            html += '<p class="aturan">' + infoKat[1] + '</p>';
            html += '</div>';
            html += '<div class="list-menu-cust">';

            items.forEach(function (m) {
                var tersedia = m.tersedia && m.aktif;
                var sisa = m.stok === null ? null : Math.max(0, m.stok - (m.jumlah || 0));
                var habis = (sisa !== null && sisa <= 0);
                var bisa = tersedia && !habis;
                var itemKeranjang = keranjang[m.id];
                var jmlPilih = itemKeranjang ? (itemKeranjang.jumlah || 0) : 0;
                var unitLabel = getUnitMenu(kat);
                var deskripsi = getDeskripsiMenu(m);

                var kelasStok = '';
                if (sisa !== null) {
                    kelasStok = sisa <= 0 ? 'habis' : (sisa <= (m.stok * 0.2) ? 'tipis' : 'aman');
                }

                var imgUrl = m.foto ? ('uploads/menu/' + m.foto) : '';

                html += '<div class="kartu-cust kartu-row' + (bisa ? '' : ' habis') + (jmlPilih > 0 ? ' dipilih' : '') + '"'
                    + ' data-menu-id="' + m.id + '"'
                    + ' data-nama="' + m.nama + '"'
                    + ' data-harga="' + m.harga + '"'
                    + ' data-kategori="' + kat + '"'
                    + ' data-unit="' + unitLabel + '"'
                    + ' data-sisa-stok="' + (sisa === null ? '' : sisa) + '">';

                // Thumbnail kiri
                html += '<div class="kartu-row-thumb">';
                if (imgUrl) {
                    html += '<img src="' + imgUrl + '" alt="' + m.nama + '" loading="lazy" onerror="this.onerror=null;this.parentNode.innerHTML=\'🍱\';">';
                } else {
                    html += '<span aria-hidden="true">🍱</span>';
                }
                html += '</div>';

                // Info tengah (Judul, Deskripsi, Harga & Stok)
                html += '<div class="kartu-row-info">';
                html += '<div class="nama">' + m.nama + '</div>';
                html += '<div class="desk">' + deskripsi + '</div>';
                html += '<div class="kartu-row-meta">';
                html += '<span class="harga">' + formatRupiah(m.harga) + ' <small>/ ' + unitLabel + '</small></span>';
                html += '<span class="badge-stok ' + kelasStok + '">';
                if (!tersedia) html += '• Tidak tersedia';
                else if (sisa === null) html += '• Tersedia';
                else if (habis) html += '• Stok habis';
                else html += '• Sisa ' + sisa + ' ' + unitLabel.toLowerCase();
                html += '</span>';
                html += '</div>';
                html += '</div>';

                // Stepper kanan langsung pada kartu
                html += '<div class="kartu-row-aksi">';
                if (bisa) {
                    html += '<div class="stepper-kartu">';
                    html += '<button type="button" class="btn-step minus" data-kartu-kurang="' + m.id + '" aria-label="Kurangi ' + m.nama + '">−</button>';
                    html += '<div class="step-qty-wrap">'
                        + '<input type="number" class="input-step-qty" min="0" max="' + (sisa !== null ? sisa : 120) + '" value="' + jmlPilih + '" data-kartu-input="' + m.id + '" aria-label="Jumlah ' + m.nama + '">'
                        + '<span class="step-qty-unit">' + unitLabel + '</span>'
                        + '</div>';
                    html += '<button type="button" class="btn-step plus" data-kartu-tambah="' + m.id + '" aria-label="Tambah ' + m.nama + '">+</button>';
                    html += '</div>';
                } else {
                    html += '<button type="button" class="btn btn-garis btn-kecil" disabled>Habis</button>';
                }
                html += '</div>';

                html += '</div>';
            });

            html += '</div></div>';
        });

        wadah.innerHTML = html;
        pasangEventKartu();
    }

    function ubahKuantitasMenu(id, nama, harga, kat, delta, nilaiLangsung) {
        var sekarang = keranjang[id] ? (keranjang[id].jumlah || 0) : 0;
        var baru = (nilaiLangsung !== undefined) ? nilaiLangsung : (sekarang + delta);

        // Jika baru pertama kali klik "+" dari 0, tambahkan 1 (atau sesuai klik)
        if (baru <= 0) {
            if (keranjang[id]) {
                delete keranjang[id];
                toast('Dihapus dari keranjang: ' + nama);
            }
        } else {
            if (baru > 120) baru = 120;
            var baruTambah = !keranjang[id];
            keranjang[id] = {
                id: id,
                nama: nama,
                harga: harga,
                kategori: kat,
                jumlah: baru
            };
            if (baruTambah) {
                toast('Ditambahkan: ' + nama + ' (' + baru + ' box)', 'sukses');
            }
        }
        simpanKeranjang();
        renderKeranjang();
    }

    function renderKeranjang() {
        var box = totalBox(), sub = subtotal();
        var minBox = CFG.MINIMAL_BOX || 10;
        var cukup = box >= minBox;
        var diskon = DB ? DB.hitungDiskon(box) : (Math.floor(box / 50) * 50000);
        var totalAkhir = Math.max(0, sub - diskon);

        // 1. Ringkasan di Halaman Katalog (index.html)
        var isi = $('#ringkasanIsi');
        var btnKosongkan = $('#kosongkanKeranjang');
        if (btnKosongkan) btnKosongkan.hidden = (box === 0);

        if (isi) {
            if (box === 0) {
                isi.innerHTML = '<div class="ringkasan-kosong">Keranjang masih kosong.<br>Klik tombol <b>+</b> pada menu di samping untuk mulai memesan.</div>';
            } else {
                var html = '';
                Object.keys(keranjang).forEach(function (id) {
                    var it = keranjang[id];
                    var subItem = (it.harga || 0) * (it.jumlah || 0);
                    html += '<div class="baris-menu" data-id="' + id + '">'
                        + '<div class="baris-menu-atas">'
                        + '<div class="nm"><strong>' + it.jumlah + 'x</strong> ' + it.nama + '</div>'
                        + '<div class=" nilai-item">' + formatRupiah(subItem) + '</div>'
                        + '</div>'
                        + '<div class="baris-menu-bawah">'
                        + '<span class="hr">' + formatRupiah(it.harga) + ' / box</span>'
                        + '<div class="baris-menu-kontrol">'
                        + '<div class="stepper stepper-mini">'
                        + '<button type="button" data-kurang="' + id + '" aria-label="Kurangi">−</button>'
                        + '<input type="number" value="' + it.jumlah + '" min="1" max="120" data-ubah="' + id + '" aria-label="Jumlah">'
                        + '<button type="button" data-tambah="' + id + '" aria-label="Tambah">+</button>'
                        + '</div>'
                        + '<button type="button" class="hapus" data-hapus="' + id + '" title="Hapus menu">×</button>'
                        + '</div>'
                        + '</div>'
                        + '</div>';
                });
                isi.innerHTML = html;
            }
        }

        // 2. Banner Status Syarat Minimal 10 Box
        var bannerMin = $('#statusMinBoxBanner');
        var pesanMinEl = $('#pesanMin');
        if (pesanMinEl) {
            if (box === 0) {
                pesanMinEl.textContent = '📦 Belum ada box dipilih (Minimal pemesanan ' + minBox + ' box)';
                if (bannerMin) bannerMin.className = 'cart-min-status netral';
            } else if (!cukup) {
                pesanMinEl.textContent = '⚠ ' + box + ' Box Terpilih (Kurang ' + (minBox - box) + ' box lagi dari min. ' + minBox + ' box)';
                if (bannerMin) bannerMin.className = 'cart-min-status kurang';
            } else {
                pesanMinEl.textContent = '✓ ' + box + ' Box Terpilih (Syarat min. ' + minBox + ' box terpenuhi)';
                if (bannerMin) bannerMin.className = 'cart-min-status cukup';
            }
        }

        // 3. Milestone Promo Kelipatan 50 Box + Progress Bar
        var promoSub = $('#promoMilestoneSub');
        var promoBar = $('#promoMilestoneBar');
        if (promoSub && promoBar) {
            if (box === 0) {
                promoSub.textContent = 'Pesan 50 box lagi untuk klaim diskon Rp 50.000!';
                promoBar.style.width = '0%';
            } else if (box < 50) {
                var kurang50 = 50 - box;
                var pct50 = Math.min(100, Math.round((box / 50) * 100));
                promoSub.textContent = 'Pesan ' + kurang50 + ' box lagi untuk klaim diskon Rp 50.000!';
                promoBar.style.width = pct50 + '%';
            } else if (box < 100) {
                var kurang100 = 100 - box;
                var pct100 = Math.min(100, Math.round(((box - 50) / 50) * 100));
                promoSub.textContent = '🎉 Diskon Rp 50.000 aktif! (' + kurang100 + ' box lagi diskon Rp 100.000)';
                promoBar.style.width = Math.max(20, pct100) + '%';
            } else {
                promoSub.textContent = '🎉 Diskon Maksimal Rp 100.000 aktif pada pesanan ini!';
                promoBar.style.width = '100%';
            }
        }

        // 4. Ringkasan di Halaman Checkout (checkout.html)
        var co = $('#ringkasanCheckoutIsi');
        if (co) {
            if (box === 0) {
                co.innerHTML = '<p class="bantuan">Belum ada menu dipilih. <a href="index.html#katalog" style="color:var(--c-primer);font-weight:600">Pilih menu dahulu &rarr;</a></p>';
            } else {
                var h = '<table class="nota-rincian-tabel" style="font-size:13px;width:100%;border-collapse:collapse;">';
                Object.keys(keranjang).forEach(function (id) {
                    var it = keranjang[id];
                    h += '<tr style="border-bottom:1px solid #f1f5f9;padding:6px 0;">'
                        + '<td style="padding:6px 0;"><strong>' + it.nama + '</strong><br><span style="font-size:12px;color:var(--c-redup);">' + formatRupiah(it.harga) + ' × ' + it.jumlah + '</span></td>'
                        + '<td style="text-align:right;padding:6px 0;font-weight:600;">' + formatRupiah(it.harga * it.jumlah) + '</td>'
                        + '</tr>';
                });
                h += '</table>';
                if (diskon > 0) {
                    h += '<div style="background:#e8f5e9;color:#1b6e44;padding:8px 12px;border-radius:8px;font-size:13px;margin-top:10px;display:flex;align-items:center;gap:8px;">'
                        + '<span>🎉</span><span><strong>Diskon 50+ Box:</strong> Hemat ' + formatRupiah(diskon) + ' langsung dipotong!</span>'
                        + '</div>';
                }
                co.innerHTML = h;
            }
        }

        if ($('#totalBox')) $('#totalBox').textContent = box;
        if ($('#subtotal')) $('#subtotal').textContent = formatRupiah(sub);

        // Baris diskon selalu tampil informatif di index.html & tampil saat aktif di checkout.html
        var barisDiskonEl = $('#barisDiskon');
        var nilaiDiskonEl = $('#nilaiDiskon');
        if (nilaiDiskonEl) {
            if (diskon > 0) {
                nilaiDiskonEl.textContent = '−' + formatRupiah(diskon);
                nilaiDiskonEl.className = 'diskon-aktif';
                if (barisDiskonEl) barisDiskonEl.style.display = 'flex';
            } else {
                nilaiDiskonEl.textContent = 'Rp 0 (Belum 50 Box)';
                nilaiDiskonEl.className = 'diskon-belum';
                if (barisDiskonEl && co) {
                    barisDiskonEl.style.display = 'none';
                } else if (barisDiskonEl) {
                    barisDiskonEl.style.display = 'flex';
                }
            }
        }

        if ($('#totalHarga')) $('#totalHarga').textContent = formatRupiah(totalAkhir);
        if ($('#totalCheckout')) $('#totalCheckout').textContent = formatRupiah(totalAkhir);
        if ($('#hitungKeranjang')) $('#hitungKeranjang').textContent = box;
        if ($('#cartBarBox')) $('#cartBarBox').textContent = box + ' Box Terpilih';
        if ($('#cartBarTotal')) $('#cartBarTotal').textContent = 'Total: ' + formatRupiah(totalAkhir);
        if ($('#cartBar')) $('#cartBar').classList.toggle('aktif', box > 0);

        if ($('#btnLanjut')) $('#btnLanjut').disabled = !cukup;

        // Tombol lanjut ke checkout di mobile bottom bar
        var ctaCheckout = $('#btnKeCheckout');
        if (ctaCheckout) {
            ctaCheckout.textContent = 'Checkout (' + box + ') →';
            ctaCheckout.style.pointerEvents = cukup ? 'auto' : 'none';
            ctaCheckout.style.opacity = cukup ? '1' : '.6';
            ctaCheckout.setAttribute('aria-disabled', cukup ? 'false' : 'true');
        }

        if ($('#fJumlahBox')) $('#fJumlahBox').value = box;

        // Sinkronisasi angka stepper pada setiap kartu menu di katalog
        $$('.kartu-cust[data-menu-id]').forEach(function (k) {
            var mid = k.getAttribute('data-menu-id');
            var jml = keranjang[mid] ? (keranjang[mid].jumlah || 0) : 0;
            k.classList.toggle('dipilih', jml > 0);
            var inp = k.querySelector('[data-kartu-input]');
            if (inp && document.activeElement !== inp) {
                inp.value = jml;
            }
        });

        // Tombol Submit Form Checkout
        periksaSiapKirim();
    }

    function periksaSiapKirim() {
        var btn = $('#btnBuatPesanan');
        if (!btn) return;
        var box = totalBox();
        var minBox = CFG.MINIMAL_BOX || 10;
        var nama = ($('#fNama') || {}).value || '';
        var namaOk = nama.trim().length >= 1 && namaValid(nama.trim());
        var wa = ($('#fWa') || {}).value || '';
        var cukup = box >= minBox && namaOk && waValid(wa);
        btn.disabled = !cukup;
    }

    function pasangEventKartu() {
        $$('.kartu-cust[data-menu-id]').forEach(function (kartu) {
            var id = kartu.getAttribute('data-menu-id');
            var nama = kartu.getAttribute('data-nama');
            var harga = parseInt(kartu.getAttribute('data-harga'), 10) || 0;
            var kat = kartu.getAttribute('data-kategori');

            var btnKurang = kartu.querySelector('[data-kartu-kurang]');
            var btnTambah = kartu.querySelector('[data-kartu-tambah]');
            var inpQty = kartu.querySelector('[data-kartu-input]');

            if (btnKurang) {
                btnKurang.onclick = function (e) {
                    e.stopPropagation();
                    ubahKuantitasMenu(id, nama, harga, kat, -1);
                };
            }
            if (btnTambah) {
                btnTambah.onclick = function (e) {
                    e.stopPropagation();
                    ubahKuantitasMenu(id, nama, harga, kat, 1);
                };
            }
            if (inpQty) {
                inpQty.oninput = function (e) {
                    e.stopPropagation();
                    var v = parseInt(this.value, 10);
                    if (isNaN(v) || v < 0) v = 0;
                    ubahKuantitasMenu(id, nama, harga, kat, 0, v);
                };
            }
        });
    }

    // -----------------------------------------------------------------
    // Event Handler Utama
    // -----------------------------------------------------------------
    document.addEventListener('DOMContentLoaded', function () {
        // 1. Toggle Navigasi Mobile
        var btnToggle = $('#custToggle'), navLinks = $('#custLinks');
        if (btnToggle && navLinks) {
            btnToggle.onclick = function () {
                var buka = navLinks.classList.toggle('buka');
                btnToggle.setAttribute('aria-expanded', buka ? 'true' : 'false');
            };
        }

        // 2. Filter Kategori Menu (Chips)
        var filterWrap = $('#kategoriFilter');
        if (filterWrap) {
            filterWrap.addEventListener('click', function (e) {
                var btn = e.target.closest('.filter-btn');
                if (!btn) return;
                var filter = btn.getAttribute('data-filter');
                $$('.filter-btn', filterWrap).forEach(function (b) {
                    var aktif = (b === btn);
                    b.classList.toggle('aktif', aktif);
                    b.setAttribute('aria-selected', aktif ? 'true' : 'false');
                });
                $$('.kategori-blok').forEach(function (blok) {
                    var kat = blok.getAttribute('data-kategori');
                    var grup = blok.getAttribute('data-grup-filter');
                    if (filter === 'semua') {
                        blok.style.display = '';
                    } else if (filter === 'paket') {
                        blok.style.display = (grup === 'paket' || (kat && kat.indexOf('paket') === 0)) ? '' : 'none';
                    } else {
                        blok.style.display = (kat === filter || grup === filter) ? '' : 'none';
                    }
                });
            });
        }

        // 3. Tombol Hero "+ Tambah 10 Box Paket Ini ke Pesanan"
        var btnPaketHero = $('#btnTambahPaketHero');
        if (btnPaketHero) {
            btnPaketHero.onclick = function () {
                var paketMenu = (katalogMenusCache || []).find(function (m) {
                    return String(m.kategori || '').toLowerCase().indexOf('paket') === 0 || m.id === 4;
                }) || (katalogMenusCache && katalogMenusCache[0]);

                var id = paketMenu ? String(paketMenu.id) : '4';
                var nama = paketMenu ? paketMenu.nama : 'Paket Selera Nusantara A';
                var harga = paketMenu ? (parseInt(paketMenu.harga, 10) || 25000) : 25000;
                var kat = paketMenu ? paketMenu.kategori : 'paket a';

                var skrg = keranjang[id] ? (keranjang[id].jumlah || 0) : 0;
                keranjang[id] = {
                    id: id,
                    nama: nama,
                    harga: harga,
                    kategori: kat,
                    jumlah: Math.min(120, skrg + 10)
                };
                simpanKeranjang();
                renderKeranjang();
                toast('+10 Box ' + nama + ' berhasil ditambahkan ke pesanan!', 'sukses');
                var elKat = $('#katalog');
                if (elKat) elKat.scrollIntoView({ behavior: 'smooth' });
            };
        }

        // 4. Setup Tanggal
        var inpTgl = $('#cekTanggal'), inpTglForm = $('#fTgl');
        if (inpTgl) {
            inpTgl.min = tglMin;
            inpTgl.max = tglMaks;
            inpTgl.value = tglPilih;
            inpTgl.onchange = function () {
                tglPilih = inpTgl.value || tglMin;
                if (inpTglForm) inpTglForm.value = tglPilih;
                perbaruiKapasitas(tglPilih);
                muatKatalog(tglPilih);
            };
        }
        if (inpTglForm) {
            inpTglForm.min = tglMin;
            inpTglForm.max = tglMaks;
            inpTglForm.value = tglPilih;
        }

        var btnCek = $('#btnCekTanggal');
        if (btnCek && inpTgl) {
            btnCek.onclick = function () {
                tglPilih = inpTgl.value || tglMin;
                if (inpTglForm) inpTglForm.value = tglPilih;
                perbaruiKapasitas(tglPilih);
                muatKatalog(tglPilih);
            };
        }

        isiPilihanJam();
        if ($('#katalogMenu')) {
            perbaruiKapasitas(tglPilih);
            muatKatalog(tglPilih);
        }
        renderKeranjang();

        // 5. Delegasi Event Keranjang (Stepper & Hapus)
        var ringkasan = $('#ringkasan');
        if (ringkasan) {
            ringkasan.onclick = function (e) {
                var kurangId = e.target.getAttribute('data-kurang');
                var tambahId = e.target.getAttribute('data-tambah');
                var hapusId = e.target.getAttribute('data-hapus');

                if (kurangId && keranjang[kurangId]) {
                    if (keranjang[kurangId].jumlah <= 1) {
                        delete keranjang[kurangId];
                    } else {
                        keranjang[kurangId].jumlah = keranjang[kurangId].jumlah - 1;
                    }
                    simpanKeranjang();
                    renderKeranjang();
                } else if (tambahId && keranjang[tambahId]) {
                    keranjang[tambahId].jumlah = Math.min(120, (keranjang[tambahId].jumlah || 1) + 1);
                    simpanKeranjang();
                    renderKeranjang();
                } else if (hapusId && keranjang[hapusId]) {
                    delete keranjang[hapusId];
                    simpanKeranjang();
                    renderKeranjang();
                }
            };

            ringkasan.oninput = function (e) {
                var ubahId = e.target.getAttribute('data-ubah');
                if (ubahId && keranjang[ubahId]) {
                    var v = parseInt(e.target.value, 10);
                    keranjang[ubahId].jumlah = isNaN(v) || v < 1 ? 1 : Math.min(120, v);
                    simpanKeranjang();
                    renderKeranjang();
                }
            };
        }

        var btnKosongkan = $('#kosongkanKeranjang');
        if (btnKosongkan) {
            btnKosongkan.onclick = function () {
                if (confirm('Kosongkan semua menu di keranjang?')) {
                    keranjang = {};
                    simpanKeranjang();
                    renderKeranjang();
                }
            };
        }

        // Tombol Lanjut ke Checkout (Halaman index -> navigasi ke checkout.html)
        var btnLanjut = $('#btnLanjut');
        if (btnLanjut) {
            btnLanjut.onclick = function () {
                window.location.href = 'checkout.html';
            };
        }

        // 6. Validasi Nama Live di Form Checkout
        var fNama = $('#fNama');
        if (fNama) {
            fNama.addEventListener('blur', function () {
                var v = this.value.trim();
                if (v === '') { tampilGalat('nama', ''); return; }
                tampilGalat('nama', pesanGalatNama(v));
                periksaSiapKirim();
            });
            fNama.addEventListener('input', function () {
                if (namaValid(this.value.trim())) tampilGalat('nama', '');
                periksaSiapKirim();
            });
        }

        var fWa = $('#fWa');
        if (fWa) {
            fWa.addEventListener('input', function () {
                periksaSiapKirim();
            });
            fWa.addEventListener('blur', function () {
                var v = this.value.trim();
                if (v && !waValid(v)) {
                    tampilGalat('no_wa', 'Nomor WhatsApp tidak valid (minimal 10 digit).');
                } else {
                    tampilGalat('no_wa', '');
                }
            });
        }

        // 7. Submit Form Checkout (Halaman checkout.html)
        var form = $('#formCheckout');
        if (form) {
            form.onsubmit = async function (e) {
                e.preventDefault();
                var galatDiv = $('#galatCheckout');
                if (galatDiv) { galatDiv.style.display = 'none'; galatDiv.textContent = ''; }

                var nama = (fNama || {}).value || '';
                var no_wa = (fWa || {}).value || '';
                var tgl = (inpTglForm || {}).value || tglPilih;
                var jam = ($('#fJam') || {}).value || '10:00';
                var catatan = ($('#fCatatan') || {}).value || '';

                var gNama = pesanGalatNama(nama);
                if (gNama) {
                    tampilGalat('nama', gNama);
                    if (fNama) fNama.focus();
                    return;
                }

                if (!waValid(no_wa)) {
                    tampilGalat('no_wa', 'Nomor WhatsApp tidak valid.');
                    if (fWa) fWa.focus();
                    return;
                }

                var items = Object.keys(keranjang).map(function (k) {
                    return { menu_id: parseInt(k, 10), jumlah: keranjang[k].jumlah };
                });

                var box = totalBox();
                var minBox = CFG.MINIMAL_BOX || 10;
                if (box < minBox) {
                    if (galatDiv) {
                        galatDiv.textContent = 'Minimum pemesanan adalah ' + minBox + ' box.';
                        galatDiv.style.display = 'block';
                    }
                    return;
                }

                var btnSubmit = $('#btnBuatPesanan');
                var btnTeks = $('#btnBuatTeks');
                if (btnSubmit) { btnSubmit.disabled = true; }
                if (btnTeks) { btnTeks.textContent = 'Memproses Pesanan...'; }

                var res = await DB.buatPesanan({
                    nama: nama.trim(),
                    no_wa: no_wa.trim(),
                    tanggal_ambil: tgl,
                    jam_ambil: jam,
                    catatan: catatan.trim(),
                    items: items
                });

                if (!res || !res.ok) {
                    if (btnSubmit) btnSubmit.disabled = false;
                    if (btnTeks) btnTeks.textContent = 'Lanjutkan Pembayaran';
                    if (galatDiv) {
                        galatDiv.textContent = (res && res.pesan) || 'Gagal membuat pesanan. Silakan periksa kembali.';
                        galatDiv.style.display = 'block';
                    }
                    return;
                }

                // Berhasil: Kosongkan keranjang & Redirect ke pembayaran
                keranjang = {};
                simpanKeranjang();
                window.location.href = 'pembayaran.html?kode=' + encodeURIComponent(res.kode) + '&t=' + encodeURIComponent(res.token_nota);
            };
        }
    });
})();
