// =====================================================================
// APLIKASI PELANGGAN (WARTEG GITHUB PAGES + SUPABASE)
// Mengatur UI: Keranjang, Meal Builder, Cek Kuota, & Form Checkout
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
        setTimeout(function () { t.remove(); }, 4000);
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

    // -----------------------------------------------------------------
    // Keranjang Belanja
    // -----------------------------------------------------------------
    var CART_KEY = 'warteg_cart_items';
    var keranjang = {};
    try {
        keranjang = JSON.parse(localStorage.getItem(CART_KEY) || '{}') || {};
    } catch (e) { keranjang = {}; }

    function simpanKeranjang() {
        try { localStorage.setItem(CART_KEY, JSON.stringify(keranjang)); } catch (e) {}
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
    // Render Komponen
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
        if ($('#kapTerpakai')) $('#kapTerpakai').textContent = kap.box;
        if ($('#kapSisa')) $('#kapSisa').textContent = kap.sisa;
        if ($('#barKapasitas')) $('#barKapasitas').style.width = kap.persen + '%';
        if ($('#kotakSisa')) $('#kotakSisa').className = 'kapasitas-kotak sisa ' + kap.level;
        if ($('#teksTanggal')) {
            $('#teksTanggal').textContent = DB.tanggalId(tanggal) + ' · jam pengambilan ' + CFG.JAM_BUKA + '–' + CFG.JAM_TUTUP + ' WIB';
        }
    }

    var labelKategori = {
        'nasi': ['Nasi', 'Pilih jenis nasi box.'],
        'lauk': ['Lauk Utama', 'Pilih lauk utama.'],
        'sayur': ['Sayur / Pendamping', 'Pilih sayur atau lauk pendamping.'],
        'paket a': ['Paket A', 'Paket siap pilih.'],
        'paket b': ['Paket B', 'Paket siap pilih.'],
        'paket c': ['Paket C', 'Paket siap pilih.']
    };

    async function muatKatalog(tanggal) {
        var wadah = $('#katalogMenu');
        if (!wadah || !DB) return;
        wadah.innerHTML = '<div class="kosong"><p>Memuat menu untuk tanggal ' + DB.tanggalId(tanggal, false) + '...</p></div>';

        var menus = await DB.getMenuTanggal(tanggal);
        if (!menus || !menus.length) {
            wadah.innerHTML = '<div class="kosong"><div class="ikon">🍽️</div><p>Belum ada menu yang dibuka untuk tanggal ini. Silakan pilih tanggal lain di atas.</p></div>';
            return;
        }

        // Kelompokkan per kategori
        var grup = {};
        menus.forEach(function (m) {
            var k = m.kategori || 'nasi';
            grup[k] = grup[k] || [];
            grup[k].push(m);
        });

        var html = '';
        var urutan = ['nasi', 'lauk', 'sayur', 'paket a', 'paket b', 'paket c'];
        urutan.forEach(function (kat) {
            var items = grup[kat];
            if (!items || !items.length) return;
            var infoKat = labelKategori[kat] || [kat.toUpperCase(), ''];
            html += '<div class="kategori-blok" data-kategori="' + kat + '">';
            html += '<h3>' + infoKat[0] + '</h3>';
            html += '<p class="aturan">' + infoKat[1] + '</p>';
            html += '<div class="grid-menu-cust">';

            items.forEach(function (m) {
                var tersedia = m.tersedia && m.aktif;
                var sisa = m.stok === null ? null : Math.max(0, m.stok - (m.jumlah || 0));
                var habis = (sisa !== null && sisa <= 0);
                var bisa = tersedia && !habis;

                var kelasStok = '';
                if (sisa !== null) {
                    kelasStok = sisa <= 0 ? 'habis' : (sisa <= (m.stok * 0.2) ? 'tipis' : 'aman');
                }

                var imgUrl = m.foto ? ('uploads/menu/' + m.foto) : '';

                html += '<div class="kartu-cust' + (bisa ? '' : ' habis') + (keranjang[m.id] ? ' dipilih' : '') + '"'
                    + ' data-menu-id="' + m.id + '"'
                    + ' data-nama="' + m.nama + '"'
                    + ' data-harga="' + m.harga + '"'
                    + ' data-kategori="' + kat + '"'
                    + ' data-sisa-stok="' + (sisa === null ? '' : sisa) + '">';
                html += '<span class="cek" aria-hidden="true">✓</span>';
                html += '<div class="foto">';
                if (imgUrl) {
                    html += '<img src="' + imgUrl + '" alt="' + m.nama + '" loading="lazy" onerror="this.onerror=null;this.parentNode.innerHTML=\'🍱\';">';
                } else {
                    html += '<span aria-hidden="true">🍱</span>';
                }
                html += '</div>';
                html += '<div class="isi">';
                html += '<span class="badge-kat">' + infoKat[0] + '</span>';
                html += '<div class="nama">' + m.nama + '</div>';
                html += '<div class="harga">' + formatRupiah(m.harga) + ' <small>/ box</small></div>';
                html += '<div class="badge-stok ' + kelasStok + '">';
                if (!tersedia) html += 'Tidak tersedia';
                else if (sisa === null) html += 'Stok tersedia';
                else if (habis) html += 'Stok habis';
                else html += 'Sisa ' + sisa + ' box';
                html += '</div>';
                html += '<div class="pilih">';
                if (bisa) {
                    html += '<button type="button" class="btn btn-garis btn-kecil btn-blok pilih-menu">' + (keranjang[m.id] ? 'Batalkan Pilihan' : 'Pilih') + '</button>';
                } else {
                    html += '<button type="button" class="btn btn-garis btn-kecil btn-blok" disabled>Tidak dapat dipilih</button>';
                }
                html += '</div></div></div>';
            });

            html += '</div></div>';
        });

        wadah.innerHTML = html;
        pasangEventKartu();
    }

    function renderKeranjang() {
        var box = totalBox(), sub = subtotal();
        var isi = $('#ringkasanIsi');
        var btnKosongkan = $('#kosongkanKeranjang');
        if (btnKosongkan) btnKosongkan.hidden = (box === 0);

        if (box === 0) {
            if (isi) isi.innerHTML = '<div class="ringkasan-kosong">Keranjang masih kosong.<br>Pilih menu di samping.</div>';
        } else {
            var html = '';
            Object.keys(keranjang).forEach(function (id) {
                var it = keranjang[id];
                html += '<div class="baris-menu" data-id="' + id + '">'
                    + '<div class="kiri"><div class="nm">' + it.nama + '</div>'
                    + '<div class="hr">' + formatRupiah(it.harga) + ' × ' + it.jumlah + ' = ' + formatRupiah(it.harga * it.jumlah) + '</div>'
                    + '<div class="stepper" style="margin-top:6px">'
                    + '<button type="button" data-kurang="' + id + '">−</button>'
                    + '<input type="number" value="' + it.jumlah + '" min="1" data-ubah="' + id + '">'
                    + '<button type="button" data-tambah="' + id + '">+</button>'
                    + '</div></div>'
                    + '<button type="button" class="hapus" data-hapus="' + id + '">×</button>'
                    + '</div>';
            });
            if (isi) isi.innerHTML = html;
        }

        if ($('#totalBox')) $('#totalBox').textContent = box;
        if ($('#subtotal')) $('#subtotal').textContent = formatRupiah(sub);
        if ($('#totalHarga')) $('#totalHarga').textContent = formatRupiah(sub);
        if ($('#hitungKeranjang')) $('#hitungKeranjang').textContent = box;
        if ($('#cartBarBox')) $('#cartBarBox').textContent = box + ' box';
        if ($('#cartBarTotal')) $('#cartBarTotal').textContent = formatRupiah(sub);
        if ($('#cartBar')) $('#cartBar').classList.toggle('aktif', box > 0);

        // Ringkasan di Form Checkout
        var co = $('#ringkasanCheckoutIsi');
        if (co) {
            if (box === 0) co.innerHTML = '<p class="bantuan">Belum ada menu dipilih.</p>';
            else {
                var h = '<table class="nota-rincian-tabel" style="font-size:13px;width:100%">';
                Object.keys(keranjang).forEach(function (id) {
                    var it = keranjang[id];
                    h += '<tr><td>' + it.nama + ' × ' + it.jumlah + '</td><td style="text-align:right">' + formatRupiah(it.harga * it.jumlah) + '</td></tr>';
                });
                h += '</table>';
                co.innerHTML = h;
            }
        }
        if ($('#totalCheckout')) $('#totalCheckout').textContent = formatRupiah(sub);

        var minBox = CFG.MINIMAL_BOX || 10;
        var cukup = box >= minBox;
        if ($('#btnLanjut')) $('#btnLanjut').disabled = !cukup;
        if ($('#btnBuatPesanan')) $('#btnBuatPesanan').disabled = !cukup;
        if ($('#pesanMin')) {
            $('#pesanMin').textContent = cukup ? (box + ' box siap dipesan') : ('Minimum ' + minBox + ' box, kurang ' + (minBox - box));
        }
        if ($('#fJumlahBox')) $('#fJumlahBox').value = box;

        // Update tanda dipilih di kartu
        $$('.kartu-cust[data-menu-id]').forEach(function (k) {
            var mid = k.getAttribute('data-menu-id');
            var ada = !!keranjang[mid];
            k.classList.toggle('dipilih', ada);
            var btn = k.querySelector('.pilih-menu');
            if (btn) btn.textContent = ada ? 'Batalkan Pilihan' : 'Pilih';
        });
    }

    function pasangEventKartu() {
        $$('.kartu-cust[data-menu-id]').forEach(function (kartu) {
            var btn = kartu.querySelector('.pilih-menu');
            if (!btn) return;
            btn.onclick = function (e) {
                e.stopPropagation();
                var id = kartu.getAttribute('data-menu-id');
                var nama = kartu.getAttribute('data-nama');
                var harga = parseInt(kartu.getAttribute('data-harga'), 10) || 0;
                var kat = kartu.getAttribute('data-kategori');

                if (keranjang[id]) {
                    delete keranjang[id];
                    toast('Dihapus: ' + nama);
                } else {
                    keranjang[id] = { id: id, nama: nama, harga: harga, kategori: kat, jumlah: 1 };
                    toast('Ditambahkan: ' + nama, 'sukses');
                }
                simpanKeranjang();
                renderKeranjang();
            };
        });
    }

    // -----------------------------------------------------------------
    // Event Handler Utama
    // -----------------------------------------------------------------
    document.addEventListener('DOMContentLoaded', function () {
        // Toggle Navigasi Mobile
        var btnToggle = $('#custToggle'), navLinks = $('#custLinks');
        if (btnToggle && navLinks) {
            btnToggle.onclick = function () {
                var buka = navLinks.classList.toggle('buka');
                btnToggle.setAttribute('aria-expanded', buka ? 'true' : 'false');
            };
        }

        // Setup Tanggal
        var inpTgl = $('#cekTanggal'), inpTglForm = $('#fTgl');
        if (inpTgl) {
            inpTgl.min = tglMin;
            inpTgl.max = tglMaks;
            inpTgl.value = tglPilih;
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
        perbaruiKapasitas(tglPilih);
        muatKatalog(tglPilih);
        renderKeranjang();

        // Delegasi Event Keranjang (Stepper & Hapus)
        var ringkasan = $('#ringkasan');
        if (ringkasan) {
            ringkasan.onclick = function (e) {
                var kurangId = e.target.getAttribute('data-kurang');
                var tambahId = e.target.getAttribute('data-tambah');
                var hapusId = e.target.getAttribute('data-hapus');

                if (kurangId && keranjang[kurangId]) {
                    keranjang[kurangId].jumlah = Math.max(1, (keranjang[kurangId].jumlah || 1) - 1);
                    simpanKeranjang();
                    renderKeranjang();
                } else if (tambahId && keranjang[tambahId]) {
                    keranjang[tambahId].jumlah = (keranjang[tambahId].jumlah || 1) + 1;
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
                    keranjang[ubahId].jumlah = isNaN(v) || v < 1 ? 1 : v;
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

        var btnLanjut = $('#btnLanjut');
        if (btnLanjut) {
            btnLanjut.onclick = function () {
                var elCheckout = $('#checkout');
                if (elCheckout) elCheckout.scrollIntoView({ behavior: 'smooth' });
            };
        }

        // Submit Form Checkout
        var form = $('#formCheckout');
        if (form) {
            form.onsubmit = async function (e) {
                e.preventDefault();
                var galatDiv = $('#galatCheckout');
                if (galatDiv) { galatDiv.style.display = 'none'; galatDiv.textContent = ''; }

                var nama = ($('#fNama') || {}).value || '';
                var no_wa = ($('#fWa') || {}).value || '';
                var tgl = (inpTglForm || {}).value || tglPilih;
                var jam = ($('#fJam') || {}).value || '10:00';
                var catatan = ($('#fCatatan') || {}).value || '';

                var items = Object.keys(keranjang).map(function (k) {
                    return { menu_id: parseInt(k, 10), jumlah: keranjang[k].jumlah };
                });

                var btnSubmit = $('#btnBuatPesanan');
                if (btnSubmit) { btnSubmit.disabled = true; btnSubmit.textContent = 'Memproses Pesanan...'; }

                var res = await DB.buatPesanan({
                    nama: nama,
                    no_wa: no_wa,
                    tanggal_ambil: tgl,
                    jam_ambil: jam,
                    catatan: catatan,
                    items: items
                });

                if (!res || !res.ok) {
                    if (btnSubmit) { btnSubmit.disabled = false; btnSubmit.textContent = 'Lanjutkan Pembayaran'; }
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
