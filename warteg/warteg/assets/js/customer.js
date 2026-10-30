// =====================================================================
// Warteg Kharisma — interaksi halaman pelanggan.
// Semua keputusan final (harga, stok, kuota, aturan) tetap divalidasi server.
// File ini mengatur tampilan: keranjang, meal builder, ringkasan, dan pengiriman checkout.
// =====================================================================
(function () {
    'use strict';
    var W = window.WARTEG || {};
    var csrf = W.csrf || '';
    var base = W.base || '';
    if (base.slice(-1) === '/') base = base.slice(0, -1);

    // ---------- Util ----------
    function $(s, r) { return (r || document).querySelector(s); }
    function $$(s, r) { return [].slice.call((r || document).querySelectorAll(s)); }
    function rupiah(n) { return 'Rp' + Math.round(n || 0).toLocaleString('id-ID'); }
    function toast(msg, tipe) {
        var area = $('#custToast'); if (!area) return;
        var t = document.createElement('div');
        t.className = 'cust-toast ' + (tipe || '');
        t.setAttribute('role', 'status');
        t.textContent = msg;
        area.appendChild(t);
        setTimeout(function () { t.remove(); }, 4000);
    }
    function api(params, method) {
        var url = base + '/pesan_aksi.php';
        if (method === 'GET') {
            var qs = new URLSearchParams(params).toString();
            return fetch(url + '?' + qs, { credentials: 'same-origin' })
                .then(function (r) { return r.json().catch(function () { return { ok: false, pesan: 'Respons tidak valid.' }; }); })
                .catch(function () { return { ok: false, pesan: 'Tidak dapat menghubungi server.' }; });
        }
        var fd = new FormData();
        Object.keys(params).forEach(function (k) { fd.append(k, params[k]); });
        fd.append('csrf', csrf);
        return fetch(url, { method: 'POST', body: fd, credentials: 'same-origin', headers: { 'X-CSRF-Token': csrf } })
            .then(function (r) { return r.json().catch(function () { return { ok: false, pesan: 'Respons tidak valid.' }; }); })
            .catch(function () { return { ok: false, pesan: 'Tidak dapat menghubungi server.' }; });
    }

    // ---------- Jam pengambilan ----------
    function isiJam() {
        var sel = $('#fJam'); if (!sel) return;
        var buka = W.jamBuka || '09:00', tutup = W.jamTutup || '19:00';
        var b = parseInt(buka.slice(0, 2), 10), t = parseInt(tutup.slice(0, 2), 10);
        sel.innerHTML = '';
        for (var h = b; h <= t; h++) {
            for (var mi = 0; mi < 60; mi += 30) {
                if (h === t && mi > 0) break;
                var hh = (h < 10 ? '0' : '') + h, mm = (mi < 10 ? '0' : '') + mi;
                var opt = document.createElement('option');
                opt.value = hh + ':' + mm; opt.textContent = hh + ':' + mm + ' WIB';
                sel.appendChild(opt);
            }
        }
    }

    // ---------- Keranjang (localStorage) ----------
    var KUNCI = 'warteg_keranjang';
    var keranjang = {}; // id -> {id, nama, harga, kategori, jumlah}
    try { keranjang = JSON.parse(localStorage.getItem(KUNCI) || '{}') || {}; } catch (e) { keranjang = {}; }
    function simpan() { try { localStorage.setItem(KUNCI, JSON.stringify(keranjang)); } catch (e) {} }

    function totalBox() { var t = 0; Object.keys(keranjang).forEach(function (k) { t += keranjang[k].jumlah; }); return t; }
    function subtotal() { var t = 0; Object.keys(keranjang).forEach(function (k) { t += keranjang[k].harga * keranjang[k].jumlah; }); return t; }

    function tandaiKartu() {
        $$('.kartu-cust[data-menu-id]').forEach(function (k) {
            var id = k.getAttribute('data-menu-id');
            var ada = !!keranjang[id];
            k.classList.toggle('dipilih', ada);
            var btn = k.querySelector('.pilih-menu');
            if (btn) btn.textContent = ada ? 'Batalkan Pilihan' : 'Pilih';
        });
    }

    function renderKeranjang() {
        var isi = $('#ringkasanIsi');
        var box = totalBox(), sub = subtotal();
        var kosongBtn = $('#kosongkanKeranjang');
        if (kosongBtn) kosongBtn.hidden = (box === 0);

        if (box === 0) {
            if (isi) isi.innerHTML = '<div class="ringkasan-kosong">Keranjang masih kosong.<br>Pilih menu di samping.</div>';
        } else {
            var html = '';
            var urut = ['nasi', 'lauk', 'sayur', 'paket a', 'paket b', 'paket c'];
            var ids = Object.keys(keranjang).sort(function (a, b) {
                var ia = urut.indexOf(keranjang[a].kategori), ib = urut.indexOf(keranjang[b].kategori);
                if (ia < 0) ia = 99; if (ib < 0) ib = 99;
                return ia - ib;
            });
            ids.forEach(function (id) {
                var it = keranjang[id];
                html += '<div class="baris-menu" data-id="' + id + '">' +
                    '<div class="kiri"><div class="nm">' + esc(it.nama) + '</div>' +
                    '<div class="hr">' + rupiah(it.harga) + ' × ' + it.jumlah + ' = ' + rupiah(it.harga * it.jumlah) + '</div>' +
                    '<div class="stepper" style="margin-top:6px">' +
                    '<button type="button" data-kurang="' + id + '" aria-label="Kurangi">−</button>' +
                    '<input type="number" value="' + it.jumlah + '" min="1" data-ubah="' + id + '" aria-label="Jumlah ' + esc(it.nama) + '">' +
                    '<button type="button" data-tambah="' + id + '" aria-label="Tambah">+</button>' +
                    '</div></div>' +
                    '<button type="button" class="hapus" data-hapus="' + id + '" aria-label="Hapus ' + esc(it.nama) + '">×</button>' +
                    '</div>';
            });
            if (isi) isi.innerHTML = html;
        }

        // Totals
        if ($('#totalBox')) $('#totalBox').textContent = box;
        if ($('#subtotal')) $('#subtotal').textContent = rupiah(sub);
        if ($('#totalHarga')) $('#totalHarga').textContent = rupiah(sub);
        if ($('#hitungKeranjang')) $('#hitungKeranjang').textContent = box;
        if ($('#cartBarBox')) $('#cartBarBox').textContent = box + ' box';
        if ($('#cartBarTotal')) $('#cartBarTotal').textContent = rupiah(sub);
        if ($('#cartBar')) $('#cartBar').classList.toggle('aktif', box > 0);

        // Ringkasan di panel checkout
        var co = $('#ringkasanCheckoutIsi');
        if (co) {
            if (box === 0) co.innerHTML = '<p class="bantuan">Belum ada menu dipilih.</p>';
            else {
                var h = '<table class="nota-rincian-tabel" style="font-size:13px">';
                Object.keys(keranjang).forEach(function (id) {
                    var it = keranjang[id];
                    h += '<tr><td>' + esc(it.nama) + ' × ' + it.jumlah + '</td><td>' + rupiah(it.harga * it.jumlah) + '</td></tr>';
                });
                h += '</table>';
                co.innerHTML = h;
            }
        }
        if ($('#totalCheckout')) $('#totalCheckout').textContent = rupiah(sub);

        // Tombol lanjut
        var cukup = box >= (W.minimal || 10);
        var lanjut = $('#btnLanjut');
        if (lanjut) lanjut.disabled = !cukup;
        if ($('#pesanMin')) {
            $('#pesanMin').textContent = cukup ? (box + ' box siap dipesan') : ('Minimum ' + (W.minimal || 10) + ' box, kurang ' + ((W.minimal || 10) - box));
        }
        if ($('#fJumlahBox')) $('#fJumlahBox').value = box;
        periksaSiapKirim();
    }

    function esc(s) { return String(s).replace(/[&<>"']/g, function (c) { return { '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[c]; }); }

    // ---------- Meal builder klik ----------
    function pasangKartu() {
        $$('.kartu-cust[data-menu-id]').forEach(function (kartu) {
            var btn = kartu.querySelector('.pilih-menu');
            if (!btn) return;
            btn.addEventListener('click', function () {
                var id = kartu.getAttribute('data-menu-id');
                var kategori = (kartu.getAttribute('data-kategori') || '').toLowerCase();
                var sisa = kartu.getAttribute('data-sisa-stok');

                if (keranjang[id]) {
                    // Batalkan pilihan menu ini saja (tidak mengganggu pilihan lain).
                    delete keranjang[id];
                    tandaiKartu(); simpan(); renderKeranjang();
                    toast(kartu.getAttribute('data-nama') + ' dihapus dari keranjang.', '');
                    return;
                }

                // Stok habis / tidak tersedia -> tolak (validasi ganda di server tetap berlaku).
                if (kartu.classList.contains('habis')) {
                    toast(kartu.getAttribute('data-nama') + ' sedang tidak tersedia.', 'galat');
                    return;
                }
                if (sisa !== '' && sisa !== null) {
                    var sisaN = parseInt(sisa, 10);
                    if (isNaN(sisaN) || sisaN <= 0) { toast('Stok ' + kartu.getAttribute('data-nama') + ' habis.', 'galat'); return; }
                }

                // Paket siap pilih: hanya boleh satu paket dalam satu pesanan (hindari hitung ganda).
                if (kategori.indexOf('paket') === 0) {
                    var sudahPaket = Object.keys(keranjang).some(function (k) { return (keranjang[k].kategori || '').toLowerCase().indexOf('paket') === 0; });
                    if (sudahPaket) { toast('Paket hanya bisa dipilih satu per pesanan.', 'galat'); return; }
                } else {
                    // Aturan komposisi: satu pilihan per kategori (nasi / lauk / sayur).
                    // Memilih menu baru di kategori yang sama menggantikan pilihan lama,
                    // sesuai aturan "Pilih satu jenis ... untuk setiap box".
                    var lama = Object.keys(keranjang).filter(function (k) {
                        return (keranjang[k].kategori || '').toLowerCase() === kategori;
                    });
                    lama.forEach(function (k) { delete keranjang[k]; });
                }

                // PENTING: jumlah awal tiap menu = 1 box (BUKAN MINIMAL_BOX).
                // Total box dihitung dari akumulasi jumlah seluruh menu; pengguna menaikkan
                // lewat stepper hingga memenuhi minimum. Tidak ada auto-jadi-10-box.
                var jumlah = 1;
                if (sisa !== '' && sisa !== null) {
                    var sisaN2 = parseInt(sisa, 10);
                    if (!isNaN(sisaN2) && jumlah > sisaN2) jumlah = sisaN2;
                }
                keranjang[id] = {
                    id: id,
                    nama: kartu.getAttribute('data-nama'),
                    harga: parseInt(kartu.getAttribute('data-harga'), 10) || 0,
                    kategori: kategori,
                    jumlah: jumlah
                };
                tandaiKartu(); simpan(); renderKeranjang();
                toast(kartu.getAttribute('data-nama') + ' ditambahkan (1 box).', 'sukses');
            });
        });
    }

    // Batas atas jumlah satu menu: sisa stok kartu (bila ada). null = tanpa batas.
    function batasStok(id) {
        var kartu = document.querySelector('.kartu-cust[data-menu-id="' + id + '"]');
        if (!kartu) return null;
        var sisa = kartu.getAttribute('data-sisa-stok');
        if (sisa === '' || sisa === null) return null;
        var n = parseInt(sisa, 10);
        return isNaN(n) ? null : n;
    }
    function setJumlah(id, v) {
        if (!keranjang[id]) return;
        var min = 1, maks = batasStok(id);
        if (isNaN(v) || v < min) v = min;
        if (maks !== null && v > maks) {
            v = maks;
            toast('Melebihi sisa stok. Maksimal ' + maks + ' box untuk ' + keranjang[id].nama + '.', 'galat');
        }
        keranjang[id].jumlah = v; simpan(); renderKeranjang();
    }

    // ---------- Delegasi tombol stepper/hapus ----------
    var ringkasan = $('#ringkasan');
    if (ringkasan) {
        ringkasan.addEventListener('click', function (e) {
            var t = e.target.closest ? e.target.closest('button') : e.target;
            if (!t || !t.hasAttribute) t = e.target;
            if (t.hasAttribute('data-tambah')) {
                var id = t.getAttribute('data-tambah');
                if (keranjang[id]) setJumlah(id, keranjang[id].jumlah + 1);
            } else if (t.hasAttribute('data-kurang')) {
                var id2 = t.getAttribute('data-kurang');
                if (keranjang[id2]) setJumlah(id2, Math.max(1, keranjang[id2].jumlah - 1));
            } else if (t.hasAttribute('data-hapus')) {
                var id3 = t.getAttribute('data-hapus');
                delete keranjang[id3]; tandaiKartu(); simpan(); renderKeranjang();
            }
        });
        ringkasan.addEventListener('change', function (e) {
            if (e.target.hasAttribute('data-ubah')) {
                var id = e.target.getAttribute('data-ubah');
                setJumlah(id, parseInt(e.target.value, 10));
            }
        });
    }
    var kosong = $('#kosongkanKeranjang');
    if (kosong) kosong.addEventListener('click', function () {
        if (!confirm('Kosongkan seluruh keranjang?')) return;
        keranjang = {}; tandaiKartu(); simpan(); renderKeranjang();
    });

    // ---------- Cek kuota & muat menu ulang untuk tanggal tertentu ----------
    var tanggalAktif = ($('#cekTanggal') && $('#cekTanggal').value) || '';

    function muatKuota(tgl, muatMenu) {
        return api({ aksi: 'menu', tanggal: tgl }, 'GET').then(function (res) {
            if (!res.ok) { toast(res.pesan || 'Gagal memuat kuota.', 'galat'); return; }
            if ($('#kapTerpakai')) $('#kapTerpakai').textContent = res.kuota.terpakai;
            if ($('#kapSisa')) $('#kapSisa').textContent = res.kuota.sisa;
            if ($('#barKapasitas')) $('#barKapasitas').style.width = res.kuota.persen + '%';
            var ks = $('#kotakSisa'); if (ks) { ks.className = 'kapasitas-kotak sisa ' + res.kuota.level; }
            if ($('#teksTanggal')) $('#teksTanggal').textContent = res.tanggal_label + ' · jam pengambilan ' + (W.jamBuka || '09:00') + '–' + (W.jamTutup || '19:00') + ' WIB';
            if (muatMenu) {
                renderKatalog(res.menu);
                tanggalAktif = tgl;
                // sinkronkan input tanggal checkout
                if ($('#fTgl')) { $('#fTgl').value = tgl; }
            }
        });
    }

    // Render ulang katalog dari data AJAX (format kartu sama seperti server).
    function renderKatalog(menu) {
        var wadah = $('#katalogMenu'); if (!wadah) return;
        if (!menu || !menu.length) {
            wadah.innerHTML = '<div class="kosong"><div class="ikon">🍽️</div><p>Belum ada menu yang dibuka untuk tanggal ini.</p></div>';
            return;
        }
        var label = { 'nasi': ['Nasi', 'Pilih satu jenis nasi untuk setiap box.'], 'lauk': ['Lauk Utama', 'Pilih satu lauk utama.'], 'sayur': ['Sayur / Pendamping', 'Pilih sayur atau pendamping.'], 'paket a': ['Paket A', ''], 'paket b': ['Paket B', ''], 'paket c': ['Paket C', ''] };
        var urut = ['nasi', 'lauk', 'sayur', 'paket a', 'paket b', 'paket c'];
        var grup = {};
        menu.forEach(function (m) { (grup[m.kategori] = grup[m.kategori] || []).push(m); });
        var html = '';
        urut.forEach(function (kat) {
            if (!grup[kat]) return;
            var lab = label[kat] || [kat, ''];
            html += '<div class="kategori-blok" data-kategori="' + esc(kat) + '"><h3>' + esc(lab[0]) + '</h3><p class="aturan">' + esc(lab[1]) + '</p><div class="grid-menu-cust">';
            grup[kat].forEach(function (m) {
                var kelasStok = '';
                if (m.sisa_stok !== null) kelasStok = m.sisa_stok <= 0 ? 'habis' : (m.sisa_stok <= m.stok * 0.2 ? 'tipis' : 'aman');
                var teksStok = !m.tersedia ? 'Tidak tersedia' : (m.sisa_stok === null ? 'Stok tersedia' : (m.sisa_stok <= 0 ? 'Stok habis' : 'Sisa ' + m.sisa_stok + ' box'));
                html += '<div class="kartu-cust' + (m.bisa ? '' : ' habis') + '" data-menu-id="' + m.id + '" data-nama="' + esc(m.nama) + '" data-harga="' + m.harga + '" data-kategori="' + esc(m.kategori) + '" data-sisa-stok="' + (m.sisa_stok === null ? '' : m.sisa_stok) + '">' +
                    '<span class="cek" aria-hidden="true">✓</span>' +
                    '<div class="foto">' + (m.foto ? '<img src="' + esc(m.foto) + '" alt="' + esc(m.nama) + '" loading="lazy">' : '<span aria-hidden="true">🍱</span>') + '</div>' +
                    '<div class="isi"><span class="badge-kat">' + esc((label[m.kategori] || [m.kategori])[0]) + '</span>' +
                    '<div class="nama">' + esc(m.nama) + '</div>' +
                    '<div class="harga">' + rupiah(m.harga) + ' <small>/ box</small></div>' +
                    '<div class="badge-stok ' + kelasStok + '">' + esc(teksStok) + '</div>' +
                    '<div class="pilih">' + (m.bisa ? '<button type="button" class="btn btn-garis btn-kecil btn-blok pilih-menu">Pilih</button>' : '<button type="button" class="btn btn-garis btn-kecil btn-blok" disabled>Tidak dapat dipilih</button>') + '</div>' +
                    '</div></div>';
            });
            html += '</div></div>';
        });
        wadah.innerHTML = html;
        pasangKartu();
        tandaiKartu();
    }

    var btnCek = $('#btnCekTanggal');
    if (btnCek) btnCek.addEventListener('click', function () {
        var tgl = $('#cekTanggal').value;
        if (!tgl) { toast('Pilih tanggal terlebih dahulu.', 'galat'); return; }
        btnCek.disabled = true; btnCek.textContent = 'Memuat…';
        muatKuota(tgl, true).then(function () { btnCek.disabled = false; btnCek.textContent = 'Tampilkan Kuota'; });
    });
    if ($('#cekTanggal')) {
        $('#cekTanggal').addEventListener('change', function () {
            var tgl = this.value;
            muatKuota(tgl, true);
        });
    }
    // Sinkronkan tanggal checkout dengan pemilihan tanggal katalog bila diubah di form.
    if ($('#fTgl')) {
        $('#fTgl').addEventListener('change', function () {
            var tgl = this.value;
            if (tgl && tgl !== tanggalAktif) {
                if ($('#cekTanggal')) $('#cekTanggal').value = tgl;
                muatKuota(tgl, true);
            }
        });
    }

    // ---------- Tombol "Lanjutkan" -> scroll ke checkout ----------
    var lanjut = $('#btnLanjut');
    if (lanjut) lanjut.addEventListener('click', function () {
        var co = $('#checkout');
        if (co) { co.scrollIntoView({ behavior: 'smooth', block: 'start' }); var n = $('#fNama'); if (n) setTimeout(function () { n.focus(); }, 400); }
    });

    // ---------- Validasi klien (format cepat) ----------
    function waValid(v) {
        var d = String(v).replace(/[^\d]/g, '');
        if (d.charAt(0) === '0') d = '62' + d.slice(1);
        return /^62\d{8,14}$/.test(d) || /^\d{10,15}$/.test(d);
    }
    function tampilGalat(field, msg) {
        var el = document.querySelector('.pesan-galat[data-untuk="' + field + '"]');
        if (el) { el.textContent = msg || ''; el.classList.toggle('tampil', !!msg); }
        var inp = document.getElementById('f' + field.charAt(0).toUpperCase() + field.slice(1).replace('_wa', 'Wa').replace('tanggal_ambil', 'Tgl').replace('jam_ambil', 'Jam'));
        if (inp) inp.classList.toggle('salah', !!msg);
    }

    function periksaSiapKirim() {
        var btn = $('#btnBuatPesanan'); if (!btn) return;
        var box = totalBox();
        var nama = ($('#fNama') || {}).value || '';
        var wa = ($('#fWa') || {}).value || '';
        var cukup = box >= (W.minimal || 10) && nama.trim().length >= 1 && waValid(wa);
        btn.disabled = !cukup;
    }
    ['#fNama', '#fWa'].forEach(function (s) {
        var el = $(s); if (el) el.addEventListener('input', function () { periksaSiapKirim(); });
    });

    // ---------- Submit pesanan ----------
    var form = $('#formCheckout');
    if (form) form.addEventListener('submit', function (e) {
        e.preventDefault();
        var box = totalBox();
        if (box < (W.minimal || 10)) { toast('Minimum ' + (W.minimal || 10) + ' box.', 'galat'); return; }
        var nama = $('#fNama').value.trim();
        var wa = $('#fWa').value.trim();
        var tgl = $('#fTgl').value;
        var jam = $('#fJam').value;
        var catatan = $('#fCatatan').value.trim();

        // Reset galat
        ['nama', 'no_wa', 'tanggal_ambil', 'jam_ambil', 'catatan'].forEach(function (f) { tampilGalat(f, ''); });
        var galat = $('#galatCheckout'); if (galat) { galat.style.display = 'none'; galat.textContent = ''; }

        var valid = true;
        if (!nama) { tampilGalat('nama', 'Nama wajib diisi.'); valid = false; }
        if (!waValid(wa)) { tampilGalat('no_wa', 'Nomor WhatsApp tidak valid.'); valid = false; }
        if (!tgl) { tampilGalat('tanggal_ambil', 'Tanggal wajib dipilih.'); valid = false; }
        if (!jam) { tampilGalat('jam_ambil', 'Jam wajib dipilih.'); valid = false; }
        if (!valid) { toast('Periksa kembali data pemesan.', 'galat'); return; }

        // Susun items JSON
        var items = {};
        Object.keys(keranjang).forEach(function (id) { items[id] = keranjang[id].jumlah; });
        if (!Object.keys(items).length) { toast('Keranjang kosong.', 'galat'); return; }

        // Idempoten: satu token per percobaan submit (mencegah pesanan ganda saat klik berulang).
        var idem = 'id' + Date.now() + Math.random().toString(36).slice(2, 10);

        var btn = $('#btnBuatPesanan');
        btn.disabled = true;
        var teksAwal = $('#btnBuatTeks').textContent;
        $('#btnBuatTeks').textContent = 'Memproses…';

        api({
            aksi: 'pesan',
            nama: nama, no_wa: wa, tanggal_ambil: tgl, jam_ambil: jam, catatan: catatan,
            items: JSON.stringify(items), jumlah_box: box, idempotency: idem
        }, 'POST').then(function (res) {
            btn.disabled = false;
            $('#btnBuatTeks').textContent = teksAwal;

            if (!res.ok) {
                if (res.field) tampilGalat(res.field, res.pesan);
                if (galat) { galat.style.display = 'flex'; galat.textContent = res.pesan || 'Terjadi kesalahan.'; }
                toast(res.pesan || 'Gagal membuat pesanan.', 'galat');
                if (res.sisa !== undefined && res.sisa !== null) {
                    // Perbarui kuota yang tampil bila ditolak karena kuota.
                    if ($('#kapSisa')) $('#kapSisa').textContent = res.sisa;
                }
                return;
            }

            // Berhasil: kosongkan keranjang & arahkan ke halaman pembayaran/nota.
            keranjang = {}; simpan();
            toast('Pesanan ' + res.kode + ' berhasil dibuat.', 'sukses');
            // Arahkan ke pembayaran (QRIS) atau nota bila gateway belum dikonfigurasi.
            var tujuan = res.pembayaran && res.pembayaran.terkonfigurasi ? res.url_bayar : res.url_nota;
            setTimeout(function () { window.location.href = tujuan; }, 700);
        });
    });

    // ---------- Toggle menu navigasi mobile ----------
    var toggle = $('#custToggle'), links = $('#custLinks');
    if (toggle && links) {
        toggle.addEventListener('click', function () {
            var buka = links.classList.toggle('buka');
            toggle.setAttribute('aria-expanded', buka ? 'true' : 'false');
        });
    }

    // ---------- Init ----------
    isiJam();
    // BUG FIX: pasangKartu() WAJIB dipanggil saat init. Sebelumnya hanya dipanggil dari
    // renderKatalog() (setelah AJAX ganti tanggal), sehingga tombol "Pilih" yang dirender
    // server-side pada awal halaman TIDAK punya event listener dan tidak bisa diklik.
    pasangKartu();
    tandaiKartu();
    renderKeranjang();
    periksaSiapKirim();
})();
