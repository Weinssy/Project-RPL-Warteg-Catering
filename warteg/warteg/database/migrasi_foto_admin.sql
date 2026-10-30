-- =====================================================================
-- Migrasi: foto profil admin
-- Jalankan SEKALI pada database warteg_admin yang SUDAH BERJALAN.
-- phpMyAdmin -> pilih database `warteg_admin` -> tab "SQL" -> tempel isi file ini -> Go.
-- Tidak mengubah/menghapus data admin yang sudah ada; kolom baru bernilai NULL untuk admin lama.
-- =====================================================================
USE warteg_admin;

-- Jika muncul galat "Duplicate column name 'foto'", kolom sudah ada -> migrasi ini tidak perlu diulang.
ALTER TABLE admin
  ADD COLUMN foto VARCHAR(255) NULL DEFAULT NULL
  COMMENT 'nama file foto profil di folder uploads/admin/'
  AFTER nama;
