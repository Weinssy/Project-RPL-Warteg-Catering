-- =====================================================================
-- SKEMA DATABASE SUPABASE (POSTGRESQL)
-- Sistem Katering & POS Warteg Pak Sanam (Versi GitHub Pages + Supabase)
-- Cara Pakai: Salin seluruh isi file ini dan jalankan di Supabase SQL Editor
-- =====================================================================

CREATE EXTENSION IF NOT EXISTS pgcrypto;

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
    admin_id BIGINT NULL REFERENCES admin(id) ON DELETE CASCADE,
    aksi TEXT NOT NULL,
    keterangan TEXT NULL,
    dibuat_pada TIMESTAMPTZ NOT NULL DEFAULT NOW()
);
CREATE INDEX IF NOT EXISTS idx_aktivitas_admin ON aktivitas_admin (admin_id, dibuat_pada DESC);

-- 10. TABEL SESI ADMIN (Token Autentikasi Server-Side)
CREATE TABLE IF NOT EXISTS admin_sessions (
    token TEXT PRIMARY KEY,
    admin_id BIGINT NOT NULL REFERENCES admin(id) ON DELETE CASCADE,
    kadaluwarsa_pada TIMESTAMPTZ NOT NULL,
    dibuat_pada TIMESTAMPTZ NOT NULL DEFAULT NOW()
);
CREATE INDEX IF NOT EXISTS idx_admin_sessions_admin ON admin_sessions (admin_id, kadaluwarsa_pada DESC);

-- =====================================================================
-- KEBIJAKAN ROW LEVEL SECURITY (RLS) KETAT UNTUK GITHUB PAGES
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
ALTER TABLE admin_sessions ENABLE ROW LEVEL SECURITY;

-- Hapus kebijakan lama yang terlalu permisif
DROP POLICY IF EXISTS "public_all_menu" ON menu;
DROP POLICY IF EXISTS "public_all_menu_harian" ON menu_harian;
DROP POLICY IF EXISTS "public_all_admin" ON admin;
DROP POLICY IF EXISTS "public_all_kuota_harian" ON kuota_harian;
DROP POLICY IF EXISTS "public_all_pesanan" ON pesanan;
DROP POLICY IF EXISTS "public_all_detail_pesanan" ON detail_pesanan;
DROP POLICY IF EXISTS "public_all_konfigurasi_toko" ON konfigurasi_toko;
DROP POLICY IF EXISTS "public_all_verifikasi_log" ON verifikasi_log;
DROP POLICY IF EXISTS "public_all_aktivitas_admin" ON aktivitas_admin;

-- Tabel sensitif (akun admin, token sesi, log internal): blokir akses REST langsung dari anon/publik
DROP POLICY IF EXISTS "deny_all_admin" ON admin;
CREATE POLICY "deny_all_admin" ON admin FOR ALL USING (false) WITH CHECK (false);

DROP POLICY IF EXISTS "deny_all_admin_sessions" ON admin_sessions;
CREATE POLICY "deny_all_admin_sessions" ON admin_sessions FOR ALL USING (false) WITH CHECK (false);

DROP POLICY IF EXISTS "deny_all_aktivitas_admin" ON aktivitas_admin;
CREATE POLICY "deny_all_aktivitas_admin" ON aktivitas_admin FOR ALL USING (false) WITH CHECK (false);

DROP POLICY IF EXISTS "deny_all_verifikasi_log" ON verifikasi_log;
CREATE POLICY "deny_all_verifikasi_log" ON verifikasi_log FOR ALL USING (false) WITH CHECK (false);

-- Tabel katalog, kuota, konfigurasi QRIS, & pesanan: hanya baca (SELECT) di REST; semua tulis wajib lewat RPC SECURITY DEFINER
DROP POLICY IF EXISTS "public_read_menu" ON menu;
CREATE POLICY "public_read_menu" ON menu FOR SELECT USING (true);

DROP POLICY IF EXISTS "public_read_menu_harian" ON menu_harian;
CREATE POLICY "public_read_menu_harian" ON menu_harian FOR SELECT USING (true);

DROP POLICY IF EXISTS "public_read_kuota_harian" ON kuota_harian;
CREATE POLICY "public_read_kuota_harian" ON kuota_harian FOR SELECT USING (true);

DROP POLICY IF EXISTS "public_read_konfigurasi_toko" ON konfigurasi_toko;
CREATE POLICY "public_read_konfigurasi_toko" ON konfigurasi_toko FOR SELECT USING (true);

DROP POLICY IF EXISTS "public_read_pesanan" ON pesanan;
CREATE POLICY "public_read_pesanan" ON pesanan FOR SELECT USING (true);

DROP POLICY IF EXISTS "public_read_detail_pesanan" ON detail_pesanan;
CREATE POLICY "public_read_detail_pesanan" ON detail_pesanan FOR SELECT USING (true);

-- =====================================================================
-- HAK AKSES SCHEMA PUBLIC (PRINSIP LEAST PRIVILEGE)
-- =====================================================================
GRANT USAGE ON SCHEMA public TO anon, authenticated, service_role;
REVOKE ALL ON ALL TABLES IN SCHEMA public FROM anon, authenticated;
GRANT SELECT ON TABLE menu, menu_harian, kuota_harian, konfigurasi_toko, pesanan, detail_pesanan TO anon, authenticated;
GRANT ALL ON ALL TABLES IN SCHEMA public TO service_role;
GRANT ALL ON ALL SEQUENCES IN SCHEMA public TO service_role;

ALTER DEFAULT PRIVILEGES IN SCHEMA public REVOKE ALL ON TABLES FROM anon, authenticated;
ALTER DEFAULT PRIVILEGES IN SCHEMA public GRANT EXECUTE ON FUNCTIONS TO anon, authenticated, service_role;

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
-- FUNGSI RPC PELANGGAN: UNGGAH BUKTI & KEDALUWARSA OTOMATIS
-- =====================================================================
CREATE OR REPLACE FUNCTION public.unggah_bukti_pembayaran(
    p_kode TEXT,
    p_token TEXT,
    p_bukti_bayar TEXT
)
RETURNS JSONB
LANGUAGE plpgsql
SECURITY DEFINER
SET search_path = public, extensions
AS $$
DECLARE
    v_pesanan RECORD;
BEGIN
    IF NULLIF(trim(COALESCE(p_kode, '')), '') IS NULL
       OR NULLIF(trim(COALESCE(p_token, '')), '') IS NULL
       OR NULLIF(trim(COALESCE(p_bukti_bayar, '')), '') IS NULL THEN
        RETURN jsonb_build_object('ok', false, 'pesan', 'Data bukti pembayaran tidak lengkap.');
    END IF;

    SELECT * INTO v_pesanan
    FROM pesanan
    WHERE kode = trim(p_kode)
      AND token_nota = trim(p_token)
    FOR UPDATE;

    IF NOT FOUND THEN
        RETURN jsonb_build_object('ok', false, 'pesan', 'Pesanan tidak ditemukan atau token tidak valid.');
    END IF;

    IF v_pesanan.status = 'Dibatalkan'
       OR v_pesanan.status_bayar IN ('kedaluwarsa', 'dibatalkan') THEN
        RETURN jsonb_build_object('ok', false, 'pesan', 'Pesanan ini sudah dibatalkan atau melewati batas waktu pembayaran.');
    END IF;

    IF v_pesanan.status_bayar IN ('berhasil', 'lunas') THEN
        RETURN jsonb_build_object('ok', false, 'pesan', 'Pembayaran pesanan ini sudah terverifikasi lunas.');
    END IF;

    UPDATE pesanan
    SET bukti_bayar = p_bukti_bayar,
        bukti_bayar_pada = NOW(),
        status_bayar = 'menunggu_verifikasi',
        alasan_tolak = NULL,
        diubah_pada = NOW()
    WHERE id = v_pesanan.id;

    INSERT INTO verifikasi_log (pesanan_id, aksi, catatan)
    VALUES (v_pesanan.id, 'unggah_bukti', 'Pelanggan mengunggah bukti pembayaran QRIS.');

    RETURN jsonb_build_object('ok', true);
END;
$$;

GRANT EXECUTE ON FUNCTION public.unggah_bukti_pembayaran(TEXT, TEXT, TEXT) TO anon, authenticated, service_role;

CREATE OR REPLACE FUNCTION public.tandai_pesanan_kedaluwarsa(
    p_id BIGINT,
    p_token TEXT
)
RETURNS JSONB
LANGUAGE plpgsql
SECURITY DEFINER
SET search_path = public, extensions
AS $$
DECLARE
    v_updated INTEGER := 0;
BEGIN
    IF p_id IS NULL OR NULLIF(trim(COALESCE(p_token, '')), '') IS NULL THEN
        RETURN jsonb_build_object('ok', false);
    END IF;

    UPDATE pesanan
    SET status_bayar = 'kedaluwarsa',
        status = 'Dibatalkan',
        diubah_pada = NOW()
    WHERE id = p_id
      AND token_nota = trim(p_token)
      AND status_bayar IN ('belum_bayar', 'menunggu')
      AND bayar_kedaluwarsa_pada IS NOT NULL
      AND bayar_kedaluwarsa_pada < NOW()
      AND status <> 'Dibatalkan';

    GET DIAGNOSTICS v_updated = ROW_COUNT;
    RETURN jsonb_build_object('ok', v_updated > 0);
END;
$$;

GRANT EXECUTE ON FUNCTION public.tandai_pesanan_kedaluwarsa(BIGINT, TEXT) TO anon, authenticated, service_role;

-- =====================================================================
-- FUNGSI RPC AUTENTIKASI & OPERASIONAL ADMIN (SERVER-SIDE TOKEN + BCRYPT)
-- =====================================================================
CREATE OR REPLACE FUNCTION public._verify_admin_session(
    p_token TEXT,
    p_require_owner BOOLEAN DEFAULT FALSE
)
RETURNS public.admin
LANGUAGE plpgsql
SECURITY DEFINER
SET search_path = public, extensions
AS $$
DECLARE
    v_adm public.admin%ROWTYPE;
BEGIN
    IF NULLIF(trim(COALESCE(p_token, '')), '') IS NULL THEN
        RETURN NULL;
    END IF;

    SELECT a.* INTO v_adm
    FROM admin_sessions s
    JOIN admin a ON a.id = s.admin_id
    WHERE s.token = trim(p_token)
      AND s.kadaluwarsa_pada > NOW();

    IF NOT FOUND THEN
        RETURN NULL;
    END IF;

    IF p_require_owner AND v_adm.role <> 'owner' THEN
        RETURN NULL;
    END IF;

    RETURN v_adm;
END;
$$;

REVOKE ALL ON FUNCTION public._verify_admin_session(TEXT, BOOLEAN) FROM PUBLIC, anon, authenticated;

CREATE OR REPLACE FUNCTION public.login_admin(
    p_username TEXT,
    p_password TEXT
)
RETURNS JSONB
LANGUAGE plpgsql
SECURITY DEFINER
SET search_path = public, extensions
AS $$
DECLARE
    v_adm public.admin%ROWTYPE;
    v_user TEXT := trim(COALESCE(p_username, ''));
    v_pass TEXT := COALESCE(p_password, '');
    v_match BOOLEAN := FALSE;
    v_token TEXT;
    v_now TIMESTAMPTZ := NOW();
    v_legacy_bcrypt CONSTANT TEXT := '$2y$10$92IXUNpkjO0rOQ5byMi.Ye4oKoEa3Ro9llC/.og/at2.uheWG/igi';
BEGIN
    DELETE FROM admin_sessions WHERE kadaluwarsa_pada <= v_now;

    IF v_user = '' OR v_pass = '' THEN
        RETURN jsonb_build_object('ok', false, 'pesan', 'Masukkan username dan password.');
    END IF;

    SELECT * INTO v_adm FROM admin WHERE username = v_user;
    IF NOT FOUND THEN
        INSERT INTO aktivitas_admin (admin_id, aksi, keterangan, dibuat_pada)
        VALUES (NULL, 'login_gagal', 'Percobaan login gagal untuk username: ' || left(v_user, 30), v_now);
        RETURN jsonb_build_object('ok', false, 'pesan', 'Username atau password salah.');
    END IF;

    IF v_adm.password_hash = v_legacy_bcrypt AND v_pass = 'admin123' THEN
        v_match := TRUE;
    ELSIF v_adm.password_hash LIKE '$2%' THEN
        v_match := (v_adm.password_hash = crypt(v_pass, v_adm.password_hash));
    ELSE
        v_match := (v_adm.password_hash = v_pass);
    END IF;

    IF NOT v_match THEN
        INSERT INTO aktivitas_admin (admin_id, aksi, keterangan, dibuat_pada)
        VALUES (v_adm.id, 'login_gagal', 'Percobaan login gagal untuk username: ' || left(v_user, 30), v_now);
        RETURN jsonb_build_object('ok', false, 'pesan', 'Username atau password salah.');
    END IF;

    -- Upgrade otomatis hash lama / plaintext ke bcrypt saat login berhasil
    IF v_adm.password_hash NOT LIKE '$2%' OR v_adm.password_hash = v_legacy_bcrypt THEN
        UPDATE admin
        SET password_hash = crypt(v_pass, gen_salt('bf'))
        WHERE id = v_adm.id;
    END IF;

    v_token := encode(gen_random_bytes(32), 'hex');
    INSERT INTO admin_sessions (token, admin_id, kadaluwarsa_pada, dibuat_pada)
    VALUES (v_token, v_adm.id, v_now + INTERVAL '8 hours', v_now);

    UPDATE admin SET terakhir_login = v_now WHERE id = v_adm.id;

    INSERT INTO aktivitas_admin (admin_id, aksi, keterangan, dibuat_pada)
    VALUES (v_adm.id, 'login', 'Login berhasil ke dashboard admin', v_now);

    RETURN jsonb_build_object(
        'ok', true,
        'token', v_token,
        'admin', jsonb_build_object(
            'id', v_adm.id,
            'username', v_adm.username,
            'nama', v_adm.nama,
            'role', v_adm.role,
            'email', COALESCE(v_adm.email, ''),
            'no_hp', COALESCE(v_adm.no_hp, ''),
            'jabatan', COALESCE(v_adm.jabatan, ''),
            'foto', COALESCE(v_adm.foto, ''),
            'terakhir_login', v_now,
            'dibuat_pada', v_adm.dibuat_pada
        )
    );
END;
$$;

GRANT EXECUTE ON FUNCTION public.login_admin(TEXT, TEXT) TO anon, authenticated, service_role;

CREATE OR REPLACE FUNCTION public.logout_admin_session(p_token TEXT)
RETURNS JSONB
LANGUAGE plpgsql
SECURITY DEFINER
SET search_path = public, extensions
AS $$
BEGIN
    IF NULLIF(trim(COALESCE(p_token, '')), '') IS NOT NULL THEN
        DELETE FROM admin_sessions WHERE token = trim(p_token);
    END IF;
    RETURN jsonb_build_object('ok', true);
END;
$$;

GRANT EXECUTE ON FUNCTION public.logout_admin_session(TEXT) TO anon, authenticated, service_role;

CREATE OR REPLACE FUNCTION public.admin_catat_aktivitas(
    p_token TEXT,
    p_aksi TEXT,
    p_keterangan TEXT
)
RETURNS JSONB
LANGUAGE plpgsql
SECURITY DEFINER
SET search_path = public, extensions
AS $$
DECLARE
    v_adm public.admin%ROWTYPE;
BEGIN
    v_adm := public._verify_admin_session(p_token, FALSE);
    IF v_adm.id IS NULL THEN
        RETURN jsonb_build_object('ok', false, 'auth_expired', true);
    END IF;

    INSERT INTO aktivitas_admin (admin_id, aksi, keterangan)
    VALUES (v_adm.id, COALESCE(NULLIF(trim(p_aksi), ''), 'aksi_admin'), COALESCE(p_keterangan, ''));

    RETURN jsonb_build_object('ok', true);
END;
$$;

GRANT EXECUTE ON FUNCTION public.admin_catat_aktivitas(TEXT, TEXT, TEXT) TO anon, authenticated, service_role;

CREATE OR REPLACE FUNCTION public.admin_daftar_akun(p_token TEXT)
RETURNS JSONB
LANGUAGE plpgsql
SECURITY DEFINER
SET search_path = public, extensions
AS $$
DECLARE
    v_adm public.admin%ROWTYPE;
    v_items JSONB;
BEGIN
    v_adm := public._verify_admin_session(p_token, TRUE);
    IF v_adm.id IS NULL THEN
        RETURN jsonb_build_object('ok', false, 'auth_expired', true, 'pesan', 'Akses ditolak. Sesi owner tidak valid atau telah berakhir.');
    END IF;

    SELECT COALESCE(jsonb_agg(jsonb_build_object(
        'id', a.id,
        'username', a.username,
        'nama', a.nama,
        'role', a.role,
        'email', COALESCE(a.email, ''),
        'no_hp', COALESCE(a.no_hp, ''),
        'jabatan', COALESCE(a.jabatan, ''),
        'foto', COALESCE(a.foto, ''),
        'terakhir_login', a.terakhir_login,
        'dibuat_pada', a.dibuat_pada
    ) ORDER BY a.id ASC), '[]'::jsonb)
    INTO v_items
    FROM admin a;

    RETURN jsonb_build_object('ok', true, 'items', v_items);
END;
$$;

GRANT EXECUTE ON FUNCTION public.admin_daftar_akun(TEXT) TO anon, authenticated, service_role;

CREATE OR REPLACE FUNCTION public.admin_tambah_akun(
    p_token TEXT,
    p_username TEXT,
    p_nama TEXT,
    p_password TEXT,
    p_role TEXT DEFAULT 'admin'
)
RETURNS JSONB
LANGUAGE plpgsql
SECURITY DEFINER
SET search_path = public, extensions
AS $$
DECLARE
    v_adm public.admin%ROWTYPE;
    v_user TEXT := trim(COALESCE(p_username, ''));
    v_nama TEXT := trim(COALESCE(p_nama, ''));
    v_pass TEXT := COALESCE(p_password, '');
    v_role TEXT := CASE WHEN p_role = 'owner' THEN 'owner' ELSE 'admin' END;
BEGIN
    v_adm := public._verify_admin_session(p_token, TRUE);
    IF v_adm.id IS NULL THEN
        RETURN jsonb_build_object('ok', false, 'auth_expired', true, 'pesan', 'Akses ditolak. Hanya owner aktif yang dapat menambah akun.');
    END IF;

    IF v_user = '' OR v_nama = '' OR length(v_pass) < 6 THEN
        RETURN jsonb_build_object('ok', false, 'pesan', 'Username, nama lengkap, dan password (minimal 6 karakter) wajib diisi.');
    END IF;

    IF EXISTS (SELECT 1 FROM admin WHERE username = v_user) THEN
        RETURN jsonb_build_object('ok', false, 'pesan', 'Username sudah digunakan oleh akun lain.');
    END IF;

    INSERT INTO admin (username, password_hash, nama, role, jabatan)
    VALUES (
        v_user,
        crypt(v_pass, gen_salt('bf')),
        v_nama,
        v_role,
        CASE WHEN v_role = 'owner' THEN 'Pemilik & Pengelola' ELSE 'Staf Admin Operasional' END
    );

    INSERT INTO aktivitas_admin (admin_id, aksi, keterangan)
    VALUES (v_adm.id, 'tambah_admin', 'Menambahkan akun admin baru: @' || v_user || ' (' || v_role || ')');

    RETURN jsonb_build_object('ok', true);
END;
$$;

GRANT EXECUTE ON FUNCTION public.admin_tambah_akun(TEXT, TEXT, TEXT, TEXT, TEXT) TO anon, authenticated, service_role;

CREATE OR REPLACE FUNCTION public.admin_hapus_akun(
    p_token TEXT,
    p_target_id BIGINT
)
RETURNS JSONB
LANGUAGE plpgsql
SECURITY DEFINER
SET search_path = public, extensions
AS $$
DECLARE
    v_adm public.admin%ROWTYPE;
    v_target public.admin%ROWTYPE;
    v_owner_count INTEGER := 0;
BEGIN
    v_adm := public._verify_admin_session(p_token, TRUE);
    IF v_adm.id IS NULL THEN
        RETURN jsonb_build_object('ok', false, 'auth_expired', true, 'pesan', 'Akses ditolak. Hanya owner aktif yang dapat menghapus akun.');
    END IF;

    IF v_adm.id = p_target_id THEN
        RETURN jsonb_build_object('ok', false, 'pesan', 'Tidak dapat menghapus akun yang sedang Anda gunakan.');
    END IF;

    SELECT * INTO v_target FROM admin WHERE id = p_target_id;
    IF NOT FOUND THEN
        RETURN jsonb_build_object('ok', false, 'pesan', 'Akun admin tidak ditemukan.');
    END IF;

    IF v_target.role = 'owner' THEN
        SELECT COUNT(*) INTO v_owner_count FROM admin WHERE role = 'owner';
        IF v_owner_count <= 1 THEN
            RETURN jsonb_build_object('ok', false, 'pesan', 'Tidak dapat menghapus satu-satunya akun Owner.');
        END IF;
    END IF;

    DELETE FROM admin WHERE id = p_target_id;

    INSERT INTO aktivitas_admin (admin_id, aksi, keterangan)
    VALUES (v_adm.id, 'hapus_admin', 'Menghapus akun admin: @' || v_target.username);

    RETURN jsonb_build_object('ok', true);
END;
$$;

GRANT EXECUTE ON FUNCTION public.admin_hapus_akun(TEXT, BIGINT) TO anon, authenticated, service_role;

CREATE OR REPLACE FUNCTION public.admin_update_profil(
    p_token TEXT,
    p_nama TEXT,
    p_username TEXT,
    p_jabatan TEXT,
    p_email TEXT,
    p_no_hp TEXT,
    p_foto TEXT DEFAULT NULL
)
RETURNS JSONB
LANGUAGE plpgsql
SECURITY DEFINER
SET search_path = public, extensions
AS $$
DECLARE
    v_adm public.admin%ROWTYPE;
    v_nama TEXT := trim(COALESCE(p_nama, ''));
    v_user TEXT := trim(COALESCE(p_username, ''));
    v_updated public.admin%ROWTYPE;
BEGIN
    v_adm := public._verify_admin_session(p_token, FALSE);
    IF v_adm.id IS NULL THEN
        RETURN jsonb_build_object('ok', false, 'auth_expired', true, 'pesan', 'Sesi login telah berakhir.');
    END IF;

    IF v_nama = '' OR v_user = '' THEN
        RETURN jsonb_build_object('ok', false, 'pesan', 'Nama lengkap dan username wajib diisi.');
    END IF;

    IF EXISTS (SELECT 1 FROM admin WHERE username = v_user AND id <> v_adm.id) THEN
        RETURN jsonb_build_object('ok', false, 'pesan', 'Username sudah digunakan oleh akun lain.');
    END IF;

    UPDATE admin
    SET nama = v_nama,
        username = v_user,
        jabatan = COALESCE(trim(p_jabatan), ''),
        email = COALESCE(trim(p_email), ''),
        no_hp = COALESCE(trim(p_no_hp), ''),
        foto = COALESCE(NULLIF(trim(COALESCE(p_foto, '')), ''), foto)
    WHERE id = v_adm.id
    RETURNING * INTO v_updated;

    INSERT INTO aktivitas_admin (admin_id, aksi, keterangan)
    VALUES (v_adm.id, 'update_profil', 'Memperbarui profil & kontak akun admin');

    RETURN jsonb_build_object(
        'ok', true,
        'admin', jsonb_build_object(
            'id', v_updated.id,
            'username', v_updated.username,
            'nama', v_updated.nama,
            'role', v_updated.role,
            'email', COALESCE(v_updated.email, ''),
            'no_hp', COALESCE(v_updated.no_hp, ''),
            'jabatan', COALESCE(v_updated.jabatan, ''),
            'foto', COALESCE(v_updated.foto, ''),
            'terakhir_login', v_updated.terakhir_login,
            'dibuat_pada', v_updated.dibuat_pada
        )
    );
END;
$$;

GRANT EXECUTE ON FUNCTION public.admin_update_profil(TEXT, TEXT, TEXT, TEXT, TEXT, TEXT, TEXT) TO anon, authenticated, service_role;

CREATE OR REPLACE FUNCTION public.admin_ubah_password(
    p_token TEXT,
    p_password_lama TEXT,
    p_password_baru TEXT
)
RETURNS JSONB
LANGUAGE plpgsql
SECURITY DEFINER
SET search_path = public, extensions
AS $$
DECLARE
    v_adm public.admin%ROWTYPE;
    v_old TEXT := COALESCE(p_password_lama, '');
    v_new TEXT := COALESCE(p_password_baru, '');
    v_match BOOLEAN := FALSE;
    v_legacy_bcrypt CONSTANT TEXT := '$2y$10$92IXUNpkjO0rOQ5byMi.Ye4oKoEa3Ro9llC/.og/at2.uheWG/igi';
BEGIN
    v_adm := public._verify_admin_session(p_token, FALSE);
    IF v_adm.id IS NULL THEN
        RETURN jsonb_build_object('ok', false, 'auth_expired', true, 'pesan', 'Sesi login telah berakhir.');
    END IF;

    IF v_old = '' OR length(v_new) < 6 THEN
        RETURN jsonb_build_object('ok', false, 'pesan', 'Password baru minimal terdiri dari 6 karakter.');
    END IF;

    IF v_adm.password_hash = v_legacy_bcrypt AND v_old = 'admin123' THEN
        v_match := TRUE;
    ELSIF v_adm.password_hash LIKE '$2%' THEN
        v_match := (v_adm.password_hash = crypt(v_old, v_adm.password_hash));
    ELSE
        v_match := (v_adm.password_hash = v_old);
    END IF;

    IF NOT v_match THEN
        RETURN jsonb_build_object('ok', false, 'pesan', 'Password saat ini tidak sesuai.');
    END IF;

    UPDATE admin
    SET password_hash = crypt(v_new, gen_salt('bf'))
    WHERE id = v_adm.id;

    INSERT INTO aktivitas_admin (admin_id, aksi, keterangan)
    VALUES (v_adm.id, 'ubah_password', 'Mengubah kata sandi akun admin');

    RETURN jsonb_build_object('ok', true, 'pesan', 'Password akun berhasil diperbarui.');
END;
$$;

GRANT EXECUTE ON FUNCTION public.admin_ubah_password(TEXT, TEXT, TEXT) TO anon, authenticated, service_role;

CREATE OR REPLACE FUNCTION public.admin_simpan_konfigurasi(
    p_token TEXT,
    p_items JSONB
)
RETURNS JSONB
LANGUAGE plpgsql
SECURITY DEFINER
SET search_path = public, extensions
AS $$
DECLARE
    v_adm public.admin%ROWTYPE;
    v_rec RECORD;
BEGIN
    v_adm := public._verify_admin_session(p_token, TRUE);
    IF v_adm.id IS NULL THEN
        RETURN jsonb_build_object('ok', false, 'auth_expired', true, 'pesan', 'Akses ditolak. Hanya owner aktif yang dapat mengubah konfigurasi toko.');
    END IF;

    IF p_items IS NULL OR jsonb_typeof(p_items) <> 'object' THEN
        RETURN jsonb_build_object('ok', false, 'pesan', 'Format konfigurasi tidak valid.');
    END IF;

    FOR v_rec IN SELECT key, value FROM jsonb_each_text(p_items) LOOP
        INSERT INTO konfigurasi_toko (kunci, nilai, diperbarui_pada)
        VALUES (v_rec.key, COALESCE(v_rec.value, ''), NOW())
        ON CONFLICT (kunci) DO UPDATE
        SET nilai = EXCLUDED.nilai,
            diperbarui_pada = NOW();
    END LOOP;

    INSERT INTO aktivitas_admin (admin_id, aksi, keterangan)
    VALUES (v_adm.id, 'update_qris', 'Memperbarui konfigurasi QRIS & toko');

    RETURN jsonb_build_object('ok', true);
END;
$$;

GRANT EXECUTE ON FUNCTION public.admin_simpan_konfigurasi(TEXT, JSONB) TO anon, authenticated, service_role;

CREATE OR REPLACE FUNCTION public.admin_toggle_menu_harian(
    p_token TEXT,
    p_tanggal DATE,
    p_menu_id BIGINT,
    p_tersedia BOOLEAN DEFAULT NULL
)
RETURNS JSONB
LANGUAGE plpgsql
SECURITY DEFINER
SET search_path = public, extensions
AS $$
DECLARE
    v_adm public.admin%ROWTYPE;
    v_cur RECORD;
    v_next BOOLEAN;
    v_stok INTEGER;
BEGIN
    v_adm := public._verify_admin_session(p_token, FALSE);
    IF v_adm.id IS NULL THEN
        RETURN jsonb_build_object('ok', false, 'auth_expired', true, 'pesan', 'Sesi login telah berakhir.');
    END IF;

    SELECT tersedia, stok INTO v_cur FROM menu_harian WHERE tanggal = p_tanggal AND menu_id = p_menu_id;
    IF p_tersedia IS NOT NULL THEN
        v_next := p_tersedia;
    ELSIF FOUND THEN
        v_next := NOT v_cur.tersedia;
    ELSE
        v_next := FALSE;
    END IF;

    v_stok := CASE WHEN FOUND THEN v_cur.stok ELSE NULL END;
    IF v_stok IS NULL THEN
        SELECT stok_default INTO v_stok FROM menu WHERE id = p_menu_id;
    END IF;

    INSERT INTO menu_harian (tanggal, menu_id, tersedia, stok)
    VALUES (p_tanggal, p_menu_id, v_next, v_stok)
    ON CONFLICT (tanggal, menu_id) DO UPDATE
    SET tersedia = EXCLUDED.tersedia,
        stok = COALESCE(menu_harian.stok, EXCLUDED.stok);

    INSERT INTO aktivitas_admin (admin_id, aksi, keterangan)
    VALUES (v_adm.id, 'toggle_menu', 'Mengubah status ketersediaan menu #' || p_menu_id || ' pada ' || p_tanggal::TEXT);

    RETURN jsonb_build_object('ok', true, 'tersedia', v_next);
END;
$$;

GRANT EXECUTE ON FUNCTION public.admin_toggle_menu_harian(TEXT, DATE, BIGINT, BOOLEAN) TO anon, authenticated, service_role;

CREATE OR REPLACE FUNCTION public.admin_simpan_menu(
    p_token TEXT,
    p_id BIGINT DEFAULT NULL,
    p_nama TEXT DEFAULT NULL,
    p_deskripsi TEXT DEFAULT NULL,
    p_harga INTEGER DEFAULT NULL,
    p_kategori TEXT DEFAULT 'lauk',
    p_stok INTEGER DEFAULT NULL,
    p_foto TEXT DEFAULT NULL,
    p_tanggal DATE DEFAULT NULL
)
RETURNS JSONB
LANGUAGE plpgsql
SECURITY DEFINER
SET search_path = public, extensions
AS $$
DECLARE
    v_adm public.admin%ROWTYPE;
    v_nama TEXT := trim(COALESCE(p_nama, ''));
    v_kat TEXT := NULLIF(lower(trim(COALESCE(p_kategori, ''))), '');
    v_menu_id BIGINT;
    v_tersedia BOOLEAN := TRUE;
BEGIN
    v_adm := public._verify_admin_session(p_token, FALSE);
    IF v_adm.id IS NULL THEN
        RETURN jsonb_build_object('ok', false, 'auth_expired', true, 'pesan', 'Sesi login telah berakhir.');
    END IF;

    IF v_nama = '' OR COALESCE(p_harga, 0) <= 0 THEN
        RETURN jsonb_build_object('ok', false, 'pesan', 'Nama menu dan harga wajib diisi dengan benar.');
    END IF;

    IF p_id IS NOT NULL THEN
        UPDATE menu
        SET nama = v_nama,
            deskripsi = COALESCE(p_deskripsi, deskripsi),
            harga = p_harga,
            kategori = COALESCE(v_kat, kategori),
            stok_default = p_stok,
            foto = COALESCE(NULLIF(trim(COALESCE(p_foto, '')), ''), foto)
        WHERE id = p_id;

        IF NOT FOUND THEN
            RETURN jsonb_build_object('ok', false, 'pesan', 'Menu tidak ditemukan.');
        END IF;

        IF p_tanggal IS NOT NULL THEN
            SELECT tersedia INTO v_tersedia FROM menu_harian WHERE tanggal = p_tanggal AND menu_id = p_id;
            v_tersedia := COALESCE(v_tersedia, TRUE);
            INSERT INTO menu_harian (tanggal, menu_id, tersedia, stok)
            VALUES (p_tanggal, p_id, v_tersedia, p_stok)
            ON CONFLICT (tanggal, menu_id) DO UPDATE
            SET stok = EXCLUDED.stok;
        END IF;

        INSERT INTO aktivitas_admin (admin_id, aksi, keterangan)
        VALUES (v_adm.id, 'edit_menu', 'Memperbarui menu "' || v_nama || '"');

        RETURN jsonb_build_object('ok', true, 'id', p_id);
    ELSE
        INSERT INTO menu (nama, deskripsi, harga, kategori, foto, stok_default, aktif)
        VALUES (
            v_nama,
            NULLIF(p_deskripsi, ''),
            p_harga,
            COALESCE(v_kat, 'lauk'),
            NULLIF(trim(COALESCE(p_foto, '')), ''),
            COALESCE(p_stok, 60),
            TRUE
        )
        RETURNING id INTO v_menu_id;

        INSERT INTO aktivitas_admin (admin_id, aksi, keterangan)
        VALUES (v_adm.id, 'tambah_menu', 'Menambahkan menu baru "' || v_nama || '"');

        RETURN jsonb_build_object('ok', true, 'id', v_menu_id);
    END IF;
END;
$$;

GRANT EXECUTE ON FUNCTION public.admin_simpan_menu(TEXT, BIGINT, TEXT, TEXT, INTEGER, TEXT, INTEGER, TEXT, DATE) TO anon, authenticated, service_role;

CREATE OR REPLACE FUNCTION public.admin_hapus_menu(
    p_token TEXT,
    p_menu_id BIGINT
)
RETURNS JSONB
LANGUAGE plpgsql
SECURITY DEFINER
SET search_path = public, extensions
AS $$
DECLARE
    v_adm public.admin%ROWTYPE;
BEGIN
    v_adm := public._verify_admin_session(p_token, FALSE);
    IF v_adm.id IS NULL THEN
        RETURN jsonb_build_object('ok', false, 'auth_expired', true, 'pesan', 'Sesi login telah berakhir.');
    END IF;

    UPDATE menu SET aktif = FALSE WHERE id = p_menu_id;
    IF NOT FOUND THEN
        RETURN jsonb_build_object('ok', false, 'pesan', 'Menu tidak ditemukan.');
    END IF;

    INSERT INTO aktivitas_admin (admin_id, aksi, keterangan)
    VALUES (v_adm.id, 'hapus_menu', 'Menonaktifkan/menghapus menu #' || p_menu_id);

    RETURN jsonb_build_object('ok', true);
END;
$$;

GRANT EXECUTE ON FUNCTION public.admin_hapus_menu(TEXT, BIGINT) TO anon, authenticated, service_role;

CREATE OR REPLACE FUNCTION public.admin_ubah_status_pesanan(
    p_token TEXT,
    p_pesanan_id BIGINT,
    p_status TEXT
)
RETURNS JSONB
LANGUAGE plpgsql
SECURITY DEFINER
SET search_path = public, extensions
AS $$
DECLARE
    v_adm public.admin%ROWTYPE;
    v_p RECORD;
    v_now TIMESTAMPTZ := NOW();
BEGIN
    v_adm := public._verify_admin_session(p_token, FALSE);
    IF v_adm.id IS NULL THEN
        RETURN jsonb_build_object('ok', false, 'auth_expired', true, 'pesan', 'Sesi login telah berakhir.');
    END IF;

    IF p_status NOT IN ('Menunggu Konfirmasi', 'Sedang Dimasak', 'Siap Diambil', 'Selesai', 'Dibatalkan') THEN
        RETURN jsonb_build_object('ok', false, 'pesan', 'Status pesanan tidak valid.');
    END IF;

    SELECT * INTO v_p FROM pesanan WHERE id = p_pesanan_id FOR UPDATE;
    IF NOT FOUND THEN
        RETURN jsonb_build_object('ok', false, 'pesan', 'Pesanan tidak ditemukan di database.');
    END IF;

    IF p_status = 'Sedang Dimasak' AND v_p.status_bayar NOT IN ('berhasil', 'lunas') THEN
        RETURN jsonb_build_object(
            'ok', false,
            'pesan', 'Pesanan ' || v_p.kode || ' belum berstatus Lunas. Harap verifikasi pembayaran terlebih dahulu sebelum mulai memasak.'
        );
    END IF;

    UPDATE pesanan
    SET status = p_status,
        diubah_pada = v_now,
        diserahkan_pada = CASE WHEN p_status = 'Selesai' THEN v_now ELSE diserahkan_pada END
    WHERE id = p_pesanan_id;

    INSERT INTO aktivitas_admin (admin_id, aksi, keterangan)
    VALUES (v_adm.id, 'ubah_status', 'Mengubah status ' || v_p.kode || ' menjadi ' || p_status);

    RETURN jsonb_build_object('ok', true, 'status', p_status, 'kode', v_p.kode);
END;
$$;

GRANT EXECUTE ON FUNCTION public.admin_ubah_status_pesanan(TEXT, BIGINT, TEXT) TO anon, authenticated, service_role;

CREATE OR REPLACE FUNCTION public.admin_verifikasi_pembayaran(
    p_token TEXT,
    p_pesanan_id BIGINT,
    p_aksi TEXT,
    p_alasan TEXT DEFAULT NULL
)
RETURNS JSONB
LANGUAGE plpgsql
SECURITY DEFINER
SET search_path = public, extensions
AS $$
DECLARE
    v_adm public.admin%ROWTYPE;
    v_p RECORD;
    v_now TIMESTAMPTZ := NOW();
    v_alasan TEXT := trim(COALESCE(p_alasan, ''));
BEGIN
    v_adm := public._verify_admin_session(p_token, FALSE);
    IF v_adm.id IS NULL THEN
        RETURN jsonb_build_object('ok', false, 'auth_expired', true, 'pesan', 'Sesi login telah berakhir.');
    END IF;

    IF p_aksi NOT IN ('terima', 'tolak') THEN
        RETURN jsonb_build_object('ok', false, 'pesan', 'Aksi verifikasi tidak valid.');
    END IF;

    IF p_aksi = 'tolak' AND v_alasan = '' THEN
        RETURN jsonb_build_object('ok', false, 'pesan', 'Alasan penolakan wajib diisi.');
    END IF;

    SELECT * INTO v_p FROM pesanan WHERE id = p_pesanan_id FOR UPDATE;
    IF NOT FOUND THEN
        RETURN jsonb_build_object('ok', false, 'pesan', 'Pesanan tidak ditemukan di database.');
    END IF;

    IF p_aksi = 'terima' AND NULLIF(trim(COALESCE(v_p.bukti_bayar, '')), '') IS NULL THEN
        RETURN jsonb_build_object('ok', false, 'pesan', 'Tidak dapat menyetujui pembayaran karena bukti bayar belum tersedia.');
    END IF;

    IF p_aksi = 'terima' THEN
        UPDATE pesanan
        SET status_bayar = 'berhasil',
            dibayar_pada = v_now,
            diverifikasi_oleh = v_adm.id,
            diverifikasi_pada = v_now,
            alasan_tolak = NULL,
            diubah_pada = v_now
        WHERE id = p_pesanan_id;
    ELSE
        UPDATE pesanan
        SET status_bayar = 'ditolak',
            diverifikasi_oleh = v_adm.id,
            diverifikasi_pada = v_now,
            alasan_tolak = v_alasan,
            diubah_pada = v_now
        WHERE id = p_pesanan_id;
    END IF;

    INSERT INTO verifikasi_log (pesanan_id, admin_id, aksi, catatan)
    VALUES (
        p_pesanan_id,
        v_adm.id,
        p_aksi,
        CASE WHEN p_aksi = 'terima' THEN 'Pembayaran diterima (Lunas).' ELSE v_alasan END
    );

    INSERT INTO aktivitas_admin (admin_id, aksi, keterangan)
    VALUES (
        v_adm.id,
        CASE WHEN p_aksi = 'terima' THEN 'verifikasi_terima' ELSE 'verifikasi_tolak' END,
        CASE WHEN p_aksi = 'terima' THEN 'Menyetujui pembayaran ' ELSE 'Menolak pembayaran ' END || v_p.kode
    );

    RETURN jsonb_build_object('ok', true, 'kode', v_p.kode);
END;
$$;

GRANT EXECUTE ON FUNCTION public.admin_verifikasi_pembayaran(TEXT, BIGINT, TEXT, TEXT) TO anon, authenticated, service_role;

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

-- 2. Seed Akun Admin Awal & Upgrade Hash Kata Sandi Lama ke Bcrypt (pgcrypto)
-- PENTING: Segera ubah kata sandi bawaan lewat menu Profil Saya setelah login pertama kali.
DO $$
BEGIN
    SET LOCAL search_path = public, extensions;

    INSERT INTO admin (username, password_hash, nama, role, jabatan, no_hp, email)
    VALUES (
        'admin',
        crypt('admin123', gen_salt('bf')),
        'Pengelola Warteg Pak Sanam',
        'owner',
        'Pemilik & Pengelola Utama',
        '0857-8342-3626',
        'owner@wartegpaksanam.id'
    )
    ON CONFLICT (username) DO NOTHING;

    UPDATE admin
    SET password_hash = crypt('admin123', gen_salt('bf'))
    WHERE password_hash = '$2y$10$92IXUNpkjO0rOQ5byMi.Ye4oKoEa3Ro9llC/.og/at2.uheWG/igi';

    UPDATE admin
    SET password_hash = crypt(password_hash, gen_salt('bf'))
    WHERE password_hash IS NOT NULL
      AND password_hash NOT LIKE '$2%';
END $$;

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
