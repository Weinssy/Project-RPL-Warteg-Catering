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
ALTER TABLE menu ADD COLUMN IF NOT EXISTS stok_default INTEGER NULL;
ALTER TABLE menu ADD COLUMN IF NOT EXISTS aktif BOOLEAN NOT NULL DEFAULT TRUE;
ALTER TABLE menu ADD COLUMN IF NOT EXISTS foto TEXT NULL;
CREATE UNIQUE INDEX IF NOT EXISTS idx_menu_nama_unique ON menu (nama);

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
ALTER TABLE admin ADD COLUMN IF NOT EXISTS jabatan TEXT NULL;
ALTER TABLE admin ADD COLUMN IF NOT EXISTS no_hp TEXT NULL;
ALTER TABLE admin ADD COLUMN IF NOT EXISTS telepon TEXT NULL;
ALTER TABLE admin ADD COLUMN IF NOT EXISTS email TEXT NULL;
ALTER TABLE admin ADD COLUMN IF NOT EXISTS foto TEXT NULL;
ALTER TABLE admin ADD COLUMN IF NOT EXISTS terakhir_login TIMESTAMPTZ NULL;
UPDATE admin SET no_hp = telepon WHERE no_hp IS NULL AND telepon IS NOT NULL;

-- 4. TABEL KUOTA HARIAN (Pengunci Kapasitas 120 Box / Hari)
CREATE TABLE IF NOT EXISTS kuota_harian (
    tanggal DATE PRIMARY KEY,
    terpakai INTEGER NOT NULL DEFAULT 0,
    diperbarui_pada TIMESTAMPTZ NOT NULL DEFAULT NOW()
);
ALTER TABLE kuota_harian ADD COLUMN IF NOT EXISTS terpakai INTEGER NOT NULL DEFAULT 0;
ALTER TABLE kuota_harian ADD COLUMN IF NOT EXISTS diperbarui_pada TIMESTAMPTZ NOT NULL DEFAULT NOW();

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
ALTER TABLE pesanan ADD COLUMN IF NOT EXISTS nominal_bayar INTEGER NULL;
ALTER TABLE pesanan ADD COLUMN IF NOT EXISTS bukti_bayar TEXT NULL;
ALTER TABLE pesanan ADD COLUMN IF NOT EXISTS bukti_bayar_pada TIMESTAMPTZ NULL;
ALTER TABLE pesanan ADD COLUMN IF NOT EXISTS diverifikasi_oleh BIGINT NULL;
ALTER TABLE pesanan ADD COLUMN IF NOT EXISTS diverifikasi_pada TIMESTAMPTZ NULL;
ALTER TABLE pesanan ADD COLUMN IF NOT EXISTS alasan_tolak TEXT NULL;
ALTER TABLE pesanan ADD COLUMN IF NOT EXISTS qris_payload TEXT NULL;
ALTER TABLE pesanan ADD COLUMN IF NOT EXISTS bayar_kedaluwarsa_pada TIMESTAMPTZ NULL;
ALTER TABLE pesanan ADD COLUMN IF NOT EXISTS dibayar_pada TIMESTAMPTZ NULL;
ALTER TABLE pesanan ADD COLUMN IF NOT EXISTS diubah_pada TIMESTAMPTZ NULL;
ALTER TABLE pesanan ADD COLUMN IF NOT EXISTS diserahkan_pada TIMESTAMPTZ NULL;
ALTER TABLE pesanan ADD COLUMN IF NOT EXISTS token_nota TEXT NULL;

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

ALTER TABLE pesanan DROP CONSTRAINT IF EXISTS pesanan_status_bayar_check;
ALTER TABLE pesanan ADD CONSTRAINT pesanan_status_bayar_check CHECK (
    status_bayar IN (
        'belum_bayar',
        'menunggu',
        'menunggu_verifikasi',
        'berhasil',
        'ditolak',
        'gagal',
        'kedaluwarsa',
        'dibatalkan',
        'lunas'
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
ALTER TABLE konfigurasi_toko ADD COLUMN IF NOT EXISTS diperbarui_pada TIMESTAMPTZ NOT NULL DEFAULT NOW();

-- 8. TABEL LOG VERIFIKASI PEMBAYARAN
CREATE TABLE IF NOT EXISTS verifikasi_log (
    id BIGSERIAL PRIMARY KEY,
    pesanan_id BIGINT NOT NULL REFERENCES pesanan(id) ON DELETE CASCADE,
    admin_id BIGINT NULL REFERENCES admin(id) ON DELETE SET NULL,
    aksi TEXT NOT NULL CHECK (aksi IN ('terima', 'setuju', 'tolak', 'unggah_bukti')),
    catatan TEXT NULL,
    dibuat_pada TIMESTAMPTZ NOT NULL DEFAULT NOW()
);
ALTER TABLE verifikasi_log ADD COLUMN IF NOT EXISTS catatan TEXT NULL;
ALTER TABLE verifikasi_log DROP CONSTRAINT IF EXISTS verifikasi_log_aksi_check;
ALTER TABLE verifikasi_log ADD CONSTRAINT verifikasi_log_aksi_check
    CHECK (aksi IN ('terima', 'setuju', 'tolak', 'unggah_bukti'));
DO $$
BEGIN
    IF EXISTS (
        SELECT 1 FROM information_schema.columns
        WHERE table_schema = 'public' AND table_name = 'verifikasi_log' AND column_name = 'kode_pesanan'
    ) THEN
        ALTER TABLE verifikasi_log ALTER COLUMN kode_pesanan DROP NOT NULL;
    END IF;
END $$;
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
-- HAK AKSES SCHEMA PUBLIC UNTUK ANON & AUTHENTICATED (POSTGREST)
-- =====================================================================
GRANT USAGE ON SCHEMA public TO anon, authenticated, service_role;
GRANT ALL ON ALL TABLES IN SCHEMA public TO anon, authenticated;
GRANT ALL ON ALL SEQUENCES IN SCHEMA public TO anon, authenticated;
GRANT ALL ON ALL ROUTINES IN SCHEMA public TO anon, authenticated;

ALTER DEFAULT PRIVILEGES IN SCHEMA public GRANT ALL ON TABLES TO anon, authenticated;
ALTER DEFAULT PRIVILEGES IN SCHEMA public GRANT ALL ON SEQUENCES TO anon, authenticated;
ALTER DEFAULT PRIVILEGES IN SCHEMA public GRANT ALL ON ROUTINES TO anon, authenticated;

-- =====================================================================
-- FUNGSI RPC TRANSAKSI CHECKOUT (ANTI-OVERBOOKING 120 BOX/HARI + VALIDASI)
-- =====================================================================
-- Hapus semua versi overload fungsi lama agar tidak bentrok tipe/nama parameter (42P13 / PGRST202)
DO $$
DECLARE
    r RECORD;
BEGIN
    FOR r IN
        SELECT oid::regprocedure AS sig
        FROM pg_proc
        WHERE proname = 'buat_pesanan_katering'
          AND pronamespace = 'public'::regnamespace
    LOOP
        EXECUTE 'DROP FUNCTION IF EXISTS ' || r.sig || ' CASCADE';
    END LOOP;
END $$;

CREATE OR REPLACE FUNCTION public.buat_pesanan_katering(
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
SET search_path = public
AS $$
DECLARE
    v_total_box INTEGER := 0;
    v_total_item_qty INTEGER := 0;
    v_total_harga INTEGER := 0;
    v_diskon INTEGER := 0;
    v_nominal_bayar INTEGER := 0;
    v_kuota_saat_ini INTEGER := 0;
    v_item RECORD;
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
    IF p_tanggal_ambil IS NULL OR p_tanggal_ambil < (CURRENT_DATE + 1) OR p_tanggal_ambil > (CURRENT_DATE + 30) THEN
        RETURN jsonb_build_object(
            'ok', false,
            'pesan', 'Tanggal pengambilan harus berada dalam rentang H-1 (besok) hingga 30 hari ke depan.'
        );
    END IF;

    IF p_jam_ambil IS NULL OR p_jam_ambil < '09:00:00'::time OR p_jam_ambil > '19:00:00'::time THEN
        RETURN jsonb_build_object(
            'ok', false,
            'pesan', 'Jam pengambilan harus berada dalam jam operasional dapur (09.00 - 19.00 WIB).'
        );
    END IF;

    IF p_items IS NULL OR jsonb_typeof(p_items) <> 'array' OR jsonb_array_length(p_items) = 0 THEN
        RETURN jsonb_build_object('ok', false, 'pesan', 'Pilih minimal satu menu katering.');
    END IF;

    -- 1. Kunci baris kuota_harian untuk tanggal tersebut agar aman dari race condition
    INSERT INTO kuota_harian (tanggal, terpakai)
    VALUES (p_tanggal_ambil, 0)
    ON CONFLICT (tanggal) DO NOTHING;

    PERFORM 1 FROM kuota_harian WHERE tanggal = p_tanggal_ambil FOR UPDATE;

    SELECT COALESCE(SUM(total_box), 0) INTO v_kuota_saat_ini
    FROM pesanan
    WHERE tanggal_ambil = p_tanggal_ambil
      AND status NOT IN ('Dibatalkan')
      AND status_bayar NOT IN ('gagal', 'kedaluwarsa', 'dibatalkan');

    -- 2. Hitung total box utama & validasi stok tiap menu (agregasi per menu_id)
    FOR v_item IN
        SELECT (elem->>'menu_id')::BIGINT AS menu_id,
               SUM(GREATEST(0, COALESCE((elem->>'jumlah')::INTEGER, 0)))::INTEGER AS jumlah
        FROM jsonb_array_elements(p_items) AS elem
        GROUP BY (elem->>'menu_id')::BIGINT
        HAVING SUM(GREATEST(0, COALESCE((elem->>'jumlah')::INTEGER, 0))) > 0
    LOOP
        SELECT * INTO v_menu FROM menu WHERE id = v_item.menu_id AND aktif = TRUE;
        IF NOT FOUND THEN
            RETURN jsonb_build_object('ok', false, 'pesan', 'Salah satu menu tidak ditemukan atau sedang nonaktif.');
        END IF;

        v_stok_efektif := v_menu.stok_default;
        SELECT tersedia, stok INTO v_mh FROM menu_harian WHERE tanggal = p_tanggal_ambil AND menu_id = v_menu.id;
        IF FOUND THEN
            IF v_mh.tersedia = FALSE THEN
                RETURN jsonb_build_object('ok', false, 'pesan', 'Menu "' || v_menu.nama || '" sedang tidak tersedia pada tanggal tersebut.');
            END IF;
            v_stok_efektif := COALESCE(v_mh.stok, v_menu.stok_default);
        END IF;

        IF v_stok_efektif IS NOT NULL THEN
            SELECT COALESCE(SUM(dp.jumlah), 0) INTO v_terjual
            FROM detail_pesanan dp
            JOIN pesanan p ON p.id = dp.pesanan_id
            WHERE p.tanggal_ambil = p_tanggal_ambil
              AND dp.menu_id = v_menu.id
              AND p.status NOT IN ('Dibatalkan')
              AND p.status_bayar NOT IN ('gagal', 'kedaluwarsa', 'dibatalkan');

            IF (v_terjual + v_item.jumlah) > v_stok_efektif THEN
                RETURN jsonb_build_object(
                    'ok', false,
                    'pesan', 'Stok "' || v_menu.nama || '" pada tanggal tersebut hanya tersisa ' || GREATEST(0, v_stok_efektif - v_terjual) || '.'
                );
            END IF;
        END IF;

        v_total_item_qty := v_total_item_qty + v_item.jumlah;
        IF v_menu.kategori IN ('paket a', 'paket b', 'paket c', 'nasi') OR v_menu.kategori LIKE 'paket%' THEN
            v_total_box := v_total_box + v_item.jumlah;
        END IF;
        v_total_harga := v_total_harga + (v_item.jumlah * v_menu.harga);
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

    -- 4. Buat Kode Pesanan Unik (WRT-YYYYMMDD-XXX) & Token Nota (core gen_random_uuid)
    LOOP
        v_kode := 'WRT-' || to_char(p_tanggal_ambil, 'YYYYMMDD') || '-' || lpad((floor(random() * 900) + 100)::TEXT, 3, '0');
        EXIT WHEN NOT EXISTS (SELECT 1 FROM pesanan WHERE kode = v_kode);
    END LOOP;
    v_token := replace(gen_random_uuid()::TEXT, '-', '');

    -- 5. Hitung Voucher Diskon 5% untuk pemesanan 80 box atau lebih
    IF v_total_box >= 80 THEN
        v_diskon := ROUND(v_total_harga * 0.05)::INTEGER;
    ELSE
        v_diskon := 0;
    END IF;
    v_nominal_bayar := GREATEST(0, v_total_harga - v_diskon);

    SELECT COALESCE(NULLIF(nilai, '')::INTEGER, 24) INTO v_batas_jam
    FROM konfigurasi_toko WHERE kunci = 'batas_bayar_jam';
    v_batas_jam := COALESCE(v_batas_jam, 24);

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

    -- 7. Simpan detail_pesanan beserta snapshot nama_menu & unit (agregasi per menu_id agar aman terhadap constraint unik)
    FOR v_item IN
        SELECT (elem->>'menu_id')::BIGINT AS menu_id,
               SUM(GREATEST(0, COALESCE((elem->>'jumlah')::INTEGER, 0)))::INTEGER AS jumlah
        FROM jsonb_array_elements(p_items) AS elem
        GROUP BY (elem->>'menu_id')::BIGINT
        HAVING SUM(GREATEST(0, COALESCE((elem->>'jumlah')::INTEGER, 0))) > 0
    LOOP
        SELECT * INTO v_menu FROM menu WHERE id = v_item.menu_id;
        v_unit := CASE
            WHEN v_menu.kategori IN ('paket a', 'paket b', 'paket c', 'nasi') OR v_menu.kategori LIKE 'paket%' THEN 'box'
            ELSE 'porsi'
        END;

        INSERT INTO detail_pesanan (pesanan_id, menu_id, nama_menu, unit, jumlah, harga_satuan, subtotal)
        VALUES (
            v_pesanan_id,
            v_menu.id,
            v_menu.nama,
            v_unit,
            v_item.jumlah,
            v_menu.harga,
            v_item.jumlah * v_menu.harga
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

GRANT EXECUTE ON FUNCTION public.buat_pesanan_katering(TEXT, TEXT, DATE, TIME, TEXT, JSONB) TO anon, authenticated, service_role;

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
ON CONFLICT (kunci) DO NOTHING;

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

-- 3. Seed Katalog Menu Baru Sesuai Produk Satuan (Foto Bersih & Mudah Diedit)
ALTER TABLE menu ADD COLUMN IF NOT EXISTS deskripsi TEXT NULL;
ALTER TABLE menu ADD COLUMN IF NOT EXISTS stok_default INTEGER NULL;
ALTER TABLE menu ADD COLUMN IF NOT EXISTS aktif BOOLEAN NOT NULL DEFAULT TRUE;
ALTER TABLE menu ADD COLUMN IF NOT EXISTS foto TEXT NULL;
CREATE UNIQUE INDEX IF NOT EXISTS idx_menu_nama_unique ON menu (nama);

INSERT INTO menu (nama, deskripsi, harga, kategori, foto, stok_default, aktif) VALUES
('Paket Ayam Goreng Sambal', 'Nasi Putih + Ayam Goreng Sambal + Tempe & Tahu + Lalapan Segar', 23000, 'paket a', 'ayam_goreng_sambal.jpg', 50, true),
('Paket Ayam Bakar Sambal', 'Nasi Putih + Ayam Bakar Madu Sambal + Tempe & Tahu + Lalapan Segar', 23000, 'paket b', 'ayam_bakar_sambal.jpg', 50, true),
('Paket Bebek Goreng Sambal', 'Nasi Putih + Bebek Goreng Krispi Sambal + Tempe & Tahu + Lalapan Segar', 30000, 'paket c', 'bebek_goreng_sambal.jpg', 40, true),
('Ayam Goreng (Lauk Saja)', 'Ayam goreng gurih renyah bumbu kuning meresap dengan lalapan timun', 18000, 'lauk', 'ayam_goreng.jpg', 60, true),
('Ayam Bakar (Lauk Saja)', 'Ayam bakar madu manis gurih aroma bakaran dengan lalapan timun', 18000, 'lauk', 'ayam_bakar.jpg', 60, true),
('Tempe & Tahu Goreng', 'Potongan tahu dan tempe goreng renyah bumbu ketumbar khas rumahan', 2000, 'lauk', 'tahu_tempe.jpg', 100, true),
('Kol Goreng Crispy', 'Kol goreng manis gurih renyah pelengkap nikmat ayam dan bebek', 5000, 'sayur', 'kol_goreng.jpg', 80, true),
('Es Teh / Teh Hangat', 'Teh seduh manis harum melati segar dingin atau hangat', 4000, 'sayur', 'es_teh.jpg', 120, true),
('Es Jeruk / Jeruk Hangat', 'Jeruk peras murni segar kaya vitamin C dingin atau hangat', 5000, 'sayur', 'es_jeruk.jpg', 100, true),
('Es Teler Premium', 'Es teler manis segar isi alpukat, kelapa muda, nangka, jeli & susu kental manis', 10000, 'sayur', 'es_teler.jpg', 50, true),
('Nasi Putih Pulen', 'Nasi putih hangat porsi katering box sekat', 6000, 'nasi', 'ayam_goreng_sambal.jpg', 120, true),
('Nasi Uduk Gurih', 'Nasi uduk santan rempah daun salam dengan taburan bawang goreng', 8000, 'nasi', 'ayam_bakar_sambal.jpg', 80, true)
ON CONFLICT (nama) DO NOTHING;

-- 4. Pastikan kolom audit dan relasi aktivitas admin mendukung log upaya login gagal
ALTER TABLE IF EXISTS aktivitas_admin ALTER COLUMN admin_id DROP NOT NULL;

-- 5. Reload Schema Cache PostgREST Supabase secara instan
NOTIFY pgrst, 'reload schema';
