-- =====================================================================
-- SKEMA DATABASE SUPABASE (POSTGRESQL)
-- Sistem Katering & POS Warteg Pak Sanam (Versi GitHub Pages + Supabase)
-- Cara Pakai: Salin seluruh isi file ini dan jalankan di Supabase SQL Editor
-- =====================================================================

-- 1. TABEL MENU (Katalog Makanan)
CREATE TABLE IF NOT EXISTS menu (
    id BIGSERIAL PRIMARY KEY,
    nama TEXT NOT NULL,
    deskripsi TEXT NULL,
    harga INTEGER NOT NULL CHECK (harga >= 0),
    kategori TEXT NOT NULL DEFAULT 'lauk'
        CHECK (kategori IN ('paket a', 'paket b', 'paket c', 'nasi', 'lauk', 'sayur')),
    foto TEXT NULL,
    stok_default INTEGER NULL,
    aktif BOOLEAN NOT NULL DEFAULT TRUE,
    dibuat_pada TIMESTAMPTZ NOT NULL DEFAULT NOW()
);
ALTER TABLE menu ADD COLUMN IF NOT EXISTS deskripsi TEXT NULL;

-- 2. TABEL MENU HARIAN (Ketersediaan & Stok Per Tanggal)
CREATE TABLE IF NOT EXISTS menu_harian (
    id BIGSERIAL PRIMARY KEY,
    tanggal DATE NOT NULL,
    menu_id BIGINT NOT NULL REFERENCES menu(id) ON DELETE CASCADE,
    tersedia BOOLEAN NOT NULL DEFAULT TRUE,
    stok INTEGER NULL,
    UNIQUE (tanggal, menu_id)
);
CREATE INDEX IF NOT EXISTS idx_menu_harian_tgl ON menu_harian (tanggal);

-- 3. TABEL ADMIN (Akun Pengelola & Owner)
CREATE TABLE IF NOT EXISTS admin (
    id BIGSERIAL PRIMARY KEY,
    username TEXT NOT NULL UNIQUE,
    password_hash TEXT NOT NULL,
    nama TEXT NOT NULL,
    role TEXT NOT NULL DEFAULT 'admin' CHECK (role IN ('owner', 'admin')),
    email TEXT NULL,
    no_hp TEXT NULL,
    jabatan TEXT NULL,
    foto TEXT NULL,
    terakhir_login TIMESTAMPTZ NULL,
    dibuat_pada TIMESTAMPTZ NOT NULL DEFAULT NOW()
);

-- 4. TABEL KUOTA HARIAN (Pengunci Kapasitas 120 Box / Hari)
CREATE TABLE IF NOT EXISTS kuota_harian (
    tanggal DATE PRIMARY KEY,
    terpakai INTEGER NOT NULL DEFAULT 0,
    diperbarui_pada TIMESTAMPTZ NOT NULL DEFAULT NOW()
);

-- 5. TABEL PESANAN (Header Transaksi Katering)
CREATE TABLE IF NOT EXISTS pesanan (
    id BIGSERIAL PRIMARY KEY,
    kode TEXT NOT NULL UNIQUE,
    nama_pelanggan TEXT NOT NULL,
    no_wa TEXT NOT NULL,
    tanggal_ambil DATE NOT NULL,
    jam_ambil TIME NOT NULL,
    catatan TEXT NULL,
    total_box INTEGER NOT NULL CHECK (total_box >= 10),
    total_harga INTEGER NOT NULL,
    status TEXT NOT NULL DEFAULT 'Menunggu Konfirmasi'
        CHECK (status IN (
            'Menunggu Pembayaran',
            'Menunggu Verifikasi',
            'Dikonfirmasi',
            'Menunggu Konfirmasi',
            'Sedang Dimasak',
            'Siap Diambil',
            'Selesai',
            'Dibatalkan'
        )),
    metode_bayar TEXT NOT NULL DEFAULT 'qris_statis',
    status_bayar TEXT NOT NULL DEFAULT 'belum_bayar'
        CHECK (status_bayar IN (
            'belum_bayar',
            'menunggu',
            'menunggu_verifikasi',
            'berhasil',
            'lunas',
            'ditolak',
            'gagal',
            'kedaluwarsa',
            'dibatalkan'
        )),
    diskon INTEGER NOT NULL DEFAULT 0,
    nominal_bayar INTEGER NULL,
    bukti_bayar TEXT NULL,
    bukti_bayar_pada TIMESTAMPTZ NULL,
    diverifikasi_oleh BIGINT NULL REFERENCES admin(id) ON DELETE SET NULL,
    diverifikasi_pada TIMESTAMPTZ NULL,
    alasan_tolak TEXT NULL,
    qris_payload TEXT NULL,
    bayar_kedaluwarsa_pada TIMESTAMPTZ NULL,
    dibayar_pada TIMESTAMPTZ NULL,
    diubah_pada TIMESTAMPTZ NULL,
    diserahkan_pada TIMESTAMPTZ NULL,
    token_nota TEXT NULL UNIQUE,
    dibuat_pada TIMESTAMPTZ NOT NULL DEFAULT NOW()
);
ALTER TABLE pesanan ADD COLUMN IF NOT EXISTS diskon INTEGER NOT NULL DEFAULT 0;
ALTER TABLE pesanan ADD COLUMN IF NOT EXISTS diubah_pada TIMESTAMPTZ NULL;
ALTER TABLE pesanan ADD COLUMN IF NOT EXISTS diserahkan_pada TIMESTAMPTZ NULL;

-- Pastikan constraint status pesanan mendukung siklus lengkap hingga Selesai & Dibatalkan
ALTER TABLE pesanan DROP CONSTRAINT IF EXISTS pesanan_status_check;
ALTER TABLE pesanan ADD CONSTRAINT pesanan_status_check CHECK (
    status IN (
        'Menunggu Pembayaran',
        'Menunggu Verifikasi',
        'Dikonfirmasi',
        'Menunggu Konfirmasi',
        'Sedang Dimasak',
        'Siap Diambil',
        'Selesai',
        'Dibatalkan'
    )
);

CREATE INDEX IF NOT EXISTS idx_pesanan_tanggal ON pesanan (tanggal_ambil);
CREATE INDEX IF NOT EXISTS idx_pesanan_status ON pesanan (status);
CREATE INDEX IF NOT EXISTS idx_pesanan_status_bayar ON pesanan (status_bayar);

-- 6. TABEL DETAIL PESANAN (Item Menu & Snapshot Historis per Transaksi)
CREATE TABLE IF NOT EXISTS detail_pesanan (
    id BIGSERIAL PRIMARY KEY,
    pesanan_id BIGINT NOT NULL REFERENCES pesanan(id) ON DELETE CASCADE,
    menu_id BIGINT NOT NULL REFERENCES menu(id) ON DELETE RESTRICT,
    nama_menu TEXT NULL,
    unit TEXT NOT NULL DEFAULT 'box',
    jumlah INTEGER NOT NULL CHECK (jumlah > 0),
    harga_satuan INTEGER NOT NULL,
    subtotal INTEGER NOT NULL
);
ALTER TABLE detail_pesanan ADD COLUMN IF NOT EXISTS nama_menu TEXT NULL;
ALTER TABLE detail_pesanan ADD COLUMN IF NOT EXISTS unit TEXT NOT NULL DEFAULT 'box';
CREATE INDEX IF NOT EXISTS idx_detail_pesanan ON detail_pesanan (pesanan_id);

-- 7. TABEL KONFIGURASI TOKO (Pengaturan QRIS & Batas Bayar)
CREATE TABLE IF NOT EXISTS konfigurasi_toko (
    kunci TEXT PRIMARY KEY,
    nilai TEXT NULL,
    diperbarui_pada TIMESTAMPTZ NOT NULL DEFAULT NOW()
);

-- 8. TABEL LOG VERIFIKASI PEMBAYARAN
CREATE TABLE IF NOT EXISTS verifikasi_log (
    id BIGSERIAL PRIMARY KEY,
    pesanan_id BIGINT NOT NULL REFERENCES pesanan(id) ON DELETE CASCADE,
    admin_id BIGINT NULL REFERENCES admin(id) ON DELETE SET NULL,
    aksi TEXT NOT NULL CHECK (aksi IN ('terima', 'tolak', 'unggah_bukti')),
    catatan TEXT NULL,
    dibuat_pada TIMESTAMPTZ NOT NULL DEFAULT NOW()
);
CREATE INDEX IF NOT EXISTS idx_verifikasi_pesanan ON verifikasi_log (pesanan_id);

-- 9. TABEL AKTIVITAS ADMIN
CREATE TABLE IF NOT EXISTS aktivitas_admin (
    id BIGSERIAL PRIMARY KEY,
    admin_id BIGINT NOT NULL REFERENCES admin(id) ON DELETE CASCADE,
    aksi TEXT NOT NULL,
    keterangan TEXT NULL,
    dibuat_pada TIMESTAMPTZ NOT NULL DEFAULT NOW()
);
CREATE INDEX IF NOT EXISTS idx_aktivitas_admin ON aktivitas_admin (admin_id, dibuat_pada DESC);

-- =====================================================================
-- KEBIJAKAN ROW LEVEL SECURITY (RLS) UNTUK GITHUB PAGES
-- =====================================================================
ALTER TABLE menu ENABLE ROW LEVEL SECURITY;
ALTER TABLE menu_harian ENABLE ROW LEVEL SECURITY;
ALTER TABLE admin ENABLE ROW LEVEL SECURITY;
ALTER TABLE kuota_harian ENABLE ROW LEVEL SECURITY;
ALTER TABLE pesanan ENABLE ROW LEVEL SECURITY;
ALTER TABLE detail_pesanan ENABLE ROW LEVEL SECURITY;
ALTER TABLE konfigurasi_toko ENABLE ROW LEVEL SECURITY;
ALTER TABLE verifikasi_log ENABLE ROW LEVEL SECURITY;
ALTER TABLE aktivitas_admin ENABLE ROW LEVEL SECURITY;

-- Kebijakan akses tabel untuk frontend GitHub Pages + RPC Security Definer
DROP POLICY IF EXISTS "public_all_menu" ON menu;
CREATE POLICY "public_all_menu" ON menu FOR ALL USING (true) WITH CHECK (true);

DROP POLICY IF EXISTS "public_all_menu_harian" ON menu_harian;
CREATE POLICY "public_all_menu_harian" ON menu_harian FOR ALL USING (true) WITH CHECK (true);

DROP POLICY IF EXISTS "public_all_admin" ON admin;
CREATE POLICY "public_all_admin" ON admin FOR ALL USING (true) WITH CHECK (true);

DROP POLICY IF EXISTS "public_all_kuota_harian" ON kuota_harian;
CREATE POLICY "public_all_kuota_harian" ON kuota_harian FOR ALL USING (true) WITH CHECK (true);

DROP POLICY IF EXISTS "public_all_pesanan" ON pesanan;
CREATE POLICY "public_all_pesanan" ON pesanan FOR ALL USING (true) WITH CHECK (true);

DROP POLICY IF EXISTS "public_all_detail_pesanan" ON detail_pesanan;
CREATE POLICY "public_all_detail_pesanan" ON detail_pesanan FOR ALL USING (true) WITH CHECK (true);

DROP POLICY IF EXISTS "public_all_konfigurasi_toko" ON konfigurasi_toko;
CREATE POLICY "public_all_konfigurasi_toko" ON konfigurasi_toko FOR ALL USING (true) WITH CHECK (true);

DROP POLICY IF EXISTS "public_all_verifikasi_log" ON verifikasi_log;
CREATE POLICY "public_all_verifikasi_log" ON verifikasi_log FOR ALL USING (true) WITH CHECK (true);

DROP POLICY IF EXISTS "public_all_aktivitas_admin" ON aktivitas_admin;
CREATE POLICY "public_all_aktivitas_admin" ON aktivitas_admin FOR ALL USING (true) WITH CHECK (true);

-- =====================================================================
-- FUNGSI RPC TRANSAKSI CHECKOUT (ANTI-OVERBOOKING 120 BOX/HARI + VALIDASI)
-- =====================================================================
CREATE OR REPLACE FUNCTION buat_pesanan_katering(
    p_nama_pelanggan TEXT,
    p_no_wa TEXT,
    p_tanggal_ambil DATE,
    p_jam_ambil TIME,
    p_catatan TEXT,
    p_items JSONB
)
RETURNS JSONB
LANGUAGE plpgsql
SECURITY DEFINER
AS $$
DECLARE
    v_total_box INTEGER := 0;
    v_total_item_qty INTEGER := 0;
    v_total_harga INTEGER := 0;
    v_diskon INTEGER := 0;
    v_nominal_bayar INTEGER := 0;
    v_kuota_saat_ini INTEGER := 0;
    v_item JSONB;
    v_menu RECORD;
    v_mh RECORD;
    v_terjual INTEGER;
    v_stok_efektif INTEGER;
    v_kode TEXT;
    v_token TEXT;
    v_pesanan_id BIGINT;
    v_unit TEXT;
    v_batas_jam INTEGER := 24;
BEGIN
    -- 0. Validasi Tanggal Ambil (H+1 s/d H+30) & Jam Operasional (09:00 - 19:00)
    IF p_tanggal_ambil < (CURRENT_DATE + 1) OR p_tanggal_ambil > (CURRENT_DATE + 30) THEN
        RETURN jsonb_build_object(
            'ok', false,
            'pesan', 'Tanggal pengambilan harus berada dalam rentang H-1 (besok) hingga 30 hari ke depan.'
        );
    END IF;

    IF p_jam_ambil < '09:00:00'::time OR p_jam_ambil > '19:00:00'::time THEN
        RETURN jsonb_build_object(
            'ok', false,
            'pesan', 'Jam pengambilan harus berada dalam jam operasional dapur (09.00 - 19.00 WIB).'
        );
    END IF;

    -- 1. Kunci baris kuota_harian untuk tanggal tersebut
    INSERT INTO kuota_harian (tanggal, terpakai)
    VALUES (p_tanggal_ambil, 0)
    ON CONFLICT (tanggal) DO NOTHING;

    SELECT COALESCE(SUM(total_box), 0) INTO v_kuota_saat_ini
    FROM pesanan
    WHERE tanggal_ambil = p_tanggal_ambil
      AND status NOT IN ('Dibatalkan')
      AND status_bayar NOT IN ('gagal', 'kedaluwarsa', 'dibatalkan');

    -- 2. Hitung total box utama & validasi stok tiap menu
    FOR v_item IN SELECT * FROM jsonb_array_elements(p_items)
    LOOP
        IF COALESCE((v_item->>'jumlah')::INTEGER, 0) <= 0 THEN
            CONTINUE;
        END IF;

        SELECT * INTO v_menu FROM menu WHERE id = (v_item->>'menu_id')::BIGINT AND aktif = TRUE;
        IF NOT FOUND THEN
            RETURN jsonb_build_object('ok', false, 'pesan', 'Salah satu menu tidak ditemukan atau sedang nonaktif.');
        END IF;

        SELECT * INTO v_mh FROM menu_harian WHERE tanggal = p_tanggal_ambil AND menu_id = v_menu.id;
        IF FOUND AND v_mh.tersedia = FALSE THEN
            RETURN jsonb_build_object('ok', false, 'pesan', 'Menu "' || v_menu.nama || '" sedang tidak tersedia pada tanggal tersebut.');
        END IF;

        v_stok_efektif := COALESCE(v_mh.stok, v_menu.stok_default);
        IF v_stok_efektif IS NOT NULL THEN
            SELECT COALESCE(SUM(dp.jumlah), 0) INTO v_terjual
            FROM detail_pesanan dp
            JOIN pesanan p ON p.id = dp.pesanan_id
            WHERE p.tanggal_ambil = p_tanggal_ambil
              AND dp.menu_id = v_menu.id
              AND p.status NOT IN ('Dibatalkan')
              AND p.status_bayar NOT IN ('gagal', 'kedaluwarsa', 'dibatalkan');

            IF (v_terjual + (v_item->>'jumlah')::INTEGER) > v_stok_efektif THEN
                RETURN jsonb_build_object(
                    'ok', false,
                    'pesan', 'Stok "' || v_menu.nama || '" pada tanggal tersebut hanya tersisa ' || GREATEST(0, v_stok_efektif - v_terjual) || '.'
                );
            END IF;
        END IF;

        v_total_item_qty := v_total_item_qty + (v_item->>'jumlah')::INTEGER;
        IF v_menu.kategori IN ('paket a', 'paket b', 'paket c', 'nasi') THEN
            v_total_box := v_total_box + (v_item->>'jumlah')::INTEGER;
        END IF;
        v_total_harga := v_total_harga + ((v_item->>'jumlah')::INTEGER * v_menu.harga);
    END LOOP;

    -- 3. Validasi minimal 10 box utama & maksimal kapasitas 120 box
    IF v_total_box < 10 THEN
        RETURN jsonb_build_object(
            'ok', false,
            'pesan', 'Pesanan minimal 10 box (Paket Nasi Box atau Nasi Pilihan). Saat ini baru ' || v_total_box || ' box.'
        );
    END IF;

    IF (v_kuota_saat_ini + v_total_box) > 120 THEN
        RETURN jsonb_build_object(
            'ok', false,
            'pesan', 'Kuota tanggal tersebut hanya tersisa ' || GREATEST(0, 120 - v_kuota_saat_ini) || ' box dari kapasitas 120 box.'
        );
    END IF;

    -- 4. Buat Kode Pesanan Unik (WRT-YYYYMMDD-XXX) & Token Nota
    v_kode := 'WRT-' || to_char(p_tanggal_ambil, 'YYYYMMDD') || '-' || lpad((floor(random() * 900) + 100)::TEXT, 3, '0');
    v_token := encode(gen_random_bytes(16), 'hex');

    -- 5. Hitung Diskon Kelipatan 50 Box (Rp 50.000 per kelipatan 50 box)
    v_diskon := FLOOR(v_total_box / 50) * 50000;
    v_nominal_bayar := GREATEST(0, v_total_harga - v_diskon);

    SELECT COALESCE(NULLIF(nilai, '')::INTEGER, 24) INTO v_batas_jam
    FROM konfigurasi_toko WHERE kunci = 'batas_bayar_jam';

    -- 6. Simpan ke tabel pesanan
    INSERT INTO pesanan (
        kode, nama_pelanggan, no_wa, tanggal_ambil, jam_ambil, catatan,
        total_box, total_harga, diskon, status, metode_bayar, status_bayar,
        nominal_bayar, bayar_kedaluwarsa_pada, token_nota
    ) VALUES (
        v_kode, p_nama_pelanggan, p_no_wa, p_tanggal_ambil, p_jam_ambil, p_catatan,
        v_total_box, v_total_harga, v_diskon, 'Menunggu Konfirmasi', 'qris_statis', 'belum_bayar',
        v_nominal_bayar, NOW() + (v_batas_jam || ' hours')::INTERVAL, v_token
    ) RETURNING id INTO v_pesanan_id;

    -- 7. Simpan detail_pesanan beserta snapshot nama_menu & unit
    FOR v_item IN SELECT * FROM jsonb_array_elements(p_items)
    LOOP
        IF COALESCE((v_item->>'jumlah')::INTEGER, 0) <= 0 THEN
            CONTINUE;
        END IF;
        SELECT * INTO v_menu FROM menu WHERE id = (v_item->>'menu_id')::BIGINT;
        v_unit := CASE WHEN v_menu.kategori IN ('paket a', 'paket b', 'paket c', 'nasi') THEN 'box' ELSE 'porsi' END;

        INSERT INTO detail_pesanan (pesanan_id, menu_id, nama_menu, unit, jumlah, harga_satuan, subtotal)
        VALUES (
            v_pesanan_id,
            v_menu.id,
            v_menu.nama,
            v_unit,
            (v_item->>'jumlah')::INTEGER,
            v_menu.harga,
            (v_item->>'jumlah')::INTEGER * v_menu.harga
        );
    END LOOP;

    -- 8. Update kuota_harian
    UPDATE kuota_harian
    SET terpakai = v_kuota_saat_ini + v_total_box,
        diperbarui_pada = NOW()
    WHERE tanggal = p_tanggal_ambil;

    RETURN jsonb_build_object(
        'ok', true,
        'id', v_pesanan_id,
        'kode', v_kode,
        'token_nota', v_token,
        'total_box', v_total_box,
        'total_harga', v_total_harga,
        'diskon', v_diskon,
        'nominal_bayar', v_nominal_bayar
    );
END;
$$;

-- =====================================================================
-- DATA AWAL (SEED DATA DEMO)
-- =====================================================================

-- 1. Seed Konfigurasi Toko
INSERT INTO konfigurasi_toko (kunci, nilai) VALUES
    ('qris_gambar', 'qris_warteg.svg'),
    ('qris_nama_merchant', 'Warteg Pak Sanam'),
    ('qris_nmid', 'ID1024000000001'),
    ('qris_instruksi', 'Scan kode QRIS di atas dengan aplikasi pembayaran apa pun (BCA mobile, Livin, BRImo, GoPay, OVO, DANA, ShopeePay), masukkan nominal sesuai total tagihan, lalu unggah tangkapan layar bukti pembayaran di halaman ini.'),
    ('batas_bayar_jam', '24')
ON CONFLICT (kunci) DO UPDATE SET nilai = EXCLUDED.nilai;

-- 2. Seed Akun Admin Default (Username: admin | Password: admin123)
INSERT INTO admin (username, password_hash, nama, role, jabatan, no_hp, email)
VALUES (
    'admin',
    'admin123',
    'Pengelola Warteg Pak Sanam',
    'owner',
    'Pemilik & Pengelola Utama',
    '0857-8342-3626',
    'owner@wartegpaksanam.id'
)
ON CONFLICT (username) DO NOTHING;

-- 3. Seed Katalog Menu Default (Jika tabel menu masih kosong)
DO $$
BEGIN
    IF NOT EXISTS (SELECT 1 FROM menu LIMIT 1) THEN
        INSERT INTO menu (nama, deskripsi, harga, kategori, foto, stok_default, aktif) VALUES
        ('Paket A - Ayam Goreng Lengkuas', 'Nasi Putih + Ayam Lengkuas + Tempe Orek + Buncis + Sambal + Kerupuk', 25000, 'paket a', 'menu_1776005851_7368.jpg', 50, true),
        ('Paket B - Rendang Daging Sapi', 'Nasi Putih + Rendang Sapi Empuk + Sayur Daun Singkong + Telur Dadar + Sambal Ijo', 32000, 'paket b', 'menu_1776007200_1321.jpg', 40, true),
        ('Paket C - Ikan Tongkol Balado', 'Nasi Putih + Tongkol Suwir Balado + Sayur Urap + Bakwan Jagung + Sambal', 24000, 'paket c', 'menu_1776007290_3103.jpg', 50, true),
        ('Nasi Putih Pulen', 'Nasi putih hangat porsi katering box sekat', 6000, 'nasi', 'menu_1776007978_5646.jpeg', 120, true),
        ('Nasi Uduk Gurih', 'Nasi uduk santan rempah daun salam dengan bawang goreng', 8000, 'nasi', 'menu_1776008161_7755.jpg', 80, true),
        ('Ayam Bakar Madu', 'Ayam potong bakar bumbu madu gurih manis khas Pak Sanam', 15000, 'lauk', 'menu_1776005884_9385.jpg', 60, true),
        ('Telur Balado Utuh', 'Telur ayam rebus bumbu balado merah pedas sedang', 7000, 'lauk', 'menu_1776005929_7844.jpg', 80, true),
        ('Perkedel Kentang Daging', 'Perkedel kentang tumbuk lembut dengan campuran daging cincang', 6000, 'lauk', 'menu_1776007136_4103.jpg', 70, true),
        ('Cumi Asin Cabe Ijo', 'Tumis cumi asin empuk dengan irisan cabai hijau besar dan tomat', 14000, 'lauk', 'menu_1776007418_5901.jpg', 50, true),
        ('Tumis Kangkung Belacan', 'Sayur kangkung segar tumis bawang putih dan terasi harum', 6000, 'sayur', 'menu_1776008057_7600.jpg', 70, true),
        ('Sayur Sop Ayam Segar', 'Sop wortel, kentang, buncis, kol dengan kaldu ayam gurih', 7000, 'sayur', 'menu_1776008256_4289.jpeg', 60, true),
        ('Orek Tempe Kacang Teri', 'Tempe iris tipis masak kecap manis dengan kacang tanah dan teri medan', 6000, 'sayur', 'menu_1776008389_4550.jpg', 80, true);
    END IF;
END $$;
