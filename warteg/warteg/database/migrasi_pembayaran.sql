-- =====================================================================
-- Migrasi: Pembayaran QRIS untuk pesanan customer + log WhatsApp.
-- AMAN untuk database yang SUDAH berisi data: hanya MENAMBAH kolom/tabel.
-- Tidak ada DROP, tidak ada reset data. Jalankan sekali lewat phpMyAdmin -> SQL.
-- =====================================================================
USE warteg_admin;

-- ---------------------------------------------------------------------
-- 1) Kolom pembayaran pada tabel pesanan.
--    Status PEMBAYARAN sengaja DIPISAH dari `status` (status proses katering):
--      status         : Menunggu Konfirmasi / Sedang Dimasak / Siap Diambil (operasional)
--      status_bayar   : belum_bayar / menunggu / berhasil / gagal / kedaluwarsa
-- ---------------------------------------------------------------------
ALTER TABLE pesanan
  ADD COLUMN status_bayar ENUM('belum_bayar','menunggu','berhasil','gagal','kedaluwarsa')
      NOT NULL DEFAULT 'belum_bayar' COMMENT 'status pembayaran QRIS, terpisah dari status proses' AFTER total_harga,
  ADD COLUMN metode_bayar   VARCHAR(20)  NOT NULL DEFAULT 'qris' COMMENT 'metode pembayaran' AFTER status_bayar,
  ADD COLUMN kode_bayar     VARCHAR(120) NULL DEFAULT NULL COMMENT 'ID transaksi/order di payment gateway' AFTER metode_bayar,
  ADD COLUMN qr_payload     TEXT         NULL DEFAULT NULL COMMENT 'string QRIS dinamis / URL gambar QR (tidak sensitif)' AFTER kode_bayar,
  ADD COLUMN nominal_bayar  BIGINT UNSIGNED NULL DEFAULT NULL COMMENT 'nominal yang harus dibayar (snapshot)' AFTER qr_payload,
  ADD COLUMN dibayar_pada   TIMESTAMP    NULL DEFAULT NULL COMMENT 'waktu pembayaran terverifikasi' AFTER nominal_bayar,
  ADD COLUMN bayar_kedaluwarsa_pada TIMESTAMP NULL DEFAULT NULL COMMENT 'batas waktu bayar' AFTER dibayar_pada,
  ADD COLUMN token_nota     CHAR(40)     NULL DEFAULT NULL COMMENT 'token acak untuk membuka nota/lacak pesanan tanpa login' AFTER bayar_kedaluwarsa_pada,
  ADD COLUMN catatan        VARCHAR(255) NULL DEFAULT NULL COMMENT 'catatan pesanan dari pelanggan' AFTER token_nota;

-- Token acak diisi untuk baris lama (tidak mengekspos data; hanya penanda acak).
UPDATE pesanan SET token_nota = MD5(CONCAT(kode, '-', id, '-', RAND())) WHERE token_nota IS NULL;

-- Indeks agar pencarian nota & transaksi cepat. Tambah UNIQUE pada kode_bayar bila belum ada,
-- agar pembuatan pesanan ganda untuk satu transaksi gateway dapat dicegah.
ALTER TABLE pesanan
  ADD KEY idx_pesanan_status_bayar (status_bayar),
  ADD KEY idx_pesanan_token (token_nota),
  ADD KEY idx_pesanan_kode_bayar (kode_bayar);

-- ---------------------------------------------------------------------
-- 2) Log callback pembayaran (idempoten): setiap callback webhook disimpan
--    sekali. UNIQUE(fingerprint) mencegah pemrosesan ulang transaksi yang sama.
-- ---------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS pembayaran_log (
  id            BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  pesanan_id    INT UNSIGNED NULL DEFAULT NULL,
  kode_pesanan  VARCHAR(30) NULL DEFAULT NULL,
  kode_bayar    VARCHAR(120) NULL DEFAULT NULL COMMENT 'ID transaksi dari gateway',
  provider      VARCHAR(20) NOT NULL DEFAULT '',
  event         VARCHAR(60) NOT NULL DEFAULT '' COMMENT 'jenis event/callback',
  status_masuk  VARCHAR(30) NOT NULL DEFAULT '' COMMENT 'status mentah dari gateway',
  status_hasil  VARCHAR(30) NOT NULL DEFAULT '' COMMENT 'status internal setelah diproses',
  nominal       BIGINT UNSIGNED NULL DEFAULT NULL,
  fingerprint   CHAR(64) NOT NULL COMMENT 'sha256(provider+ids+status+nominal) untuk idempotensi',
  ip            VARCHAR(45) NULL DEFAULT NULL,
  dibuat_pada   TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  UNIQUE KEY uq_pembayaran_fingerprint (fingerprint),
  KEY idx_pembayaran_pesanan (pesanan_id),
  KEY idx_pembayaran_kode_bayar (kode_bayar),
  CONSTRAINT fk_pembayaran_pesanan FOREIGN KEY (pesanan_id) REFERENCES pesanan(id)
    ON UPDATE CASCADE ON DELETE SET NULL
) ENGINE=InnoDB;

-- ---------------------------------------------------------------------
-- 3) Log pengiriman WhatsApp admin. Kegagalan kirim TIDAK mengubah status
--    pembayaran; log dipakai untuk retry dan audit.
-- ---------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS wa_log (
  id            BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  pesanan_id    INT UNSIGNED NULL DEFAULT NULL,
  kode_pesanan  VARCHAR(30) NULL DEFAULT NULL,
  tujuan        VARCHAR(30) NOT NULL DEFAULT '' COMMENT 'nomor WA admin tujuan',
  jenis         VARCHAR(30) NOT NULL DEFAULT 'pesanan_baru',
  status        ENUM('menunggu','terkirim','gagal') NOT NULL DEFAULT 'menunggu',
  kode_http     INT NULL DEFAULT NULL,
  respons       VARCHAR(255) NULL DEFAULT NULL COMMENT 'ringkasan respons/kesalahan (tanpa token)',
  percobaan     INT UNSIGNED NOT NULL DEFAULT 0,
  dibuat_pada   TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  dikirim_pada  TIMESTAMP NULL DEFAULT NULL,
  PRIMARY KEY (id),
  KEY idx_wa_pesanan (pesanan_id),
  KEY idx_wa_status (status),
  CONSTRAINT fk_wa_pesanan FOREIGN KEY (pesanan_id) REFERENCES pesanan(id)
    ON UPDATE CASCADE ON DELETE SET NULL
) ENGINE=InnoDB;
