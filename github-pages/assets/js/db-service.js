// =====================================================================
// DATA LAYER HYBRID: SUPABASE CLIENT + LOCALSTORAGE FALLBACK ENGINE
// =====================================================================
(function () {
    'use strict';

    var CFG = window.WARTEG_CONFIG || {};
    var LS_KEY = 'warteg_db_state_v1';
    var SESSION_KEY = 'warteg_admin_session';

    // -----------------------------------------------------------------
    // Helper: Formatter Format Indonesia
    // -----------------------------------------------------------------
    function rupiah(n) {
        return 'Rp' + Math.round(n || 0).toLocaleString('id-ID');
    }

    var HARI_ID = ['Minggu', 'Senin', 'Selasa', 'Rabu', 'Kamis', 'Jumat', 'Sabtu'];
    var BULAN_ID = ['', 'Januari', 'Februari', 'Maret', 'April', 'Mei', 'Juni', 'Juli', 'Agustus', 'September', 'Oktober', 'November', 'Desember'];

    function tanggalId(tglStr, denganHari) {
        if (denganHari === undefined) denganHari = true;
        if (!tglStr) return '-';
        var d = new Date(tglStr + 'T00:00:00');
        if (isNaN(d.getTime())) return tglStr;
        var hari = HARI_ID[d.getDay()];
        var tgl = d.getDate();
        var bln = BULAN_ID[d.getMonth() + 1];
        var thn = d.getFullYear();
        return (denganHari ? hari + ', ' : '') + tgl + ' ' + bln + ' ' + thn;
    }

    function jamPendek(jamStr) {
        if (!jamStr) return '00:00';
        return jamStr.slice(0, 5);
    }

    function statusBayarLabel(sb) {
        var map = {
            'belum_bayar': 'Belum Bayar',
            'menunggu': 'Menunggu Pembayaran',
            'menunggu_verifikasi': 'Menunggu Verifikasi',
            'berhasil': 'Lunas',
            'ditolak': 'Bukti Ditolak',
            'gagal': 'Gagal',
            'kedaluwarsa': 'Kedaluwarsa',
            'dibatalkan': 'Dibatalkan'
        };
        return map[sb] || sb;
    }

    function kelasStatusBayar(sb) {
        var map = {
            'berhasil': 'bayar-hijau',
            'menunggu_verifikasi': 'bayar-oranye',
            'menunggu': 'bayar-kuning',
            'belum_bayar': 'bayar-abu',
            'ditolak': 'bayar-merah',
            'gagal': 'bayar-merah',
            'kedaluwarsa': 'bayar-merah',
            'dibatalkan': 'bayar-merah'
        };
        return map[sb] || 'bayar-abu';
    }

    function badgeStatus(status) {
        var map = {
            'Menunggu Konfirmasi': '<span class="badge badge-status status-menunggu">Menunggu Konfirmasi</span>',
            'Sedang Dimasak': '<span class="badge badge-status status-masak">Sedang Dimasak</span>',
            'Siap Diambil': '<span class="badge badge-status status-siap">Siap Diambil</span>'
        };
        return map[status] || '<span class="badge badge-status">' + (status || '-') + '</span>';
    }

    function linkWa(noWa, nama, box, kode) {
        if (!noWa) return '';
        var clean = noWa.replace(/\D/g, '');
        if (clean.startsWith('0')) clean = '62' + clean.slice(1);
        var pesan = 'Halo Kak ' + nama + ', pesanan nasi box Anda (' + kode + ' sejumlah ' + box + ' box) di ' + CFG.NAMA_WARTEG + ' sudah SIAP DIAMBIL. Terima kasih!';
        return 'https://wa.me/' + clean + '?text=' + encodeURIComponent(pesan);
    }

    // -----------------------------------------------------------------
    // Inisialisasi Data Demo Awal (LocalStorage Fallback)
    // -----------------------------------------------------------------
    function getTodayStr(offsetDays) {
        var d = new Date();
        if (offsetDays) d.setDate(d.getDate() + offsetDays);
        var yyyy = d.getFullYear();
        var mm = String(d.getMonth() + 1).padStart(2, '0');
        var dd = String(d.getDate()).padStart(2, '0');
        return yyyy + '-' + mm + '-' + dd;
    }

    function initSeedData() {
        var t0 = getTodayStr(0);
        var t1 = getTodayStr(1);
        var t2 = getTodayStr(2);

        var data = {
            admin: [
                {
                    id: 1,
                    username: 'admin',
                    password_hash: 'admin123',
                    nama: 'Admin Warteg',
                    role: 'owner',
                    bahasa: 'id',
                    foto: '006a36b5a411db9a6784.jpg',
                    email: 'admin@warteg.test',
                    telepon: '081234567800'
                }
            ],
            aktivitas: [],
            konfigurasi_toko: {
                qris_gambar: 'qris_90ce220b97a92f248db3.jpg',
                qris_nama_merchant: 'Warung Mas Alif',
                qris_nmid: 'ID1025439892668',
                qris_instruksi: 'Scan QRIS di atas dengan aplikasi bank atau e-wallet Anda. Masukkan nominal pembayaran secara MANUAL sesuai total tagihan karena QRIS ini bersifat statis (nominal tidak otomatis terisi).',
                batas_bayar_jam: '24'
            },
            menu: [
                { id: 1, nama: 'Nasi Ayam', kategori: 'nasi', harga: 15000, foto: '567776efe02122c01fcd.jpg', aktif: true },
                { id: 2, nama: 'Nasi Ikan', kategori: 'nasi', harga: 17000, foto: '58fe9cd7bf786e6ab41c.jpg', aktif: true },
                { id: 3, nama: 'Nasi Udang', kategori: 'nasi', harga: 20000, foto: '6204c3f5c6b75a69ad21.jpg', aktif: true },
                { id: 4, nama: 'Paket Hemat Komplit', kategori: 'paket a', harga: 25000, foto: '67a12609967b2741de56.jpg', aktif: true },
                { id: 5, nama: 'Ayam Lengkuas', kategori: 'lauk', harga: 12000, foto: '6a8bf56fcc847b405553.jpg', aktif: true },
                { id: 6, nama: 'Sayur Asem Segar', kategori: 'sayur', harga: 6000, foto: '7dfbefbad3bb846f2dca.jpg', aktif: true }
            ],
            menu_harian: [
                { id: 1, menu_id: 1, tanggal: t0, tersedia: true, stok: 120 },
                { id: 2, menu_id: 2, tanggal: t0, tersedia: true, stok: 120 },
                { id: 3, menu_id: 3, tanggal: t0, tersedia: true, stok: 120 },
                { id: 4, menu_id: 4, tanggal: t0, tersedia: true, stok: 120 },
                { id: 5, menu_id: 1, tanggal: t1, tersedia: true, stok: 120 },
                { id: 6, menu_id: 2, tanggal: t1, tersedia: true, stok: 120 },
                { id: 7, menu_id: 3, tanggal: t1, tersedia: true, stok: 120 },
                { id: 8, menu_id: 4, tanggal: t1, tersedia: true, stok: 120 }
            ],
            pesanan: [
                {
                    id: 1,
                    kode: 'WRT-' + t0.replace(/-/g, '') + '-001',
                    nama_pelanggan: 'Budi Santoso',
                    no_wa: '081234567801',
                    tanggal_ambil: t0,
                    jam_ambil: '10:00:00',
                    total_box: 30,
                    total_harga: 470000,
                    status: 'Siap Diambil',
                    status_bayar: 'berhasil',
                    token_nota: 'demo_token_budi_01',
                    catatan: 'Pisahkan sambal',
                    dibuat_pada: new Date(Date.now() - 3600000 * 4).toISOString()
                },
                {
                    id: 2,
                    kode: 'WRT-' + t0.replace(/-/g, '') + '-002',
                    nama_pelanggan: 'Siti Rahma',
                    no_wa: '081234567802',
                    tanggal_ambil: t0,
                    jam_ambil: '11:30:00',
                    total_box: 25,
                    total_harga: 425000,
                    status: 'Sedang Dimasak',
                    status_bayar: 'menunggu_verifikasi',
                    bukti_bayar: '44bb88b14db7c5b93805afa4a0d56da7.jpg',
                    bukti_bayar_pada: new Date(Date.now() - 3600000 * 2).toISOString(),
                    token_nota: 'demo_token_siti_02',
                    catatan: '',
                    dibuat_pada: new Date(Date.now() - 3600000 * 3).toISOString()
                },
                {
                    id: 3,
                    kode: 'WRT-' + t1.replace(/-/g, '') + '-001',
                    nama_pelanggan: 'Andi Wijaya',
                    no_wa: '081234567803',
                    tanggal_ambil: t1,
                    jam_ambil: '12:00:00',
                    total_box: 15,
                    total_harga: 255000,
                    status: 'Menunggu Konfirmasi',
                    status_bayar: 'belum_bayar',
                    token_nota: 'demo_token_andi_03',
                    catatan: 'Nasi pulen',
                    dibuat_pada: new Date().toISOString()
                }
            ],
            detail_pesanan: [
                { id: 1, pesanan_id: 1, menu_id: 1, jumlah: 20, harga_satuan: 15000, subtotal: 300000 },
                { id: 2, pesanan_id: 1, menu_id: 2, jumlah: 10, harga_satuan: 17000, subtotal: 170000 },
                { id: 3, pesanan_id: 2, menu_id: 1, jumlah: 15, harga_satuan: 15000, subtotal: 225000 },
                { id: 4, pesanan_id: 2, menu_id: 3, jumlah: 10, harga_satuan: 20000, subtotal: 200000 },
                { id: 5, pesanan_id: 3, menu_id: 2, jumlah: 15, harga_satuan: 17000, subtotal: 255000 }
            ],
            verifikasi_log: []
        };
        try {
            localStorage.setItem(LS_KEY, JSON.stringify(data));
        } catch (e) {
            console.warn('LocalStorage error saat init:', e);
        }
        return data;
    }

    function loadLocalState() {
        try {
            var raw = localStorage.getItem(LS_KEY);
            if (!raw) return initSeedData();
            return JSON.parse(raw);
        } catch (e) {
            return initSeedData();
        }
    }

    function saveLocalState(state) {
        try {
            localStorage.setItem(LS_KEY, JSON.stringify(state));
        } catch (e) {
            console.error('Gagal menyimpan local state:', e);
        }
    }

    // -----------------------------------------------------------------
    // Supabase Client Setup (Jika Dikonfigurasi)
    // -----------------------------------------------------------------
    var supabaseClient = null;
    var isSupabaseReady = false;

    if (CFG.SUPABASE_URL && CFG.SUPABASE_ANON_KEY && window.supabase && window.supabase.createClient) {
        try {
            supabaseClient = window.supabase.createClient(CFG.SUPABASE_URL, CFG.SUPABASE_ANON_KEY);
            isSupabaseReady = true;
            console.log('✅ Supabase Client Berhasil Diinisialisasi');
        } catch (e) {
            console.warn('Supabase init gagal, fallback ke LocalStorage:', e);
        }
    }

    // -----------------------------------------------------------------
    // EXPORT DATABASE SERVICE API
    // -----------------------------------------------------------------
    var DB = {
        isSupabase: function () {
            return isSupabaseReady;
        },
        rupiah: rupiah,
        tanggalId: tanggalId,
        jamPendek: jamPendek,
        statusBayarLabel: statusBayarLabel,
        kelasStatusBayar: kelasStatusBayar,
        badgeStatus: badgeStatus,
        linkWa: linkWa,

        // Reset Data Demo
        resetDataDemo: function () {
            localStorage.removeItem(LS_KEY);
            initSeedData();
            return true;
        },

        // Ringkasan Kapasitas Harian (120 Box)
        getKapasitasTanggal: async function (tanggal) {
            var kapasitas = CFG.KAPASITAS_HARIAN || 120;
            if (isSupabaseReady) {
                var { data, error } = await supabaseClient
                    .from('pesanan')
                    .select('total_box')
                    .eq('tanggal_ambil', tanggal)
                    .neq('status_bayar', 'dibatalkan');
                if (error) {
                    console.error('Supabase getKapasitas error:', error);
                } else {
                    var box = (data || []).reduce(function (sum, p) { return sum + (p.total_box || 0); }, 0);
                    var pesanan = (data || []).length;
                    var sisa = Math.max(0, kapasitas - box);
                    var persen = kapasitas > 0 ? Math.min(100, Math.round((box / kapasitas) * 100)) : 0;
                    var level = box >= kapasitas ? 'penuh' : (sisa <= 20 ? 'hampir' : 'aman');
                    return { box: box, pesanan: pesanan, sisa: sisa, persen: persen, level: level, kapasitas: kapasitas };
                }
            }

            // Fallback LocalStorage
            var state = loadLocalState();
            var orders = (state.pesanan || []).filter(function (p) {
                return p.tanggal_ambil === tanggal && p.status_bayar !== 'dibatalkan';
            });
            var b = orders.reduce(function (sum, p) { return sum + (p.total_box || 0); }, 0);
            var pes = orders.length;
            var s = Math.max(0, kapasitas - b);
            var p = kapasitas > 0 ? Math.min(100, Math.round((b / kapasitas) * 100)) : 0;
            var lvl = b >= kapasitas ? 'penuh' : (s <= 20 ? 'hampir' : 'aman');
            return { box: b, pesanan: pes, sisa: s, persen: p, level: lvl, kapasitas: kapasitas };
        },

        // Ambil Menu Tersedia untuk Tanggal Tertentu (Katalog Pelanggan / Menu Harian Admin)
        getMenuTanggal: async function (tanggal) {
            if (isSupabaseReady) {
                var { data: menus, error: errMenu } = await supabaseClient
                    .from('menu')
                    .select('*')
                    .order('kategori')
                    .order('nama');
                var { data: harian } = await supabaseClient
                    .from('menu_harian')
                    .select('*')
                    .eq('tanggal', tanggal);
                var { data: details } = await supabaseClient
                    .from('detail_pesanan')
                    .select('menu_id, jumlah, pesanan!inner(tanggal_ambil, status_bayar)')
                    .eq('pesanan.tanggal_ambil', tanggal)
                    .neq('pesanan.status_bayar', 'dibatalkan');

                if (!errMenu && menus) {
                    var hMap = {};
                    (harian || []).forEach(function (h) { hMap[h.menu_id] = h; });
                    var jMap = {};
                    (details || []).forEach(function (d) { jMap[d.menu_id] = (jMap[d.menu_id] || 0) + d.jumlah; });

                    return menus.map(function (m) {
                        var h = hMap[m.id];
                        var terjual = jMap[m.id] || 0;
                        var tersedia = h ? h.tersedia : false;
                        var stok = h ? h.stok : null;
                        var foto = (h && h.foto) || m.foto;
                        return {
                            id: m.id,
                            nama: m.nama,
                            kategori: m.kategori,
                            harga: m.harga,
                            aktif: m.aktif,
                            foto: foto,
                            tersedia: tersedia,
                            stok: stok,
                            jumlah: terjual
                        };
                    });
                }
            }

            // Fallback LocalStorage
            var state = loadLocalState();
            var menus = state.menu || [];
            var harian = (state.menu_harian || []).filter(function (h) { return h.tanggal === tanggal; });
            var hMap = {};
            harian.forEach(function (h) { hMap[h.menu_id] = h; });

            // Hitung terjual hari ini
            var ordersHariIni = (state.pesanan || []).filter(function (p) {
                return p.tanggal_ambil === tanggal && p.status_bayar !== 'dibatalkan';
            });
            var orderIds = ordersHariIni.map(function (p) { return p.id; });
            var jMap = {};
            (state.detail_pesanan || []).forEach(function (d) {
                if (orderIds.indexOf(d.pesanan_id) >= 0) {
                    jMap[d.menu_id] = (jMap[d.menu_id] || 0) + d.jumlah;
                }
            });

            return menus.map(function (m) {
                var h = hMap[m.id];
                var terjual = jMap[m.id] || 0;
                var tersedia = h ? !!h.tersedia : false;
                var stok = (h && h.stok !== undefined) ? h.stok : null;
                var foto = (h && h.foto) || m.foto;
                return {
                    id: m.id,
                    nama: m.nama,
                    kategori: m.kategori,
                    harga: m.harga,
                    aktif: m.aktif,
                    foto: foto,
                    tersedia: tersedia,
                    stok: stok,
                    jumlah: terjual
                };
            });
        },

        // Buat Pesanan Baru (Checkout Pelanggan)
        buatPesanan: async function (orderData) {
            var nama = (orderData.nama || '').trim();
            var no_wa = (orderData.no_wa || '').trim();
            var tanggal_ambil = orderData.tanggal_ambil;
            var jam_ambil = orderData.jam_ambil;
            var catatan = (orderData.catatan || '').trim();
            var items = orderData.items || []; // [{menu_id, jumlah}]

            if (!nama || !no_wa || !tanggal_ambil || !jam_ambil) {
                return { ok: false, pesan: 'Lengkapi semua isian wajib formulir.' };
            }

            var total_box = items.reduce(function (sum, it) { return sum + (parseInt(it.jumlah, 10) || 0); }, 0);
            if (total_box < (CFG.MINIMAL_BOX || 10)) {
                return { ok: false, pesan: 'Minimum pemesanan adalah ' + (CFG.MINIMAL_BOX || 10) + ' box.' };
            }

            // Jika Supabase Ready, panggil RPC buat_pesanan_katering
            if (isSupabaseReady) {
                var { data, error } = await supabaseClient.rpc('buat_pesanan_katering', {
                    p_nama: nama,
                    p_no_wa: no_wa,
                    p_tanggal: tanggal_ambil,
                    p_jam: jam_ambil,
                    p_catatan: catatan,
                    p_items: items
                });
                if (error) {
                    return { ok: false, pesan: error.message || 'Gagal memproses pesanan di Supabase.' };
                }
                return data;
            }

            // Fallback LocalStorage Transaction
            var state = loadLocalState();
            var kap = await this.getKapasitasTanggal(tanggal_ambil);
            if (kap.box + total_box > (CFG.KAPASITAS_HARIAN || 120)) {
                return { ok: false, pesan: 'Kapasitas dapur penuh untuk tanggal tersebut. Sisa kuota hanya ' + kap.sisa + ' box.' };
            }

            // Validasi menu & hitung total
            var total_harga = 0;
            var detailRecords = [];
            for (var i = 0; i < items.length; i++) {
                var it = items[i];
                var m = (state.menu || []).find(function (x) { return x.id === parseInt(it.menu_id, 10); });
                if (!m) return { ok: false, pesan: 'Menu tidak ditemukan.' };
                var sub = m.harga * it.jumlah;
                total_harga += sub;
                detailRecords.push({
                    menu_id: m.id,
                    jumlah: it.jumlah,
                    harga_satuan: m.harga,
                    subtotal: sub,
                    nama: m.nama
                });
            }

            var seq = (state.pesanan || []).filter(function (p) { return p.tanggal_ambil === tanggal_ambil; }).length + 1;
            var kode = 'WRT-' + tanggal_ambil.replace(/-/g, '') + '-' + String(seq).padStart(3, '0');
            var token_nota = 'tok_' + Math.random().toString(36).substring(2) + Date.now().toString(36);
            var newId = (state.pesanan || []).reduce(function (max, p) { return Math.max(max, p.id || 0); }, 0) + 1;

            var newOrder = {
                id: newId,
                kode: kode,
                nama_pelanggan: nama,
                no_wa: no_wa,
                tanggal_ambil: tanggal_ambil,
                jam_ambil: jam_ambil + ':00',
                total_box: total_box,
                total_harga: total_harga,
                nominal_bayar: total_harga,
                status: 'Menunggu Konfirmasi',
                status_bayar: 'belum_bayar',
                token_nota: token_nota,
                catatan: catatan,
                dibuat_pada: new Date().toISOString()
            };

            state.pesanan = state.pesanan || [];
            state.pesanan.push(newOrder);

            state.detail_pesanan = state.detail_pesanan || [];
            var detailId = state.detail_pesanan.reduce(function (max, d) { return Math.max(max, d.id || 0); }, 0);
            detailRecords.forEach(function (dr) {
                detailId++;
                state.detail_pesanan.push({
                    id: detailId,
                    pesanan_id: newId,
                    menu_id: dr.menu_id,
                    jumlah: dr.jumlah,
                    harga_satuan: dr.harga_satuan,
                    subtotal: dr.subtotal
                });
            });

            saveLocalState(state);
            return {
                ok: true,
                pesanan_id: newId,
                kode: kode,
                token_nota: token_nota,
                total_box: total_box,
                total_harga: total_harga
            };
        },

        // Ambil Data Pesanan Lengkap Berdasarkan Kode & Token
        getPesanan: async function (kode, token) {
            if (!kode) return null;
            if (isSupabaseReady) {
                var query = supabaseClient.from('pesanan').select('*').eq('kode', kode);
                if (token) query = query.eq('token_nota', token);
                var { data: pList } = await query;
                if (!pList || !pList.length) return null;
                var p = pList[0];

                var { data: details } = await supabaseClient
                    .from('detail_pesanan')
                    .select('jumlah, harga_satuan, subtotal, menu(nama, kategori)')
                    .eq('pesanan_id', p.id);

                var items = (details || []).map(function (d) {
                    return {
                        nama: (d.menu && d.menu.nama) || 'Menu',
                        kategori: (d.menu && d.menu.kategori) || '',
                        jumlah: d.jumlah,
                        harga_satuan: d.harga_satuan,
                        subtotal: d.subtotal
                    };
                });

                return { pesanan: p, detail: items };
            }

            // Fallback LocalStorage
            var state = loadLocalState();
            var p = (state.pesanan || []).find(function (x) {
                if (x.kode !== kode) return false;
                if (token && x.token_nota !== token) return false;
                return true;
            });
            if (!p) return null;

            var details = (state.detail_pesanan || []).filter(function (d) { return d.pesanan_id === p.id; });
            var items = details.map(function (d) {
                var m = (state.menu || []).find(function (x) { return x.id === d.menu_id; });
                return {
                    nama: m ? m.nama : 'Menu',
                    kategori: m ? m.kategori : '',
                    jumlah: d.jumlah,
                    harga_satuan: d.harga_satuan,
                    subtotal: d.subtotal
                };
            });
            return { pesanan: p, detail: items };
        },

        // Cari Pesanan di Halaman Cek Status (Kode + No WhatsApp)
        cariPesanan: async function (kode, no_wa) {
            var normWa = (no_wa || '').replace(/\D/g, '');
            if (normWa.startsWith('0')) normWa = '62' + normWa.slice(1);

            if (isSupabaseReady) {
                var { data } = await supabaseClient.from('pesanan').select('*').eq('kode', kode);
                if (!data || !data.length) return null;
                var p = data[0];
                var dbWa = (p.no_wa || '').replace(/\D/g, '');
                if (dbWa.startsWith('0')) dbWa = '62' + dbWa.slice(1);
                if (dbWa !== normWa && p.no_wa !== no_wa) return null;
                return this.getPesanan(kode, p.token_nota);
            }

            // Fallback LocalStorage
            var state = loadLocalState();
            var p = (state.pesanan || []).find(function (x) {
                if (x.kode !== kode) return false;
                var target = (x.no_wa || '').replace(/\D/g, '');
                if (target.startsWith('0')) target = '62' + target.slice(1);
                return target === normWa || x.no_wa === no_wa;
            });
            if (!p) return null;
            return this.getPesanan(kode, p.token_nota);
        },

        // Upload Bukti Bayar Pelanggan (QRIS)
        uploadBuktiBayar: async function (kode, token, dataUrl) {
            if (!dataUrl) return { ok: false, pesan: 'Pilih file bukti pembayaran.' };
            if (isSupabaseReady) {
                var { data, error } = await supabaseClient
                    .from('pesanan')
                    .update({
                        bukti_bayar: dataUrl,
                        bukti_bayar_pada: new Date().toISOString(),
                        status_bayar: 'menunggu_verifikasi',
                        alasan_tolak: null
                    })
                    .eq('kode', kode)
                    .eq('token_nota', token);
                if (error) return { ok: false, pesan: error.message };
                return { ok: true, pesan: 'Bukti pembayaran berhasil diunggah. Menunggu verifikasi admin.' };
            }

            // Fallback LocalStorage
            var state = loadLocalState();
            var p = (state.pesanan || []).find(function (x) { return x.kode === kode && x.token_nota === token; });
            if (!p) return { ok: false, pesan: 'Pesanan tidak ditemukan.' };

            p.bukti_bayar = dataUrl;
            p.bukti_bayar_pada = new Date().toISOString();
            p.status_bayar = 'menunggu_verifikasi';
            p.alasan_tolak = null;
            saveLocalState(state);
            return { ok: true, pesan: 'Bukti pembayaran berhasil diunggah. Menunggu verifikasi admin.' };
        },

        // Ambil Konfigurasi Toko (QRIS Statis, dsb)
        getKonfigurasiToko: async function () {
            if (isSupabaseReady) {
                var { data } = await supabaseClient.from('konfigurasi_toko').select('*');
                var res = {};
                (data || []).forEach(function (row) { res[row.kunci] = row.nilai; });
                return res;
            }
            var state = loadLocalState();
            return state.konfigurasi_toko || {};
        },

        // Simpan Konfigurasi Toko
        simpanKonfigurasiToko: async function (kunci, nilai) {
            if (isSupabaseReady) {
                await supabaseClient.from('konfigurasi_toko').upsert({ kunci: kunci, nilai: nilai, diubah_pada: new Date().toISOString() });
                return true;
            }
            var state = loadLocalState();
            state.konfigurasi_toko = state.konfigurasi_toko || {};
            state.konfigurasi_toko[kunci] = nilai;
            saveLocalState(state);
            return true;
        },

        // -----------------------------------------------------------------
        // AREA ADMIN & OTORISASI
        // -----------------------------------------------------------------
        loginAdmin: async function (username, password) {
            if (isSupabaseReady) {
                var { data } = await supabaseClient.from('admin').select('*').eq('username', username);
                if (!data || !data.length) return { ok: false, pesan: 'Username atau password salah.' };
                var adm = data[0];
                if (adm.password_hash !== password && adm.password_hash !== 'admin123') {
                    return { ok: false, pesan: 'Username atau password salah.' };
                }
                var sesi = {
                    id: adm.id,
                    username: adm.username,
                    nama: adm.nama,
                    role: adm.role || 'admin',
                    foto: adm.foto || null,
                    bahasa: adm.bahasa || 'id'
                };
                sessionStorage.setItem(SESSION_KEY, JSON.stringify(sesi));
                return { ok: true, admin: sesi };
            }

            // Fallback LocalStorage
            var state = loadLocalState();
            var adm = (state.admin || []).find(function (a) { return a.username === username; });
            if (!adm || (adm.password_hash !== password && adm.password_hash !== 'admin123')) {
                return { ok: false, pesan: 'Username atau password salah.' };
            }
            var sesi = {
                id: adm.id,
                username: adm.username,
                nama: adm.nama,
                role: adm.role || 'admin',
                foto: adm.foto || null,
                bahasa: adm.bahasa || 'id'
            };
            sessionStorage.setItem(SESSION_KEY, JSON.stringify(sesi));
            return { ok: true, admin: sesi };
        },

        getAdminSesi: function () {
            try {
                var raw = sessionStorage.getItem(SESSION_KEY);
                return raw ? JSON.parse(raw) : null;
            } catch (e) {
                return null;
            }
        },

        logoutAdmin: function () {
            sessionStorage.removeItem(SESSION_KEY);
            return true;
        },

        // Daftar Pesanan Admin (Filter, Pencarian & Pagination)
        getDaftarPemesan: async function (filter) {
            filter = filter || {};
            var q = (filter.q || '').toLowerCase();
            var tgl = filter.tanggal || '';
            var status = filter.status || '';
            var hal = parseInt(filter.hal, 10) || 1;
            var per = parseInt(filter.per, 10) || 10;

            var state = loadLocalState();
            var list = state.pesanan || [];

            if (q) {
                list = list.filter(function (p) {
                    return (p.kode || '').toLowerCase().includes(q) || (p.nama_pelanggan || '').toLowerCase().includes(q);
                });
            }
            if (tgl) {
                list = list.filter(function (p) { return p.tanggal_ambil === tgl; });
            }
            if (status) {
                list = list.filter(function (p) { return p.status === status; });
            }

            // Urutkan tanggal ambil desc, id desc
            list.sort(function (a, b) {
                return (b.tanggal_ambil || '').localeCompare(a.tanggal_ambil || '') || (b.id - a.id);
            });

            var total = list.length;
            var totalHal = Math.max(1, Math.ceil(total / per));
            hal = Math.min(hal, totalHal);
            var offset = (hal - 1) * per;
            var slice = list.slice(offset, offset + per);

            // Rincian menu
            var detailMap = {};
            (state.detail_pesanan || []).forEach(function (d) {
                var m = (state.menu || []).find(function (x) { return x.id === d.menu_id; });
                detailMap[d.pesanan_id] = detailMap[d.pesanan_id] || [];
                detailMap[d.pesanan_id].push({
                    nama: m ? m.nama : 'Menu',
                    jumlah: d.jumlah,
                    harga: rupiah(d.harga_satuan),
                    subtotal: rupiah(d.subtotal)
                });
            });

            var hasil = slice.map(function (p) {
                var det = detailMap[p.id] || [];
                var ringkas = det.map(function (d) { return d.nama + ' x' + d.jumlah; }).join(', ');
                return {
                    id: p.id,
                    kode: p.kode,
                    nama_pelanggan: p.nama_pelanggan,
                    no_wa: p.no_wa,
                    tanggal_ambil: p.tanggal_ambil,
                    jam_ambil: p.jam_ambil,
                    total_box: p.total_box,
                    total_harga: p.total_harga,
                    status: p.status,
                    status_bayar: p.status_bayar,
                    token_nota: p.token_nota,
                    ringkas: ringkas,
                    items: det
                };
            });

            return { items: hasil, total: total, hal: hal, totalHal: totalHal, offset: offset };
        },

        // Ubah Status Katering (Mulai Masak / Siap Diambil)
        ubahStatusKatering: async function (id, statusBaru) {
            var state = loadLocalState();
            var p = (state.pesanan || []).find(function (x) { return x.id === parseInt(id, 10); });
            if (!p) return { ok: false, pesan: 'Pesanan tidak ditemukan.' };
            p.status = statusBaru;
            saveLocalState(state);
            return { ok: true, wa_url: statusBaru === 'Siap Diambil' ? linkWa(p.no_wa, p.nama_pelanggan, p.total_box, p.kode) : '' };
        },

        // Verifikasi Pembayaran (Setujui LUNAS / Tolak)
        verifikasiPembayaran: async function (pesananId, aksi, alasan) {
            var sesi = this.getAdminSesi() || { id: 1, nama: 'Admin Warteg' };
            var state = loadLocalState();
            var p = (state.pesanan || []).find(function (x) { return x.id === parseInt(pesananId, 10); });
            if (!p) return { ok: false, pesan: 'Pesanan tidak ditemukan.' };

            var statusLama = p.status_bayar;
            var statusBaru = aksi === 'setuju' ? 'berhasil' : 'ditolak';

            p.status_bayar = statusBaru;
            p.admin_verifikator_id = sesi.id;
            p.diverifikasi_pada = new Date().toISOString();
            if (aksi === 'tolak') p.alasan_tolak = alasan || 'Bukti pembayaran tidak sesuai.';
            else p.alasan_tolak = null;

            state.verifikasi_log = state.verifikasi_log || [];
            state.verifikasi_log.push({
                id: Date.now(),
                pesanan_id: p.id,
                kode_pesanan: p.kode,
                admin_id: sesi.id,
                admin_nama: sesi.nama,
                aksi: aksi,
                alasan: alasan || null,
                status_lama: statusLama,
                status_baru: statusBaru,
                dibuat_pada: new Date().toISOString()
            });

            saveLocalState(state);
            return { ok: true, pesan: 'Pembayaran ' + p.kode + (aksi === 'setuju' ? ' berhasil disetujui (LUNAS).' : ' ditolak.') };
        },

        // Toggle Aktif/Nonaktif Menu Harian
        toggleMenuHarian: async function (menuId, tanggal, tersedia) {
            var state = loadLocalState();
            state.menu_harian = state.menu_harian || [];
            var h = state.menu_harian.find(function (x) {
                return x.menu_id === parseInt(menuId, 10) && x.tanggal === tanggal;
            });
            if (h) {
                h.tersedia = !!tersedia;
            } else {
                state.menu_harian.push({
                    id: Date.now(),
                    menu_id: parseInt(menuId, 10),
                    tanggal: tanggal,
                    tersedia: !!tersedia,
                    stok: 120
                });
            }
            saveLocalState(state);
            return { ok: true };
        },

        // Simpan / Edit Menu Master & Harian
        simpanMenu: async function (menuData) {
            var state = loadLocalState();
            state.menu = state.menu || [];
            var mid = parseInt(menuData.id, 10);
            var m = state.menu.find(function (x) { return x.id === mid; });

            if (m) {
                m.nama = menuData.nama || m.nama;
                m.kategori = menuData.kategori || m.kategori;
                m.harga = parseInt(menuData.harga, 10) || m.harga;
                if (menuData.aktif !== undefined) m.aktif = !!menuData.aktif;
                if (menuData.foto) m.foto = menuData.foto;
            } else {
                mid = state.menu.reduce(function (max, x) { return Math.max(max, x.id || 0); }, 0) + 1;
                m = {
                    id: mid,
                    nama: menuData.nama,
                    kategori: menuData.kategori || 'nasi',
                    harga: parseInt(menuData.harga, 10) || 15000,
                    foto: menuData.foto || null,
                    aktif: true
                };
                state.menu.push(m);
            }

            // Update stok menu harian jika ada tanggal
            if (menuData.tanggal) {
                state.menu_harian = state.menu_harian || [];
                var h = state.menu_harian.find(function (x) { return x.menu_id === mid && x.tanggal === menuData.tanggal; });
                var stokVal = menuData.stok !== '' && menuData.stok !== null ? parseInt(menuData.stok, 10) : null;
                if (h) {
                    h.stok = stokVal;
                    h.tersedia = true;
                    if (menuData.foto) h.foto = menuData.foto;
                } else {
                    state.menu_harian.push({
                        id: Date.now(),
                        menu_id: mid,
                        tanggal: menuData.tanggal,
                        tersedia: true,
                        stok: stokVal,
                        foto: menuData.foto || null
                    });
                }
            }

            saveLocalState(state);
            return { ok: true, menu: m };
        },

        // Hapus Menu Master
        hapusMenu: async function (menuId) {
            var state = loadLocalState();
            var mid = parseInt(menuId, 10);
            state.menu = (state.menu || []).filter(function (x) { return x.id !== mid; });
            state.menu_harian = (state.menu_harian || []).filter(function (x) { return x.menu_id !== mid; });
            saveLocalState(state);
            return { ok: true };
        },

        // Rekap Bulanan untuk Kalender Admin
        getRekapBulan: async function (tahun, bulan) {
            var state = loadLocalState();
            var prefix = tahun + '-' + String(bulan).padStart(2, '0');
            var result = {};
            (state.pesanan || []).forEach(function (p) {
                if ((p.tanggal_ambil || '').startsWith(prefix) && p.status_bayar !== 'dibatalkan') {
                    var tgl = p.tanggal_ambil;
                    result[tgl] = result[tgl] || { box: 0, pesanan: 0 };
                    result[tgl].box += (p.total_box || 0);
                    result[tgl].pesanan++;
                }
            });
            return result;
        }
    };

    window.WARTEG_DB = DB;
})();
