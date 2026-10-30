-- =====================================================================
-- Migrasi: profil admin lengkap, role Owner, preferensi, dan log aktivitas
-- Jalankan SEKALI pada database warteg_admin yang SUDAH BERJALAN.
-- phpMyAdmin -> pilih database `warteg_admin` -> tab "SQL" -> tempel isi file ini -> Go.
--
-- AMAN untuk data lama:
--   * Hanya MENAMBAH kolom baru yang NULL/default. Tidak ada DROP/rencana menghapus data.
--   * Semua akun admin lama otomatis ber-peran 'admin' (bukan Owner) dan tetap bisa login.
--   * Tidak ada akun Owner default dengan kata sandi yang ditanam di kode.
--
-- CATATAN: MySQL versi lama belum mendukung "ADD COLUMN IF NOT EXISTS".
-- Jika muncul galat "Duplicate column name ...", kolom itu sudah ada -> abaikan/lewati baris itu.
-- =====================================================================
USE warteg_admin;

-- ---------- Profil, role, dan preferensi ----------
ALTER TABLE admin
  ADD COLUMN tanggal_lahir  DATE NULL DEFAULT NULL              COMMENT 'tanggal lahir admin'                AFTER nama,
  ADD COLUMN jenis_kelamin  ENUM('L','P') NULL DEFAULT NULL     COMMENT 'L = laki-laki, P = perempuan'        AFTER tanggal_lahir,
  ADD COLUMN telepon        VARCHAR(20) NULL DEFAULT NULL       COMMENT 'nomor telepon / WhatsApp'             AFTER jenis_kelamin,
  ADD COLUMN alamat         VARCHAR(255) NULL DEFAULT NULL      COMMENT 'alamat domisili'                      AFTER telepon,
  ADD COLUMN email          VARCHAR(150) NULL DEFAULT NULL      COMMENT 'email kontak'                          AFTER alamat,
  ADD COLUMN role           ENUM('admin','owner') NOT NULL DEFAULT 'admin' COMMENT 'hak akses sistem'          AFTER email,
  ADD COLUMN bahasa         ENUM('id','en') NOT NULL DEFAULT 'id' COMMENT 'preferensi bahasa antarmuka'       AFTER role,
  ADD COLUMN notif_pesanan  TINYINT(1) NOT NULL DEFAULT 1       COMMENT 'preferensi notifikasi pesanan baru'   AFTER bahasa,
  ADD COLUMN notif_stok     TINYINT(1) NOT NULL DEFAULT 1       COMMENT 'preferensi notifikasi stok menipis'   AFTER notif_pesanan,
  ADD COLUMN diubah_pada    TIMESTAMP NULL DEFAULT NULL         COMMENT 'kapan profil terakhir diubah'         AFTER dibuat_pada;

-- Stempel cache foto di header. Sebelumnya memakai dibuat_pada; kini punya kolom sendiri.
UPDATE admin SET diubah_pada = dibuat_pada WHERE diubah_pada IS NULL;

-- Index bantu pembacaan akun Owner (boleh tidak ada duplikat pembaca; index biasa aman).
ALTER TABLE admin ADD INDEX idx_admin_role (role);

-- ---------- Log aktivitas ----------
-- Tabel terpisah agar riwayat tidak ikut terhapus bila akun admin dihapus.
-- admin_id boleh NULL bila akun sudah tidak ada; admin_nama menyimpan snapshot nama.
CREATE TABLE IF NOT EXISTS aktivitas_admin (
  id          BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  admin_id    INT UNSIGNED NULL DEFAULT NULL COMMENT 'akun pelaku (NULL bila akun sudah dihapus)',
  admin_nama  VARCHAR(100) NOT NULL DEFAULT '' COMMENT 'snapshot nama pelaku saat aktivitas dicatat',
  aksi        VARCHAR(40) NOT NULL             COMMENT 'login, logout, ubah_profil, ubah_foto, ubah_sandi, tambah_admin, ...',
  keterangan  VARCHAR(255) NOT NULL DEFAULT '' COMMENT 'ringkasan aman, tanpa data sensitif',
  ip          VARCHAR(45) NULL DEFAULT NULL    COMMENT 'IP klien (IPv4/IPv6)',
  dibuat_pada TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  KEY idx_aktivitas_admin (admin_id, dibuat_pada),
  KEY idx_aktivitas_waktu (dibuat_pada),
  CONSTRAINT fk_aktivitas_admin FOREIGN KEY (admin_id) REFERENCES admin(id)
    ON UPDATE CASCADE ON DELETE SET NULL
) ENGINE=InnoDB;

-- =====================================================================
-- LANGKAH WAJIB SETELAH MIGRASI (sekali saja):
-- Tentukan akun Owner yang SUDAH ADA. Sistem tidak membuat Owner default.
-- Ganti 'admin' dengan username akun yang benar, atau sesuaikan id-nya.
--
--   UPDATE admin SET role = 'owner' WHERE username = 'admin';
--
-- Untuk memeriksa hasilnya:
--   SELECT id, username, nama, role FROM admin;
-- =====================================================================
