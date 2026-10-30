-- =====================================================================
-- Migrasi: Sistem pembayaran QRIS STATIS + verifikasi manual admin.
-- AMAN untuk database yang SUDAH berisi data: hanya MENAMBAH kolom/tabel.
-- Tidak ada DROP, tidak ada reset data. Jalankan sekali lewat phpMyAdmin -> SQL.
-- =====================================================================
USE warteg_admin;

-- ---------------------------------------------------------------------
-- 1) Tambah status baru pada ENUM status_bayar: menunggu_verifikasi, ditolak
-- ---------------------------------------------------------------------
ALTER TABLE pesanan
  MODIFY COLUMN status_bayar ENUM('belum_bayar','menunggu','menunggu_verifikasi','berhasil','ditolak','gagal','kedaluwarsa','dibatalkan')
      NOT NULL DEFAULT 'belum_bayar' COMMENT 'status pembayaran QRIS';

-- ---------------------------------------------------------------------
-- 2) Kolom tambahan untuk bukti bayar & verifikasi admin
-- ---------------------------------------------------------------------
ALTER TABLE pesanan
  ADD COLUMN bukti_bayar VARCHAR(255) NULL DEFAULT NULL COMMENT 'nama file bukti pembayaran di uploads/bukti/' AFTER bayar_kedaluwarsa_pada,
  ADD COLUMN bukti_bayar_pada TIMESTAMP NULL DEFAULT NULL COMMENT 'waktu upload bukti bayar' AFTER bukti_bayar,
  ADD COLUMN alasan_tolak VARCHAR(500) NULL DEFAULT NULL COMMENT 'alasan penolakan pembayaran oleh admin' AFTER bukti_bayar_pada,
  ADD COLUMN admin_verifikator_id INT UNSIGNED NULL DEFAULT NULL COMMENT 'ID admin yg memverifikasi' AFTER alasan_tolak,
  ADD COLUMN diverifikasi_pada TIMESTAMP NULL DEFAULT NULL COMMENT 'waktu verifikasi oleh admin' AFTER admin_verifikator_id;

ALTER TABLE pesanan
  ADD KEY idx_pesanan_bukti (bukti_bayar),
  ADD KEY idx_pesanan_verifikator (admin_verifikator_id);

-- ---------------------------------------------------------------------
-- 3) Tabel konfigurasi toko (QRIS statis, dll)
-- ---------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS konfigurasi_toko (
  kunci       VARCHAR(50) NOT NULL,
  nilai       TEXT NULL DEFAULT NULL,
  diubah_pada TIMESTAMP NULL DEFAULT NULL ON UPDATE CURRENT_TIMESTAMP,
  diubah_oleh INT UNSIGNED NULL DEFAULT NULL COMMENT 'admin_id yang terakhir mengubah',
  PRIMARY KEY (kunci)
) ENGINE=InnoDB;

-- Default: QRIS belum diupload
INSERT IGNORE INTO konfigurasi_toko (kunci, nilai) VALUES
  ('qris_gambar', NULL),
  ('qris_nama_merchant', 'Waroeng Mas Ripa'),
  ('qris_nmid', 'ID1025439892668'),
  ('qris_instruksi', 'Scan QRIS di atas dengan aplikasi bank atau e-wallet Anda. Masukkan nominal pembayaran secara MANUAL sesuai total tagihan karena QRIS ini bersifat statis (nominal tidak otomatis terisi).'),
  ('batas_bayar_jam', '24');

-- ---------------------------------------------------------------------
-- 4) Log verifikasi pembayaran oleh admin (audit trail)
-- ---------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS verifikasi_log (
  id            BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  pesanan_id    INT UNSIGNED NOT NULL,
  kode_pesanan  VARCHAR(30) NOT NULL,
  admin_id      INT UNSIGNED NULL DEFAULT NULL,
  admin_nama    VARCHAR(100) NOT NULL DEFAULT '',
  aksi          ENUM('setuju','tolak') NOT NULL,
  alasan        VARCHAR(500) NULL DEFAULT NULL,
  status_lama   VARCHAR(30) NOT NULL DEFAULT '',
  status_baru   VARCHAR(30) NOT NULL DEFAULT '',
  dibuat_pada   TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  KEY idx_verifikasi_pesanan (pesanan_id),
  KEY idx_verifikasi_admin (admin_id),
  CONSTRAINT fk_verifikasi_pesanan FOREIGN KEY (pesanan_id) REFERENCES pesanan(id)
    ON UPDATE CASCADE ON DELETE CASCADE,
  CONSTRAINT fk_verifikasi_admin FOREIGN KEY (admin_id) REFERENCES admin(id)
    ON UPDATE CASCADE ON DELETE SET NULL
) ENGINE=InnoDB;
