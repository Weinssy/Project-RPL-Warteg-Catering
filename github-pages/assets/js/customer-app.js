// =====================================================================
// LOGIKA HALAMAN UTAMA PELANGGAN (KATALOG, CEK KUOTA, & CHECKOUT)
// Sistem Katering & POS Warteg Pak Sanam (GitHub Pages Edition)
// =====================================================================
(function () {
    'use strict';

    var DB = window.WARTEG_DB;
    var CFG = window.WARTEG_CONFIG || {};
    var STORAGE_CART = 'warteg_keranjang_v2';

    var tglMin = DB.addDaysIso(CFG.MINIMAL_H_MINUS || 1);
    var tglMaks = DB.addDaysIso(CFG.MAKS_HARI_KE || 30);
    var metaAwal = DB.getCartMeta ? DB.getCartMeta() : { tanggal: tglMin, jam: '11:00' };
    var tglPilih = metaAwal.tanggal || tglMin;
    var jamPilih = metaAwal.jam || '11:00';

    var katalogMenu = [];
    var keranjang = muatKeranjangStorage();
    var kategoriAktif = 'semua';
    var sisaKuotaSaatIni = CFG.KAPASITAS_HARIAN || 120;

    function muatKeranjangStorage() {
        try {
            var raw = localStorage.getItem(STORAGE_CART);
            var obj = raw ? JSON.parse(raw) : {};
            return (obj && typeof obj === 'object') ? obj : {};
        } catch (e) {
            return {};
        }
    }

    function simpanKeranjangStorage() {
        try {
            localStorage.setItem(STORAGE_CART, JSON.stringify(keranjang));
        } catch (e) {}
    }

    // -----------------------------------------------------------------
    // 0. Toast Notification Helper
    // -----------------------------------------------------------------
    var toastTimer = null;
    function tampilToast(pesan) {
        var area = document.getElementById('custToast');
        if (!area) return;
        area.innerHTML = '<div class="cust-toast-item">' + DB.escapeHtml(pesan) + '</div>';
        if (toastTimer) clearTimeout(toastTimer);
        toastTimer = setTimeout(function () {
            area.innerHTML = '';
        }, 2800);
    }

    // -----------------------------------------------------------------
    // 1. Setup Input Tanggal & Navigasi Mobile
    // -----------------------------------------------------------------
    function initNavDanTanggal() {
        var btnToggle = document.getElementById('custToggle');
        var navLinks = document.getElementById('custLinks');
        if (btnToggle && navLinks && !btnToggle.dataset.boundToggle) {
            btnToggle.dataset.boundToggle = '1';
            btnToggle.addEventListener('click', function () {
                var buka = navLinks.classList.toggle('buka');
                btnToggle.setAttribute('aria-expanded', buka ? 'true' : 'false');
            });
            navLinks.querySelectorAll('a').forEach(function (a) {
                a.addEventListener('click', function () {
                    navLinks.classList.remove('buka');
                    btnToggle.setAttribute('aria-expanded', 'false');
                });
            });
            document.addEventListener('keydown', function (ev) {
                if (ev.key === 'Escape' && navLinks.classList.contains('buka')) {
                    navLinks.classList.remove('buka');
                    btnToggle.setAttribute('aria-expanded', 'false');
                    btnToggle.focus();
                }
            });
        }

        var inpCek = document.getElementById('cekTanggal');
        var inpFormTgl = document.getElementById('fTgl') || document.getElementById('tanggal');
        var inpFormJam = document.getElementById('fJam') || document.getElementById('jam');

        [inpCek, inpFormTgl].forEach(function (el) {
            if (!el) return;
            el.min = tglMin;
            el.max = tglMaks;
            el.value = tglPilih;
        });

        if (inpFormJam) {
            inpFormJam.value = jamPilih;
            inpFormJam.addEventListener('change', function () {
                jamPilih = this.value || '11:00';
                if (DB.setCartMeta) DB.setCartMeta({ tanggal: tglPilih, jam: jamPilih });
            });
        }

        var btnCek = document.getElementById('btnCekTanggal');
        if (btnCek && inpCek) {
            btnCek.addEventListener('click', function () {
                if (inpCek.value) {
                    muatDataTanggal(inpCek.value);
                    tampilToast('Menampilkan kuota & menu untuk ' + DB.tanggalId(inpCek.value, false));
                }
            });
            inpCek.addEventListener('change', function () {
                if (this.value) muatDataTanggal(this.value);
            });
        }

        if (inpFormTgl) {
            inpFormTgl.addEventListener('change', function () {
                if (this.value) {
                    muatDataTanggal(this.value);
                }
            });
        }

        // Filter Chips Kategori
        var filterWrap = document.getElementById('kategoriFilter');
        if (filterWrap) {
            filterWrap.querySelectorAll('.filter-btn').forEach(function (btn) {
                btn.addEventListener('click', function () {
                    filterWrap.querySelectorAll('.filter-btn').forEach(function (b) {
                        b.classList.remove('aktif');
                        b.setAttribute('aria-pressed', 'false');
                    });
                    this.classList.add('aktif');
                    this.setAttribute('aria-pressed', 'true');
                    kategoriAktif = this.getAttribute('data-filter') || 'semua';
                    renderKatalog();
                });
            });
        }

        // Tombol Kosongkan Keranjang
        var btnKosongkan = document.getElementById('kosongkanKeranjang');
        if (btnKosongkan) {
            btnKosongkan.addEventListener('click', function () {
                if (totalItemKeranjang() === 0) return;
                if (confirm('Kosongkan semua menu di keranjang pesanan?')) {
                    keranjang = {};
                    simpanKeranjangStorage();
                    renderKatalog();
                    renderRingkasan();
                    tampilToast('Keranjang pesanan dikosongkan');
                }
            });
        }

        // Tombol Lanjut ke Checkout
        var btnLanjut = document.getElementById('btnLanjut');
        if (btnLanjut) {
            btnLanjut.addEventListener('click', function () {
                if (DB.setCartMeta) DB.setCartMeta({ tanggal: tglPilih, jam: jamPilih });
                var secCheckout = document.getElementById('checkout');
                if (secCheckout) {
                    secCheckout.scrollIntoView({ behavior: 'smooth' });
                    var inpNama = document.getElementById('nama');
                    if (inpNama) setTimeout(function () { inpNama.focus(); }, 400);
                } else {
                    window.location.href = 'checkout.html';
                }
            });
        }

        // Tombol Hero: + Tambah 10 Box Paket Ini ke Pesanan
        var btnHeroPaket = document.getElementById('btnTambahPaketHero');
        if (btnHeroPaket) {
            btnHeroPaket.addEventListener('click', function () {
                if (!katalogMenu || !katalogMenu.length) return;
                var paketA = katalogMenu.find(function (m) {
                    return String(m.kategori || '').toLowerCase() === 'paket a' || m.id === 1;
                }) || katalogMenu[0];

                if (paketA) {
                    ubahKuantitasMenu(paketA.id, 10, true);
                    tampilToast('10 Box ' + paketA.nama + ' ditambahkan ke keranjang');
                    var secKatalog = document.getElementById('katalog');
                    if (secKatalog) secKatalog.scrollIntoView({ behavior: 'smooth' });
                }
            });
        }
    }

    function sinkronTanggal(tglBaru) {
        var valTgl = DB.validasiTanggalAmbil ? DB.validasiTanggalAmbil(tglBaru) : { ok: true };
        if (!valTgl.ok) {
            tampilToast(valTgl.pesan);
            tglPilih = tglMin;
        } else {
            tglPilih = tglBaru;
        }
        if (DB.setCartMeta) DB.setCartMeta({ tanggal: tglPilih, jam: jamPilih });

        var inpCek = document.getElementById('cekTanggal');
        var inpFormTgl = document.getElementById('fTgl') || document.getElementById('tanggal');
        if (inpCek && inpCek.value !== tglPilih) inpCek.value = tglPilih;
        if (inpFormTgl && inpFormTgl.value !== tglPilih) inpFormTgl.value = tglPilih;
    }

    // -----------------------------------------------------------------
    // 2. Muat Data Kuota & Katalog Menu per Tanggal
    // -----------------------------------------------------------------
    async function muatDataTanggal(tgl) {
        sinkronTanggal(tgl);
        var wadah = document.getElementById('katalogMenu');
        if (wadah) {
            wadah.innerHTML = '<div class="kosong"><div class="spinner" style="margin:0 auto 10px" aria-hidden="true"></div><p>Memuat katalog menu untuk ' + DB.escapeHtml(DB.tanggalId(tglPilih)) + '...</p></div>';
        }
        try {
            await muatKuota(tglPilih);
            katalogMenu = await DB.getMenuTanggal(tglPilih);

            // Sesuaikan item keranjang jika pada tanggal baru menu tidak tersedia atau stok berkurang
            sesuaikanKeranjangDenganStokTanggal();

            if (wadah) {
                updateLabelTabKategori();
                renderKatalog();
            }
            renderRingkasan();
        } catch (err) {
            console.error('Gagal memuat data menu:', err);
            if (wadah) {
                wadah.innerHTML = '<div class="kosong kosong-galat">'
                    + '<p><strong>Gagal memuat daftar menu.</strong> Periksa koneksi internet Anda lalu coba lagi.</p>'
                    + '<button type="button" class="btn btn-garis btn-kecil js-retry-katalog" style="margin-top:12px">Muat Ulang Menu</button>'
                    + '</div>';
                var btnRetry = wadah.querySelector('.js-retry-katalog');
                if (btnRetry) {
                    btnRetry.addEventListener('click', function () {
                        muatDataTanggal(tglPilih);
                    });
                }
            }
        }
    }

    function sesuaikanKeranjangDenganStokTanggal() {
        var map = {};
        (katalogMenu || []).forEach(function (m) { map[m.id] = m; });
        var adaPerubahan = false;

        Object.keys(keranjang).forEach(function (idStr) {
            var id = Number(idStr);
            var qty = keranjang[id] || 0;
            var m = map[id];
            if (!m || !m.aktif || !m.tersedia) {
                delete keranjang[id];
                adaPerubahan = true;
                return;
            }
            if (m.stok !== null && m.stok !== undefined) {
                var sisaStok = Math.max(0, Number(m.stok) - Number(m.terjual || 0));
                if (qty > sisaStok) {
                    if (sisaStok <= 0) delete keranjang[id];
                    else keranjang[id] = sisaStok;
                    adaPerubahan = true;
                }
            }
        });

        if (adaPerubahan) {
            simpanKeranjangStorage();
            tampilToast('Keranjang disesuaikan dengan stok menu pada tanggal ' + DB.tanggalId(tglPilih, false));
        }
    }

    async function muatKuota(tgl) {
        var info = await DB.getKapasitasTanggal(tgl);
        sisaKuotaSaatIni = info.sisa;

        var elTerpakai = document.getElementById('kapTerpakai');
        var elSisa = document.getElementById('kapSisa');
        var elTeksTgl = document.getElementById('teksTanggal');
        var elBar = document.getElementById('barKapasitas');
        var kotakSisa = document.getElementById('kotakSisa');
        var badgeTitle = document.getElementById('badgeKuotaAman');
        var badgeSub = document.getElementById('badgeKuotaSub');

        if (elTerpakai) elTerpakai.textContent = info.terpakai;
        if (elSisa) elSisa.textContent = info.sisa;
        if (elTeksTgl) {
            elTeksTgl.textContent = 'Jadwal Pengambilan: ' + info.tanggal_id + ' · Batas dapur 120 box/hari';
        }
        if (elBar) {
            var persenTersedia = Math.max(0, 100 - info.persen);
            elBar.style.width = persenTersedia + '%';
            elBar.className = info.level === 'penuh' ? 'penuh' : (info.level === 'hampir' ? 'hampir' : '');
        }
        if (kotakSisa) {
            kotakSisa.className = 'kapasitas-kotak sisa ' + info.level;
        }
        if (badgeTitle && badgeSub) {
            if (info.level === 'penuh') {
                badgeTitle.textContent = 'KUOTA PENUH';
                badgeSub.textContent = 'Pilih tanggal lain';
            } else if (info.level === 'hampir') {
                badgeTitle.textContent = 'HAMPIR PENUH';
                badgeSub.textContent = 'Sisa ' + info.sisa + ' box lagi';
            } else {
                badgeTitle.textContent = 'KUOTA AMAN';
                badgeSub.textContent = 'Pesanan siap diterima';
            }
        }

        // Update pill kuota di navbar dengan konteks tanggal terpilih
        var navQuotaPill = document.getElementById('navQuotaPill');
        if (navQuotaPill) {
            var labelTgl = (tgl === tglMin) ? 'Besok' : DB.tanggalId(tgl, false);
            navQuotaPill.className = 'nav-quota-pill ' + info.level;
            navQuotaPill.innerHTML = '<span class="dot-aman" aria-hidden="true"></span> Sisa Kuota (' + DB.escapeHtml(labelTgl) + '): <b id="navSisaKuota">' + info.sisa + '</b> Box';
        }

        // Update info jadwal ambil di keranjang/checkout jika elemen tersedia
        var infoTglCheckout = document.getElementById('infoKuotaTanggalCheckout');
        if (infoTglCheckout) {
            infoTglCheckout.textContent = 'Kuota tersedia pada ' + info.tanggal_id + ': ' + info.sisa + ' dari ' + info.kapasitas + ' box.';
        }
    }

    // -----------------------------------------------------------------
    // 3. Kelompok Kategori & Update Label Jumlah Menu di Tab Filter
    // -----------------------------------------------------------------
    function kelompokKategori(katRaw) {
        var k = String(katRaw || '').toLowerCase();
        if (k.indexOf('paket') === 0) return 'paket';
        if (k === 'nasi') return 'nasi';
        if (k === 'lauk') return 'lauk';
        if (k === 'sayur') return 'sayur';
        return 'lainnya';
    }

    function updateLabelTabKategori() {
        var filterWrap = document.getElementById('kategoriFilter');
        if (!filterWrap) return;

        var counts = {
            semua: katalogMenu.length,
            paket: 0,
            nasi: 0,
            lauk: 0,
            sayur: 0
        };

        katalogMenu.forEach(function (m) {
            var g = kelompokKategori(m.kategori);
            if (counts[g] !== undefined) counts[g]++;
        });

        var labelBase = {
            semua: 'Semua Menu',
            paket: 'Paket Nasi Box',
            nasi: 'Nasi Pilihan',
            lauk: 'Lauk Ayam & Daging',
            sayur: 'Sayuran & Pelengkap'
        };

        filterWrap.querySelectorAll('.filter-btn').forEach(function (btn) {
            var f = btn.getAttribute('data-filter');
            if (labelBase[f]) {
                btn.textContent = labelBase[f] + ' (' + (counts[f] || 0) + ')';
            }
        });
    }

    // -----------------------------------------------------------------
    // 4. Render Katalog Menu (4-Zone Clean Card)
    // -----------------------------------------------------------------
    function cocokFilter(menu, filter) {
        if (filter === 'semua') return true;
        return kelompokKategori(menu.kategori) === filter;
    }

    function buatKartuMenuHtml(m) {
        var qty = keranjang[m.id] || 0;
        var sisaStok = m.stok === null ? null : Math.max(0, m.stok - (m.terjual || 0));
        var habis = !m.tersedia || (sisaStok !== null && sisaStok <= 0);
        var terpilihClass = qty > 0 ? ' terpilih' : '';
        var habisClass = habis ? ' habis' : '';

        var unitLabel = m.is_box ? 'Box' : 'Porsi';

        var metaStok = '';
        if (habis) {
            metaStok = '<span class="stok-habis-pill">Habis untuk tanggal ini</span>';
        } else if (sisaStok !== null && sisaStok <= 25) {
            metaStok = '<span class="stok-tipis-pill">Tersisa ' + sisaStok + ' ' + unitLabel.toLowerCase() + '</span>';
        }

        var deskripsiText = m.deskripsi || 'Dimasak segar dengan bumbu rempah pilihan khas Warteg Pak Sanam.';

        var fotoHtml = '';
        if (m.foto) {
            fotoHtml = '<div class="menu-thumb-item">'
                + '<img src="uploads/menu/' + DB.escapeHtml(m.foto) + '" alt="' + DB.escapeHtml(m.nama) + '" width="80" height="80" onerror="this.parentElement.style.display=\'none\'">'
                + '</div>';
        }

        return '<article class="kartu-menu' + terpilihClass + habisClass + '" data-id="' + m.id + '">'
            + fotoHtml
            + '<div class="isi">'
            + '<div class="menu-info-kiri">'
            + '<div class="nama">' + DB.escapeHtml(m.nama) + '</div>'
            + '<div class="menu-deskripsi">' + DB.escapeHtml(deskripsiText) + '</div>'
            + '<div class="menu-harga-baris">'
            + '<span class="harga">' + DB.rupiah(m.harga) + ' <small>/ ' + unitLabel + '</small></span>'
            + metaStok
            + '</div>'
            + '</div>'
            + '<div class="kontrol">'
            + '<div class="stepper">'
            + '<button type="button" class="btn-step js-min" data-id="' + m.id + '" ' + (habis || qty <= 0 ? 'disabled' : '') + ' aria-label="Kurangi ' + DB.escapeHtml(m.nama) + '">−</button>'
            + '<input type="number" class="qty-input js-qty" data-id="' + m.id + '" min="0" max="120" value="' + qty + '" ' + (habis ? 'disabled' : '') + ' aria-label="Jumlah ' + DB.escapeHtml(m.nama) + '">'
            + '<span class="qty-unit-badge">' + unitLabel + '</span>'
            + '<button type="button" class="btn-step plus js-plus" data-id="' + m.id + '" ' + (habis ? 'disabled' : '') + ' aria-label="Tambah ' + DB.escapeHtml(m.nama) + '">+</button>'
            + '</div>'
            + '</div>'
            + '</div>'
            + '</article>';
    }

    function renderKatalog() {
        var wadah = document.getElementById('katalogMenu');
        if (!wadah) return;

        var daftar = katalogMenu.filter(function (m) {
            return cocokFilter(m, kategoriAktif);
        });

        if (!daftar.length) {
            wadah.innerHTML = '<div class="kosong">'
                + '<p>Tidak ada menu untuk kategori ini.</p>'
                + '<button type="button" class="btn btn-garis btn-kecil js-reset-filter" style="margin-top:12px">Tampilkan Semua Menu</button>'
                + '</div>';
            var btnResetFilter = wadah.querySelector('.js-reset-filter');
            if (btnResetFilter) {
                btnResetFilter.addEventListener('click', function () {
                    var btnSemua = document.querySelector('#kategoriFilter .filter-btn[data-filter="semua"]');
                    if (btnSemua) btnSemua.click();
                    else {
                        kategoriAktif = 'semua';
                        renderKatalog();
                    }
                });
            }
            return;
        }

        var html = '<div class="menu-grid">';
        daftar.forEach(function (m) {
            html += buatKartuMenuHtml(m);
        });
        html += '</div>';

        wadah.innerHTML = html;
        pasangEventKatalog(wadah);
    }

    function pasangEventKatalog(wadah) {
        wadah.querySelectorAll('.js-min').forEach(function (btn) {
            btn.addEventListener('click', function () {
                var id = Number(this.getAttribute('data-id'));
                var step = (keranjang[id] && keranjang[id] <= 10 && keranjang[id] >= 5) ? 1 : 1;
                ubahKuantitasMenu(id, -step);
            });
        });

        wadah.querySelectorAll('.js-plus').forEach(function (btn) {
            btn.addEventListener('click', function () {
                var id = Number(this.getAttribute('data-id'));
                var cur = keranjang[id] || 0;
                var m = katalogMenu.find(function (x) { return x.id === id; });
                // Jika menu box utama pertama kali diklik dan total box di keranjang masih 0, langsung lompat ke 10 box
                var delta = (cur === 0 && totalBox() === 0 && m && m.is_box) ? 10 : 1;
                ubahKuantitasMenu(id, delta);
            });
        });

        wadah.querySelectorAll('.js-qty').forEach(function (inp) {
            inp.addEventListener('change', function () {
                var id = Number(this.getAttribute('data-id'));
                var val = Math.max(0, parseInt(this.value || '0', 10) || 0);
                setKuantitasMenu(id, val);
            });
        });
    }

    function batasMaksItem(m, curQty) {
        var maks = 120;
        if (m && m.stok !== null && m.stok !== undefined) {
            var sisaStok = Math.max(0, Number(m.stok) - Number(m.terjual || 0));
            maks = Math.min(maks, sisaStok);
        }
        if (m && m.is_box) {
            var boxLain = Math.max(0, totalBox() - (curQty || 0));
            var sisaKuota = Math.max(0, sisaKuotaSaatIni - boxLain);
            maks = Math.min(maks, sisaKuota);
        }
        return Math.max(0, maks);
    }

    function ubahKuantitasMenu(id, delta, silentToast) {
        var cur = keranjang[id] || 0;
        setKuantitasMenu(id, cur + delta, silentToast);
    }

    function setKuantitasMenu(id, targetQty, silentToast) {
        var cur = keranjang[id] || 0;
        var m = katalogMenu.find(function (x) { return x.id === id; });
        var maks = batasMaksItem(m, cur);
        var baru = Math.max(0, Math.min(maks, targetQty));

        if (targetQty > maks && m) {
            var unitLbl = m.is_box ? 'box' : 'porsi';
            tampilToast('Maksimal ' + maks + ' ' + unitLbl + ' untuk ' + m.nama + ' (sesuai sisa stok/kuota dapur)');
        }

        if (baru === 0) {
            delete keranjang[id];
        } else {
            keranjang[id] = baru;
        }
        simpanKeranjangStorage();

        if (!silentToast && m && baru > cur) {
            tampilToast((baru - cur) + 'x ' + m.nama + ' ditambahkan ke keranjang');
        }

        renderKatalog();
        renderRingkasan();
    }

    // -----------------------------------------------------------------
    // 5. Kalkulasi Box Utama vs Porsi Tambahan & Render Ringkasan
    // -----------------------------------------------------------------
    function totalBox() {
        var map = {};
        katalogMenu.forEach(function (m) { map[m.id] = m; });
        return Object.keys(keranjang).reduce(function (acc, k) {
            var m = map[Number(k)];
            if (m && m.is_box) {
                return acc + (keranjang[k] || 0);
            }
            return acc;
        }, 0);
    }

    function totalPorsiTambahan() {
        var map = {};
        katalogMenu.forEach(function (m) { map[m.id] = m; });
        return Object.keys(keranjang).reduce(function (acc, k) {
            var m = map[Number(k)];
            if (m && !m.is_box) {
                return acc + (keranjang[k] || 0);
            }
            return acc;
        }, 0);
    }

    function totalItemKeranjang() {
        return Object.keys(keranjang).reduce(function (acc, k) {
            return acc + (keranjang[k] || 0);
        }, 0);
    }

    function totalHarga() {
        var map = {};
        katalogMenu.forEach(function (m) { map[m.id] = m; });
        return Object.keys(keranjang).reduce(function (acc, k) {
            var m = map[k];
            return acc + (m ? m.harga * keranjang[k] : 0);
        }, 0);
    }

    function renderRingkasan() {
        var isi = document.getElementById('ringkasanIsi') || document.getElementById('ringkasanCheckoutIsi');
        var elTotalBox = document.getElementById('totalBox');
        var elSubtotal = document.getElementById('subtotal');
        var elBarisDiskon = document.getElementById('barisDiskon');
        var elNilaiDiskon = document.getElementById('nilaiDiskon');
        var elTotalHarga = document.getElementById('totalHarga');
        var elPesanMin = document.getElementById('pesanMin');
        var elStatusBanner = document.getElementById('statusMinBoxBanner');
        var btnLanjut = document.getElementById('btnLanjut');
        var btnSubmit = document.getElementById('btnBuatPesanan') || document.getElementById('btnSubmit');
        var btnKosongkan = document.getElementById('kosongkanKeranjang');

        // Promo Milestone Elements
        var promoSub = document.getElementById('promoMilestoneSub');
        var promoBar = document.getElementById('promoMilestoneBar');

        // Mobile Cart Bar
        var cartBar = document.getElementById('cartBar');
        var cartBarBox = document.getElementById('cartBarBox');
        var cartBarTotal = document.getElementById('cartBarTotal');

        var map = {};
        katalogMenu.forEach(function (m) { map[m.id] = m; });

        var jmlBox = totalBox();
        var jmlPorsi = totalPorsiTambahan();
        var jmlSemua = totalItemKeranjang();
        var subHarga = totalHarga();
        var diskon = DB.hitungDiskon(jmlBox, subHarga);
        var hargaBersih = Math.max(0, subHarga - diskon);
        var minBox = CFG.MINIMAL_BOX || 10;
        var minBoxDiskon = Number(CFG.MIN_BOX_DISKON || 80);
        var persenDiskon = Number(CFG.PERSEN_DISKON || 5);

        if (elTotalBox) elTotalBox.textContent = jmlBox + (jmlPorsi > 0 ? ' Box + ' + jmlPorsi + ' Porsi' : '');
        if (elSubtotal) elSubtotal.textContent = DB.rupiah(subHarga);
        if (elTotalHarga) elTotalHarga.textContent = DB.rupiah(hargaBersih);
        if (btnKosongkan) btnKosongkan.hidden = (jmlSemua === 0);

        // Update Rincian Diskon di Keranjang
        if (elBarisDiskon && elNilaiDiskon) {
            elBarisDiskon.style.display = 'flex';
            if (diskon > 0) {
                elNilaiDiskon.className = 'diskon-aktif';
                elNilaiDiskon.textContent = '- ' + DB.rupiah(diskon) + ' (' + persenDiskon + '%)';
            } else {
                elNilaiDiskon.className = 'diskon-belum';
                elNilaiDiskon.textContent = 'Rp 0 (Min. ' + minBoxDiskon + ' Box)';
            }
        }

        // Update Milestone Promo Diskon 5% (>= 80 Box)
        if (promoSub && promoBar) {
            var sisaKeDiskon = Math.max(0, minBoxDiskon - jmlBox);
            var persenMilestone = jmlBox === 0 ? 0 : Math.min(100, Math.round((jmlBox / minBoxDiskon) * 100));
            promoBar.style.width = persenMilestone + '%';

            if (jmlBox === 0) {
                promoSub.textContent = 'Pesan minimal ' + minBoxDiskon + ' box untuk klaim voucher diskon ' + persenDiskon + '%!';
            } else if (jmlBox >= minBoxDiskon) {
                promoSub.textContent = 'Selamat! Voucher diskon ' + persenDiskon + '% (' + DB.rupiah(diskon) + ') aktif otomatis.';
            } else {
                promoSub.textContent = 'Pesan ' + sisaKeDiskon + ' box lagi untuk klaim voucher diskon ' + persenDiskon + '%!';
            }
        }

        // Info Jadwal Ambil di Ringkasan Keranjang
        var infoJadwalHtml = '<div class="ringkasan-jadwal">'
            + '<span>Ambil: <strong>' + DB.escapeHtml(DB.tanggalId(tglPilih, false)) + '</strong></span>'
            + '<span class="ringkasan-jadwal-sisa">Sisa ' + sisaKuotaSaatIni + ' box</span>'
            + '</div>';

        // Daftar Item di Keranjang
        if (isi) {
            var keys = Object.keys(keranjang).filter(function (k) { return keranjang[k] > 0 && map[k]; });
            if (!keys.length) {
                isi.innerHTML = infoJadwalHtml + '<div class="ringkasan-kosong">Keranjang masih kosong.<br>Klik tombol <b>+</b> pada menu di samping untuk mulai memesan.</div>';
            } else {
                var html = infoJadwalHtml;
                keys.forEach(function (k) {
                    var m = map[k];
                    var q = keranjang[k];
                    var sub = m.harga * q;
                    var uLabel = m.is_box ? 'box' : 'porsi';
                    var maksItem = batasMaksItem(m, q);
                    html += '<div class="ringkasan-item">'
                        + '<div class="ringkasan-item-atas">'
                        + '<span class="nama"><strong>' + q + 'x</strong> ' + DB.escapeHtml(m.nama) + ' <small class="unit-label">(' + uLabel + ')</small></span>'
                        + '<span class="sub">' + DB.rupiah(sub) + '</span>'
                        + '</div>'
                        + '<div class="ringkasan-item-kontrol">'
                        + '<div class="ringkasan-stepper-grup">'
                        + '<button type="button" class="btn-mini-step js-cart-min" data-id="' + m.id + '" aria-label="Kurangi ' + DB.escapeHtml(m.nama) + '">&minus;</button>'
                        + '<button type="button" class="btn-mini-step plus js-cart-plus" data-id="' + m.id + '" ' + (q >= maksItem ? 'disabled' : '') + ' aria-label="Tambah ' + DB.escapeHtml(m.nama) + '">+</button>'
                        + '</div>'
                        + '<button type="button" class="btn-mini-hapus js-cart-del" data-id="' + m.id + '" aria-label="Hapus ' + DB.escapeHtml(m.nama) + '">Hapus</button>'
                        + '</div>'
                        + '</div>';
                });
                isi.innerHTML = html;

                isi.querySelectorAll('.js-cart-min').forEach(function (b) {
                    b.onclick = function () { ubahKuantitasMenu(Number(this.getAttribute('data-id')), -1, true); };
                });
                isi.querySelectorAll('.js-cart-plus').forEach(function (b) {
                    b.onclick = function () { ubahKuantitasMenu(Number(this.getAttribute('data-id')), 1, true); };
                });
                isi.querySelectorAll('.js-cart-del').forEach(function (b) {
                    b.onclick = function () {
                        var id = Number(this.getAttribute('data-id'));
                        delete keranjang[id];
                        simpanKeranjangStorage();
                        renderKatalog();
                        renderRingkasan();
                    };
                });
            }
        }

        // Validasi Minimal 10 Box Utama & Maksimal Sisa Kuota
        var memenuhiSyarat = (jmlBox >= minBox && jmlBox <= sisaKuotaSaatIni);
        if (elPesanMin && elStatusBanner) {
            if (jmlBox === 0 && jmlPorsi === 0) {
                elStatusBanner.className = 'cart-min-status netral';
                elPesanMin.textContent = '0 Box Terpilih (Syarat minimal ' + minBox + ' box)';
            } else if (jmlBox < minBox) {
                elStatusBanner.className = 'cart-min-status kurang';
                elPesanMin.textContent = jmlBox + ' Box Utama Terpilih (Kurang ' + (minBox - jmlBox) + ' box paket/nasi lagi)';
            } else if (jmlBox > sisaKuotaSaatIni) {
                elStatusBanner.className = 'cart-min-status kurang';
                elPesanMin.textContent = 'Melebihi sisa kuota dapur (' + sisaKuotaSaatIni + ' box tersedia)';
            } else {
                elStatusBanner.className = 'cart-min-status cukup';
                elPesanMin.textContent = jmlBox + ' Box Terpilih' + (jmlPorsi > 0 ? ' (+ ' + jmlPorsi + ' Porsi Lauk/Sayur)' : '') + ' · Syarat min. ' + minBox + ' box terpenuhi';
            }
        }

        if (btnLanjut) btnLanjut.disabled = !memenuhiSyarat;
        if (btnSubmit) btnSubmit.disabled = !memenuhiSyarat;

        // Bottom Bar Mobile
        if (cartBar && cartBarBox && cartBarTotal) {
            if (jmlSemua > 0) {
                cartBar.classList.add('tampil');
                cartBarBox.textContent = jmlBox + ' Box Terpilih';
                cartBarTotal.textContent = 'Total: ' + DB.rupiah(hargaBersih);
            } else {
                cartBar.classList.remove('tampil');
            }
        }
    }

    // -----------------------------------------------------------------
    // 6. Submit Form Checkout
    // -----------------------------------------------------------------
    function initFormCheckout() {
        var form = document.getElementById('formCheckout') || document.getElementById('formPesan');
        var galatBox = document.getElementById('galatCheckout');
        var btnSubmit = document.getElementById('btnBuatPesanan') || document.getElementById('btnSubmit');

        if (!form) return;

        form.addEventListener('submit', async function (e) {
            e.preventDefault();
            if (galatBox) galatBox.style.display = 'none';

            var elNama = document.getElementById('fNama') || document.getElementById('nama');
            var elWa = document.getElementById('fWa') || document.getElementById('wa');
            var elTgl = document.getElementById('fTgl') || document.getElementById('tanggal');
            var elJam = document.getElementById('fJam') || document.getElementById('jam');
            var elCatatan = document.getElementById('fCatatan') || document.getElementById('catatan');

            var nama = (elNama ? elNama.value : '').trim();
            var wa = (elWa ? elWa.value : '').trim();
            var tgl = (elTgl && elTgl.value) ? elTgl.value : tglPilih;
            var jam = (elJam && elJam.value) ? elJam.value : jamPilih;
            var catatan = (elCatatan ? elCatatan.value : '').trim();

            if (!nama || !wa || !tgl || !jam) {
                tampilGalat('Mohon lengkapi nama, nomor WhatsApp, tanggal, dan jam pengambilan.');
                return;
            }

            if (/\d/.test(nama)) {
                tampilGalat('Nama lengkap pemesan tidak boleh mengandung angka.');
                if (elNama) elNama.focus();
                return;
            }

            var items = Object.keys(keranjang)
                .filter(function (k) { return keranjang[k] > 0; })
                .map(function (k) { return { menu_id: Number(k), jumlah: Number(keranjang[k]) }; });

            if (totalBox() < (CFG.MINIMAL_BOX || 10)) {
                tampilGalat('Jumlah pesanan minimal adalah 10 box utama (Paket Nasi Box atau Nasi Pilihan).');
                return;
            }

            btnSubmit.disabled = true;
            var teksAsli = btnSubmit.textContent;
            btnSubmit.textContent = 'Memproses Pesanan...';

            try {
                var hasil = await DB.buatPesanan({
                    nama_pelanggan: nama,
                    no_wa: wa,
                    tanggal_ambil: tgl,
                    jam_ambil: jam,
                    catatan: catatan,
                    items: items
                });

                if (!hasil || !hasil.ok) {
                    btnSubmit.disabled = false;
                    btnSubmit.textContent = teksAsli;
                    tampilGalat((hasil && hasil.pesan) || 'Terjadi kesalahan saat menyimpan pesanan.');
                    return;
                }

                // Bersihkan keranjang setelah sukses
                keranjang = {};
                simpanKeranjangStorage();

                // Arahkan ke halaman pembayaran QRIS
                window.location.href = DB.urlPembayaran(hasil.kode, hasil.token_nota);
            } catch (err) {
                btnSubmit.disabled = false;
                btnSubmit.textContent = teksAsli;
                tampilGalat('Koneksi ke server terganggu. Periksa internet Anda lalu coba kirim pesanan lagi.');
            }
        });

        function tampilGalat(pesan) {
            if (!galatBox) {
                alert(pesan);
                return;
            }
            galatBox.textContent = pesan;
            galatBox.style.display = 'block';
            galatBox.scrollIntoView({ behavior: 'smooth', block: 'center' });
        }
    }

    // -----------------------------------------------------------------
    // Jalankan Saat DOM Siap
    // -----------------------------------------------------------------
    document.addEventListener('DOMContentLoaded', function () {
        initNavDanTanggal();
        initFormCheckout();
        muatDataTanggal(tglPilih);
    });
})();
