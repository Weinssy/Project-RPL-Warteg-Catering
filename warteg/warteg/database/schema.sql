-- =====================================================================
-- Admin Warteg - skema database (BARU, terpisah dari database lama arman07)
-- Jalankan lewat phpMyAdmin: tab "Import" atau tab "SQL".
-- =====================================================================
CREATE DATABASE IF NOT EXISTS warteg_admin
  CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
USE warteg_admin;

-- Akun admin dashboard
CREATE TABLE IF NOT EXISTS admin (
  id            INT UNSIGNED NOT NULL AUTO_INCREMENT,
  username      VARCHAR(50)  NOT NULL,
  password_hash VARCHAR(255) NOT NULL,
  nama          VARCHAR(100) NOT NULL,
  foto          VARCHAR(255) NULL DEFAULT NULL COMMENT 'nama file foto profil di folder uploads/admin/',
  tanggal_lahir DATE         NULL DEFAULT NULL COMMENT 'tanggal lahir admin',
  jenis_kelamin ENUM('L','P') NULL DEFAULT NULL COMMENT 'L = laki-laki, P = perempuan',
  telepon       VARCHAR(20)  NULL DEFAULT NULL COMMENT 'nomor telepon / WhatsApp',
  alamat        VARCHAR(255) NULL DEFAULT NULL COMMENT 'alamat domisili',
  email         VARCHAR(150) NULL DEFAULT NULL COMMENT 'email kontak',
  role          ENUM('admin','owner') NOT NULL DEFAULT 'admin' COMMENT 'hak akses sistem',
  bahasa        ENUM('id','en') NOT NULL DEFAULT 'id' COMMENT 'preferensi bahasa antarmuka',
  notif_pesanan TINYINT(1)   NOT NULL DEFAULT 1 COMMENT 'preferensi notifikasi pesanan baru',
  notif_stok    TINYINT(1)   NOT NULL DEFAULT 1 COMMENT 'preferensi notifikasi stok menipis',
  dibuat_pada   TIMESTAMP    NOT NULL DEFAULT CURRENT_TIMESTAMP,
  diubah_pada   TIMESTAMP    NULL DEFAULT NULL COMMENT 'kapan profil terakhir diubah',
  PRIMARY KEY (id),
  UNIQUE KEY uq_admin_username (username),
  KEY idx_admin_role (role)
) ENGINE=InnoDB;

-- Log aktivitas admin (riwayat login, perubahan profil, tambah admin, dsb.)
CREATE TABLE IF NOT EXISTS aktivitas_admin (
  id          BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  admin_id    INT UNSIGNED NULL DEFAULT NULL COMMENT 'akun pelaku (NULL bila akun sudah dihapus)',
  admin_nama  VARCHAR(100) NOT NULL DEFAULT '' COMMENT 'snapshot nama pelaku saat aktivitas dicatat',
  aksi        VARCHAR(40) NOT NULL,
  keterangan  VARCHAR(255) NOT NULL DEFAULT '',
  ip          VARCHAR(45) NULL DEFAULT NULL,
  dibuat_pada TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  KEY idx_aktivitas_admin (admin_id, dibuat_pada),
  KEY idx_aktivitas_waktu (dibuat_pada),
  CONSTRAINT fk_aktivitas_admin FOREIGN KEY (admin_id) REFERENCES admin(id)
    ON UPDATE CASCADE ON DELETE SET NULL
) ENGINE=InnoDB;

-- Master menu. Kolom `aktif` = status umum (menu masih dijual atau sudah dihentikan).
CREATE TABLE IF NOT EXISTS menu (
  id        INT UNSIGNED NOT NULL AUTO_INCREMENT,
  nama      VARCHAR(100) NOT NULL,
  kategori  ENUM('nasi','lauk','sayur', 'paket a', 'paket b', 'paket c') NOT NULL DEFAULT 'nasi',
  harga     INT UNSIGNED NOT NULL,
  foto      VARCHAR(255) NULL COMMENT 'nama file di folder uploads/menu/',
  aktif     TINYINT(1)   NOT NULL DEFAULT 1,
  PRIMARY KEY (id),
  UNIQUE KEY uq_menu_nama (nama)
) ENGINE=InnoDB;

-- Ketersediaan menu per tanggal. Tidak ada baris = menu belum dibuka pada tanggal itu.
-- Ini BERBEDA dari status pesanan.
CREATE TABLE IF NOT EXISTS menu_harian (
  id        INT UNSIGNED NOT NULL AUTO_INCREMENT,
  menu_id   INT UNSIGNED NOT NULL,
  tanggal   DATE         NOT NULL,
  tersedia  TINYINT(1)   NOT NULL DEFAULT 0,
  stok      INT UNSIGNED NULL COMMENT 'jatah box untuk tanggal ini; NULL = tanpa batas stok',
  foto      VARCHAR(255) NULL COMMENT 'foto menu khusus tanggal ini (folder uploads/menu/)',
  PRIMARY KEY (id),
  UNIQUE KEY uq_menu_tanggal (menu_id, tanggal),
  KEY idx_menu_harian_tanggal (tanggal),
  CONSTRAINT fk_menu_harian_menu FOREIGN KEY (menu_id) REFERENCES menu(id)
    ON UPDATE CASCADE ON DELETE CASCADE
) ENGINE=InnoDB;

-- Satu baris per tanggal pengambilan. Dipakai sebagai KUNCI (SELECT ... FOR UPDATE)
-- agar dua pesanan bersamaan pada tanggal yang sama diproses bergantian.
CREATE TABLE IF NOT EXISTS kuota_harian (
  tanggal DATE NOT NULL,
  PRIMARY KEY (tanggal)
) ENGINE=InnoDB;

-- Pesanan. Pesanan yang tersimpan = pesanan resmi (tidak ada konfirmasi manual).
-- total_box = jumlah seluruh detail_pesanan.jumlah (diisi saat transaksi pembuatan).
-- Kuota harian = SUM(total_box) semua pesanan pada tanggal_ambil (semua status ikut dihitung,
-- termasuk 'Siap Diambil'). Belum ada fitur pembatalan, jadi tidak ada pengecualian.
CREATE TABLE IF NOT EXISTS pesanan (
  id              INT UNSIGNED NOT NULL AUTO_INCREMENT,
  kode            VARCHAR(30)  NOT NULL,
  nama_pelanggan  VARCHAR(100) NOT NULL,
  no_wa           VARCHAR(20)  NOT NULL COMMENT 'format internasional, mis. 6281234567890',
  tanggal_ambil   DATE         NOT NULL,
  jam_ambil       TIME         NOT NULL,
  total_box       INT UNSIGNED NOT NULL,
  total_harga     BIGINT UNSIGNED NOT NULL,
  status          ENUM('Menunggu Konfirmasi','Sedang Dimasak','Siap Diambil')
                  NOT NULL DEFAULT 'Menunggu Konfirmasi',
  dibuat_pada     TIMESTAMP    NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  UNIQUE KEY uq_pesanan_kode (kode),
  KEY idx_pesanan_tanggal_status (tanggal_ambil, status),
  KEY idx_pesanan_nama (nama_pelanggan),
  CONSTRAINT ck_pesanan_box CHECK (total_box > 0)
) ENGINE=InnoDB;

-- Rincian pesanan. harga_satuan = snapshot harga saat transaksi dibuat.
CREATE TABLE IF NOT EXISTS detail_pesanan (
  id           INT UNSIGNED NOT NULL AUTO_INCREMENT,
  pesanan_id   INT UNSIGNED NOT NULL,
  menu_id      INT UNSIGNED NOT NULL,
  jumlah       INT UNSIGNED NOT NULL,
  harga_satuan INT UNSIGNED NOT NULL,
  subtotal     BIGINT UNSIGNED NOT NULL,
  PRIMARY KEY (id),
  UNIQUE KEY uq_detail_pesanan_menu (pesanan_id, menu_id),
  KEY idx_detail_menu (menu_id),
  CONSTRAINT fk_detail_pesanan FOREIGN KEY (pesanan_id) REFERENCES pesanan(id)
    ON UPDATE CASCADE ON DELETE CASCADE,
  CONSTRAINT fk_detail_menu FOREIGN KEY (menu_id) REFERENCES menu(id)
    ON UPDATE CASCADE ON DELETE RESTRICT,
  CONSTRAINT ck_detail_jumlah CHECK (jumlah > 0)
) ENGINE=InnoDB;

-- Akun admin awal. Username: admin | Password: admin123  --> SEGERA GANTI.
-- Akun ini dibuat sebagai OWNER agar Manajemen Admin langsung dapat dipakai pada instalasi baru.
-- Untuk instalasi yang SUDAH berjalan, lihat database/migrasi_profil_admin.sql bagian "LANGKAH WAJIB".
INSERT IGNORE INTO admin (username, password_hash, nama, role)
VALUES ('admin', '$2y$10$7JWVzKuMWCmQRLEc2DbNY.W1yOOhMyRwzceTC4sKzLs5erIgyaLjS', 'Admin Warteg', 'owner');
