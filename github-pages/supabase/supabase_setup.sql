-- =====================================================================
-- SKEMA SUPABASE (PostgreSQL) - SISTEM KATERING & POS WARTEG
-- Proyek: Project-RPL-Warteg-Catering
-- Kompatibel dengan: Supabase Database, Auth, Storage, & RPC Transaction
-- 
-- CARA PENGGUNAAN:
-- 1. Buka Dashboard Supabase Anda (https://supabase.com).
-- 2. Pilih project Anda -> Masuk ke menu "SQL Editor" (ikon terminal).
-- 3. Klik "New query", salin (copy) seluruh isi file ini, tempel (paste),
--    lalu klik tombol "Run" (atau Ctrl+Enter).
-- =====================================================================

-- 1. EXTENSIONS
CREATE EXTENSION IF NOT EXISTS "uuid-ossp";

-- 2. TABEL: admin
CREATE TABLE IF NOT EXISTS public.admin (
    id BIGINT GENERATED ALWAYS AS IDENTITY PRIMARY KEY,
    username TEXT NOT NULL UNIQUE,
    password_hash TEXT NOT NULL,
    nama TEXT NOT NULL,
    foto TEXT NULL,
    tanggal_lahir DATE NULL,
    jenis_kelamin TEXT NULL CHECK (jenis_kelamin IN ('L', 'P')),
    telepon TEXT NULL,
    alamat TEXT NULL,
    email TEXT NULL,
    role TEXT NOT NULL DEFAULT 'admin' CHECK (role IN ('admin', 'owner')),
    bahasa TEXT NOT NULL DEFAULT 'id' CHECK (bahasa IN ('id', 'en')),
    notif_pesanan BOOLEAN NOT NULL DEFAULT TRUE,
    notif_stok BOOLEAN NOT NULL DEFAULT TRUE,
    dibuat_pada TIMESTAMPTZ NOT NULL DEFAULT NOW(),
    diubah_pada TIMESTAMPTZ NULL
);

-- 3. TABEL: aktivitas_admin
CREATE TABLE IF NOT EXISTS public.aktivitas_admin (
    id BIGINT GENERATED ALWAYS AS IDENTITY PRIMARY KEY,
    admin_id BIGINT REFERENCES public.admin(id) ON DELETE SET NULL,
    admin_nama TEXT NOT NULL DEFAULT '',
    aksi TEXT NOT NULL,
    keterangan TEXT NOT NULL DEFAULT '',
    ip TEXT NULL,
    dibuat_pada TIMESTAMPTZ NOT NULL DEFAULT NOW()
);

-- 4. TABEL: menu
CREATE TABLE IF NOT EXISTS public.menu (
    id BIGINT GENERATED ALWAYS AS IDENTITY PRIMARY KEY,
    nama TEXT NOT NULL UNIQUE,
    kategori TEXT NOT NULL DEFAULT 'nasi' CHECK (kategori IN ('nasi', 'lauk', 'sayur', 'paket a', 'paket b', 'paket c')),
    harga INTEGER NOT NULL CHECK (harga > 0),
    foto TEXT NULL,
    aktif BOOLEAN NOT NULL DEFAULT TRUE
);

-- 5. TABEL: menu_harian
CREATE TABLE IF NOT EXISTS public.menu_harian (
    id BIGINT GENERATED ALWAYS AS IDENTITY PRIMARY KEY,
    menu_id BIGINT NOT NULL REFERENCES public.menu(id) ON DELETE CASCADE,
    tanggal DATE NOT NULL,
    tersedia BOOLEAN NOT NULL DEFAULT FALSE,
    stok INTEGER NULL CHECK (stok IS NULL OR (stok >= 0 AND stok <= 120)),
    foto TEXT NULL,
    CONSTRAINT uq_menu_harian_tanggal UNIQUE (menu_id, tanggal)
);

-- 6. TABEL: kuota_harian
CREATE TABLE IF NOT EXISTS public.kuota_harian (
    tanggal DATE PRIMARY KEY
);

-- 7. TABEL: pesanan
CREATE TABLE IF NOT EXISTS public.pesanan (
    id BIGINT GENERATED ALWAYS AS IDENTITY PRIMARY KEY,
    kode TEXT NOT NULL UNIQUE,
    nama_pelanggan TEXT NOT NULL,
    no_wa TEXT NOT NULL,
    tanggal_ambil DATE NOT NULL,
    jam_ambil TIME NOT NULL,
    total_box INTEGER NOT NULL CHECK (total_box > 0),
    total_harga BIGINT NOT NULL CHECK (total_harga >= 0),
    status TEXT NOT NULL DEFAULT 'Menunggu Konfirmasi' CHECK (status IN ('Menunggu Konfirmasi', 'Sedang Dimasak', 'Siap Diambil')),
    status_bayar TEXT NOT NULL DEFAULT 'belum_bayar' CHECK (status_bayar IN ('belum_bayar', 'menunggu', 'menunggu_verifikasi', 'berhasil', 'ditolak', 'gagal', 'kedaluwarsa', 'dibatalkan')),
    metode_bayar TEXT NOT NULL DEFAULT 'qris',
    kode_bayar TEXT NULL,
    qr_payload TEXT NULL,
    nominal_bayar BIGINT NULL,
    dibayar_pada TIMESTAMPTZ NULL,
    bayar_kedaluwarsa_pada TIMESTAMPTZ NULL,
    token_nota TEXT NULL,
    catatan TEXT NULL,
    bukti_bayar TEXT NULL,
    bukti_bayar_pada TIMESTAMPTZ NULL,
    alasan_tolak TEXT NULL,
    admin_verifikator_id BIGINT REFERENCES public.admin(id) ON DELETE SET NULL,
    diverifikasi_pada TIMESTAMPTZ NULL,
    dibuat_pada TIMESTAMPTZ NOT NULL DEFAULT NOW()
);

-- 8. TABEL: detail_pesanan
CREATE TABLE IF NOT EXISTS public.detail_pesanan (
    id BIGINT GENERATED ALWAYS AS IDENTITY PRIMARY KEY,
    pesanan_id BIGINT NOT NULL REFERENCES public.pesanan(id) ON DELETE CASCADE,
    menu_id BIGINT NOT NULL REFERENCES public.menu(id) ON DELETE RESTRICT,
    jumlah INTEGER NOT NULL CHECK (jumlah > 0),
    harga_satuan INTEGER NOT NULL CHECK (harga_satuan >= 0),
    subtotal BIGINT NOT NULL CHECK (subtotal >= 0),
    CONSTRAINT uq_detail_pesanan_menu UNIQUE (pesanan_id, menu_id)
);

-- 9. TABEL: konfigurasi_toko
CREATE TABLE IF NOT EXISTS public.konfigurasi_toko (
    kunci TEXT PRIMARY KEY,
    nilai TEXT NULL,
    diubah_pada TIMESTAMPTZ NULL DEFAULT NOW(),
    diubah_oleh BIGINT NULL
);

-- 10. TABEL: verifikasi_log
CREATE TABLE IF NOT EXISTS public.verifikasi_log (
    id BIGINT GENERATED ALWAYS AS IDENTITY PRIMARY KEY,
    pesanan_id BIGINT NOT NULL REFERENCES public.pesanan(id) ON DELETE CASCADE,
    kode_pesanan TEXT NOT NULL,
    admin_id BIGINT REFERENCES public.admin(id) ON DELETE SET NULL,
    admin_nama TEXT NOT NULL DEFAULT '',
    aksi TEXT NOT NULL CHECK (aksi IN ('setuju', 'tolak')),
    alasan TEXT NULL,
    status_lama TEXT NOT NULL DEFAULT '',
    status_baru TEXT NOT NULL DEFAULT '',
    dibuat_pada TIMESTAMPTZ NOT NULL DEFAULT NOW()
);

-- =====================================================================
-- DATABASE TRANSACTION FUNCTION (RPC): buat_pesanan_katering
-- Validasi kuota 120 box & stok dibungkus transaction aman konkurensi
-- =====================================================================
CREATE OR REPLACE FUNCTION public.buat_pesanan_katering(
    p_nama TEXT,
    p_no_wa TEXT,
    p_tanggal DATE,
    p_jam TIME,
    p_catatan TEXT,
    p_items JSONB
)
RETURNS JSONB
LANGUAGE plpgsql
SECURITY DEFINER
AS $$
DECLARE
    v_total_box INT := 0;
    v_total_harga BIGINT := 0;
    v_item RECORD;
    v_menu_harga INT;
    v_menu_nama TEXT;
    v_menu_aktif BOOLEAN;
    v_tersedia BOOLEAN;
    v_stok INT;
    v_terjual INT;
    v_kuota_terpakai INT := 0;
    v_pesanan_id BIGINT;
    v_kode TEXT;
    v_token TEXT;
    v_seq INT;
BEGIN
    -- Validasi Input Dasar
    IF TRIM(p_nama) = '' OR TRIM(p_no_wa) = '' THEN
        RAISE EXCEPTION 'Nama pemesan dan nomor WhatsApp wajib diisi.';
    END IF;

    IF jsonb_array_length(p_items) = 0 THEN
        RAISE EXCEPTION 'Keranjang pesanan masih kosong.';
    END IF;

    -- Hitung total box & harga dari p_items
    FOR v_item IN SELECT * FROM jsonb_to_recordset(p_items) AS x(menu_id BIGINT, jumlah INT)
    LOOP
        IF v_item.jumlah <= 0 THEN
            RAISE EXCEPTION 'Jumlah per menu minimal 1 box.';
        END IF;
        v_total_box := v_total_box + v_item.jumlah;
    END LOOP;

    -- Aturan Bisnis: Minimal 10 box
    IF v_total_box < 10 THEN
        RAISE EXCEPTION 'Minimum pemesanan katering adalah 10 box.';
    END IF;

    -- LOCK KUOTA HARIAN (Concurrency Lock dengan SELECT ... FOR UPDATE)
    INSERT INTO public.kuota_harian (tanggal) VALUES (p_tanggal)
    ON CONFLICT (tanggal) DO NOTHING;

    PERFORM 1 FROM public.kuota_harian WHERE tanggal = p_tanggal FOR UPDATE;

    -- Hitung akumulasi pesanan pada tanggal ini
    SELECT COALESCE(SUM(total_box), 0) INTO v_kuota_terpakai
    FROM public.pesanan
    WHERE tanggal_ambil = p_tanggal AND status_bayar != 'dibatalkan';

    -- Aturan Bisnis: Maksimal 120 box per hari
    IF (v_kuota_terpakai + v_total_box) > 120 THEN
        RAISE EXCEPTION 'Kapasitas dapur penuh untuk tanggal tersebut. Sisa kuota hanya % box.', (120 - v_kuota_terpakai);
    END IF;

    -- Generate Kode Pesanan Unik: WRT-YYYYMMDD-XXX
    SELECT COALESCE(COUNT(*), 0) + 1 INTO v_seq
    FROM public.pesanan
    WHERE tanggal_ambil = p_tanggal;

    v_kode := 'WRT-' || TO_CHAR(p_tanggal, 'YYYYMMDD') || '-' || LPAD(v_seq::TEXT, 3, '0');
    v_token := encode(gen_random_bytes(20), 'hex');

    -- Insert Header Pesanan Sementara
    INSERT INTO public.pesanan (
        kode, nama_pelanggan, no_wa, tanggal_ambil, jam_ambil,
        total_box, total_harga, status, status_bayar, token_nota, catatan
    ) VALUES (
        v_kode, p_nama, p_no_wa, p_tanggal, p_jam,
        v_total_box, 0, 'Menunggu Konfirmasi', 'belum_bayar', v_token, p_catatan
    ) RETURNING id INTO v_pesanan_id;

    -- Validasi Setiap Item & Insert Detail Pesanan
    FOR v_item IN SELECT * FROM jsonb_to_recordset(p_items) AS x(menu_id BIGINT, jumlah INT)
    LOOP
        SELECT nama, harga, aktif INTO v_menu_nama, v_menu_harga, v_menu_aktif
        FROM public.menu WHERE id = v_item.menu_id;

        IF NOT FOUND OR NOT v_menu_aktif THEN
            RAISE EXCEPTION 'Menu tidak ditemukan atau sudah dinonaktifkan.';
        END IF;

        -- Cek ketersediaan & stok harian
        SELECT tersedia, stok INTO v_tersedia, v_stok
        FROM public.menu_harian
        WHERE menu_id = v_item.menu_id AND tanggal = p_tanggal;

        IF v_tersedia IS NOT NULL AND NOT v_tersedia THEN
            RAISE EXCEPTION 'Menu "%" tidak tersedia pada tanggal yang dipilih.', v_menu_nama;
        END IF;

        IF v_stok IS NOT NULL THEN
            SELECT COALESCE(SUM(d.jumlah), 0) INTO v_terjual
            FROM public.detail_pesanan d
            JOIN public.pesanan p ON p.id = d.pesanan_id
            WHERE p.tanggal_ambil = p_tanggal AND d.menu_id = v_item.menu_id AND p.status_bayar != 'dibatalkan';

            IF (v_terjual + v_item.jumlah) > v_stok THEN
                RAISE EXCEPTION 'Stok "%" tidak mencukupi (sisa % box).', v_menu_nama, GREATEST(0, v_stok - v_terjual);
            END IF;
        END IF;

        -- Insert detail
        INSERT INTO public.detail_pesanan (pesanan_id, menu_id, jumlah, harga_satuan, subtotal)
        VALUES (v_pesanan_id, v_item.menu_id, v_item.jumlah, v_menu_harga, (v_item.jumlah::BIGINT * v_menu_harga));

        v_total_harga := v_total_harga + (v_item.jumlah::BIGINT * v_menu_harga);
    END LOOP;

    -- Update total harga final di pesanan
    UPDATE public.pesanan
    SET total_harga = v_total_harga, nominal_bayar = v_total_harga
    WHERE id = v_pesanan_id;

    RETURN jsonb_build_object(
        'ok', TRUE,
        'pesanan_id', v_pesanan_id,
        'kode', v_kode,
        'token_nota', v_token,
        'total_box', v_total_box,
        'total_harga', v_total_harga
    );
END;
$$;

-- =====================================================================
-- ROW LEVEL SECURITY (RLS) POLICIES
-- Memberikan akses aman untuk anonim (frontend pelanggan & admin)
-- =====================================================================
ALTER TABLE public.admin ENABLE ROW LEVEL SECURITY;
ALTER TABLE public.aktivitas_admin ENABLE ROW LEVEL SECURITY;
ALTER TABLE public.menu ENABLE ROW LEVEL SECURITY;
ALTER TABLE public.menu_harian ENABLE ROW LEVEL SECURITY;
ALTER TABLE public.kuota_harian ENABLE ROW LEVEL SECURITY;
ALTER TABLE public.pesanan ENABLE ROW LEVEL SECURITY;
ALTER TABLE public.detail_pesanan ENABLE ROW LEVEL SECURITY;
ALTER TABLE public.konfigurasi_toko ENABLE ROW LEVEL SECURITY;
ALTER TABLE public.verifikasi_log ENABLE ROW LEVEL SECURITY;

-- Kebijakan akses penuh untuk anonim / public key
CREATE POLICY "Akses publik menu" ON public.menu FOR ALL USING (true) WITH CHECK (true);
CREATE POLICY "Akses publik menu_harian" ON public.menu_harian FOR ALL USING (true) WITH CHECK (true);
CREATE POLICY "Akses publik kuota_harian" ON public.kuota_harian FOR ALL USING (true) WITH CHECK (true);
CREATE POLICY "Akses publik pesanan" ON public.pesanan FOR ALL USING (true) WITH CHECK (true);
CREATE POLICY "Akses publik detail_pesanan" ON public.detail_pesanan FOR ALL USING (true) WITH CHECK (true);
CREATE POLICY "Akses publik konfigurasi_toko" ON public.konfigurasi_toko FOR ALL USING (true) WITH CHECK (true);
CREATE POLICY "Akses publik verifikasi_log" ON public.verifikasi_log FOR ALL USING (true) WITH CHECK (true);
CREATE POLICY "Akses publik admin" ON public.admin FOR ALL USING (true) WITH CHECK (true);
CREATE POLICY "Akses publik aktivitas_admin" ON public.aktivitas_admin FOR ALL USING (true) WITH CHECK (true);

-- =====================================================================
-- DATA SEED AWAL (Demo)
-- =====================================================================
-- Akun Admin Default: admin / admin123
INSERT INTO public.admin (username, password_hash, nama, role, bahasa)
VALUES ('admin', 'admin123', 'Admin Warteg', 'owner', 'id')
ON CONFLICT (username) DO NOTHING;

-- Konfigurasi Toko Awal
INSERT INTO public.konfigurasi_toko (kunci, nilai) VALUES
  ('qris_gambar', 'qris_90ce220b97a92f248db3.jpg'),
  ('qris_nama_merchant', 'Warung Mas Alif'),
  ('qris_nmid', 'ID1025439892668'),
  ('qris_instruksi', 'Scan QRIS di atas dengan aplikasi bank atau e-wallet Anda. Masukkan nominal pembayaran secara MANUAL sesuai total tagihan karena QRIS ini bersifat statis (nominal tidak otomatis terisi).'),
  ('batas_bayar_jam', '24')
ON CONFLICT (kunci) DO NOTHING;

-- Master Menu Awal
INSERT INTO public.menu (nama, kategori, harga, foto, aktif) VALUES
  ('Nasi Ayam',  'nasi', 15000, '567776efe02122c01fcd.jpg', TRUE),
  ('Nasi Ikan',  'nasi', 17000, '58fe9cd7bf786e6ab41c.jpg', TRUE),
  ('Nasi Udang', 'nasi', 20000, '6204c3f5c6b75a69ad21.jpg', TRUE),
  ('Paket Hemat Komplit', 'paket a', 25000, '67a12609967b2741de56.jpg', TRUE)
ON CONFLICT (nama) DO NOTHING;

-- Buka Menu Hari Ini & Besok
INSERT INTO public.menu_harian (menu_id, tanggal, tersedia, stok)
SELECT id, CURRENT_DATE, TRUE, 120 FROM public.menu
ON CONFLICT DO NOTHING;

INSERT INTO public.menu_harian (menu_id, tanggal, tersedia, stok)
SELECT id, CURRENT_DATE + INTERVAL '1 day', TRUE, 120 FROM public.menu
ON CONFLICT DO NOTHING;

