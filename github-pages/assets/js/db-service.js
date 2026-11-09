// =====================================================================
// LAYANAN DATA HYBRID: SUPABASE CLOUD + AUTO-FALLBACK LOCALSTORAGE
// Sistem Katering & POS Warteg Pak Sanam (GitHub Pages Edition)
// =====================================================================
(function () {
    'use strict';

    var CFG = window.WARTEG_CONFIG || {};
    var STORAGE_KEY = 'warteg_katering_state_v3';
    var SESSION_KEY = 'warteg_admin_session_v1';
    var CART_META_KEY = 'warteg_cart_meta_v1';
    var LOCKOUT_KEY = 'warteg_admin_lockout_v1';
    var FAILED_ATTEMPTS_KEY = 'warteg_admin_failed_attempts_v1';
    var MAX_FAILED_ATTEMPTS = 5;
    var LOCKOUT_DURATION_MS = 15 * 60 * 1000; // 15 menit
    var SESSION_DURATION_MS = 8 * 60 * 60 * 1000; // 8 jam batas aktif sesi admin

    // Inisialisasi Supabase Client jika URL & Key tersedia
    var sb = null;
    var isSupabaseReady = false;
    if (
        CFG.SUPABASE_URL &&
        CFG.SUPABASE_ANON_KEY &&
        CFG.SUPABASE_URL.indexOf('https://') === 0 &&
        typeof window.supabase !== 'undefined'
    ) {
        try {
            sb = window.supabase.createClient(CFG.SUPABASE_URL, CFG.SUPABASE_ANON_KEY);
            isSupabaseReady = true;
        } catch (err) {
            console.warn('Gagal menginisialisasi Supabase, beralih ke LocalStorage:', err);
        }
    }

    // =================================================================
    // HELPER TANGGAL, FORMATTER, ESCAPE HTML & SANITASI
    // =================================================================
    var HARI_ID = ['Minggu', 'Senin', 'Selasa', 'Rabu', 'Kamis', 'Jumat', 'Sabtu'];
    var BULAN_ID = [
        '', 'Januari', 'Februari', 'Maret', 'April', 'Mei', 'Juni',
        'Juli', 'Agustus', 'September', 'Oktober', 'November', 'Desember'
    ];

    function escapeHtml(str) {
        if (str === null || str === undefined) return '';
        return String(str)
            .replace(/&/g, '&amp;')
            .replace(/</g, '&lt;')
            .replace(/>/g, '&gt;')
            .replace(/"/g, '&quot;')
            .replace(/'/g, '&#39;');
    }

    function normalisasiWa(wa) {
        var digits = String(wa || '').replace(/\D/g, '');
        if (!digits) return '';
        if (digits.indexOf('0') === 0) return '62' + digits.slice(1);
        if (digits.indexOf('8') === 0) return '62' + digits;
        return digits;
    }

    function isKategoriBoxUtama(kategori) {
        var k = String(kategori || '').toLowerCase().trim();
        return k === 'paket a' || k === 'paket b' || k === 'paket c' || k === 'nasi' || k.indexOf('paket') === 0;
    }

    function unitMenu(kategori) {
        return isKategoriBoxUtama(kategori) ? 'box' : 'porsi';
    }

    function urlNota(kode, token, prefix) {
        var pre = prefix || '';
        return pre + 'nota.html?kode=' + encodeURIComponent(kode || '') + '&t=' + encodeURIComponent(token || '');
    }

    function urlPembayaran(kode, token, prefix) {
        var pre = prefix || '';
        return pre + 'pembayaran.html?kode=' + encodeURIComponent(kode || '') + '&t=' + encodeURIComponent(token || '');
    }

    function urlInvoice(kode, token, prefix) {
        var pre = prefix || '';
        return pre + 'invoice.html?kode=' + encodeURIComponent(kode || '') + '&t=' + encodeURIComponent(token || '');
    }

    function formatIsoDate(dateObj) {
        var y = dateObj.getFullYear();
        var m = String(dateObj.getMonth() + 1).padStart(2, '0');
        var d = String(dateObj.getDate()).padStart(2, '0');
        return y + '-' + m + '-' + d;
    }

    function addDaysIso(offsetDays) {
        var d = new Date();
        d.setHours(0, 0, 0, 0);
        d.setDate(d.getDate() + offsetDays);
        return formatIsoDate(d);
    }

    function rupiah(angka) {
        var n = Math.round(Number(angka) || 0);
        return 'Rp ' + n.toString().replace(/\B(?=(\d{3})+(?!\d))/g, '.');
    }

    function tanggalId(tglStr, denganHari) {
        if (denganHari === undefined) denganHari = true;
        if (!tglStr) return '-';
        var parts = String(tglStr).slice(0, 10).split('-');
        if (parts.length < 3) return escapeHtml(tglStr);
        var dt = new Date(Number(parts[0]), Number(parts[1]) - 1, Number(parts[2]));
        if (isNaN(dt.getTime())) return escapeHtml(tglStr);
        var teks = dt.getDate() + ' ' + BULAN_ID[dt.getMonth() + 1] + ' ' + dt.getFullYear();
        return denganHari ? (HARI_ID[dt.getDay()] + ', ' + teks) : teks;
    }

    function waktuId(waktuStr) {
        if (!waktuStr) return '-';
        var dt = new Date(waktuStr);
        if (isNaN(dt.getTime())) return escapeHtml(waktuStr);
        var tgl = dt.getDate() + ' ' + BULAN_ID[dt.getMonth() + 1] + ' ' + dt.getFullYear();
        var jam = String(dt.getHours()).padStart(2, '0') + ':' + String(dt.getMinutes()).padStart(2, '0');
        return tgl + ', ' + jam + ' WIB';
    }

    function jamPendek(jamStr) {
        if (!jamStr) return '-';
        return String(jamStr).slice(0, 5);
    }

    function statusBayarLabel(sb) {
        var map = {
            'belum_bayar': 'Belum Dibayar',
            'menunggu': 'Belum Dibayar',
            'menunggu_verifikasi': 'Menunggu Verifikasi Admin',
            'berhasil': 'Lunas (Terverifikasi)',
            'lunas': 'Lunas (Terverifikasi)',
            'ditolak': 'Bukti Ditolak',
            'gagal': 'Gagal',
            'kedaluwarsa': 'Kedaluwarsa',
            'dibatalkan': 'Dibatalkan'
        };
        return map[sb] || 'Belum Dibayar';
    }

    function kelasStatusBayar(sb) {
        var map = {
            'belum_bayar': 'badge-kuning',
            'menunggu': 'badge-kuning',
            'menunggu_verifikasi': 'badge-biru',
            'berhasil': 'badge-hijau',
            'lunas': 'badge-hijau',
            'ditolak': 'badge-merah',
            'gagal': 'badge-merah',
            'kedaluwarsa': 'badge-abu',
            'dibatalkan': 'badge-abu'
        };
        return map[sb] || 'badge-abu';
    }

    function badgeStatus(status) {
        var map = {
            'Menunggu Pembayaran': 'badge-menunggu',
            'Menunggu Verifikasi': 'badge-menunggu',
            'Menunggu Konfirmasi': 'badge-menunggu',
            'Dikonfirmasi': 'badge-dimasak',
            'Sedang Dimasak': 'badge-dimasak',
            'Siap Diambil': 'badge-siap',
            'Selesai': 'badge-hijau',
            'Dibatalkan': 'badge-merah'
        };
        var kls = map[status] || 'badge-menunggu';
        return '<span class="badge ' + kls + '">' + escapeHtml(status || 'Menunggu Konfirmasi') + '</span>';
    }

    function hitungDiskon(totalBox) {
        var box = Number(totalBox) || 0;
        var kelipatan = Math.floor(box / (CFG.DISKON_PER_BOX || 50));
        return kelipatan * (CFG.NOMINAL_DISKON || 50000);
    }

    function validasiTanggalAmbil(tglStr) {
        if (!tglStr || !/^\d{4}-\d{2}-\d{2}$/.test(tglStr)) {
            return { ok: false, pesan: 'Format tanggal pengambilan tidak valid.' };
        }
        var tglMin = addDaysIso(CFG.MINIMAL_H_MINUS || 1);
        var tglMaks = addDaysIso(CFG.MAKS_HARI_KE || 30);
        if (tglStr < tglMin) {
            return { ok: false, pesan: 'Tanggal pengambilan minimal H-1 (' + tanggalId(tglMin, false) + ').' };
        }
        if (tglStr > tglMaks) {
            return { ok: false, pesan: 'Tanggal pengambilan maksimal 30 hari ke depan (' + tanggalId(tglMaks, false) + ').' };
        }
        return { ok: true, tglMin: tglMin, tglMaks: tglMaks };
    }

    function validasiJamAmbil(jamStr) {
        var jam = jamPendek(jamStr);
        if (!/^\d{2}:\d{2}$/.test(jam)) {
            return { ok: false, pesan: 'Format jam pengambilan tidak valid.' };
        }
        var buka = CFG.JAM_BUKA || '09:00';
        var tutup = CFG.JAM_TUTUP || '19:00';
        if (jam < buka || jam > tutup) {
            return { ok: false, pesan: 'Jam pengambilan harus berada di antara jam operasional ' + buka.replace(':', '.') + ' - ' + tutup.replace(':', '.') + ' WIB.' };
        }
        return { ok: true, jam: jam };
    }

    // =================================================================
    // DATA SEED FALLBACK (LOCALSTORAGE)
    // =================================================================
    function buatSeedData() {
        var besok = addDaysIso(1);
        var lusa = addDaysIso(2);
        return {
            konfigurasi: {
                qris_gambar: 'qris_warteg.svg',
                qris_nama_merchant: 'Warteg Pak Sanam',
                qris_nmid: 'ID1024000000001',
                qris_instruksi: 'Scan kode QRIS di atas dengan aplikasi pembayaran apa pun (BCA mobile, Livin, BRImo, GoPay, OVO, DANA, ShopeePay), masukkan nominal sesuai total tagihan, lalu unggah tangkapan layar bukti pembayaran di halaman ini.',
                batas_bayar_jam: '24'
            },
            admin: [
                {
                    id: 1,
                    username: 'admin',
                    password_hash: 'admin123',
                    nama: 'Pengelola Warteg Pak Sanam',
                    role: 'owner',
                    email: 'owner@wartegpaksanam.id',
                    no_hp: '0857-8342-3626',
                    jabatan: 'Pemilik & Pengelola Utama',
                    foto: 'admin_1_1776007918.jpeg',
                    terakhir_login: new Date().toISOString(),
                    dibuat_pada: '2026-04-01T08:00:00.000Z'
                }
            ],
            menu: [
                { id: 1, nama: 'Paket Ayam Goreng Sambal', deskripsi: 'Nasi Putih + Ayam Goreng Sambal + Tempe & Tahu + Lalapan Segar', harga: 23000, kategori: 'paket a', foto: 'ayam_goreng_sambal.jpg', stok_default: 50, aktif: true },
                { id: 2, nama: 'Paket Ayam Bakar Sambal', deskripsi: 'Nasi Putih + Ayam Bakar Madu Sambal + Tempe & Tahu + Lalapan Segar', harga: 23000, kategori: 'paket b', foto: 'ayam_bakar_sambal.jpg', stok_default: 50, aktif: true },
                { id: 3, nama: 'Paket Bebek Goreng Sambal', deskripsi: 'Nasi Putih + Bebek Goreng Krispi Sambal + Tempe & Tahu + Lalapan Segar', harga: 30000, kategori: 'paket c', foto: 'bebek_goreng_sambal.jpg', stok_default: 40, aktif: true },
                { id: 4, nama: 'Ayam Goreng (Lauk Saja)', deskripsi: 'Ayam goreng gurih renyah bumbu kuning meresap dengan lalapan timun', harga: 18000, kategori: 'lauk', foto: 'ayam_goreng.jpg', stok_default: 60, aktif: true },
                { id: 5, nama: 'Ayam Bakar (Lauk Saja)', deskripsi: 'Ayam bakar madu manis gurih aroma bakaran dengan lalapan timun', harga: 18000, kategori: 'lauk', foto: 'ayam_bakar.jpg', stok_default: 60, aktif: true },
                { id: 6, nama: 'Tempe & Tahu Goreng', deskripsi: 'Potongan tahu dan tempe goreng renyah bumbu ketumbar khas rumahan', harga: 2000, kategori: 'lauk', foto: 'tahu_tempe.jpg', stok_default: 100, aktif: true },
                { id: 7, nama: 'Kol Goreng Crispy', deskripsi: 'Kol goreng manis gurih renyah pelengkap nikmat ayam dan bebek', harga: 5000, kategori: 'sayur', foto: 'kol_goreng.jpg', stok_default: 80, aktif: true },
                { id: 8, nama: 'Es Teh / Teh Hangat', deskripsi: 'Teh seduh manis harum melati segar dingin atau hangat', harga: 4000, kategori: 'sayur', foto: 'es_teh.jpg', stok_default: 120, aktif: true },
                { id: 9, nama: 'Es Jeruk / Jeruk Hangat', deskripsi: 'Jeruk peras murni segar kaya vitamin C dingin atau hangat', harga: 5000, kategori: 'sayur', foto: 'es_jeruk.jpg', stok_default: 100, aktif: true },
                { id: 10, nama: 'Es Teler Premium', deskripsi: 'Es teler manis segar isi alpukat, kelapa muda, nangka, jeli & susu kental manis', harga: 10000, kategori: 'sayur', foto: 'es_teler.jpg', stok_default: 50, aktif: true },
                { id: 11, nama: 'Nasi Putih Pulen', deskripsi: 'Nasi putih hangat porsi katering box sekat', harga: 6000, kategori: 'nasi', foto: 'ayam_goreng_sambal.jpg', stok_default: 120, aktif: true },
                { id: 12, nama: 'Nasi Uduk Gurih', deskripsi: 'Nasi uduk santan rempah daun salam dengan taburan bawang goreng', harga: 8000, kategori: 'nasi', foto: 'ayam_bakar_sambal.jpg', stok_default: 80, aktif: true }
            ],
            menu_harian: {},
            pesanan: [
                {
                    id: 1,
                    kode: 'WRT-' + besok.replace(/-/g, '') + '-101',
                    nama_pelanggan: 'Budi Santoso (PT Maju Bersama)',
                    no_wa: '081234567890',
                    tanggal_ambil: besok,
                    jam_ambil: '11:30',
                    catatan: 'Sambal dipisah untuk 5 box, sisanya dicampur.',
                    total_box: 25,
                    total_harga: 625000,
                    diskon: 0,
                    nominal_bayar: 625000,
                    status: 'Sedang Dimasak',
                    metode_bayar: 'qris_statis',
                    status_bayar: 'berhasil',
                    bukti_bayar: '../uploads/bukti/44bb88b14db7c5b93805afa4a0d56da7.jpg',
                    bukti_bayar_pada: new Date(Date.now() - 3600000 * 5).toISOString(),
                    diverifikasi_oleh: 1,
                    diverifikasi_pada: new Date(Date.now() - 3600000 * 4).toISOString(),
                    dibayar_pada: new Date(Date.now() - 3600000 * 4).toISOString(),
                    alasan_tolak: null,
                    token_nota: 'demo-token-101',
                    dibuat_pada: new Date(Date.now() - 3600000 * 6).toISOString()
                },
                {
                    id: 2,
                    kode: 'WRT-' + lusa.replace(/-/g, '') + '-202',
                    nama_pelanggan: 'Siti Rahmawati (Pengajian Al-Ikhlas)',
                    no_wa: '081398765432',
                    tanggal_ambil: lusa,
                    jam_ambil: '15:00',
                    catatan: 'Mohon tepat waktu jam 3 sore.',
                    total_box: 30,
                    total_harga: 720000,
                    diskon: 0,
                    nominal_bayar: 720000,
                    status: 'Menunggu Konfirmasi',
                    metode_bayar: 'qris_statis',
                    status_bayar: 'menunggu_verifikasi',
                    bukti_bayar: '../uploads/bukti/a3b8be91a65cbbe2b24e3528091ce25e.jpg',
                    bukti_bayar_pada: new Date(Date.now() - 1800000).toISOString(),
                    diverifikasi_oleh: null,
                    diverifikasi_pada: null,
                    dibayar_pada: null,
                    alasan_tolak: null,
                    token_nota: 'demo-token-202',
                    dibuat_pada: new Date(Date.now() - 3600000).toISOString()
                }
            ],
            detail_pesanan: [
                { id: 1, pesanan_id: 1, menu_id: 1, nama_menu: 'Paket A - Ayam Goreng Lengkuas', unit: 'box', jumlah: 25, harga_satuan: 25000, subtotal: 625000 },
                { id: 2, pesanan_id: 2, menu_id: 3, nama_menu: 'Paket C - Ikan Tongkol Balado', unit: 'box', jumlah: 30, harga_satuan: 24000, subtotal: 720000 }
            ],
            verifikasi_log: [
                { id: 1, pesanan_id: 1, admin_id: 1, aksi: 'unggah_bukti', catatan: 'Pelanggan mengunggah bukti bayar.', dibuat_pada: new Date(Date.now() - 3600000 * 5).toISOString() },
                { id: 2, pesanan_id: 1, admin_id: 1, aksi: 'terima', catatan: 'Pembayaran QRIS terverifikasi lunas.', dibuat_pada: new Date(Date.now() - 3600000 * 4).toISOString() },
                { id: 3, pesanan_id: 2, admin_id: null, aksi: 'unggah_bukti', catatan: 'Pelanggan mengunggah bukti bayar.', dibuat_pada: new Date(Date.now() - 1800000).toISOString() }
            ],
            aktivitas: [
                { id: 1, admin_id: 1, aksi: 'verifikasi_terima', keterangan: 'Menerima pembayaran pesanan WRT-' + besok.replace(/-/g, '') + '-101', dibuat_pada: new Date(Date.now() - 3600000 * 4).toISOString() },
                { id: 2, admin_id: 1, aksi: 'ubah_status', keterangan: 'Mengubah status WRT-' + besok.replace(/-/g, '') + '-101 menjadi Sedang Dimasak', dibuat_pada: new Date(Date.now() - 3600000 * 3).toISOString() }
            ]
        };
    }

    function getLocalState() {
        try {
            var raw = localStorage.getItem(STORAGE_KEY);
            if (!raw) {
                var seed = buatSeedData();
                localStorage.setItem(STORAGE_KEY, JSON.stringify(seed));
                return seed;
            }
            return JSON.parse(raw);
        } catch (e) {
            return buatSeedData();
        }
    }

    function saveLocalState(state) {
        try {
            localStorage.setItem(STORAGE_KEY, JSON.stringify(state));
        } catch (e) {
            console.error('Gagal menyimpan ke LocalStorage:', e);
        }
    }

    function sanitasiObjekAdmin(adm) {
        if (!adm) return null;
        return {
            id: adm.id,
            username: adm.username,
            nama: adm.nama,
            role: adm.role || 'admin',
            email: adm.email || '',
            no_hp: adm.no_hp || '',
            jabatan: adm.jabatan || '',
            foto: adm.foto || '',
            terakhir_login: adm.terakhir_login || null,
            dibuat_pada: adm.dibuat_pada || null
        };
    }

    // =================================================================
    // API PUBLIC SERVICE (DIPANGGIL OLEH CUSTOMER & ADMIN)
    // =================================================================
    var DB = {
        isSupabase: function () {
            return isSupabaseReady;
        },

        // Helper Fungsi Format & Sanitasi
        escapeHtml: escapeHtml,
        normalisasiWa: normalisasiWa,
        isKategoriBoxUtama: isKategoriBoxUtama,
        unitMenu: unitMenu,
        urlNota: urlNota,
        urlPembayaran: urlPembayaran,
        urlInvoice: urlInvoice,
        rupiah: rupiah,
        tanggalId: tanggalId,
        waktuId: waktuId,
        jamPendek: jamPendek,
        statusBayarLabel: statusBayarLabel,
        kelasStatusBayar: kelasStatusBayar,
        badgeStatus: badgeStatus,
        addDaysIso: addDaysIso,
        hitungDiskon: hitungDiskon,
        validasiTanggalAmbil: validasiTanggalAmbil,
        validasiJamAmbil: validasiJamAmbil,

        // Persistensi Meta Keranjang (Tanggal & Jam Ambil)
        getCartMeta: function () {
            try {
                var raw = localStorage.getItem(CART_META_KEY);
                var obj = raw ? JSON.parse(raw) : {};
                var tglMin = addDaysIso(CFG.MINIMAL_H_MINUS || 1);
                var tglMaks = addDaysIso(CFG.MAKS_HARI_KE || 30);
                var tgl = obj.tanggal || tglMin;
                if (tgl < tglMin || tgl > tglMaks) tgl = tglMin;
                var jam = obj.jam || '11:00';
                return { tanggal: tgl, jam: jam };
            } catch (e) {
                return { tanggal: addDaysIso(CFG.MINIMAL_H_MINUS || 1), jam: '11:00' };
            }
        },

        setCartMeta: function (meta) {
            try {
                var cur = this.getCartMeta();
                var next = {
                    tanggal: (meta && meta.tanggal) ? meta.tanggal : cur.tanggal,
                    jam: (meta && meta.jam) ? meta.jam : cur.jam
                };
                localStorage.setItem(CART_META_KEY, JSON.stringify(next));
                return next;
            } catch (e) {
                return meta;
            }
        },

        // -------------------------------------------------------------
        // 1. KONFIGURASI TOKO & QRIS
        // -------------------------------------------------------------
        getKonfigurasi: async function () {
            var defaults = buatSeedData().konfigurasi;
            if (isSupabaseReady) {
                try {
                    var res = await sb.from('konfigurasi_toko').select('kunci, nilai');
                    if (!res.error && res.data && res.data.length > 0) {
                        var cfg = Object.assign({}, defaults);
                        res.data.forEach(function (r) { cfg[r.kunci] = r.nilai; });
                        if (cfg.qris_nama_merchant === 'Warung Mas Alif' || cfg.qris_nama_merchant === 'Warteg Kharisma') {
                            cfg.qris_nama_merchant = CFG.NAMA_WARTEG || 'Warteg Pak Sanam';
                        }
                        return cfg;
                    }
                } catch (e) {
                    console.warn('Fallback getKonfigurasi:', e);
                }
            }
            var st = getLocalState();
            var localCfg = Object.assign({}, defaults, st.konfigurasi || {});
            if (localCfg.qris_nama_merchant === 'Warung Mas Alif' || localCfg.qris_nama_merchant === 'Warteg Kharisma') {
                localCfg.qris_nama_merchant = CFG.NAMA_WARTEG || 'Warteg Pak Sanam';
            }
            return localCfg;
        },

        simpanKonfigurasi: async function (objBaru) {
            if (isSupabaseReady) {
                try {
                    var rows = Object.keys(objBaru).map(function (k) {
                        return { kunci: k, nilai: String(objBaru[k] ?? ''), diperbarui_pada: new Date().toISOString() };
                    });
                    var res = await sb.from('konfigurasi_toko').upsert(rows);
                    if (res.error) return { ok: false, pesan: res.error.message || 'Gagal menyimpan konfigurasi ke Supabase.' };
                } catch (e) {
                    return { ok: false, pesan: e.message || 'Gagal menghubungi server database.' };
                }
            }
            var state = getLocalState();
            state.konfigurasi = Object.assign({}, state.konfigurasi || {}, objBaru);
            saveLocalState(state);
            await this.catatAktivitas('update_qris', 'Memperbarui konfigurasi QRIS & toko');
            return { ok: true };
        },

        getKonfigurasiToko: async function () {
            return await this.getKonfigurasi();
        },

        simpanKonfigurasiToko: async function (kunciAtauObj, nilai) {
            if (typeof kunciAtauObj === 'string') {
                var o = {};
                o[kunciAtauObj] = nilai;
                return await this.simpanKonfigurasi(o);
            }
            return await this.simpanKonfigurasi(kunciAtauObj || {});
        },

        // -------------------------------------------------------------
        // 2. MENU & KETERSEDIAAN TANGGAL
        // -------------------------------------------------------------
        getMenuTanggal: async function (tanggal) {
            var tgl = tanggal || addDaysIso(1);
            if (isSupabaseReady) {
                try {
                    var mRes = await sb.from('menu').select('*').eq('aktif', true).order('kategori').order('nama');
                    if (!mRes.error && mRes.data && mRes.data.length > 0) {
                        var mhRes = await sb.from('menu_harian').select('*').eq('tanggal', tgl);
                        var mhMap = {};
                        (mhRes.data || []).forEach(function (x) { mhMap[x.menu_id] = x; });

                        var pRes = await sb
                            .from('pesanan')
                            .select('id, status, status_bayar, detail_pesanan(menu_id, jumlah)')
                            .eq('tanggal_ambil', tgl)
                            .neq('status', 'Dibatalkan')
                            .not('status_bayar', 'in', '("gagal","kedaluwarsa","dibatalkan")');

                        var terjualMap = {};
                        (pRes.data || []).forEach(function (p) {
                            (p.detail_pesanan || []).forEach(function (dp) {
                                terjualMap[dp.menu_id] = (terjualMap[dp.menu_id] || 0) + Number(dp.jumlah || 0);
                            });
                        });

                        return mRes.data.map(function (m) {
                            var mh = mhMap[m.id];
                            var tersedia = mh ? Boolean(mh.tersedia) : true;
                            var stok = (mh && mh.stok !== null && mh.stok !== undefined) ? Number(mh.stok) : (m.stok_default !== null ? Number(m.stok_default) : null);
                            var terjual = terjualMap[m.id] || 0;
                            return {
                                id: m.id,
                                nama: m.nama,
                                deskripsi: m.deskripsi || '',
                                harga: Number(m.harga),
                                kategori: m.kategori,
                                unit: unitMenu(m.kategori),
                                is_box: isKategoriBoxUtama(m.kategori),
                                foto: m.foto,
                                stok_default: m.stok_default,
                                aktif: m.aktif,
                                tersedia: tersedia,
                                stok: stok,
                                terjual: terjual,
                                jumlah: terjual
                            };
                        });
                    }
                } catch (e) {
                    console.warn('Fallback getMenuTanggal:', e);
                }
            }

            // Fallback LocalStorage
            var state = getLocalState();
            var mhTanggal = (state.menu_harian && state.menu_harian[tgl]) || {};
            var pesananAktifIds = (state.pesanan || [])
                .filter(function (p) {
                    return p.tanggal_ambil === tgl &&
                        p.status !== 'Dibatalkan' &&
                        ['gagal', 'kedaluwarsa', 'dibatalkan'].indexOf(p.status_bayar) === -1;
                })
                .map(function (p) { return p.id; });

            var terjualLocal = {};
            (state.detail_pesanan || []).forEach(function (dp) {
                if (pesananAktifIds.indexOf(dp.pesanan_id) >= 0) {
                    terjualLocal[dp.menu_id] = (terjualLocal[dp.menu_id] || 0) + Number(dp.jumlah || 0);
                }
            });

            return (state.menu || []).filter(function (m) { return m.aktif; }).map(function (m) {
                var mh = mhTanggal[m.id];
                var tersedia = mh && mh.tersedia !== undefined ? Boolean(mh.tersedia) : true;
                var stok = (mh && mh.stok !== undefined && mh.stok !== null) ? Number(mh.stok) : (m.stok_default !== null ? Number(m.stok_default) : null);
                var terjual = terjualLocal[m.id] || 0;
                return Object.assign({}, m, {
                    deskripsi: m.deskripsi || '',
                    unit: unitMenu(m.kategori),
                    is_box: isKategoriBoxUtama(m.kategori),
                    tersedia: tersedia,
                    stok: stok,
                    terjual: terjual,
                    jumlah: terjual
                });
            });
        },

        // -------------------------------------------------------------
        // 3. KAPASITAS KUOTA HARIAN (MAKS 120 BOX)
        // -------------------------------------------------------------
        getKapasitasTanggal: async function (tanggal) {
            var tgl = tanggal || addDaysIso(1);
            var kap = CFG.KAPASITAS_HARIAN || 120;
            var terpakai = 0;
            var jmlPesanan = 0;

            if (isSupabaseReady) {
                try {
                    var res = await sb
                        .from('pesanan')
                        .select('total_box, status, status_bayar')
                        .eq('tanggal_ambil', tgl)
                        .neq('status', 'Dibatalkan')
                        .not('status_bayar', 'in', '("gagal","kedaluwarsa","dibatalkan")');

                    if (!res.error && res.data) {
                        jmlPesanan = res.data.length;
                        res.data.forEach(function (r) { terpakai += Number(r.total_box || 0); });
                        var sisa = Math.max(0, kap - terpakai);
                        var persen = Math.min(100, Math.round((terpakai / kap) * 100));
                        var level = sisa === 0 ? 'penuh' : (persen >= 75 ? 'hampir' : 'aman');
                        return {
                            tanggal: tgl,
                            tanggal_id: tanggalId(tgl),
                            terpakai: terpakai,
                            box: terpakai,
                            pesanan: jmlPesanan,
                            sisa: sisa,
                            kapasitas: kap,
                            persen: persen,
                            level: level
                        };
                    }
                } catch (e) {
                    console.warn('Fallback getKapasitasTanggal:', e);
                }
            }

            var state = getLocalState();
            (state.pesanan || []).forEach(function (p) {
                if (
                    p.tanggal_ambil === tgl &&
                    p.status !== 'Dibatalkan' &&
                    ['gagal', 'kedaluwarsa', 'dibatalkan'].indexOf(p.status_bayar) === -1
                ) {
                    terpakai += Number(p.total_box || 0);
                    jmlPesanan += 1;
                }
            });
            var sisaL = Math.max(0, kap - terpakai);
            var persenL = Math.min(100, Math.round((terpakai / kap) * 100));
            var levelL = sisaL === 0 ? 'penuh' : (persenL >= 75 ? 'hampir' : 'aman');
            return {
                tanggal: tgl,
                tanggal_id: tanggalId(tgl),
                terpakai: terpakai,
                box: terpakai,
                pesanan: jmlPesanan,
                sisa: sisaL,
                kapasitas: kap,
                persen: persenL,
                level: levelL
            };
        },

        // -------------------------------------------------------------
        // 4. CHECKOUT / BUAT PESANAN BARU (ANTI-OVERBOOKING & VALIDASI)
        // -------------------------------------------------------------
        buatPesanan: async function (payload) {
            var nama = String(payload.nama_pelanggan || '').trim();
            var wa = String(payload.no_wa || '').trim();
            var tgl = String(payload.tanggal_ambil || '').trim();
            var jam = String(payload.jam_ambil || '').trim();
            var catatan = String(payload.catatan || '').trim();
            var items = Array.isArray(payload.items) ? payload.items : [];

            if (!nama || !wa || !tgl || !jam) {
                return { ok: false, pesan: 'Mohon lengkapi nama pemesan, nomor WhatsApp, tanggal, dan jam pengambilan.' };
            }
            if (wa.replace(/\D/g, '').length < 9) {
                return { ok: false, pesan: 'Nomor WhatsApp tidak valid (minimal 9 digit angka).' };
            }

            var valTgl = validasiTanggalAmbil(tgl);
            if (!valTgl.ok) return valTgl;

            var valJam = validasiJamAmbil(jam);
            if (!valJam.ok) return valJam;

            if (!items.length) {
                return { ok: false, pesan: 'Pilih minimal satu menu katering.' };
            }

            // Validasi ketersediaan menu, stok harian, dan jumlah Box Utama terhadap data tanggal ambil
            var katalogTgl = await this.getMenuTanggal(tgl);
            var menuMap = {};
            (katalogTgl || []).forEach(function (m) { menuMap[m.id] = m; });

            var totalBoxUtama = 0;
            var totalPorsiTambahan = 0;
            var totalHargaHitung = 0;
            var itemValid = [];

            for (var i = 0; i < items.length; i++) {
                var it = items[i];
                var qty = parseInt(it.jumlah, 10);
                if (isNaN(qty) || qty <= 0) continue;

                var m = menuMap[Number(it.menu_id)];
                if (!m || !m.aktif) {
                    return { ok: false, pesan: 'Salah satu menu yang dipilih sedang tidak aktif.' };
                }
                if (!m.tersedia) {
                    return { ok: false, pesan: 'Menu "' + m.nama + '" tidak tersedia pada tanggal ' + tanggalId(tgl, false) + '.' };
                }
                if (m.stok !== null && m.stok !== undefined) {
                    var sisaStok = Math.max(0, Number(m.stok) - Number(m.terjual || 0));
                    if (qty > sisaStok) {
                        return { ok: false, pesan: 'Stok "' + m.nama + '" pada tanggal tersebut hanya tersisa ' + sisaStok + ' ' + m.unit + '.' };
                    }
                }

                if (m.is_box) {
                    totalBoxUtama += qty;
                } else {
                    totalPorsiTambahan += qty;
                }
                totalHargaHitung += qty * Number(m.harga);
                itemValid.push({
                    menu_id: m.id,
                    nama_menu: m.nama,
                    unit: m.unit,
                    jumlah: qty,
                    harga_satuan: Number(m.harga),
                    subtotal: qty * Number(m.harga)
                });
            }

            var minBox = CFG.MINIMAL_BOX || 10;
            if (totalBoxUtama < minBox) {
                return {
                    ok: false,
                    pesan: 'Pesanan minimal ' + minBox + ' box utama (Paket Nasi Box atau Nasi Pilihan). Saat ini baru ' + totalBoxUtama + ' box.'
                };
            }

            var kapInfo = await this.getKapasitasTanggal(tgl);
            if (totalBoxUtama > kapInfo.sisa) {
                return {
                    ok: false,
                    pesan: 'Kuota dapur pada ' + tanggalId(tgl, false) + ' hanya tersisa ' + kapInfo.sisa + ' box (pesanan Anda ' + totalBoxUtama + ' box).'
                };
            }

            var diskon = hitungDiskon(totalBoxUtama);
            var nominalBayar = Math.max(0, totalHargaHitung - diskon);
            var cfgToko = await this.getKonfigurasi();
            var batasJam = Math.max(1, parseInt(cfgToko.batas_bayar_jam || '24', 10) || 24);
            var bayarKedaluwarsa = new Date(Date.now() + batasJam * 3600000).toISOString();

            if (isSupabaseReady) {
                try {
                    var rpcRes = await sb.rpc('buat_pesanan_katering', {
                        p_nama_pelanggan: nama,
                        p_no_wa: wa,
                        p_tanggal_ambil: tgl,
                        p_jam_ambil: valJam.jam,
                        p_catatan: catatan,
                        p_items: itemValid.map(function (x) { return { menu_id: x.menu_id, jumlah: x.jumlah }; })
                    });

                    if (!rpcRes.error && rpcRes.data) {
                        if (!rpcRes.data.ok) {
                            return { ok: false, pesan: rpcRes.data.pesan || 'Gagal membuat pesanan.' };
                        }
                        // Pastikan diskon, nominal_bayar, dan bayar_kedaluwarsa_pada tersimpan konsisten di Supabase
                        if (rpcRes.data.id) {
                            await sb.from('pesanan').update({
                                total_box: totalBoxUtama,
                                total_harga: totalHargaHitung,
                                diskon: diskon,
                                nominal_bayar: nominalBayar,
                                bayar_kedaluwarsa_pada: bayarKedaluwarsa
                            }).eq('id', rpcRes.data.id);
                        }
                        return Object.assign({}, rpcRes.data, {
                            total_box: totalBoxUtama,
                            total_harga: totalHargaHitung,
                            diskon: diskon,
                            nominal_bayar: nominalBayar
                        });
                    }
                    if (rpcRes.error) {
                        console.warn('Supabase RPC buat_pesanan_katering gagal:', rpcRes.error.message);
                        return { ok: false, pesan: 'Gagal menyimpan pesanan ke database: ' + rpcRes.error.message };
                    }
                } catch (e) {
                    console.warn('Error buatPesanan Supabase:', e);
                    return { ok: false, pesan: 'Terjadi gangguan koneksi saat menyimpan pesanan. Silakan coba lagi.' };
                }
            }

            // Fallback LocalStorage Transaction
            var state = getLocalState();
            var kode = 'WRT-' + tgl.replace(/-/g, '') + '-' + Math.floor(100 + Math.random() * 900);
            var token = 'tok-' + Math.random().toString(36).substring(2, 12) + Date.now().toString(36);
            var newId = (state.pesanan.reduce(function (m, x) { return Math.max(m, x.id); }, 0)) + 1;

            var pesananBaru = {
                id: newId,
                kode: kode,
                nama_pelanggan: nama,
                no_wa: wa,
                tanggal_ambil: tgl,
                jam_ambil: valJam.jam,
                catatan: catatan,
                total_box: totalBoxUtama,
                total_porsi: totalPorsiTambahan,
                total_harga: totalHargaHitung,
                diskon: diskon,
                nominal_bayar: nominalBayar,
                status: 'Menunggu Konfirmasi',
                metode_bayar: 'qris_statis',
                status_bayar: 'belum_bayar',
                bukti_bayar: null,
                bukti_bayar_pada: null,
                diverifikasi_oleh: null,
                diverifikasi_pada: null,
                dibayar_pada: null,
                alasan_tolak: null,
                bayar_kedaluwarsa_pada: bayarKedaluwarsa,
                token_nota: token,
                dibuat_pada: new Date().toISOString()
            };

            state.pesanan.unshift(pesananBaru);
            var nextDetailId = (state.detail_pesanan.reduce(function (m, x) { return Math.max(m, x.id); }, 0)) + 1;
            itemValid.forEach(function (d) {
                state.detail_pesanan.push(Object.assign({ id: nextDetailId++, pesanan_id: newId }, d));
            });

            saveLocalState(state);
            return {
                ok: true,
                id: newId,
                kode: kode,
                token_nota: token,
                total_box: totalBoxUtama,
                total_harga: totalHargaHitung,
                diskon: diskon,
                nominal_bayar: nominalBayar
            };
        },

        // -------------------------------------------------------------
        // 5. AMBIL PESANAN (NOTA, PEMBAYARAN, INVOICE, CEK STATUS)
        // -------------------------------------------------------------
        getPesanan: async function (kode, token) {
            if (!kode) return null;
            var kodeClean = String(kode).trim().toUpperCase();
            var tokenClean = String(token || '').trim();
            var isAdmin = !!this.getAdminSesi();

            // Akses publik pelanggan wajib menyertakan token_nota yang valid (Temuan 3)
            if (!tokenClean && !isAdmin) {
                return null;
            }

            if (isSupabaseReady) {
                try {
                    var query = sb
                        .from('pesanan')
                        .select('*, detail_pesanan(*, menu(nama, foto, kategori))')
                        .eq('kode', kodeClean);

                    if (tokenClean) {
                        query = query.eq('token_nota', tokenClean);
                    }

                    var res = await query.maybeSingle();
                    if (!res.error && res.data) {
                        var p = res.data;
                        if (tokenClean && p.token_nota && p.token_nota !== tokenClean) {
                            return null;
                        }
                        if (p.diskon === undefined || p.diskon === null) {
                            p.diskon = hitungDiskon(p.total_box);
                        }
                        if (p.nominal_bayar === undefined || p.nominal_bayar === null) {
                            p.nominal_bayar = Math.max(0, Number(p.total_harga || 0) - Number(p.diskon || 0));
                        }

                        // Cek kedaluwarsa otomatis bila belum bayar melewati batas waktu (Temuan 15)
                        if (
                            (p.status_bayar === 'belum_bayar' || p.status_bayar === 'menunggu') &&
                            p.bayar_kedaluwarsa_pada &&
                            new Date(p.bayar_kedaluwarsa_pada).getTime() < Date.now() &&
                            p.status !== 'Dibatalkan'
                        ) {
                            p.status_bayar = 'kedaluwarsa';
                            p.status = 'Dibatalkan';
                            await sb.from('pesanan').update({
                                status_bayar: 'kedaluwarsa',
                                status: 'Dibatalkan'
                            }).eq('id', p.id);
                        }

                        var detail = (p.detail_pesanan || []).map(function (d) {
                            var kat = (d.menu && d.menu.kategori) || '';
                            return {
                                id: d.id,
                                menu_id: d.menu_id,
                                nama: d.nama_menu || (d.menu && d.menu.nama) || 'Menu #' + d.menu_id,
                                foto: (d.menu && d.menu.foto) || null,
                                kategori: kat,
                                unit: d.unit || unitMenu(kat),
                                jumlah: d.jumlah,
                                harga_satuan: d.harga_satuan,
                                subtotal: d.subtotal
                            };
                        });
                        return { pesanan: p, detail: detail };
                    }
                } catch (e) {
                    console.warn('Fallback getPesanan:', e);
                }
            }

            var state = getLocalState();
            var pLocal = (state.pesanan || []).find(function (x) {
                return x.kode.toUpperCase() === kodeClean && (!tokenClean ? isAdmin : x.token_nota === tokenClean);
            });
            if (!pLocal) return null;

            if (pLocal.diskon === undefined || pLocal.diskon === null) {
                pLocal.diskon = hitungDiskon(pLocal.total_box);
            }
            if (pLocal.nominal_bayar === undefined || pLocal.nominal_bayar === null) {
                pLocal.nominal_bayar = Math.max(0, Number(pLocal.total_harga || 0) - Number(pLocal.diskon || 0));
            }

            if (
                (pLocal.status_bayar === 'belum_bayar' || pLocal.status_bayar === 'menunggu') &&
                pLocal.bayar_kedaluwarsa_pada &&
                new Date(pLocal.bayar_kedaluwarsa_pada).getTime() < Date.now() &&
                pLocal.status !== 'Dibatalkan'
            ) {
                pLocal.status_bayar = 'kedaluwarsa';
                pLocal.status = 'Dibatalkan';
                saveLocalState(state);
            }

            var menuMap = {};
            (state.menu || []).forEach(function (m) { menuMap[m.id] = m; });
            var detailLocal = (state.detail_pesanan || [])
                .filter(function (d) { return d.pesanan_id === pLocal.id; })
                .map(function (d) {
                    var m = menuMap[d.menu_id] || {};
                    return {
                        id: d.id,
                        menu_id: d.menu_id,
                        nama: d.nama_menu || m.nama || ('Menu #' + d.menu_id),
                        foto: m.foto || null,
                        kategori: m.kategori || '',
                        unit: d.unit || unitMenu(m.kategori || ''),
                        jumlah: d.jumlah,
                        harga_satuan: d.harga_satuan,
                        subtotal: d.subtotal
                    };
                });

            return { pesanan: pLocal, detail: detailLocal };
        },

        cariPesanan: async function (kode, no_wa) {
            var kodeClean = String(kode || '').trim().toUpperCase();
            var waInput = normalisasiWa(no_wa);
            if (!kodeClean || !waInput || waInput.length < 9) return null;

            if (isSupabaseReady) {
                try {
                    var res = await sb
                        .from('pesanan')
                        .select('*, detail_pesanan(*, menu(nama, foto, kategori))')
                        .eq('kode', kodeClean)
                        .maybeSingle();

                    if (!res.error && res.data) {
                        var pCloud = res.data;
                        var waDb = normalisasiWa(pCloud.no_wa);
                        if (waDb !== waInput) return null;
                        return await this.getPesanan(pCloud.kode, pCloud.token_nota);
                    }
                } catch (e) {
                    console.warn('Fallback cariPesanan:', e);
                }
            }

            var state = getLocalState();
            var p = (state.pesanan || []).find(function (x) {
                return x.kode.toUpperCase() === kodeClean && normalisasiWa(x.no_wa) === waInput;
            });
            if (!p) return null;
            return await this.getPesanan(p.kode, p.token_nota);
        },

        // -------------------------------------------------------------
        // 6. UNGGAH BUKTI PEMBAYARAN (DARI HALAMAN PEMBAYARAN)
        // -------------------------------------------------------------
        unggahBuktiBayar: async function (kode, token, dataUrlGambar) {
            if (!kode || !token || !dataUrlGambar) {
                return { ok: false, pesan: 'Data bukti pembayaran tidak lengkap.' };
            }
            var now = new Date().toISOString();

            if (isSupabaseReady) {
                try {
                    var cari = await sb
                        .from('pesanan')
                        .select('id, status, status_bayar, bayar_kedaluwarsa_pada')
                        .eq('kode', kode)
                        .eq('token_nota', token)
                        .maybeSingle();

                    if (!cari.error && cari.data) {
                        if (cari.data.status === 'Dibatalkan' || cari.data.status_bayar === 'kedaluwarsa' || cari.data.status_bayar === 'dibatalkan') {
                            return { ok: false, pesan: 'Pesanan ini sudah dibatalkan atau melewati batas waktu pembayaran.' };
                        }
                        if (cari.data.status_bayar === 'berhasil' || cari.data.status_bayar === 'lunas') {
                            return { ok: false, pesan: 'Pembayaran pesanan ini sudah terverifikasi lunas.' };
                        }

                        var upd = await sb.from('pesanan').update({
                            bukti_bayar: dataUrlGambar,
                            bukti_bayar_pada: now,
                            status_bayar: 'menunggu_verifikasi',
                            alasan_tolak: null
                        }).eq('id', cari.data.id);

                        if (upd.error) {
                            return { ok: false, pesan: 'Gagal menyimpan bukti bayar ke server: ' + upd.error.message };
                        }

                        await sb.from('verifikasi_log').insert({
                            pesanan_id: cari.data.id,
                            aksi: 'unggah_bukti',
                            catatan: 'Pelanggan mengunggah bukti pembayaran QRIS.'
                        });
                        return { ok: true };
                    }
                } catch (e) {
                    console.warn('Fallback unggahBuktiBayar:', e);
                }
            }

            var state = getLocalState();
            var p = (state.pesanan || []).find(function (x) {
                return x.kode === kode && x.token_nota === token;
            });
            if (!p) return { ok: false, pesan: 'Pesanan tidak ditemukan.' };
            if (p.status === 'Dibatalkan' || p.status_bayar === 'kedaluwarsa' || p.status_bayar === 'dibatalkan') {
                return { ok: false, pesan: 'Pesanan ini sudah dibatalkan atau kedaluwarsa.' };
            }

            p.bukti_bayar = dataUrlGambar;
            p.bukti_bayar_pada = now;
            p.status_bayar = 'menunggu_verifikasi';
            p.alasan_tolak = null;

            state.verifikasi_log.push({
                id: state.verifikasi_log.length + 1,
                pesanan_id: p.id,
                admin_id: null,
                aksi: 'unggah_bukti',
                catatan: 'Pelanggan mengunggah bukti pembayaran QRIS.',
                dibuat_pada: now
            });

            saveLocalState(state);
            return { ok: true };
        },

        // -------------------------------------------------------------
        // 7. AUTENTIKASI, BRUTE-FORCE LOCKOUT & SESI ADMIN
        // -------------------------------------------------------------
        cekStatusLockout: function () {
            try {
                var rawLock = localStorage.getItem(LOCKOUT_KEY);
                var attempts = parseInt(localStorage.getItem(FAILED_ATTEMPTS_KEY) || '0', 10);
                if (rawLock) {
                    var lockTime = parseInt(rawLock, 10);
                    var now = Date.now();
                    if (lockTime > now) {
                        var sisaDetik = Math.ceil((lockTime - now) / 1000);
                        return { terkunci: true, sisaDetik: sisaDetik, attempts: attempts };
                    } else {
                        localStorage.removeItem(LOCKOUT_KEY);
                        localStorage.removeItem(FAILED_ATTEMPTS_KEY);
                        return { terkunci: false, sisaDetik: 0, attempts: 0 };
                    }
                }
                return { terkunci: false, sisaDetik: 0, attempts: attempts };
            } catch (e) {
                return { terkunci: false, sisaDetik: 0, attempts: 0 };
            }
        },

        loginAdmin: async function (username, password) {
            var lockStatus = this.cekStatusLockout();
            if (lockStatus.terkunci) {
                var menit = Math.ceil(lockStatus.sisaDetik / 60);
                return {
                    ok: false,
                    terkunci: true,
                    sisaDetik: lockStatus.sisaDetik,
                    pesan: 'Form login dikunci sementara demi keamanan karena 5x percobaan gagal. Silakan coba lagi dalam ' + menit + ' menit.'
                };
            }

            var u = String(username || '').trim();
            var p = String(password || '');
            if (!u || !p) {
                return { ok: false, pesan: 'Masukkan username dan password.' };
            }

            var self = this;
            var prosesGagal = async function () {
                var cur = parseInt(localStorage.getItem(FAILED_ATTEMPTS_KEY) || '0', 10) + 1;
                localStorage.setItem(FAILED_ATTEMPTS_KEY, String(cur));
                await self.catatAktivitas('login_gagal', 'Percobaan login gagal untuk username: ' + (u.slice(0, 30)));

                if (cur >= MAX_FAILED_ATTEMPTS) {
                    var lockUntil = Date.now() + LOCKOUT_DURATION_MS;
                    localStorage.setItem(LOCKOUT_KEY, String(lockUntil));
                    return {
                        ok: false,
                        terkunci: true,
                        sisaDetik: 15 * 60,
                        pesan: '5 kali percobaan gagal berturut-turut. Akses login dikunci sementara selama 15 menit.'
                    };
                }
                var sisa = MAX_FAILED_ATTEMPTS - cur;
                return {
                    ok: false,
                    terkunci: false,
                    attempts: cur,
                    sisaPercobaan: sisa,
                    pesan: 'Username atau password salah. (Tersisa ' + sisa + ' kali percobaan sebelum akses dikunci)'
                };
            };

            var LEGACY_SEED_BCRYPT = '$2y$10$92IXUNpkjO0rOQ5byMi.Ye4oKoEa3Ro9llC/.og/at2.uheWG/igi';

            if (isSupabaseReady) {
                try {
                    var res = await sb.from('admin').select('*').eq('username', u).maybeSingle();
                    if (!res.error && res.data) {
                        var adm = res.data;
                        var hash = String(adm.password_hash || '');
                        var cocok = (hash === p) || (hash === LEGACY_SEED_BCRYPT && p === 'admin123');
                        if (cocok) {
                            var now = new Date().toISOString();
                            await sb.from('admin').update({ terakhir_login: now }).eq('id', adm.id);
                            adm.terakhir_login = now;
                            var sesiClean = sanitasiObjekAdmin(adm);
                            sesiClean.kadaluwarsa_pada = Date.now() + SESSION_DURATION_MS;
                            localStorage.setItem(SESSION_KEY, JSON.stringify(sesiClean));
                            localStorage.removeItem(LOCKOUT_KEY);
                            localStorage.removeItem(FAILED_ATTEMPTS_KEY);
                            await this.catatAktivitas('login', 'Login berhasil ke dashboard admin');
                            return { ok: true, admin: sesiClean };
                        }
                        return await prosesGagal();
                    }
                } catch (e) {
                    console.warn('Fallback loginAdmin:', e);
                }
            }

            var state = getLocalState();
            var admLocal = (state.admin || []).find(function (a) { return a.username === u; });
            if (!admLocal) return await prosesGagal();

            var hashL = String(admLocal.password_hash || '');
            var cocokL = (hashL === p) || (hashL === LEGACY_SEED_BCRYPT && p === 'admin123');
            if (!cocokL) return await prosesGagal();

            admLocal.terakhir_login = new Date().toISOString();
            saveLocalState(state);
            var sesiLocalClean = sanitasiObjekAdmin(admLocal);
            sesiLocalClean.kadaluwarsa_pada = Date.now() + SESSION_DURATION_MS;
            localStorage.setItem(SESSION_KEY, JSON.stringify(sesiLocalClean));
            localStorage.removeItem(LOCKOUT_KEY);
            localStorage.removeItem(FAILED_ATTEMPTS_KEY);
            await this.catatAktivitas('login', 'Login berhasil ke dashboard admin');
            return { ok: true, admin: sesiLocalClean };
        },

        getAdminSesi: function () {
            try {
                var raw = localStorage.getItem(SESSION_KEY);
                if (!raw) return null;
                var parsed = JSON.parse(raw);
                if (!parsed) return null;
                // Cek kedaluwarsa sesi (8 jam)
                if (parsed.kadaluwarsa_pada && Date.now() > parsed.kadaluwarsa_pada) {
                    localStorage.removeItem(SESSION_KEY);
                    return null;
                }
                return sanitasiObjekAdmin(parsed);
            } catch (e) {
                return null;
            }
        },

        getSesiAdmin: function () {
            return this.getAdminSesi();
        },

        logoutAdmin: function () {
            localStorage.removeItem(SESSION_KEY);
        },

        catatAktivitas: async function (aksi, keterangan) {
            var sesi = this.getAdminSesi();
            var adminId = (sesi && sesi.id) ? sesi.id : 1;
            var now = new Date().toISOString();

            if (isSupabaseReady) {
                try {
                    await sb.from('aktivitas_admin').insert({
                        admin_id: adminId,
                        aksi: String(aksi || 'aksi_admin'),
                        keterangan: String(keterangan || ''),
                        dibuat_pada: now
                    });
                } catch (e) {
                    console.warn('Gagal mencatat aktivitas ke Supabase:', e);
                }
            }

            var state = getLocalState();
            if (!Array.isArray(state.aktivitas)) state.aktivitas = [];
            state.aktivitas.unshift({
                id: state.aktivitas.length + 1,
                admin_id: adminId,
                aksi: String(aksi || 'aksi_admin'),
                keterangan: String(keterangan || ''),
                dibuat_pada: now
            });
            saveLocalState(state);
        },

        // -------------------------------------------------------------
        // 8. OPERASI ADMIN: PESANAN, REKAP, VERIFIKASI, STATUS MASAK
        // -------------------------------------------------------------
        getRekapBulan: async function (tahun, bulan) {
            var awal = tahun + '-' + String(bulan).padStart(2, '0') + '-01';
            var lastDay = new Date(tahun, bulan, 0).getDate();
            var akhir = tahun + '-' + String(bulan).padStart(2, '0') + '-' + String(lastDay).padStart(2, '0');
            var map = {};

            if (isSupabaseReady) {
                try {
                    var res = await sb
                        .from('pesanan')
                        .select('tanggal_ambil, total_box, status, status_bayar')
                        .gte('tanggal_ambil', awal)
                        .lte('tanggal_ambil', akhir)
                        .neq('status', 'Dibatalkan')
                        .not('status_bayar', 'in', '("gagal","kedaluwarsa","dibatalkan")');

                    if (!res.error && res.data) {
                        res.data.forEach(function (r) {
                            if (!map[r.tanggal_ambil]) map[r.tanggal_ambil] = { box: 0, pesanan: 0 };
                            map[r.tanggal_ambil].box += Number(r.total_box || 0);
                            map[r.tanggal_ambil].pesanan += 1;
                        });
                        return map;
                    }
                } catch (e) {
                    console.warn('Fallback getRekapBulan:', e);
                }
            }

            var state = getLocalState();
            (state.pesanan || []).forEach(function (p) {
                if (
                    p.tanggal_ambil >= awal &&
                    p.tanggal_ambil <= akhir &&
                    p.status !== 'Dibatalkan' &&
                    ['gagal', 'kedaluwarsa', 'dibatalkan'].indexOf(p.status_bayar) === -1
                ) {
                    if (!map[p.tanggal_ambil]) map[p.tanggal_ambil] = { box: 0, pesanan: 0 };
                    map[p.tanggal_ambil].box += Number(p.total_box || 0);
                    map[p.tanggal_ambil].pesanan += 1;
                }
            });
            return map;
        },

        getDaftarPemesan: async function (filter) {
            filter = filter || {};
            var q = (filter.q || '').trim().toLowerCase();
            var tgl = filter.tanggal || '';
            var st = filter.status || '';
            var sbFilter = filter.status_bayar || '';
            var urut = filter.urut || '';
            var hal = Math.max(1, Number(filter.hal || 1));
            var per = Math.max(1, Number(filter.per || 10));

            if (isSupabaseReady) {
                try {
                    var query = sb
                        .from('pesanan')
                        .select('*, detail_pesanan(*, menu(nama, kategori))', { count: 'exact' });

                    if (tgl) query = query.eq('tanggal_ambil', tgl);
                    if (st) query = query.eq('status', st);
                    if (sbFilter) {
                        if (Array.isArray(sbFilter)) {
                            query = query.in('status_bayar', sbFilter);
                        } else {
                            query = query.eq('status_bayar', sbFilter);
                        }
                    }
                    if (q) query = query.or('kode.ilike.%' + q + '%,nama_pelanggan.ilike.%' + q + '%');

                    if (urut === 'terbaru') {
                        query = query.order('dibuat_pada', { ascending: false });
                    } else {
                        query = query
                            .order('tanggal_ambil', { ascending: false })
                            .order('jam_ambil', { ascending: true })
                            .order('id', { ascending: false });
                    }

                    var offset = (hal - 1) * per;
                    var res = await query.range(offset, offset + per - 1);

                    if (!res.error && res.data) {
                        var items = res.data.map(function (p) {
                            var dList = (p.detail_pesanan || []).map(function (d) {
                                var kat = (d.menu && d.menu.kategori) || '';
                                var u = d.unit || unitMenu(kat);
                                return {
                                    nama: d.nama_menu || (d.menu && d.menu.nama) || ('Menu #' + d.menu_id),
                                    unit: u,
                                    jumlah: d.jumlah,
                                    harga_satuan: d.harga_satuan,
                                    subtotal_angka: d.subtotal,
                                    harga: rupiah(d.harga_satuan),
                                    subtotal: rupiah(d.subtotal)
                                };
                            });
                            var ringkas = dList.map(function (x) { return x.nama + ' (' + x.jumlah + ' ' + x.unit + ')'; }).join(', ');
                            var diskonVal = (p.diskon !== undefined && p.diskon !== null) ? Number(p.diskon) : hitungDiskon(p.total_box);
                            var nominalVal = (p.nominal_bayar !== undefined && p.nominal_bayar !== null)
                                ? Number(p.nominal_bayar)
                                : Math.max(0, Number(p.total_harga || 0) - diskonVal);

                            return {
                                id: p.id,
                                kode: p.kode,
                                nama_pelanggan: p.nama_pelanggan,
                                no_wa: p.no_wa,
                                tanggal_ambil: p.tanggal_ambil,
                                jam_ambil: p.jam_ambil,
                                catatan: p.catatan || '',
                                total_box: Number(p.total_box || 0),
                                total_harga: Number(p.total_harga || 0),
                                diskon: diskonVal,
                                nominal_bayar: nominalVal,
                                status: p.status,
                                status_bayar: p.status_bayar || 'belum_bayar',
                                bukti_bayar: p.bukti_bayar || null,
                                bukti_bayar_pada: p.bukti_bayar_pada || null,
                                alasan_tolak: p.alasan_tolak || null,
                                diverifikasi_pada: p.diverifikasi_pada || null,
                                dibuat_pada: p.dibuat_pada || null,
                                token_nota: p.token_nota,
                                ringkas: ringkas,
                                items: dList
                            };
                        });
                        var total = res.count || items.length;
                        return {
                            items: items,
                            total: total,
                            hal: hal,
                            totalHal: Math.max(1, Math.ceil(total / per)),
                            offset: offset
                        };
                    }
                } catch (e) {
                    console.warn('Fallback getDaftarPemesan:', e);
                }
            }

            var state = getLocalState();
            var menuMap = {};
            (state.menu || []).forEach(function (m) { menuMap[m.id] = m; });

            var filtered = (state.pesanan || []).filter(function (p) {
                if (tgl && p.tanggal_ambil !== tgl) return false;
                if (st && p.status !== st) return false;
                if (sbFilter) {
                    var sbCur = p.status_bayar || 'belum_bayar';
                    if (Array.isArray(sbFilter)) {
                        if (sbFilter.indexOf(sbCur) === -1) return false;
                    } else if (sbCur !== sbFilter) {
                        return false;
                    }
                }
                if (q) {
                    var matchKode = (p.kode || '').toLowerCase().indexOf(q) >= 0;
                    var matchNama = (p.nama_pelanggan || '').toLowerCase().indexOf(q) >= 0;
                    if (!matchKode && !matchNama) return false;
                }
                return true;
            });

            if (urut === 'terbaru') {
                filtered.sort(function (a, b) {
                    return String(b.dibuat_pada || '').localeCompare(String(a.dibuat_pada || ''));
                });
            }

            var totalL = filtered.length;
            var totalHalL = Math.max(1, Math.ceil(totalL / per));
            if (hal > totalHalL) hal = totalHalL;
            var offsetL = (hal - 1) * per;

            var paged = filtered.slice(offsetL, offsetL + per).map(function (p) {
                var dList = (state.detail_pesanan || [])
                    .filter(function (d) { return d.pesanan_id === p.id; })
                    .map(function (d) {
                        var m = menuMap[d.menu_id] || {};
                        var u = d.unit || unitMenu(m.kategori || '');
                        return {
                            nama: d.nama_menu || m.nama || ('Menu #' + d.menu_id),
                            unit: u,
                            jumlah: d.jumlah,
                            harga_satuan: d.harga_satuan,
                            subtotal_angka: d.subtotal,
                            harga: rupiah(d.harga_satuan),
                            subtotal: rupiah(d.subtotal)
                        };
                    });
                var ringkas = dList.map(function (x) { return x.nama + ' (' + x.jumlah + ' ' + x.unit + ')'; }).join(', ');
                var diskonVal = (p.diskon !== undefined && p.diskon !== null) ? Number(p.diskon) : hitungDiskon(p.total_box);
                var nominalVal = (p.nominal_bayar !== undefined && p.nominal_bayar !== null)
                    ? Number(p.nominal_bayar)
                    : Math.max(0, Number(p.total_harga || 0) - diskonVal);

                return Object.assign({}, p, {
                    catatan: p.catatan || '',
                    diskon: diskonVal,
                    nominal_bayar: nominalVal,
                    status_bayar: p.status_bayar || 'belum_bayar',
                    ringkas: ringkas,
                    items: dList
                });
            });

            return { items: paged, total: totalL, hal: hal, totalHal: totalHalL, offset: offsetL };
        },

        ubahStatusKatering: async function (pesananId, statusBaru) {
            var validStatuses = ['Menunggu Konfirmasi', 'Sedang Dimasak', 'Siap Diambil', 'Selesai', 'Dibatalkan'];
            if (validStatuses.indexOf(statusBaru) < 0) {
                return { ok: false, pesan: 'Status pesanan tidak valid.' };
            }

            var p = null;
            var now = new Date().toISOString();

            if (isSupabaseReady) {
                try {
                    var sel = await sb.from('pesanan').select('*').eq('id', pesananId).maybeSingle();
                    if (sel.error || !sel.data) {
                        return { ok: false, pesan: 'Pesanan tidak ditemukan di database.' };
                    }
                    p = sel.data;

                    // Wajib lunas sebelum mulai dimasak (Temuan 16)
                    if (statusBaru === 'Sedang Dimasak' && p.status_bayar !== 'berhasil' && p.status_bayar !== 'lunas') {
                        return {
                            ok: false,
                            pesan: 'Pesanan ' + p.kode + ' belum berstatus Lunas. Harap verifikasi pembayaran terlebih dahulu sebelum mulai memasak.'
                        };
                    }

                    var payloadUpd = { status: statusBaru, diubah_pada: now };
                    if (statusBaru === 'Selesai') payloadUpd.diserahkan_pada = now;

                    var upd = await sb.from('pesanan').update(payloadUpd).eq('id', pesananId);
                    if (upd.error) {
                        // Fallback bila kolom diubah_pada / diserahkan_pada belum ada di Supabase remote (Temuan 6)
                        var updFallback = await sb.from('pesanan').update({ status: statusBaru }).eq('id', pesananId);
                        if (updFallback.error) {
                            var errMsg = updFallback.error.message || '';
                            if (errMsg.indexOf('pesanan_status_check') >= 0) {
                                errMsg = 'Status "' + statusBaru + '" belum diizinkan oleh check constraint database Supabase. Harap jalankan pembaruan constraint pesanan_status_check di Supabase SQL Editor.';
                            }
                            return { ok: false, pesan: 'Gagal memperbarui status: ' + errMsg };
                        }
                    }

                    p.status = statusBaru;
                } catch (e) {
                    console.warn('Error ubahStatusKatering Supabase:', e);
                    return { ok: false, pesan: 'Gagal menghubungi database saat mengubah status.' };
                }
            } else {
                var state = getLocalState();
                p = (state.pesanan || []).find(function (x) { return x.id === Number(pesananId); });
                if (!p) return { ok: false, pesan: 'Pesanan tidak ditemukan.' };

                if (statusBaru === 'Sedang Dimasak' && p.status_bayar !== 'berhasil' && p.status_bayar !== 'lunas') {
                    return {
                        ok: false,
                        pesan: 'Pesanan ' + p.kode + ' belum berstatus Lunas. Harap verifikasi pembayaran terlebih dahulu sebelum mulai memasak.'
                    };
                }

                p.status = statusBaru;
                p.diubah_pada = now;
                if (statusBaru === 'Selesai') p.diserahkan_pada = now;
                saveLocalState(state);
            }

            await this.catatAktivitas('ubah_status', 'Mengubah status ' + p.kode + ' menjadi ' + statusBaru);

            var waUrl = null;
            if ((statusBaru === 'Sedang Dimasak' || statusBaru === 'Siap Diambil') && p.no_wa) {
                var cleanWa = normalisasiWa(p.no_wa);
                if (cleanWa) {
                    var origin = (typeof window !== 'undefined' && window.location && window.location.origin) ? window.location.origin : '';
                    var pathname = (typeof window !== 'undefined' && window.location && window.location.pathname) ? window.location.pathname : '';
                    var baseDir = pathname.replace(/\/admin\/[^/]*$/, '/');
                    var notaFullUrl = (origin && pathname)
                        ? (origin + baseDir + 'nota.html?kode=' + encodeURIComponent(p.kode || '') + (p.token_nota ? ('&t=' + encodeURIComponent(p.token_nota)) : ''))
                        : ('nota.html?kode=' + encodeURIComponent(p.kode || ''));

                    var pesanWa = '';
                    if (statusBaru === 'Sedang Dimasak') {
                        pesanWa = 'Halo *' + p.nama_pelanggan + '*,\n\n'
                            + 'Pesanan nasi box Anda di *' + (CFG.NAMA_WARTEG || 'Warteg Pak Sanam') + '* dengan kode *' + p.kode + '* (' + p.total_box + ' box) saat ini *SEDANG DIMASAK* di dapur kami.\n\n'
                            + 'Jadwal Pengambilan:\n'
                            + '- Tanggal: ' + tanggalId(p.tanggal_ambil) + '\n'
                            + '- Jam: ' + jamPendek(p.jam_ambil) + ' WIB\n'
                            + '- Lokasi: ' + (CFG.ALAMAT_WARTEG || 'Jalan BDN 1 Cilandak Barat Jakarta Selatan') + '\n\n'
                            + 'Tautan Nota Digital:\n' + notaFullUrl + '\n\n'
                            + 'Kami akan mengabari kembali saat pesanan sudah siap diambil. Terima kasih.';
                    } else if (statusBaru === 'Siap Diambil') {
                        pesanWa = 'Halo *' + p.nama_pelanggan + '*,\n\n'
                            + 'Pesanan nasi box Anda di *' + (CFG.NAMA_WARTEG || 'Warteg Pak Sanam') + '* dengan kode *' + p.kode + '* (' + p.total_box + ' box) sudah *SIAP DIAMBIL*.\n\n'
                            + 'Silakan diambil sesuai jadwal:\n'
                            + '- Tanggal: ' + tanggalId(p.tanggal_ambil) + '\n'
                            + '- Jam: ' + jamPendek(p.jam_ambil) + ' WIB\n'
                            + '- Lokasi: ' + (CFG.ALAMAT_WARTEG || 'Jalan BDN 1 Cilandak Barat Jakarta Selatan') + '\n\n'
                            + 'Tautan Nota Digital:\n' + notaFullUrl + '\n\n'
                            + 'Harap tunjukkan nota ini saat pengambilan di warteg. Terima kasih.';
                    }
                    waUrl = 'https://wa.me/' + cleanWa + '?text=' + encodeURIComponent(pesanWa);
                }
            }

            return { ok: true, status: statusBaru, wa_url: waUrl };
        },

        verifikasiPembayaran: async function (pesananId, aksi, alasan) {
            var sesi = this.getAdminSesi();
            var adminId = (sesi && sesi.id) || 1;
            var now = new Date().toISOString();

            if (aksi === 'tolak' && !String(alasan || '').trim()) {
                return { ok: false, pesan: 'Alasan penolakan wajib diisi.' };
            }

            if (isSupabaseReady) {
                try {
                    var sel = await sb.from('pesanan').select('*').eq('id', pesananId).maybeSingle();
                    if (sel.error || !sel.data) {
                        return { ok: false, pesan: 'Pesanan tidak ditemukan di database.' };
                    }
                    var pCloud = sel.data;

                    // Cegah setujui jika bukti bayar kosong (Temuan 7)
                    if (aksi === 'terima' && !pCloud.bukti_bayar) {
                        return { ok: false, pesan: 'Tidak dapat menyetujui pembayaran karena bukti bayar belum tersedia.' };
                    }

                    var updateData = aksi === 'terima'
                        ? {
                            status_bayar: 'berhasil',
                            dibayar_pada: now,
                            diverifikasi_oleh: adminId,
                            diverifikasi_pada: now,
                            alasan_tolak: null
                        }
                        : {
                            status_bayar: 'ditolak',
                            diverifikasi_oleh: adminId,
                            diverifikasi_pada: now,
                            alasan_tolak: String(alasan || '').trim()
                        };

                    var upd = await sb.from('pesanan').update(updateData).eq('id', pesananId);
                    if (upd.error) {
                        // Fallback jika kolom diverifikasi_oleh / diverifikasi_pada belum ada di schema cache Supabase
                        var fallbackData = aksi === 'terima'
                            ? { status_bayar: 'berhasil', dibayar_pada: now }
                            : { status_bayar: 'ditolak' };
                        var updFallback = await sb.from('pesanan').update(fallbackData).eq('id', pesananId);
                        if (updFallback.error) {
                            return { ok: false, pesan: 'Gagal memperbarui verifikasi: ' + updFallback.error.message };
                        }
                    }

                    try {
                        await sb.from('verifikasi_log').insert({
                            pesanan_id: pesananId,
                            admin_id: adminId,
                            aksi: aksi,
                            catatan: aksi === 'terima' ? 'Pembayaran diterima (Lunas).' : String(alasan || '').trim()
                        });
                    } catch (eLog) {
                        console.warn('Gagal mencatat verifikasi_log:', eLog);
                    }

                    await this.catatAktivitas(
                        aksi === 'terima' ? 'verifikasi_terima' : 'verifikasi_tolak',
                        (aksi === 'terima' ? 'Menyetujui pembayaran ' : 'Menolak pembayaran ') + pCloud.kode
                    );

                    return {
                        ok: true,
                        pesan: aksi === 'terima'
                            ? 'Pembayaran pesanan ' + pCloud.kode + ' berhasil diverifikasi (LUNAS).'
                            : 'Bukti pembayaran pesanan ' + pCloud.kode + ' telah ditolak.'
                    };
                } catch (e) {
                    console.warn('Error verifikasiPembayaran Supabase:', e);
                    return { ok: false, pesan: 'Gagal menghubungi server database saat verifikasi.' };
                }
            }

            var state = getLocalState();
            var p = (state.pesanan || []).find(function (x) { return x.id === Number(pesananId); });
            if (!p) return { ok: false, pesan: 'Pesanan tidak ditemukan.' };

            if (aksi === 'terima' && !p.bukti_bayar) {
                return { ok: false, pesan: 'Tidak dapat menyetujui pembayaran karena bukti bayar belum tersedia.' };
            }

            if (aksi === 'terima') {
                p.status_bayar = 'berhasil';
                p.dibayar_pada = now;
                p.diverifikasi_oleh = adminId;
                p.diverifikasi_pada = now;
                p.alasan_tolak = null;
            } else {
                p.status_bayar = 'ditolak';
                p.diverifikasi_oleh = adminId;
                p.diverifikasi_pada = now;
                p.alasan_tolak = String(alasan || '').trim();
            }

            state.verifikasi_log.push({
                id: state.verifikasi_log.length + 1,
                pesanan_id: p.id,
                admin_id: adminId,
                aksi: aksi,
                catatan: aksi === 'terima' ? 'Pembayaran diterima (Lunas).' : String(alasan || '').trim(),
                dibuat_pada: now
            });

            saveLocalState(state);
            await this.catatAktivitas(
                aksi === 'terima' ? 'verifikasi_terima' : 'verifikasi_tolak',
                (aksi === 'terima' ? 'Menyetujui pembayaran ' : 'Menolak pembayaran ') + p.kode
            );

            return {
                ok: true,
                pesan: aksi === 'terima'
                    ? 'Pembayaran pesanan ' + p.kode + ' berhasil diverifikasi (LUNAS).'
                    : 'Bukti pembayaran pesanan ' + p.kode + ' telah ditolak.'
            };
        },

        // -------------------------------------------------------------
        // 9. OPERASI MENU HARIAN & TAMBAH MENU (TEMUAN 5 - CLOUD + LOCAL)
        // -------------------------------------------------------------
        toggleMenuHarian: async function (arg1, arg2, tersediaBaru) {
            var tanggal, menuId;
            if (typeof arg1 === 'string' && /^\d{4}-\d{2}-\d{2}$/.test(arg1)) {
                tanggal = arg1;
                menuId = arg2;
            } else {
                menuId = arg1;
                tanggal = arg2;
            }
            var tgl = tanggal || addDaysIso(1);
            var idNum = Number(menuId);

            if (isSupabaseReady) {
                try {
                    var cur = await sb
                        .from('menu_harian')
                        .select('*')
                        .eq('tanggal', tgl)
                        .eq('menu_id', idNum)
                        .maybeSingle();

                    var nextTersedia = tersediaBaru !== undefined
                        ? Boolean(tersediaBaru)
                        : (cur.data ? !cur.data.tersedia : false);

                    var stokTetap = cur.data ? cur.data.stok : null;
                    if (stokTetap === null || stokTetap === undefined) {
                        var mRow = await sb.from('menu').select('stok_default').eq('id', idNum).maybeSingle();
                        if (mRow.data) stokTetap = mRow.data.stok_default;
                    }

                    var upRes = await sb.from('menu_harian').upsert({
                        tanggal: tgl,
                        menu_id: idNum,
                        tersedia: nextTersedia,
                        stok: stokTetap
                    }, { onConflict: 'tanggal,menu_id' });

                    if (!upRes.error) {
                        await this.catatAktivitas('toggle_menu', 'Mengubah status ketersediaan menu #' + idNum + ' pada ' + tgl);
                        return { ok: true, tersedia: nextTersedia };
                    }
                } catch (e) {
                    console.warn('Fallback toggleMenuHarian:', e);
                }
            }

            var state = getLocalState();
            if (!state.menu_harian[tgl]) state.menu_harian[tgl] = {};
            var curLocal = state.menu_harian[tgl][idNum] || { tersedia: true, stok: null };
            curLocal.tersedia = tersediaBaru !== undefined ? Boolean(tersediaBaru) : !curLocal.tersedia;
            state.menu_harian[tgl][idNum] = curLocal;
            saveLocalState(state);
            return { ok: true, tersedia: curLocal.tersedia };
        },

        simpanMenu: async function (payload) {
            var nama = String(payload.nama || '').trim();
            var deskripsi = String(payload.deskripsi || '').trim();
            var harga = Math.max(0, Number(payload.harga || 0));
            var kategori = String(payload.kategori || 'lauk').toLowerCase();
            var stokVal = (payload.stok === '' || payload.stok === null || payload.stok === undefined)
                ? null
                : Math.min(120, Math.max(0, Number(payload.stok)));

            if (!nama || harga <= 0) {
                return { ok: false, pesan: 'Nama menu dan harga wajib diisi dengan benar.' };
            }

            if (isSupabaseReady) {
                try {
                    if (payload.id) {
                        var mId = Number(payload.id);
                        var updFields = {
                            nama: nama,
                            harga: harga,
                            kategori: kategori,
                            stok_default: stokVal
                        };
                        if (payload.deskripsi !== undefined) updFields.deskripsi = deskripsi;
                        if (payload.foto) updFields.foto = payload.foto;

                        var updM = await sb.from('menu').update(updFields).eq('id', mId);
                        if (updM.error) {
                            delete updFields.deskripsi;
                            await sb.from('menu').update(updFields).eq('id', mId);
                        }

                        if (payload.tanggal) {
                            var curMh = await sb
                                .from('menu_harian')
                                .select('tersedia')
                                .eq('tanggal', payload.tanggal)
                                .eq('menu_id', mId)
                                .maybeSingle();
                            await sb.from('menu_harian').upsert({
                                tanggal: payload.tanggal,
                                menu_id: mId,
                                tersedia: curMh.data ? curMh.data.tersedia : true,
                                stok: stokVal
                            }, { onConflict: 'tanggal,menu_id' });
                        }
                        await this.catatAktivitas('edit_menu', 'Memperbarui menu "' + nama + '"');
                        return { ok: true, id: mId };
                    } else {
                        var insFields = {
                            nama: nama,
                            deskripsi: deskripsi || null,
                            harga: harga,
                            kategori: kategori,
                            foto: payload.foto || null,
                            stok_default: stokVal !== null ? stokVal : 60,
                            aktif: true
                        };
                        var insM = await sb.from('menu').insert(insFields).select('id').single();
                        if (insM.error) {
                            delete insFields.deskripsi;
                            insM = await sb.from('menu').insert(insFields).select('id').single();
                        }
                        if (!insM.error && insM.data) {
                            await this.catatAktivitas('tambah_menu', 'Menambahkan menu baru "' + nama + '"');
                            return { ok: true, id: insM.data.id };
                        }
                    }
                } catch (e) {
                    console.warn('Fallback simpanMenu:', e);
                }
            }

            var state = getLocalState();
            if (payload.id) {
                var m = (state.menu || []).find(function (x) { return x.id === Number(payload.id); });
                if (!m) return { ok: false, pesan: 'Menu tidak ditemukan.' };
                m.nama = nama;
                if (payload.deskripsi !== undefined) m.deskripsi = deskripsi;
                m.harga = harga;
                if (payload.kategori) m.kategori = kategori;
                if (payload.foto) m.foto = payload.foto;
                if (payload.tanggal && stokVal !== null) {
                    if (!state.menu_harian[payload.tanggal]) state.menu_harian[payload.tanggal] = {};
                    var mh = state.menu_harian[payload.tanggal][m.id] || { tersedia: true };
                    mh.stok = stokVal;
                    state.menu_harian[payload.tanggal][m.id] = mh;
                }
                m.stok_default = stokVal;
                saveLocalState(state);
                return { ok: true, id: m.id };
            } else {
                var newId = (state.menu.reduce(function (mx, x) { return Math.max(mx, x.id); }, 0)) + 1;
                state.menu.push({
                    id: newId,
                    nama: nama,
                    deskripsi: deskripsi,
                    harga: harga,
                    kategori: kategori,
                    foto: payload.foto || null,
                    stok_default: stokVal !== null ? stokVal : 60,
                    aktif: true
                });
                saveLocalState(state);
                return { ok: true, id: newId };
            }
        },

        hapusMenu: async function (menuId) {
            var idNum = Number(menuId);
            if (isSupabaseReady) {
                try {
                    var del = await sb.from('menu').update({ aktif: false }).eq('id', idNum);
                    if (!del.error) {
                        await this.catatAktivitas('hapus_menu', 'Menonaktifkan/menghapus menu #' + idNum);
                        return { ok: true };
                    }
                } catch (e) {
                    console.warn('Fallback hapusMenu:', e);
                }
            }
            var state = getLocalState();
            state.menu = (state.menu || []).filter(function (x) { return x.id !== idNum; });
            saveLocalState(state);
            return { ok: true };
        },

        // -------------------------------------------------------------
        // 10. MANAJEMEN AKUN ADMIN & PROFIL (TEMUAN 2, 5, 20)
        // -------------------------------------------------------------
        getDaftarAdmin: async function () {
            if (isSupabaseReady) {
                try {
                    var res = await sb
                        .from('admin')
                        .select('id, username, nama, role, email, no_hp, jabatan, foto, terakhir_login, dibuat_pada')
                        .order('id', { ascending: true });

                    if (!res.error && res.data && res.data.length > 0) {
                        return res.data.map(sanitasiObjekAdmin);
                    }
                } catch (e) {
                    console.warn('Fallback getDaftarAdmin:', e);
                }
            }
            var state = getLocalState();
            return (state.admin || []).map(sanitasiObjekAdmin);
        },

        tambahAdmin: async function (payload) {
            var u = (payload.username || '').trim();
            var n = (payload.nama || '').trim();
            var p = payload.password || '';
            var r = payload.role === 'owner' ? 'owner' : 'admin';

            if (!u || !n || p.length < 6) {
                return { ok: false, pesan: 'Username, nama lengkap, dan password (minimal 6 karakter) wajib diisi.' };
            }

            if (isSupabaseReady) {
                try {
                    var cek = await sb.from('admin').select('id').eq('username', u).maybeSingle();
                    if (cek.data) {
                        return { ok: false, pesan: 'Username sudah digunakan oleh akun lain.' };
                    }
                    var ins = await sb.from('admin').insert({
                        username: u,
                        password_hash: p,
                        nama: n,
                        role: r,
                        jabatan: r === 'owner' ? 'Pemilik & Pengelola' : 'Staf Admin Operasional',
                        dibuat_pada: new Date().toISOString()
                    });
                    if (!ins.error) {
                        await this.catatAktivitas('tambah_admin', 'Menambahkan akun admin baru: @' + u + ' (' + r + ')');
                        return { ok: true };
                    }
                } catch (e) {
                    console.warn('Fallback tambahAdmin:', e);
                }
            }

            var state = getLocalState();
            if ((state.admin || []).some(function (x) { return x.username === u; })) {
                return { ok: false, pesan: 'Username sudah digunakan.' };
            }
            var newId = (state.admin.reduce(function (m, x) { return Math.max(m, x.id); }, 0)) + 1;
            state.admin.push({
                id: newId,
                username: u,
                password_hash: p,
                nama: n,
                role: r,
                email: '',
                no_hp: '',
                jabatan: r === 'owner' ? 'Pemilik & Pengelola' : 'Staf Admin Operasional',
                foto: null,
                terakhir_login: null,
                dibuat_pada: new Date().toISOString()
            });
            saveLocalState(state);
            await this.catatAktivitas('tambah_admin', 'Menambahkan akun admin baru: @' + u + ' (' + r + ')');
            return { ok: true };
        },

        hapusAdmin: async function (id) {
            var targetId = Number(id);
            var sesi = this.getAdminSesi();
            if (sesi && Number(sesi.id) === targetId) {
                return { ok: false, pesan: 'Tidak dapat menghapus akun yang sedang Anda gunakan.' };
            }

            if (isSupabaseReady) {
                try {
                    var targetRes = await sb.from('admin').select('id, username, role').eq('id', targetId).maybeSingle();
                    if (targetRes.data && targetRes.data.role === 'owner') {
                        var ownersRes = await sb.from('admin').select('id').eq('role', 'owner');
                        if (ownersRes.data && ownersRes.data.length <= 1) {
                            return { ok: false, pesan: 'Tidak dapat menghapus satu-satunya akun Owner.' };
                        }
                    }
                    var del = await sb.from('admin').delete().eq('id', targetId);
                    if (!del.error) {
                        await this.catatAktivitas('hapus_admin', 'Menghapus akun admin ID #' + targetId);
                        return { ok: true };
                    }
                } catch (e) {
                    console.warn('Fallback hapusAdmin:', e);
                }
            }

            var state = getLocalState();
            var target = (state.admin || []).find(function (x) { return x.id === targetId; });
            if (!target) return { ok: false, pesan: 'Akun tidak ditemukan.' };
            var jmlOwner = (state.admin || []).filter(function (x) { return x.role === 'owner'; }).length;
            if (target.role === 'owner' && jmlOwner <= 1) {
                return { ok: false, pesan: 'Tidak dapat menghapus satu-satunya akun Owner.' };
            }
            state.admin = (state.admin || []).filter(function (x) { return x.id !== targetId; });
            saveLocalState(state);
            await this.catatAktivitas('hapus_admin', 'Menghapus akun admin: @' + target.username);
            return { ok: true };
        },

        updateProfilAdmin: async function (payload) {
            var sesi = this.getAdminSesi();
            if (!sesi) return { ok: false, pesan: 'Sesi login telah berakhir.' };

            var nama = String(payload.nama || sesi.nama || '').trim();
            var username = String(payload.username || sesi.username || '').trim();
            var jabatan = String(payload.jabatan !== undefined ? payload.jabatan : (payload.alamat !== undefined ? payload.alamat : (sesi.jabatan || ''))).trim();
            var email = String(payload.email !== undefined ? payload.email : (sesi.email || '')).trim();
            var no_hp = String(payload.no_hp !== undefined ? payload.no_hp : (payload.telepon !== undefined ? payload.telepon : (sesi.no_hp || ''))).trim();
            var foto = payload.foto !== undefined ? payload.foto : (sesi.foto || null);

            if (!nama || !username) {
                return { ok: false, pesan: 'Nama lengkap dan username wajib diisi.' };
            }

            if (isSupabaseReady) {
                try {
                    var updData = {
                        nama: nama,
                        username: username,
                        jabatan: jabatan,
                        email: email,
                        no_hp: no_hp
                    };
                    if (foto) updData.foto = foto;
                    var upd = await sb.from('admin').update(updData).eq('id', sesi.id);
                    if (upd.error) {
                        return { ok: false, pesan: 'Gagal memperbarui profil di database: ' + upd.error.message };
                    }
                } catch (e) {
                    console.warn('Fallback updateProfilAdmin:', e);
                }
            }

            var state = getLocalState();
            var adm = (state.admin || []).find(function (x) { return x.id === sesi.id; });
            if (adm) {
                adm.nama = nama;
                adm.username = username;
                adm.jabatan = jabatan;
                adm.email = email;
                adm.no_hp = no_hp;
                if (foto) adm.foto = foto;
                saveLocalState(state);
            }

            var sesiBaru = Object.assign({}, sesi, {
                nama: nama,
                username: username,
                jabatan: jabatan,
                email: email,
                no_hp: no_hp,
                foto: foto
            });
            localStorage.setItem(SESSION_KEY, JSON.stringify(sesiBaru));
            await this.catatAktivitas('update_profil', 'Memperbarui profil & kontak akun admin');
            return { ok: true, admin: sesiBaru };
        },

        ubahPasswordAdmin: async function (passwordLama, passwordBaru) {
            var sesi = this.getAdminSesi();
            if (!sesi) return { ok: false, pesan: 'Sesi login telah berakhir.' };
            if (!passwordLama || !passwordBaru || String(passwordBaru).length < 6) {
                return { ok: false, pesan: 'Password baru minimal terdiri dari 6 karakter.' };
            }

            var LEGACY_SEED_BCRYPT = '$2y$10$92IXUNpkjO0rOQ5byMi.Ye4oKoEa3Ro9llC/.og/at2.uheWG/igi';

            if (isSupabaseReady) {
                try {
                    var res = await sb.from('admin').select('id, password_hash').eq('id', sesi.id).maybeSingle();
                    if (!res.error && res.data) {
                        var hash = String(res.data.password_hash || '');
                        var cocok = (hash === passwordLama) || (hash === LEGACY_SEED_BCRYPT && passwordLama === 'admin123');
                        if (!cocok) {
                            return { ok: false, pesan: 'Password saat ini tidak sesuai.' };
                        }
                        var upd = await sb.from('admin').update({ password_hash: passwordBaru }).eq('id', sesi.id);
                        if (upd.error) {
                            return { ok: false, pesan: 'Gagal memperbarui password: ' + upd.error.message };
                        }
                    }
                } catch (e) {
                    console.warn('Fallback ubahPasswordAdmin:', e);
                }
            } else {
                var state = getLocalState();
                var adm = (state.admin || []).find(function (x) { return x.id === sesi.id; });
                if (adm) {
                    var hashL = String(adm.password_hash || '');
                    var cocokL = (hashL === passwordLama) || (hashL === LEGACY_SEED_BCRYPT && passwordLama === 'admin123');
                    if (!cocokL) {
                        return { ok: false, pesan: 'Password saat ini tidak sesuai.' };
                    }
                    adm.password_hash = passwordBaru;
                    saveLocalState(state);
                }
            }

            await this.catatAktivitas('ubah_password', 'Mengubah kata sandi akun admin');
            return { ok: true, pesan: 'Password akun berhasil diperbarui.' };
        },

        getAktivitasAdmin: function () {
            var state = getLocalState();
            return state.aktivitas || [];
        }
    };

    window.WARTEG_DB = DB;

    // =================================================================
    // INISIALISASI GLOBAL HEADER MOBILE PELANGGAN (TEMUAN 17)
    // Memastikan tombol hamburger #custToggle berfungsi di semua halaman
    // =================================================================
    document.addEventListener('DOMContentLoaded', function () {
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
            document.addEventListener('keydown', function (e) {
                if (e.key === 'Escape' && navLinks.classList.contains('buka')) {
                    navLinks.classList.remove('buka');
                    btnToggle.setAttribute('aria-expanded', 'false');
                    btnToggle.focus();
                }
            });
            document.addEventListener('click', function (e) {
                if (navLinks.classList.contains('buka') && !navLinks.contains(e.target) && !btnToggle.contains(e.target)) {
                    navLinks.classList.remove('buka');
                    btnToggle.setAttribute('aria-expanded', 'false');
                }
            });
        }
    });
})();
